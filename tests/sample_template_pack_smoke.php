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

require_once dirname(__DIR__) . '/includes/helpers-ops.php';

$pack = dcb_ops_sample_template_pack();
if (!isset($pack['generic_intake_form'], $pack['consent_attestation_form'], $pack['simple_document_packet'])) {
    fwrite(STDERR, "sample pack keys missing\n");
    exit(1);
}
if (count((array) ($pack['generic_intake_form']['fields'] ?? array())) < 3) {
    fwrite(STDERR, "intake sample too small\n");
    exit(1);
}

$validated = dcb_ops_validate_and_prepare_import(array('forms' => $pack), array());
if (empty($validated['ok']) || (int) (($validated['stats']['imported'] ?? 0)) !== 3) {
    fwrite(STDERR, "sample pack import validation mismatch\n");
    exit(1);
}

echo "sample_template_pack_smoke:ok\n";
