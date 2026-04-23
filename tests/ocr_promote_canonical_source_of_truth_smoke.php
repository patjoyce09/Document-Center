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
if (!function_exists('get_the_title')) {
    function get_the_title($post_id) { return 'Canonical Promote Smoke'; }
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
        'text' => "Patient Name: __________\nDOB: ____/____/______\nSignature: __________________\nDate: __________________",
        'text_length' => 160,
        'confidence_proxy' => 0.84,
    ),
);

$document_model = dcb_ocr_build_document_model($pages);
$page_meta = array(1 => array('page_number' => 1, 'engine' => 'fixture', 'confidence_proxy' => 0.84));
$widgets = dcb_ocr_detect_field_widgets($document_model, $page_meta, array());
$page_graph = dcb_ocr_build_page_relation_graph($document_model, $widgets);
$scene_graph = dcb_ocr_build_scene_graph($document_model, $widgets, $page_graph, $page_meta);
$canonical = dcb_ocr_build_canonical_form_graph($document_model, $widgets, $page_graph, $scene_graph, $page_meta, array());

$review_id = 5199;
$extraction = array(
    'pages' => $pages,
    'text' => implode("\n", array_map(static function ($row) { return (string) ($row['text'] ?? ''); }, $pages)),
    'ocr_document_model' => $document_model,
    'ocr_widget_candidates' => $widgets,
    'ocr_page_graph' => $page_graph,
    'ocr_scene_graph' => $scene_graph,
    'ocr_canonical_form_graph' => $canonical,
);
update_post_meta($review_id, '_dcb_ocr_review_extraction', wp_json_encode($extraction));

$corrections = array(
    'candidate_fields' => array(
        array(
            'field_label' => 'Noise Header Override',
            'suggested_key' => 'noise_header_override',
            'suggested_type' => 'text',
            'decision' => 'accept',
            'confidence_bucket' => 'high',
        ),
    ),
);
update_post_meta($review_id, '_dcb_ocr_review_corrections', wp_json_encode($corrections));

$promoted = dcb_ocr_review_promote_builder_draft($review_id);

assert_true(isset($promoted['ocr_review']['canonical_graph_source_of_truth']['enabled']) && !empty($promoted['ocr_review']['canonical_graph_source_of_truth']['enabled']), 'promoted draft should mark canonical graph as source of truth');
assert_true(isset($promoted['fields']) && is_array($promoted['fields']) && count($promoted['fields']) > 1, 'promoted draft should project canonical fields even after candidate-level review rows');
assert_true(isset($promoted['grouped_controls']) && is_array($promoted['grouped_controls']), 'promoted draft should expose grouped controls from canonical propagation');
assert_true(isset($promoted['approval_blocks']) && is_array($promoted['approval_blocks']), 'promoted draft should expose approval blocks from canonical propagation');
assert_true(isset($promoted['hard_stops']) && is_array($promoted['hard_stops']), 'promoted draft should expose semantic hard-stop rules from canonical propagation');

$has_canonical_projected_field = false;
foreach ((array) ($promoted['fields'] ?? array()) as $field_row) {
    if (!is_array($field_row)) {
        continue;
    }
    $meta = isset($field_row['ocr_meta']) && is_array($field_row['ocr_meta']) ? $field_row['ocr_meta'] : array();
    if (!empty($meta['canonical_graph_applied'])) {
        $has_canonical_projected_field = true;
        break;
    }
}
assert_true($has_canonical_projected_field, 'promoted draft fields should include canonical-projected metadata');

echo "ocr_promote_canonical_source_of_truth_smoke:ok\n";
