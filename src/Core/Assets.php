<?php

declare(strict_types=1);

namespace VanturaxForms\Core;

use VanturaxForms\Admin\AdminMenu;
use VanturaxForms\Database\Migrator;
use VanturaxForms\Fields\FieldRegistry;

if (!defined('ABSPATH')) {
    exit;
}

final class Assets
{
    public const ADMIN_SCRIPT_HANDLE = 'vxf-admin-app';
    public const ADMIN_STYLE_HANDLE  = 'vxf-admin-style';

    private Migrator $migrator;
    private FieldRegistry $fieldRegistry;

    public function __construct(Migrator $migrator, FieldRegistry $fieldRegistry)
    {
        $this->migrator      = $migrator;
        $this->fieldRegistry = $fieldRegistry;
    }

    public function registerHooks(): void
    {
        add_action('admin_enqueue_scripts', [$this, 'enqueueAdminAssets']);
    }

    /**
     * Conditionally enqueue Admin SPA scripts and styles ONLY on Vanturax Forms pages.
     */
    public function enqueueAdminAssets(string $hookSuffix): void
    {
        if ($hookSuffix !== AdminMenu::getPageHookSuffix()) {
            return;
        }

        if (!Capabilities::currentUserCanAccessAdmin()) {
            return;
        }

        $scriptPath = VXF_PLUGIN_DIR . 'assets/build/admin.js';
        $stylePath  = VXF_PLUGIN_DIR . 'assets/build/admin.css';

        $scriptVersion = file_exists($scriptPath) ? (string) filemtime($scriptPath) : VXF_VERSION;
        $styleVersion  = file_exists($stylePath) ? (string) filemtime($stylePath) : VXF_VERSION;

        if (file_exists($stylePath)) {
            wp_enqueue_style(
                self::ADMIN_STYLE_HANDLE,
                VXF_PLUGIN_URL . 'assets/build/admin.css',
                [],
                $styleVersion
            );
        }

        if (file_exists($scriptPath)) {
            wp_enqueue_script(
                self::ADMIN_SCRIPT_HANDLE,
                VXF_PLUGIN_URL . 'assets/build/admin.js',
                ['wp-element', 'wp-api-fetch'],
                $scriptVersion,
                true
            );

            wp_localize_script(
                self::ADMIN_SCRIPT_HANDLE,
                'vxfAdminData',
                $this->getAdminBootstrapData()
            );
        }
    }

    /**
     * Build sanitized configuration payload passed to the Admin React application.
     *
     * @return array<string, mixed>
     */
    private function getAdminBootstrapData(): array
    {
        $currentUser = wp_get_current_user();

        return [
            'pluginName'       => 'Vanturax Forms',
            'version'          => VXF_VERSION,
            'dbVersion'        => VXF_DB_VERSION,
            'restUrl'          => esc_url_raw(rest_url('vanturax-forms/v1')),
            'restNonce'        => wp_create_nonce(Security::REST_NONCE_ACTION),
            'adminNonce'       => Security::createAdminNonce(),
            'siteUrl'          => esc_url_raw(home_url('/')),
            'buildExists'      => file_exists(VXF_PLUGIN_DIR . 'assets/build/admin.js'),
            'dbStatus'         => $this->migrator->getStatus(),
            'fieldDefinitions' => $this->fieldRegistry->toClientArray(),
            'user'             => [
                'id'          => (int) $currentUser->ID,
                'displayName' => sanitize_text_field((string) $currentUser->display_name),
                'canManage'   => current_user_can(Capabilities::MANAGE_FORMS),
            ],
        ];
    }
}
