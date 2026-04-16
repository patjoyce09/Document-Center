<?php
/**
 * Plugin Name: Document Center Builder
 * Plugin URI: https://example.com
 * Description: Standalone digital document/form system with builder UI, conditional logic, OCR-assisted drafting, submissions, signatures, and diagnostics.
 * Version: 0.3.0
 * Requires at least: 6.4
 * Requires PHP: 8.0
 * Author: Joyce Systems
 * Text Domain: document-center-builder
 * Domain Path: /languages
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!defined('DCB_VERSION')) {
    define('DCB_VERSION', '0.3.0');
}
if (!defined('DCB_PLUGIN_FILE')) {
    define('DCB_PLUGIN_FILE', __FILE__);
}
if (!defined('DCB_PLUGIN_BASENAME')) {
    define('DCB_PLUGIN_BASENAME', plugin_basename(__FILE__));
}
if (!defined('DCB_PLUGIN_DIR')) {
    define('DCB_PLUGIN_DIR', plugin_dir_path(__FILE__));
}
if (!defined('DCB_PLUGIN_URL')) {
    define('DCB_PLUGIN_URL', plugin_dir_url(__FILE__));
}

require_once DCB_PLUGIN_DIR . 'includes/helpers-schema.php';
require_once DCB_PLUGIN_DIR . 'includes/helpers-ocr.php';
require_once DCB_PLUGIN_DIR . 'includes/helpers-render.php';
require_once DCB_PLUGIN_DIR . 'includes/class-loader.php';

if (!function_exists('dcb_menu_slug_exists')) {
    function dcb_menu_slug_exists(string $slug): bool {
        global $menu, $submenu;

        foreach ((array) $menu as $item) {
            if (isset($item[2]) && (string) $item[2] === $slug) {
                return true;
            }
        }

        foreach ((array) $submenu as $rows) {
            foreach ((array) $rows as $item) {
                if (isset($item[2]) && (string) $item[2] === $slug) {
                    return true;
                }
            }
        }

        return false;
    }
}

if (!function_exists('dcb_render_recovery_dashboard')) {
    function dcb_render_recovery_dashboard(): void {
        echo '<div class="wrap">';
        echo '<h1>Document Center Builder</h1>';

        if (class_exists('DCB_Admin') && method_exists('DCB_Admin', 'render_dashboard')) {
            DCB_Admin::render_dashboard();
            echo '</div>';
            return;
        }

        echo '<div class="notice notice-warning"><p>Document Center loaded in recovery mode. Core admin handlers were not available at menu render time.</p></div>';
        echo '<p><a class="button button-primary" href="' . esc_url(admin_url('plugins.php')) . '">Back to Plugins</a></p>';
        echo '</div>';
    }
}

if (!function_exists('dcb_register_recovery_menu')) {
    function dcb_register_recovery_menu(): void {
        if (!current_user_can('activate_plugins')) {
            return;
        }

        if (dcb_menu_slug_exists('dcb-dashboard')) {
            return;
        }

        add_menu_page(
            __('Document Center', 'document-center-builder'),
            __('Document Center', 'document-center-builder'),
            'activate_plugins',
            'dcb-dashboard',
            'dcb_render_recovery_dashboard',
            'dashicons-forms',
            35
        );
    }
}

add_action('admin_menu', 'dcb_register_recovery_menu', 999);

register_activation_hook(__FILE__, array('DCB_Loader', 'activate'));

DCB_Loader::instance()->boot();
