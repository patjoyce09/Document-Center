<?php

if (!defined('ABSPATH')) {
    exit;
}

require_once DCB_PLUGIN_DIR . 'includes/class-settings.php';
require_once DCB_PLUGIN_DIR . 'includes/class-permissions.php';
require_once DCB_PLUGIN_DIR . 'includes/class-form-repository.php';
require_once DCB_PLUGIN_DIR . 'includes/class-forms.php';
require_once DCB_PLUGIN_DIR . 'includes/class-admin.php';
require_once DCB_PLUGIN_DIR . 'includes/class-assets.php';
require_once DCB_PLUGIN_DIR . 'includes/class-builder.php';
require_once DCB_PLUGIN_DIR . 'includes/class-submissions.php';
require_once DCB_PLUGIN_DIR . 'includes/class-renderer.php';
require_once DCB_PLUGIN_DIR . 'includes/class-signatures.php';
require_once DCB_PLUGIN_DIR . 'includes/class-ocr.php';
require_once DCB_PLUGIN_DIR . 'includes/class-ocr-engine.php';
require_once DCB_PLUGIN_DIR . 'includes/class-uploader.php';
require_once DCB_PLUGIN_DIR . 'includes/class-diagnostics.php';
require_once DCB_PLUGIN_DIR . 'includes/class-workflow.php';
require_once DCB_PLUGIN_DIR . 'includes/class-integration-tutor.php';
require_once DCB_PLUGIN_DIR . 'includes/class-migrations.php';
require_once DCB_PLUGIN_DIR . 'includes/class-cli.php';

final class DCB_Loader {
    private static ?DCB_Loader $instance = null;
    private const BOOT_FAILURES_OPTION = 'dcb_boot_failures';

    public static function instance(): DCB_Loader {
        if (!self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public static function activate(): void {
        DCB_Permissions::activate();
        DCB_Settings::activate_defaults();
        DCB_Migrations::activate();
        DCB_Form_Repository::register_post_type();
        DCB_Submissions::register_post_types();
        flush_rewrite_rules(false);
    }

    public function boot(): void {
        add_action('plugins_loaded', array($this, 'load_textdomain'));
        $failures = array();

        self::run_boot_step('DCB_Permissions::init', static function (): void {
            DCB_Permissions::init();
        }, $failures);

        // Register admin menu early so navigation remains available even if later modules fail.
        self::run_boot_step('DCB_Admin::init', static function (): void {
            DCB_Admin::init();
        }, $failures);

        self::run_boot_step('DCB_Settings::init', static function (): void {
            DCB_Settings::init();
        }, $failures);

        self::run_boot_step('DCB_Form_Repository::init', static function (): void {
            DCB_Form_Repository::init();
        }, $failures);

        self::run_boot_step('DCB_Assets::init', static function (): void {
            DCB_Assets::init();
        }, $failures);

        self::run_boot_step('DCB_Forms::init', static function (): void {
            DCB_Forms::init();
        }, $failures);

        self::run_boot_step('DCB_Builder::init', static function (): void {
            DCB_Builder::init();
        }, $failures);

        self::run_boot_step('DCB_Submissions::init', static function (): void {
            DCB_Submissions::init();
        }, $failures);

        self::run_boot_step('DCB_Renderer::init', static function (): void {
            DCB_Renderer::init();
        }, $failures);

        self::run_boot_step('DCB_Signatures::init', static function (): void {
            DCB_Signatures::init();
        }, $failures);

        self::run_boot_step('DCB_OCR::init', static function (): void {
            DCB_OCR::init();
        }, $failures);

        self::run_boot_step('DCB_Uploader::init', static function (): void {
            DCB_Uploader::init();
        }, $failures);

        self::run_boot_step('DCB_Diagnostics::init', static function (): void {
            DCB_Diagnostics::init();
        }, $failures);

        self::run_boot_step('DCB_Workflow::init', static function (): void {
            DCB_Workflow::init();
        }, $failures);

        self::run_boot_step('DCB_Integration_Tutor::init', static function (): void {
            DCB_Integration_Tutor::init();
        }, $failures);

        self::run_boot_step('DCB_Migrations::run', static function (): void {
            DCB_Migrations::run();
        }, $failures);

        self::run_boot_step('DCB_CLI::init', static function (): void {
            DCB_CLI::init();
        }, $failures);

        update_option(self::BOOT_FAILURES_OPTION, $failures, false);

        add_action('admin_menu', array(__CLASS__, 'register_emergency_menu'), 1000);
        if (!empty($failures)) {
            add_action('admin_notices', array(__CLASS__, 'render_boot_failures_notice'));
        }
    }

    public function load_textdomain(): void {
        load_plugin_textdomain('document-center-builder', false, dirname(DCB_PLUGIN_BASENAME) . '/languages');
    }

    private static function run_boot_step(string $step, callable $callback, array &$failures): void {
        try {
            $callback();
        } catch (\Throwable $e) {
            $failure = array(
                'step' => $step,
                'message' => sanitize_text_field($e->getMessage()),
                'file' => sanitize_text_field($e->getFile()),
                'line' => (int) $e->getLine(),
            );
            $failures[] = $failure;
            if (function_exists('error_log')) {
                error_log('[DCB_BOOT_STEP_FAILED] ' . wp_json_encode($failure, JSON_UNESCAPED_SLASHES));
            }
        }
    }

    public static function register_emergency_menu(): void {
        if (!function_exists('current_user_can') || !current_user_can('read')) {
            return;
        }

        global $menu;
        foreach ((array) $menu as $item) {
            if (isset($item[2]) && (string) $item[2] === 'dcb-dashboard') {
                return;
            }
        }

        add_menu_page(
            __('Document Center', 'document-center-builder'),
            __('Document Center', 'document-center-builder'),
            'read',
            'dcb-dashboard',
            array(__CLASS__, 'render_emergency_dashboard'),
            'dashicons-forms'
        );
    }

    public static function render_emergency_dashboard(): void {
        echo '<div class="wrap"><h1>Document Center Builder</h1>';
        echo '<div class="notice notice-warning"><p>Loaded in emergency menu mode. One or more modules failed during boot.</p></div>';
        self::render_boot_failures_table();
        echo '</div>';
    }

    public static function render_boot_failures_notice(): void {
        if (!function_exists('current_user_can') || !current_user_can('manage_options')) {
            return;
        }
        $failures = get_option(self::BOOT_FAILURES_OPTION, array());
        if (!is_array($failures) || empty($failures)) {
            return;
        }
        $first = (array) $failures[0];
        echo '<div class="notice notice-error"><p><strong>Document Center boot warning:</strong> ' . esc_html((string) ($first['step'] ?? 'boot')) . ' — ' . esc_html((string) ($first['message'] ?? 'Unknown error')) . '</p></div>';
    }

    private static function render_boot_failures_table(): void {
        $failures = get_option(self::BOOT_FAILURES_OPTION, array());
        if (!is_array($failures) || empty($failures)) {
            echo '<p>No boot failures recorded in this request.</p>';
            return;
        }

        echo '<table class="widefat striped" style="max-width:1200px"><thead><tr><th>Step</th><th>Message</th><th>File</th><th>Line</th></tr></thead><tbody>';
        foreach ($failures as $row) {
            if (!is_array($row)) {
                continue;
            }
            echo '<tr>';
            echo '<td>' . esc_html((string) ($row['step'] ?? '')) . '</td>';
            echo '<td>' . esc_html((string) ($row['message'] ?? '')) . '</td>';
            echo '<td>' . esc_html((string) ($row['file'] ?? '')) . '</td>';
            echo '<td>' . esc_html((string) ((int) ($row['line'] ?? 0))) . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
    }
}
