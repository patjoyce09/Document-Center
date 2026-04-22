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
    function current_time() { return '2026-04-22 00:00:00'; }
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

$pages = array(
    array(
        'page_number' => 1,
        'engine' => 'fixture',
        'text' => "Patient Name: ______\nDate of Birth: ____\nSignature: ______\nDate: ______",
        'text_length' => 120,
        'confidence_proxy' => 0.84,
    ),
    array(
        'page_number' => 2,
        'engine' => 'fixture',
        'text' => "Do you consent? Yes / No\nAdditional Notes: ______",
        'text_length' => 96,
        'confidence_proxy' => 0.81,
    ),
);

$document_model = dcb_ocr_build_document_model($pages);
$page_meta = array(
    1 => array('page_number' => 1, 'engine' => 'fixture', 'confidence_proxy' => 0.84),
    2 => array('page_number' => 2, 'engine' => 'fixture', 'confidence_proxy' => 0.81),
);
$widgets = dcb_ocr_detect_field_widgets($document_model, $page_meta, array());
$page_graph = dcb_ocr_build_page_relation_graph($document_model, $widgets);
$scene_graph = dcb_ocr_build_scene_graph($document_model, $widgets, $page_graph, $page_meta);
$canonical = dcb_ocr_build_canonical_form_graph($document_model, $widgets, $page_graph, $scene_graph, $page_meta, array());

$page_widgets = array(1 => array(), 2 => array());
$page_groups = array(1 => array(), 2 => array());
foreach ((array) ($canonical['pages'] ?? array()) as $page_row) {
    if (!is_array($page_row)) {
        continue;
    }
    $pn = max(1, (int) ($page_row['page_number'] ?? 1));
    foreach ((array) ($page_row['widgets'] ?? array()) as $widget_row) {
        if (!is_array($widget_row)) {
            continue;
        }
        $sid = sanitize_key((string) ($widget_row['stable_id'] ?? ''));
        if ($sid !== '') {
            $page_widgets[$pn][] = $sid;
        }
    }
    foreach ((array) ($page_row['grouped_controls'] ?? array()) as $group_row) {
        if (!is_array($group_row)) {
            continue;
        }
        $sid = sanitize_key((string) ($group_row['stable_id'] ?? ''));
        if ($sid !== '') {
            $page_groups[$pn][] = $sid;
        }
    }
}

assert_true(!empty($page_widgets[1]) && !empty($page_widgets[2]), 'fixture must produce widgets on both pages');

$widget_page1 = $page_widgets[1][0];
$widget_page1_b = isset($page_widgets[1][1]) ? $page_widgets[1][1] : $page_widgets[1][0];
$widget_page2 = $page_widgets[2][0];
$group_page2 = !empty($page_groups[2]) ? $page_groups[2][0] : 'group_2_synthetic';

$patch = array(
    'patch_version' => '1.0',
    'patch_id' => 'validation_boundary_smoke',
    'meta' => array('source' => 'test'),
    'widgets' => array(
        array(
            'stable_id' => $widget_page1,
            'label_text' => 'Corrected Label',
            'group_membership' => array(
                'add' => array($group_page2),
                'remove' => array(),
            ),
        ),
    ),
    'relations' => array(
        array(
            'decision' => 'upsert',
            'from' => $widget_page1,
            'to' => $widget_page1_b,
            'relation' => 'same_question_group',
        ),
        array(
            'decision' => 'upsert',
            'from' => $widget_page1,
            'to' => $widget_page2,
            'relation' => 'teleport_link',
        ),
        array(
            'decision' => 'upsert',
            'from' => $widget_page1,
            'to' => $widget_page2,
            'relation' => 'paired_signature_date',
        ),
    ),
);

$patched = dcb_ocr_apply_canonical_graph_patch($canonical, $patch);
$reviewer_patch = isset($patched['reviewer_patch']) && is_array($patched['reviewer_patch']) ? $patched['reviewer_patch'] : array();
$validation = isset($reviewer_patch['validation']) && is_array($reviewer_patch['validation']) ? $reviewer_patch['validation'] : array();

assert_true(isset($validation['accepted_counts']) && is_array($validation['accepted_counts']), 'validation metadata should include accepted counts');
assert_true(max(0, (int) ($validation['rejected_count'] ?? 0)) >= 2, 'validation should reject invalid relation rows');

$reason_codes = isset($validation['rejected_reason_codes']) && is_array($validation['rejected_reason_codes'])
    ? $validation['rejected_reason_codes']
    : array();
assert_true(in_array('relation_kind_not_allowed', $reason_codes, true), 'validation should reject unsupported relation kinds');
assert_true(in_array('cross_page_group_membership', $reason_codes, true) || in_array('cross_page_relation_not_allowed', $reason_codes, true), 'validation should reject cross-page inconsistencies');

assert_true(isset($reviewer_patch['applied_counts']) && is_array($reviewer_patch['applied_counts']), 'reviewer patch should include applied counts');
assert_true(max(0, (int) ($reviewer_patch['applied_counts']['total'] ?? 0)) >= 1, 'at least one valid patch operation should be applied');
assert_true(isset($patched['correction_provenance']) && is_array($patched['correction_provenance']) && !empty($patched['correction_provenance']), 'correction provenance should be retained');

echo "ocr_canonical_patch_validation_smoke:ok\n";
