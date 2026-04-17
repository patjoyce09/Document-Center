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
