<?php

if (!defined('ABSPATH')) {
    exit;
}

final class DCB_Loader {
    private static ?DCB_Loader $instance = null;
    private static bool $dependencies_loaded = false;
    private const BOOT_ERROR_OPTION = 'dcb_boot_error';

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

        try {
            self::load_dependencies();
        } catch (\Throwable $e) {
            update_option(self::BOOT_ERROR_OPTION, sanitize_text_field($e->getMessage()), false);
            add_action('admin_notices', array(__CLASS__, 'render_boot_error_notice'));
            return;
        }

        update_option(self::BOOT_ERROR_OPTION, '', false);
        DCB_Permissions::init();
        DCB_Settings::init();
        DCB_Form_Repository::init();
        DCB_Admin::init();
        DCB_Assets::init();
        DCB_Forms::init();
        DCB_Builder::init();
        DCB_Submissions::init();
        DCB_Renderer::init();
        DCB_Signatures::init();
        DCB_OCR::init();
        DCB_Uploader::init();
        DCB_Diagnostics::init();
        DCB_Workflow::init();
        DCB_Integration_Tutor::init();
        DCB_Migrations::run();
        DCB_CLI::init();
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

    public static function render_boot_error_notice(): void {
        if (!function_exists('current_user_can') || !current_user_can('manage_options')) {
            return;
        }

        $message = sanitize_text_field((string) get_option(self::BOOT_ERROR_OPTION, ''));
        if ($message === '') {
            return;
        }

        echo '<div class="notice notice-error"><p><strong>Document Center Builder failed to boot:</strong> ' . esc_html($message) . '</p></div>';
    }
}
