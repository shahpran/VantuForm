<?php

declare(strict_types=1);

namespace VanturaxForms\Admin;

use VanturaxForms\Core\Capabilities;
use VanturaxForms\Database\Migrator;

if (!defined('ABSPATH')) {
    exit;
}

final class AdminMenu
{
    public const MENU_SLUG = 'vanturax-forms';

    private static string $pageHookSuffix = '';
    private Migrator $migrator;

    public function __construct(Migrator $migrator)
    {
        $this->migrator = $migrator;
    }

    public function registerHooks(): void
    {
        add_action('admin_menu', [$this, 'registerMenu']);
        add_action('admin_notices', [$this, 'renderDatabaseErrorNotice']);
    }

    public static function getPageHookSuffix(): string
    {
        return self::$pageHookSuffix;
    }

    /**
     * Register top-level Vanturax Forms menu in WordPress Admin.
     */
    public function registerMenu(): void
    {
        if (!Capabilities::currentUserCanAccessAdmin()) {
            return;
        }

        $capability = current_user_can(Capabilities::MANAGE_FORMS)
            ? Capabilities::MANAGE_FORMS
            : Capabilities::VIEW_SUBMISSIONS;

        $hook = add_menu_page(
            __('Vanturax Forms', 'vanturax-forms'),
            __('Vanturax Forms', 'vanturax-forms'),
            $capability,
            self::MENU_SLUG,
            [$this, 'renderAdminPage'],
            'dashicons-feedback',
            26
        );

        if (is_string($hook)) {
            self::$pageHookSuffix = $hook;
        }
    }

    /**
     * Display an admin warning if database table installation failed.
     */
    public function renderDatabaseErrorNotice(): void
    {
        if (!Capabilities::currentUserCanAccessAdmin()) {
            return;
        }

        $dbError = (string) get_option(Migrator::DB_ERROR_OPTION, '');
        if ($dbError === '') {
            return;
        }

        printf(
            '<div class="notice notice-error"><p><strong>%s:</strong> %s</p></div>',
            esc_html__('Vanturax Forms Database Error', 'vanturax-forms'),
            esc_html($dbError)
        );
    }

    /**
     * Render the Admin page container with progressive-enhancement fallback
     * if compiled Vite assets have not yet been built.
     */
    public function renderAdminPage(): void
    {
        if (!Capabilities::currentUserCanAccessAdmin()) {
            wp_die(
                esc_html__('You do not have sufficient permissions to access Vanturax Forms.', 'vanturax-forms'),
                403
            );
        }

        $buildExists = file_exists(VXF_PLUGIN_DIR . 'assets/build/admin.js');
        $dbStatus    = $this->migrator->getStatus();
        ?>
        <div class="wrap vxf-admin-wrap">
            <div id="vxf-admin-root"
                 data-plugin-version="<?php echo esc_attr(VXF_VERSION); ?>"
                 data-db-healthy="<?php echo $dbStatus['is_healthy'] ? '1' : '0'; ?>">
                <?php if (!$buildExists) : ?>
                    <div class="notice notice-warning inline">
                        <p>
                            <strong><?php echo esc_html__('Vanturax Forms', 'vanturax-forms'); ?></strong>
                            (v<?php echo esc_html(VXF_VERSION); ?>) —
                            <?php echo esc_html__('Compiled Admin UI assets (assets/build/admin.js) were not found. Run "npm run build" locally and upload the compiled build directory.', 'vanturax-forms'); ?>
                        </p>
                    </div>
                <?php else : ?>
                    <p><?php echo esc_html__('Loading Vanturax Forms Dashboard…', 'vanturax-forms'); ?></p>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }
}
