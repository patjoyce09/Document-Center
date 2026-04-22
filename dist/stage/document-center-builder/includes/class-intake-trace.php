<?php

if (!defined('ABSPATH')) {
    exit;
}

final class DCB_Intake_Trace {
    public static function init(): void {
        add_action('admin_menu', array(__CLASS__, 'register_admin_page'));
        add_filter('post_row_actions', array(__CLASS__, 'add_trace_row_action'), 20, 2);
    }

    public static function user_can_access(): bool {
        return DCB_Permissions::can(DCB_Permissions::CAP_REVIEW_SUBMISSIONS)
            || DCB_Permissions::can(DCB_Permissions::CAP_RUN_OCR_TOOLS);
    }

    public static function register_admin_page(): void {
        add_submenu_page(
            'dcb-dashboard',
            __('Intake Trace Timeline', 'document-center-builder'),
            __('Intake Trace Timeline', 'document-center-builder'),
            'read',
            'dcb-intake-trace',
            array(__CLASS__, 'render_page')
        );
    }

    public static function add_trace_row_action(array $actions, WP_Post $post): array {
        if (!self::user_can_access()) {
            return $actions;
        }

        $trace_id = '';
        if ($post->post_type === 'dcb_upload_log') {
            $trace_id = sanitize_text_field((string) get_post_meta((int) $post->ID, '_dcb_upload_trace_id', true));
        } elseif ($post->post_type === 'dcb_ocr_review_queue') {
            $trace_id = sanitize_text_field((string) get_post_meta((int) $post->ID, '_dcb_ocr_review_trace_id', true));
        } elseif ($post->post_type === 'dcb_form_submission') {
            $trace_id = sanitize_text_field((string) get_post_meta((int) $post->ID, '_dcb_intake_trace_id', true));
        }

        if ($trace_id === '') {
            return $actions;
        }

        $actions['dcb_intake_trace'] = '<a href="' . esc_url(dcb_intake_trace_admin_url($trace_id)) . '">Trace Timeline</a>';
        return $actions;
    }

    public static function render_page(): void {
        if (!self::user_can_access()) {
            wp_die('Unauthorized');
        }

        $trace_id = isset($_GET['trace_id']) ? sanitize_text_field((string) $_GET['trace_id']) : '';

        echo '<div class="wrap">';
        echo '<h1>Intake Trace Timeline</h1>';
        echo '<p>Single-chain view from original capture to review, submission, routing, and finalized state.</p>';

        echo '<form method="get" style="margin:12px 0 18px;">';
        echo '<input type="hidden" name="page" value="dcb-intake-trace" />';
        echo '<label for="dcb-trace-id" style="font-weight:600;">Trace ID</label> ';
        echo '<input id="dcb-trace-id" type="text" name="trace_id" value="' . esc_attr($trace_id) . '" class="regular-text" placeholder="dcb-intake-..." /> ';
        submit_button(__('Load Timeline', 'document-center-builder'), 'secondary', 'submit', false);
        echo '</form>';

        if ($trace_id === '') {
            echo '<p>Enter a trace ID to inspect intake chain visibility.</p>';
            echo '</div>';
            return;
        }

        $payload = self::build_trace_payload($trace_id);
        if (empty($payload['summary']) || !is_array($payload['summary'])) {
            echo '<p>No chain found for this trace ID.</p>';
            echo '</div>';
            return;
        }

        $summary = (array) $payload['summary'];
        $upload = isset($payload['upload']) && is_array($payload['upload']) ? $payload['upload'] : array();
        $review = isset($payload['review']) && is_array($payload['review']) ? $payload['review'] : array();
        $submission = isset($payload['submission']) && is_array($payload['submission']) ? $payload['submission'] : array();
        $digital_twin = isset($payload['digital_twin']) && is_array($payload['digital_twin']) ? $payload['digital_twin'] : array();
        $final_output = isset($payload['final_output']) && is_array($payload['final_output']) ? $payload['final_output'] : array();

        echo '<h2>Chain Summary</h2>';
        echo '<table class="widefat striped" style="max-width:980px"><tbody>';
        echo '<tr><th style="width:250px">Trace ID</th><td>' . esc_html((string) ($summary['trace_id'] ?? $trace_id)) . '</td></tr>';
        echo '<tr><th>Original Upload Artifact</th><td>' . esc_html((string) ($upload['id'] ?? 0) > 0 ? ('Upload Log #' . (int) $upload['id']) : 'Not linked') . '</td></tr>';
        echo '<tr><th>Source Channel</th><td>' . esc_html((string) ($summary['source_channel'] ?? 'direct_upload')) . '</td></tr>';
        echo '<tr><th>Capture Type</th><td>' . esc_html((string) ($summary['capture_type'] ?? 'unknown')) . '</td></tr>';
        echo '<tr><th>Upload Timestamp</th><td>' . esc_html((string) ($upload['uploaded_at'] ?? '')) . '</td></tr>';
        echo '<tr><th>OCR Review</th><td>' . esc_html((string) ($review['id'] ?? 0) > 0 ? ('Review #' . (int) $review['id'] . ' (' . (string) ($review['status'] ?? 'pending_review') . ')') : 'Not linked') . '</td></tr>';
        echo '<tr><th>OCR / Capture Risk</th><td>' . esc_html((string) ($review['capture_risk_summary'] ?? 'n/a')) . '</td></tr>';
        echo '<tr><th>Digital Twin / Fillable Path</th><td>' . esc_html((string) ($digital_twin['label'] ?? 'n/a')) . '</td></tr>';
        echo '<tr><th>Submission</th><td>' . esc_html((string) ($submission['id'] ?? 0) > 0 ? ('Submission #' . (int) $submission['id']) : 'Not linked') . '</td></tr>';
        echo '<tr><th>Workflow Status</th><td>' . esc_html((string) ($summary['workflow_status'] ?? '')) . '</td></tr>';
        echo '<tr><th>Finalized / Output</th><td>' . esc_html((string) ($final_output['status'] ?? 'n/a')) . '</td></tr>';
        echo '<tr><th>Current State</th><td>' . esc_html((string) ($summary['current_state_label'] ?? 'Uploaded')) . (!empty($summary['unresolved_ocr_risk']) ? ' <strong>• unresolved OCR risk</strong>' : '') . '</td></tr>';
        echo '</tbody></table>';

        $events = isset($payload['events']) && is_array($payload['events']) ? $payload['events'] : array();
        echo '<h2 style="margin-top:18px;">Timeline Events</h2>';
        if (empty($events)) {
            echo '<p>No timeline events available for this trace ID.</p>';
        } else {
            echo '<table class="widefat striped" style="max-width:980px"><thead><tr><th style="width:190px">Time</th><th style="width:220px">Event</th><th>Details</th></tr></thead><tbody>';
            foreach ($events as $event) {
                if (!is_array($event)) {
                    continue;
                }
                echo '<tr>';
                echo '<td>' . esc_html((string) ($event['time'] ?? '')) . '</td>';
                echo '<td>' . esc_html((string) ($event['label'] ?? ($event['event'] ?? 'event'))) . '</td>';
                echo '<td>' . esc_html((string) ($event['details'] ?? '')) . '</td>';
                echo '</tr>';
            }
            echo '</tbody></table>';
        }

        echo '</div>';
    }

    private static function build_trace_payload(string $trace_id): array {
        $trace_id = sanitize_text_field($trace_id);
        if ($trace_id === '') {
            return array();
        }

        $ids = self::resolve_ids($trace_id);
        $upload = self::read_upload_payload((int) ($ids['upload_log_id'] ?? 0), $trace_id);
        $review = self::read_review_payload((int) ($ids['review_item_id'] ?? 0), $trace_id);
        $submission = self::read_submission_payload((int) ($ids['submission_id'] ?? 0), $trace_id);

        if ((int) ($review['id'] ?? 0) < 1 && (int) ($upload['review_item_id'] ?? 0) > 0) {
            $review = self::read_review_payload((int) $upload['review_item_id'], $trace_id);
        }
        if ((int) ($submission['id'] ?? 0) < 1 && (int) ($upload['submission_id'] ?? 0) > 0) {
            $submission = self::read_submission_payload((int) $upload['submission_id'], $trace_id);
        }

        $workflow_status = sanitize_key((string) ($submission['workflow_status'] ?? ''));
        $review_status = sanitize_key((string) ($review['status'] ?? ''));
        $current_state = dcb_intake_state_from_statuses($workflow_status, $review_status);

        $raw = array(
            'trace_id' => $trace_id,
            'source_channel' => (string) ($upload['source_channel'] ?? ($submission['source_channel'] ?? 'direct_upload')),
            'capture_type' => (string) ($upload['capture_type'] ?? ($submission['capture_type'] ?? 'unknown')),
            'workflow_status' => $workflow_status,
            'review_status' => $review_status,
            'current_state' => $current_state,
            'upload_log_id' => (int) ($upload['id'] ?? 0),
            'review_item_id' => (int) ($review['id'] ?? 0),
            'submission_id' => (int) ($submission['id'] ?? 0),
            'unresolved_ocr_risk' => !empty($review['unresolved_ocr_risk']),
            'upload' => $upload,
            'review' => $review,
            'submission' => $submission,
            'digital_twin' => array(
                'label' => (string) ($submission['form_label'] ?? '') !== ''
                    ? ('Form key: ' . (string) ($submission['form_key'] ?? '') . ' (' . (string) ($submission['form_label'] ?? '') . ')')
                    : ((string) ($review['digital_twin_label'] ?? '') !== '' ? (string) $review['digital_twin_label'] : 'n/a'),
            ),
            'final_output' => array(
                'status' => !empty($submission['is_finalized']) ? 'finalized' : ((string) ($workflow_status !== '' ? $workflow_status : 'draft')),
            ),
        );

        return dcb_intake_trace_build_payload($raw);
    }

    private static function resolve_ids(string $trace_id): array {
        $out = array(
            'upload_log_id' => 0,
            'review_item_id' => 0,
            'submission_id' => 0,
        );

        $upload_ids = get_posts(array(
            'post_type' => 'dcb_upload_log',
            'post_status' => 'publish',
            'posts_per_page' => 1,
            'fields' => 'ids',
            'meta_query' => array(
                array(
                    'key' => '_dcb_upload_trace_id',
                    'value' => $trace_id,
                    'compare' => '=',
                ),
            ),
        ));
        if (!empty($upload_ids)) {
            $out['upload_log_id'] = (int) $upload_ids[0];
            $out['review_item_id'] = (int) get_post_meta($out['upload_log_id'], '_dcb_upload_ocr_review_item_id', true);
            $out['submission_id'] = (int) get_post_meta($out['upload_log_id'], '_dcb_upload_linked_submission_id', true);
        }

        if ($out['review_item_id'] < 1) {
            $review_ids = get_posts(array(
                'post_type' => 'dcb_ocr_review_queue',
                'post_status' => 'publish',
                'posts_per_page' => 1,
                'fields' => 'ids',
                'meta_query' => array(
                    array(
                        'key' => '_dcb_ocr_review_trace_id',
                        'value' => $trace_id,
                        'compare' => '=',
                    ),
                ),
            ));
            if (!empty($review_ids)) {
                $out['review_item_id'] = (int) $review_ids[0];
            }
        }

        if ($out['submission_id'] < 1) {
            $submission_ids = get_posts(array(
                'post_type' => 'dcb_form_submission',
                'post_status' => 'publish',
                'posts_per_page' => 1,
                'fields' => 'ids',
                'meta_query' => array(
                    array(
                        'key' => '_dcb_intake_trace_id',
                        'value' => $trace_id,
                        'compare' => '=',
                    ),
                ),
            ));
            if (!empty($submission_ids)) {
                $out['submission_id'] = (int) $submission_ids[0];
            }
        }

        return $out;
    }

    private static function read_upload_payload(int $upload_id, string $trace_id): array {
        if ($upload_id < 1) {
            return array('id' => 0, 'trace_id' => $trace_id);
        }

        return array(
            'id' => $upload_id,
            'trace_id' => sanitize_text_field((string) get_post_meta($upload_id, '_dcb_upload_trace_id', true)),
            'file_name' => sanitize_text_field((string) get_post_meta($upload_id, '_dcb_upload_title', true)),
            'uploaded_at' => sanitize_text_field((string) get_post_meta($upload_id, '_dcb_upload_uploaded_at', true)),
            'source_channel' => dcb_intake_normalize_source_channel((string) get_post_meta($upload_id, '_dcb_upload_source_channel', true)),
            'capture_type' => dcb_intake_normalize_capture_type((string) get_post_meta($upload_id, '_dcb_upload_capture_type', true)),
            'review_item_id' => (int) get_post_meta($upload_id, '_dcb_upload_ocr_review_item_id', true),
            'submission_id' => (int) get_post_meta($upload_id, '_dcb_upload_linked_submission_id', true),
        );
    }

    private static function read_review_payload(int $review_id, string $trace_id): array {
        if ($review_id < 1) {
            return array('id' => 0, 'trace_id' => $trace_id);
        }

        $warning_count = (int) get_post_meta($review_id, '_dcb_ocr_review_capture_warning_count', true);
        $risk_bucket = sanitize_key((string) get_post_meta($review_id, '_dcb_ocr_review_capture_risk_bucket', true));
        $revisions = get_post_meta($review_id, '_dcb_ocr_review_revisions', true);
        if (!is_array($revisions)) {
            $revisions = array();
        }

        return array(
            'id' => $review_id,
            'trace_id' => sanitize_text_field((string) get_post_meta($review_id, '_dcb_ocr_review_trace_id', true)),
            'status' => sanitize_key((string) get_post_meta($review_id, '_dcb_ocr_review_status', true)),
            'created_at' => sanitize_text_field((string) get_post_meta($review_id, '_dcb_ocr_review_status_updated_at', true)),
            'capture_risk_summary' => ($risk_bucket !== '' ? ucfirst($risk_bucket) : 'n/a') . ' • ' . max(0, $warning_count) . ' warning' . (max(0, $warning_count) === 1 ? '' : 's'),
            'unresolved_ocr_risk' => (string) get_post_meta($review_id, '_dcb_ocr_review_capture_risk_unresolved', true) === '1',
            'digital_twin_label' => sanitize_text_field((string) get_post_meta($review_id, '_dcb_ocr_review_file', true)),
            'revisions' => $revisions,
        );
    }

    private static function read_submission_payload(int $submission_id, string $trace_id): array {
        if ($submission_id < 1) {
            return array('id' => 0, 'trace_id' => $trace_id);
        }

        $workflow_timeline = class_exists('DCB_Workflow') ? DCB_Workflow::get_timeline($submission_id) : array();
        if (!is_array($workflow_timeline)) {
            $workflow_timeline = array();
        }

        return array(
            'id' => $submission_id,
            'trace_id' => sanitize_text_field((string) get_post_meta($submission_id, '_dcb_intake_trace_id', true)),
            'submitted_at' => sanitize_text_field((string) get_post_meta($submission_id, '_dcb_form_submitted_at', true)),
            'workflow_status' => sanitize_key((string) get_post_meta($submission_id, '_dcb_workflow_status', true)),
            'form_key' => sanitize_key((string) get_post_meta($submission_id, '_dcb_form_key', true)),
            'form_label' => sanitize_text_field((string) get_post_meta($submission_id, '_dcb_form_label', true)),
            'source_channel' => dcb_intake_normalize_source_channel((string) get_post_meta($submission_id, '_dcb_intake_source_channel', true)),
            'capture_type' => dcb_intake_normalize_capture_type((string) get_post_meta($submission_id, '_dcb_intake_capture_type', true)),
            'is_finalized' => sanitize_key((string) get_post_meta($submission_id, '_dcb_workflow_status', true)) === 'finalized',
            'workflow_timeline' => $workflow_timeline,
        );
    }
}
