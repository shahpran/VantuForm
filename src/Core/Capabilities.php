<?php

declare(strict_types=1);

namespace VanturaxForms\Core;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Capability lifecycle policy:
 * - Granted to 'administrator' on plugin activation.
 * - Preserved across ordinary deactivation and standard uninstall so role
 *   customizations made by site owners are never unexpectedly wiped.
 * - Removed only if a future explicit admin-confirmed "Purge All Data" action
 *   is invoked by an administrator.
 */
final class Capabilities
{
    public const MANAGE_FORMS       = 'vxf_manage_forms';
    public const VIEW_SUBMISSIONS   = 'vxf_view_submissions';
    public const MANAGE_SUBMISSIONS = 'vxf_manage_submissions';

    /**
     * Return all custom plugin capabilities.
     *
     * @return string[]
     */
    public static function all(): array
    {
        return [
            self::MANAGE_FORMS,
            self::VIEW_SUBMISSIONS,
            self::MANAGE_SUBMISSIONS,
        ];
    }

    /**
     * Grant default capabilities to the Administrator role on activation.
     */
    public static function register(): void
    {
        $adminRole = get_role('administrator');
        if (!$adminRole instanceof \WP_Role) {
            return;
        }

        foreach (self::all() as $capability) {
            if (!$adminRole->has_cap($capability)) {
                $adminRole->add_cap($capability);
            }
        }
    }

    /**
     * Check if the current user can access the Vanturax Forms admin area.
     */
    public static function currentUserCanAccessAdmin(): bool
    {
        return current_user_can(self::MANAGE_FORMS) || current_user_can(self::VIEW_SUBMISSIONS);
    }
}
