<?php

if (!defined('ABSPATH')) {
    exit;
}

final class DCB_Builder {
    public static function init(): void {
        add_action('admin_post_dcb_save_builder', array(__CLASS__, 'save_builder'));
        add_action('wp_ajax_dcb_builder_ocr_seed_extract', array(__CLASS__, 'ocr_seed_extract_ajax'));
        add_action('wp_ajax_dcb_builder_attach_background', array(__CLASS__, 'attach_background_ajax'));
        add_action('wp_ajax_dcb_builder_validate_schema', array(__CLASS__, 'validate_schema_ajax'));
    }

    public static function render_page(): void {
        if (!DCB_Permissions::can(DCB_Permissions::CAP_MANAGE_FORMS)) {
            wp_die('Unauthorized');
        }

        $builder_warnings = array();
        $custom_forms = array();
        try {
            $custom_forms = dcb_get_custom_forms();
        } catch (\Throwable $e) {
            $builder_warnings[] = 'Could not load existing forms; showing empty builder state.';
            if (function_exists('error_log')) {
                error_log('[DCB_BUILDER_LOAD_FAILED] ' . sanitize_text_field($e->getMessage()));
            }
        }
        $forms_json = wp_json_encode($custom_forms, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if (!is_string($forms_json) || $forms_json === '') {
            $forms_json = '{}';
        }

        $diag = array('status' => 'unknown');
        try {
            $diag = dcb_ocr_collect_environment_diagnostics();
        } catch (\Throwable $e) {
            $builder_warnings[] = 'OCR diagnostics unavailable right now.';
            if (function_exists('error_log')) {
                error_log('[DCB_BUILDER_DIAG_FAILED] ' . sanitize_text_field($e->getMessage()));
            }
        }

        echo '<div class="wrap">';
        echo '<h1>Forms Builder</h1>';
        if (!empty($builder_warnings)) {
            echo '<div class="notice notice-warning"><p>' . esc_html(implode(' ', $builder_warnings)) . '</p></div>';
        }
        if (isset($_GET['updated'])) {
            echo '<div class="notice notice-success"><p>Builder settings saved.</p></div>';
        }
        if (isset($_GET['builder_error'])) {
            echo '<div class="notice notice-warning"><p>' . esc_html(sanitize_text_field((string) $_GET['builder_error'])) . '</p></div>';
        }

        echo '<h2>OCR Diagnostics Snapshot</h2>';
        echo '<p>Status: <strong>' . esc_html((string) ($diag['status'] ?? 'missing')) . '</strong></p>';

        echo '<form method="post" enctype="multipart/form-data" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field('dcb_save_builder', 'dcb_builder_nonce');
        echo '<input type="hidden" name="action" value="dcb_save_builder" />';

        echo '<h3 style="margin-top:16px;">OCR → Draft Form</h3>';
        if (!DCB_Permissions::can(DCB_Permissions::CAP_RUN_OCR_TOOLS)) {
            echo '<p><em>You can edit and save forms, but OCR draft extraction requires additional OCR tool permissions.</em></p>';
        }
        echo '<table class="form-table"><tbody>';
        echo '<tr><th scope="row"><label for="dcb-ocr-seed-file">Seed File</label></th><td><input type="file" id="dcb-ocr-seed-file" name="ocr_seed_file" accept=".pdf,.txt,.csv,.jpg,.jpeg,.png,.webp,.heic,.heif,.docx" /></td></tr>';
        echo '<tr><th scope="row"><label for="dcb-ocr-form-key">Form Key (optional)</label></th><td><input type="text" id="dcb-ocr-form-key" name="ocr_form_key" class="regular-text" placeholder="wound_assessment_form" /></td></tr>';
        echo '<tr><th scope="row"><label for="dcb-ocr-form-label">Form Label (optional)</label></th><td><input type="text" id="dcb-ocr-form-label" name="ocr_form_label" class="regular-text" placeholder="Wound Assessment Form" /></td></tr>';
        echo '<tr><th scope="row"><label for="dcb-ocr-recipients">Recipients (optional)</label></th><td><input type="text" id="dcb-ocr-recipients" name="ocr_form_recipients" class="large-text" placeholder="team@example.com" /></td></tr>';
        echo '<tr><th scope="row"></th><td><button type="button" class="button button-secondary" id="dcb-ocr-extract-review">Extract OCR Draft for Review</button><p class="description">Runs OCR extraction without saving. Review candidates, then apply into the builder.</p></td></tr>';
        echo '</tbody></table>';

        echo '<div id="dcb-ocr-review-root" class="dcb-ocr-review-root" data-can-run-ocr="' . esc_attr(DCB_Permissions::can(DCB_Permissions::CAP_RUN_OCR_TOOLS) ? '1' : '0') . '" data-ocr-nonce="' . esc_attr(wp_create_nonce('dcb_builder_ocr_seed_extract')) . '"></div>';

        echo '<p><button type="submit" class="button button-primary">Save Builder</button></p>';

        echo '<div id="th-df-builder-root" class="th-df-builder-root" data-target="#th-df-builder-json">';
        echo '<div class="th-df-builder-loading">Loading form builder...</div>';
        echo '</div>';
        echo '<textarea id="th-df-builder-json" name="digital_forms_json" rows="20" class="large-text code" spellcheck="false" style="display:none;">' . esc_textarea($forms_json) . '</textarea>';

        echo '<details style="margin-top:10px;"><summary>Advanced: Edit Raw JSON</summary>';
        echo '<textarea id="th-df-builder-json-advanced" rows="16" class="large-text code" spellcheck="false">' . esc_textarea($forms_json) . '</textarea>';
        echo '<p><button type="button" class="button" id="th-df-builder-apply-raw">Apply Raw JSON to Builder</button></p>';
        echo '</details>';

        submit_button('Save Builder');
        echo '</form>';
        echo '</div>';
    }

    public static function ocr_seed_extract_ajax(): void {
        if (!DCB_Permissions::can(DCB_Permissions::CAP_MANAGE_FORMS)) {
            wp_send_json_error(array('message' => 'Unauthorized'), 403);
        }
        if (!DCB_Permissions::can(DCB_Permissions::CAP_RUN_OCR_TOOLS)) {
            wp_send_json_error(array('message' => 'OCR tools capability is required.'), 403);
        }

        check_ajax_referer('dcb_builder_ocr_seed_extract', 'nonce');

        if (!isset($_FILES['ocr_seed_file']) || !is_array($_FILES['ocr_seed_file'])) {
            wp_send_json_error(array('message' => 'Please choose a seed file.'), 422);
        }

        $seed_file = $_FILES['ocr_seed_file'];
        $seed_error = (int) ($seed_file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($seed_error !== UPLOAD_ERR_OK) {
            wp_send_json_error(array('message' => 'OCR seed upload failed.'), 422);
        }

        require_once ABSPATH . 'wp-admin/includes/file.php';
        $uploaded = wp_handle_upload($seed_file, array('test_form' => false, 'mimes' => dcb_upload_allowed_mimes()));
        if (!is_array($uploaded) || isset($uploaded['error'])) {
            wp_send_json_error(array('message' => 'OCR seed upload failed. Please check file type and size.'), 422);
        }

        $path = (string) ($uploaded['file'] ?? '');
        $mime = (string) ($uploaded['type'] ?? '');
        $name = sanitize_file_name((string) ($seed_file['name'] ?? 'scanned-form'));
        $label = sanitize_text_field((string) ($_POST['ocr_form_label'] ?? ''));
        if ($label === '') {
            $label = trim((string) preg_replace('/\.[^.]+$/', '', $name));
        }
        if ($label === '') {
            $label = 'Scanned Form';
        }

        $form_key = sanitize_key((string) ($_POST['ocr_form_key'] ?? ''));
        if ($form_key === '') {
            $form_key = sanitize_key($label);
        }
        if ($form_key === '') {
            $form_key = 'scanned_form_' . gmdate('Ymd_His');
        }

        $recipients_override = sanitize_text_field((string) ($_POST['ocr_form_recipients'] ?? ''));

        try {
            $ocr = dcb_upload_extract_text_from_file($path, $mime);
            $draft = dcb_ocr_to_draft_form((string) ($ocr['text'] ?? ''), $label, $ocr);
            $bg = self::digital_twin_background_from_upload($path, $mime, (string) ($uploaded['url'] ?? ''));
            if (!empty($bg)) {
                $draft['digital_twin_background'] = $bg;
            }
            if ($recipients_override !== '') {
                $draft['recipients'] = $recipients_override;
            }

            if ($path !== '' && file_exists($path)) {
                @unlink($path);
            }

            wp_send_json_success(array(
                'form_key' => $form_key,
                'form_label' => $label,
                'draft' => dcb_normalize_single_form($draft),
                'raw_draft' => $draft,
            ));
        } catch (\Throwable $e) {
            if ($path !== '' && file_exists($path)) {
                @unlink($path);
            }
            wp_send_json_error(array('message' => 'OCR extraction failed.'), 500);
        }
    }

    public static function validate_schema_ajax(): void {
        if (!DCB_Permissions::can(DCB_Permissions::CAP_MANAGE_FORMS)) {
            wp_send_json_error(array('message' => 'Unauthorized'), 403);
        }

        check_ajax_referer('dcb_builder_validate_schema', 'nonce');

        $raw_form = isset($_POST['form']) ? wp_unslash((string) $_POST['form']) : '';
        $form = array();

        if ($raw_form !== '') {
            $decoded = json_decode($raw_form, true);
            if (!is_array($decoded)) {
                wp_send_json_error(array('message' => 'Invalid form payload.'), 422);
            }
            $form = $decoded;
        } else {
            $raw_forms_json = isset($_POST['digital_forms_json']) ? wp_unslash((string) $_POST['digital_forms_json']) : '';
            $form_key = sanitize_key((string) ($_POST['form_key'] ?? ''));
            if ($raw_forms_json === '' || $form_key === '') {
                wp_send_json_error(array('message' => 'Missing form data.'), 422);
            }
            $decoded_forms = json_decode($raw_forms_json, true);
            if (!is_array($decoded_forms) || !isset($decoded_forms[$form_key]) || !is_array($decoded_forms[$form_key])) {
                wp_send_json_error(array('message' => 'Form not found in payload.'), 422);
            }
            $form = $decoded_forms[$form_key];
        }

        $validation = dcb_builder_validate_form_schema($form);
        $preview = dcb_builder_preview_payload($form);

        wp_send_json_success(array(
            'errors' => array_values((array) ($validation['errors'] ?? array())),
            'warnings' => array_values((array) ($validation['warnings'] ?? array())),
            'preview' => $preview,
        ));
    }

    public static function attach_background_ajax(): void {
        if (!DCB_Permissions::can(DCB_Permissions::CAP_MANAGE_FORMS)) {
            wp_send_json_error(array('message' => 'Unauthorized'), 403);
        }
        if (!DCB_Permissions::can(DCB_Permissions::CAP_RUN_OCR_TOOLS)) {
            wp_send_json_error(array('message' => 'OCR tools capability is required.'), 403);
        }

        check_ajax_referer('dcb_builder_ocr_seed_extract', 'nonce');

        if (!isset($_FILES['ocr_seed_file']) || !is_array($_FILES['ocr_seed_file'])) {
            wp_send_json_error(array('message' => 'Please choose a seed file.'), 422);
        }

        $seed_file = $_FILES['ocr_seed_file'];
        $seed_error = (int) ($seed_file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($seed_error !== UPLOAD_ERR_OK) {
            wp_send_json_error(array('message' => 'Background upload failed.'), 422);
        }

        require_once ABSPATH . 'wp-admin/includes/file.php';
        $uploaded = wp_handle_upload($seed_file, array('test_form' => false, 'mimes' => dcb_upload_allowed_mimes()));
        if (!is_array($uploaded) || isset($uploaded['error'])) {
            wp_send_json_error(array('message' => 'Background upload failed. Please check file type and size.'), 422);
        }

        $path = (string) ($uploaded['file'] ?? '');
        $mime = (string) ($uploaded['type'] ?? '');
        try {
            $bg = self::digital_twin_background_from_upload($path, $mime, (string) ($uploaded['url'] ?? ''));
            if ($path !== '' && file_exists($path)) {
                @unlink($path);
            }
            if (empty($bg)) {
                wp_send_json_error(array('message' => 'Could not generate scan background from this file.'), 422);
            }
            wp_send_json_success(array('background' => $bg));
        } catch (\Throwable $e) {
            if ($path !== '' && file_exists($path)) {
                @unlink($path);
            }
            wp_send_json_error(array('message' => 'Could not generate scan background.'), 500);
        }
    }

    public static function save_builder(): void {
        if (!DCB_Permissions::can(DCB_Permissions::CAP_MANAGE_FORMS)) {
            wp_die('Unauthorized');
        }
        if (!isset($_POST['dcb_builder_nonce']) || !wp_verify_nonce((string) $_POST['dcb_builder_nonce'], 'dcb_save_builder')) {
            wp_die('Security check failed');
        }

        $digital_forms_json = isset($_POST['digital_forms_json']) ? wp_unslash((string) $_POST['digital_forms_json']) : '';
        $existing_custom_forms = dcb_get_custom_forms();
        $custom_forms = array();
        $builder_error = '';

        $decoded_forms = json_decode($digital_forms_json, true);
        if (trim($digital_forms_json) !== '' && json_last_error() !== JSON_ERROR_NONE) {
            $builder_error = 'Builder JSON could not be parsed. Previous forms were kept.';
            $custom_forms = $existing_custom_forms;
        } elseif (is_array($decoded_forms)) {
            foreach ($decoded_forms as $form_key => $form) {
                if (!is_array($form)) {
                    continue;
                }
                $key = sanitize_key((string) $form_key);
                if ($key === '') {
                    continue;
                }
                $normalized_form = dcb_normalize_single_form($form);
                if ($normalized_form === null) {
                    continue;
                }
                $existing_form = isset($existing_custom_forms[$key]) && is_array($existing_custom_forms[$key]) ? $existing_custom_forms[$key] : null;
                $custom_forms[$key] = dcb_apply_versioning($normalized_form, $existing_form);
            }
        }

        if (isset($_FILES['ocr_seed_file']) && is_array($_FILES['ocr_seed_file'])) {
            $seed_file = $_FILES['ocr_seed_file'];
            $seed_error = (int) ($seed_file['error'] ?? UPLOAD_ERR_NO_FILE);
            if ($seed_error !== UPLOAD_ERR_NO_FILE) {
                require_once ABSPATH . 'wp-admin/includes/file.php';
                $uploaded = wp_handle_upload($seed_file, array('test_form' => false, 'mimes' => dcb_upload_allowed_mimes()));

                if (!is_array($uploaded) || isset($uploaded['error'])) {
                    $builder_error = 'OCR seed upload failed. Please check file type and size.';
                } else {
                    $path = (string) ($uploaded['file'] ?? '');
                    $mime = (string) ($uploaded['type'] ?? '');
                    $name = sanitize_file_name((string) ($seed_file['name'] ?? 'scanned-form'));
                    $label = sanitize_text_field((string) ($_POST['ocr_form_label'] ?? ''));
                    if ($label === '') {
                        $label = trim((string) preg_replace('/\.[^.]+$/', '', $name));
                    }
                    if ($label === '') {
                        $label = 'Scanned Form';
                    }

                    $ocr = dcb_upload_extract_text_from_file($path, $mime);
                    $draft = dcb_ocr_to_draft_form((string) ($ocr['text'] ?? ''), $label, $ocr);
                    $bg = self::digital_twin_background_from_upload($path, $mime, (string) ($uploaded['url'] ?? ''));
                    if (!empty($bg)) {
                        $draft['digital_twin_background'] = $bg;
                    }

                    $recipients_override = sanitize_text_field((string) ($_POST['ocr_form_recipients'] ?? ''));
                    if ($recipients_override !== '') {
                        $draft['recipients'] = $recipients_override;
                    }

                    $form_key = sanitize_key((string) ($_POST['ocr_form_key'] ?? ''));
                    if ($form_key === '') {
                        $form_key = sanitize_key($label);
                    }
                    if ($form_key === '') {
                        $form_key = 'scanned_form_' . gmdate('Ymd_His');
                    }

                    $normalized_draft = dcb_normalize_single_form($draft);
                    if ($normalized_draft !== null) {
                        $existing_form = isset($existing_custom_forms[$form_key]) && is_array($existing_custom_forms[$form_key]) ? $existing_custom_forms[$form_key] : null;
                        $custom_forms[$form_key] = dcb_apply_versioning($normalized_draft, $existing_form);
                    }

                    if ($path !== '') {
                        @unlink($path);
                    }
                }
            }
        }

        update_option('dcb_forms_custom', $custom_forms, false);

        $args = array('page' => 'dcb-builder', 'updated' => '1');
        if ($builder_error !== '') {
            $args['builder_error'] = rawurlencode($builder_error);
        }

        wp_safe_redirect(add_query_arg($args, admin_url('admin.php')));
        exit;
    }

    private static function digital_twin_background_from_upload(string $uploaded_path, string $mime, string $uploaded_url = ''): array {
        $mime = strtolower(trim($mime));
        if ($uploaded_path === '' || !file_exists($uploaded_path)) {
            return array();
        }

        if (strpos($mime, 'image/') === 0) {
            $url = esc_url_raw($uploaded_url);
            if ($url !== '') {
                return array(
                    'image_url' => $url,
                    'mode' => 'overlay',
                    'opacity' => 1.0,
                    'pages' => array(
                        array('page_number' => 1, 'image_url' => $url),
                    ),
                );
            }
        }

        if ($mime !== 'application/pdf') {
            return array();
        }

        $pdftoppm = function_exists('dcb_ocr_get_pdftoppm_path') ? dcb_ocr_get_pdftoppm_path() : '';
        if ($pdftoppm === '' || !function_exists('dcb_ocr_exec_binary')) {
            return array();
        }

        $uploads = wp_upload_dir();
        if (!is_array($uploads) || empty($uploads['basedir']) || empty($uploads['baseurl'])) {
            return array();
        }

        $subdir = '/dcb-digital-twin-backgrounds';
        $target_dir = trailingslashit((string) $uploads['basedir']) . ltrim($subdir, '/');
        wp_mkdir_p($target_dir);
        if (!is_dir($target_dir)) {
            return array();
        }

        $token = function_exists('wp_generate_password') ? wp_generate_password(8, false, false) : uniqid('dcbbg', true);
        $base = 'pdfbg-' . gmdate('Ymd-His') . '-' . preg_replace('/[^a-zA-Z0-9_\-]/', '', (string) $token);
        $prefix = trailingslashit($target_dir) . $base;

        dcb_ocr_exec_binary($pdftoppm, array('-f', '1', '-l', '12', '-r', '150', '-png', $uploaded_path, $prefix), false);
        $glob = glob($prefix . '-*.png');
        if (!is_array($glob) || empty($glob)) {
            return array();
        }

        natsort($glob);
        $pages = array();
        foreach ($glob as $idx => $generated) {
            if (!is_string($generated) || !file_exists($generated)) {
                continue;
            }
            $page_number = $idx + 1;
            if (preg_match('/-(\d+)\.png$/', basename($generated), $m)) {
                $page_number = max(1, (int) $m[1]);
            }
            $page_url = trailingslashit((string) $uploads['baseurl']) . ltrim($subdir, '/') . '/' . basename($generated);
            $page_url = esc_url_raw($page_url);
            if ($page_url === '') {
                continue;
            }
            $pages[] = array('page_number' => $page_number, 'image_url' => $page_url);
        }

        if (empty($pages)) {
            return array();
        }

        $public_url = esc_url_raw((string) ($pages[0]['image_url'] ?? ''));
        if ($public_url === '') {
            return array();
        }

        return array(
            'image_url' => $public_url,
            'mode' => 'overlay',
            'opacity' => 1.0,
            'pages' => $pages,
        );
    }
}
