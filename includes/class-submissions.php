<?php

if (!defined('ABSPATH')) {
    exit;
}

final class DCB_Submissions {
    public static function init(): void {
        add_action('init', array(__CLASS__, 'register_post_types'), 21);
        add_action('wp_ajax_dcb_save_digital_form_draft', array(__CLASS__, 'save_draft_ajax'));
        add_action('wp_ajax_dcb_get_digital_form_draft', array(__CLASS__, 'get_draft_ajax'));
        add_action('wp_ajax_dcb_submit_digital_form', array(__CLASS__, 'submit_ajax'));
        add_action('add_meta_boxes', array(__CLASS__, 'add_submission_meta_boxes'));
        add_filter('manage_edit-dcb_form_submission_columns', array(__CLASS__, 'submission_columns'));
        add_action('manage_dcb_form_submission_posts_custom_column', array(__CLASS__, 'submission_column_content'), 10, 2);
        add_filter('post_row_actions', array(__CLASS__, 'submission_row_actions'), 10, 2);
    }

    public static function register_post_types(): void {
        register_post_type('dcb_form_submission', array(
            'labels' => array(
                'name' => __('Document Form Submissions', 'document-center-builder'),
                'singular_name' => __('Document Form Submission', 'document-center-builder'),
            ),
            'public' => false,
            'show_ui' => true,
            'show_in_menu' => false,
            'supports' => array('title'),
            'menu_icon' => 'dashicons-feedback',
            'capability_type' => array('dcb_submission', 'dcb_submissions'),
            'capabilities' => self::submission_post_type_capabilities(),
            'map_meta_cap' => true,
        ));

        register_post_type('dcb_upload_log', array(
            'labels' => array(
                'name' => __('Upload Logs', 'document-center-builder'),
                'singular_name' => __('Upload Log', 'document-center-builder'),
            ),
            'public' => false,
            'show_ui' => true,
            'show_in_menu' => false,
            'supports' => array('title'),
            'capability_type' => array('dcb_upload_log', 'dcb_upload_logs'),
            'capabilities' => self::upload_log_post_type_capabilities(),
            'map_meta_cap' => true,
        ));

        register_post_type('dcb_ocr_review_queue', array(
            'labels' => array(
                'name' => __('OCR Review Queue', 'document-center-builder'),
                'singular_name' => __('OCR Review Item', 'document-center-builder'),
            ),
            'public' => false,
            'show_ui' => true,
            'show_in_menu' => false,
            'supports' => array('title'),
            'capability_type' => array('dcb_ocr_review_item', 'dcb_ocr_review_items'),
            'capabilities' => self::ocr_review_post_type_capabilities(),
            'map_meta_cap' => true,
        ));
    }

    private static function submission_post_type_capabilities(): array {
        return array(
            'edit_post' => DCB_Permissions::CAP_REVIEW_SUBMISSIONS,
            'read_post' => DCB_Permissions::CAP_REVIEW_SUBMISSIONS,
            'delete_post' => DCB_Permissions::CAP_REVIEW_SUBMISSIONS,
            'edit_posts' => DCB_Permissions::CAP_REVIEW_SUBMISSIONS,
            'edit_others_posts' => DCB_Permissions::CAP_REVIEW_SUBMISSIONS,
            'publish_posts' => DCB_Permissions::CAP_REVIEW_SUBMISSIONS,
            'read_private_posts' => DCB_Permissions::CAP_REVIEW_SUBMISSIONS,
            'delete_posts' => DCB_Permissions::CAP_REVIEW_SUBMISSIONS,
            'delete_private_posts' => DCB_Permissions::CAP_REVIEW_SUBMISSIONS,
            'delete_published_posts' => DCB_Permissions::CAP_REVIEW_SUBMISSIONS,
            'delete_others_posts' => DCB_Permissions::CAP_REVIEW_SUBMISSIONS,
            'edit_private_posts' => DCB_Permissions::CAP_REVIEW_SUBMISSIONS,
            'edit_published_posts' => DCB_Permissions::CAP_REVIEW_SUBMISSIONS,
            'create_posts' => DCB_Permissions::CAP_REVIEW_SUBMISSIONS,
        );
    }

    private static function upload_log_post_type_capabilities(): array {
        return array(
            'edit_post' => DCB_Permissions::CAP_REVIEW_SUBMISSIONS,
            'read_post' => DCB_Permissions::CAP_REVIEW_SUBMISSIONS,
            'delete_post' => DCB_Permissions::CAP_REVIEW_SUBMISSIONS,
            'edit_posts' => DCB_Permissions::CAP_REVIEW_SUBMISSIONS,
            'edit_others_posts' => DCB_Permissions::CAP_REVIEW_SUBMISSIONS,
            'publish_posts' => DCB_Permissions::CAP_REVIEW_SUBMISSIONS,
            'read_private_posts' => DCB_Permissions::CAP_REVIEW_SUBMISSIONS,
            'delete_posts' => DCB_Permissions::CAP_REVIEW_SUBMISSIONS,
            'delete_private_posts' => DCB_Permissions::CAP_REVIEW_SUBMISSIONS,
            'delete_published_posts' => DCB_Permissions::CAP_REVIEW_SUBMISSIONS,
            'delete_others_posts' => DCB_Permissions::CAP_REVIEW_SUBMISSIONS,
            'edit_private_posts' => DCB_Permissions::CAP_REVIEW_SUBMISSIONS,
            'edit_published_posts' => DCB_Permissions::CAP_REVIEW_SUBMISSIONS,
            'create_posts' => DCB_Permissions::CAP_REVIEW_SUBMISSIONS,
        );
    }

    private static function ocr_review_post_type_capabilities(): array {
        return array(
            'edit_post' => DCB_Permissions::CAP_RUN_OCR_TOOLS,
            'read_post' => DCB_Permissions::CAP_RUN_OCR_TOOLS,
            'delete_post' => DCB_Permissions::CAP_RUN_OCR_TOOLS,
            'edit_posts' => DCB_Permissions::CAP_RUN_OCR_TOOLS,
            'edit_others_posts' => DCB_Permissions::CAP_RUN_OCR_TOOLS,
            'publish_posts' => DCB_Permissions::CAP_RUN_OCR_TOOLS,
            'read_private_posts' => DCB_Permissions::CAP_RUN_OCR_TOOLS,
            'delete_posts' => DCB_Permissions::CAP_RUN_OCR_TOOLS,
            'delete_private_posts' => DCB_Permissions::CAP_RUN_OCR_TOOLS,
            'delete_published_posts' => DCB_Permissions::CAP_RUN_OCR_TOOLS,
            'delete_others_posts' => DCB_Permissions::CAP_RUN_OCR_TOOLS,
            'edit_private_posts' => DCB_Permissions::CAP_RUN_OCR_TOOLS,
            'edit_published_posts' => DCB_Permissions::CAP_RUN_OCR_TOOLS,
            'create_posts' => DCB_Permissions::CAP_RUN_OCR_TOOLS,
        );
    }

    public static function draft_meta_key(string $form_key): string {
        return '_dcb_df_draft_' . sanitize_key($form_key);
    }

    public static function save_draft_ajax(): void {
        check_ajax_referer('dcb_digital_form_draft_nonce', 'nonce');
        if (!is_user_logged_in()) {
            wp_send_json_error(array('message' => 'Not logged in.'), 403);
        }

        $form_key = sanitize_key((string) ($_POST['form_key'] ?? ''));
        $fields_json = isset($_POST['fields']) ? wp_unslash((string) $_POST['fields']) : '{}';
        $fields = json_decode($fields_json, true);
        if ($form_key === '' || !is_array($fields)) {
            wp_send_json_error(array('message' => 'Invalid draft payload.'), 400);
        }

        update_user_meta(get_current_user_id(), self::draft_meta_key($form_key), array(
            'fields' => array_map('sanitize_text_field', array_map(static function ($v) {
                return is_scalar($v) ? (string) $v : '';
            }, $fields)),
            'updated_at' => current_time('mysql'),
        ));

        wp_send_json_success(array('message' => 'Draft saved.'));
    }

    public static function get_draft_ajax(): void {
        check_ajax_referer('dcb_digital_form_draft_nonce', 'nonce');
        if (!is_user_logged_in()) {
            wp_send_json_error(array('message' => 'Not logged in.'), 403);
        }

        $form_key = sanitize_key((string) ($_POST['form_key'] ?? ''));
        if ($form_key === '') {
            wp_send_json_error(array('message' => 'Form key missing.'), 400);
        }

        $draft = get_user_meta(get_current_user_id(), self::draft_meta_key($form_key), true);
        if (!is_array($draft)) {
            $draft = array('fields' => array(), 'updated_at' => '');
        }

        wp_send_json_success(array('draft' => $draft));
    }

    public static function submit_ajax(): void {
        check_ajax_referer('dcb_submit_digital_form_nonce', 'nonce');
        if (!is_user_logged_in()) {
            wp_send_json_error(array('message' => 'Not logged in.'), 403);
        }

        $form_key = sanitize_key((string) ($_POST['form_key'] ?? ''));
        $fields_json = isset($_POST['fields']) ? wp_unslash((string) $_POST['fields']) : '{}';
        $raw_fields = json_decode($fields_json, true);
        if (!is_array($raw_fields)) {
            $raw_fields = array();
        }

        $access_allowed = apply_filters('dcb_submission_access_allowed', true, $form_key, get_current_user_id(), array('source' => 'submit_ajax'));
        if (!$access_allowed) {
            wp_send_json_error(array('message' => 'Access denied for this form. Prerequisites were not met.'), 403);
        }

        $policy_versions = dcb_get_policy_versions();
        $signature_mode = sanitize_key((string) ($_POST['signature_mode'] ?? 'typed'));
        if (!in_array($signature_mode, array('typed', 'drawn'), true)) {
            $signature_mode = 'typed';
        }
        $signature_drawn_data = isset($_POST['signature_drawn_data']) ? trim((string) wp_unslash($_POST['signature_drawn_data'])) : '';
        $signer_identity = sanitize_text_field((string) ($_POST['signer_identity'] ?? ''));
        $signature_timestamp_client = sanitize_text_field((string) ($_POST['signature_timestamp'] ?? ''));
        $consent_text_version = sanitize_text_field((string) ($_POST['consent_text_version'] ?? $policy_versions['consent_text_version']));
        $attestation_text_version = sanitize_text_field((string) ($_POST['attestation_text_version'] ?? $policy_versions['attestation_text_version']));

        if ($signature_mode === 'drawn' && $signer_identity !== '' && empty($raw_fields['signature_name'])) {
            $raw_fields['signature_name'] = $signer_identity;
        }

        $user = wp_get_current_user();
        $user_id = $user instanceof WP_User ? (int) $user->ID : 0;
        $user_name = $user instanceof WP_User ? (string) $user->display_name : 'User';
        $user_email = $user instanceof WP_User ? (string) $user->user_email : '';

        $validation = dcb_validate_submission($form_key, $raw_fields);
        if (empty($validation['ok'])) {
            wp_send_json_error(array('message' => 'Please fix validation errors before submitting.', 'errors' => array_values(array_unique((array) ($validation['errors'] ?? array())))), 422);
        }

        if ($signature_mode === 'drawn' && !dcb_signature_data_is_valid($signature_drawn_data)) {
            wp_send_json_error(array('message' => 'Please fix validation errors before submitting.', 'errors' => array('Drawn signature is missing or invalid. Please sign again before submitting.')), 422);
        }

        if ($signer_identity === '') {
            $signer_identity = $user_name;
        }

        $clean = (array) ($validation['clean'] ?? array());
        $form = (array) ($validation['form'] ?? array());
        $form_label = (string) ($form['label'] ?? $form_key);
        $form_version = max(1, (int) ($form['version'] ?? 1));

        $submission_id = wp_insert_post(array(
            'post_type' => 'dcb_form_submission',
            'post_status' => 'publish',
            'post_title' => sprintf('%s — %s — %s', $form_label, $user_name, current_time('mysql')),
        ));

        if (is_wp_error($submission_id) || (int) $submission_id < 1) {
            wp_send_json_error(array('message' => 'Could not save form submission.'), 500);
        }

        update_post_meta((int) $submission_id, '_dcb_form_key', $form_key);
        update_post_meta((int) $submission_id, '_dcb_form_label', $form_label);
        update_post_meta((int) $submission_id, '_dcb_form_version', $form_version);
        update_post_meta((int) $submission_id, '_dcb_form_snapshot', wp_json_encode($form));
        update_post_meta((int) $submission_id, '_dcb_form_submitted_by', $user_id);
        update_post_meta((int) $submission_id, '_dcb_form_submitted_by_name', $user_name);
        update_post_meta((int) $submission_id, '_dcb_form_submitted_by_email', $user_email);
        update_post_meta((int) $submission_id, '_dcb_form_submitted_at', current_time('mysql'));
        update_post_meta((int) $submission_id, '_dcb_form_data', wp_json_encode($clean));
        update_post_meta((int) $submission_id, '_dcb_form_qa_passed', '1');
        update_post_meta((int) $submission_id, '_dcb_form_consent_text_version', $consent_text_version !== '' ? $consent_text_version : $policy_versions['consent_text_version']);
        update_post_meta((int) $submission_id, '_dcb_form_attestation_text_version', $attestation_text_version !== '' ? $attestation_text_version : $policy_versions['attestation_text_version']);
        update_post_meta((int) $submission_id, '_dcb_workflow_status', 'submitted');
        update_post_meta((int) $submission_id, '_dcb_workflow_assignee_user_id', 0);
        if (class_exists('DCB_Workflow')) {
            DCB_Workflow::add_timeline((int) $submission_id, 'submitted', array('form_key' => $form_key));
        }

        $ip = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field((string) wp_unslash($_SERVER['REMOTE_ADDR'])) : '';
        $ua = isset($_SERVER['HTTP_USER_AGENT']) ? sanitize_text_field((string) wp_unslash($_SERVER['HTTP_USER_AGENT'])) : '';
        $payload_hash = hash_hmac('sha256', wp_json_encode($clean), wp_salt('auth'));
        $signature_timestamp_server = current_time('mysql');
        $drawn_signature_hash = '';
        if ($signature_mode === 'drawn' && $signature_drawn_data !== '') {
            $drawn_signature_hash = hash('sha256', $signature_drawn_data);
            update_post_meta((int) $submission_id, '_dcb_form_signature_drawn_data', $signature_drawn_data);
            update_post_meta((int) $submission_id, '_dcb_form_signature_drawn_sha256', $drawn_signature_hash);
        }

        update_post_meta((int) $submission_id, '_dcb_form_signature_mode', $signature_mode);
        update_post_meta((int) $submission_id, '_dcb_form_signer_identity', $signer_identity);
        update_post_meta((int) $submission_id, '_dcb_form_signature_timestamp', $signature_timestamp_server);
        $esign_evidence = array(
            'consent' => (string) ($clean['esign_consent'] ?? ''),
            'attestation' => (string) ($clean['attest_truth'] ?? ''),
            'signature' => (string) ($clean['signature_name'] ?? ''),
            'signatureDate' => (string) ($clean['signature_date'] ?? ''),
            'signatureMode' => $signature_mode,
            'signerIdentity' => $signer_identity,
            'signatureTimestampClient' => $signature_timestamp_client,
            'signatureTimestamp' => $signature_timestamp_server,
            'drawnSignatureHash' => $drawn_signature_hash,
            'consentTextVersion' => $consent_text_version !== '' ? $consent_text_version : $policy_versions['consent_text_version'],
            'attestationTextVersion' => $attestation_text_version !== '' ? $attestation_text_version : $policy_versions['attestation_text_version'],
            'ip' => $ip,
            'userAgent' => mb_substr($ua, 0, 255),
            'timestamp' => $signature_timestamp_server,
            'hash' => $payload_hash,
        );
        update_post_meta((int) $submission_id, '_dcb_form_payload_hash', $payload_hash);
        update_post_meta((int) $submission_id, '_dcb_form_esign_evidence', wp_json_encode($esign_evidence));

        dcb_finalize_submission_output((int) $submission_id, $user_id);
        if (class_exists('DCB_Workflow')) {
            $route = DCB_Workflow::route_submission((int) $submission_id, array(
                'source' => 'submit_ajax',
                'status' => 'submitted',
                'form_key' => $form_key,
            ));
            if (empty($route['matched'])) {
                DCB_Workflow::set_status((int) $submission_id, 'in_review', 'Submission finalized and routed for review.');
            }
        }
        dcb_send_submission_notification((int) $submission_id);
        do_action('dcb_submission_completed', (int) $submission_id, $form_key, $user_id);

        delete_user_meta($user_id, self::draft_meta_key($form_key));

        wp_send_json_success(array('message' => 'Form submitted successfully.', 'submissionId' => (int) $submission_id));
    }

    public static function add_submission_meta_boxes(): void {
        add_meta_box('dcb-submission-details', __('Submission Output & Signature Evidence', 'document-center-builder'), array(__CLASS__, 'render_submission_meta_box'), 'dcb_form_submission', 'normal', 'high');
    }

    public static function render_submission_meta_box(WP_Post $post): void {
        if (!DCB_Permissions::can(DCB_Permissions::CAP_REVIEW_SUBMISSIONS)) {
            echo '<p>Unauthorized.</p>';
            return;
        }

        $submission_id = (int) $post->ID;
        echo dcb_render_submission_html($submission_id, 'admin'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    }

    public static function submission_columns(array $columns): array {
        if (!isset($columns['title'])) {
            return $columns;
        }

        return array(
            'cb' => $columns['cb'] ?? '',
            'title' => __('Record', 'document-center-builder'),
            'dcb_form_name' => __('Form', 'document-center-builder'),
            'dcb_form_version' => __('Version', 'document-center-builder'),
            'dcb_workflow_status' => __('Workflow', 'document-center-builder'),
            'dcb_workflow_assignee' => __('Assignee', 'document-center-builder'),
            'dcb_submitter' => __('Submitter', 'document-center-builder'),
            'dcb_submitted_at' => __('Submitted', 'document-center-builder'),
            'dcb_signature_mode' => __('Signature Mode', 'document-center-builder'),
            'dcb_email_status' => __('Email', 'document-center-builder'),
            'date' => $columns['date'] ?? __('Date', 'document-center-builder'),
        );
    }

    public static function submission_column_content(string $column, int $post_id): void {
        switch ($column) {
            case 'dcb_form_name':
                echo esc_html((string) get_post_meta($post_id, '_dcb_form_label', true));
                break;
            case 'dcb_form_version':
                echo esc_html((string) max(1, (int) get_post_meta($post_id, '_dcb_form_version', true)));
                break;
            case 'dcb_submitter':
                $name = (string) get_post_meta($post_id, '_dcb_form_submitted_by_name', true);
                $email = (string) get_post_meta($post_id, '_dcb_form_submitted_by_email', true);
                echo esc_html($name !== '' ? $name : $email);
                break;
            case 'dcb_workflow_status':
                $status = sanitize_key((string) get_post_meta($post_id, '_dcb_workflow_status', true));
                if ($status === '') {
                    $status = 'submitted';
                }
                $statuses = class_exists('DCB_Workflow') ? DCB_Workflow::statuses() : array();
                echo esc_html((string) ($statuses[$status] ?? $status));
                break;
            case 'dcb_workflow_assignee':
                $assignee = (int) get_post_meta($post_id, '_dcb_workflow_assignee_user_id', true);
                if ($assignee < 1) {
                    echo '—';
                    break;
                }
                $user = get_user_by('id', $assignee);
                echo esc_html($user instanceof WP_User ? (string) $user->display_name : ('User #' . $assignee));
                break;
            case 'dcb_submitted_at':
                echo esc_html((string) get_post_meta($post_id, '_dcb_form_submitted_at', true));
                break;
            case 'dcb_signature_mode':
                $mode = sanitize_key((string) get_post_meta($post_id, '_dcb_form_signature_mode', true));
                echo esc_html($mode !== '' ? $mode : 'typed');
                break;
            case 'dcb_email_status':
                echo esc_html((string) get_post_meta($post_id, '_dcb_form_email_status', true));
                break;
        }
    }

    public static function submission_row_actions(array $actions, WP_Post $post): array {
        if ($post->post_type !== 'dcb_form_submission' || !DCB_Permissions::can(DCB_Permissions::CAP_REVIEW_SUBMISSIONS)) {
            return $actions;
        }

        $submission_id = (int) $post->ID;
        $actions['dcb_print'] = '<a href="' . esc_url(DCB_Renderer::submission_print_url($submission_id)) . '">Print</a>';
        $actions['dcb_export'] = '<a href="' . esc_url(DCB_Renderer::submission_export_url($submission_id)) . '">Export JSON</a>';
        $actions['dcb_export_pdf'] = '<a href="' . esc_url(DCB_Renderer::submission_export_pdf_url($submission_id)) . '">Export PDF</a>';

        return $actions;
    }
}
