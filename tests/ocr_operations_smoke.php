<?php

define('ABSPATH', __DIR__ . '/');

$GLOBALS['dcb_test_options'] = array(
    'dcb_ocr_mode' => 'remote',
    'dcb_ocr_api_base_url' => 'https://ocr.example.test',
    'dcb_ocr_api_key' => 'secret-key',
    'dcb_ocr_timeout_seconds' => 15,
    'dcb_ocr_max_file_size_mb' => 15,
    'dcb_ocr_confidence_threshold' => 0.45,
);
$GLOBALS['dcb_test_meta'] = array();
$GLOBALS['dcb_test_posts'] = array();
$GLOBALS['dcb_test_next_post_id'] = 2000;
$GLOBALS['dcb_mock_remote_response'] = array('status' => 200, 'body' => '{"text":"Name: Jane","engine":"remote-api","pages":[{"page_number":1,"text":"Name: Jane","confidence_proxy":0.81}],"warnings":[]}');
$GLOBALS['mock_current_user_caps'] = array();
$GLOBALS['mock_redirect_url'] = '';

class DCB_Test_Stop extends Exception {}

if (!class_exists('WP_Error')) {
    class WP_Error {
        private string $message;
        public function __construct(string $message = 'error') { $this->message = $message; }
        public function get_error_message() { return $this->message; }
    }
}
if (!class_exists('WP_User')) {
    class WP_User {
        public int $ID = 7;
        public string $display_name = 'OCR Reviewer';
    }
}
if (!class_exists('WP_Post')) {
    class WP_Post {
        public int $ID = 0;
        public string $post_type = 'dcb_ocr_review_queue';
        public string $post_title = 'OCR Review';
        public string $post_modified = '2026-04-16 00:00:00';
    }
}

if (!function_exists('__')) {
    function __($text) { return $text; }
}
if (!function_exists('add_action')) {
    function add_action() {}
}
if (!function_exists('add_filter')) {
    function add_filter() {}
}
if (!function_exists('add_meta_box')) {
    function add_meta_box() {}
}
if (!function_exists('wp_nonce_url')) {
    function wp_nonce_url($url) { return (string) $url; }
}
if (!function_exists('check_admin_referer')) {
    function check_admin_referer() { return true; }
}
if (!function_exists('wp_verify_nonce')) {
    function wp_verify_nonce() { return true; }
}
if (!function_exists('sanitize_key')) {
    function sanitize_key($key) { return strtolower(preg_replace('/[^a-zA-Z0-9_]/', '', (string) $key)); }
}
if (!function_exists('sanitize_text_field')) {
    function sanitize_text_field($text) { return trim((string) $text); }
}
if (!function_exists('sanitize_textarea_field')) {
    function sanitize_textarea_field($text) { return trim((string) $text); }
}
if (!function_exists('sanitize_file_name')) {
    function sanitize_file_name($name) { return preg_replace('/[^A-Za-z0-9._-]/', '-', (string) $name); }
}
if (!function_exists('esc_url_raw')) {
    function esc_url_raw($url) { return (string) $url; }
}
if (!function_exists('trailingslashit')) {
    function trailingslashit($v) { return rtrim((string) $v, '/') . '/'; }
}
if (!function_exists('wp_parse_url')) {
    function wp_parse_url($url) { return parse_url((string) $url); }
}
if (!function_exists('wp_json_encode')) {
    function wp_json_encode($value, $flags = 0) { return json_encode($value, $flags); }
}
if (!function_exists('wp_generate_password')) {
    function wp_generate_password($len = 12) { return substr(str_repeat('a', $len), 0, $len); }
}
if (!function_exists('wp_mkdir_p')) {
    function wp_mkdir_p($dir) { return @mkdir($dir, 0777, true) || is_dir($dir); }
}
if (!function_exists('wp_strip_all_tags')) {
    function wp_strip_all_tags($text) { return strip_tags((string) $text); }
}
if (!function_exists('current_time')) {
    function current_time() { return '2026-04-16 00:00:00'; }
}
if (!function_exists('wp_get_current_user')) {
    function wp_get_current_user() { return new WP_User(); }
}
if (!function_exists('get_current_user_id')) {
    function get_current_user_id() { return 7; }
}
if (!function_exists('current_user_can')) {
    function current_user_can($cap) { return !empty($GLOBALS['mock_current_user_caps'][(string) $cap]); }
}
if (!function_exists('do_action')) {
    function do_action() {}
}
if (!function_exists('apply_filters')) {
    function apply_filters($hook, $value) { return $value; }
}
if (!function_exists('get_option')) {
    function get_option($key, $default = null) {
        return array_key_exists((string) $key, $GLOBALS['dcb_test_options']) ? $GLOBALS['dcb_test_options'][(string) $key] : $default;
    }
}
if (!function_exists('update_option')) {
    function update_option($key, $value) { $GLOBALS['dcb_test_options'][(string) $key] = $value; return true; }
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
    function update_post_meta($post_id, $key, $value) { $GLOBALS['dcb_test_meta'][(int) $post_id][(string) $key] = $value; return true; }
}
if (!function_exists('wp_insert_post')) {
    function wp_insert_post($postarr) {
        $id = ++$GLOBALS['dcb_test_next_post_id'];
        $GLOBALS['dcb_test_posts'][$id] = (array) $postarr;
        return $id;
    }
}
if (!function_exists('is_wp_error')) {
    function is_wp_error($value) { return $value instanceof WP_Error; }
}
if (!function_exists('get_posts')) {
    function get_posts($args = array()) {
        $ids = array();
        foreach ($GLOBALS['dcb_test_posts'] as $id => $post) {
            if (($post['post_type'] ?? '') === 'dcb_ocr_review_queue') {
                $ids[] = (int) $id;
            }
        }
        return $ids;
    }
}
if (!function_exists('get_the_title')) {
    function get_the_title($post_id) {
        $post = $GLOBALS['dcb_test_posts'][(int) $post_id] ?? array();
        return (string) ($post['post_title'] ?? 'OCR Review');
    }
}
if (!function_exists('wp_remote_post')) {
    function wp_remote_post() {
        $mock = $GLOBALS['dcb_mock_remote_response'];
        if (!empty($mock['wp_error'])) {
            return new WP_Error((string) ($mock['message'] ?? 'request failed'));
        }
        return array('status' => (int) ($mock['status'] ?? 200), 'body' => (string) ($mock['body'] ?? '{}'));
    }
}
if (!function_exists('wp_remote_retrieve_response_code')) {
    function wp_remote_retrieve_response_code($response) { return (int) ($response['status'] ?? 0); }
}
if (!function_exists('wp_remote_retrieve_body')) {
    function wp_remote_retrieve_body($response) { return (string) ($response['body'] ?? ''); }
}
if (!function_exists('admin_url')) {
    function admin_url($path = '') { return 'https://example.test/wp-admin/' . ltrim((string) $path, '/'); }
}
if (!function_exists('wp_safe_redirect')) {
    function wp_safe_redirect($url) { $GLOBALS['mock_redirect_url'] = (string) $url; return true; }
}
if (!function_exists('wp_die')) {
    function wp_die($message = 'wp_die') { throw new DCB_Test_Stop((string) $message); }
}
if (!function_exists('wp_unslash')) {
    function wp_unslash($value) { return $value; }
}

require_once dirname(__DIR__) . '/includes/class-permissions.php';
require_once dirname(__DIR__) . '/includes/helpers-ocr.php';
require_once dirname(__DIR__) . '/includes/class-ocr-engine.php';
require_once dirname(__DIR__) . '/includes/class-ocr.php';

function assert_true($ok, $message): void {
    if (!$ok) {
        fwrite(STDERR, "Assertion failed: {$message}\n");
        exit(1);
    }
}

// A) Failure taxonomy normalization.
assert_true(dcb_ocr_normalize_failure_reason('tesseract_missing') === 'local_binary_missing', 'failure aliases should normalize');
assert_true(dcb_ocr_normalize_failure_reason('unknown_new_code') === 'unknown', 'unknown failure should map to unknown taxonomy');

// B) Remote normalized result shape.
$remote = (new DCB_OCR_Engine_Remote())->extract(__FILE__, 'text/plain');
assert_true(isset($remote['provider']) && $remote['provider'] === 'remote', 'remote provider should be preserved');
assert_true(isset($remote['pages']) && is_array($remote['pages']), 'remote pages should be normalized array');
assert_true(isset($remote['confidence_bucket']), 'remote result should include confidence bucket');

// C) Local normalized result shape.
$local = dcb_upload_extract_text_from_file_local(__FILE__, 'text/plain');
assert_true(isset($local['provider']) && $local['provider'] === 'local', 'local result should include local provider');
assert_true(isset($local['warnings']) && is_array($local['warnings']), 'local warnings should be array');

// D) OCR review item lifecycle create/update/correct/reprocess.
$review_id = dcb_ocr_maybe_enqueue_review_item(__FILE__, 'text/plain', array(
    'text' => 'short text',
    'pages' => array(array('page_number' => 1, 'text' => 'short text', 'confidence_proxy' => 0.20)),
    'provider' => 'local',
    'mode' => 'local',
    'engine' => 'native-text',
    'failure_reason' => 'low_confidence',
    'provenance' => array('mode' => 'local'),
));
assert_true($review_id > 0, 'review item should be created for low confidence payload');
assert_true((string) get_post_meta($review_id, '_dcb_ocr_review_status', true) === 'pending_review', 'new review item status should default pending_review');

assert_true(dcb_ocr_review_update_status($review_id, 'approved', 'Reviewed') === true, 'review status update should succeed');
assert_true((string) get_post_meta($review_id, '_dcb_ocr_review_status', true) === 'approved', 'status should persist as approved');

dcb_ocr_review_apply_manual_corrections($review_id, array(
    'text_summary' => 'Corrected summary',
    'candidate_fields' => array(array('field_label' => 'Patient Name', 'suggested_key' => 'patient_name', 'decision' => 'accept')),
));
assert_true((string) get_post_meta($review_id, '_dcb_ocr_review_status', true) === 'corrected', 'manual correction should mark item corrected');

$reprocess = dcb_ocr_review_reprocess($review_id, 'remote');
assert_true(!empty($reprocess['ok']), 'reprocess should succeed when source file exists');
assert_true((string) get_post_meta($review_id, '_dcb_ocr_review_status', true) === 'reprocessed', 'reprocess should mark item as reprocessed');

// E) Capability enforcement on OCR admin actions.
$GLOBALS['mock_current_user_caps'] = array();
$_GET = array('review_id' => $review_id, 'task' => 'approve', 'dcb_ocr_review_nonce' => 'ok');
$threw = false;
try {
    DCB_OCR::handle_review_action();
} catch (DCB_Test_Stop $e) {
    $threw = true;
}
assert_true($threw, 'OCR review action should enforce dcb_run_ocr_tools capability');

echo "ocr_operations_smoke:ok\n";
