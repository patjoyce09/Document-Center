<?php

define('ABSPATH', __DIR__ . '/');

$GLOBALS['dcb_test_options'] = array(
    'dcb_ocr_input_normalization_enabled' => '1',
    'dcb_ocr_input_max_dimension' => 2200,
);

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
if (!function_exists('wp_generate_password')) {
    function wp_generate_password($len = 12) { return substr(str_repeat('a', $len), 0, $len); }
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
if (!function_exists('wp_mkdir_p')) {
    function wp_mkdir_p($dir) { return @mkdir((string) $dir, 0777, true) || is_dir((string) $dir); }
}
if (!function_exists('mb_substr')) {
    function mb_substr($text, $start, $length = null) { return $length === null ? substr((string) $text, (int) $start) : substr((string) $text, (int) $start, (int) $length); }
}
if (!function_exists('apply_filters')) {
    function apply_filters($hook, $value) { return $value; }
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

$manifest_file = dirname(__DIR__) . '/fixtures/ocr-realworld/manifest.json';
assert_true(file_exists($manifest_file), 'real-world manifest should exist');

$manifest = json_decode((string) file_get_contents($manifest_file), true);
assert_true(is_array($manifest), 'manifest must decode');
$cases = isset($manifest['cases']) && is_array($manifest['cases']) ? $manifest['cases'] : array();
assert_true(!empty($cases), 'manifest should include cases');

$agg = array(
    'precision_sum' => 0.0,
    'recall_sum' => 0.0,
    'type_accuracy_sum' => 0.0,
    'section_quality_sum' => 0.0,
    'repeater_quality_sum' => 0.0,
    'false_positive_sum' => 0.0,
    'cleanup_burden_sum' => 0.0,
    'count' => 0,
    'binary_samples_present' => 0,
);

foreach ($cases as $case) {
    if (!is_array($case)) {
        continue;
    }

    $label = sanitize_text_field((string) ($case['label'] ?? 'Fixture Case'));
    $sample_rel = sanitize_text_field((string) ($case['sample_path'] ?? ''));
    $sample_path = dirname(__DIR__) . '/' . ltrim($sample_rel, '/');
    assert_true($sample_path !== '' && file_exists($sample_path), 'sample text should exist for ' . $label);

    $binary_rel = sanitize_text_field((string) ($case['optional_binary_path'] ?? ''));
    $binary_path = $binary_rel !== '' ? dirname(__DIR__) . '/' . ltrim($binary_rel, '/') : '';
    if ($binary_path !== '' && file_exists($binary_path)) {
        $agg['binary_samples_present']++;
    }

    $sample_text = trim((string) file_get_contents($sample_path));
    $pages = array(array(
        'page_number' => 1,
        'text' => $sample_text,
        'engine' => 'fixture-realworld-sample',
        'text_length' => strlen($sample_text),
        'confidence_proxy' => dcb_text_confidence_proxy($sample_text),
    ));

    $draft = dcb_ocr_to_draft_form($sample_text, $label, array(
        'pages' => $pages,
        'input_source_type' => sanitize_key((string) ($case['source_type'] ?? 'other')),
        'input_normalization' => array(
            'enabled' => true,
            'source_type' => sanitize_key((string) ($case['source_type'] ?? 'other')),
            'max_dimension' => 2200,
            'stages' => array('orientation_correction', 'deskew', 'crop_cleanup', 'contrast_cleanup', 'max_dimension_normalization'),
            'page_count' => 1,
        ),
    ));

    $expected = isset($case['expected']) && is_array($case['expected']) ? $case['expected'] : array();
    $expected_fields = isset($expected['fields']) && is_array($expected['fields']) ? $expected['fields'] : array();

    $pred = array();
    foreach ((array) ($draft['fields'] ?? array()) as $field) {
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

    $false_positive_count = max(0, count($pred) - $tp);
    $max_false_positive = max(0, (int) ($expected['max_false_positive'] ?? 999));
    assert_true($false_positive_count <= $max_false_positive, $label . ': false positives should stay within expected bound');

    $cleanup_burden = isset($draft['ocr_review']['review_cleanup_burden_proxy']) && is_numeric($draft['ocr_review']['review_cleanup_burden_proxy'])
        ? (float) $draft['ocr_review']['review_cleanup_burden_proxy']
        : ($false_positive_count / max(1, count($pred)));

    $agg['precision_sum'] += $precision;
    $agg['recall_sum'] += $recall;
    $agg['type_accuracy_sum'] += $type_accuracy;
    $agg['section_quality_sum'] += $section_quality;
    $agg['repeater_quality_sum'] += $repeater_quality;
    $agg['false_positive_sum'] += $false_positive_count;
    $agg['cleanup_burden_sum'] += max(0.0, min(1.0, $cleanup_burden));
    $agg['count']++;
}

$count = max(1, (int) $agg['count']);
$metrics = array(
    'cases' => $count,
    'binary_samples_present' => (int) $agg['binary_samples_present'],
    'field_precision' => round($agg['precision_sum'] / $count, 4),
    'field_recall' => round($agg['recall_sum'] / $count, 4),
    'field_type_accuracy' => round($agg['type_accuracy_sum'] / $count, 4),
    'section_detection_quality' => round($agg['section_quality_sum'] / $count, 4),
    'repeater_detection_quality' => round($agg['repeater_quality_sum'] / $count, 4),
    'avg_false_positive_count' => round($agg['false_positive_sum'] / $count, 4),
    'review_cleanup_burden_proxy' => round($agg['cleanup_burden_sum'] / $count, 4),
);

assert_true($metrics['field_precision'] >= 0.40, 'real-world precision should meet minimum');
assert_true($metrics['field_recall'] >= 0.40, 'real-world recall should meet minimum');
assert_true($metrics['field_type_accuracy'] >= 0.40, 'real-world type accuracy should meet minimum');
assert_true($metrics['review_cleanup_burden_proxy'] <= 0.55, 'real-world cleanup burden should stay below ceiling');

echo 'ocr_realworld_benchmark_smoke:ok ' . json_encode($metrics) . "\n";
