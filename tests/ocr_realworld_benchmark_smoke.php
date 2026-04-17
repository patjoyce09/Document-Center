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

require_once dirname(__DIR__) . '/includes/helpers-schema.php';
require_once dirname(__DIR__) . '/includes/helpers-ocr.php';

function assert_true($condition, $message): void {
    if (!$condition) {
        fwrite(STDERR, "Assertion failed: {$message}\n");
        exit(1);
    }
}

function detect_fixture_mime(string $path, string $source_type): string {
    $ext = strtolower((string) pathinfo($path, PATHINFO_EXTENSION));
    if ($ext === 'pdf' || $source_type === 'pdf') {
        return 'application/pdf';
    }
    if (in_array($ext, array('jpg', 'jpeg', 'jfif'), true)) {
        return 'image/jpeg';
    }
    if ($ext === 'png') {
        return 'image/png';
    }
    if ($ext === 'webp') {
        return 'image/webp';
    }
    if ($ext === 'txt') {
        return 'text/plain';
    }
    if (function_exists('mime_content_type')) {
        $detected = @mime_content_type($path);
        if (is_string($detected) && $detected !== '') {
            return strtolower(trim($detected));
        }
    }
    return 'application/octet-stream';
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
    'binary_replay_attempted' => 0,
    'binary_replay_success' => 0,
    'required_binary_missing' => 0,
    'average_capture_warning_sum' => 0.0,
    'normalization_improvement_sum' => 0.0,
    'rasterization_coverage_sum' => 0.0,
    'low_resolution_risk_cases' => 0,
    'low_contrast_risk_cases' => 0,
    'rotation_skew_risk_cases' => 0,
    'crop_border_risk_cases' => 0,
    'stage_attempt_counts' => array(),
    'stage_application_counts' => array(),
    'case_diagnostics' => array(),
    'confidence_delta_sum' => 0.0,
    'warning_delta_sum' => 0.0,
    'text_length_delta_sum' => 0.0,
    'unresolved_capture_risk_cases' => 0,
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
    $required_local_binary = !empty($case['required_local_binary']);
    $binary_present = $binary_path !== '' && file_exists($binary_path);
    if ($binary_present) {
        $agg['binary_samples_present']++;
    } elseif ($required_local_binary) {
        $agg['required_binary_missing']++;
    }

    $source_type = sanitize_key((string) ($case['source_type'] ?? 'other'));
    $replay_mode = 'sample_text_fallback';
    $replay_diag = array();
    $extraction = array();
    $draft = array();

    if ($binary_present) {
        $agg['binary_replay_attempted']++;
        $replay_mode = 'binary_replay';
        $mime = detect_fixture_mime($binary_path, $source_type);
        $replay_diag = dcb_ocr_local_replay_before_after_diagnostics($binary_path, $mime);
        $raw_after = isset($replay_diag['after_result']) && is_array($replay_diag['after_result']) ? $replay_diag['after_result'] : array();
        $extraction = dcb_ocr_enrich_extraction_result((array) $raw_after);
        $text = sanitize_textarea_field((string) ($extraction['text'] ?? ''));
        $draft = dcb_ocr_to_draft_form($text, $label, $extraction);
        if ($text !== '' || (!empty($extraction['pages']) && is_array($extraction['pages']))) {
            $agg['binary_replay_success']++;
        }
    }

    if (empty($draft)) {
        $sample_text = trim((string) file_get_contents($sample_path));
        $pages = array(array(
            'page_number' => 1,
            'text' => $sample_text,
            'engine' => 'fixture-realworld-sample',
            'text_length' => strlen($sample_text),
            'confidence_proxy' => dcb_text_confidence_proxy($sample_text),
        ));

        $extraction = array(
            'pages' => $pages,
            'input_source_type' => $source_type,
            'input_normalization' => array(
                'enabled' => true,
                'source_type' => $source_type,
                'max_dimension' => 2200,
                'stages' => array('orientation_correction', 'deskew', 'crop_cleanup', 'contrast_cleanup', 'max_dimension_normalization'),
                'page_count' => 1,
                'average_warning_count' => 0.0,
                'normalization_improvement_proxy' => 0.0,
                'stage_attempt_counts' => array('orientation_correction' => 1, 'deskew' => 1, 'crop_cleanup' => 1, 'contrast_cleanup' => 1, 'max_dimension_normalization' => 1),
                'stage_application_counts' => array('orientation_correction' => 0, 'deskew' => 0, 'crop_cleanup' => 0, 'contrast_cleanup' => 0, 'max_dimension_normalization' => 0),
                'warnings' => array(),
                'capture_recommendations' => array(),
                'rasterization_coverage' => $source_type === 'pdf' ? 1.0 : 0.0,
            ),
        );

        $replay_diag = array(
            'source_type' => $source_type,
            'before' => array('page_count' => 1, 'text_length_proxy' => strlen($sample_text), 'confidence_proxy' => round(dcb_text_confidence_proxy($sample_text), 4), 'warning_count' => 0),
            'after' => array('page_count' => 1, 'text_length_proxy' => strlen($sample_text), 'confidence_proxy' => round(dcb_text_confidence_proxy($sample_text), 4), 'warning_count' => 0, 'normalization_warning_count' => 0, 'improvement_proxy' => 0.0),
            'deltas' => array('text_length_delta' => 0, 'confidence_proxy_delta' => 0.0, 'warning_count_delta' => 0),
            'normalization' => array(
                'stage_attempt_counts' => array('orientation_correction' => 1, 'deskew' => 1, 'crop_cleanup' => 1, 'contrast_cleanup' => 1, 'max_dimension_normalization' => 1),
                'stage_application_counts' => array('orientation_correction' => 0, 'deskew' => 0, 'crop_cleanup' => 0, 'contrast_cleanup' => 0, 'max_dimension_normalization' => 0),
                'warnings' => array(),
                'capture_recommendations' => array(),
                'average_warning_count' => 0.0,
                'rasterization_coverage' => $source_type === 'pdf' ? 1.0 : 0.0,
            ),
        );

        $draft = dcb_ocr_to_draft_form($sample_text, $label, $extraction);
    }

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

    $norm = isset($draft['ocr_review']['input_normalization']) && is_array($draft['ocr_review']['input_normalization'])
        ? $draft['ocr_review']['input_normalization']
        : (isset($extraction['input_normalization']) && is_array($extraction['input_normalization']) ? $extraction['input_normalization'] : array());

    $stage_attempts = isset($norm['stage_attempt_counts']) && is_array($norm['stage_attempt_counts']) ? $norm['stage_attempt_counts'] : array();
    $stage_applied = isset($norm['stage_application_counts']) && is_array($norm['stage_application_counts']) ? $norm['stage_application_counts'] : array();
    foreach ($stage_attempts as $stage => $count_val) {
        $k = sanitize_key((string) $stage);
        if ($k === '') {
            continue;
        }
        if (!isset($agg['stage_attempt_counts'][$k])) {
            $agg['stage_attempt_counts'][$k] = 0;
        }
        $agg['stage_attempt_counts'][$k] += max(0, (int) $count_val);
    }
    foreach ($stage_applied as $stage => $count_val) {
        $k = sanitize_key((string) $stage);
        if ($k === '') {
            continue;
        }
        if (!isset($agg['stage_application_counts'][$k])) {
            $agg['stage_application_counts'][$k] = 0;
        }
        $agg['stage_application_counts'][$k] += max(0, (int) $count_val);
    }

    $quality_rows = isset($norm['quality']) && is_array($norm['quality']) ? $norm['quality'] : array();
    $any_low_resolution = false;
    $any_low_contrast = false;
    $any_rotation_skew = false;
    $any_crop_border = false;
    foreach ($quality_rows as $q_row) {
        if (!is_array($q_row)) {
            continue;
        }
        $any_low_resolution = $any_low_resolution || !empty($q_row['low_resolution_risk']);
        $any_low_contrast = $any_low_contrast || !empty($q_row['low_contrast_risk']);
        $any_rotation_skew = $any_rotation_skew || !empty($q_row['rotation_skew_risk']);
        $any_crop_border = $any_crop_border || !empty($q_row['crop_border_risk']);
    }
    if ($any_low_resolution) {
        $agg['low_resolution_risk_cases']++;
    }
    if ($any_low_contrast) {
        $agg['low_contrast_risk_cases']++;
    }
    if ($any_rotation_skew) {
        $agg['rotation_skew_risk_cases']++;
    }
    if ($any_crop_border) {
        $agg['crop_border_risk_cases']++;
    }

    $avg_capture_warning = isset($norm['average_warning_count']) && is_numeric($norm['average_warning_count']) ? (float) $norm['average_warning_count'] : 0.0;
    $normalization_improvement = isset($norm['normalization_improvement_proxy']) && is_numeric($norm['normalization_improvement_proxy']) ? (float) $norm['normalization_improvement_proxy'] : 0.0;
    $rasterization_coverage = isset($norm['rasterization_coverage']) && is_numeric($norm['rasterization_coverage']) ? (float) $norm['rasterization_coverage'] : 0.0;

    $agg['average_capture_warning_sum'] += max(0.0, $avg_capture_warning);
    $agg['normalization_improvement_sum'] += max(0.0, min(1.0, $normalization_improvement));
    $agg['rasterization_coverage_sum'] += max(0.0, min(1.0, $rasterization_coverage));
    $agg['confidence_delta_sum'] += isset($replay_diag['deltas']['confidence_proxy_delta']) ? (float) $replay_diag['deltas']['confidence_proxy_delta'] : 0.0;
    $agg['warning_delta_sum'] += isset($replay_diag['deltas']['warning_count_delta']) ? (float) $replay_diag['deltas']['warning_count_delta'] : 0.0;
    $agg['text_length_delta_sum'] += isset($replay_diag['deltas']['text_length_delta']) ? (float) $replay_diag['deltas']['text_length_delta'] : 0.0;
    if (!empty($norm['warnings']) && is_array($norm['warnings'])) {
        $agg['unresolved_capture_risk_cases']++;
    }

    $agg['case_diagnostics'][] = array(
        'case_id' => sanitize_key((string) ($case['case_id'] ?? 'case_' . ($agg['count'] + 1))),
        'replay_mode' => $replay_mode,
        'binary_present' => $binary_present,
        'source_type' => $source_type,
        'page_count' => max(1, (int) ($norm['page_count'] ?? 1)),
        'orientation_correction_attempted' => max(0, (int) ($stage_attempts['orientation_correction'] ?? 0)),
        'orientation_correction_applied' => max(0, (int) ($stage_applied['orientation_correction'] ?? 0)),
        'deskew_attempted' => max(0, (int) ($stage_attempts['deskew'] ?? 0)),
        'deskew_applied' => max(0, (int) ($stage_applied['deskew'] ?? 0)),
        'crop_cleanup_attempted' => max(0, (int) ($stage_attempts['crop_cleanup'] ?? 0)),
        'crop_cleanup_applied' => max(0, (int) ($stage_applied['crop_cleanup'] ?? 0)),
        'contrast_cleanup_attempted' => max(0, (int) ($stage_attempts['contrast_cleanup'] ?? 0)),
        'contrast_cleanup_applied' => max(0, (int) ($stage_applied['contrast_cleanup'] ?? 0)),
        'max_dimension_attempted' => max(0, (int) ($stage_attempts['max_dimension_normalization'] ?? 0)),
        'max_dimension_applied' => max(0, (int) ($stage_applied['max_dimension_normalization'] ?? 0)),
        'normalization_warning_count' => isset($norm['warnings']) && is_array($norm['warnings']) ? count($norm['warnings']) : 0,
        'capture_warning_count_avg' => round(max(0.0, $avg_capture_warning), 4),
        'normalization_improvement_proxy' => round(max(0.0, min(1.0, $normalization_improvement)), 4),
        'rasterization_coverage' => round(max(0.0, min(1.0, $rasterization_coverage)), 4),
        'before_text_length_proxy' => max(0, (int) ($replay_diag['before']['text_length_proxy'] ?? 0)),
        'after_text_length_proxy' => max(0, (int) ($replay_diag['after']['text_length_proxy'] ?? 0)),
        'before_confidence_proxy' => round((float) ($replay_diag['before']['confidence_proxy'] ?? 0.0), 4),
        'after_confidence_proxy' => round((float) ($replay_diag['after']['confidence_proxy'] ?? 0.0), 4),
        'before_warning_count' => max(0, (int) ($replay_diag['before']['warning_count'] ?? 0)),
        'after_warning_count' => max(0, (int) ($replay_diag['after']['warning_count'] ?? 0)),
        'confidence_proxy_delta' => round((float) ($replay_diag['deltas']['confidence_proxy_delta'] ?? 0.0), 4),
        'warning_count_delta' => (int) ($replay_diag['deltas']['warning_count_delta'] ?? 0),
        'text_length_delta' => (int) ($replay_diag['deltas']['text_length_delta'] ?? 0),
    );

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
    'binary_replay_attempted_count' => (int) $agg['binary_replay_attempted'],
    'binary_replay_success_count' => (int) $agg['binary_replay_success'],
    'required_binary_missing_count' => (int) $agg['required_binary_missing'],
    'normalization_stage_attempt_counts' => $agg['stage_attempt_counts'],
    'normalization_stage_application_counts' => $agg['stage_application_counts'],
    'average_capture_warning_count' => round($agg['average_capture_warning_sum'] / $count, 4),
    'normalization_improvement_proxy' => round($agg['normalization_improvement_sum'] / $count, 4),
    'rasterization_coverage_avg' => round($agg['rasterization_coverage_sum'] / $count, 4),
    'low_resolution_risk_case_rate' => round($agg['low_resolution_risk_cases'] / $count, 4),
    'low_contrast_risk_case_rate' => round($agg['low_contrast_risk_cases'] / $count, 4),
    'rotation_skew_risk_case_rate' => round($agg['rotation_skew_risk_cases'] / $count, 4),
    'crop_border_risk_case_rate' => round($agg['crop_border_risk_cases'] / $count, 4),
    'avg_confidence_proxy_delta' => round($agg['confidence_delta_sum'] / $count, 4),
    'avg_warning_count_delta' => round($agg['warning_delta_sum'] / $count, 4),
    'avg_text_length_delta' => round($agg['text_length_delta_sum'] / $count, 4),
    'unresolved_capture_risk_cases' => (int) $agg['unresolved_capture_risk_cases'],
);

assert_true($metrics['field_precision'] >= 0.40, 'real-world precision should meet minimum');
assert_true($metrics['field_recall'] >= 0.40, 'real-world recall should meet minimum');
assert_true($metrics['field_type_accuracy'] >= 0.40, 'real-world type accuracy should meet minimum');
assert_true($metrics['review_cleanup_burden_proxy'] <= 0.55, 'real-world cleanup burden should stay below ceiling');

$diag_log = array(
    'cases' => $agg['case_diagnostics'],
);

echo 'ocr_realworld_benchmark_smoke:ok ' . json_encode($metrics) . "\n";
echo 'ocr_realworld_benchmark_smoke:diagnostics ' . json_encode($diag_log) . "\n";
