<?php

declare(strict_types=1);

namespace VanturaxForms\Database;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Concurrency-safe, transaction-compatible sequential entry counter per form.
 *
 * Uses INSERT ... ON DUPLICATE KEY UPDATE with LAST_INSERT_ID() evaluated
 * on both initial insert (1) and subsequent increments (last_entry_number + 1)
 * on the same $wpdb connection.
 */
final class CounterRepository
{
    private \wpdb $wpdb;
    private string $tableName;

    public function __construct(?\wpdb $wpdb = null)
    {
        if ($wpdb === null) {
            global $wpdb;
        }

        if (!$wpdb instanceof \wpdb) {
            throw new \RuntimeException('Valid $wpdb database instance is required.');
        }

        $this->wpdb      = $wpdb;
        $tables          = Schema::getTableNames($this->wpdb);
        $this->tableName = $tables['form_counters'];
    }

    /**
     * Atomically allocate and return the next sequential entry number for a form.
     *
     * Must be called inside an active InnoDB transaction during submission
     * creation so counter increments roll back cleanly if entry insertion fails.
     *
     * @throws \InvalidArgumentException If $formId is not positive.
     * @throws \RuntimeException If the counter query fails or returns an invalid sequence.
     */
    public function nextEntryNumber(int $formId): int
    {
        if ($formId <= 0) {
            throw new \InvalidArgumentException('Form ID must be a positive integer.');
        }

        $sql = $this->wpdb->prepare(
            "INSERT INTO {$this->tableName} (form_id, last_entry_number)
             VALUES (%d, LAST_INSERT_ID(1))
             ON DUPLICATE KEY UPDATE last_entry_number = LAST_INSERT_ID(last_entry_number + 1)",
            $formId
        );

        $this->wpdb->last_error = '';
        $result = $this->wpdb->query($sql);
        if ($result === false) {
            throw new \RuntimeException(
                sprintf('Failed to increment form entry counter: %s', $this->wpdb->last_error)
            );
        }

        $nextNumber = (int) $this->wpdb->get_var('SELECT LAST_INSERT_ID()');
        if ($nextNumber <= 0) {
            throw new \RuntimeException('Invalid entry sequence value returned from LAST_INSERT_ID().');
        }

        return $nextNumber;
    }

    /**
     * Read the current highest entry number without incrementing it.
     */
    public function getCurrentCount(int $formId): int
    {
        if ($formId <= 0) {
            return 0;
        }

        $count = $this->wpdb->get_var(
            $this->wpdb->prepare(
                "SELECT last_entry_number FROM {$this->tableName} WHERE form_id = %d",
                $formId
            )
        );

        return $count !== null ? (int) $count : 0;
    }
}
