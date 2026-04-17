<?php

define('ABSPATH', __DIR__ . '/');

$GLOBALS['mock_current_user_caps'] = array();
$GLOBALS['mock_is_logged_in'] = true;
$GLOBALS['mock_user_meta'] = array();
$GLOBALS['mock_post_meta'] = array();
$GLOBALS['mock_roles'] = array();
$GLOBALS['mock_last_json'] = array();

class DCB_Test_Stop extends Exception {
    public array $payload;
    public int $statusCode;

    public function __construct(string $message, array $payload = array(), int $statusCode = 200) {
        parent::__construct($message);
        $this->payload = $payload;
        $this->statusCode = $statusCode;
    }
}

if (!class_exists('WP_Role')) {
    class WP_Role {
        public array $caps = array();

        public function add_cap(string $cap): void {
            $this->caps[$cap] = true;
        }
    }
}

if (!class_exists('WP_User')) {
    class WP_User {
        public int $ID = 77;
        public string $display_name = 'Portal User';
        public string $user_email = 'portal@example.com';
    }
}

if (!class_exists('WP_Post')) {
    class WP_Post {
        public int $ID = 1;
        public string $post_type = 'dcb_form_submission';
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
if (!function_exists('apply_filters')) {
    function apply_filters($hook, $value) { return $value; }
}
if (!function_exists('do_action')) {
    function do_action() {}
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
if (!function_exists('wp_unslash')) {
    function wp_unslash($value) { return $value; }
}
if (!function_exists('wp_json_encode')) {
    function wp_json_encode($value, $flags = 0) { return json_encode($value, $flags); }
}
if (!function_exists('wp_salt')) {
    function wp_salt() { return 'test-salt'; }
}
if (!function_exists('current_user_can')) {
    function current_user_can($cap) { return !empty($GLOBALS['mock_current_user_caps'][(string) $cap]); }
}
if (!function_exists('is_user_logged_in')) {
    function is_user_logged_in() { return !empty($GLOBALS['mock_is_logged_in']); }
}
if (!function_exists('get_current_user_id')) {
    function get_current_user_id() { return 77; }
}
if (!function_exists('wp_get_current_user')) {
    function wp_get_current_user() { return new WP_User(); }
}
if (!function_exists('check_ajax_referer')) {
    function check_ajax_referer() { return true; }
}
if (!function_exists('check_admin_referer')) {
    function check_admin_referer() { return true; }
}
if (!function_exists('wp_die')) {
    function wp_die($message = 'wp_die') { throw new DCB_Test_Stop((string) $message); }
}
if (!function_exists('wp_send_json_success')) {
    function wp_send_json_success($data = array(), $status = 200) {
        $GLOBALS['mock_last_json'] = array('success' => true, 'data' => $data, 'status' => $status);
        throw new DCB_Test_Stop('json_success', is_array($data) ? $data : array('value' => $data), (int) $status);
    }
}
if (!function_exists('wp_send_json_error')) {
    function wp_send_json_error($data = array(), $status = 400) {
        $GLOBALS['mock_last_json'] = array('success' => false, 'data' => $data, 'status' => $status);
        throw new DCB_Test_Stop('json_error', is_array($data) ? $data : array('value' => $data), (int) $status);
    }
}
if (!function_exists('current_time')) {
    function current_time() { return '2026-04-16 00:00:00'; }
}
if (!function_exists('get_option')) {
    function get_option($key, $default = null) { return $default; }
}
if (!function_exists('update_option')) {
    function update_option() { return true; }
}
if (!function_exists('add_option')) {
    function add_option() { return true; }
}
if (!function_exists('get_role')) {
    function get_role($role_name) { return $GLOBALS['mock_roles'][(string) $role_name] ?? null; }
}
if (!function_exists('update_user_meta')) {
    function update_user_meta($user_id, $key, $value) {
        $GLOBALS['mock_user_meta'][(int) $user_id][(string) $key] = $value;
        return true;
    }
}
if (!function_exists('get_user_meta')) {
    function get_user_meta($user_id, $key, $single = false) {
        $value = $GLOBALS['mock_user_meta'][(int) $user_id][(string) $key] ?? null;
        if ($single) {
            return $value;
        }
        return $value === null ? array() : array($value);
    }
}
if (!function_exists('delete_user_meta')) {
    function delete_user_meta($user_id, $key) {
        unset($GLOBALS['mock_user_meta'][(int) $user_id][(string) $key]);
        return true;
    }
}
if (!function_exists('wp_insert_post')) {
    function wp_insert_post() { return 901; }
}
if (!function_exists('is_wp_error')) {
    function is_wp_error() { return false; }
}
if (!function_exists('update_post_meta')) {
    function update_post_meta($post_id, $key, $value) {
        $GLOBALS['mock_post_meta'][(int) $post_id][(string) $key] = $value;
        return true;
    }
}
if (!function_exists('get_post_meta')) {
    function get_post_meta($post_id, $key, $single = false) {
        $value = $GLOBALS['mock_post_meta'][(int) $post_id][(string) $key] ?? null;
        if ($single) {
            return $value;
        }
        return $value === null ? array() : array($value);
    }
}
if (!function_exists('admin_url')) {
    function admin_url($path = '') { return 'https://example.local/wp-admin/' . ltrim((string) $path, '/'); }
}
if (!function_exists('wp_safe_redirect')) {
    function wp_safe_redirect() { return true; }
}
if (!function_exists('add_query_arg')) {
    function add_query_arg($args, $url) {
        return $url . '?' . http_build_query((array) $args);
    }
}
if (!function_exists('dcb_get_custom_forms')) {
    function dcb_get_custom_forms() { return array(); }
}
if (!function_exists('dcb_ocr_collect_environment_diagnostics')) {
    function dcb_ocr_collect_environment_diagnostics($include_provider_diagnostics = true) { return array('status' => 'missing'); }
}
if (!function_exists('dcb_upload_ocr_debug_log_recent')) {
    function dcb_upload_ocr_debug_log_recent() { return array(); }
}
if (!function_exists('dcb_ocr_smoke_validation')) {
    function dcb_ocr_smoke_validation() { return array('plain_text_pdf_path' => array('ok' => false)); }
}
if (!function_exists('dcb_get_policy_versions')) {
    function dcb_get_policy_versions() {
        return array('consent_text_version' => 'consent-default', 'attestation_text_version' => 'attestation-default');
    }
}
if (!function_exists('dcb_validate_submission')) {
    function dcb_validate_submission($form_key, $raw_fields) {
        return array(
            'ok' => true,
            'clean' => is_array($raw_fields) ? $raw_fields : array(),
            'form' => array('label' => 'Test Form', 'version' => 1),
        );
    }
}
if (!function_exists('dcb_signature_data_is_valid')) {
    function dcb_signature_data_is_valid() { return true; }
}
if (!function_exists('dcb_finalize_submission_output')) {
    function dcb_finalize_submission_output() { return true; }
}
if (!function_exists('dcb_send_submission_notification')) {
    function dcb_send_submission_notification() { return true; }
}

require_once dirname(__DIR__) . '/includes/class-permissions.php';
require_once dirname(__DIR__) . '/includes/class-builder.php';
require_once dirname(__DIR__) . '/includes/class-diagnostics.php';
require_once dirname(__DIR__) . '/includes/class-ocr.php';
require_once dirname(__DIR__) . '/includes/class-workflow.php';
require_once dirname(__DIR__) . '/includes/class-submissions.php';

function assert_true($condition, $message): void {
    if (!$condition) {
        fwrite(STDERR, "Assertion failed: {$message}\n");
        exit(1);
    }
}

function assert_throws(callable $fn, string $message): void {
    try {
        $fn();
    } catch (DCB_Test_Stop $e) {
        return;
    }

    fwrite(STDERR, "Expected exception not thrown: {$message}\n");
    exit(1);
}

$GLOBALS['mock_current_user_caps'] = array('read' => true);
assert_throws(static function () { DCB_Builder::render_page(); }, 'unauthorized user cannot open builder page');
assert_throws(static function () { DCB_Builder::ocr_seed_extract_ajax(); }, 'unauthorized user cannot run builder OCR extraction');
assert_throws(static function () { DCB_Diagnostics::render_settings_page(); }, 'unauthorized user cannot open settings page');
assert_throws(static function () { DCB_OCR::render_diagnostics_page(); }, 'unauthorized user cannot open ocr diagnostics page');
$_GET = array('review_id' => 1, 'task' => 'approve');
assert_throws(static function () { DCB_OCR::handle_review_action(); }, 'unauthorized user cannot execute OCR review action');
assert_throws(static function () { DCB_Workflow::handle_transition(); }, 'unauthorized user cannot run workflow transition action');

$GLOBALS['mock_current_user_caps'] = array(DCB_Permissions::CAP_MANAGE_FORMS => true);
assert_throws(static function () { DCB_Builder::ocr_seed_extract_ajax(); }, 'builder OCR extraction requires OCR tools capability');

$GLOBALS['mock_current_user_caps'] = array('manage_options' => true);
foreach (DCB_Permissions::all_caps() as $cap) {
    assert_true(DCB_Permissions::can($cap), 'administrator should pass cap ' . $cap);
}

$GLOBALS['mock_roles'] = array(
    'administrator' => new WP_Role(),
    'editor' => new WP_Role(),
);
DCB_Permissions::activate();

foreach (DCB_Permissions::all_caps() as $cap) {
    assert_true(!empty($GLOBALS['mock_roles']['administrator']->caps[$cap]), 'administrator role should receive cap ' . $cap);
}
assert_true(!empty($GLOBALS['mock_roles']['editor']->caps[DCB_Permissions::CAP_REVIEW_SUBMISSIONS]), 'editor should receive review cap');
assert_true(!empty($GLOBALS['mock_roles']['editor']->caps[DCB_Permissions::CAP_MANAGE_WORKFLOWS]), 'editor should receive workflow cap');
assert_true(empty($GLOBALS['mock_roles']['editor']->caps[DCB_Permissions::CAP_MANAGE_SETTINGS]), 'editor should not receive settings cap by default');
assert_true(empty($GLOBALS['mock_roles']['editor']->caps[DCB_Permissions::CAP_RUN_OCR_TOOLS]), 'editor should not receive ocr tools cap by default');
assert_true(empty($GLOBALS['mock_roles']['editor']->caps[DCB_Permissions::CAP_MANAGE_FORMS]), 'editor should not receive builder cap by default');

$GLOBALS['mock_current_user_caps'] = array('read' => true);
$GLOBALS['mock_is_logged_in'] = true;

$_POST = array(
    'nonce' => 'ok',
    'form_key' => 'intake_form',
    'fields' => wp_json_encode(array('first_name' => 'Alex')),
);
try {
    DCB_Submissions::save_draft_ajax();
} catch (DCB_Test_Stop $e) {
    assert_true($e->getMessage() === 'json_success', 'save draft should succeed for normal logged-in user');
}

$_POST = array(
    'nonce' => 'ok',
    'form_key' => 'intake_form',
);
try {
    DCB_Submissions::get_draft_ajax();
} catch (DCB_Test_Stop $e) {
    assert_true($e->getMessage() === 'json_success', 'get draft should succeed for normal logged-in user');
}

$_POST = array(
    'nonce' => 'ok',
    'form_key' => 'intake_form',
    'fields' => wp_json_encode(array(
        'esign_consent' => 'yes',
        'attest_truth' => 'yes',
        'signature_name' => 'Alex',
        'signature_date' => '2026-04-16',
    )),
    'signature_mode' => 'typed',
    'signer_identity' => 'Alex',
);
try {
    DCB_Submissions::submit_ajax();
} catch (DCB_Test_Stop $e) {
    assert_true($e->getMessage() === 'json_success', 'submit should succeed for normal logged-in user');
}

echo "capabilities_access_smoke:ok\n";
