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
        'text' => "NOTICE OF PRIVACY PRACTICES\nPlease complete all required fields.\nPatient Name: __________\nDOB: ____/____/______\nDo you consent? Yes / No\nSignature: __________________\nDate: __________________",
        'text_length' => 210,
        'confidence_proxy' => 0.78,
    ),
    array(
        'page_number' => 2,
        'engine' => 'fixture',
        'text' => "SECOND PAGE NOTICE\nFor office use only\nChecklist [ ] Verified [ ] Pending\nPrinted Name: __________\nRelationship: __________",
        'text_length' => 150,
        'confidence_proxy' => 0.74,
    ),
);

$document_model = dcb_ocr_build_document_model($pages);
$page_meta = array(
    1 => array('page_number' => 1, 'engine' => 'fixture', 'confidence_proxy' => 0.78),
    2 => array('page_number' => 2, 'engine' => 'fixture', 'confidence_proxy' => 0.74),
);

$widgets = dcb_ocr_detect_field_widgets($document_model, $page_meta, array());
$page_graph = dcb_ocr_build_page_relation_graph($document_model, $widgets);
$scene_graph = dcb_ocr_build_scene_graph($document_model, $widgets, $page_graph, $page_meta);
$canonical_graph = dcb_ocr_build_canonical_form_graph($document_model, $widgets, $page_graph, $scene_graph, $page_meta, array());

assert_true(isset($page_graph['nodes']) && is_array($page_graph['nodes']) && count($page_graph['nodes']) > 0, 'page graph should contain nodes');
assert_true(isset($page_graph['edges']) && is_array($page_graph['edges']) && count($page_graph['edges']) > 0, 'page graph should contain edges');

$edge_relations = array();
foreach ($page_graph['edges'] as $edge) {
    if (!is_array($edge)) {
        continue;
    }
    $edge_relations[] = sanitize_key((string) ($edge['relation'] ?? ''));
}
assert_true(in_array('nearest_label', $edge_relations, true), 'page graph should link widgets to nearest labels');
assert_true(in_array('signature_date_pair', $edge_relations, true), 'page graph should include signature/date pairing');
assert_true(in_array('label_of', $edge_relations, true), 'page graph should include label_of relation');
assert_true(in_array('belongs_to_group', $edge_relations, true), 'page graph should include belongs_to_group relation');
assert_true(in_array('section_contains', $edge_relations, true), 'page graph should include section_contains relation');
assert_true(in_array('paired_signature_date', $edge_relations, true), 'page graph should include paired_signature_date relation');

assert_true(isset($scene_graph['pages']) && is_array($scene_graph['pages']) && count($scene_graph['pages']) === 2, 'scene graph should contain both pages');

$grouped_control_count = 0;
$approval_block_count = 0;
foreach ($scene_graph['pages'] as $page) {
    if (!is_array($page)) {
        continue;
    }
    $grouped_control_count += isset($page['grouped_controls']) && is_array($page['grouped_controls']) ? count($page['grouped_controls']) : 0;
    $approval_block_count += isset($page['approval_blocks']) && is_array($page['approval_blocks']) ? count($page['approval_blocks']) : 0;
}

assert_true($grouped_control_count >= 1, 'scene graph should contain grouped controls');
assert_true($approval_block_count >= 1, 'scene graph should contain approval/signature blocks');
assert_true(isset($canonical_graph['graph_kind']) && (string) $canonical_graph['graph_kind'] === 'canonical_form_graph', 'canonical graph should expose canonical form graph kind');
assert_true(isset($canonical_graph['pages']) && is_array($canonical_graph['pages']) && count($canonical_graph['pages']) === 2, 'canonical graph should preserve page count');
assert_true(isset($canonical_graph['relations']) && is_array($canonical_graph['relations']) && count($canonical_graph['relations']) > 0, 'canonical graph should include relations');

echo "ocr_page_scene_graph_smoke:ok\n";
