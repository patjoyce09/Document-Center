<?php

if (!defined('ABSPATH')) {
    exit;
}

require_once DCB_PLUGIN_DIR . 'includes/class-settings.php';
require_once DCB_PLUGIN_DIR . 'includes/class-permissions.php';
require_once DCB_PLUGIN_DIR . 'includes/class-forms.php';
require_once DCB_PLUGIN_DIR . 'includes/class-admin.php';
require_once DCB_PLUGIN_DIR . 'includes/class-assets.php';
require_once DCB_PLUGIN_DIR . 'includes/class-builder.php';
require_once DCB_PLUGIN_DIR . 'includes/class-submissions.php';
require_once DCB_PLUGIN_DIR . 'includes/class-renderer.php';
require_once DCB_PLUGIN_DIR . 'includes/class-signatures.php';
require_once DCB_PLUGIN_DIR . 'includes/class-license-boundary.php';
require_once DCB_PLUGIN_DIR . 'includes/class-ocr.php';
require_once DCB_PLUGIN_DIR . 'includes/class-ocr-engine.php';
require_once DCB_PLUGIN_DIR . 'includes/class-uploader.php';
require_once DCB_PLUGIN_DIR . 'includes/class-diagnostics.php';
require_once DCB_PLUGIN_DIR . 'includes/class-ops.php';
require_once DCB_PLUGIN_DIR . 'includes/class-workflow.php';
require_once DCB_PLUGIN_DIR . 'includes/class-integration-tutor.php';
require_once DCB_PLUGIN_DIR . 'includes/class-intake-trace.php';
require_once DCB_PLUGIN_DIR . 'includes/class-migrations.php';
require_once DCB_PLUGIN_DIR . 'includes/class-cli.php';

final class DCB_Loader {
    private static ?DCB_Loader $instance = null;

    public static function instance(): DCB_Loader {
        if (!self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public static function activate(): void {
        DCB_Settings::activate_defaults();
        DCB_Permissions::activate();
        DCB_Migrations::activate();
        DCB_Submissions::register_post_types();
        flush_rewrite_rules(false);
    }

    public function boot(): void {
        add_action('plugins_loaded', array($this, 'load_textdomain'));
        DCB_Settings::init();
        DCB_Permissions::init();
        DCB_Admin::init();
        DCB_Assets::init();
        DCB_Forms::init();
        DCB_Builder::init();
        DCB_Submissions::init();
        DCB_Renderer::init();
        DCB_Signatures::init();
        DCB_License_Boundary::init();
        DCB_OCR::init();
        DCB_Uploader::init();
        DCB_Diagnostics::init();
        DCB_Ops::init();
        DCB_Workflow::init();
        DCB_Integration_Tutor::init();
        DCB_Intake_Trace::init();
        DCB_Migrations::run();
        DCB_CLI::init();
    }

    public function load_textdomain(): void {
        load_plugin_textdomain('document-center-builder', false, dirname(DCB_PLUGIN_BASENAME) . '/languages');
    }
}
