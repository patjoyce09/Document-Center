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

require_once dirname(__DIR__) . '/includes/helpers-schema.php';
require_once dirname(__DIR__) . '/includes/helpers-ocr.php';

function assert_true($condition, $message): void {
    if (!$condition) {
        fwrite(STDERR, "Assertion failed: {$message}\n");
        exit(1);
    }
}

$notice_text = "NOTICE OF PRIVACY PRACTICES\n"
    . "Please read carefully before signing.\n"
    . "This notice explains how medical information may be used and disclosed and how you can get access to this information.\n"
    . "Please review all instructions and keep a copy for your records.\n"
    . "Do not write in shaded areas or instructional paragraphs.\n"
    . "Patient Signature: ____________________________\n"
    . "Date: ____________________________\n"
    . "Printed Name: ____________________________\n";

$draft = dcb_ocr_to_draft_form($notice_text, 'Sparse Notice Form', array(
    'pages' => array(
        array(
            'page_number' => 1,
            'engine' => 'fixture',
            'text' => $notice_text,
            'text_length' => strlen($notice_text),
            'confidence_proxy' => 0.82,
        ),
    ),
));

$fields = isset($draft['fields']) && is_array($draft['fields']) ? $draft['fields'] : array();
assert_true(count($fields) <= 6, 'sparse notice form should suppress excessive false-positive fields');

$field_labels = array();
foreach ($fields as $field) {
    if (!is_array($field)) {
        continue;
    }
    $field_labels[] = strtolower((string) ($field['label'] ?? ''));
}

$joined = implode(' | ', $field_labels);
assert_true(strpos($joined, 'signature') !== false, 'sparse notice should keep signature field cues');
assert_true(strpos($joined, 'date') !== false, 'sparse notice should keep date field cues');

$cleanup = isset($draft['ocr_review']['review_cleanup_burden_proxy']) ? (float) $draft['ocr_review']['review_cleanup_burden_proxy'] : 1.0;
assert_true($cleanup <= 0.70, 'sparse suppression should keep review cleanup burden bounded');

echo "ocr_sparse_form_suppression_smoke:ok\n";
