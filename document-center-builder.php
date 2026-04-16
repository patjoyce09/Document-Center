<?php
/**
 * Plugin Name: Document Center Builder
 * Plugin URI: https://example.com
 * Description: Standalone digital document/form system with builder UI, conditional logic, OCR-assisted drafting, submissions, signatures, and diagnostics.
 * Version: 0.3.4
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
    define('DCB_VERSION', '0.3.4');
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

if (!function_exists('dcb_render_direct_dashboard')) {
    function dcb_render_direct_dashboard(): void {
        if (class_exists('DCB_Admin') && method_exists('DCB_Admin', 'render_dashboard')) {
            DCB_Admin::render_dashboard();
            return;
        }

        echo '<div class="wrap"><h1>Document Center Builder</h1><div class="notice notice-warning"><p>Dashboard is in direct-access mode.</p></div></div>';
    }
}

if (!function_exists('dcb_direct_dashboard_dispatch')) {
    function dcb_direct_dashboard_dispatch(): void {
        if (!function_exists('is_admin') || !is_admin()) {
            return;
        }

        $page = isset($_GET['page']) ? sanitize_key((string) $_GET['page']) : '';
        if ($page !== 'dcb-direct-dashboard') {
            return;
        }

        if (!current_user_can('read')) {
            wp_die('Unauthorized');
        }

        require_once ABSPATH . 'wp-admin/admin-header.php';
        dcb_render_direct_dashboard();
        require_once ABSPATH . 'wp-admin/admin-footer.php';
        exit;
    }
}

if (!function_exists('dcb_plugin_quick_link')) {
    function dcb_plugin_quick_link(array $actions, string $plugin_file): array {
        if (strpos($plugin_file, 'document-center-builder.php') === false) {
            return $actions;
        }
        if (!current_user_can('read')) {
            return $actions;
        }

        $actions = array_merge(array(
            'dcb_open' => '<a href="' . esc_url(admin_url('admin.php?page=dcb-direct-dashboard')) . '">Open Document Center</a>',
        ), $actions);

        return $actions;
    }
}

add_action('admin_init', 'dcb_direct_dashboard_dispatch', 1);
add_filter('plugin_action_links', 'dcb_plugin_quick_link', 10, 2);

register_activation_hook(__FILE__, array('DCB_Loader', 'activate'));

DCB_Loader::instance()->boot();
