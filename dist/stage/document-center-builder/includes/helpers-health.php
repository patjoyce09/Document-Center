<?php

if (!defined('ABSPATH')) {
    exit;
}

function dcb_health_can_review(): bool {
    if (class_exists('DCB_Permissions')) {
        return DCB_Permissions::can(DCB_Permissions::CAP_REVIEW_SUBMISSIONS);
    }
    return function_exists('current_user_can') && (current_user_can('dcb_review_submissions') || current_user_can('manage_options'));
}

function dcb_health_can_run_ocr(): bool {
    if (class_exists('DCB_Permissions')) {
        return DCB_Permissions::can(DCB_Permissions::CAP_RUN_OCR_TOOLS);
    }
    return function_exists('current_user_can') && (current_user_can('dcb_run_ocr_tools') || current_user_can('manage_options'));
}

function dcb_health_workflow_status_summary(int $limit = 6): array {
    if (!function_exists('get_posts')) {
        return array();
    }

    $ids = get_posts(array(
        'post_type' => 'dcb_form_submission',
        'post_status' => 'publish',
        'posts_per_page' => 250,
        'fields' => 'ids',
    ));

    $counts = array();
    foreach ((array) $ids as $id) {
        $submission_id = (int) $id;
        if ($submission_id < 1) {
            continue;
        }
        $status = function_exists('get_post_meta') ? sanitize_key((string) get_post_meta($submission_id, '_dcb_workflow_status', true)) : '';
        if ($status === '') {
            $status = 'submitted';
        }
        if (!isset($counts[$status])) {
            $counts[$status] = 0;
        }
        $counts[$status]++;
    }

    arsort($counts);
    if ($limit > 0) {
        $counts = array_slice($counts, 0, $limit, true);
    }

    return $counts;
}

function dcb_health_unresolved_ocr_risk_count(): int {
    if (!function_exists('get_posts')) {
        return 0;
    }

    $ids = get_posts(array(
        'post_type' => 'dcb_ocr_review_queue',
        'post_status' => 'publish',
        'posts_per_page' => 500,
        'fields' => 'ids',
        'meta_query' => array(
            array(
                'key' => '_dcb_ocr_review_capture_risk_unresolved',
                'value' => '1',
                'compare' => '=',
            ),
        ),
    ));

    return is_array($ids) ? count($ids) : 0;
}

function dcb_health_weekly_digest_enabled(array $context = array()): bool {
    if (isset($context['enabled'])) {
        return !empty($context['enabled']);
    }
    if (!function_exists('get_option')) {
        return false;
    }
    return (string) get_option('dcb_health_weekly_digest_enabled', '0') === '1';
}

function dcb_health_snapshot_payload(array $context = array()): array {
    $readiness = function_exists('dcb_ops_setup_readiness_payload')
        ? dcb_ops_setup_readiness_payload(isset($context['readiness_context']) && is_array($context['readiness_context']) ? $context['readiness_context'] : array())
        : array('summary' => array('ok' => 0, 'warn' => 0, 'fail' => 0));
    $readiness_summary = isset($readiness['summary']) && is_array($readiness['summary']) ? $readiness['summary'] : array('ok' => 0, 'warn' => 0, 'fail' => 0);

    $ocr_mode = sanitize_key((string) (function_exists('get_option') ? get_option('dcb_ocr_mode', 'auto') : 'auto'));
    if (!in_array($ocr_mode, array('local', 'remote', 'auto'), true)) {
        $ocr_mode = 'auto';
    }

    $ocr_health = array('status' => 'unknown');
    if (!empty($context['ocr_health']) && is_array($context['ocr_health'])) {
        $ocr_health = $context['ocr_health'];
    } elseif (function_exists('dcb_ocr_collect_environment_diagnostics')) {
        $diag = dcb_ocr_collect_environment_diagnostics(false);
        if (is_array($diag)) {
            $ocr_health = array(
                'status' => sanitize_key((string) ($diag['status'] ?? 'unknown')),
                'warnings' => isset($diag['warnings']) && is_array($diag['warnings']) ? array_values($diag['warnings']) : array(),
            );
        }
    }

    $queue_summary = function_exists('dcb_ocr_review_queue_summary') ? dcb_ocr_review_queue_summary() : array('status_counts' => array(), 'failure_counts' => array());
    $ocr_status_counts = isset($queue_summary['status_counts']) && is_array($queue_summary['status_counts']) ? $queue_summary['status_counts'] : array();

    $workflow_counts = isset($context['workflow_counts']) && is_array($context['workflow_counts'])
        ? $context['workflow_counts']
        : dcb_health_workflow_status_summary();

    $unresolved_risk_count = isset($context['unresolved_risk_count'])
        ? max(0, (int) $context['unresolved_risk_count'])
        : dcb_health_unresolved_ocr_risk_count();

    $ops_last_action = array();
    if (isset($context['ops_last_action']) && is_array($context['ops_last_action'])) {
        $ops_last_action = $context['ops_last_action'];
    } elseif (function_exists('get_option')) {
        $state = get_option('dcb_ops_last_action', array());
        if (is_array($state)) {
            $ops_last_action = $state;
        }
    }

    $upload_item = array();
    if (isset($readiness['items']) && is_array($readiness['items'])) {
        foreach ($readiness['items'] as $item) {
            if (!is_array($item)) {
                continue;
            }
            if ((string) ($item['key'] ?? '') === 'uploads') {
                $upload_item = $item;
                break;
            }
        }
    }

    $payload = array(
        'readiness_summary' => array(
            'ok' => max(0, (int) ($readiness_summary['ok'] ?? 0)),
            'warn' => max(0, (int) ($readiness_summary['warn'] ?? 0)),
            'fail' => max(0, (int) ($readiness_summary['fail'] ?? 0)),
        ),
        'ocr' => array(
            'mode' => $ocr_mode,
            'health_status' => sanitize_key((string) ($ocr_health['status'] ?? 'unknown')),
            'queue_status_counts' => $ocr_status_counts,
        ),
        'upload_storage' => array(
            'status' => sanitize_key((string) ($upload_item['status'] ?? 'unknown')),
            'note' => sanitize_text_field((string) ($upload_item['note'] ?? '')),
        ),
        'workflow_status_counts' => $workflow_counts,
        'unresolved_ocr_risk_count' => $unresolved_risk_count,
        'ops_last_action' => array(
            'action' => sanitize_key((string) ($ops_last_action['action'] ?? '')),
            'status' => sanitize_key((string) ($ops_last_action['status'] ?? '')),
            'message' => sanitize_text_field((string) ($ops_last_action['message'] ?? '')),
            'time' => sanitize_text_field((string) ($ops_last_action['time'] ?? '')),
        ),
        'permissions' => array(
            'can_review' => dcb_health_can_review(),
            'can_run_ocr_tools' => dcb_health_can_run_ocr(),
        ),
        'links' => array(
            'setup_ops' => function_exists('admin_url') ? admin_url('admin.php?page=dcb-ops') : 'admin.php?page=dcb-ops',
            'ocr_diagnostics' => function_exists('admin_url') ? admin_url('admin.php?page=dcb-ocr-diagnostics') : 'admin.php?page=dcb-ocr-diagnostics',
            'ocr_queue' => function_exists('admin_url') ? admin_url('edit.php?post_type=dcb_ocr_review_queue') : 'edit.php?post_type=dcb_ocr_review_queue',
            'intake_trace' => function_exists('admin_url') ? admin_url('admin.php?page=dcb-intake-trace') : 'admin.php?page=dcb-intake-trace',
            'upload_artifacts' => function_exists('admin_url') ? admin_url('edit.php?post_type=dcb_upload_log') : 'edit.php?post_type=dcb_upload_log',
            'workflow_queue' => function_exists('admin_url') ? admin_url('admin.php?page=dcb-workflow-queues') : 'admin.php?page=dcb-workflow-queues',
        ),
    );

    return function_exists('apply_filters') ? (array) apply_filters('dcb_system_health_snapshot_payload', $payload, $context) : $payload;
}

function dcb_health_weekly_digest_payload(array $snapshot = array(), array $context = array()): array {
    if (empty($snapshot)) {
        $snapshot = dcb_health_snapshot_payload();
    }

    $readiness = isset($snapshot['readiness_summary']) && is_array($snapshot['readiness_summary']) ? $snapshot['readiness_summary'] : array();
    $unresolved = max(0, (int) ($snapshot['unresolved_ocr_risk_count'] ?? 0));
    $ops_last = isset($snapshot['ops_last_action']) && is_array($snapshot['ops_last_action']) ? $snapshot['ops_last_action'] : array();

    $digest = array(
        'enabled' => dcb_health_weekly_digest_enabled($context),
        'generated_at' => gmdate('c'),
        'subject' => 'Document Center Weekly Health Snapshot',
        'summary' => array(
            'readiness_ok' => max(0, (int) ($readiness['ok'] ?? 0)),
            'readiness_warn' => max(0, (int) ($readiness['warn'] ?? 0)),
            'readiness_fail' => max(0, (int) ($readiness['fail'] ?? 0)),
            'unresolved_ocr_risk_count' => $unresolved,
            'last_ops_action' => sanitize_key((string) ($ops_last['action'] ?? '')),
            'last_ops_action_status' => sanitize_key((string) ($ops_last['status'] ?? '')),
        ),
    );

    return function_exists('apply_filters') ? (array) apply_filters('dcb_health_weekly_digest_payload', $digest, $snapshot, $context) : $digest;
}
