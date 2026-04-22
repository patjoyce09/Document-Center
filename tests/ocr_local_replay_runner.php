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

function runner_detect_mime(string $path, string $source_type): string {
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

function runner_rel_path(string $path, string $root): string {
    $clean = str_replace('\\', '/', (string) $path);
    $root_clean = rtrim(str_replace('\\', '/', (string) $root), '/');
    if ($root_clean !== '' && strpos($clean, $root_clean . '/') === 0) {
        return ltrim(substr($clean, strlen($root_clean)), '/');
    }
    return ltrim($clean, '/');
}

function runner_abs_path(string $root, string $value): string {
    $v = trim((string) $value);
    if ($v === '') {
        return '';
    }
    if (strpos($v, '/') === 0) {
        return $v;
    }
    return rtrim($root, '/') . '/' . ltrim($v, '/');
}

function runner_ratio_metric(int $actual, int $expected_min): float {
    if ($expected_min <= 0) {
        return $actual <= 0 ? 1.0 : 1.0;
    }
    return round(max(0.0, min(1.0, $actual / max(1, $expected_min))), 4);
}

function runner_extract_group_counts(array $extraction): array {
    $scene_pages = isset($extraction['ocr_scene_graph']['pages']) && is_array($extraction['ocr_scene_graph']['pages'])
        ? $extraction['ocr_scene_graph']['pages']
        : array();

    $yes_no = 0;
    $checkbox = 0;
    foreach ($scene_pages as $page) {
        if (!is_array($page)) {
            continue;
        }
        $groups = isset($page['grouped_controls']) && is_array($page['grouped_controls']) ? $page['grouped_controls'] : array();
        foreach ($groups as $group) {
            if (!is_array($group)) {
                continue;
            }
            $group_type = sanitize_key((string) ($group['group_type'] ?? ''));
            if ($group_type === 'yes_no') {
                $yes_no++;
            } elseif ($group_type === 'checkbox_cluster') {
                $checkbox++;
            }
        }
    }

    return array(
        'yes_no_group_count' => $yes_no,
        'checkbox_group_count' => $checkbox,
    );
}

function runner_page_reports(array $extraction): array {
    $pages = isset($extraction['pages']) && is_array($extraction['pages']) ? $extraction['pages'] : array();
    $scene_pages = isset($extraction['ocr_scene_graph']['pages']) && is_array($extraction['ocr_scene_graph']['pages'])
        ? $extraction['ocr_scene_graph']['pages']
        : array();
    $candidates = isset($extraction['ocr_candidates']) && is_array($extraction['ocr_candidates']) ? $extraction['ocr_candidates'] : array();
    $pairs = isset($extraction['ocr_document_model']['signature_date_pairs']) && is_array($extraction['ocr_document_model']['signature_date_pairs'])
        ? $extraction['ocr_document_model']['signature_date_pairs']
        : array();

    $scene_by_page = array();
    foreach ($scene_pages as $scene_page) {
        if (!is_array($scene_page)) {
            continue;
        }
        $pn = max(1, (int) ($scene_page['page_number'] ?? 1));
        $scene_by_page[$pn] = $scene_page;
    }

    $out = array();
    foreach ($pages as $page) {
        if (!is_array($page)) {
            continue;
        }
        $pn = max(1, (int) ($page['page_number'] ?? 1));
        $scene = isset($scene_by_page[$pn]) && is_array($scene_by_page[$pn]) ? $scene_by_page[$pn] : array();
        $widgets = isset($scene['widgets']) && is_array($scene['widgets']) ? $scene['widgets'] : array();
        $groups = isset($scene['grouped_controls']) && is_array($scene['grouped_controls']) ? $scene['grouped_controls'] : array();

        $yes_no_groups = 0;
        $checkbox_groups = 0;
        foreach ($groups as $group) {
            if (!is_array($group)) {
                continue;
            }
            $group_type = sanitize_key((string) ($group['group_type'] ?? ''));
            if ($group_type === 'yes_no') {
                $yes_no_groups++;
            } elseif ($group_type === 'checkbox_cluster') {
                $checkbox_groups++;
            }
        }

        $sig_pairs = 0;
        foreach ($pairs as $pair) {
            if (!is_array($pair)) {
                continue;
            }
            if (max(1, (int) ($pair['signature_page_number'] ?? 1)) === $pn && max(0, (int) ($pair['date_line_index'] ?? 0)) > 0) {
                $sig_pairs++;
            }
        }

        $review_needed = 0;
        foreach ($candidates as $candidate) {
            if (!is_array($candidate)) {
                continue;
            }
            if (max(1, (int) ($candidate['page_number'] ?? 1)) !== $pn) {
                continue;
            }
            if (sanitize_key((string) ($candidate['warning_state'] ?? '')) === 'review_needed') {
                $review_needed++;
            }
        }

        $out[] = array(
            'page_number' => $pn,
            'text_length_proxy' => max(0, (int) ($page['text_length'] ?? strlen((string) ($page['text'] ?? '')))),
            'confidence_proxy' => round(max(0.0, min(1.0, (float) ($page['confidence_proxy'] ?? 0.0))), 4),
            'widget_count' => count($widgets),
            'yes_no_group_count' => $yes_no_groups,
            'checkbox_group_count' => $checkbox_groups,
            'signature_pair_count' => $sig_pairs,
            'review_needed_candidates' => $review_needed,
        );
    }

    return $out;
}

function runner_parse_args(array $argv): array {
    $args = array(
        'manifest' => dirname(__DIR__) . '/fixtures/ocr-realworld/manifest.json',
        'artifact' => '',
        'json' => false,
        'private' => false,
    );

    foreach ($argv as $idx => $value) {
        if ($idx === 0) {
            continue;
        }
        $v = (string) $value;
        if ($v === '--json') {
            $args['json'] = true;
            continue;
        }
        if ($v === '--private') {
            $args['private'] = true;
            continue;
        }
        if (strpos($v, '--artifact=') === 0) {
            $args['artifact'] = trim(substr($v, strlen('--artifact=')));
            continue;
        }
        if (strpos($v, '--manifest=') === 0) {
            $args['manifest'] = trim(substr($v, strlen('--manifest=')));
            continue;
        }
    }

    return $args;
}

$args = runner_parse_args($argv);
$root = dirname(__DIR__);
if (!empty($args['private']) && (string) $args['manifest'] === $root . '/fixtures/ocr-realworld/manifest.json') {
    $args['manifest'] = $root . '/private-fixtures/ocr-realforms/manifests/realforms.local.json';
}
$manifest_file = (string) $args['manifest'];
if (!file_exists($manifest_file)) {
    fwrite(STDERR, "ocr_local_replay_runner:error manifest_missing {$manifest_file}\n");
    exit(1);
}

$manifest = json_decode((string) file_get_contents($manifest_file), true);
if (!is_array($manifest)) {
    fwrite(STDERR, "ocr_local_replay_runner:error manifest_invalid_json\n");
    exit(1);
}

$cases = isset($manifest['cases']) && is_array($manifest['cases']) ? $manifest['cases'] : array();
if (empty($cases)) {
    fwrite(STDERR, "ocr_local_replay_runner:error no_cases\n");
    exit(1);
}

$agg = array(
    'replay_cases_run' => 0,
    'binary_replay_cases_run' => 0,
    'binary_replay_success_count' => 0,
    'required_binary_missing_count' => 0,
    'avg_improvement_proxy_delta' => 0.0,
    'avg_warning_delta' => 0.0,
    'avg_confidence_delta' => 0.0,
    'avg_text_length_delta' => 0.0,
    'per_stage_applied_counts' => array(),
    'cases_with_unresolved_capture_risks' => 0,
    'field_precision' => 0.0,
    'field_recall' => 0.0,
    'field_type_accuracy' => 0.0,
    'section_detection_quality' => 0.0,
    'repeater_detection_quality' => 0.0,
    'avg_false_positive_count' => 0.0,
    'yes_no_grouping_accuracy' => 0.0,
    'checkbox_grouping_accuracy' => 0.0,
    'signature_date_pairing_accuracy' => 0.0,
    'review_cleanup_burden_proxy' => 0.0,
    'canonical_graph_completeness_proxy' => 0.0,
);

$sum = array(
    'improvement_delta' => 0.0,
    'warning_delta' => 0.0,
    'confidence_delta' => 0.0,
    'text_len_delta' => 0.0,
    'precision' => 0.0,
    'recall' => 0.0,
    'type_accuracy' => 0.0,
    'section_quality' => 0.0,
    'repeater_quality' => 0.0,
    'false_positive' => 0.0,
    'yes_no_grouping_accuracy' => 0.0,
    'checkbox_grouping_accuracy' => 0.0,
    'signature_date_pairing_accuracy' => 0.0,
    'cleanup_burden' => 0.0,
    'canonical_graph_completeness' => 0.0,
);

$case_reports = array();

foreach ($cases as $case) {
    if (!is_array($case)) {
        continue;
    }

    $agg['replay_cases_run']++;
    $case_id = sanitize_key((string) ($case['case_id'] ?? ($case['id'] ?? 'case_' . $agg['replay_cases_run'])));
    $label = sanitize_text_field((string) ($case['label'] ?? $case_id));
    $source_type = sanitize_key((string) ($case['source_type'] ?? 'other'));

    $sample_path = runner_abs_path($root, (string) ($case['sample_path'] ?? ''));
    $binary_candidate = (string) ($case['binary_path'] ?? ($case['source_file'] ?? ($case['optional_binary_path'] ?? '')));
    $binary_path = runner_abs_path($root, $binary_candidate);
    $required_binary = !empty($case['required_local_binary']);
    $binary_exists = $binary_path !== '' && file_exists($binary_path);
    $sample_exists = $sample_path !== '' && file_exists($sample_path);

    $replay_mode = 'sample_text_fallback';
    $diag = array();
    $draft = array();
    $extraction = array();

    if ($binary_exists) {
        $agg['binary_replay_cases_run']++;
        $replay_mode = 'binary_replay';
        $mime = runner_detect_mime($binary_path, $source_type);
        $diag = dcb_ocr_local_replay_before_after_diagnostics($binary_path, $mime);
        $after_result = isset($diag['after_result']) && is_array($diag['after_result']) ? $diag['after_result'] : array();
        $extraction = dcb_ocr_enrich_extraction_result($after_result);
        $text = sanitize_textarea_field((string) ($extraction['text'] ?? ''));
        $draft = dcb_ocr_to_draft_form($text, $label, $extraction);
        if ($text !== '' || (!empty($extraction['pages']) && is_array($extraction['pages']))) {
            $agg['binary_replay_success_count']++;
        }
    } else {
        if ($required_binary) {
            $agg['required_binary_missing_count']++;
            $replay_mode = 'required_binary_missing_fallback';
        }

        $sample_text = $sample_exists ? trim((string) file_get_contents($sample_path)) : '';
        $before_conf = dcb_text_confidence_proxy($sample_text);
        $pages = array(array(
            'page_number' => 1,
            'text' => $sample_text,
            'engine' => 'fixture-realworld-sample',
            'text_length' => strlen($sample_text),
            'confidence_proxy' => $before_conf,
        ));
        $extraction = array(
            'pages' => $pages,
            'text' => $sample_text,
            'input_source_type' => $source_type,
            'input_normalization' => array(
                'enabled' => true,
                'source_type' => $source_type,
                'max_dimension' => 2200,
                'stages' => array('orientation_correction', 'deskew', 'crop_cleanup', 'contrast_cleanup', 'max_dimension_normalization'),
                'page_count' => 1,
                'average_warning_count' => 0.0,
                'normalization_improvement_proxy' => 0.0,
                'warnings' => array(),
                'capture_recommendations' => array(),
                'stage_attempt_counts' => array('orientation_correction' => 1, 'deskew' => 1, 'crop_cleanup' => 1, 'contrast_cleanup' => 1, 'max_dimension_normalization' => 1),
                'stage_application_counts' => array('orientation_correction' => 0, 'deskew' => 0, 'crop_cleanup' => 0, 'contrast_cleanup' => 0, 'max_dimension_normalization' => 0),
            ),
        );
        $draft = dcb_ocr_to_draft_form($sample_text, $label, $extraction);
        $diag = array(
            'source_type' => $source_type,
            'before' => array('page_count' => 1, 'text_length_proxy' => strlen($sample_text), 'confidence_proxy' => round($before_conf, 4), 'warning_count' => 0),
            'after' => array('page_count' => 1, 'text_length_proxy' => strlen($sample_text), 'confidence_proxy' => round($before_conf, 4), 'warning_count' => 0, 'normalization_warning_count' => 0, 'improvement_proxy' => 0.0),
            'deltas' => array('text_length_delta' => 0, 'confidence_proxy_delta' => 0.0, 'warning_count_delta' => 0),
            'normalization' => array(
                'stages' => array('orientation_correction', 'deskew', 'crop_cleanup', 'contrast_cleanup', 'max_dimension_normalization'),
                'stage_attempt_counts' => array('orientation_correction' => 1, 'deskew' => 1, 'crop_cleanup' => 1, 'contrast_cleanup' => 1, 'max_dimension_normalization' => 1),
                'stage_application_counts' => array('orientation_correction' => 0, 'deskew' => 0, 'crop_cleanup' => 0, 'contrast_cleanup' => 0, 'max_dimension_normalization' => 0),
                'capture_recommendations' => array(),
                'average_warning_count' => 0.0,
                'rasterization_coverage' => $source_type === 'pdf' ? 1.0 : 0.0,
                'warnings' => array(),
            ),
        );
    }

    $norm = isset($diag['normalization']) && is_array($diag['normalization']) ? $diag['normalization'] : array();
    $stage_applied = isset($norm['stage_application_counts']) && is_array($norm['stage_application_counts']) ? $norm['stage_application_counts'] : array();
    foreach ($stage_applied as $stage => $count_val) {
        $k = sanitize_key((string) $stage);
        if ($k === '') {
            continue;
        }
        if (!isset($agg['per_stage_applied_counts'][$k])) {
            $agg['per_stage_applied_counts'][$k] = 0;
        }
        $agg['per_stage_applied_counts'][$k] += max(0, (int) $count_val);
    }

    $warnings = isset($norm['warnings']) && is_array($norm['warnings']) ? $norm['warnings'] : array();
    if (!empty($warnings)) {
        $agg['cases_with_unresolved_capture_risks']++;
    }

    $expected = isset($case['expected']) && is_array($case['expected']) ? $case['expected'] : array();
    $expected_fields = isset($expected['fields']) && is_array($expected['fields']) ? $expected['fields'] : array();
    $expected_features = isset($case['expected_features']) && is_array($case['expected_features']) ? $case['expected_features'] : array();
    $expected_groupings = isset($case['expected_groupings']) && is_array($case['expected_groupings']) ? $case['expected_groupings'] : array();
    $expected_signature_pairs = isset($case['expected_signature_date_pairs']) && is_array($case['expected_signature_date_pairs']) ? $case['expected_signature_date_pairs'] : array();
    $expected_critical_fields = isset($case['expected_critical_fields']) && is_array($case['expected_critical_fields']) ? $case['expected_critical_fields'] : array();

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
    foreach ($exp as $k => $type) {
        if (isset($pred[$k])) {
            $tp++;
            if ($pred[$k] === $type) {
                $type_hits++;
            }
        }
    }

    $precision = $tp / max(1, count($pred));
    $recall = $tp / max(1, count($exp));
    $type_accuracy = $tp > 0 ? ($type_hits / $tp) : 0.0;
    $false_positive_count = max(0, count($pred) - $tp);

    $critical_keys = array();
    foreach ($expected_critical_fields as $critical_key) {
        $k = sanitize_key((string) $critical_key);
        if ($k !== '') {
            $critical_keys[] = $k;
        }
    }
    $critical_hits = 0;
    foreach ($critical_keys as $critical_key) {
        if (isset($pred[$critical_key])) {
            $critical_hits++;
        }
    }
    $critical_recall = empty($critical_keys) ? 1.0 : ($critical_hits / max(1, count($critical_keys)));

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

    $quality_metrics = isset($extraction['quality_metrics']) && is_array($extraction['quality_metrics']) ? $extraction['quality_metrics'] : array();
    $group_counts = runner_extract_group_counts($extraction);
    $yes_no_actual = max(0, (int) ($group_counts['yes_no_group_count'] ?? 0));
    $checkbox_actual = max(0, (int) ($group_counts['checkbox_group_count'] ?? 0));
    $signature_pair_actual = max(0, (int) ($quality_metrics['signature_pair_count'] ?? 0));

    $yes_no_expected_min = max(0, (int) ($expected_groupings['yes_no_group_count_min'] ?? (!empty($expected_features['has_yes_no_groups']) ? 1 : 0)));
    $checkbox_expected_min = max(0, (int) ($expected_groupings['checkbox_group_count_min'] ?? (!empty($expected_features['has_checkboxes']) ? 1 : 0)));
    $signature_pair_expected_min = max(0, (int) ($expected_signature_pairs['min_pairs'] ?? (!empty($expected_features['has_signatures']) && !empty($expected_features['has_dates']) ? 1 : 0)));

    $yes_no_grouping_accuracy = runner_ratio_metric($yes_no_actual, $yes_no_expected_min);
    $checkbox_grouping_accuracy = runner_ratio_metric($checkbox_actual, $checkbox_expected_min);
    $signature_pairing_accuracy = runner_ratio_metric($signature_pair_actual, $signature_pair_expected_min);

    $cleanup_burden = isset($quality_metrics['review_cleanup_burden_proxy'])
        ? round(max(0.0, min(1.0, (float) $quality_metrics['review_cleanup_burden_proxy'])), 4)
        : round($false_positive_count / max(1, count($pred)), 4);

    $canonical_rel = max(0, (int) ($quality_metrics['canonical_relation_count'] ?? 0));
    $canonical_pages = max(0, (int) ($quality_metrics['canonical_page_count'] ?? 0));
    $widget_count = max(0, (int) ($quality_metrics['widget_candidate_count'] ?? count($pred)));
    $page_count = max(1, count((array) ($extraction['pages'] ?? array())));
    $expected_relation_floor = max(1, (int) floor($widget_count * 0.55));
    $relation_ratio = max(0.0, min(1.0, $canonical_rel / max(1, $expected_relation_floor)));
    $page_ratio = max(0.0, min(1.0, $canonical_pages / max(1, $page_count)));
    $canonical_graph_completeness = round(($relation_ratio * 0.7) + ($page_ratio * 0.3), 4);

    $sum['improvement_delta'] += isset($diag['deltas']['confidence_proxy_delta']) ? (float) $diag['deltas']['confidence_proxy_delta'] : 0.0;
    $sum['warning_delta'] += isset($diag['deltas']['warning_count_delta']) ? (float) $diag['deltas']['warning_count_delta'] : 0.0;
    $sum['confidence_delta'] += isset($diag['deltas']['confidence_proxy_delta']) ? (float) $diag['deltas']['confidence_proxy_delta'] : 0.0;
    $sum['text_len_delta'] += isset($diag['deltas']['text_length_delta']) ? (float) $diag['deltas']['text_length_delta'] : 0.0;
    $sum['precision'] += $precision;
    $sum['recall'] += $recall;
    $sum['type_accuracy'] += $type_accuracy;
    $sum['section_quality'] += $section_quality;
    $sum['repeater_quality'] += $repeater_quality;
    $sum['false_positive'] += $false_positive_count;
    $sum['yes_no_grouping_accuracy'] += $yes_no_grouping_accuracy;
    $sum['checkbox_grouping_accuracy'] += $checkbox_grouping_accuracy;
    $sum['signature_date_pairing_accuracy'] += $signature_pairing_accuracy;
    $sum['cleanup_burden'] += $cleanup_burden;
    $sum['canonical_graph_completeness'] += $canonical_graph_completeness;

    $case_reports[] = array(
        'case_id' => $case_id,
        'label' => $label,
        'fixture_type' => sanitize_key((string) ($case['fixture_type'] ?? 'realworld')),
        'source_quality' => sanitize_key((string) ($case['source_quality'] ?? 'unknown')),
        'dense_vs_sparse' => sanitize_key((string) ($case['dense_vs_sparse'] ?? 'unknown')),
        'packet_file' => runner_rel_path((string) ($case['packet_file'] ?? ''), $root),
        'source_file' => runner_rel_path((string) ($case['source_file'] ?? ''), $root),
        'binary_path' => runner_rel_path($binary_path, $root),
        'manifest_page_number' => max(0, (int) ($case['page_number'] ?? 0)),
        'source_type' => $source_type,
        'mode' => $replay_mode,
        'binary_present' => $binary_exists,
        'sample_present' => $sample_exists,
        'page_count' => max(1, (int) ($diag['after']['page_count'] ?? 1)),
        'before_text_length_proxy' => max(0, (int) ($diag['before']['text_length_proxy'] ?? 0)),
        'after_text_length_proxy' => max(0, (int) ($diag['after']['text_length_proxy'] ?? 0)),
        'text_length_delta' => (int) ($diag['deltas']['text_length_delta'] ?? 0),
        'before_confidence' => round((float) ($diag['before']['confidence_proxy'] ?? 0.0), 4),
        'after_confidence' => round((float) ($diag['after']['confidence_proxy'] ?? 0.0), 4),
        'confidence_delta' => round((float) ($diag['deltas']['confidence_proxy_delta'] ?? 0.0), 4),
        'before_warning_count' => max(0, (int) ($diag['before']['warning_count'] ?? 0)),
        'after_warning_count' => max(0, (int) ($diag['after']['warning_count'] ?? 0)),
        'warning_count_delta' => (int) ($diag['deltas']['warning_count_delta'] ?? 0),
        'normalization_stages_attempted' => isset($norm['stage_attempt_counts']) ? $norm['stage_attempt_counts'] : array(),
        'normalization_stages_applied' => isset($norm['stage_application_counts']) ? $norm['stage_application_counts'] : array(),
        'normalization_improvement_proxy' => round((float) ($diag['after']['improvement_proxy'] ?? 0.0), 4),
        'capture_recommendations' => isset($norm['capture_recommendations']) ? $norm['capture_recommendations'] : array(),
        'field_precision' => round($precision, 4),
        'field_recall' => round($recall, 4),
        'critical_field_recall' => round($critical_recall, 4),
        'field_type_accuracy' => round($type_accuracy, 4),
        'false_positive_count' => $false_positive_count,
        'yes_no_grouping_accuracy' => $yes_no_grouping_accuracy,
        'checkbox_grouping_accuracy' => $checkbox_grouping_accuracy,
        'signature_date_pairing_accuracy' => $signature_pairing_accuracy,
        'review_cleanup_burden_proxy' => $cleanup_burden,
        'canonical_graph_completeness_proxy' => $canonical_graph_completeness,
        'yes_no_group_count' => $yes_no_actual,
        'checkbox_group_count' => $checkbox_actual,
        'signature_pair_count' => $signature_pair_actual,
        'quality_metrics' => array(
            'widget_candidate_count' => $widget_count,
            'canonical_relation_count' => $canonical_rel,
            'canonical_page_count' => $canonical_pages,
        ),
        'page_reports' => runner_page_reports($extraction),
    );
}

$count = max(1, (int) $agg['replay_cases_run']);
$agg['avg_improvement_proxy_delta'] = round($sum['improvement_delta'] / $count, 4);
$agg['avg_warning_delta'] = round($sum['warning_delta'] / $count, 4);
$agg['avg_confidence_delta'] = round($sum['confidence_delta'] / $count, 4);
$agg['avg_text_length_delta'] = round($sum['text_len_delta'] / $count, 4);
$agg['field_precision'] = round($sum['precision'] / $count, 4);
$agg['field_recall'] = round($sum['recall'] / $count, 4);
$agg['field_type_accuracy'] = round($sum['type_accuracy'] / $count, 4);
$agg['section_detection_quality'] = round($sum['section_quality'] / $count, 4);
$agg['repeater_detection_quality'] = round($sum['repeater_quality'] / $count, 4);
$agg['avg_false_positive_count'] = round($sum['false_positive'] / $count, 4);
$agg['yes_no_grouping_accuracy'] = round($sum['yes_no_grouping_accuracy'] / $count, 4);
$agg['checkbox_grouping_accuracy'] = round($sum['checkbox_grouping_accuracy'] / $count, 4);
$agg['signature_date_pairing_accuracy'] = round($sum['signature_date_pairing_accuracy'] / $count, 4);
$agg['review_cleanup_burden_proxy'] = round($sum['cleanup_burden'] / $count, 4);
$agg['canonical_graph_completeness_proxy'] = round($sum['canonical_graph_completeness'] / $count, 4);

if (empty($args['json'])) {
    foreach ($case_reports as $case_report) {
        echo 'replay_case ' . json_encode($case_report) . "\n";
    }
    echo 'replay_summary ' . json_encode($agg) . "\n";
} else {
    echo json_encode(array('summary' => $agg, 'cases' => $case_reports)) . "\n";
}

if (!empty($args['artifact'])) {
    $artifact_path = (string) $args['artifact'];
    $artifact_dir = dirname($artifact_path);
    if (!is_dir($artifact_dir)) {
        @mkdir($artifact_dir, 0777, true);
    }
    @file_put_contents($artifact_path, json_encode(array('summary' => $agg, 'cases' => $case_reports), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}

exit(0);
