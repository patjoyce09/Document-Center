<?php

if (!defined('ABSPATH')) {
    exit;
}

final class DCB_Builder {
    public static function init(): void {
        add_action('admin_post_dcb_save_builder', array(__CLASS__, 'save_builder'));
    }

    public static function render_page(): void {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }

        $custom_forms = dcb_get_custom_forms();
        $forms_json = wp_json_encode($custom_forms, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if (!is_string($forms_json) || $forms_json === '') {
            $forms_json = '{}';
        }

        $diag = dcb_ocr_collect_environment_diagnostics();

        echo '<div class="wrap">';
        echo '<h1>Forms Builder</h1>';
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

        echo '<div id="th-df-builder-root" class="th-df-builder-root" data-target="#th-df-builder-json">';
        echo '<div class="th-df-builder-loading">Loading form builder...</div>';
        echo '</div>';
        echo '<textarea id="th-df-builder-json" name="digital_forms_json" rows="20" class="large-text code" spellcheck="false" style="display:none;">' . esc_textarea($forms_json) . '</textarea>';

        echo '<details style="margin-top:10px;"><summary>Advanced: Edit Raw JSON</summary>';
        echo '<textarea id="th-df-builder-json-advanced" rows="16" class="large-text code" spellcheck="false">' . esc_textarea($forms_json) . '</textarea>';
        echo '<p><button type="button" class="button" id="th-df-builder-apply-raw">Apply Raw JSON to Builder</button></p>';
        echo '</details>';

        echo '<h3 style="margin-top:16px;">OCR → Draft Form</h3>';
        echo '<table class="form-table"><tbody>';
        echo '<tr><th scope="row"><label for="dcb-ocr-seed-file">Seed File</label></th><td><input type="file" id="dcb-ocr-seed-file" name="ocr_seed_file" accept=".pdf,.txt,.csv,.jpg,.jpeg,.png,.webp,.heic,.heif,.docx" /></td></tr>';
        echo '<tr><th scope="row"><label for="dcb-ocr-form-key">Form Key (optional)</label></th><td><input type="text" id="dcb-ocr-form-key" name="ocr_form_key" class="regular-text" placeholder="wound_assessment_form" /></td></tr>';
        echo '<tr><th scope="row"><label for="dcb-ocr-form-label">Form Label (optional)</label></th><td><input type="text" id="dcb-ocr-form-label" name="ocr_form_label" class="regular-text" placeholder="Wound Assessment Form" /></td></tr>';
        echo '<tr><th scope="row"><label for="dcb-ocr-recipients">Recipients (optional)</label></th><td><input type="text" id="dcb-ocr-recipients" name="ocr_form_recipients" class="large-text" placeholder="team@example.com" /></td></tr>';
        echo '</tbody></table>';

        submit_button('Save Builder');
        echo '</form>';
        echo '</div>';
    }

    public static function save_builder(): void {
        if (!current_user_can('manage_options')) {
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
}
