<?php

declare(strict_types=1);

namespace VanturaxForms\Database;

if (!defined('ABSPATH')) {
    exit;
}

final class Migrator
{
    public const DB_VERSION_OPTION = 'vanturax_forms_db_version';
    public const DB_ERROR_OPTION   = 'vanturax_forms_db_last_error';
    public const LOCK_OPTION       = 'vxf_db_migration_lock';

    /**
     * 180-second lease exceeds standard shared-hosting PHP max_execution_time (30s-120s)
     * while remaining bounded so crashed workers recover automatically.
     */
    private const LOCK_TTL_SECONDS = 180;

    /**
     * Required columns per table for structural health verification.
     *
     * @var array<string, string[]>
     */
    private const REQUIRED_COLUMNS = [
        'forms' => [
            'id', 'title', 'slug', 'status', 'schema_version',
            'schema_json', 'settings_json', 'revision', 'created_at', 'updated_at',
        ],
        'form_counters' => [
            'form_id', 'last_entry_number',
        ],
        'submissions' => [
            'id', 'form_id', 'entry_number', 'submission_uuid',
            'status', 'created_at', 'updated_at',
        ],
        'submission_meta' => [
            'id', 'submission_id', 'form_id', 'field_key',
            'field_type', 'meta_value', 'meta_hash', 'meta_prefix',
        ],
        'integration_logs' => [
            'id', 'submission_id', 'form_id', 'integration_slug',
            'status', 'worker_token', 'locked_at', 'lock_expires_at',
            'attempts', 'next_retry_at', 'created_at',
        ],
    ];

    /**
     * Critical indexes per table for data integrity and queue/search performance.
     *
     * @var array<string, string[]>
     */
    private const REQUIRED_INDEXES = [
        'forms'            => ['PRIMARY', 'idx_slug'],
        'form_counters'    => ['PRIMARY'],
        'submissions'      => ['PRIMARY', 'idx_submission_uuid', 'idx_form_entry'],
        'submission_meta'  => ['PRIMARY', 'idx_submission_field', 'idx_form_field_hash', 'idx_form_field_prefix'],
        'integration_logs' => ['PRIMARY', 'idx_submission_integration', 'idx_queue_poll', 'idx_stale_locks'],
    ];

    /**
     * Run safe incremental schema installation/migration when the stored DB version lags.
     */
    public function maybeMigrate(): void
    {
        $installedVersion = (string) get_option(self::DB_VERSION_OPTION, '');
        if ($installedVersion === VXF_DB_VERSION) {
            return;
        }

        $this->install();
    }

    /**
     * Acquire an atomic MySQL option lock, renew lease before every DDL step,
     * inspect health, and commit final option state inside a row-locked InnoDB transaction.
     * Never drops or truncates existing customer tables or rows.
     */
    public function install(): bool
    {
        global $wpdb;

        if (!$wpdb instanceof \wpdb) {
            return false;
        }

        $lockState = $this->acquireAtomicLock($wpdb);
        if ($lockState === null) {
            return false;
        }

        $lockReleasedInTx = false;
        $previousSuppress = $wpdb->suppress_errors(false);

        try {
            require_once ABSPATH . 'wp-admin/includes/upgrade.php';

            $schemas = Schema::getTableSchemas($wpdb);
            $errors  = [];

            foreach ($schemas as $key => $sql) {
                $renewedState = $this->renewAtomicLock($wpdb, $lockState);
                if ($renewedState === null) {
                    // Lease was lost/expired and reclaimed by another worker; abort safely.
                    return false;
                }
                $lockState = $renewedState;

                $wpdb->last_error = '';
                dbDelta($sql);

                if ($wpdb->last_error !== '') {
                    $errors[$key] = $wpdb->last_error;
                }
            }

            // Re-verify and renew lock ownership after the final dbDelta() completes
            // before running INFORMATION_SCHEMA health checks.
            $postDdlState = $this->renewAtomicLock($wpdb, $lockState);
            if ($postDdlState === null) {
                return false;
            }
            $lockState = $postDdlState;

            $statusReport = $this->inspectDatabaseHealth($wpdb);
            foreach ($statusReport['tables'] as $key => $health) {
                if (!$health['exists']) {
                    $errors["table_{$key}"] = 'Table was not created.';
                } elseif (!$health['is_innodb']) {
                    $errors["engine_{$key}"] = 'Table storage engine is not InnoDB.';
                } elseif (!empty($health['missing_columns'])) {
                    $errors["columns_{$key}"] = 'Missing columns: ' . implode(', ', $health['missing_columns']);
                } elseif (!empty($health['missing_indexes'])) {
                    $errors["indexes_{$key}"] = 'Missing indexes: ' . implode(', ', $health['missing_indexes']);
                }
            }

            $finalized = $this->finalizeMigrationAtomically($wpdb, $lockState, $errors);
            if ($finalized) {
                $lockReleasedInTx = true;
                return empty($errors);
            }

            return false;
        } finally {
            $wpdb->suppress_errors($previousSuppress);
            if (!$lockReleasedInTx) {
                $this->releaseAtomicLock($wpdb, $lockState);
            }
        }
    }

    /**
     * Inspect the storage engine of {$wpdb->options}.
     * Returns the uppercase engine name (e.g., 'INNODB', 'MYISAM') or null if unavailable.
     */
    private function getOptionsTableEngine(\wpdb $wpdb): ?string
    {
        if (!isset($wpdb->options) || !is_string($wpdb->options) || $wpdb->options === '') {
            return null;
        }

        $engine = $wpdb->get_var(
            $wpdb->prepare(
                'SELECT ENGINE FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s LIMIT 1',
                $wpdb->options
            )
        );

        if (!is_string($engine) || $engine === '') {
            return null;
        }

        return strtoupper($engine);
    }

    /**
     * Atomically verify wp_options uses InnoDB, lock the migration lock row via
     * SELECT ... FOR UPDATE, write final DB_ERROR_OPTION / DB_VERSION_OPTION state,
     * and delete the owned lock row inside a single InnoDB transaction.
     *
     * Never alters the customer's wp_options storage engine automatically.
     *
     * @param array{token: string, expires_at: int, payload: string} $lockState
     * @param array<string, string> $errors
     */
    private function finalizeMigrationAtomically(\wpdb $wpdb, array $lockState, array $errors): bool
    {
        $optionsEngine = $this->getOptionsTableEngine($wpdb);
        if ($optionsEngine !== 'INNODB') {
            $detected = $optionsEngine ?? 'unknown/missing';
            $diagnostic = sprintf(
                'Atomic migration finalization aborted: WordPress options table (%s) must use the InnoDB storage engine to guarantee row-level transactional locking (detected: %s).',
                (string) ($wpdb->options ?? 'wp_options'),
                $detected
            );

            $errors['options_engine'] = $diagnostic;
            $encodedErrors = wp_json_encode($errors);

            // Best-effort diagnostic persistence only if this worker still owns the lock.
            if (is_string($encodedErrors) && $this->renewAtomicLock($wpdb, $lockState) !== null) {
                update_option(self::DB_ERROR_OPTION, $encodedErrors, false);
            }

            return false;
        }

        $wpdb->last_error = '';
        $started = $wpdb->query('START TRANSACTION');
        if ($started === false || $wpdb->last_error !== '') {
            return false;
        }

        try {
            // 1. Acquire an exclusive InnoDB row lock on the migration lock option.
            $wpdb->last_error = '';
            $currentRow = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT option_value FROM {$wpdb->options}
                     WHERE option_name = %s
                     FOR UPDATE",
                    self::LOCK_OPTION
                )
            );

            if ($wpdb->last_error !== '' || !is_string($currentRow) || $currentRow !== $lockState['payload']) {
                $wpdb->query('ROLLBACK');
                return false;
            }

            $decoded = json_decode($currentRow, true);
            if (
                !is_array($decoded)
                || !isset($decoded['token'], $decoded['expires_at'])
                || !is_string($decoded['token'])
                || !hash_equals($lockState['token'], $decoded['token'])
                || (int) $decoded['expires_at'] < time()
            ) {
                $wpdb->query('ROLLBACK');
                return false;
            }

            // 2. Perform fenced option updates inside the locked transaction.
            if (!empty($errors)) {
                $encodedErrors = wp_json_encode($errors);
                if (!is_string($encodedErrors)) {
                    $wpdb->query('ROLLBACK');
                    return false;
                }

                $wpdb->last_error = '';
                $errorWrite = $wpdb->query(
                    $wpdb->prepare(
                        "INSERT INTO {$wpdb->options} (option_name, option_value, autoload)
                         VALUES (%s, %s, 'no')
                         ON DUPLICATE KEY UPDATE option_value = VALUES(option_value), autoload = 'no'",
                        self::DB_ERROR_OPTION,
                        $encodedErrors
                    )
                );

                if ($errorWrite === false || $wpdb->last_error !== '') {
                    $wpdb->query('ROLLBACK');
                    return false;
                }
            } else {
                $wpdb->last_error = '';
                $clearError = $wpdb->query(
                    $wpdb->prepare(
                        "DELETE FROM {$wpdb->options} WHERE option_name = %s",
                        self::DB_ERROR_OPTION
                    )
                );

                if ($clearError === false || $wpdb->last_error !== '') {
                    $wpdb->query('ROLLBACK');
                    return false;
                }

                $wpdb->last_error = '';
                $versionWrite = $wpdb->query(
                    $wpdb->prepare(
                        "INSERT INTO {$wpdb->options} (option_name, option_value, autoload)
                         VALUES (%s, %s, 'yes')
                         ON DUPLICATE KEY UPDATE option_value = VALUES(option_value), autoload = 'yes'",
                        self::DB_VERSION_OPTION,
                        VXF_DB_VERSION
                    )
                );

                if ($versionWrite === false || $wpdb->last_error !== '') {
                    $wpdb->query('ROLLBACK');
                    return false;
                }
            }

            // 3. Release the exact owned lock row within the same atomic commit.
            $wpdb->last_error = '';
            $lockDeleted = $wpdb->query(
                $wpdb->prepare(
                    "DELETE FROM {$wpdb->options}
                     WHERE option_name = %s AND option_value = %s",
                    self::LOCK_OPTION,
                    $lockState['payload']
                )
            );

            if ($lockDeleted !== 1 || $wpdb->last_error !== '') {
                $wpdb->query('ROLLBACK');
                return false;
            }

            $wpdb->last_error = '';
            $committed = $wpdb->query('COMMIT');
            if ($committed === false || $wpdb->last_error !== '') {
                $wpdb->query('ROLLBACK');
                return false;
            }

            // Invalidate WordPress option caches strictly after COMMIT succeeds.
            $this->invalidateOptionCaches([
                self::LOCK_OPTION,
                self::DB_ERROR_OPTION,
                self::DB_VERSION_OPTION,
            ]);

            return true;
        } catch (\Throwable $e) {
            $wpdb->query('ROLLBACK');
            return false;
        }
    }

    /**
     * Invalidate WordPress object caches after direct SQL mutations on wp_options.
     *
     * @param string[] $optionNames
     */
    private function invalidateOptionCaches(array $optionNames): void
    {
        foreach ($optionNames as $optionName) {
            wp_cache_delete($optionName, 'options');
        }

        wp_cache_delete('alloptions', 'options');
        wp_cache_delete('notoptions', 'options');
    }

    /**
     * Encode an immutable lock payload string for exact compare-and-swap / compare-and-delete.
     */
    private function encodeLockPayload(string $token, int $expiresAt): ?string
    {
        $payload = wp_json_encode([
            'token'      => $token,
            'expires_at' => $expiresAt,
        ]);

        return is_string($payload) ? $payload : null;
    }

    /**
     * Atomically acquire a process lock in wp_options using INSERT uniqueness.
     * Reclaims expired or malformed abandoned locks via compare-and-swap UPDATE.
     *
     * @return array{token: string, expires_at: int, payload: string}|null
     */
    private function acquireAtomicLock(\wpdb $wpdb): ?array
    {
        $token     = bin2hex(random_bytes(16));
        $now       = time();
        $expiresAt = $now + self::LOCK_TTL_SECONDS;
        $payload   = $this->encodeLockPayload($token, $expiresAt);

        if ($payload === null) {
            return null;
        }

        // 1. Atomic insert: succeeds for at most one concurrent worker due to UNIQUE option_name.
        if (add_option(self::LOCK_OPTION, $payload, '', false)) {
            return [
                'token'      => $token,
                'expires_at' => $expiresAt,
                'payload'    => $payload,
            ];
        }

        // 2. Read raw option directly from MySQL (bypassing stale object cache) to check expiration.
        $existingRaw = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1",
                self::LOCK_OPTION
            )
        );

        if (!is_string($existingRaw)) {
            // Option disappeared between add_option() and SELECT; retry once atomically.
            if (add_option(self::LOCK_OPTION, $payload, '', false)) {
                return [
                    'token'      => $token,
                    'expires_at' => $expiresAt,
                    'payload'    => $payload,
                ];
            }
            return null;
        }

        $decoded   = json_decode($existingRaw, true);
        $isValid   = is_array($decoded)
            && isset($decoded['token'], $decoded['expires_at'])
            && is_string($decoded['token'])
            && strlen($decoded['token']) === 32;
        $isExpired = !$isValid || ((int) $decoded['expires_at'] < $now);

        if (!$isExpired) {
            // Active lease held by another worker.
            return null;
        }

        // 3. Atomic compare-and-swap reclamation: succeeds only if option_value is unchanged.
        $reclaimed = $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$wpdb->options}
                 SET option_value = %s
                 WHERE option_name = %s AND option_value = %s",
                $payload,
                self::LOCK_OPTION,
                $existingRaw
            )
        );

        if ($reclaimed === 1) {
            $this->invalidateOptionCaches([self::LOCK_OPTION]);
            return [
                'token'      => $token,
                'expires_at' => $expiresAt,
                'payload'    => $payload,
            ];
        }

        return null;
    }

    /**
     * Fenced lease renewal: extends expiration strictly beyond the previous expires_at
     * so the serialized payload is guaranteed to differ even when multiple renewals
     * occur within the same second.
     *
     * @param array{token: string, expires_at: int, payload: string} $lockState
     * @return array{token: string, expires_at: int, payload: string}|null
     */
    private function renewAtomicLock(\wpdb $wpdb, array $lockState): ?array
    {
        $decodedCurrent = json_decode($lockState['payload'], true);
        if (
            !is_array($decodedCurrent)
            || !isset($decodedCurrent['token'], $decodedCurrent['expires_at'])
            || !hash_equals($lockState['token'], (string) $decodedCurrent['token'])
        ) {
            return null;
        }

        $previousExpiresAt = max($lockState['expires_at'], (int) $decodedCurrent['expires_at']);
        $newExpiresAt      = max(time() + self::LOCK_TTL_SECONDS, $previousExpiresAt + 1);

        $newPayload = $this->encodeLockPayload($lockState['token'], $newExpiresAt);
        if ($newPayload === null || $newPayload === $lockState['payload']) {
            return null;
        }

        $updated = $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$wpdb->options}
                 SET option_value = %s
                 WHERE option_name = %s AND option_value = %s",
                $newPayload,
                self::LOCK_OPTION,
                $lockState['payload']
            )
        );

        if ($updated === 1) {
            $this->invalidateOptionCaches([self::LOCK_OPTION]);
            return [
                'token'      => $lockState['token'],
                'expires_at' => $newExpiresAt,
                'payload'    => $newPayload,
            ];
        }

        return null;
    }

    /**
     * Fenced atomic release: deletes the lock row ONLY if both option_name and exact
     * serialized payload (containing the worker's token) still match in MySQL.
     * An expired worker whose lock was reclaimed by a newer worker deletes 0 rows.
     *
     * @param array{token: string, expires_at: int, payload: string} $lockState
     */
    private function releaseAtomicLock(\wpdb $wpdb, array $lockState): void
    {
        $deleted = $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$wpdb->options}
                 WHERE option_name = %s AND option_value = %s",
                self::LOCK_OPTION,
                $lockState['payload']
            )
        );

        if ($deleted === 1) {
            $this->invalidateOptionCaches([self::LOCK_OPTION]);
        }
    }

    /**
     * Return comprehensive schema health status for Admin dashboard diagnostics.
     * Intended strictly for Admin UI / migration checks, never public frontend requests.
     *
     * @return array{
     *     db_version: string,
     *     expected_version: string,
     *     options_is_innodb: bool,
     *     is_healthy: bool,
     *     tables: array<string, array{
     *         exists: bool,
     *         is_innodb: bool,
     *         missing_columns: string[],
     *         missing_indexes: string[]
     *     }>,
     *     last_error: string
     * }
     */
    public function getStatus(): array
    {
        global $wpdb;

        if (!$wpdb instanceof \wpdb) {
            return [
                'db_version'        => '',
                'expected_version'  => VXF_DB_VERSION,
                'options_is_innodb' => false,
                'is_healthy'        => false,
                'tables'            => [],
                'last_error'        => 'Global $wpdb instance is unavailable.',
            ];
        }

        return $this->inspectDatabaseHealth($wpdb);
    }

    /**
     * Inspect wp_options engine, table existence, InnoDB storage engine,
     * required columns, and required indexes.
     *
     * @return array{
     *     db_version: string,
     *     expected_version: string,
     *     options_is_innodb: bool,
     *     is_healthy: bool,
     *     tables: array<string, array{
     *         exists: bool,
     *         is_innodb: bool,
     *         missing_columns: string[],
     *         missing_indexes: string[]
     *     }>,
     *     last_error: string
     * }
     */
    private function inspectDatabaseHealth(\wpdb $wpdb): array
    {
        $tablesStatus    = [];
        $allHealthy      = true;
        $optionsIsInnoDb = ($this->getOptionsTableEngine($wpdb) === 'INNODB');

        foreach (Schema::getTableNames($wpdb) as $key => $tableName) {
            $engine = $wpdb->get_var(
                $wpdb->prepare(
                    'SELECT ENGINE FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s LIMIT 1',
                    $tableName
                )
            );

            $exists   = is_string($engine) && $engine !== '';
            $isInnoDb = $exists && strtoupper($engine) === 'INNODB';

            $missingColumns = [];
            $missingIndexes = [];

            if ($exists) {
                $actualColumns = $wpdb->get_col(
                    $wpdb->prepare(
                        'SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s',
                        $tableName
                    )
                );
                $actualColumnsLower = array_map('strtolower', is_array($actualColumns) ? $actualColumns : []);

                foreach (self::REQUIRED_COLUMNS[$key] ?? [] as $reqCol) {
                    if (!in_array(strtolower($reqCol), $actualColumnsLower, true)) {
                        $missingColumns[] = $reqCol;
                    }
                }

                $actualIndexes = $wpdb->get_col(
                    $wpdb->prepare(
                        'SELECT DISTINCT INDEX_NAME FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s',
                        $tableName
                    )
                );
                $actualIndexesLower = array_map('strtolower', is_array($actualIndexes) ? $actualIndexes : []);

                foreach (self::REQUIRED_INDEXES[$key] ?? [] as $reqIdx) {
                    if (!in_array(strtolower($reqIdx), $actualIndexesLower, true)) {
                        $missingIndexes[] = $reqIdx;
                    }
                }
            } else {
                $missingColumns = self::REQUIRED_COLUMNS[$key] ?? [];
                $missingIndexes = self::REQUIRED_INDEXES[$key] ?? [];
            }

            if (!$exists || !$isInnoDb || !empty($missingColumns) || !empty($missingIndexes)) {
                $allHealthy = false;
            }

            $tablesStatus[$key] = [
                'exists'          => $exists,
                'is_innodb'       => $isInnoDb,
                'missing_columns' => $missingColumns,
                'missing_indexes' => $missingIndexes,
            ];
        }

        $installedVersion = (string) get_option(self::DB_VERSION_OPTION, '');
        $lastError        = (string) get_option(self::DB_ERROR_OPTION, '');

        return [
            'db_version'        => $installedVersion,
            'expected_version'  => VXF_DB_VERSION,
            'options_is_innodb' => $optionsIsInnoDb,
            'is_healthy'        => $optionsIsInnoDb && $allHealthy && ($installedVersion === VXF_DB_VERSION) && ($lastError === ''),
            'tables'            => $tablesStatus,
            'last_error'        => $lastError,
        ];
    }
}
