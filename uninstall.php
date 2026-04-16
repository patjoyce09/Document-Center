<?php
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
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
    'dcb_ocr_api_auth_header',
    'dcb_ocr_timeout_seconds',
    'dcb_ocr_max_file_size_mb',
    'dcb_ocr_confidence_threshold',
    'dcb_forms_storage_mode',
    'dcb_tutor_integration_enabled',
    'dcb_tutor_mapping',
);

foreach ($option_keys as $key) {
    delete_option($key);
}
