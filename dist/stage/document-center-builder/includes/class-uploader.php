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
        $uploader_user_id = get_current_user_id();

        $file_batches = self::normalize_files_array((array) $_FILES['files']);
        $files = isset($file_batches['valid']) && is_array($file_batches['valid']) ? $file_batches['valid'] : array();
        $failed_files = isset($file_batches['failed']) && is_array($file_batches['failed']) ? $file_batches['failed'] : array();

        foreach ($failed_files as $failed) {
            if (!is_array($failed)) {
                continue;
            }
            $failed_file = sanitize_file_name((string) ($failed['file'] ?? 'file'));
            $failed_error = sanitize_text_field((string) ($failed['error'] ?? 'Upload failed before processing.'));
            $results[] = array(
                'file' => $failed_file,
                'detectedType' => $hint,
                'sentTo' => '',
                'status' => 'upload_failed',
                'error' => $failed_error,
            );
            self::notify_uploader_failure(
                $uploader_user_id,
                $failed_file,
                $failed_error,
                array('Please retry with fewer files in one upload if this keeps happening.')
            );
        }

        if (empty($files) && empty($results)) {
            wp_send_json_error(array('message' => 'No valid files.'), 400);
        }

        require_once ABSPATH . 'wp-admin/includes/file.php';
        foreach ($files as $file) {
            $uploaded = wp_handle_upload($file, array(
                'test_form' => false,
                'mimes' => dcb_upload_allowed_mimes(),
            ));

            if (!is_array($uploaded) || isset($uploaded['error'])) {
                $failed_file = sanitize_file_name((string) ($file['name'] ?? 'file'));
                $failed_error = is_array($uploaded) ? sanitize_text_field((string) ($uploaded['error'] ?? 'Upload failed')) : 'Upload failed';
                $results[] = array(
                    'file' => $failed_file,
                    'detectedType' => $hint,
                    'sentTo' => '',
                    'status' => 'upload_failed',
                    'error' => $failed_error,
                );
                self::notify_uploader_failure(
                    $uploader_user_id,
                    $failed_file,
                    $failed_error,
                    array('Please retry with fewer files in one upload if this keeps happening.')
                );
                continue;
            }

            $path = (string) ($uploaded['file'] ?? '');
            $mime = (string) ($uploaded['type'] ?? '');
            $text_result = dcb_upload_extract_text_from_file($path, $mime);
            $ocr = isset($text_result['ocr']) && is_array($text_result['ocr'])
                ? (array) $text_result['ocr']
                : (is_array($text_result) ? (array) $text_result : array());
            $confidence = isset($ocr['confidence_proxy']) && is_numeric($ocr['confidence_proxy'])
                ? (float) $ocr['confidence_proxy']
                : (isset($text_result['confidence_proxy']) && is_numeric($text_result['confidence_proxy']) ? (float) $text_result['confidence_proxy'] : 0.0);
            $review_item_id = isset($text_result['review_item_id']) ? (int) $text_result['review_item_id'] : 0;
            $capture_diagnostics = self::extract_capture_diagnostics($text_result);
            $source_channel = self::resolve_source_channel($intake_channel_raw, $mime, (string) ($capture_diagnostics['input_source_type'] ?? 'unknown'));
            $capture_type = dcb_intake_capture_type_for_channel($source_channel, $mime, (string) ($capture_diagnostics['input_source_type'] ?? 'unknown'));
            $trace_id = dcb_intake_generate_trace_id(0, $review_item_id);
            $ocr_text = (string) ($text_result['text'] ?? '');
            $quality_gate = self::evaluate_quality_gate($ocr, $capture_diagnostics, $confidence, $ocr_text);

            $routing_target = '';
            $routing_source = 'quality_gate';
            $routing_match = '';
            $status = 'quality_gate_failed';
            $error = sanitize_text_field((string) ($quality_gate['message'] ?? 'OCR quality check failed.'));
            $intake_state = 'quality_failed';

            if (empty($quality_gate['failed'])) {
                $routing = self::resolve_recipients($hint, $ocr, $confidence);
                $routing_target = (string) ($routing['recipients'] ?? '');
                $routing_source = sanitize_key((string) ($routing['source'] ?? 'default'));
                $routing_match = sanitize_text_field((string) ($routing['matched'] ?? ''));
                $status = 'routed';
                $error = '';
                $intake_state = $review_item_id > 0 ? 'ocr_review_pending' : 'uploaded';
            }

            $log_id = self::create_log_entry(array(
                'title' => sanitize_file_name((string) ($file['name'] ?? basename($path))),
                'path' => $path,
                'mime' => $mime,
                'hint' => $hint,
                'resolved_hint' => $routing_match,
                'routing_source' => $routing_source,
                'routing_target' => $routing_target,
                'ocr_confidence' => $confidence,
                'ocr_bucket' => dcb_confidence_bucket($confidence),
                'ocr_text' => $ocr_text,
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

                if (function_exists('do_action')) {
                    do_action('dcb_upload_artifact_logged', (int) $log_id, array(
                        'review_item_id' => $review_item_id,
                        'trace_id' => $trace_id,
                        'source_channel' => $source_channel,
                        'capture_type' => $capture_type,
                        'hint' => $hint,
                        'ocr_text' => (string) ($text_result['text'] ?? ''),
                        'ocr_meta' => $ocr,
                    ));
                }
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
            } elseif (!empty($quality_gate['failed'])) {
                $recommendations = isset($quality_gate['recommendations']) && is_array($quality_gate['recommendations'])
                    ? $quality_gate['recommendations']
                    : array();
                self::notify_uploader_failure(
                    $uploader_user_id,
                    sanitize_file_name((string) ($file['name'] ?? basename($path))),
                    $error,
                    $recommendations,
                    $log_id
                );
            }

            $results[] = array(
                'file' => sanitize_file_name((string) ($file['name'] ?? basename($path))),
                'detectedType' => $hint !== '' ? $hint : (string) ($ocr['detected_type'] ?? 'unknown'),
                'sentTo' => $routing_target,
                'routingSource' => $routing_source,
                'routingMatch' => $routing_match,
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
        $failed = array();
        $names = isset($files['name']) && is_array($files['name']) ? $files['name'] : array();
        $types = isset($files['type']) && is_array($files['type']) ? $files['type'] : array();
        $tmp_names = isset($files['tmp_name']) && is_array($files['tmp_name']) ? $files['tmp_name'] : array();
        $errors = isset($files['error']) && is_array($files['error']) ? $files['error'] : array();
        $sizes = isset($files['size']) && is_array($files['size']) ? $files['size'] : array();

        foreach ($names as $idx => $name) {
            $safe_name = sanitize_file_name((string) $name);
            $error = isset($errors[$idx]) ? (int) $errors[$idx] : UPLOAD_ERR_NO_FILE;
            if ($error !== UPLOAD_ERR_OK) {
                $failed[] = array(
                    'file' => $safe_name,
                    'error' => self::file_upload_error_message($error),
                );
                continue;
            }

            $tmp_name = (string) ($tmp_names[$idx] ?? '');
            if ($tmp_name === '') {
                $failed[] = array(
                    'file' => $safe_name,
                    'error' => 'Temporary upload file was missing.',
                );
                continue;
            }

            $normalized[] = array(
                'name' => $safe_name,
                'type' => sanitize_text_field((string) ($types[$idx] ?? 'application/octet-stream')),
                'tmp_name' => $tmp_name,
                'error' => $error,
                'size' => isset($sizes[$idx]) ? (int) $sizes[$idx] : 0,
            );
        }

        return array(
            'valid' => $normalized,
            'failed' => $failed,
        );
    }

    private static function file_upload_error_message(int $code): string {
        switch ($code) {
            case UPLOAD_ERR_INI_SIZE:
            case UPLOAD_ERR_FORM_SIZE:
                return 'File exceeds upload size limit.';
            case UPLOAD_ERR_PARTIAL:
                return 'File was only partially uploaded.';
            case UPLOAD_ERR_NO_FILE:
                return 'No file was uploaded.';
            case UPLOAD_ERR_NO_TMP_DIR:
                return 'Server temporary upload folder is missing.';
            case UPLOAD_ERR_CANT_WRITE:
                return 'Server could not write uploaded file.';
            case UPLOAD_ERR_EXTENSION:
                return 'Upload blocked by a server extension.';
            default:
                return 'Upload failed before processing.';
        }
    }

    private static function evaluate_quality_gate(array $ocr, array $capture_diagnostics, float $confidence, string $ocr_text): array {
        $warning_count = max(0, (int) ($capture_diagnostics['capture_warning_count'] ?? 0));
        $routing_decision = sanitize_key((string) ($ocr['page_quality_routing']['routing_decision'] ?? ''));
        $failure_reason = function_exists('dcb_ocr_normalize_failure_reason')
            ? dcb_ocr_normalize_failure_reason((string) ($ocr['failure_reason'] ?? ''))
            : sanitize_key((string) ($ocr['failure_reason'] ?? ''));
        $text_length = strlen(trim($ocr_text));
        $min_conf = max(0.0, min(1.0, (float) get_option('dcb_upload_min_confidence', 0.45)));
        $recommendations = isset($capture_diagnostics['capture_recommendations']) && is_array($capture_diagnostics['capture_recommendations'])
            ? $capture_diagnostics['capture_recommendations']
            : array();

        if ($routing_decision === 'low_quality_review_recommended' || $failure_reason === 'empty_extraction') {
            return array(
                'failed' => true,
                'message' => 'OCR quality check failed: image was not readable enough to route safely.',
                'recommendations' => $recommendations,
            );
        }

        if ($warning_count >= 2 && ($confidence < $min_conf || $text_length < 40)) {
            return array(
                'failed' => true,
                'message' => 'OCR quality check failed: low image quality (blur, lighting, contrast, or crop) prevented reliable extraction.',
                'recommendations' => $recommendations,
            );
        }

        if (in_array($failure_reason, array('rasterization_failed', 'parse_failed', 'unsupported_mime', 'max_file_size_exceeded', 'extraction_timeout', 'unknown'), true)) {
            return array(
                'failed' => true,
                'message' => 'OCR processing failed before routing this file.',
                'recommendations' => $recommendations,
            );
        }

        return array('failed' => false, 'message' => '', 'recommendations' => array());
    }

    private static function notify_uploader_failure(int $user_id, string $file_name, string $reason, array $recommendations = array(), int $log_id = 0): void {
        $user_id = max(0, $user_id);
        if ($user_id < 1) {
            return;
        }

        $user = get_userdata($user_id);
        if (!$user instanceof WP_User) {
            return;
        }

        $to = dcb_parse_emails((string) ($user->user_email ?? ''));
        if (empty($to)) {
            return;
        }

        $safe_file = sanitize_file_name($file_name);
        $safe_reason = sanitize_text_field($reason);
        $subject = '[Document Center Upload] File needs a better copy';
        $body = "One of your uploaded files could not be routed.\n\n"
            . 'File: ' . $safe_file . "\n"
            . 'Reason: ' . $safe_reason . "\n";

        if ($log_id > 0) {
            $body .= 'Log ID: ' . (int) $log_id . "\n";
        }

        $body .= "\nWhat to do next:\n"
            . "- Re-upload a clearer image/PDF (good lighting, in focus, full page visible).\n"
            . "- If this keeps failing, choose the document type from the upload dropdown so routing can be assisted.\n";

        $tips = array_values(array_unique(array_filter(array_map('sanitize_text_field', $recommendations))));
        if (!empty($tips)) {
            $body .= "\nCapture tips:\n";
            foreach (array_slice($tips, 0, 4) as $tip) {
                $body .= '- ' . $tip . "\n";
            }
        }

        wp_mail($to, $subject, $body);
    }

    private static function resolve_recipients(string $hint, array $ocr = array(), float $confidence = 0.0): array {
        $default = (string) get_option('dcb_upload_default_recipients', '');
        $profiles = get_option('dcb_upload_form_profiles', array());
        if (!is_array($profiles)) {
            $profiles = array();
        }

        $signals = array();
        $hint = trim($hint);
        if ($hint !== '') {
            $signals[] = array('value' => $hint, 'source' => 'hint_exact');
        }

        $detected_type = sanitize_key((string) ($ocr['detected_type'] ?? ''));
        if ($detected_type !== '' && $detected_type !== 'unknown') {
            $signals[] = array('value' => $detected_type, 'source' => 'ocr_detected_type');
        }

        $source_classification = sanitize_key((string) ($ocr['source_classification'] ?? ''));
        if ($source_classification !== '' && $source_classification !== 'unknown') {
            $signals[] = array('value' => $source_classification, 'source' => 'ocr_source_classification');
        }

        $min_conf = max(0.0, min(1.0, (float) get_option('dcb_upload_min_confidence', 0.45)));
        if ($confidence < $min_conf && $hint === '') {
            return array(
                'recipients' => $default,
                'source' => 'default_low_confidence',
                'matched' => '',
            );
        }

        foreach ($signals as $signal) {
            $match = self::match_profile_recipients((string) ($signal['value'] ?? ''), $profiles);
            if (!empty($match['recipients'])) {
                return array(
                    'recipients' => (string) $match['recipients'],
                    'source' => sanitize_key((string) ($signal['source'] ?? 'matched')),
                    'matched' => sanitize_text_field((string) ($match['matched'] ?? '')),
                );
            }
        }

        if ($hint === '' && $detected_type !== '' && $detected_type !== 'unknown') {
            $heuristic = self::heuristic_profile_match($detected_type, $profiles);
            if (!empty($heuristic['recipients'])) {
                return array(
                    'recipients' => (string) $heuristic['recipients'],
                    'source' => 'ocr_heuristic',
                    'matched' => sanitize_text_field((string) ($heuristic['matched'] ?? '')),
                );
            }
        }

        return array(
            'recipients' => $default,
            'source' => 'default',
            'matched' => '',
        );
    }

    private static function normalize_match_key(string $value): string {
        $value = strtolower(trim($value));
        if ($value === '') {
            return '';
        }
        $value = preg_replace('/[^a-z0-9]+/i', '_', $value);
        $value = is_string($value) ? trim($value, '_') : '';
        return sanitize_key($value);
    }

    private static function match_profile_recipients(string $signal, array $profiles): array {
        $signal_raw = strtolower(trim($signal));
        $signal_key = self::normalize_match_key($signal);
        if ($signal_raw === '' || $signal_key === '') {
            return array();
        }

        foreach ($profiles as $profile) {
            if (!is_array($profile)) {
                continue;
            }
            $name = sanitize_text_field((string) ($profile['name'] ?? ''));
            $recipients = (string) ($profile['recipients'] ?? '');
            if ($name === '' || $recipients === '') {
                continue;
            }

            $name_raw = strtolower(trim($name));
            $name_key = self::normalize_match_key($name);
            if ($name_raw === $signal_raw || $name_key === $signal_key) {
                return array('recipients' => $recipients, 'matched' => $name);
            }

            $profile_key = sanitize_key((string) ($profile['key'] ?? $profile['slug'] ?? ''));
            if ($profile_key !== '' && $profile_key === $signal_key) {
                return array('recipients' => $recipients, 'matched' => $name);
            }

            $aliases = array();
            if (isset($profile['aliases']) && is_array($profile['aliases'])) {
                $aliases = $profile['aliases'];
            } elseif (isset($profile['aliases']) && is_string($profile['aliases'])) {
                $aliases = explode(',', (string) $profile['aliases']);
            }
            foreach ($aliases as $alias) {
                $alias_key = self::normalize_match_key((string) $alias);
                if ($alias_key !== '' && $alias_key === $signal_key) {
                    return array('recipients' => $recipients, 'matched' => $name);
                }
            }
        }

        return array();
    }

    private static function heuristic_profile_match(string $detected_type, array $profiles): array {
        $type = sanitize_key($detected_type);
        if ($type === '') {
            return array();
        }

        $token_map = array(
            'consent' => array('consent', 'authorization'),
            'intake' => array('intake', 'admission'),
            'physician_order' => array('order', 'physician'),
            'visit_note' => array('visit', 'note', 'communication'),
            'eval' => array('eval', 'evaluation', 'assessment'),
        );

        $tokens = isset($token_map[$type]) ? $token_map[$type] : array($type);
        foreach ($profiles as $profile) {
            if (!is_array($profile)) {
                continue;
            }
            $name = sanitize_text_field((string) ($profile['name'] ?? ''));
            $recipients = (string) ($profile['recipients'] ?? '');
            if ($name === '' || $recipients === '') {
                continue;
            }

            $name_key = self::normalize_match_key($name);
            foreach ($tokens as $token) {
                $token = sanitize_key((string) $token);
                if ($token !== '' && strpos($name_key, $token) !== false) {
                    return array('recipients' => $recipients, 'matched' => $name);
                }
            }
        }

        return array();
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
