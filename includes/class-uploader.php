<?php

if (!defined('ABSPATH')) {
    exit;
}

final class DCB_Uploader {
    public static function init(): void {
        add_action('wp_ajax_dcb_upload_files', array(__CLASS__, 'upload_files_ajax'));
        add_action('dcb_ocr_review_status_changed', array(__CLASS__, 'handle_ocr_review_status_changed'), 10, 4);
    }

    public static function upload_files_ajax(): void {
        check_ajax_referer('dcb_upload_files_nonce', 'nonce');

        if (!is_user_logged_in()) {
            wp_send_json_error(array('message' => 'Not logged in.'), 403);
        }

        if (empty($_FILES['files']) || !is_array($_FILES['files'])) {
            wp_send_json_error(array('message' => 'No files provided.'), 400);
        }

        $hint = sanitize_text_field((string) ($_POST['typeHint'] ?? ''));
        $intake_channel_raw = sanitize_text_field((string) ($_POST['intakeChannel'] ?? 'direct_upload'));
        $results = array();

        $files = self::normalize_files_array((array) $_FILES['files']);
        if (empty($files)) {
            wp_send_json_error(array('message' => 'No valid files.'), 400);
        }

        require_once ABSPATH . 'wp-admin/includes/file.php';
        foreach ($files as $file) {
            $uploaded = wp_handle_upload($file, array(
                'test_form' => false,
                'mimes' => dcb_upload_allowed_mimes(),
            ));

            if (!is_array($uploaded) || isset($uploaded['error'])) {
                $results[] = array(
                    'file' => sanitize_file_name((string) ($file['name'] ?? 'file')),
                    'detectedType' => $hint,
                    'sentTo' => '',
                    'status' => 'upload_failed',
                    'error' => is_array($uploaded) ? sanitize_text_field((string) ($uploaded['error'] ?? 'Upload failed')) : 'Upload failed',
                );
                continue;
            }

            $path = (string) ($uploaded['file'] ?? '');
            $mime = (string) ($uploaded['type'] ?? '');
            $text_result = dcb_upload_extract_text_from_file($path, $mime);
            $ocr = (array) ($text_result['ocr'] ?? array());
            $confidence = isset($ocr['confidence_proxy']) ? (float) $ocr['confidence_proxy'] : 0.0;
            $review_item_id = isset($text_result['review_item_id']) ? (int) $text_result['review_item_id'] : 0;
            $capture_diagnostics = self::extract_capture_diagnostics($text_result);
            $source_channel = self::resolve_source_channel($intake_channel_raw, $mime, (string) ($capture_diagnostics['input_source_type'] ?? 'unknown'));
            $capture_type = dcb_intake_capture_type_for_channel($source_channel, $mime, (string) ($capture_diagnostics['input_source_type'] ?? 'unknown'));
            $trace_id = dcb_intake_generate_trace_id(0, $review_item_id);
            $intake_state = $review_item_id > 0 ? 'ocr_review_pending' : 'uploaded';

            $routing_target = self::resolve_recipients($hint);
            $status = 'routed';
            $error = '';

            $log_id = self::create_log_entry(array(
                'title' => sanitize_file_name((string) ($file['name'] ?? basename($path))),
                'path' => $path,
                'mime' => $mime,
                'hint' => $hint,
                'routing_target' => $routing_target,
                'ocr_confidence' => $confidence,
                'ocr_bucket' => dcb_confidence_bucket($confidence),
                'ocr_text' => (string) ($text_result['text'] ?? ''),
                'ocr_meta' => wp_json_encode($ocr),
                'ocr_review_item_id' => $review_item_id,
                'source_channel' => $source_channel,
                'capture_type' => $capture_type,
                'trace_id' => $trace_id,
                'intake_state' => $intake_state,
                'linked_submission_id' => 0,
                'uploaded_at' => current_time('mysql'),
            ));

            if ($review_item_id > 0 && $log_id > 0) {
                update_post_meta($review_item_id, '_dcb_ocr_review_upload_log_id', $log_id);
                update_post_meta($log_id, '_dcb_upload_ocr_review_item_id', $review_item_id);
                update_post_meta($review_item_id, '_dcb_ocr_review_trace_id', $trace_id);
                update_post_meta($review_item_id, '_dcb_ocr_review_source_channel', $source_channel);
                update_post_meta($review_item_id, '_dcb_ocr_review_capture_type', $capture_type);
            }

            if ($log_id > 0) {
                update_post_meta($log_id, '_dcb_upload_trace_id', $trace_id);
            }

            if ($routing_target !== '') {
                $subject = '[Document Center Upload] ' . sanitize_file_name((string) ($file['name'] ?? basename($path)));
                $body = "A file upload was received.\n\n"
                    . 'Hint: ' . $hint . "\n"
                    . 'Confidence: ' . number_format($confidence, 3) . "\n"
                    . 'Log ID: ' . $log_id . "\n";

                $to = dcb_parse_emails($routing_target);
                $sent = !empty($to) ? wp_mail($to, $subject, $body) : false;
                if (!$sent) {
                    $status = 'routed_email_failed';
                    $error = 'Could not email routing recipients.';
                }
            }

            $results[] = array(
                'file' => sanitize_file_name((string) ($file['name'] ?? basename($path))),
                'detectedType' => $hint !== '' ? $hint : (string) ($ocr['detected_type'] ?? 'unknown'),
                'sentTo' => $routing_target,
                'status' => $status,
                'confidence' => round($confidence, 3),
                'confidenceBucket' => dcb_confidence_bucket($confidence),
                'inputSourceType' => (string) ($capture_diagnostics['input_source_type'] ?? 'unknown'),
                'captureWarningCount' => (int) ($capture_diagnostics['capture_warning_count'] ?? 0),
                'captureWarnings' => isset($capture_diagnostics['capture_warnings']) && is_array($capture_diagnostics['capture_warnings']) ? $capture_diagnostics['capture_warnings'] : array(),
                'captureRecommendations' => isset($capture_diagnostics['capture_recommendations']) && is_array($capture_diagnostics['capture_recommendations']) ? $capture_diagnostics['capture_recommendations'] : array(),
                'captureRiskBucket' => (string) ($capture_diagnostics['capture_risk_bucket'] ?? 'clean'),
                'sourceChannel' => $source_channel,
                'captureType' => $capture_type,
                'intakeState' => $intake_state,
                'traceId' => $trace_id,
                'error' => $error,
                'logId' => $log_id,
                'reviewItemId' => $review_item_id,
            );

            if ($review_item_id < 1 && $path !== '' && file_exists($path)) {
                @unlink($path);
            }
        }

        wp_send_json_success(array('results' => $results));
    }

    private static function normalize_files_array(array $files): array {
        $normalized = array();
        $names = isset($files['name']) && is_array($files['name']) ? $files['name'] : array();
        $types = isset($files['type']) && is_array($files['type']) ? $files['type'] : array();
        $tmp_names = isset($files['tmp_name']) && is_array($files['tmp_name']) ? $files['tmp_name'] : array();
        $errors = isset($files['error']) && is_array($files['error']) ? $files['error'] : array();
        $sizes = isset($files['size']) && is_array($files['size']) ? $files['size'] : array();

        foreach ($names as $idx => $name) {
            $error = isset($errors[$idx]) ? (int) $errors[$idx] : UPLOAD_ERR_NO_FILE;
            if ($error !== UPLOAD_ERR_OK) {
                continue;
            }
            $normalized[] = array(
                'name' => sanitize_file_name((string) $name),
                'type' => sanitize_text_field((string) ($types[$idx] ?? 'application/octet-stream')),
                'tmp_name' => (string) ($tmp_names[$idx] ?? ''),
                'error' => $error,
                'size' => isset($sizes[$idx]) ? (int) $sizes[$idx] : 0,
            );
        }

        return $normalized;
    }

    private static function resolve_recipients(string $hint): string {
        $default = (string) get_option('dcb_upload_default_recipients', '');
        if ($hint === '') {
            return $default;
        }

        $profiles = get_option('dcb_upload_form_profiles', array());
        if (!is_array($profiles)) {
            return $default;
        }

        foreach ($profiles as $profile) {
            if (!is_array($profile)) {
                continue;
            }
            $name = sanitize_text_field((string) ($profile['name'] ?? ''));
            if ($name !== '' && strtolower($name) === strtolower($hint)) {
                $recipients = (string) ($profile['recipients'] ?? '');
                if ($recipients !== '') {
                    return $recipients;
                }
            }
        }

        return $default;
    }

    private static function create_log_entry(array $data): int {
        $title = sanitize_text_field((string) ($data['title'] ?? 'Upload'));
        $id = wp_insert_post(array(
            'post_type' => 'dcb_upload_log',
            'post_status' => 'publish',
            'post_title' => $title . ' — ' . current_time('mysql'),
        ));

        if (is_wp_error($id) || (int) $id < 1) {
            return 0;
        }

        foreach ($data as $key => $value) {
            update_post_meta((int) $id, '_dcb_upload_' . sanitize_key((string) $key), is_scalar($value) ? (string) $value : wp_json_encode($value));
        }

        update_post_meta((int) $id, '_dcb_upload_user_id', get_current_user_id());
        update_post_meta((int) $id, '_dcb_upload_created_at', current_time('mysql'));

        return (int) $id;
    }

    private static function extract_capture_diagnostics(array $text_result): array {
        $normalization = isset($text_result['input_normalization']) && is_array($text_result['input_normalization'])
            ? $text_result['input_normalization']
            : array();

        $warnings_raw = isset($normalization['warnings']) && is_array($normalization['warnings']) ? $normalization['warnings'] : array();
        $warnings = array();
        foreach ($warnings_raw as $row) {
            if (!is_array($row)) {
                continue;
            }
            $message = sanitize_text_field((string) ($row['message'] ?? ''));
            if ($message !== '') {
                $warnings[] = $message;
            }
        }

        $recommendations_raw = isset($normalization['capture_recommendations']) && is_array($normalization['capture_recommendations'])
            ? $normalization['capture_recommendations']
            : array();
        $recommendations = array();
        foreach ($recommendations_raw as $tip) {
            $tip = sanitize_text_field((string) $tip);
            if ($tip !== '') {
                $recommendations[] = $tip;
            }
        }

        $warning_count = count($warnings);
        $risk_bucket = function_exists('dcb_ocr_capture_risk_bucket') ? dcb_ocr_capture_risk_bucket($warning_count) : ($warning_count > 0 ? 'moderate' : 'clean');

        return array(
            'input_source_type' => sanitize_key((string) ($text_result['input_source_type'] ?? 'unknown')),
            'capture_warning_count' => $warning_count,
            'capture_warnings' => array_values(array_unique($warnings)),
            'capture_recommendations' => array_values(array_unique($recommendations)),
            'capture_risk_bucket' => sanitize_key((string) $risk_bucket),
        );
    }

    private static function resolve_source_channel(string $requested, string $mime, string $source_type): string {
        $channel = dcb_intake_normalize_source_channel($requested);
        $mime = strtolower(trim($mime));
        $source_type = sanitize_key($source_type);

        if ($channel === 'direct_upload') {
            if ($source_type === 'photo' || strpos($mime, 'image/') === 0) {
                return 'phone_photo';
            }
            if ($source_type === 'pdf' || strpos($mime, 'application/pdf') === 0) {
                return 'scanned_pdf';
            }
        }

        return $channel;
    }

    public static function handle_ocr_review_status_changed(int $review_id, string $from_status, string $to_status, string $note): void {
        $review_id = max(0, $review_id);
        if ($review_id < 1) {
            return;
        }

        $log_id = (int) get_post_meta($review_id, '_dcb_ocr_review_upload_log_id', true);
        if ($log_id < 1) {
            return;
        }

        $workflow_status = sanitize_key((string) get_post_meta($log_id, '_dcb_upload_linked_workflow_status', true));
        $new_state = dcb_intake_state_from_statuses($workflow_status, $to_status);
        update_post_meta($log_id, '_dcb_upload_intake_state', $new_state);
        update_post_meta($log_id, '_dcb_upload_last_status_note', sanitize_text_field($note));
    }
}
