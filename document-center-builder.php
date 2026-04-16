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

if (!function_exists('dcb_register_fatal_shutdown_guard')) {
    function dcb_register_fatal_shutdown_guard(): void {
        register_shutdown_function(static function (): void {
            $error = error_get_last();
            if (!is_array($error)) {
                return;
            }

            $type = (int) ($error['type'] ?? 0);
            $fatal_types = array(E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR);
            if (!in_array($type, $fatal_types, true)) {
                return;
            }

            $file = str_replace('\\', '/', (string) ($error['file'] ?? ''));
            if ($file === '' || strpos($file, '/document-center-builder/') === false) {
                return;
            }

            $line = (int) ($error['line'] ?? 0);
            $message = sanitize_text_field((string) ($error['message'] ?? 'Fatal plugin error'));
            $summary = sprintf('fatal_shutdown: %s (%s:%d)', $message, $file, $line);

            if (function_exists('update_option')) {
                update_option('dcb_safe_mode', '1', false);
                update_option('dcb_boot_error', $summary, false);
                update_option('dcb_boot_trace', array(
                    'status' => 'failed',
                    'plugin_version' => defined('DCB_VERSION') ? (string) DCB_VERSION : '',
                    'schema_version' => (int) get_option('dcb_schema_version', 0),
                    'started_at' => function_exists('current_time') ? (string) current_time('mysql') : gmdate('Y-m-d H:i:s'),
                    'completed_at' => function_exists('current_time') ? (string) current_time('mysql') : gmdate('Y-m-d H:i:s'),
                    'dependencies_loaded' => true,
                    'failed_step' => 'runtime.shutdown',
                    'failure' => array(
                        'step' => 'runtime.shutdown',
                        'message' => $message,
                        'file' => $file,
                        'line' => $line,
                        'trace' => '',
                    ),
                    'steps' => array(),
                ), false);
            }

            if (function_exists('error_log')) {
                error_log('[DCB_FATAL_SHUTDOWN] ' . $summary);
            }
        });
    }
}

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
        if (class_exists('DCB_Admin') && method_exists('DCB_Admin', 'render_dashboard')) {
            DCB_Admin::render_dashboard();
        } else {
            echo '<div class="wrap">';
            echo '<h1>Document Center Builder</h1>';
            echo '<div class="notice notice-warning"><p>Document Center loaded in recovery mode. Core admin handlers were not available at menu render time.</p></div>';
            echo '<p><a class="button button-primary" href="' . esc_url(admin_url('plugins.php')) . '">Back to Plugins</a></p>';
            echo '</div>';
        }

        if (class_exists('DCB_Loader') && method_exists('DCB_Loader', 'render_boot_trace_panel')) {
            echo '<div class="wrap">';
            DCB_Loader::render_boot_trace_panel();
            echo '</div>';
        }
    }
}

if (!function_exists('dcb_register_recovery_menu')) {
    function dcb_register_recovery_menu(): void {
        if (!current_user_can('read')) {
            return;
        }

        add_menu_page(
            __('Document Center', 'document-center-builder'),
            __('Document Center', 'document-center-builder'),
            'read',
            'dcb-dashboard',
            'dcb_render_recovery_dashboard',
            'dashicons-forms'
        );
    }
}

if (!function_exists('dcb_plugin_action_links')) {
    function dcb_plugin_action_links(array $links): array {
        if (!current_user_can('read')) {
            return $links;
        }

        $open_link = '<a href="' . esc_url(admin_url('admin.php?page=dcb-dashboard')) . '">Open Document Center</a>';
        array_unshift($links, $open_link);
        return $links;
    }
}

if (!function_exists('dcb_plugin_action_links_global')) {
    function dcb_plugin_action_links_global(array $actions, string $plugin_file): array {
        if (!current_user_can('read')) {
            return $actions;
        }

        if (strpos($plugin_file, 'document-center-builder.php') === false) {
            return $actions;
        }

        if (!isset($actions['dcb_open'])) {
            $actions['dcb_open'] = '<a href="' . esc_url(admin_url('tools.php?page=dcb-recovery-dashboard')) . '">Open Document Center</a>';
        }

        return $actions;
    }
}

if (!function_exists('dcb_register_tools_fallback_menu')) {
    function dcb_register_tools_fallback_menu(): void {
        if (!current_user_can('read')) {
            return;
        }

        add_management_page(
            __('Document Center', 'document-center-builder'),
            __('Document Center', 'document-center-builder'),
            'read',
            'dcb-recovery-dashboard',
            'dcb_render_recovery_dashboard'
        );
    }
}

if (!function_exists('dcb_plugins_screen_quick_notice')) {
    function dcb_plugins_screen_quick_notice(): void {
        if (!current_user_can('read')) {
            return;
        }

        if (!function_exists('get_current_screen')) {
            return;
        }

        $screen = get_current_screen();
        if (!$screen || (string) ($screen->id ?? '') !== 'plugins') {
            return;
        }

        $url = admin_url('tools.php?page=dcb-recovery-dashboard');
        echo '<div class="notice notice-info"><p><strong>Document Center:</strong> <a href="' . esc_url($url) . '">Open Dashboard</a></p></div>';
    }
}

add_action('admin_menu', 'dcb_register_recovery_menu', 999);
add_action('admin_menu', 'dcb_register_tools_fallback_menu', 999);
add_filter('plugin_action_links_' . DCB_PLUGIN_BASENAME, 'dcb_plugin_action_links');
add_filter('plugin_action_links', 'dcb_plugin_action_links_global', 10, 2);
add_action('admin_notices', 'dcb_plugins_screen_quick_notice');

dcb_register_fatal_shutdown_guard();

register_activation_hook(__FILE__, array('DCB_Loader', 'activate'));

DCB_Loader::instance()->boot();
