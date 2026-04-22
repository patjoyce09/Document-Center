<?php

if (!defined('ABSPATH')) {
    exit;
}

function dcb_intake_channel_model(): array {
    return array(
        'direct_upload' => 'Direct Upload',
        'phone_photo' => 'Phone Photo/Image',
        'scanned_pdf' => 'Scanned PDF',
        'email_import' => 'Emailed Attachment / Imported File',
        'digital_only' => 'Manually Created Digital-Only Form',
    );
}

function dcb_intake_normalize_source_channel(string $channel): string {
    $raw = sanitize_key($channel);
    $map = array(
        'auto_detect' => 'direct_upload',
        'auto' => 'direct_upload',
        'direct_upload' => 'direct_upload',
        'direct' => 'direct_upload',
        'upload' => 'direct_upload',
        'phone_photo' => 'phone_photo',
        'photo' => 'phone_photo',
        'image' => 'phone_photo',
        'scanned_pdf' => 'scanned_pdf',
        'scan_pdf' => 'scanned_pdf',
        'pdf_scan' => 'scanned_pdf',
        'email_import' => 'email_import',
        'email' => 'email_import',
        'emailed_attachment' => 'email_import',
        'imported_file' => 'email_import',
        'digital_only' => 'digital_only',
        'manual_digital' => 'digital_only',
    );

    $normalized = $map[$raw] ?? 'direct_upload';
    return function_exists('apply_filters') ? (string) apply_filters('dcb_intake_source_channel', $normalized, $channel) : $normalized;
}

function dcb_intake_normalize_capture_type(string $capture_type): string {
    $capture_type = sanitize_key($capture_type);
    $allowed = array('direct_file', 'photo_image', 'scan_pdf', 'email_attachment', 'digital_manual', 'unknown');
    return in_array($capture_type, $allowed, true) ? $capture_type : 'unknown';
}

function dcb_intake_capture_type_for_channel(string $source_channel, string $mime = '', string $source_type = ''): string {
    $channel = dcb_intake_normalize_source_channel($source_channel);
    $mime = strtolower(trim($mime));
    $source_type = sanitize_key($source_type);

    if ($channel === 'digital_only') {
        return 'digital_manual';
    }
    if ($channel === 'email_import') {
        return 'email_attachment';
    }
    if ($channel === 'phone_photo' || $source_type === 'photo' || strpos($mime, 'image/') === 0) {
        return 'photo_image';
    }
    if ($channel === 'scanned_pdf' || strpos($mime, 'application/pdf') === 0 || $source_type === 'pdf' || $source_type === 'scan') {
        return 'scan_pdf';
    }

    return 'direct_file';
}

function dcb_intake_state_from_statuses(string $workflow_status, string $review_status = ''): string {
    $workflow_status = sanitize_key($workflow_status);
    $review_status = sanitize_key($review_status);

    if ($workflow_status === 'finalized') {
        return 'finalized';
    }
    if ($workflow_status === 'approved') {
        return 'approved';
    }
    if ($workflow_status === 'needs_correction') {
        return 'returned_for_correction';
    }
    if (in_array($review_status, array('pending_review', 'reprocessed'), true)) {
        return 'ocr_review_pending';
    }
    if ($review_status === 'corrected') {
        return 'correction_in_review';
    }
    if ($workflow_status === 'rejected' || $review_status === 'rejected') {
        return 'rejected';
    }
    if ($workflow_status !== '') {
        return 'submitted';
    }

    return 'uploaded';
}

function dcb_intake_state_label(string $state): string {
    $state = sanitize_key($state);
    $labels = array(
        'uploaded' => 'Uploaded',
        'ocr_review_pending' => 'OCR Review Pending',
        'correction_in_review' => 'Correction in Review',
        'returned_for_correction' => 'Returned for Correction',
        'submitted' => 'Submitted',
        'approved' => 'Approved',
        'finalized' => 'Finalized',
        'rejected' => 'Rejected',
    );
    return $labels[$state] ?? ucfirst(str_replace('_', ' ', $state));
}

function dcb_intake_generate_trace_id(int $upload_log_id = 0, int $review_item_id = 0): string {
    $seed = $upload_log_id . '|' . $review_item_id . '|' . current_time('mysql');
    return 'dcb-intake-' . substr(hash('sha256', $seed), 0, 20);
}

function dcb_intake_build_traceability_summary(array $payload): array {
    $source_channel = dcb_intake_normalize_source_channel((string) ($payload['source_channel'] ?? 'direct_upload'));
    $capture_type = dcb_intake_normalize_capture_type((string) ($payload['capture_type'] ?? 'unknown'));
    $state = sanitize_key((string) ($payload['current_state'] ?? 'uploaded'));

    return array(
        'trace_id' => sanitize_text_field((string) ($payload['trace_id'] ?? '')),
        'source_channel' => $source_channel,
        'capture_type' => $capture_type,
        'upload_log_id' => max(0, (int) ($payload['upload_log_id'] ?? 0)),
        'review_item_id' => max(0, (int) ($payload['review_item_id'] ?? 0)),
        'submission_id' => max(0, (int) ($payload['submission_id'] ?? 0)),
        'workflow_status' => sanitize_key((string) ($payload['workflow_status'] ?? '')),
        'review_status' => sanitize_key((string) ($payload['review_status'] ?? '')),
        'current_state' => $state,
        'current_state_label' => dcb_intake_state_label($state),
        'routing_status' => sanitize_text_field((string) ($payload['routing_status'] ?? 'queued')),
        'final_output_status' => sanitize_text_field((string) ($payload['final_output_status'] ?? 'draft')),
    );
}

function dcb_resource_center_status_model(array $forms, array $required_form_keys, array $submitted_states, array $upload_states): array {
    $required_lookup = array_fill_keys(array_values(array_filter(array_map('sanitize_key', $required_form_keys))), true);
    $rows = array();

    foreach ($forms as $form_key => $form) {
        $key = sanitize_key((string) $form_key);
        if ($key === '') {
            continue;
        }

        $workflow_status = sanitize_key((string) ($submitted_states[$key]['workflow_status'] ?? ''));
        $review_status = sanitize_key((string) ($submitted_states[$key]['review_status'] ?? ''));
        $upload_count = max(0, (int) ($upload_states[$key]['count'] ?? 0));
        $state = dcb_intake_state_from_statuses($workflow_status, $review_status);

        if ($workflow_status === '' && !empty($upload_states[$key]['latest_state'])) {
            $state = sanitize_key((string) $upload_states[$key]['latest_state']);
        }

        $rows[$key] = array(
            'form_key' => $key,
            'form_label' => sanitize_text_field((string) ($form['label'] ?? $key)),
            'requirement' => isset($required_lookup[$key]) ? 'required' : 'optional',
            'upload_count' => $upload_count,
            'state' => $state,
            'state_label' => dcb_intake_state_label($state),
            'needs_correction' => $state === 'returned_for_correction' || $state === 'correction_in_review',
            'is_finalized' => $state === 'approved' || $state === 'finalized',
            'latest_upload_log_id' => max(0, (int) ($upload_states[$key]['latest_upload_log_id'] ?? 0)),
            'latest_review_id' => max(0, (int) ($upload_states[$key]['latest_review_id'] ?? 0)),
            'latest_trace_id' => sanitize_text_field((string) ($upload_states[$key]['latest_trace_id'] ?? '')),
            'latest_source_channel' => sanitize_key((string) ($upload_states[$key]['latest_source_channel'] ?? '')),
            'latest_capture_type' => sanitize_key((string) ($upload_states[$key]['latest_capture_type'] ?? '')),
        );
    }

    $missing_required = array_values(array_filter($rows, static function ($row) {
        return is_array($row)
            && (string) ($row['requirement'] ?? 'optional') === 'required'
            && !in_array((string) ($row['state'] ?? 'uploaded'), array('submitted', 'approved', 'finalized'), true);
    }));

    return array(
        'rows' => array_values($rows),
        'summary' => array(
            'required_count' => count(array_filter($rows, static function ($row) {
                return is_array($row) && (string) ($row['requirement'] ?? 'optional') === 'required';
            })),
            'missing_required_count' => count($missing_required),
            'correction_count' => count(array_filter($rows, static function ($row) {
                return is_array($row) && !empty($row['needs_correction']);
            })),
            'approved_count' => count(array_filter($rows, static function ($row) {
                return is_array($row) && !empty($row['is_finalized']);
            })),
        ),
    );
}

function dcb_intake_trace_admin_url(string $trace_id): string {
    $trace_id = sanitize_text_field($trace_id);
    if ($trace_id === '') {
        return function_exists('admin_url') ? admin_url('admin.php?page=dcb-intake-trace') : 'admin.php?page=dcb-intake-trace';
    }

    $query = 'admin.php?page=dcb-intake-trace&trace_id=' . rawurlencode($trace_id);
    return function_exists('admin_url') ? admin_url($query) : $query;
}

function dcb_intake_trace_resolve_linked_ids_from_payload(array $payload): array {
    $upload_id = max(0, (int) ($payload['upload_log_id'] ?? 0));
    $review_id = max(0, (int) ($payload['review_item_id'] ?? 0));
    $submission_id = max(0, (int) ($payload['submission_id'] ?? 0));

    if ($review_id < 1 && isset($payload['review']) && is_array($payload['review'])) {
        $review_id = max(0, (int) ($payload['review']['id'] ?? 0));
    }
    if ($upload_id < 1 && isset($payload['upload']) && is_array($payload['upload'])) {
        $upload_id = max(0, (int) ($payload['upload']['id'] ?? 0));
    }
    if ($submission_id < 1 && isset($payload['submission']) && is_array($payload['submission'])) {
        $submission_id = max(0, (int) ($payload['submission']['id'] ?? 0));
    }

    return array(
        'upload_log_id' => $upload_id,
        'review_item_id' => $review_id,
        'submission_id' => $submission_id,
    );
}

function dcb_intake_trace_current_state_summary(array $payload): array {
    $trace_id = sanitize_text_field((string) ($payload['trace_id'] ?? ''));
    $source_channel = dcb_intake_normalize_source_channel((string) ($payload['source_channel'] ?? 'direct_upload'));
    $capture_type = dcb_intake_normalize_capture_type((string) ($payload['capture_type'] ?? 'unknown'));
    $workflow_status = sanitize_key((string) ($payload['workflow_status'] ?? ''));
    $review_status = sanitize_key((string) ($payload['review_status'] ?? ''));
    $current_state = sanitize_key((string) ($payload['current_state'] ?? dcb_intake_state_from_statuses($workflow_status, $review_status)));

    return array(
        'trace_id' => $trace_id,
        'source_channel' => $source_channel,
        'capture_type' => $capture_type,
        'workflow_status' => $workflow_status,
        'review_status' => $review_status,
        'current_state' => $current_state,
        'current_state_label' => dcb_intake_state_label($current_state),
        'unresolved_ocr_risk' => !empty($payload['unresolved_ocr_risk']),
        'linked_ids' => dcb_intake_trace_resolve_linked_ids_from_payload($payload),
    );
}

function dcb_intake_trace_build_timeline_events(array $payload): array {
    $events = array();

    $upload = isset($payload['upload']) && is_array($payload['upload']) ? $payload['upload'] : array();
    if (!empty($upload)) {
        $events[] = array(
            'time' => sanitize_text_field((string) ($upload['uploaded_at'] ?? $upload['created_at'] ?? '')),
            'event' => 'uploaded',
            'label' => 'Artifact uploaded',
            'details' => sanitize_text_field((string) ($upload['file_name'] ?? 'Original artifact captured')),
        );
    }

    $review = isset($payload['review']) && is_array($payload['review']) ? $payload['review'] : array();
    if (!empty($review)) {
        $events[] = array(
            'time' => sanitize_text_field((string) ($review['created_at'] ?? '')),
            'event' => 'ocr_queued',
            'label' => 'OCR review queued',
            'details' => sanitize_text_field((string) ($review['status'] ?? 'pending_review')),
        );

        $revisions = isset($review['revisions']) && is_array($review['revisions']) ? $review['revisions'] : array();
        foreach ($revisions as $row) {
            if (!is_array($row)) {
                continue;
            }
            $event_key = sanitize_key((string) ($row['event'] ?? 'review_event'));
            $event_map = array(
                'manual_correction_saved' => 'correction_requested',
                'status_changed' => 'ocr_reviewed',
                'reprocessed' => 'ocr_reviewed',
            );
            $mapped = $event_map[$event_key] ?? $event_key;
            $events[] = array(
                'time' => sanitize_text_field((string) ($row['time'] ?? '')),
                'event' => $mapped,
                'label' => ucfirst(str_replace('_', ' ', $mapped)),
                'details' => sanitize_text_field((string) ($row['actor_name'] ?? '')),
            );
        }
    }

    $submission = isset($payload['submission']) && is_array($payload['submission']) ? $payload['submission'] : array();
    if (!empty($submission)) {
        $events[] = array(
            'time' => sanitize_text_field((string) ($submission['submitted_at'] ?? '')),
            'event' => 'resubmitted',
            'label' => 'Submission created',
            'details' => sanitize_text_field((string) ($submission['workflow_status'] ?? 'submitted')),
        );

        $timeline = isset($submission['workflow_timeline']) && is_array($submission['workflow_timeline']) ? $submission['workflow_timeline'] : array();
        foreach ($timeline as $row) {
            if (!is_array($row)) {
                continue;
            }
            $event_key = sanitize_key((string) ($row['event'] ?? 'workflow_event'));
            $map = array(
                'correction_requested' => 'correction_requested',
                'approved' => 'approved',
                'finalized' => 'finalized',
                'submitted' => 'resubmitted',
            );
            $mapped = $map[$event_key] ?? $event_key;
            $events[] = array(
                'time' => sanitize_text_field((string) ($row['time'] ?? '')),
                'event' => $mapped,
                'label' => ucfirst(str_replace('_', ' ', $mapped)),
                'details' => sanitize_text_field((string) ($row['actor_name'] ?? '')),
            );
        }
    }

    usort($events, static function ($a, $b) {
        $at = (string) ($a['time'] ?? '');
        $bt = (string) ($b['time'] ?? '');
        if ($at === $bt) {
            return 0;
        }
        if ($at === '') {
            return 1;
        }
        if ($bt === '') {
            return -1;
        }
        return strcmp($at, $bt);
    });

    return $events;
}

function dcb_intake_trace_build_payload(array $payload): array {
    $summary = dcb_intake_trace_current_state_summary($payload);
    $events = dcb_intake_trace_build_timeline_events($payload);
    $out = array(
        'summary' => $summary,
        'events' => $events,
        'linked_ids' => isset($summary['linked_ids']) && is_array($summary['linked_ids']) ? $summary['linked_ids'] : array(),
        'upload' => isset($payload['upload']) && is_array($payload['upload']) ? $payload['upload'] : array(),
        'review' => isset($payload['review']) && is_array($payload['review']) ? $payload['review'] : array(),
        'submission' => isset($payload['submission']) && is_array($payload['submission']) ? $payload['submission'] : array(),
        'digital_twin' => isset($payload['digital_twin']) && is_array($payload['digital_twin']) ? $payload['digital_twin'] : array(),
        'final_output' => isset($payload['final_output']) && is_array($payload['final_output']) ? $payload['final_output'] : array(),
    );

    return function_exists('apply_filters') ? (array) apply_filters('dcb_intake_trace_timeline_payload', $out, $payload) : $out;
}
