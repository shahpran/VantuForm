<?php

declare(strict_types=1);

namespace VanturaxForms\Database;

use VanturaxForms\Exceptions\FormConflictException;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Data access layer for {prefix}vanturax_forms and associated tables.
 */
final class FormRepository
{
    private \wpdb $wpdb;
    private string $tableName;
    private string $submissionsTable;
    private string $submissionMetaTable;
    private string $integrationLogsTable;
    private string $countersTable;

    public function __construct(?\wpdb $wpdb = null)
    {
        if ($wpdb === null) {
            global $wpdb;
        }

        if (!$wpdb instanceof \wpdb) {
            throw new \RuntimeException('Valid $wpdb database instance is required.');
        }

        $this->wpdb                 = $wpdb;
        $tables                     = Schema::getTableNames($this->wpdb);
        $this->tableName            = $tables['forms'];
        $this->submissionsTable     = $tables['submissions'];
        $this->submissionMetaTable  = $tables['submission_meta'];
        $this->integrationLogsTable = $tables['integration_logs'];
        $this->countersTable        = $tables['form_counters'];
    }

    /**
     * Retrieve paginated forms with submission counts.
     *
     * @param array{status?: string, search?: string, page?: int, per_page?: int} $args
     * @return array{items: array<int, array<string, mixed>>, total: int}
     */
    public function paginate(array $args = []): array
    {
        $status  = $args['status'] ?? '';
        $search  = trim((string) ($args['search'] ?? ''));
        $page    = max(1, (int) ($args['page'] ?? 1));
        $perPage = max(1, min(100, (int) ($args['per_page'] ?? 20)));
        $offset  = ($page - 1) * $perPage;

        $whereClauses = ['1=1'];
        $params       = [];

        if ($status !== '' && in_array($status, ['publish', 'draft', 'trash'], true)) {
            $whereClauses[] = 'f.status = %s';
            $params[]       = $status;
        } else {
            $whereClauses[] = "f.status != 'trash'";
        }

        if ($search !== '') {
            $whereClauses[] = '(f.title LIKE %s OR f.slug LIKE %s)';
            $like           = '%' . $this->wpdb->esc_like($search) . '%';
            $params[]       = $like;
            $params[]       = $like;
        }

        $whereSql = implode(' AND ', $whereClauses);

        $countSql = "SELECT COUNT(*) FROM {$this->tableName} f WHERE {$whereSql}";
        if (!empty($params)) {
            $countSql = $this->wpdb->prepare($countSql, ...$params);
        }

        $this->wpdb->last_error = '';
        $total = (int) $this->wpdb->get_var($countSql);
        if ($this->wpdb->last_error !== '') {
            throw new \RuntimeException(sprintf('Database error counting forms: %s', $this->wpdb->last_error));
        }

        $queryParams = array_merge($params, [$perPage, $offset]);
        $selectSql   = $this->wpdb->prepare(
            "SELECT f.id, f.title, f.slug, f.status, f.schema_version, f.revision,
                    f.created_by, f.created_at, f.updated_at,
                    (SELECT COUNT(*) FROM {$this->submissionsTable} s WHERE s.form_id = f.id AND s.status != 'trash') AS entry_count
             FROM {$this->tableName} f
             WHERE {$whereSql}
             ORDER BY f.updated_at DESC, f.id DESC
             LIMIT %d OFFSET %d",
            ...$queryParams
        );

        $this->wpdb->last_error = '';
        $rows = $this->wpdb->get_results($selectSql, ARRAY_A);
        if ($this->wpdb->last_error !== '') {
            throw new \RuntimeException(sprintf('Database error listing forms: %s', $this->wpdb->last_error));
        }

        return [
            'items' => is_array($rows) ? $rows : [],
            'total' => $total,
        ];
    }

    /**
     * Find a single form row by ID.
     *
     * @return array<string, mixed>|null
     */
    public function findById(int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }

        $this->wpdb->last_error = '';
        $row = $this->wpdb->get_row(
            $this->wpdb->prepare(
                "SELECT id, title, slug, status, schema_version, schema_json, settings_json,
                        revision, created_by, created_at, updated_at
                 FROM {$this->tableName}
                 WHERE id = %d
                 LIMIT 1",
                $id
            ),
            ARRAY_A
        );

        if ($this->wpdb->last_error !== '') {
            throw new \RuntimeException(sprintf('Database error fetching form: %s', $this->wpdb->last_error));
        }

        return is_array($row) ? $row : null;
    }

    /**
     * Check if a slug is already taken (optionally excluding a given form ID).
     */
    public function slugExists(string $slug, ?int $excludeId = null): bool
    {
        if ($excludeId !== null && $excludeId > 0) {
            $found = $this->wpdb->get_var(
                $this->wpdb->prepare(
                    "SELECT id FROM {$this->tableName} WHERE slug = %s AND id != %d LIMIT 1",
                    $slug,
                    $excludeId
                )
            );
        } else {
            $found = $this->wpdb->get_var(
                $this->wpdb->prepare(
                    "SELECT id FROM {$this->tableName} WHERE slug = %s LIMIT 1",
                    $slug
                )
            );
        }

        return $found !== null;
    }

    /**
     * Generate a unique slug candidate within the 191-character VARCHAR limit.
     */
    public function generateUniqueSlug(string $desiredSlug, ?int $excludeId = null): string
    {
        $base = sanitize_title($desiredSlug);
        if ($base === '') {
            $base = 'form';
        }

        $base      = mb_substr($base, 0, 175);
        $candidate = $base;
        $suffix    = 2;

        while ($this->slugExists($candidate, $excludeId)) {
            $suffixStr = '-' . $suffix;
            $candidate = mb_substr($base, 0, 191 - strlen($suffixStr)) . $suffixStr;
            $suffix++;
        }

        return $candidate;
    }

    /**
     * Insert a new form row with atomic duplicate-slug collision retry.
     *
     * @param array{
     *     title: string,
     *     desired_slug: string,
     *     status: string,
     *     schema_version: string,
     *     schema_json: string,
     *     settings_json: string,
     *     created_by: int
     * } $data
     * @throws \RuntimeException On non-duplicate database insertion failure.
     */
    public function insertWithUniqueSlug(array $data): int
    {
        $now              = current_time('mysql', true);
        $previousSuppress = $this->wpdb->suppress_errors(true);

        try {
            $maxAttempts = 10;

            for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
                $slug = $this->generateUniqueSlug($data['desired_slug']);
                if ($attempt > 1) {
                    $randomSuffix = '-' . bin2hex(random_bytes(3));
                    $slug         = mb_substr($slug, 0, 191 - strlen($randomSuffix)) . $randomSuffix;
                }

                $this->wpdb->last_error = '';
                $inserted = $this->wpdb->query(
                    $this->wpdb->prepare(
                        "INSERT INTO {$this->tableName}
                         (title, slug, status, schema_version, schema_json, settings_json, revision, created_by, created_at, updated_at)
                         VALUES (%s, %s, %s, %s, %s, %s, 1, %d, %s, %s)",
                        $data['title'],
                        $slug,
                        $data['status'],
                        $data['schema_version'],
                        $data['schema_json'],
                        $data['settings_json'],
                        $data['created_by'],
                        $now,
                        $now
                    )
                );

                if ($inserted === 1 && $this->wpdb->last_error === '') {
                    $insertId = (int) $this->wpdb->insert_id;
                    if ($insertId <= 0) {
                        throw new \RuntimeException('Failed to retrieve newly created form ID.');
                    }
                    return $insertId;
                }

                if ($this->isDuplicateEntryError($this->wpdb->last_error) && $attempt < $maxAttempts) {
                    continue;
                }

                throw new \RuntimeException(
                    sprintf('Database error while creating form: %s', $this->wpdb->last_error)
                );
            }

            throw new \RuntimeException('Unable to allocate a unique form slug after multiple concurrent attempts.');
        } finally {
            $this->wpdb->suppress_errors($previousSuppress);
        }
    }

    /**
     * Update an existing form row using strict optimistic concurrency control
     * and safe duplicate-slug collision handling.
     *
     * @param array{
     *     title: string,
     *     slug: string,
     *     status: string,
     *     schema_version: string,
     *     schema_json: string,
     *     settings_json: string
     * } $data
     * @throws FormConflictException When expectedRevision no longer matches or a concurrent slug collision occurs.
     * @throws \RuntimeException On actual SQL/database failure.
     */
    public function updateWithOptimisticLock(int $id, int $expectedRevision, array $data): bool
    {
        $now              = current_time('mysql', true);
        $previousSuppress = $this->wpdb->suppress_errors(true);

        try {
            $this->wpdb->last_error = '';
            $updated = $this->wpdb->query(
                $this->wpdb->prepare(
                    "UPDATE {$this->tableName}
                     SET title = %s,
                         slug = %s,
                         status = %s,
                         schema_version = %s,
                         schema_json = %s,
                         settings_json = %s,
                         revision = revision + 1,
                         updated_at = %s
                     WHERE id = %d AND revision = %d",
                    $data['title'],
                    $data['slug'],
                    $data['status'],
                    $data['schema_version'],
                    $data['schema_json'],
                    $data['settings_json'],
                    $now,
                    $id,
                    $expectedRevision
                )
            );

            if ($this->isDuplicateEntryError($this->wpdb->last_error)) {
                throw new FormConflictException(
                    __('The requested form slug is already in use by another form. Please choose a different slug and try again.', 'vanturax-forms')
                );
            }

            if ($updated === false || $this->wpdb->last_error !== '') {
                throw new \RuntimeException(
                    sprintf('Database error while updating form: %s', $this->wpdb->last_error)
                );
            }

            if ($updated === 1) {
                return true;
            }
        } finally {
            $this->wpdb->suppress_errors($previousSuppress);
        }

        // Distinguish between non-existent form (404) and revision mismatch (409 Conflict).
        $currentRow = $this->findById($id);
        if ($currentRow === null) {
            return false;
        }

        throw new FormConflictException(
            __('This form was modified by another user since you opened it. Please reload the latest version before saving.', 'vanturax-forms')
        );
    }

    /**
     * Soft-delete a form by moving it to 'trash'.
     *
     * @return 'trashed'|'already_trashed'|'not_found'
     */
    public function trash(int $id): string
    {
        $existing = $this->findById($id);
        if ($existing === null) {
            return 'not_found';
        }

        if (($existing['status'] ?? '') === 'trash') {
            return 'already_trashed';
        }

        $now = current_time('mysql', true);

        $this->wpdb->last_error = '';
        $updated = $this->wpdb->query(
            $this->wpdb->prepare(
                "UPDATE {$this->tableName}
                 SET status = 'trash', revision = revision + 1, updated_at = %s
                 WHERE id = %d",
                $now,
                $id
            )
        );

        if ($updated === false || $this->wpdb->last_error !== '') {
            throw new \RuntimeException(
                sprintf('Database error while moving form to trash: %s', $this->wpdb->last_error)
            );
        }

        return 'trashed';
    }

    /**
     * Restore a trashed form back to 'draft' status safely.
     *
     * @return 'restored'|'not_trashed'|'not_found'
     */
    public function restore(int $id): string
    {
        $existing = $this->findById($id);
        if ($existing === null) {
            return 'not_found';
        }

        if (($existing['status'] ?? '') !== 'trash') {
            return 'not_trashed';
        }

        $now = current_time('mysql', true);

        $this->wpdb->last_error = '';
        $updated = $this->wpdb->query(
            $this->wpdb->prepare(
                "UPDATE {$this->tableName}
                 SET status = 'draft', revision = revision + 1, updated_at = %s
                 WHERE id = %d AND status = 'trash'",
                $now,
                $id
            )
        );

        if ($updated === false || $this->wpdb->last_error !== '') {
            throw new \RuntimeException(
                sprintf('Database error while restoring form: %s', $this->wpdb->last_error)
            );
        }

        return 'restored';
    }

    /**
     * Permanently delete a trashed form and all of its associated child records
     * inside a single InnoDB transaction so no orphaned records remain.
     */
    public function deletePermanentlyWithAssociatedData(int $id): bool
    {
        $this->wpdb->last_error = '';
        $started = $this->wpdb->query('START TRANSACTION');
        if ($started === false || $this->wpdb->last_error !== '') {
            throw new \RuntimeException('Failed to start database transaction for permanent deletion.');
        }

        try {
            $childTables = [
                $this->integrationLogsTable,
                $this->submissionMetaTable,
                $this->submissionsTable,
                $this->countersTable,
            ];

            foreach ($childTables as $childTable) {
                $this->wpdb->last_error = '';
                $res = $this->wpdb->query(
                    $this->wpdb->prepare("DELETE FROM {$childTable} WHERE form_id = %d", $id)
                );

                if ($res === false || $this->wpdb->last_error !== '') {
                    $this->wpdb->query('ROLLBACK');
                    throw new \RuntimeException(
                        sprintf('Failed deleting associated records from %s: %s', $childTable, $this->wpdb->last_error)
                    );
                }
            }

            $this->wpdb->last_error = '';
            $deletedForm = $this->wpdb->query(
                $this->wpdb->prepare(
                    "DELETE FROM {$this->tableName} WHERE id = %d AND status = 'trash'",
                    $id
                )
            );

            if ($deletedForm === false || $this->wpdb->last_error !== '') {
                $this->wpdb->query('ROLLBACK');
                throw new \RuntimeException(
                    sprintf('Failed deleting form record: %s', $this->wpdb->last_error)
                );
            }

            if ($deletedForm !== 1) {
                $this->wpdb->query('ROLLBACK');
                return false;
            }

            $this->wpdb->last_error = '';
            $committed = $this->wpdb->query('COMMIT');
            if ($committed === false || $this->wpdb->last_error !== '') {
                $this->wpdb->query('ROLLBACK');
                throw new \RuntimeException('Failed committing permanent form deletion transaction.');
            }

            return true;
        } catch (\Throwable $e) {
            $this->wpdb->query('ROLLBACK');
            throw $e;
        }
    }

    private function isDuplicateEntryError(string $error): bool
    {
        if ($error === '') {
            return false;
        }

        return stripos($error, 'Duplicate entry') !== false
            || strpos($error, '1062') !== false;
    }
}
