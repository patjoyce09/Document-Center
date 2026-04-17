<?php

define('ABSPATH', __DIR__ . '/');

if (!function_exists('sanitize_key')) {
    function sanitize_key($key) { return strtolower(preg_replace('/[^a-zA-Z0-9_]/', '', (string) $key)); }
}
if (!function_exists('sanitize_text_field')) {
    function sanitize_text_field($text) { return trim((string) $text); }
}
if (!function_exists('apply_filters')) {
    function apply_filters($hook, $value) { return $value; }
}
if (!function_exists('wp_json_encode')) {
    function wp_json_encode($value, $flags = 0) { return json_encode($value, $flags); }
}

require_once dirname(__DIR__) . '/includes/helpers-ops.php';

$forms = array(
    'intake_form' => array(
        'label' => 'Intake',
        'fields' => array(
            array('key' => 'name', 'label' => 'Name', 'type' => 'text', 'required' => true),
        ),
    ),
);

$export = dcb_ops_export_forms_payload($forms, array('source' => 'smoke_test'));
if ((string) ($export['contract'] ?? '') !== 'dcb_forms_export' || empty($export['forms']['intake_form'])) {
    fwrite(STDERR, "export payload mismatch\n");
    exit(1);
}

$raw = json_encode($export);
$parsed = dcb_ops_parse_import_payload((string) $raw);
if (empty($parsed['ok'])) {
    fwrite(STDERR, "parse failed\n");
    exit(1);
}

$validated = dcb_ops_validate_and_prepare_import((array) ($parsed['payload'] ?? array()), array());
if (empty($validated['ok']) || (int) (($validated['stats']['imported'] ?? 0)) !== 1) {
    fwrite(STDERR, "validate mismatch\n");
    exit(1);
}

echo "forms_import_export_ops_smoke:ok\n";
