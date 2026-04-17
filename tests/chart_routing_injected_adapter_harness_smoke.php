<?php

define('ABSPATH', __DIR__ . '/');

$GLOBALS['dcb_harness_scenario'] = 'success';
$GLOBALS['dcb_harness_attempt'] = 0;

if (!function_exists('sanitize_key')) {
    function sanitize_key($key) { return strtolower(preg_replace('/[^a-zA-Z0-9_]/', '', (string) $key)); }
}
if (!function_exists('sanitize_text_field')) {
    function sanitize_text_field($text) { return trim((string) $text); }
}
if (!function_exists('apply_filters')) {
    function apply_filters($hook, $value) {
        $args = func_get_args();

        if ($hook === 'dcb_chart_routing_connector_resolve_target') {
            return array(
                'ok' => true,
                'message' => 'resolved',
                'chart_target' => array('chart_target_id' => 'chart-1'),
            );
        }

        if ($hook === 'dcb_chart_routing_connector_attach') {
            $GLOBALS['dcb_harness_attempt']++;
            $scenario = (string) ($GLOBALS['dcb_harness_scenario'] ?? 'success');

            if ($scenario === 'success') {
                return array('ok' => true, 'state' => 'attached', 'message' => 'attached', 'external_reference' => 'ref-1');
            }

            if ($scenario === 'temporary_then_success') {
                if ($GLOBALS['dcb_harness_attempt'] === 1) {
                    return array('ok' => false, 'state' => 'retry_pending', 'failure_reason' => 'network_timeout', 'message' => 'timeout');
                }
                return array('ok' => true, 'state' => 'attached', 'message' => 'attached on retry', 'external_reference' => 'ref-2');
            }

            return array('ok' => false, 'state' => 'retry_exhausted', 'failure_reason' => 'permanent_rejection', 'message' => 'rejected');
        }

        return $value;
    }
}

require_once dirname(__DIR__) . '/includes/class-chart-routing-connectors.php';

function assert_true($condition, $message): void {
    if (!$condition) {
        fwrite(STDERR, "Assertion failed: {$message}\n");
        exit(1);
    }
}

$connector = new DCB_Chart_Routing_Placeholder_Connector('api', array('provider_key' => 'real_connector_skeleton'));

$resolved = $connector->resolve_chart_target(array('candidate_key' => 'c-1'), array());
assert_true(!empty($resolved['ok']), 'injected adapter should resolve target');

$GLOBALS['dcb_harness_scenario'] = 'success';
$GLOBALS['dcb_harness_attempt'] = 0;
$attach_success = $connector->attach_document_to_chart(array('chart_target_id' => 'chart-1'), array('upload_log_id' => 10), array('queue_id' => 1));
assert_true(!empty($attach_success['ok']), 'success scenario should attach');

$GLOBALS['dcb_harness_scenario'] = 'temporary_then_success';
$GLOBALS['dcb_harness_attempt'] = 0;
$attach_temp_1 = $connector->attach_document_to_chart(array('chart_target_id' => 'chart-1'), array('upload_log_id' => 10), array('queue_id' => 2));
$attach_temp_2 = $connector->attach_document_to_chart(array('chart_target_id' => 'chart-1'), array('upload_log_id' => 10), array('queue_id' => 2, 'retry_attempt' => 2));
assert_true(empty($attach_temp_1['ok']) && (string) ($attach_temp_1['state'] ?? '') === 'retry_pending', 'temporary failure should request retry_pending');
assert_true(!empty($attach_temp_2['ok']) && (string) ($attach_temp_2['state'] ?? '') === 'attached', 'temporary scenario should succeed on follow-up attempt');

$GLOBALS['dcb_harness_scenario'] = 'permanent_fail';
$GLOBALS['dcb_harness_attempt'] = 0;
$attach_permanent = $connector->attach_document_to_chart(array('chart_target_id' => 'chart-1'), array('upload_log_id' => 10), array('queue_id' => 3));
assert_true(empty($attach_permanent['ok']) && (string) ($attach_permanent['state'] ?? '') === 'retry_exhausted', 'permanent failure should report retry_exhausted');


echo "chart_routing_injected_adapter_harness_smoke:ok\n";
