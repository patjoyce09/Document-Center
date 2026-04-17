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

$plugin_php = "<?php\n/**\n * Version: 0.3.9\n */\n";
$readme = "Stable tag: 0.3.9\n== Changelog ==\n= 0.3.9 =\n* Notes\n";

$payload = dcb_release_version_consistency_payload(array(
    'plugin_php' => $plugin_php,
    'readme' => $readme,
));

if (empty($payload['ok'])) {
    fwrite(STDERR, "version consistency should pass\n");
    exit(1);
}

$bad = dcb_release_version_consistency_payload(array(
    'plugin_php' => str_replace('0.3.9', '0.3.8', $plugin_php),
    'readme' => $readme,
));
if (!empty($bad['ok'])) {
    fwrite(STDERR, "version consistency should fail on mismatch\n");
    exit(1);
}

echo "release_version_consistency_smoke:ok\n";
