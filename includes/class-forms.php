<?php

if (!defined('ABSPATH')) {
    exit;
}

final class DCB_Forms {
    public static function init(): void {
        add_shortcode('dcb_digital_forms_portal', array(__CLASS__, 'digital_forms_shortcode'));
        add_shortcode('dcb_upload_portal', array(__CLASS__, 'upload_shortcode'));
        add_shortcode('document_upload_portal', array(__CLASS__, 'upload_shortcode'));
        add_shortcode('document_digital_forms_portal', array(__CLASS__, 'digital_forms_shortcode'));
    }

    public static function digital_forms_shortcode(): string {
        if (!is_user_logged_in()) {
            return '<div class="th-df-wrap"><p>Please log in to submit digital forms.</p></div>';
        }

        $forms = dcb_form_definitions();
        $user_id = get_current_user_id();
        ob_start();
        ?>
        <div class="th-df-wrap" id="th-df-wrap">
            <div class="th-df-card">
                <h2>Digital Forms</h2>
                <p class="th-df-help">Complete forms online with hard-stop quality checks before submission.</p>

                <label class="th-df-label" for="th-df-form-key">Select Form</label>
                <select id="th-df-form-key" class="th-df-select">
                    <option value="">Choose a form...</option>
                    <?php foreach ($forms as $key => $form) : ?>
                        <?php
                        $can_access = apply_filters('dcb_submission_access_allowed', true, (string) $key, $user_id, array('source' => 'portal_list'));
                        if (!$can_access) {
                            continue;
                        }
                        ?>
                        <option value="<?php echo esc_attr((string) $key); ?>"><?php echo esc_html((string) ($form['label'] ?? $key)); ?></option>
                    <?php endforeach; ?>
                </select>

                <form id="th-df-form" class="th-df-form" novalidate></form>
                <div id="th-df-signature-layer" class="th-df-signature-layer" hidden></div>

                <div class="th-df-actions">
                    <button id="th-df-submit" type="button" class="th-df-btn" disabled>Submit Form</button>
                </div>
                <p id="th-df-status" class="th-df-status" aria-live="polite"></p>
                <ul id="th-df-errors" class="th-df-errors" hidden></ul>
            </div>
        </div>
        <?php
        return (string) ob_get_clean();
    }

    public static function upload_shortcode(): string {
        if (!is_user_logged_in()) {
            return '<div class="th-upload-wrap"><p>Please log in to upload files.</p></div>';
        }

        $profiles = get_option('dcb_upload_form_profiles', array());
        if (!is_array($profiles)) {
            $profiles = array();
        }

        ob_start();
        ?>
        <div class="th-upload-wrap">
            <div class="th-upload-card">
                <h2>Upload Documents</h2>
                <p class="th-upload-help">Upload one or more files. We run security checks, OCR extraction, and routing.</p>

                <label for="th-upload-type-hint" style="display:block;margin:12px 0 8px;font-weight:600;">Document Type Hint (optional)</label>
                <select id="th-upload-type-hint" class="th-df-select" style="max-width:420px;">
                    <option value="">Auto-detect</option>
                    <?php foreach ($profiles as $profile) : ?>
                        <?php $profile_name = trim((string) ($profile['name'] ?? '')); ?>
                        <?php if ($profile_name === '') { continue; } ?>
                        <option value="<?php echo esc_attr($profile_name); ?>"><?php echo esc_html($profile_name); ?></option>
                    <?php endforeach; ?>
                </select>

                <label class="th-upload-dropzone" for="th-upload-files">
                    <span class="th-upload-dropzone-icon">&#8682;</span>
                    <span>Drag & drop files or click to browse</span>
                    <small>PDF, DOC, DOCX, JPG, PNG, WEBP, TXT, CSV (max 15MB each).</small>
                    <input id="th-upload-files" type="file" multiple accept=".pdf,.doc,.docx,.jpg,.jpeg,.jfif,.png,.webp,.txt,.csv" />
                </label>

                <ul id="th-upload-file-list" class="th-upload-file-list" hidden></ul>
                <button id="th-upload-submit" type="button" class="th-upload-btn" hidden>Upload &amp; Submit</button>
                <p id="th-upload-status" class="th-upload-status" aria-live="polite"></p>
            </div>

            <div id="th-upload-results-wrap" class="th-upload-card" hidden>
                <h3>Upload Results</h3>
                <table class="th-upload-table">
                    <thead>
                        <tr>
                            <th>File</th>
                            <th>Detected Type</th>
                            <th>Sent To</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody id="th-upload-results"></tbody>
                </table>
            </div>
        </div>
        <?php
        return (string) ob_get_clean();
    }
}
