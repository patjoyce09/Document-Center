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
        'text' => "Patient Name: __________\nDOB: ____/____/______\nSelect one: Yes / No\nSignature: __________________\nDate: __________________\nItem   Qty   Description\nService A   1   Follow-up",
        'text_length' => 260,
        'confidence_proxy' => 0.84,
    ),
);

$document_model = dcb_ocr_build_document_model($pages);
$page_meta = array(1 => array('page_number' => 1, 'engine' => 'fixture', 'confidence_proxy' => 0.84));
$widgets = dcb_ocr_detect_field_widgets($document_model, $page_meta, array());
$page_graph = dcb_ocr_build_page_relation_graph($document_model, $widgets);
$scene_graph = dcb_ocr_build_scene_graph($document_model, $widgets, $page_graph, $page_meta);
$canonical = dcb_ocr_build_canonical_form_graph($document_model, $widgets, $page_graph, $scene_graph, $page_meta, array());

$anchors = isset($canonical['semantic_hard_stop_anchors']) && is_array($canonical['semantic_hard_stop_anchors'])
    ? $canonical['semantic_hard_stop_anchors']
    : array();
$targets = isset($canonical['semantic_hard_stop_targets']) && is_array($canonical['semantic_hard_stop_targets'])
    ? $canonical['semantic_hard_stop_targets']
    : array();

assert_true(!empty($anchors), 'canonical should expose semantic hard-stop anchors');
assert_true(isset($anchors['anchor_schema_version']) && (string) $anchors['anchor_schema_version'] === '1.0', 'anchor schema version should be present');
assert_true(isset($anchors['approval_blocks']) && is_array($anchors['approval_blocks']), 'approval block anchors should be present');
assert_true(isset($anchors['signature_date_pairs']) && is_array($anchors['signature_date_pairs']), 'signature/date pair anchors should be present');
assert_true(isset($anchors['control_groups']) && is_array($anchors['control_groups']), 'control group anchors should be present');
assert_true(isset($anchors['demographic_blocks']) && is_array($anchors['demographic_blocks']), 'demographic block anchors should be present');
assert_true(isset($anchors['repeater_groups']) && is_array($anchors['repeater_groups']), 'repeater/table anchors should be present');
assert_true(isset($anchors['sparse_form_critical_field_set']) && is_array($anchors['sparse_form_critical_field_set']), 'sparse critical field set should be present');

assert_true(count((array) ($anchors['approval_blocks'] ?? array())) >= 1, 'approval block anchors should include at least one block');
assert_true(count((array) ($anchors['control_groups'] ?? array())) >= 1, 'control group anchors should include at least one group');
assert_true(count((array) ($anchors['demographic_blocks'] ?? array())) >= 1, 'demographic anchors should include at least one region');
assert_true(!empty($targets), 'canonical should expose semantic hard-stop targets');

$target_types = array();
foreach ($targets as $target_row) {
    if (!is_array($target_row)) {
        continue;
    }
    $target_types[] = sanitize_key((string) ($target_row['rule_type'] ?? ''));
}
assert_true(in_array('approval_block_incomplete', $target_types, true), 'hard-stop targets should include approval block rules');
assert_true(in_array('demographic_block_incomplete', $target_types, true), 'hard-stop targets should include demographic rules');

$draft = dcb_ocr_to_draft_form('', 'Semantic Anchor Form', array(
    'pages' => $pages,
    'ocr_document_model' => $document_model,
    'ocr_widget_candidates' => $widgets,
    'ocr_page_graph' => $page_graph,
    'ocr_scene_graph' => $scene_graph,
    'ocr_canonical_form_graph' => $canonical,
));

assert_true(isset($draft['hard_stops']) && is_array($draft['hard_stops']) && !empty($draft['hard_stops']), 'draft hard_stops should be generated from semantic targets');
assert_true(isset($draft['hard_stop_targets']) && is_array($draft['hard_stop_targets']) && !empty($draft['hard_stop_targets']), 'draft should include semantic hard-stop targets');
assert_true(isset($draft['hard_stop_anchors']) && is_array($draft['hard_stop_anchors']) && !empty($draft['hard_stop_anchors']), 'draft should include semantic hard-stop anchors');

$hard_stop_types = array();
 $semantic_payload_count = 0;
foreach ((array) $draft['hard_stops'] as $rule_row) {
    if (!is_array($rule_row)) {
        continue;
    }
    $hard_stop_types[] = sanitize_key((string) ($rule_row['type'] ?? ''));
    if (!empty($rule_row['semantic_target']) && is_array($rule_row['semantic_target'])) {
        $semantic_payload_count++;
    }
}
assert_true(!empty($hard_stop_types), 'generated hard-stop rules should include semantic rule types');
assert_true($semantic_payload_count > 0, 'generated hard-stop rules should retain semantic target payload metadata');

echo "ocr_semantic_hard_stop_anchors_smoke:ok\n";
