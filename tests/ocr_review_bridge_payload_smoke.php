<?php

define('ABSPATH', __DIR__ . '/');

$GLOBALS['dcb_test_meta'] = array();

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
if (!function_exists('wp_json_encode')) {
    function wp_json_encode($value, $flags = 0) { return json_encode($value, $flags); }
}
if (!function_exists('apply_filters')) {
    function apply_filters($hook, $value) { return $value; }
}
if (!function_exists('do_action')) {
    function do_action() {}
}
if (!function_exists('get_option')) {
    function get_option($key, $default = null) { return $default; }
}
if (!function_exists('update_option')) {
    function update_option($key, $value) { return true; }
}
if (!function_exists('get_post_meta')) {
    function get_post_meta($post_id, $key, $single = false) {
        $value = $GLOBALS['dcb_test_meta'][(int) $post_id][(string) $key] ?? null;
        if ($single) {
            return $value;
        }
        return $value === null ? array() : array($value);
    }
}
if (!function_exists('update_post_meta')) {
    function update_post_meta($post_id, $key, $value) {
        $GLOBALS['dcb_test_meta'][(int) $post_id][(string) $key] = $value;
        return true;
    }
}
if (!function_exists('mb_substr')) {
    function mb_substr($text, $start, $length = null) {
        return $length === null ? substr((string) $text, (int) $start) : substr((string) $text, (int) $start, (int) $length);
    }
}
if (!function_exists('wp_strip_all_tags')) {
    function wp_strip_all_tags($text) { return strip_tags((string) $text); }
}
if (!function_exists('wp_generate_password')) {
    function wp_generate_password($len = 12) { return substr(str_repeat('a', $len), 0, $len); }
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
        'text' => "Patient Name: __________\nDOB: ____/____/______\nSelect one: Yes / No\nSignature: __________________\nDate: __________________",
        'text_length' => 220,
        'confidence_proxy' => 0.83,
    ),
);

$document_model = dcb_ocr_build_document_model($pages);
$page_meta = array(1 => array('page_number' => 1, 'engine' => 'fixture', 'confidence_proxy' => 0.83));
$widgets = dcb_ocr_detect_field_widgets($document_model, $page_meta, array());
$page_graph = dcb_ocr_build_page_relation_graph($document_model, $widgets);
$scene_graph = dcb_ocr_build_scene_graph($document_model, $widgets, $page_graph, $page_meta);
$canonical = dcb_ocr_build_canonical_form_graph($document_model, $widgets, $page_graph, $scene_graph, $page_meta, array());

$review_id = 4242;
$extraction = array(
    'pages' => $pages,
    'text' => implode("\n", array_map(static function ($row) { return (string) ($row['text'] ?? ''); }, $pages)),
    'ocr_document_model' => $document_model,
    'ocr_widget_candidates' => $widgets,
    'ocr_page_graph' => $page_graph,
    'ocr_scene_graph' => $scene_graph,
    'ocr_canonical_form_graph' => $canonical,
    'quality_metrics' => array(
        'false_positive_risk_count' => 2,
        'review_cleanup_burden_proxy' => 0.5,
    ),
);
update_post_meta($review_id, '_dcb_ocr_review_extraction', wp_json_encode($extraction));

$first_widget_stable = '';
foreach ((array) ($canonical['pages'] ?? array()) as $page_row) {
    if (!is_array($page_row)) {
        continue;
    }
    foreach ((array) ($page_row['widgets'] ?? array()) as $widget_row) {
        if (!is_array($widget_row)) {
            continue;
        }
        $sid = sanitize_key((string) ($widget_row['stable_id'] ?? ''));
        if ($sid !== '') {
            $first_widget_stable = $sid;
            break 2;
        }
    }
}
assert_true($first_widget_stable !== '', 'fixture should provide at least one canonical widget stable id');

$preview = dcb_ocr_review_patch_bridge($review_id, array(
    'stable_ids' => array($first_widget_stable),
    'validate_only' => true,
));
assert_true(!empty($preview['ok']), 'review patch bridge preview should succeed');
assert_true(!empty($preview['entity_count']), 'review patch bridge should return canonical entities');
assert_true(isset($preview['structural_kpis']['baseline']) && is_array($preview['structural_kpis']['baseline']), 'preview should include baseline structural KPIs');
assert_true(isset($preview['structural_kpis']['patched']) && is_array($preview['structural_kpis']['patched']), 'preview should include patched structural KPIs');
assert_true(isset($preview['structural_kpis']['delta']) && is_array($preview['structural_kpis']['delta']), 'preview should include KPI deltas');

$apply = dcb_ocr_review_patch_bridge($review_id, array(
    'stable_ids' => array($first_widget_stable),
    'apply_patch' => true,
    'persist' => true,
    'canonical_graph_patch' => array(
        'patch_version' => '1.0',
        'patch_id' => 'smoke_patch_bridge',
        'widgets' => array(
            array(
                'stable_id' => $first_widget_stable,
                'classification' => 'fillable',
                'label_text' => 'Updated by review bridge smoke',
            ),
        ),
    ),
));

assert_true(!empty($apply['ok']), 'review patch bridge apply should succeed');
assert_true(!empty($apply['patch_applied']), 'review patch bridge should apply accepted patch');
assert_true(!empty($apply['patch_persisted']), 'review patch bridge should persist accepted patch');
assert_true(isset($apply['validation']['accepted']), 'review patch bridge should return validation payload');
assert_true(isset($apply['structural_kpis']['delta']) && is_array($apply['structural_kpis']['delta']), 'review patch bridge apply should return KPI deltas');

$saved_patch_raw = (string) get_post_meta($review_id, '_dcb_ocr_review_canonical_graph_patch', true);
assert_true($saved_patch_raw !== '', 'accepted patch should be persisted');

$updated_extraction_raw = (string) get_post_meta($review_id, '_dcb_ocr_review_extraction', true);
$updated_extraction = json_decode($updated_extraction_raw, true);
assert_true(is_array($updated_extraction), 'updated extraction should remain JSON object');
assert_true(isset($updated_extraction['ocr_canonical_form_graph']) && is_array($updated_extraction['ocr_canonical_form_graph']), 'updated extraction should include canonical graph');

$draft = dcb_ocr_to_draft_form((string) ($updated_extraction['text'] ?? ''), 'Bridge Smoke Draft', $updated_extraction);
assert_true(isset($draft['digital_twin_hints']['canonical_graph_applied']) && !empty($draft['digital_twin_hints']['canonical_graph_applied']), 'draft should mark canonical graph as applied in hints');
assert_true(isset($draft['grouped_controls']) && is_array($draft['grouped_controls']), 'draft should expose grouped controls from canonical graph');
assert_true(isset($draft['approval_blocks']) && is_array($draft['approval_blocks']), 'draft should expose approval blocks from canonical graph');
assert_true(isset($draft['hard_stops']) && is_array($draft['hard_stops']) && !empty($draft['hard_stops']), 'draft should include generated semantic hard-stop rules');

echo "ocr_review_bridge_payload_smoke:ok\n";
