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
);

foreach ($option_keys as $key) {
    delete_option($key);
}
