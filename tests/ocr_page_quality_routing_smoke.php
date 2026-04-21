<?php

define('ABSPATH', __DIR__ . '/');

if (!function_exists('sanitize_key')) {
    function sanitize_key($key) { return strtolower(preg_replace('/[^a-zA-Z0-9_]/', '', (string) $key)); }
}
if (!function_exists('sanitize_text_field')) {
    function sanitize_text_field($text) { return trim((string) $text); }
}
if (!function_exists('sanitize_textarea_field')) {
    function sanitize_textarea_field($text) { return trim((string) $text); }
}
if (!function_exists('sanitize_email')) {
    function sanitize_email($email) { return trim((string) $email); }
}
if (!function_exists('mb_substr')) {
    function mb_substr($text, $start, $length = null) {
        return $length === null ? substr((string) $text, (int) $start) : substr((string) $text, (int) $start, (int) $length);
    }
}
if (!function_exists('get_option')) {
    function get_option($key, $default = null) { return $default; }
}

require_once dirname(__DIR__) . '/includes/helpers-ocr.php';

function assert_true($condition, $message): void {
    if (!$condition) {
        fwrite(STDERR, "Assertion failed: {$message}\n");
        exit(1);
    }
}

$inspection_photo = array('kind' => 'image', 'is_pdf' => false, 'is_image' => true);
$text_stage_photo = array('pages' => array(array('page_number' => 1, 'text' => 'Captured form page', 'text_length' => 18, 'confidence_proxy' => 0.35)));
$normalization_photo = array(
    'pages' => array(
        array(
            'page_number' => 1,
            'quality' => array(
                'quality_bucket' => 'low',
                'blur_risk' => true,
                'low_resolution_risk' => true,
                'low_contrast_risk' => true,
                'dark_capture_risk' => true,
                'rotation_skew_risk' => true,
                'crop_border_risk' => true,
            ),
            'capture_warnings' => array(
                array('code' => 'blur_risk', 'message' => 'Blur risk'),
                array('code' => 'low_resolution_capture', 'message' => 'Low resolution'),
                array('code' => 'crop_border_risk', 'message' => 'Crop risk'),
            ),
        ),
    ),
);

$routing_photo = dcb_ocr_build_page_quality_routing($inspection_photo, $text_stage_photo, $normalization_photo, array(), 'photo');
assert_true((string) ($routing_photo['source_type'] ?? '') === 'phone_photo', 'photo source should classify as phone_photo');
assert_true((string) ($routing_photo['routing_decision'] ?? '') === 'review_recommended', 'high risk photo should route to review_recommended');
assert_true(!empty($routing_photo['review_recommended']), 'review_recommended flag should be true');

$inspection_pdf = array('kind' => 'pdf', 'is_pdf' => true, 'is_image' => false);
$text_stage_pdf = array(
    'text' => "Patient Intake Form\nPatient Name: John Doe\nDate of Birth: 01/01/1980\nAddress: 100 Main Street, Suite 22\nPhone: 555-555-1212\nInsurance Number: ABC123456\nPrimary Provider: Dr Smith\nConsent to treatment: [ ] Yes [ ] No\nPatient Signature: Sign Here\nDate Signed: 04/20/2026\nEmergency Contact Name: Jane Doe\nEmergency Contact Relationship: Spouse",
    'pages' => array(
        array('page_number' => 1, 'text' => "Patient Intake Form\nPatient Name: John Doe\nDate of Birth: 01/01/1980\nAddress: 100 Main Street, Suite 22\nPhone: 555-555-1212\nInsurance Number: ABC123456\nPrimary Provider: Dr Smith\nConsent to treatment: [ ] Yes [ ] No\nPatient Signature: Sign Here\nDate Signed: 04/20/2026\nEmergency Contact Name: Jane Doe\nEmergency Contact Relationship: Spouse", 'text_length' => 420, 'confidence_proxy' => 0.88),
    ),
);
$native_pass = dcb_ocr_native_pdf_first_pass(__FILE__, $inspection_pdf, $text_stage_pdf);
$routing_pdf = dcb_ocr_build_page_quality_routing($inspection_pdf, $text_stage_pdf, array('pages' => array()), $native_pass, 'pdf');
assert_true(!empty($native_pass['native_text_available']), 'native pdf pass should detect native text');
assert_true((int) ($native_pass['widget_count'] ?? 0) >= 2, 'native pdf pass should detect widget evidence');
assert_true((string) ($routing_pdf['routing_decision'] ?? '') === 'native_pdf_first_pass', 'native pdf should prefer native_pdf_first_pass route');

echo "ocr_page_quality_routing_smoke:ok\n";
