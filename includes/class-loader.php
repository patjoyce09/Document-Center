<?php

if (!defined('ABSPATH')) {
    exit;
}

final class DCB_Loader {
    private static ?DCB_Loader $instance = null;
    private static bool $dependencies_loaded = false;
    private const BOOT_ERROR_OPTION = 'dcb_boot_error';
    private const BOOT_TRACE_OPTION = 'dcb_boot_trace';
    private const SAFE_MODE_OPTION = 'dcb_safe_mode';

    public static function instance(): DCB_Loader {
        if (!self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public static function activate(): void {
        self::load_dependencies();
        DCB_Permissions::activate();
        DCB_Settings::activate_defaults();
        DCB_Migrations::activate();
        DCB_Form_Repository::register_post_type();
        DCB_Submissions::register_post_types();
        flush_rewrite_rules(false);
    }

    public function boot(): void {
        add_action('plugins_loaded', array($this, 'load_textdomain'));
        self::maybe_handle_safe_mode_toggle();

        if (self::safe_mode_enabled()) {
            $trace = self::new_boot_trace();
            $trace['status'] = 'safe_mode';
            $trace['completed_at'] = self::now_mysql();
            self::persist_boot_trace($trace);
            add_action('admin_notices', array(__CLASS__, 'render_boot_error_notice'));
            return;
        }

        $trace = self::new_boot_trace();

        try {
            self::load_dependencies();
            $trace['dependencies_loaded'] = true;
        } catch (\Throwable $e) {
            self::record_step_failure($trace, 'dependencies.load', $e);
            self::persist_boot_trace($trace);
            update_option(self::BOOT_ERROR_OPTION, self::format_boot_error($trace), false);
            self::log_boot_failure($trace);
            add_action('admin_notices', array(__CLASS__, 'render_boot_error_notice'));
            return;
        }

        foreach (self::boot_steps() as $step_name => $step_callable) {
            self::record_step_started($trace, $step_name);

            try {
                $step_callable();
                self::record_step_succeeded($trace, $step_name);
            } catch (\Throwable $e) {
                self::record_step_failure($trace, $step_name, $e);
                self::persist_boot_trace($trace);
                update_option(self::BOOT_ERROR_OPTION, self::format_boot_error($trace), false);
                self::log_boot_failure($trace);
                add_action('admin_notices', array(__CLASS__, 'render_boot_error_notice'));
                return;
            }
        }

        $trace['status'] = 'succeeded';
        $trace['completed_at'] = self::now_mysql();
        self::persist_boot_trace($trace);
        update_option(self::BOOT_ERROR_OPTION, '', false);
    }

    public function load_textdomain(): void {
        load_plugin_textdomain('document-center-builder', false, dirname(DCB_PLUGIN_BASENAME) . '/languages');
    }

    private static function load_dependencies(): void {
        if (self::$dependencies_loaded) {
            return;
        }

        $files = array(
            'includes/class-settings.php',
            'includes/class-permissions.php',
            'includes/class-form-repository.php',
            'includes/class-forms.php',
            'includes/class-admin.php',
            'includes/class-assets.php',
            'includes/class-builder.php',
            'includes/class-submissions.php',
            'includes/class-renderer.php',
            'includes/class-signatures.php',
            'includes/class-ocr.php',
            'includes/class-ocr-engine.php',
            'includes/class-uploader.php',
            'includes/class-diagnostics.php',
            'includes/class-workflow.php',
            'includes/class-integration-tutor.php',
            'includes/class-migrations.php',
            'includes/class-cli.php',
        );

        foreach ($files as $relative) {
            require_once DCB_PLUGIN_DIR . $relative;
        }

        self::$dependencies_loaded = true;
    }

    private static function boot_steps(): array {
        return array(
            'DCB_Permissions::init' => static function (): void { DCB_Permissions::init(); },
            'DCB_Settings::init' => static function (): void { DCB_Settings::init(); },
            'DCB_Form_Repository::init' => static function (): void { DCB_Form_Repository::init(); },
            'DCB_Admin::init' => static function (): void { DCB_Admin::init(); },
            'DCB_Assets::init' => static function (): void { DCB_Assets::init(); },
            'DCB_Forms::init' => static function (): void { DCB_Forms::init(); },
            'DCB_Builder::init' => static function (): void { DCB_Builder::init(); },
            'DCB_Submissions::init' => static function (): void { DCB_Submissions::init(); },
            'DCB_Renderer::init' => static function (): void { DCB_Renderer::init(); },
            'DCB_Signatures::init' => static function (): void { DCB_Signatures::init(); },
            'DCB_OCR::init' => static function (): void { DCB_OCR::init(); },
            'DCB_Uploader::init' => static function (): void { DCB_Uploader::init(); },
            'DCB_Diagnostics::init' => static function (): void { DCB_Diagnostics::init(); },
            'DCB_Workflow::init' => static function (): void { DCB_Workflow::init(); },
            'DCB_Integration_Tutor::init' => static function (): void { DCB_Integration_Tutor::init(); },
            'DCB_Migrations::run' => static function (): void { DCB_Migrations::run(); },
            'DCB_CLI::init' => static function (): void { DCB_CLI::init(); },
        );
    }

    private static function new_boot_trace(): array {
        return array(
            'status' => 'running',
            'plugin_version' => defined('DCB_VERSION') ? (string) DCB_VERSION : '',
            'schema_version' => (int) get_option('dcb_schema_version', 0),
            'started_at' => self::now_mysql(),
            'completed_at' => '',
            'dependencies_loaded' => false,
            'failed_step' => '',
            'failure' => array(),
            'steps' => array(),
        );
    }

    private static function record_step_started(array &$trace, string $step_name): void {
        $trace['steps'][$step_name] = array(
            'status' => 'started',
            'started_at' => self::now_mysql(),
            'completed_at' => '',
        );
    }

    private static function record_step_succeeded(array &$trace, string $step_name): void {
        if (!isset($trace['steps'][$step_name]) || !is_array($trace['steps'][$step_name])) {
            $trace['steps'][$step_name] = array();
        }

        $trace['steps'][$step_name]['status'] = 'succeeded';
        $trace['steps'][$step_name]['completed_at'] = self::now_mysql();
    }

    private static function record_step_failure(array &$trace, string $step_name, \Throwable $e): void {
        if (!isset($trace['steps'][$step_name]) || !is_array($trace['steps'][$step_name])) {
            $trace['steps'][$step_name] = array();
        }

        $trace['status'] = 'failed';
        $trace['completed_at'] = self::now_mysql();
        $trace['failed_step'] = $step_name;
        $trace['steps'][$step_name]['status'] = 'failed';
        $trace['steps'][$step_name]['completed_at'] = self::now_mysql();

        $trace['failure'] = array(
            'step' => $step_name,
            'message' => sanitize_text_field($e->getMessage()),
            'file' => sanitize_text_field($e->getFile()),
            'line' => (int) $e->getLine(),
            'trace' => sanitize_textarea_field(self::trace_summary($e)),
        );

        $trace['steps'][$step_name]['error'] = $trace['failure'];
    }

    private static function trace_summary(\Throwable $e): string {
        $trace = trim((string) $e->getTraceAsString());
        if ($trace === '') {
            return '';
        }

        $lines = preg_split('/\r\n|\r|\n/', $trace);
        if (!is_array($lines)) {
            return '';
        }

        return implode("\n", array_slice($lines, 0, 12));
    }

    private static function persist_boot_trace(array $trace): void {
        update_option(self::BOOT_TRACE_OPTION, $trace, false);
    }

    private static function format_boot_error(array $trace): string {
        $failure = isset($trace['failure']) && is_array($trace['failure']) ? $trace['failure'] : array();
        $step = sanitize_text_field((string) ($failure['step'] ?? $trace['failed_step'] ?? 'boot'));
        $message = sanitize_text_field((string) ($failure['message'] ?? 'Unknown boot failure.'));
        return $step . ': ' . $message;
    }

    private static function log_boot_failure(array $trace): void {
        if (!function_exists('error_log')) {
            return;
        }

        $failure = isset($trace['failure']) && is_array($trace['failure']) ? $trace['failure'] : array();
        $payload = array(
            'status' => (string) ($trace['status'] ?? 'failed'),
            'failed_step' => (string) ($trace['failed_step'] ?? ''),
            'failure' => $failure,
            'started_at' => (string) ($trace['started_at'] ?? ''),
            'completed_at' => (string) ($trace['completed_at'] ?? ''),
        );

        error_log('[DCB_BOOT_FAILURE] ' . wp_json_encode($payload, JSON_UNESCAPED_SLASHES));
    }

    private static function now_mysql(): string {
        return function_exists('current_time') ? (string) current_time('mysql') : gmdate('Y-m-d H:i:s');
    }

    private static function maybe_handle_safe_mode_toggle(): void {
        if (!function_exists('is_admin') || !is_admin()) {
            return;
        }

        $toggle = isset($_GET['dcb_safe_mode']) ? sanitize_key((string) $_GET['dcb_safe_mode']) : '';
        if ($toggle !== 'off') {
            return;
        }

        if (!function_exists('current_user_can') || !current_user_can('activate_plugins')) {
            return;
        }

        $nonce = isset($_GET['_dcbnonce']) ? sanitize_text_field((string) $_GET['_dcbnonce']) : '';
        if (!function_exists('wp_verify_nonce') || !wp_verify_nonce($nonce, 'dcb_safe_mode_off')) {
            return;
        }

        update_option(self::SAFE_MODE_OPTION, '0', false);
        update_option(self::BOOT_ERROR_OPTION, '', false);
    }

    public static function safe_mode_enabled(): bool {
        return get_option(self::SAFE_MODE_OPTION, '0') === '1';
    }

    public static function safe_mode_disable_url(): string {
        $base = admin_url('tools.php?page=dcb-recovery-dashboard&dcb_safe_mode=off');
        if (!function_exists('wp_create_nonce')) {
            return $base;
        }
        return add_query_arg(array('_dcbnonce' => wp_create_nonce('dcb_safe_mode_off')), $base);
    }

    public static function get_boot_trace(): array {
        $trace = get_option(self::BOOT_TRACE_OPTION, array());
        return is_array($trace) ? $trace : array();
    }

    public static function render_boot_trace_panel(): void {
        if (!function_exists('current_user_can') || !current_user_can('read')) {
            return;
        }

        $trace = self::get_boot_trace();
        $failure = isset($trace['failure']) && is_array($trace['failure']) ? $trace['failure'] : array();
        $steps = isset($trace['steps']) && is_array($trace['steps']) ? $trace['steps'] : array();

        echo '<div class="notice notice-info" style="margin-top:16px;"><p><strong>Boot Self Test</strong></p></div>';
        echo '<table class="widefat striped" style="max-width:1200px">';
        echo '<tbody>';
        echo '<tr><th style="width:240px">Plugin Version</th><td>' . esc_html((string) ($trace['plugin_version'] ?? '')) . '</td></tr>';
        echo '<tr><th>Schema Version</th><td>' . esc_html((string) ((int) get_option('dcb_schema_version', 0))) . '</td></tr>';
        echo '<tr><th>Safe Mode</th><td>' . esc_html(self::safe_mode_enabled() ? 'enabled' : 'disabled') . '</td></tr>';
        echo '<tr><th>Last Boot Status</th><td>' . esc_html((string) ($trace['status'] ?? 'unknown')) . '</td></tr>';
        echo '<tr><th>Dependencies Loaded</th><td>' . esc_html(!empty($trace['dependencies_loaded']) ? 'yes' : 'no') . '</td></tr>';
        echo '<tr><th>Last Boot Started</th><td>' . esc_html((string) ($trace['started_at'] ?? '')) . '</td></tr>';
        echo '<tr><th>Last Boot Completed</th><td>' . esc_html((string) ($trace['completed_at'] ?? '')) . '</td></tr>';
        echo '<tr><th>Failed Step</th><td>' . esc_html((string) ($trace['failed_step'] ?? '')) . '</td></tr>';
        echo '<tr><th>Failure Message</th><td>' . esc_html((string) ($failure['message'] ?? '')) . '</td></tr>';
        echo '</tbody></table>';

        echo '<h2 style="margin-top:16px;">Boot Step Trace</h2>';
        echo '<table class="widefat striped" style="max-width:1200px">';
        echo '<thead><tr><th>Step</th><th>Status</th><th>Started</th><th>Completed</th><th>Error</th></tr></thead><tbody>';
        foreach ($steps as $step_name => $step) {
            if (!is_array($step)) {
                continue;
            }
            $error = isset($step['error']) && is_array($step['error']) ? $step['error'] : array();
            $error_text = (string) ($error['message'] ?? '');
            if ($error_text === '' && isset($error['file'])) {
                $error_text = (string) $error['file'] . ':' . (int) ($error['line'] ?? 0);
            }
            echo '<tr>';
            echo '<td>' . esc_html((string) $step_name) . '</td>';
            echo '<td>' . esc_html((string) ($step['status'] ?? '')) . '</td>';
            echo '<td>' . esc_html((string) ($step['started_at'] ?? '')) . '</td>';
            echo '<td>' . esc_html((string) ($step['completed_at'] ?? '')) . '</td>';
            echo '<td>' . esc_html($error_text) . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table>';

        if (!empty($failure['trace'])) {
            echo '<h2 style="margin-top:16px;">Failure Trace (summary)</h2>';
            echo '<pre style="max-width:1200px;white-space:pre-wrap;">' . esc_html((string) $failure['trace']) . '</pre>';
        }

        if (self::safe_mode_enabled() && function_exists('current_user_can') && current_user_can('activate_plugins')) {
            echo '<p style="margin-top:12px;"><a class="button button-primary" href="' . esc_url(self::safe_mode_disable_url()) . '">Disable Safe Mode and Retry Boot</a></p>';
        }
    }

    public static function render_boot_error_notice(): void {
        if (!function_exists('current_user_can') || !current_user_can('read')) {
            return;
        }

        $message = sanitize_text_field((string) get_option(self::BOOT_ERROR_OPTION, ''));
        if ($message === '') {
            return;
        }

        $prefix = self::safe_mode_enabled() ? 'Document Center Builder is in Safe Mode' : 'Document Center Builder boot failure';
        echo '<div class="notice notice-error"><p><strong>' . esc_html($prefix) . ':</strong> ' . esc_html($message) . ' — <a href="' . esc_url(admin_url('tools.php?page=dcb-recovery-dashboard')) . '">Open recovery dashboard</a></p></div>';
    }
}
