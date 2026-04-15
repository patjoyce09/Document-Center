<?php

if (!defined('ABSPATH')) {
    exit;
}

final class DCB_Settings {
    public static function init(): void {
        add_action('admin_init', array(__CLASS__, 'register'));
    }

    public static function activate_defaults(): void {
        $defaults = self::defaults();
        foreach ($defaults as $key => $value) {
            if (get_option($key, null) === null) {
                add_option($key, $value, '', false);
            }
        }
    }

    public static function defaults(): array {
        return array(
            'dcb_upload_routing_rules' => array(),
            'dcb_upload_form_profiles' => array(),
            'dcb_upload_default_recipients' => '',
            'dcb_upload_review_recipients' => '',
            'dcb_upload_min_confidence' => 0.45,
            'dcb_upload_email_attachments' => '0',
            'dcb_upload_tesseract_path' => '',
            'dcb_upload_pdftotext_path' => '',
            'dcb_upload_pdftoppm_path' => '',
            'dcb_policy_consent_text_version' => 'consent-default',
            'dcb_policy_attestation_text_version' => 'attestation-default',
            'dcb_brand_label' => 'Document Center Builder',
            'dcb_forms_custom' => array(),
        );
    }

    public static function register(): void {
        register_setting('dcb_settings_group', 'dcb_upload_default_recipients');
        register_setting('dcb_settings_group', 'dcb_upload_review_recipients');
        register_setting('dcb_settings_group', 'dcb_upload_min_confidence');
        register_setting('dcb_settings_group', 'dcb_upload_email_attachments');
        register_setting('dcb_settings_group', 'dcb_upload_tesseract_path');
        register_setting('dcb_settings_group', 'dcb_upload_pdftotext_path');
        register_setting('dcb_settings_group', 'dcb_upload_pdftoppm_path');
        register_setting('dcb_settings_group', 'dcb_policy_consent_text_version');
        register_setting('dcb_settings_group', 'dcb_policy_attestation_text_version');
        register_setting('dcb_settings_group', 'dcb_brand_label');

        register_setting('dcb_settings_group', 'dcb_upload_accept_mimes', array(
            'type' => 'array',
            'sanitize_callback' => static function ($value) {
                return is_array($value) ? array_values(array_filter(array_map('sanitize_text_field', $value))) : array();
            },
            'default' => array('pdf', 'doc', 'docx', 'jpg', 'jpeg', 'png', 'webp', 'txt', 'csv'),
        ));
    }
}
