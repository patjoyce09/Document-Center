<?php

define('ABSPATH', __DIR__ . '/');

$GLOBALS['mock_current_user_caps'] = array();
$GLOBALS['mock_post_meta'] = array();
$GLOBALS['mock_died'] = false;
$GLOBALS['mock_submenus'] = array();

class DCB_Test_Stop extends Exception {}

if (!class_exists('WP_Post')) {
    class WP_Post {
        public int $ID = 0;
        public string $post_type = '';
    }
}

if (!function_exists('__')) {
    function __($text) { return $text; }
}
if (!function_exists('sanitize_key')) {
    function sanitize_key($key) { return strtolower(preg_replace('/[^a-zA-Z0-9_]/', '', (string) $key)); }
}
if (!function_exists('sanitize_text_field')) {
    function sanitize_text_field($text) { return trim((string) $text); }
}
if (!function_exists('esc_url')) {
    function esc_url($url) { return (string) $url; }
}
if (!function_exists('esc_attr')) {
    function esc_attr($value) { return (string) $value; }
}
if (!function_exists('esc_html')) {
    function esc_html($value) { return (string) $value; }
}
if (!function_exists('add_action')) {
    function add_action() {}
}
if (!function_exists('add_filter')) {
    function add_filter() {}
}
if (!function_exists('submit_button')) {
    function submit_button() { echo '<button>Load Timeline</button>'; }
}
if (!function_exists('admin_url')) {
    function admin_url($path = '') { return 'https://example.local/wp-admin/' . ltrim((string) $path, '/'); }
}
if (!function_exists('current_user_can')) {
    function current_user_can($cap) { return !empty($GLOBALS['mock_current_user_caps'][(string) $cap]); }
}
if (!function_exists('wp_die')) {
    function wp_die($message = 'wp_die') {
        $GLOBALS['mock_died'] = true;
        throw new DCB_Test_Stop((string) $message);
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
if (!function_exists('add_submenu_page')) {
    function add_submenu_page($parent_slug, $page_title, $menu_title, $capability, $menu_slug, $callback = null) {
        $GLOBALS['mock_submenus'][] = array(
            'parent_slug' => $parent_slug,
            'page_title' => $page_title,
            'menu_title' => $menu_title,
            'capability' => $capability,
            'menu_slug' => $menu_slug,
        );
        return 'dcb-intake-trace';
    }
}
if (!function_exists('get_posts')) {
    function get_posts() { return array(); }
}
if (!function_exists('apply_filters')) {
    function apply_filters($hook, $value) { return $value; }
}

require_once dirname(__DIR__) . '/includes/helpers-intake.php';
require_once dirname(__DIR__) . '/includes/class-permissions.php';
require_once dirname(__DIR__) . '/includes/class-intake-trace.php';

function assert_true($condition, $message): void {
    if (!$condition) {
        fwrite(STDERR, "Assertion failed: {$message}\n");
        exit(1);
    }
}

DCB_Intake_Trace::register_admin_page();
assert_true(!empty($GLOBALS['mock_submenus']), 'submenu should be registered');
assert_true((string) ($GLOBALS['mock_submenus'][0]['menu_slug'] ?? '') === 'dcb-intake-trace', 'menu slug should match');

$upload = new WP_Post();
$upload->ID = 100;
$upload->post_type = 'dcb_upload_log';
$GLOBALS['mock_post_meta'][100]['_dcb_upload_trace_id'] = 'trace-upload-100';

$review = new WP_Post();
$review->ID = 200;
$review->post_type = 'dcb_ocr_review_queue';
$GLOBALS['mock_post_meta'][200]['_dcb_ocr_review_trace_id'] = 'trace-review-200';

$submission = new WP_Post();
$submission->ID = 300;
$submission->post_type = 'dcb_form_submission';
$GLOBALS['mock_post_meta'][300]['_dcb_intake_trace_id'] = 'trace-submission-300';

$GLOBALS['mock_current_user_caps'] = array(DCB_Permissions::CAP_REVIEW_SUBMISSIONS => true);

$actions_upload = DCB_Intake_Trace::add_trace_row_action(array(), $upload);
$actions_review = DCB_Intake_Trace::add_trace_row_action(array(), $review);
$actions_submission = DCB_Intake_Trace::add_trace_row_action(array(), $submission);

assert_true(isset($actions_upload['dcb_intake_trace']), 'upload row action should exist');
assert_true(isset($actions_review['dcb_intake_trace']), 'review row action should exist');
assert_true(isset($actions_submission['dcb_intake_trace']), 'submission row action should exist');
assert_true(strpos((string) $actions_submission['dcb_intake_trace'], 'page=dcb-intake-trace') !== false, 'trace url should point to timeline page');

$GLOBALS['mock_current_user_caps'] = array(DCB_Permissions::CAP_RUN_OCR_TOOLS => true);
$actions_review_ocr_only = DCB_Intake_Trace::add_trace_row_action(array(), $review);
assert_true(isset($actions_review_ocr_only['dcb_intake_trace']), 'ocr-tools role should receive review queue trace action');

$GLOBALS['mock_current_user_caps'] = array();
try {
    DCB_Intake_Trace::render_page();
    fwrite(STDERR, "expected unauthorized wp_die\n");
    exit(1);
} catch (DCB_Test_Stop $e) {
    assert_true($GLOBALS['mock_died'] === true, 'render_page should deny unauthorized access');
}

echo "intake_trace_admin_links_smoke:ok\n";
