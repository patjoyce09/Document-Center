<?php

define('ABSPATH', __DIR__ . '/');
define('DCB_VERSION', '0.2.9-test');

$GLOBALS['dcb_test_meta'] = array();
$GLOBALS['dcb_test_posts'] = array();
$GLOBALS['dcb_test_filters'] = array();
$GLOBALS['dcb_test_actions'] = array();

if (!class_exists('WP_Post')) {
    class WP_Post {
        public int $ID = 0;
        public string $post_type = 'dcb_form_submission';
    }
}
if (!class_exists('WP_User')) {
    class WP_User {
        public int $ID = 7;
        public string $display_name = 'Finalizer';
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
if (!function_exists('esc_html')) {
    function esc_html($text) { return (string) $text; }
}
if (!function_exists('esc_attr')) {
    function esc_attr($text) { return (string) $text; }
}
if (!function_exists('wp_json_encode')) {
    function wp_json_encode($value, $flags = 0) { return json_encode($value, $flags); }
}
if (!function_exists('current_time')) {
    function current_time() { return '2026-04-16 00:00:00'; }
}
if (!function_exists('apply_filters')) {
    function apply_filters($hook, $value) {
        if (!isset($GLOBALS['dcb_test_filters'][(string) $hook])) {
            return $value;
        }
        $args = func_get_args();
        array_shift($args);
        $current = array_shift($args);
        foreach ($GLOBALS['dcb_test_filters'][(string) $hook] as $cb) {
            $current = call_user_func_array($cb, array_merge(array($current), $args));
        }
        return $current;
    }
}
if (!function_exists('add_filter')) {
    function add_filter($hook, $cb) {
        if (!isset($GLOBALS['dcb_test_filters'][(string) $hook])) {
            $GLOBALS['dcb_test_filters'][(string) $hook] = array();
        }
        $GLOBALS['dcb_test_filters'][(string) $hook][] = $cb;
    }
}
if (!function_exists('do_action')) {
    function do_action($hook) {
        $args = func_get_args();
        $GLOBALS['dcb_test_actions'][] = $args;
    }
}
if (!function_exists('get_option')) {
    function get_option($key, $default = null) {
        if ($key === 'dcb_policy_consent_text_version') {
            return 'consent-default';
        }
        if ($key === 'dcb_policy_attestation_text_version') {
            return 'attestation-default';
        }
        return $default;
    }
}
if (!function_exists('get_post')) {
    function get_post($id) {
        if (!isset($GLOBALS['dcb_test_posts'][(int) $id])) {
            return null;
        }
        $post = new WP_Post();
        $post->ID = (int) $id;
        $post->post_type = (string) ($GLOBALS['dcb_test_posts'][(int) $id]['post_type'] ?? 'dcb_form_submission');
        return $post;
    }
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
if (!function_exists('get_user_by')) {
    function get_user_by($field, $value) {
        if ($field === 'id' && (int) $value > 0) {
            $u = new WP_User();
            $u->ID = (int) $value;
            $u->display_name = $value === 9 ? 'Approver' : 'Finalizer';
            return $u;
        }
        return null;
    }
}
if (!function_exists('dcb_normalize_single_form')) {
    function dcb_normalize_single_form($form) { return is_array($form) ? $form : null; }
}
if (!function_exists('dcb_form_definitions')) {
    function dcb_form_definitions($for_js = false) {
        return array(
            'intake_form' => array(
                'label' => 'Intake Form',
                'version' => 2,
                'fields' => array(
                    array('key' => 'first_name', 'label' => 'First Name', 'type' => 'text'),
                    array('key' => 'signature_name', 'label' => 'Signature', 'type' => 'text'),
                ),
                'template_blocks' => array(
                    array('type' => 'heading', 'text' => 'Intake Summary', 'level' => 2),
                ),
                'document_nodes' => array(
                    array('type' => 'block', 'block_index' => 0, 'resolved' => true),
                ),
            ),
        );
    }
}
if (!function_exists('dcb_resolve_document_nodes')) {
    function dcb_resolve_document_nodes($document_nodes, $template_blocks, $fields) {
        $nodes = array();
        foreach ((array) $document_nodes as $node) {
            if (!is_array($node)) {
                continue;
            }
            if (($node['type'] ?? '') === 'block') {
                $nodes[] = array('type' => 'block', 'block_index' => 0, 'resolved' => true);
            }
        }
        return array('nodes' => $nodes, 'warnings' => array());
    }
}

require_once dirname(__DIR__) . '/includes/class-signatures.php';
require_once dirname(__DIR__) . '/includes/helpers-render.php';

function assert_true($condition, $message): void {
    if (!$condition) {
        fwrite(STDERR, "Assertion failed: {$message}\n");
        exit(1);
    }
}

$submission_id = 501;
$GLOBALS['dcb_test_posts'][$submission_id] = array('post_type' => 'dcb_form_submission');
update_post_meta($submission_id, '_dcb_form_key', 'intake_form');
update_post_meta($submission_id, '_dcb_form_label', 'Intake Form');
update_post_meta($submission_id, '_dcb_form_version', 2);
update_post_meta($submission_id, '_dcb_form_submitted_by', 7);
update_post_meta($submission_id, '_dcb_form_submitted_by_name', 'Portal User');
update_post_meta($submission_id, '_dcb_form_submitted_by_email', 'portal@example.com');
update_post_meta($submission_id, '_dcb_form_submitted_at', '2026-04-16 00:00:00');
update_post_meta($submission_id, '_dcb_form_qa_passed', '1');
update_post_meta($submission_id, '_dcb_form_data', json_encode(array('first_name' => 'Jamie', 'signature_name' => 'Jamie')));
update_post_meta($submission_id, '_dcb_form_payload_hash', 'abc123');
update_post_meta($submission_id, '_dcb_workflow_status', 'approved');
update_post_meta($submission_id, '_dcb_workflow_timeline', array(
    array('event' => 'approved', 'time' => '2026-04-16 00:10:00', 'actor_name' => 'Approver'),
));

$normalized_signature = DCB_Signatures::persist_submission_signature($submission_id, array(
    'mode' => 'typed',
    'signature_value' => 'Jamie',
    'signer_display_name' => 'Jamie Doe',
    'signer_user_id' => 7,
    'signature_timestamp' => '2026-04-16 00:00:01',
    'signature_date' => '2026-04-16',
    'signature_field_key' => 'signature_name',
    'signature_source' => 'digital_form_submission',
));
assert_true((string) ($normalized_signature['signer_display_name'] ?? '') === 'Jamie Doe', 'signature persistence should normalize signer display name');

$restored_signature = DCB_Signatures::get_submission_signature($submission_id);
assert_true((string) ($restored_signature['signature_field_key'] ?? '') === 'signature_name', 'signature retrieval should preserve signature field context');

add_filter('dcb_output_template_key', static function ($key) {
    return 'dcb-digital-form-compact-v1';
});

$payload = dcb_normalize_submission_payload($submission_id);
assert_true((string) ($payload['render_template_mapping']['template_key'] ?? '') === 'dcb-digital-form-compact-v1', 'template selection filter should apply');
assert_true((string) ($payload['export_context']['export_contract_version'] ?? '') === '2.0', 'export context should include contract version');

dcb_finalize_submission_output($submission_id, 7);
assert_true((string) get_post_meta($submission_id, '_dcb_output_template_key', true) === 'dcb-digital-form-compact-v1', 'finalization should persist output template key');
assert_true((string) get_post_meta($submission_id, '_dcb_form_output_version', true) !== '', 'finalization should persist output version');

$final_html = dcb_render_submission_html($submission_id, 'final');
assert_true(strpos($final_html, 'LOCKED FINAL RECORD') !== false, 'final render should include locked marker');
assert_true(strpos($final_html, 'Finalization Evidence') !== false, 'final render should include finalization evidence section');
assert_true(strpos($final_html, 'Signature Evidence') !== false, 'final render should include signature evidence section');

$print_html = dcb_render_submission_html($submission_id, 'print');
assert_true(strpos($print_html, 'LOCKED FINAL RECORD') !== false, 'print render should include locked marker');

$badge = dcb_render_status_badge_html('finalized', 'print');
assert_true(strpos($badge, 'FINALIZED') !== false, 'status badge should render finalized label');

$pdf_fail = dcb_pdf_export_adapter_validate_result(array('ok' => false, 'message' => 'no adapter'));
assert_true(empty($pdf_fail['ok']), 'pdf adapter validator should fail bad contract');
$pdf_ok = dcb_pdf_export_adapter_validate_result(array('ok' => true, 'mime' => 'application/pdf', 'filename' => 'a.pdf', 'binary' => 'PDFDATA'));
assert_true(!empty($pdf_ok['ok']) && $pdf_ok['filename'] === 'a.pdf', 'pdf adapter validator should pass valid contract');

echo "output_signatures_smoke:ok\n";
