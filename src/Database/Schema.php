<?php

declare(strict_types=1);

namespace VanturaxForms\Database;

if (!defined('ABSPATH')) {
    exit;
}

final class Schema
{
    /**
     * Return full prefixed table names for all 5 core tables.
     *
     * @return array<string, string>
     */
    public static function getTableNames(\wpdb $wpdb): array
    {
        $prefix = $wpdb->prefix;

        return [
            'forms'            => $prefix . 'vanturax_forms',
            'submissions'      => $prefix . 'vanturax_submissions',
            'submission_meta'  => $prefix . 'vanturax_submission_meta',
            'integration_logs' => $prefix . 'vanturax_integration_logs',
            'form_counters'    => $prefix . 'vanturax_form_counters',
        ];
    }

    /**
     * Return dbDelta-compatible CREATE TABLE SQL statements for all 5 tables.
     *
     * Index compatibility notes:
     * - Every table explicitly uses ENGINE=InnoDB and utf8mb4.
     * - submission_meta.field_key is VARCHAR(64) (256 bytes under utf8mb4).
     * - idx_form_field_hash (8 + 256 + 16 = 280 bytes) and
     *   idx_form_field_prefix (8 + 256 + 400 = 664 bytes) remain strictly
     *   under the legacy 767-byte InnoDB index key ceiling.
     *
     * @return array<string, string>
     */
    public static function getTableSchemas(\wpdb $wpdb): array
    {
        $tables  = self::getTableNames($wpdb);
        $charset = $wpdb->get_charset_collate();

        return [
            'forms' => "CREATE TABLE {$tables['forms']} (
                id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                title VARCHAR(255) NOT NULL,
                slug VARCHAR(191) NOT NULL,
                status ENUM('publish', 'draft', 'trash') NOT NULL DEFAULT 'publish',
                schema_version VARCHAR(20) NOT NULL DEFAULT '1.0.0',
                schema_json LONGTEXT NOT NULL,
                settings_json LONGTEXT NOT NULL,
                revision INT(11) UNSIGNED NOT NULL DEFAULT 1,
                created_by BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY idx_slug (slug),
                KEY idx_status_updated (status, updated_at)
            ) ENGINE=InnoDB {$charset};",

            'form_counters' => "CREATE TABLE {$tables['form_counters']} (
                form_id BIGINT(20) UNSIGNED NOT NULL,
                last_entry_number BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
                PRIMARY KEY  (form_id)
            ) ENGINE=InnoDB {$charset};",

            'submissions' => "CREATE TABLE {$tables['submissions']} (
                id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                form_id BIGINT(20) UNSIGNED NOT NULL,
                entry_number BIGINT(20) UNSIGNED NOT NULL,
                submission_uuid CHAR(36) NOT NULL,
                status ENUM('unread', 'read', 'starred', 'spam', 'trash') NOT NULL DEFAULT 'unread',
                user_id BIGINT(20) UNSIGNED NULL DEFAULT NULL,
                ip_hash CHAR(64) NULL DEFAULT NULL,
                user_agent VARCHAR(255) NULL DEFAULT NULL,
                source_url TEXT NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY idx_submission_uuid (submission_uuid),
                UNIQUE KEY idx_form_entry (form_id, entry_number),
                KEY idx_form_status_created (form_id, status, created_at),
                KEY idx_created_at (created_at)
            ) ENGINE=InnoDB {$charset};",

            'submission_meta' => "CREATE TABLE {$tables['submission_meta']} (
                id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                submission_id BIGINT(20) UNSIGNED NOT NULL,
                form_id BIGINT(20) UNSIGNED NOT NULL,
                field_key VARCHAR(64) NOT NULL,
                field_type VARCHAR(50) NOT NULL,
                meta_value LONGTEXT NULL,
                meta_hash BINARY(16) NULL DEFAULT NULL,
                meta_prefix VARCHAR(100) NULL DEFAULT NULL,
                PRIMARY KEY  (id),
                KEY idx_submission_field (submission_id, field_key),
                KEY idx_form_field_hash (form_id, field_key, meta_hash),
                KEY idx_form_field_prefix (form_id, field_key, meta_prefix)
            ) ENGINE=InnoDB {$charset};",

            'integration_logs' => "CREATE TABLE {$tables['integration_logs']} (
                id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                submission_id BIGINT(20) UNSIGNED NOT NULL,
                form_id BIGINT(20) UNSIGNED NOT NULL,
                integration_slug VARCHAR(64) NOT NULL,
                status ENUM('pending', 'processing', 'success', 'failed', 'retrying') NOT NULL DEFAULT 'pending',
                worker_token CHAR(32) NULL DEFAULT NULL,
                locked_at DATETIME NULL DEFAULT NULL,
                lock_expires_at DATETIME NULL DEFAULT NULL,
                attempts TINYINT(3) UNSIGNED NOT NULL DEFAULT 0,
                response_code SMALLINT(5) NULL DEFAULT NULL,
                response_message TEXT NULL,
                next_retry_at DATETIME NULL DEFAULT NULL,
                executed_at DATETIME NULL DEFAULT NULL,
                created_at DATETIME NOT NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY idx_submission_integration (submission_id, integration_slug),
                KEY idx_queue_poll (status, next_retry_at),
                KEY idx_stale_locks (status, lock_expires_at),
                KEY idx_form_status (form_id, status)
            ) ENGINE=InnoDB {$charset};",
        ];
    }
}
