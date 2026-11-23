<?php
/**
 * Uninstall handler for Vanturax Forms.
 *
 * Retention & Lock Ownership Policy:
 * - Preserves all 5 custom database tables, form schemas, submission entries,
 *   integration logs, plugin settings, and custom role capabilities so
 *   reinstalling or upgrading never destroys customer data.
 * - Does not forcefully delete an active migration lock owned by a running
 *   worker; any abandoned lock self-recovers automatically upon lease expiration.
 */

declare(strict_types=1);

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}
