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
if (!function_exists('current_time')) {
    function current_time() { return '2026-04-20 00:00:00'; }
}
if (!function_exists('wp_json_encode')) {
    function wp_json_encode($value, $flags = 0) { return json_encode($value, $flags); }
}
if (!function_exists('wp_strip_all_tags')) {
    function wp_strip_all_tags($text) { return strip_tags((string) $text); }
}
if (!function_exists('mb_substr')) {
    function mb_substr($text, $start, $length = null) {
        return $length === null ? substr((string) $text, (int) $start) : substr((string) $text, (int) $start, (int) $length);
    }
}
if (!function_exists('get_option')) {
    function get_option($key, $default = null) { return $default; }
}
if (!function_exists('update_option')) {
    function update_option($key, $value) { return true; }
}
if (!function_exists('trailingslashit')) {
    function trailingslashit($v) { return rtrim((string) $v, '/') . '/'; }
}

require_once dirname(__DIR__) . '/includes/helpers-schema.php';
require_once dirname(__DIR__) . '/includes/helpers-ocr.php';

function assert_true($condition, $message): void {
    if (!$condition) {
        fwrite(STDERR, "Assertion failed: {$message}\n");
        exit(1);
    }
}

$pages = array(
    array(
        'page_number' => 1,
        'engine' => 'fixture',
        'text' => "SECTION A: PATIENT\nPatient Name: __________\nDOB: ____/____/______\nGender [ ] Male [ ] Female\nConsent Given Yes / No\nSignature: __________________\nDate: __________________\nPrinted Name: __________\nRelationship: __________\nTitle: __________\nItem   Qty   Description   Amount\nMedication  2   Daily dose   25.00",
        'text_length' => 340,
        'confidence_proxy' => 0.81,
    ),
);

$document_model = dcb_ocr_build_document_model($pages);
$page_meta = array(
    1 => array(
        'page_number' => 1,
        'engine' => 'fixture',
        'text_length' => 340,
        'confidence_proxy' => 0.81,
        'normalization' => array('quality' => array('quality_bucket' => 'high'), 'capture_warnings' => array()),
    ),
);

$widgets = dcb_ocr_detect_field_widgets($document_model, $page_meta, array());
assert_true(is_array($widgets) && count($widgets) >= 6, 'widget detector should produce several widgets');

$widget_types = array();
foreach ($widgets as $widget) {
    if (!is_array($widget)) {
        continue;
    }
    $widget_types[] = sanitize_key((string) ($widget['widget_type'] ?? ''));
}

assert_true(in_array('yes_no_group', $widget_types, true), 'widget detector should find yes/no grouping');
assert_true(in_array('checkbox', $widget_types, true), 'widget detector should find checkbox controls');
assert_true(in_array('signature_line', $widget_types, true), 'widget detector should find signature lines');
assert_true(in_array('date_field', $widget_types, true), 'widget detector should find date fields');
assert_true(in_array('repeater_zone', $widget_types, true), 'widget detector should find repeater/table zones');

$candidates = dcb_upload_stage_field_candidate_extraction($document_model, $page_meta, $widgets);
assert_true(is_array($candidates) && count($candidates) >= 5, 'field candidate extraction should use widget detections');

$has_geometry = false;
$has_widget_meta = false;
foreach ($candidates as $candidate) {
    if (!is_array($candidate)) {
        continue;
    }
    if (isset($candidate['geometry']) && is_array($candidate['geometry'])) {
        $has_geometry = true;
    }
    if ((string) ($candidate['widget_type'] ?? '') !== '') {
        $has_widget_meta = true;
    }
}

assert_true($has_geometry, 'candidates should carry geometry metadata');
assert_true($has_widget_meta, 'candidates should carry widget metadata');

echo "ocr_field_widget_detection_smoke:ok\n";
