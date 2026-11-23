<?php
/**
 * Plugin Name:       Vanturax Forms
 * Plugin URI:        https://vanturax.com/forms
 * Description:       Lightweight, high-performance commercial WordPress Form Builder with multi-step forms, conditional logic, and modular integrations.
 * Version:           1.0.0
 * Requires at least: 6.2
 * Requires PHP:      8.0
 * Author:            Vanturax
 * Author URI:        https://vanturax.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       vanturax-forms
 * Domain Path:       /languages
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

define('VXF_VERSION', '1.0.0');
define('VXF_DB_VERSION', '1.0.0');
define('VXF_MIN_PHP_VERSION', '8.0.0');
define('VXF_MIN_WP_VERSION', '6.2');
define('VXF_PLUGIN_FILE', __FILE__);
define('VXF_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('VXF_PLUGIN_URL', plugin_dir_url(__FILE__));
define('VXF_PLUGIN_BASENAME', plugin_basename(__FILE__));

/**
 * Verify minimum PHP and WordPress requirements before loading classes.
 */
function vxf_check_environment(): bool
{
    if (version_compare(PHP_VERSION, VXF_MIN_PHP_VERSION, '<')) {
        add_action('admin_notices', static function (): void {
            if (!current_user_can('activate_plugins')) {
                return;
            }
            printf(
                '<div class="notice notice-error"><p>%s</p></div>',
                esc_html(
                    sprintf(
                        /* translators: 1: Minimum PHP version, 2: Current PHP version */
                        __('Vanturax Forms requires PHP %1$s or higher. Your server is running PHP %2$s.', 'vanturax-forms'),
                        VXF_MIN_PHP_VERSION,
                        PHP_VERSION
                    )
                )
            );
        });
        return false;
    }

    global $wp_version;
    if (isset($wp_version) && version_compare($wp_version, VXF_MIN_WP_VERSION, '<')) {
        add_action('admin_notices', static function () use ($wp_version): void {
            if (!current_user_can('activate_plugins')) {
                return;
            }
            printf(
                '<div class="notice notice-error"><p>%s</p></div>',
                esc_html(
                    sprintf(
                        /* translators: 1: Minimum WordPress version, 2: Current WordPress version */
                        __('Vanturax Forms requires WordPress %1$s or higher. Your site is running WordPress %2$s.', 'vanturax-forms'),
                        VXF_MIN_WP_VERSION,
                        $wp_version
                    )
                )
            );
        });
        return false;
    }

    return true;
}

if (!vxf_check_environment()) {
    return;
}

$vxf_autoloader = VXF_PLUGIN_DIR . 'vendor/autoload.php';
if (!file_exists($vxf_autoloader)) {
    add_action('admin_notices', static function (): void {
        if (!current_user_can('activate_plugins')) {
            return;
        }
        printf(
            '<div class="notice notice-error"><p>%s</p></div>',
            esc_html__('Vanturax Forms error: Composer autoloader (vendor/autoload.php) is missing. Please install a complete release package or run composer install.', 'vanturax-forms')
        );
    });
    return;
}

require_once $vxf_autoloader;

use VanturaxForms\Core\Plugin;

register_activation_hook(VXF_PLUGIN_FILE, [Plugin::class, 'activate']);
register_deactivation_hook(VXF_PLUGIN_FILE, [Plugin::class, 'deactivate']);

add_action('plugins_loaded', static function (): void {
    Plugin::instance()->boot();
});
