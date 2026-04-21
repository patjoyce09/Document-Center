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

$inspection_pdf = array('kind' => 'pdf', 'is_pdf' => true, 'is_image' => false);
$text_stage_native = array(
    'text' => "PATIENT INTAKE ADMISSION FORM\nThis form records demographic information and attestation details for clinical intake processing.\nPlease complete each applicable item and keep instructional paragraphs as fixed text only.\nName: __________________________\nDate of Birth: __________________\nAddress Line One: __________________\nPhone Number: __________________\nConsent to treatment [ ] Yes [ ] No\nPrinted Name: __________________\nRelationship: __________________\nSignature: __________________\nDate: __________________",
    'pages' => array(
        array('page_number' => 1, 'text' => "PATIENT INTAKE ADMISSION FORM\nThis form records demographic information and attestation details for clinical intake processing.\nPlease complete each applicable item and keep instructional paragraphs as fixed text only.\nName: __________________________\nDate of Birth: __________________\nAddress Line One: __________________\nPhone Number: __________________\nConsent to treatment [ ] Yes [ ] No\nPrinted Name: __________________\nRelationship: __________________\nSignature: __________________\nDate: __________________", 'text_length' => 640, 'confidence_proxy' => 0.88),
    ),
);

$native_pass = dcb_ocr_native_pdf_first_pass(__FILE__, $inspection_pdf, $text_stage_native);
$routing = dcb_ocr_build_page_quality_routing($inspection_pdf, $text_stage_native, array('pages' => array()), $native_pass, 'pdf');
$triage = dcb_ocr_build_source_triage($inspection_pdf, __FILE__, $text_stage_native, array('warnings' => array()), $native_pass, $routing);

assert_true(!empty($native_pass['native_text_available']), 'native pass should detect extractable text');
assert_true((int) ($native_pass['widget_count'] ?? 0) >= 2, 'native pass should detect widget candidates');
assert_true((string) ($native_pass['source_profile'] ?? '') === 'digital_pdf_native', 'native pass should classify source profile as digital_pdf_native');
assert_true(in_array('native_pdf_first_pass', (array) ($triage['decisions'] ?? array()), true), 'triage should include native_pdf_first_pass decision');

$text_stage_scanned = array(
    'text' => '',
    'pages' => array(array('page_number' => 1, 'text' => '', 'text_length' => 0, 'confidence_proxy' => 0.0)),
);
$native_pass_scanned = dcb_ocr_native_pdf_first_pass(__FILE__, $inspection_pdf, $text_stage_scanned);
$routing_scanned = dcb_ocr_build_page_quality_routing($inspection_pdf, $text_stage_scanned, array('pages' => array()), $native_pass_scanned, 'pdf');
$triage_scanned = dcb_ocr_build_source_triage($inspection_pdf, __FILE__, $text_stage_scanned, array('warnings' => array()), $native_pass_scanned, $routing_scanned);

assert_true((string) ($native_pass_scanned['source_profile'] ?? '') === 'raster_pdf_scanned', 'weak native text should classify as raster_pdf_scanned');
assert_true(in_array('raster_ocr_path', (array) ($triage_scanned['decisions'] ?? array()), true), 'triage should include raster_ocr_path fallback');

echo "ocr_native_pdf_first_pass_smoke:ok\n";
