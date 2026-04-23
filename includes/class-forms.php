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
                    <button id="th-df-download-pdf" type="button" class="th-df-btn" disabled style="margin-left:8px;background:#4f647f;">Download Filled PDF</button>
                </div>
                <p id="th-df-status" class="th-df-status" aria-live="polite"></p>
                <ul id="th-df-errors" class="th-df-errors" hidden></ul>
                <div id="th-df-hard-stop-preview" class="th-df-hard-stop-preview" hidden>
                    <p class="th-df-help" style="margin:6px 0 4px;"><strong>Submission blockers</strong> — resolve these semantic hard-stop checks before submitting.</p>
                    <ul id="th-df-hard-stop-list" class="th-df-errors"></ul>
                </div>
            </div>
        </div>
        <?php
        return (string) ob_get_clean();
    }

    public static function upload_shortcode(): string {
        if (!is_user_logged_in()) {
            return '<div class="th-upload-wrap"><p>Please log in to upload files.</p></div>';
        }

        $user_id = get_current_user_id();
        $profiles = get_option('dcb_upload_form_profiles', array());
        if (!is_array($profiles)) {
            $profiles = array();
        }
        $resource_center = self::resource_center_payload($user_id);
        $resource_rows = isset($resource_center['rows']) && is_array($resource_center['rows']) ? $resource_center['rows'] : array();
        $resource_summary = isset($resource_center['summary']) && is_array($resource_center['summary']) ? $resource_center['summary'] : array();

        ob_start();
        ?>
        <div class="th-upload-wrap">
            <div class="th-upload-card">
                <h2>Upload Documents</h2>
                <p class="th-upload-help">Upload one or more files. We run security checks, OCR extraction, and routing. If capture quality is risky, we show guidance before intake review.</p>

                <label for="th-upload-type-hint" style="display:block;margin:12px 0 8px;font-weight:600;">Document Type Hint (optional)</label>
                <select id="th-upload-type-hint" class="th-df-select" style="max-width:420px;">
                    <option value="">Auto-detect</option>
                    <?php foreach ($profiles as $profile) : ?>
                        <?php $profile_name = trim((string) ($profile['name'] ?? '')); ?>
                        <?php if ($profile_name === '') { continue; } ?>
                        <option value="<?php echo esc_attr($profile_name); ?>"><?php echo esc_html($profile_name); ?></option>
                    <?php endforeach; ?>
                </select>

                <label for="th-upload-channel" style="display:block;margin:12px 0 8px;font-weight:600;">Intake Channel (optional override)</label>
                <select id="th-upload-channel" class="th-df-select" style="max-width:420px;">
                    <option value="auto_detect" selected>Auto-detect (recommended)</option>
                    <option value="direct_upload">Direct Upload</option>
                    <option value="phone_photo">Phone Photo/Image</option>
                    <option value="scanned_pdf">Scanned PDF</option>
                    <option value="email_import">Emailed Attachment / Imported File</option>
                </select>

                <label class="th-upload-dropzone" for="th-upload-files">
                    <span class="th-upload-dropzone-icon">&#8682;</span>
                    <span>Drag & drop files or click to browse</span>
                    <small>PDF, DOC, DOCX, JPG, PNG, WEBP, HEIC, HEIF, AVIF, TIFF, BMP, GIF, TXT, CSV (max 15MB each).</small>
                    <input id="th-upload-files" type="file" multiple accept=".pdf,.doc,.docx,.jpg,.jpeg,.jfif,.png,.webp,.heic,.heif,.avif,.tif,.tiff,.bmp,.gif,.txt,.csv" />
                </label>

                <ul id="th-upload-file-list" class="th-upload-file-list" hidden></ul>
                <button id="th-upload-submit" type="button" class="th-upload-btn" hidden>Upload &amp; Submit</button>
                <p id="th-upload-status" class="th-upload-status" aria-live="polite"></p>
                <div id="th-upload-intake-alert" class="th-upload-intake-alert" hidden></div>
            </div>

            <div class="th-upload-card th-upload-resource-center">
                <h3>Intake Resource Center</h3>
                <p class="th-upload-help">Use these quick tips to reduce OCR cleanup time and reviewer back-and-forth.</p>
                <ul class="th-upload-resource-list">
                    <li>Capture the full page border without clipping signatures or labels.</li>
                    <li>Use even lighting and avoid hard shadows across text fields.</li>
                    <li>Keep the camera directly above the page to avoid skew.</li>
                    <li>Prefer JPG/PNG photo captures or clean PDF scans when possible.</li>
                </ul>
                <p class="th-upload-help th-upload-resource-links">Need admin help? Review queue and diagnostics are available in your admin panel.</p>

                <h4 style="margin-top:14px;">Packet &amp; Intake Status</h4>
                <p class="th-upload-help">
                    Required: <?php echo esc_html((string) (int) ($resource_summary['required_count'] ?? 0)); ?> ·
                    Missing Required: <?php echo esc_html((string) (int) ($resource_summary['missing_required_count'] ?? 0)); ?> ·
                    Returned for Correction: <?php echo esc_html((string) (int) ($resource_summary['correction_count'] ?? 0)); ?> ·
                    Approved/Finalized: <?php echo esc_html((string) (int) ($resource_summary['approved_count'] ?? 0)); ?>
                </p>
                <table class="th-upload-table">
                    <thead>
                    <tr>
                        <th>Form</th>
                        <th>Requirement</th>
                        <th>Uploaded</th>
                        <th>Status</th>
                        <th>Fillable</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php if (empty($resource_rows)) : ?>
                        <tr>
                            <td colspan="5">No form intake rows available yet.</td>
                        </tr>
                    <?php else : ?>
                        <?php foreach ($resource_rows as $row) : ?>
                            <?php
                            if (!is_array($row)) {
                                continue;
                            }
                            $form_key = sanitize_key((string) ($row['form_key'] ?? ''));
                            $state_label = sanitize_text_field((string) ($row['state_label'] ?? 'Uploaded'));
                            $fill_args = array('dcb_form' => $form_key);
                            $latest_upload_id = max(0, (int) ($row['latest_upload_log_id'] ?? 0));
                            $latest_review_id = max(0, (int) ($row['latest_review_id'] ?? 0));
                            $latest_trace_id = sanitize_text_field((string) ($row['latest_trace_id'] ?? ''));
                            $latest_channel = sanitize_key((string) ($row['latest_source_channel'] ?? ''));
                            $latest_capture = sanitize_key((string) ($row['latest_capture_type'] ?? ''));
                            if ($latest_upload_id > 0) {
                                $fill_args['dcb_upload_log'] = $latest_upload_id;
                            }
                            if ($latest_review_id > 0) {
                                $fill_args['dcb_review'] = $latest_review_id;
                            }
                            if ($latest_trace_id !== '') {
                                $fill_args['dcb_trace'] = $latest_trace_id;
                            }
                            if ($latest_channel !== '') {
                                $fill_args['dcb_channel'] = $latest_channel;
                            }
                            if ($latest_capture !== '') {
                                $fill_args['dcb_capture'] = $latest_capture;
                            }
                            $fillable_url = add_query_arg($fill_args, get_permalink());
                            if (is_string($fillable_url) && $fillable_url !== '') {
                                $fillable_url .= '#th-df-wrap';
                            }
                            ?>
                            <tr>
                                <td><?php echo esc_html((string) ($row['form_label'] ?? $form_key)); ?></td>
                                <td><?php echo esc_html((string) ($row['requirement'] ?? 'optional')); ?></td>
                                <td><?php echo esc_html((string) (int) ($row['upload_count'] ?? 0)); ?></td>
                                <td><?php echo esc_html($state_label); ?></td>
                                <td>
                                    <?php if ($form_key !== '') : ?>
                                        <a class="button button-small" href="<?php echo esc_url($fillable_url); ?>">Use Fillable Version</a>
                                    <?php else : ?>
                                        —
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <?php
            $requested_fillable_form = isset($_GET['dcb_form']) ? sanitize_key((string) $_GET['dcb_form']) : '';
            if ($requested_fillable_form !== '') :
                echo self::digital_forms_shortcode();
            endif;
            ?>

            <div id="th-upload-results-wrap" class="th-upload-card" hidden>
                <h3>Upload Results</h3>
                <table class="th-upload-table">
                    <thead>
                        <tr>
                            <th>File</th>
                            <th>Detected Type</th>
                            <th>Confidence</th>
                            <th>Capture Guidance</th>
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

    private static function resource_center_payload(int $user_id): array {
        $forms = dcb_form_definitions();
        if (!is_array($forms)) {
            $forms = array();
        }

        $required = array();
        $packet_defs = get_option('dcb_workflow_packet_definitions', array());
        if (is_array($packet_defs)) {
            foreach ($packet_defs as $packet) {
                if (!is_array($packet)) {
                    continue;
                }
                foreach ((array) ($packet['required_document_types'] ?? array()) as $doc_key) {
                    $doc_key = sanitize_key((string) $doc_key);
                    if ($doc_key !== '') {
                        $required[$doc_key] = true;
                    }
                }
            }
        }

        $submitted_states = array();
        $submission_ids = get_posts(array(
            'post_type' => 'dcb_form_submission',
            'post_status' => 'publish',
            'posts_per_page' => 200,
            'fields' => 'ids',
            'meta_query' => array(
                array(
                    'key' => '_dcb_form_submitted_by',
                    'value' => $user_id,
                    'compare' => '=',
                ),
            ),
        ));

        foreach ((array) $submission_ids as $submission_id) {
            $submission_id = (int) $submission_id;
            if ($submission_id < 1) {
                continue;
            }
            $form_key = sanitize_key((string) get_post_meta($submission_id, '_dcb_form_key', true));
            if ($form_key === '') {
                continue;
            }
            $submitted_states[$form_key] = array(
                'workflow_status' => sanitize_key((string) get_post_meta($submission_id, '_dcb_workflow_status', true)),
                'review_status' => sanitize_key((string) get_post_meta($submission_id, '_dcb_intake_review_status', true)),
            );
        }

        $upload_states = array();
        $upload_ids = get_posts(array(
            'post_type' => 'dcb_upload_log',
            'post_status' => 'publish',
            'posts_per_page' => 300,
            'fields' => 'ids',
            'meta_query' => array(
                array(
                    'key' => '_dcb_upload_user_id',
                    'value' => $user_id,
                    'compare' => '=',
                ),
            ),
        ));

        foreach ((array) $upload_ids as $upload_id) {
            $upload_id = (int) $upload_id;
            if ($upload_id < 1) {
                continue;
            }

            $hint = sanitize_key((string) get_post_meta($upload_id, '_dcb_upload_hint', true));
            if ($hint === '') {
                continue;
            }

            if (!isset($upload_states[$hint])) {
                $upload_states[$hint] = array(
                    'count' => 0,
                    'latest_state' => 'uploaded',
                    'latest_upload_log_id' => 0,
                    'latest_review_id' => 0,
                    'latest_trace_id' => '',
                    'latest_source_channel' => '',
                    'latest_capture_type' => '',
                );
            }
            $upload_states[$hint]['count']++;
            $upload_states[$hint]['latest_state'] = sanitize_key((string) get_post_meta($upload_id, '_dcb_upload_intake_state', true));
            $upload_states[$hint]['latest_upload_log_id'] = $upload_id;
            $upload_states[$hint]['latest_review_id'] = (int) get_post_meta($upload_id, '_dcb_upload_ocr_review_item_id', true);
            $upload_states[$hint]['latest_trace_id'] = sanitize_text_field((string) get_post_meta($upload_id, '_dcb_upload_trace_id', true));
            $upload_states[$hint]['latest_source_channel'] = sanitize_key((string) get_post_meta($upload_id, '_dcb_upload_source_channel', true));
            $upload_states[$hint]['latest_capture_type'] = sanitize_key((string) get_post_meta($upload_id, '_dcb_upload_capture_type', true));
        }

        $payload = dcb_resource_center_status_model($forms, array_keys($required), $submitted_states, $upload_states);
        return function_exists('apply_filters') ? (array) apply_filters('dcb_resource_center_payload', $payload, $user_id, $forms) : $payload;
    }
}
