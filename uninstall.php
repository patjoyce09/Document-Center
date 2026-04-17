<?php
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

$ops_helper = __DIR__ . '/includes/helpers-ops.php';
if (is_readable($ops_helper)) {
    require_once $ops_helper;
}

$purge_all = function_exists('dcb_ops_uninstall_should_purge') ? dcb_ops_uninstall_should_purge() : false;

// Always clear lightweight sync marker.
delete_option('dcb_caps_last_synced');

if (!$purge_all) {
    // Conservative default: keep stored forms/submissions/settings unless admin explicitly enables purge.
    return;
}

$option_keys = array(
    'dcb_forms_custom',
    'dcb_upload_routing_rules',
    'dcb_upload_form_profiles',
    'dcb_upload_default_recipients',
    'dcb_upload_review_recipients',
    'dcb_upload_min_confidence',
    'dcb_upload_email_attachments',
    'dcb_upload_tesseract_path',
    'dcb_upload_pdftotext_path',
    'dcb_upload_pdftoppm_path',
    'dcb_policy_consent_text_version',
    'dcb_policy_attestation_text_version',
    'dcb_brand_label',
    'dcb_upload_ocr_image_debug_log',
    'dcb_schema_version',
    'dcb_workflow_default_status',
    'dcb_workflow_enable_activity_timeline',
    'dcb_ocr_mode',
    'dcb_ocr_api_base_url',
    'dcb_ocr_api_key',
    'dcb_ocr_timeout_seconds',
    'dcb_ocr_max_file_size_mb',
    'dcb_ocr_confidence_threshold',
    'dcb_chart_routing_mode',
    'dcb_chart_routing_connector_config',
    'dcb_tutor_integration_enabled',
    'dcb_tutor_mapping',
    'dcb_uninstall_remove_data',
    'dcb_health_weekly_digest_enabled',
    'dcb_ops_last_action',
    'dcb_license_state',
);

foreach ($option_keys as $key) {
    delete_option($key);
}

if (function_exists('get_posts') && function_exists('wp_delete_post')) {
    $post_types = array('dcb_form_submission', 'dcb_upload_log', 'dcb_ocr_review_queue', 'dcb_chart_route_queue');
    foreach ($post_types as $post_type) {
        $ids = get_posts(array(
            'post_type' => $post_type,
            'post_status' => 'any',
            'numberposts' => -1,
            'fields' => 'ids',
        ));
        if (!is_array($ids)) {
            continue;
        }
        foreach ($ids as $id) {
            wp_delete_post((int) $id, true);
        }
    }
}
