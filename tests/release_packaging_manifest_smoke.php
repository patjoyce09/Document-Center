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

require_once dirname(__DIR__) . '/includes/helpers-release.php';

$manifest = dcb_release_build_manifest(array(
    'document-center-builder.php',
    'includes/class-admin.php',
    'tests/schema_smoke.php',
    '.github/workflows/ci.yml',
    'docs/setup-operations.md',
    'assets/js/digital-forms.js',
));

$include = (array) ($manifest['include'] ?? array());
$exclude = (array) ($manifest['exclude'] ?? array());

if (!in_array('document-center-builder.php', $include, true) || !in_array('assets/js/digital-forms.js', $include, true)) {
    fwrite(STDERR, "expected include paths missing\n");
    exit(1);
}
if (!in_array('tests/schema_smoke.php', $exclude, true) || !in_array('.github/workflows/ci.yml', $exclude, true)) {
    fwrite(STDERR, "expected exclude paths missing\n");
    exit(1);
}

echo "release_packaging_manifest_smoke:ok\n";
