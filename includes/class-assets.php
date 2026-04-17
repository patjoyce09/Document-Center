<?php

if (!defined('ABSPATH')) {
    exit;
}

final class DCB_Assets {
    public static function init(): void {
        add_action('admin_enqueue_scripts', array(__CLASS__, 'enqueue_admin_assets'));
        add_action('wp_enqueue_scripts', array(__CLASS__, 'enqueue_front_assets'));
        add_action('wp_enqueue_scripts', array(__CLASS__, 'enqueue_upload_assets'));
    }

    public static function enqueue_admin_assets(): void {
        if (!is_admin()) {
            return;
        }
        $page = isset($_GET['page']) ? sanitize_key((string) $_GET['page']) : '';
        if ($page !== 'dcb-builder') {
            return;
        }

        $css_rel = 'assets/css/digital-form-builder-admin.css';
        $js_rel = 'assets/js/digital-form-builder-admin.js';

        wp_enqueue_style('dcb-builder-admin', DCB_PLUGIN_URL . $css_rel, array(), file_exists(DCB_PLUGIN_DIR . $css_rel) ? (string) filemtime(DCB_PLUGIN_DIR . $css_rel) : DCB_VERSION);
        wp_enqueue_script('dcb-builder-admin', DCB_PLUGIN_URL . $js_rel, array('jquery'), file_exists(DCB_PLUGIN_DIR . $js_rel) ? (string) filemtime(DCB_PLUGIN_DIR . $js_rel) : DCB_VERSION, true);
        wp_localize_script('dcb-builder-admin', 'DCB_DF_BUILDER_ADMIN', array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'validationAction' => 'dcb_builder_validate_schema',
            'validationNonce' => wp_create_nonce('dcb_builder_validate_schema'),
            'fieldTypes' => dcb_allowed_field_types(),
            'templateBlockTypes' => dcb_allowed_template_block_types(),
            'conditionOperators' => array(
                'eq' => 'Equals',
                'neq' => 'Not equals',
                'filled' => 'Is filled',
                'not_filled' => 'Is empty',
                'in' => 'In list',
                'not_in' => 'Not in list',
                'gt' => 'Greater than',
                'gte' => 'Greater than or equal',
                'lt' => 'Less than',
                'lte' => 'Less than or equal',
            ),
            'hardStopSeverities' => array(
                'error' => 'Error',
                'warning' => 'Warning',
                'info' => 'Info',
            ),
            'canRunOCRTools' => DCB_Permissions::can(DCB_Permissions::CAP_RUN_OCR_TOOLS),
        ));
    }

    public static function enqueue_front_assets(): void {
        if (!is_singular()) {
            return;
        }

        global $post;
        if (!$post instanceof WP_Post) {
            return;
        }

        $content = (string) $post->post_content;
        if (!self::content_has_any_shortcode($content, array('dcb_digital_forms_portal', 'document_digital_forms_portal'))) {
            return;
        }

        $css_rel = 'assets/css/digital-forms.css';
        $js_rel = 'assets/js/digital-forms.js';

        wp_enqueue_style('dcb-digital-forms', DCB_PLUGIN_URL . $css_rel, array(), file_exists(DCB_PLUGIN_DIR . $css_rel) ? (string) filemtime(DCB_PLUGIN_DIR . $css_rel) : DCB_VERSION);
        wp_enqueue_script('dcb-digital-forms', DCB_PLUGIN_URL . $js_rel, array('jquery'), file_exists(DCB_PLUGIN_DIR . $js_rel) ? (string) filemtime(DCB_PLUGIN_DIR . $js_rel) : DCB_VERSION, true);

        $current_user = wp_get_current_user();
        wp_localize_script('dcb-digital-forms', 'DCB_DIGITAL_FORMS', array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('dcb_submit_digital_form_nonce'),
            'draftNonce' => wp_create_nonce('dcb_digital_form_draft_nonce'),
            'forms' => dcb_form_definitions(true),
            'outputVersion' => DCB_VERSION,
            'signature' => array(
                'consentTextVersion' => (string) get_option('dcb_policy_consent_text_version', 'consent-default'),
                'attestationTextVersion' => (string) get_option('dcb_policy_attestation_text_version', 'attestation-default'),
            ),
            'currentUser' => array(
                'name' => $current_user instanceof WP_User ? (string) $current_user->display_name : '',
                'email' => $current_user instanceof WP_User ? (string) $current_user->user_email : '',
            ),
        ));
    }

    public static function enqueue_upload_assets(): void {
        if (!is_singular()) {
            return;
        }

        global $post;
        if (!$post instanceof WP_Post) {
            return;
        }

        $content = (string) $post->post_content;
        if (!self::content_has_any_shortcode($content, array('dcb_upload_portal', 'document_upload_portal'))) {
            return;
        }

        $css_rel = 'assets/css/content-uploader.css';
        $js_rel = 'assets/js/content-uploader.js';

        wp_enqueue_style('dcb-content-uploader', DCB_PLUGIN_URL . $css_rel, array(), file_exists(DCB_PLUGIN_DIR . $css_rel) ? (string) filemtime(DCB_PLUGIN_DIR . $css_rel) : DCB_VERSION);
        wp_enqueue_script('dcb-content-uploader', DCB_PLUGIN_URL . $js_rel, array('jquery'), file_exists(DCB_PLUGIN_DIR . $js_rel) ? (string) filemtime(DCB_PLUGIN_DIR . $js_rel) : DCB_VERSION, true);
        wp_localize_script('dcb-content-uploader', 'DCB_UPLOAD_CONFIG', array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('dcb_upload_files_nonce'),
        ));
    }

    private static function content_has_any_shortcode(string $content, array $tags): bool {
        if ($content === '' || empty($tags)) {
            return false;
        }

        foreach ($tags as $tag) {
            $shortcode = sanitize_key((string) $tag);
            if ($shortcode === '') {
                continue;
            }

            if (function_exists('has_shortcode') && has_shortcode($content, $shortcode)) {
                return true;
            }

            if (strpos($content, '[' . $shortcode) !== false) {
                return true;
            }
        }

        return false;
    }
}
