<?php

define('ABSPATH', __DIR__ . '/');

$GLOBALS['dcb_test_options'] = array();

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
    function current_time() { return '2026-04-16 00:00:00'; }
}
if (!function_exists('wp_json_encode')) {
    function wp_json_encode($value, $flags = 0) { return json_encode($value, $flags); }
}
if (!function_exists('wp_strip_all_tags')) {
    function wp_strip_all_tags($text) { return strip_tags((string) $text); }
}
if (!function_exists('get_option')) {
    function get_option($key, $default = null) {
        return array_key_exists((string) $key, $GLOBALS['dcb_test_options']) ? $GLOBALS['dcb_test_options'][(string) $key] : $default;
    }
}
if (!function_exists('update_option')) {
    function update_option($key, $value) {
        $GLOBALS['dcb_test_options'][(string) $key] = $value;
        return true;
    }
}
if (!function_exists('esc_url_raw')) {
    function esc_url_raw($url) { return (string) $url; }
}
if (!function_exists('trailingslashit')) {
    function trailingslashit($v) { return rtrim((string) $v, '/') . '/'; }
}
if (!function_exists('mb_substr')) {
    function mb_substr($text, $start, $length = null) { return $length === null ? substr((string) $text, (int) $start) : substr((string) $text, (int) $start, (int) $length); }
}
if (!function_exists('dcb_upload_extract_text_from_file_local')) {
    function dcb_upload_extract_text_from_file_local($path, $mime) {
        return array('text' => '', 'pages' => array(), 'warnings' => array(), 'provider' => 'local');
    }
}

require_once dirname(__DIR__) . '/includes/helpers-schema.php';
require_once dirname(__DIR__) . '/includes/helpers-ocr.php';

function assert_true($condition, $message): void {
    if (!$condition) {
        fwrite(STDERR, "Assertion failed: {$message}\n");
        exit(1);
    }
}

$fixture_files = glob(dirname(__DIR__) . '/fixtures/ocr-benchmarks/*.json');
assert_true(is_array($fixture_files) && !empty($fixture_files), 'benchmark fixtures should exist');

$agg = array(
    'precision_sum' => 0.0,
    'recall_sum' => 0.0,
    'type_accuracy_sum' => 0.0,
    'section_quality_sum' => 0.0,
    'repeater_quality_sum' => 0.0,
    'count' => 0,
);

foreach ($fixture_files as $fixture_file) {
    $decoded = json_decode((string) file_get_contents($fixture_file), true);
    assert_true(is_array($decoded), 'fixture json should decode');

    $label = (string) ($decoded['label'] ?? 'Fixture Form');
    $pages = isset($decoded['pages']) && is_array($decoded['pages']) ? $decoded['pages'] : array();
    $expected = isset($decoded['expected']) && is_array($decoded['expected']) ? $decoded['expected'] : array();
    $expected_fields = isset($expected['fields']) && is_array($expected['fields']) ? $expected['fields'] : array();

    $draft = dcb_ocr_to_draft_form('', $label, array('pages' => $pages));
    $fields = isset($draft['fields']) && is_array($draft['fields']) ? $draft['fields'] : array();

    $pred = array();
    foreach ($fields as $field) {
        if (!is_array($field)) {
            continue;
        }
        $key = sanitize_key((string) ($field['key'] ?? ''));
        if ($key === '') {
            continue;
        }
        $pred[$key] = sanitize_key((string) ($field['type'] ?? 'text'));
    }

    $exp = array();
    foreach ($expected_fields as $row) {
        if (!is_array($row)) {
            continue;
        }
        $k = sanitize_key((string) ($row['key'] ?? ''));
        if ($k === '') {
            continue;
        }
        $exp[$k] = sanitize_key((string) ($row['type'] ?? 'text'));
    }

    $tp = 0;
    $type_hits = 0;
    foreach ($exp as $key => $type) {
        if (isset($pred[$key])) {
            $tp++;
            if ($pred[$key] === $type) {
                $type_hits++;
            }
        }
    }

    $pred_count = max(1, count($pred));
    $exp_count = max(1, count($exp));
    $precision = $tp / $pred_count;
    $recall = $tp / $exp_count;
    $type_accuracy = $tp > 0 ? ($type_hits / $tp) : 0.0;

    $expected_sections = isset($expected['sections']) && is_array($expected['sections']) ? array_values(array_filter(array_map('sanitize_key', $expected['sections']))) : array();
    $actual_sections = isset($draft['sections']) && is_array($draft['sections']) ? $draft['sections'] : array();
    $actual_section_keys = array();
    foreach ($actual_sections as $section_row) {
        if (!is_array($section_row)) {
            continue;
        }
        $actual_section_keys[] = sanitize_key((string) ($section_row['key'] ?? ''));
    }
    $section_hits = 0;
    foreach ($expected_sections as $section_key) {
        if (in_array($section_key, $actual_section_keys, true)) {
            $section_hits++;
        }
    }
    $section_quality = empty($expected_sections) ? 1.0 : ($section_hits / max(1, count($expected_sections)));

    $min_repeater_count = max(0, (int) ($expected['min_repeater_count'] ?? 0));
    $actual_repeater_count = isset($draft['repeaters']) && is_array($draft['repeaters']) ? count($draft['repeaters']) : 0;
    $repeater_quality = $actual_repeater_count >= $min_repeater_count ? 1.0 : 0.0;

    $max_false_positive = max(0, (int) ($expected['max_false_positive'] ?? 999));
    $false_positive_count = max(0, count($pred) - $tp);
    assert_true($false_positive_count <= $max_false_positive, basename($fixture_file) . ' false positives should stay within benchmark bound');

    $agg['precision_sum'] += $precision;
    $agg['recall_sum'] += $recall;
    $agg['type_accuracy_sum'] += $type_accuracy;
    $agg['section_quality_sum'] += $section_quality;
    $agg['repeater_quality_sum'] += $repeater_quality;
    $agg['count']++;
}

$count = max(1, (int) $agg['count']);
$metrics = array(
    'fixtures' => $count,
    'field_precision' => round($agg['precision_sum'] / $count, 4),
    'field_recall' => round($agg['recall_sum'] / $count, 4),
    'field_type_accuracy' => round($agg['type_accuracy_sum'] / $count, 4),
    'section_detection_quality' => round($agg['section_quality_sum'] / $count, 4),
    'repeater_detection_quality' => round($agg['repeater_quality_sum'] / $count, 4),
);

assert_true($metrics['field_precision'] >= 0.45, 'field precision should meet minimum benchmark');
assert_true($metrics['field_recall'] >= 0.45, 'field recall should meet minimum benchmark');
assert_true($metrics['field_type_accuracy'] >= 0.45, 'field type accuracy should meet minimum benchmark');
assert_true($metrics['section_detection_quality'] >= 0.50, 'section detection quality should meet minimum benchmark');
assert_true($metrics['repeater_detection_quality'] >= 0.80, 'repeater detection quality should meet minimum benchmark');

echo 'ocr_quality_benchmark_smoke:ok ' . json_encode($metrics) . "\n";
