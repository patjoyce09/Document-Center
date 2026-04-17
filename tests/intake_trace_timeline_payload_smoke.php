<?php

define('ABSPATH', __DIR__ . '/');

if (!function_exists('sanitize_key')) {
    function sanitize_key($key) { return strtolower(preg_replace('/[^a-zA-Z0-9_]/', '', (string) $key)); }
}
if (!function_exists('sanitize_text_field')) {
    function sanitize_text_field($text) { return trim((string) $text); }
}
if (!function_exists('current_time')) {
    function current_time() { return '2026-04-16 00:00:00'; }
}
if (!function_exists('admin_url')) {
    function admin_url($path = '') { return 'https://example.local/wp-admin/' . ltrim((string) $path, '/'); }
}
if (!function_exists('apply_filters')) {
    function apply_filters($hook, $value) { return $value; }
}

require_once dirname(__DIR__) . '/includes/helpers-intake.php';

$payload = array(
    'trace_id' => 'dcb-intake-case-001',
    'source_channel' => 'email_import',
    'capture_type' => 'email_attachment',
    'workflow_status' => 'approved',
    'review_status' => 'approved',
    'unresolved_ocr_risk' => false,
    'upload' => array(
        'id' => 11,
        'uploaded_at' => '2026-04-15 10:00:00',
        'file_name' => 'passport.pdf',
    ),
    'review' => array(
        'id' => 22,
        'created_at' => '2026-04-15 11:00:00',
        'status' => 'approved',
        'revisions' => array(
            array('event' => 'status_changed', 'time' => '2026-04-15 12:00:00', 'actor_name' => 'Reviewer A'),
        ),
    ),
    'submission' => array(
        'id' => 33,
        'submitted_at' => '2026-04-15 13:00:00',
        'workflow_status' => 'approved',
        'workflow_timeline' => array(
            array('event' => 'finalized', 'time' => '2026-04-15 14:00:00', 'actor_name' => 'Case Manager'),
        ),
    ),
);

$linked = dcb_intake_trace_resolve_linked_ids_from_payload($payload);
if ((int) ($linked['upload_log_id'] ?? 0) !== 11 || (int) ($linked['review_item_id'] ?? 0) !== 22 || (int) ($linked['submission_id'] ?? 0) !== 33) {
    fwrite(STDERR, "linked ids mismatch\n");
    exit(1);
}

$summary = dcb_intake_trace_current_state_summary($payload);
if ((string) ($summary['current_state'] ?? '') !== 'approved' || (string) ($summary['current_state_label'] ?? '') !== 'Approved') {
    fwrite(STDERR, "state summary mismatch\n");
    exit(1);
}

$out = dcb_intake_trace_build_payload($payload);
$events = isset($out['events']) && is_array($out['events']) ? $out['events'] : array();
if (count($events) < 4) {
    fwrite(STDERR, "timeline events missing\n");
    exit(1);
}

$first_event = (string) ($events[0]['event'] ?? '');
$last_event = (string) ($events[count($events) - 1]['event'] ?? '');
if ($first_event !== 'uploaded' || $last_event !== 'finalized') {
    fwrite(STDERR, "timeline order mismatch\n");
    exit(1);
}

$url = dcb_intake_trace_admin_url('dcb-intake-case-001');
if (strpos($url, 'page=dcb-intake-trace') === false || strpos($url, 'trace_id=dcb-intake-case-001') === false) {
    fwrite(STDERR, "trace url mismatch\n");
    exit(1);
}

echo "intake_trace_timeline_payload_smoke:ok\n";
