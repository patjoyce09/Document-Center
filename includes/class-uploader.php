<?php

if (!defined('ABSPATH')) {
    exit;
}

final class DCB_Uploader {
    public static function init(): void {
        add_action('wp_ajax_dcb_upload_files', array(__CLASS__, 'upload_files_ajax'));
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
            if ($confidence <= 0.0 && isset($text_result['confidence']) && is_numeric($text_result['confidence'])) {
                $confidence = (float) $text_result['confidence'];
            }
            $provenance = isset($text_result['provenance']) && is_array($text_result['provenance']) ? $text_result['provenance'] : array();

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
                'ocr_failure_reason' => sanitize_key((string) ($text_result['failure_reason'] ?? '')),
                'ocr_request_id' => sanitize_text_field((string) ($provenance['request_id'] ?? '')),
                'ocr_provider' => sanitize_text_field((string) ($provenance['provider'] ?? ($text_result['provider'] ?? 'local'))),
                'ocr_provider_version' => sanitize_text_field((string) ($provenance['provider_version'] ?? '')),
                'ocr_contract_version' => sanitize_text_field((string) ($provenance['contract_version'] ?? '')),
                'ocr_engine_used' => sanitize_text_field((string) ($provenance['engine_used'] ?? ($text_result['engine_used'] ?? $text_result['engine'] ?? ''))),
                'ocr_timings' => wp_json_encode((array) ($provenance['timings'] ?? ($text_result['timings'] ?? array()))),
                'ocr_mode' => sanitize_key((string) ($provenance['mode'] ?? 'local')),
            ));

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
                'error' => $error,
                'logId' => $log_id,
            );

            if ($path !== '' && file_exists($path)) {
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
}
