<?php

define('ABSPATH', __DIR__ . '/');

$GLOBALS['dcb_options'] = array();
$GLOBALS['dcb_post_meta'] = array();
$GLOBALS['dcb_user_meta'] = array();
$GLOBALS['dcb_posts'] = array();
$GLOBALS['dcb_registered_post_types'] = array();
$GLOBALS['dcb_next_post_id'] = 1000;
$GLOBALS['dcb_current_user_id'] = 1;
$GLOBALS['dcb_current_caps'] = array(
    'dcb_manage_forms' => true,
    'dcb_review_submissions' => true,
    'dcb_manage_workflows' => true,
    'dcb_manage_settings' => true,
    'dcb_run_ocr_tools' => true,
);

if (!class_exists('DCB_Test_Halt')) {
    class DCB_Test_Halt extends RuntimeException {
        public string $kind;
        public $payload;
        public int $status;

        public function __construct(string $kind, $payload = null, int $status = 0) {
            parent::__construct($kind, $status);
            $this->kind = $kind;
            $this->payload = $payload;
            $this->status = $status;
        }
    }
}

if (!class_exists('WP_User')) {
    class WP_User {
        public int $ID;
        public string $display_name;
        public string $user_email;

        public function __construct(int $id = 1, string $display_name = 'Tester', string $user_email = 'tester@example.com') {
            $this->ID = $id;
            $this->display_name = $display_name;
            $this->user_email = $user_email;
        }
    }
}

if (!class_exists('WP_Post')) {
    class WP_Post {
        public int $ID;
        public string $post_type;
        public string $post_title;
        public string $post_status;
        public string $post_name;
        public string $post_content;

        public function __construct(int $id = 0, string $post_type = 'post', string $post_title = '', string $post_status = 'publish', string $post_name = '', string $post_content = '') {
            $this->ID = $id;
            $this->post_type = $post_type;
            $this->post_title = $post_title;
            $this->post_status = $post_status;
            $this->post_name = $post_name;
            $this->post_content = $post_content;
        }
    }
}

if (!class_exists('WP_List_Table')) {
    class WP_List_Table {
        protected array $_args = array();
        protected array $_pagination_args = array();
        protected array $_column_headers = array();
        public array $items = array();

        public function __construct(array $args = array()) { $this->_args = $args; }
        protected function row_actions(array $actions): string { return ''; }
        public function display(): void {}
        public function current_action() { return false; }
        protected function get_items_per_page(string $option, int $default): int { return $default; }
        protected function get_pagenum(): int { return 1; }
        protected function set_pagination_args(array $args): void { $this->_pagination_args = $args; }
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

if (!class_exists('WP_Error')) {
    class WP_Error {
        public function get_error_message(): string { return 'request failed'; }
    }
}

if (!function_exists('__')) {
    function __($text) { return $text; }
}
if (!function_exists('esc_html__')) {
    function esc_html__($text) { return $text; }
}
if (!function_exists('add_action')) {
    function add_action($tag, $callback, $priority = 10, $accepted_args = 1) {}
}
if (!function_exists('add_filter')) {
    function add_filter($tag, $callback, $priority = 10, $accepted_args = 1) {}
}
if (!function_exists('add_shortcode')) {
    function add_shortcode($tag, $callback) {}
}
if (!function_exists('apply_filters')) {
    function apply_filters($tag, $value) {
        if ($tag === 'dcb_submission_access_allowed' && array_key_exists('dcb_submission_access_allowed', $GLOBALS)) {
            return (bool) $GLOBALS['dcb_submission_access_allowed'];
        }
        return $value;
    }
}
if (!function_exists('do_action')) {
    function do_action($tag, ...$args) {
        $GLOBALS['dcb_actions'][] = array('tag' => $tag, 'args' => $args);
    }
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
if (!function_exists('sanitize_email')) {
    function sanitize_email($email) { return strtolower(trim((string) $email)); }
}
if (!function_exists('sanitize_file_name')) {
    function sanitize_file_name($filename) {
        $filename = preg_replace('/[^A-Za-z0-9\.\-_]+/', '-', (string) $filename);
        return trim((string) $filename, '-');
    }
}
if (!function_exists('sanitize_title')) {
    function sanitize_title($title) { return trim(strtolower(preg_replace('/[^a-zA-Z0-9_\-]+/', '-', (string) $title)), '-'); }
}
if (!function_exists('esc_url_raw')) {
    function esc_url_raw($url) { return trim((string) $url); }
}
if (!function_exists('esc_url')) {
    function esc_url($url) { return (string) $url; }
}
if (!function_exists('esc_html')) {
    function esc_html($text) { return (string) $text; }
}
if (!function_exists('esc_attr')) {
    function esc_attr($text) { return (string) $text; }
}
if (!function_exists('esc_textarea')) {
    function esc_textarea($text) { return (string) $text; }
}
if (!function_exists('checked')) {
    function checked($checked, $current = true, $display = true) {
        $out = ((string) $checked === (string) $current) ? 'checked="checked"' : '';
        return $display ? print($out) : $out;
    }
}
if (!function_exists('selected')) {
    function selected($selected, $current = true, $display = true) {
        $out = ((string) $selected === (string) $current) ? 'selected="selected"' : '';
        return $display ? print($out) : $out;
    }
}
if (!function_exists('wp_json_encode')) {
    function wp_json_encode($data, $flags = 0) { return json_encode($data, $flags); }
}
if (!function_exists('wp_generate_password')) {
    function wp_generate_password($len = 12) { return substr(str_repeat('a', max(1, $len)), 0, max(1, $len)); }
}
if (!function_exists('wp_generate_uuid4')) {
    function wp_generate_uuid4() { return '00000000-0000-4000-8000-000000000000'; }
}
if (!function_exists('is_email')) {
    function is_email($email) { return filter_var((string) $email, FILTER_VALIDATE_EMAIL) !== false; }
}
if (!function_exists('is_wp_error')) {
    function is_wp_error($v) { return $v instanceof WP_Error; }
}
if (!function_exists('wp_unslash')) {
    function wp_unslash($value) { return $value; }
}
if (!function_exists('current_time')) {
    function current_time($type = 'mysql') { return '2026-04-15 00:00:00'; }
}
if (!function_exists('trailingslashit')) {
    function trailingslashit($v) { return rtrim((string) $v, '/') . '/'; }
}
if (!function_exists('admin_url')) {
    function admin_url($path = '') { return 'https://example.test/wp-admin/' . ltrim((string) $path, '/'); }
}
if (!function_exists('add_query_arg')) {
    function add_query_arg($args, $url = '') {
        $url = (string) $url;
        $parts = parse_url($url);
        $query = array();
        if (!empty($parts['query'])) {
            parse_str($parts['query'], $query);
        }
        foreach ((array) $args as $key => $value) {
            $query[(string) $key] = (string) $value;
        }
        $path = isset($parts['scheme']) ? ($parts['scheme'] . '://' . ($parts['host'] ?? '') . ($parts['path'] ?? '')) : ($parts['path'] ?? $url);
        return $path . '?' . http_build_query($query);
    }
}
if (!function_exists('wp_nonce_field')) {
    function wp_nonce_field($action, $name = '_wpnonce') {}
}
if (!function_exists('wp_verify_nonce')) {
    function wp_verify_nonce($nonce, $action = -1) { return (string) $nonce === 'valid'; }
}
if (!function_exists('check_admin_referer')) {
    function check_admin_referer($action = -1, $query_arg = '_wpnonce') {
        $nonce = isset($_REQUEST[$query_arg]) ? (string) $_REQUEST[$query_arg] : '';
        if ($nonce !== 'valid') {
            throw new DCB_Test_Halt('nonce_failed', $action, 403);
        }
        return 1;
    }
}
if (!function_exists('check_ajax_referer')) {
    function check_ajax_referer($action = -1, $query_arg = false, $stop = true) {
        $key = $query_arg ? (string) $query_arg : '_ajax_nonce';
        $nonce = isset($_REQUEST[$key]) ? (string) $_REQUEST[$key] : '';
        if ($nonce !== 'valid') {
            throw new DCB_Test_Halt('nonce_failed', $action, 403);
        }
        return 1;
    }
}
if (!function_exists('wp_safe_redirect')) {
    function wp_safe_redirect($url) { throw new DCB_Test_Halt('redirect', (string) $url, 302); }
}
if (!function_exists('wp_die')) {
    function wp_die($message = '') { throw new DCB_Test_Halt('die', (string) $message, 500); }
}
if (!function_exists('wp_send_json_success')) {
    function wp_send_json_success($data = null, $status_code = null) { throw new DCB_Test_Halt('json_success', $data, (int) ($status_code ?? 200)); }
}
if (!function_exists('wp_send_json_error')) {
    function wp_send_json_error($data = null, $status_code = null) { throw new DCB_Test_Halt('json_error', $data, (int) ($status_code ?? 400)); }
}
if (!function_exists('submit_button')) {
    function submit_button($text = 'Submit', $type = 'primary', $name = 'submit', $wrap = true) {}
}
if (!function_exists('paginate_links')) {
    function paginate_links($args = array()) { return ''; }
}
if (!function_exists('wp_kses_post')) {
    function wp_kses_post($content) { return (string) $content; }
}
if (!function_exists('nocache_headers')) {
    function nocache_headers() {}
}
if (!function_exists('wp_next_scheduled')) {
    function wp_next_scheduled($hook, $args = array()) {
        $events = isset($GLOBALS['dcb_scheduled_events']) && is_array($GLOBALS['dcb_scheduled_events']) ? $GLOBALS['dcb_scheduled_events'] : array();
        return isset($events[(string) $hook]) ? (int) $events[(string) $hook]['timestamp'] : false;
    }
}
if (!function_exists('wp_schedule_event')) {
    function wp_schedule_event($timestamp, $recurrence, $hook, $args = array()) {
        if (!isset($GLOBALS['dcb_scheduled_events']) || !is_array($GLOBALS['dcb_scheduled_events'])) {
            $GLOBALS['dcb_scheduled_events'] = array();
        }
        $GLOBALS['dcb_scheduled_events'][(string) $hook] = array(
            'timestamp' => (int) $timestamp,
            'recurrence' => (string) $recurrence,
            'args' => (array) $args,
        );
        return true;
    }
}
if (!function_exists('wp_mail')) {
    function wp_mail($to, $subject, $message, $headers = '', $attachments = array()) {
        if (!isset($GLOBALS['dcb_sent_mails']) || !is_array($GLOBALS['dcb_sent_mails'])) {
            $GLOBALS['dcb_sent_mails'] = array();
        }
        $GLOBALS['dcb_sent_mails'][] = array(
            'to' => (string) $to,
            'subject' => (string) $subject,
            'message' => (string) $message,
            'headers' => $headers,
            'attachments' => $attachments,
        );
        return true;
    }
}
if (!function_exists('get_option')) {
    function get_option($key, $default = false) { return array_key_exists($key, $GLOBALS['dcb_options']) ? $GLOBALS['dcb_options'][$key] : $default; }
}
if (!function_exists('add_option')) {
    function add_option($key, $value) { if (!array_key_exists($key, $GLOBALS['dcb_options'])) { $GLOBALS['dcb_options'][$key] = $value; } }
}
if (!function_exists('update_option')) {
    function update_option($key, $value) { $GLOBALS['dcb_options'][$key] = $value; }
}
if (!function_exists('get_post_meta')) {
    function get_post_meta($post_id, $key, $single = true) {
        $post_id = (int) $post_id;
        if (!isset($GLOBALS['dcb_post_meta'][$post_id]) || !array_key_exists($key, $GLOBALS['dcb_post_meta'][$post_id])) {
            return $single ? '' : array();
        }
        return $GLOBALS['dcb_post_meta'][$post_id][$key];
    }
}
if (!function_exists('update_post_meta')) {
    function update_post_meta($post_id, $key, $value) {
        $post_id = (int) $post_id;
        if (!isset($GLOBALS['dcb_post_meta'][$post_id])) {
            $GLOBALS['dcb_post_meta'][$post_id] = array();
        }
        $GLOBALS['dcb_post_meta'][$post_id][$key] = $value;
    }
}
if (!function_exists('delete_user_meta')) {
    function delete_user_meta($user_id, $key) {
        unset($GLOBALS['dcb_user_meta'][(int) $user_id][(string) $key]);
    }
}
if (!function_exists('get_user_meta')) {
    function get_user_meta($user_id, $key, $single = true) {
        return $GLOBALS['dcb_user_meta'][(int) $user_id][(string) $key] ?? ($single ? '' : array());
    }
}
if (!function_exists('update_user_meta')) {
    function update_user_meta($user_id, $key, $value) {
        if (!isset($GLOBALS['dcb_user_meta'][(int) $user_id])) {
            $GLOBALS['dcb_user_meta'][(int) $user_id] = array();
        }
        $GLOBALS['dcb_user_meta'][(int) $user_id][(string) $key] = $value;
    }
}
if (!function_exists('get_post')) {
    function get_post($post_id) {
        return $GLOBALS['dcb_posts'][(int) $post_id] ?? null;
    }
}
if (!function_exists('wp_insert_post')) {
    function wp_insert_post($postarr) {
        $id = isset($postarr['ID']) ? (int) $postarr['ID'] : 0;
        if ($id > 0 && isset($GLOBALS['dcb_posts'][$id]) && $GLOBALS['dcb_posts'][$id] instanceof WP_Post) {
            $post = $GLOBALS['dcb_posts'][$id];
            $post->post_type = (string) ($postarr['post_type'] ?? $post->post_type);
            $post->post_title = (string) ($postarr['post_title'] ?? $post->post_title);
            $post->post_status = (string) ($postarr['post_status'] ?? $post->post_status);
            $post->post_name = (string) ($postarr['post_name'] ?? $post->post_name);
            $post->post_content = (string) ($postarr['post_content'] ?? $post->post_content);
            $GLOBALS['dcb_posts'][$id] = $post;
            return $id;
        }

        $id = ++$GLOBALS['dcb_next_post_id'];
        $post = new WP_Post(
            $id,
            (string) ($postarr['post_type'] ?? 'post'),
            (string) ($postarr['post_title'] ?? 'Untitled'),
            (string) ($postarr['post_status'] ?? 'publish'),
            (string) ($postarr['post_name'] ?? ''),
            (string) ($postarr['post_content'] ?? '')
        );
        $GLOBALS['dcb_posts'][$id] = $post;
        return $id;
    }
}
if (!function_exists('wp_delete_post')) {
    function wp_delete_post($post_id, $force_delete = false) {
        $post_id = (int) $post_id;
        unset($GLOBALS['dcb_posts'][$post_id], $GLOBALS['dcb_post_meta'][$post_id]);
        return true;
    }
}
if (!function_exists('register_post_type')) {
    function register_post_type($post_type, $args = array()) {
        $GLOBALS['dcb_registered_post_types'][(string) $post_type] = is_array($args) ? $args : array();
    }
}
if (!function_exists('post_type_exists')) {
    function post_type_exists($post_type) {
        return array_key_exists((string) $post_type, $GLOBALS['dcb_registered_post_types']);
    }
}
if (!function_exists('get_posts')) {
    function get_posts($args = array()) {
        $post_type = isset($args['post_type']) ? (string) $args['post_type'] : '';
        $statuses = isset($args['post_status']) ? (array) $args['post_status'] : array('publish');
        $fields = isset($args['fields']) ? (string) $args['fields'] : '';
        $limit = isset($args['posts_per_page']) ? (int) $args['posts_per_page'] : 5;

        $rows = array();
        foreach ((array) $GLOBALS['dcb_posts'] as $post) {
            if (!$post instanceof WP_Post) {
                continue;
            }
            if ($post_type !== '' && $post->post_type !== $post_type) {
                continue;
            }
            if (!empty($statuses) && !in_array($post->post_status, $statuses, true)) {
                continue;
            }
            $rows[] = $post;
        }

        if ($limit > 0) {
            $rows = array_slice($rows, 0, $limit);
        }

        if ($fields === 'ids') {
            return array_values(array_map(static function ($post) { return (int) $post->ID; }, $rows));
        }

        return $rows;
    }
}
if (!function_exists('is_user_logged_in')) {
    function is_user_logged_in() { return (int) ($GLOBALS['dcb_current_user_id'] ?? 0) > 0; }
}
if (!function_exists('get_current_user_id')) {
    function get_current_user_id() { return (int) ($GLOBALS['dcb_current_user_id'] ?? 0); }
}
if (!function_exists('wp_get_current_user')) {
    function wp_get_current_user() {
        $id = (int) ($GLOBALS['dcb_current_user_id'] ?? 0);
        return new WP_User($id, 'Tester', 'tester@example.com');
    }
}
if (!function_exists('current_user_can')) {
    function current_user_can($cap) { return !empty($GLOBALS['dcb_current_caps'][(string) $cap]); }
}
if (!function_exists('user_can')) {
    function user_can($user, $cap) {
        if (!$user instanceof WP_User) {
            return false;
        }
        return $user->ID > 0 && !empty($GLOBALS['dcb_current_caps'][(string) $cap]);
    }
}
if (!function_exists('get_the_title')) {
    function get_the_title($post_id = 0) {
        $post = get_post((int) $post_id);
        return $post instanceof WP_Post ? (string) $post->post_title : '';
    }
}
if (!function_exists('get_users')) {
    function get_users($args = array()) {
        return array(new WP_User(11, 'Reviewer One', 'r1@example.com'), new WP_User(12, 'Reviewer Two', 'r2@example.com'));
    }
}
if (!function_exists('get_user_by')) {
    function get_user_by($field, $value) {
        $id = (int) $value;
        if ($id < 1) {
            return null;
        }
        if (isset($GLOBALS['dcb_missing_user_ids']) && is_array($GLOBALS['dcb_missing_user_ids']) && in_array($id, $GLOBALS['dcb_missing_user_ids'], true)) {
            return null;
        }
        return new WP_User($id, 'Reviewer ' . $id, 'reviewer' . $id . '@example.com');
    }
}
if (!function_exists('get_role')) {
    function get_role($role) { return new WP_Role(); }
}
if (!function_exists('dcb_upload_extract_text_from_file_local')) {
    function dcb_upload_extract_text_from_file_local($path, $mime) { return array('text' => 'local text', 'pages' => array()); }
}
if (!function_exists('dcb_ocr_collect_environment_diagnostics')) {
    function dcb_ocr_collect_environment_diagnostics() { return array('status' => 'ready', 'warnings' => array()); }
}
if (!function_exists('dcb_upload_normalize_text')) {
    function dcb_upload_normalize_text($text) { return trim(strtolower((string) $text)); }
}
if (!function_exists('dcb_ocr_smoke_validation')) {
    function dcb_ocr_smoke_validation($diag = null) { return array('ok' => true, 'messages' => array('ok')); }
}
if (!function_exists('wp_handle_upload')) {
    function wp_handle_upload($file, $overrides = array()) {
        if (isset($GLOBALS['dcb_mock_upload_result']) && is_array($GLOBALS['dcb_mock_upload_result'])) {
            return $GLOBALS['dcb_mock_upload_result'];
        }

        $tmp = isset($file['tmp_name']) ? (string) $file['tmp_name'] : '';
        if ($tmp === '' || !file_exists($tmp)) {
            return array('error' => 'upload failed');
        }

        $type = isset($file['type']) ? (string) $file['type'] : 'application/octet-stream';
        return array('file' => $tmp, 'type' => $type);
    }
}
if (!function_exists('dcb_parse_emails')) {
    function dcb_parse_emails($raw) {
        $raw = trim((string) $raw);
        if ($raw === '') {
            return array();
        }
        return array($raw);
    }
}
if (!function_exists('wp_mail')) {
    function wp_mail($to, $subject, $message, $headers = '', $attachments = array()) { return true; }
}
if (!function_exists('wp_remote_request')) {
    function wp_remote_request($url, $args = array()) {
        if (isset($GLOBALS['dcb_mock_remote_responses']) && is_array($GLOBALS['dcb_mock_remote_responses'])) {
            if (!empty($GLOBALS['dcb_mock_remote_responses'])) {
                return array_shift($GLOBALS['dcb_mock_remote_responses']);
            }
        }
        if (isset($GLOBALS['dcb_mock_remote_response'])) {
            return $GLOBALS['dcb_mock_remote_response'];
        }
        return array(
            'response' => array('code' => 200),
            'body' => json_encode(array(
                'contract_version' => 'dcb-ocr-v1',
                'request_id' => 'test-request',
                'provider' => array('name' => 'test-remote', 'version' => '1.0'),
                'result' => array('engine' => 'remote-api', 'text' => 'remote text', 'pages' => array(), 'warnings' => array(), 'failure_reason' => ''),
            )),
        );
    }
}
if (!function_exists('wp_remote_retrieve_response_code')) {
    function wp_remote_retrieve_response_code($response) { return (int) ($response['response']['code'] ?? 0); }
}
if (!function_exists('wp_remote_retrieve_body')) {
    function wp_remote_retrieve_body($response) { return (string) ($response['body'] ?? ''); }
}
if (!function_exists('dcb_validate_submission')) {
    function dcb_validate_submission($form_key, $raw_fields) {
        return array(
            'ok' => true,
            'errors' => array(),
            'clean' => is_array($raw_fields) ? $raw_fields : array(),
            'form' => array('label' => 'Runtime Form', 'version' => 1),
        );
    }
}
if (!function_exists('dcb_get_policy_versions')) {
    function dcb_get_policy_versions() {
        return array('consent_text_version' => 'consent-default', 'attestation_text_version' => 'attestation-default');
    }
}
if (!function_exists('dcb_finalize_submission_output')) {
    function dcb_finalize_submission_output($submission_id, $user_id) {
        update_post_meta((int) $submission_id, '_dcb_render_finalized', '1');
    }
}
if (!function_exists('dcb_send_submission_notification')) {
    function dcb_send_submission_notification($submission_id) {}
}

if (!class_exists('DCB_Signatures')) {
    final class DCB_Signatures {
        public static function normalize_mode(string $mode): string {
            return $mode === 'drawn' ? 'drawn' : 'typed';
        }

        public static function validate_payload(array $payload): array {
            return array('ok' => true, 'errors' => array());
        }

        public static function build_evidence_package(array $payload): array {
            return array(
                'payload_hash' => 'hash',
                'drawn_signature_hash' => '',
                'evidence' => array('ok' => true),
            );
        }
    }
}

require_once dirname(__DIR__, 2) . '/includes/class-permissions.php';
require_once dirname(__DIR__, 2) . '/includes/class-form-repository.php';
require_once dirname(__DIR__, 2) . '/includes/class-ocr-engine.php';
require_once dirname(__DIR__, 2) . '/includes/class-ocr.php';
require_once dirname(__DIR__, 2) . '/includes/helpers-schema.php';
require_once dirname(__DIR__, 2) . '/includes/class-workflow.php';
require_once dirname(__DIR__, 2) . '/includes/class-submissions.php';
require_once dirname(__DIR__, 2) . '/includes/class-migrations.php';
