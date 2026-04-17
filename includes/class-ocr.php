<?php

if (!defined('ABSPATH')) {
    exit;
}

final class DCB_OCR {
    public static function init(): void {
        add_action('wp_ajax_dcb_ocr_smoke_validation', array(__CLASS__, 'smoke_validation_ajax'));
        add_filter('manage_edit-dcb_ocr_review_queue_columns', array(__CLASS__, 'review_queue_columns'));
        add_action('manage_dcb_ocr_review_queue_posts_custom_column', array(__CLASS__, 'review_queue_column_content'), 10, 2);
        add_action('restrict_manage_posts', array(__CLASS__, 'render_review_queue_filters'));
        add_filter('parse_query', array(__CLASS__, 'apply_review_queue_filters'));
        add_filter('post_row_actions', array(__CLASS__, 'review_queue_row_actions'), 10, 2);
        add_action('add_meta_boxes', array(__CLASS__, 'register_review_meta_box'));
        add_action('admin_post_dcb_ocr_review_action', array(__CLASS__, 'handle_review_action'));
        add_action('admin_post_dcb_ocr_save_corrections', array(__CLASS__, 'handle_save_corrections'));
    }

    public static function smoke_validation_ajax(): void {
        if (!DCB_Permissions::can(DCB_Permissions::CAP_RUN_OCR_TOOLS)) {
            wp_send_json_error(array('message' => 'Unauthorized'), 403);
        }

        check_ajax_referer('dcb_ocr_smoke_validation', 'nonce');

        $result = dcb_ocr_smoke_validation();
        if (!empty($result['ok'])) {
            wp_send_json_success($result);
        }

        wp_send_json_error($result, 422);
    }

    public static function render_diagnostics_page(): void {
        if (!DCB_Permissions::can(DCB_Permissions::CAP_RUN_OCR_TOOLS)) {
            wp_die('Unauthorized');
        }

        $diag = dcb_ocr_collect_environment_diagnostics();
        $warnings = isset($diag['warnings']) && is_array($diag['warnings']) ? $diag['warnings'] : array();
        $languages = isset($diag['tesseract_languages']) && is_array($diag['tesseract_languages']) ? $diag['tesseract_languages'] : array();
        $checks = isset($diag['checks']) && is_array($diag['checks']) ? $diag['checks'] : array();
        $provider_diag = isset($diag['provider_diagnostics']) && is_array($diag['provider_diagnostics']) ? $diag['provider_diagnostics'] : array();
        $logs = dcb_upload_ocr_debug_log_recent(10);
        $queue_summary = function_exists('dcb_ocr_review_queue_summary') ? dcb_ocr_review_queue_summary() : array('status_counts' => array(), 'failure_counts' => array());
        $status_counts = isset($queue_summary['status_counts']) && is_array($queue_summary['status_counts']) ? $queue_summary['status_counts'] : array();
        $failure_counts = isset($queue_summary['failure_counts']) && is_array($queue_summary['failure_counts']) ? $queue_summary['failure_counts'] : array();
        $remote_caps = isset($provider_diag['engines']['remote']) && is_array($provider_diag['engines']['remote']) ? $provider_diag['engines']['remote'] : array();

        echo '<div class="wrap">';
        echo '<h1>OCR Diagnostics</h1>';
        echo '<p>Environment readiness and runtime diagnostics for local/remote OCR providers (HTTPS API supported, no SSH).</p>';

        echo '<table class="widefat striped" style="max-width:920px">';
        echo '<tbody>';
        echo '<tr><th style="width:240px">Overall Status</th><td><strong>' . esc_html((string) ($diag['status'] ?? 'unknown')) . '</strong></td></tr>';
        echo '<tr><th>Tesseract</th><td>' . esc_html((string) ($checks['tesseract']['path'] ?? 'Not found')) . '</td></tr>';
        echo '<tr><th>pdftotext</th><td>' . esc_html((string) ($checks['pdftotext']['path'] ?? 'Not found')) . '</td></tr>';
        echo '<tr><th>pdftoppm</th><td>' . esc_html((string) ($checks['pdftoppm']['path'] ?? 'Not found')) . '</td></tr>';
        echo '<tr><th>Tesseract Languages</th><td>' . esc_html(implode(', ', $languages)) . '</td></tr>';
        echo '<tr><th>OCR Mode</th><td>' . esc_html((string) ($provider_diag['mode'] ?? 'local')) . ' (active: ' . esc_html((string) ($provider_diag['active'] ?? 'local')) . ')</td></tr>';
        echo '<tr><th>Selected Engine</th><td>' . esc_html((string) ($provider_diag['active'] ?? 'local')) . '</td></tr>';
        echo '</tbody></table>';

        echo '<h2>Remote Provider Validation</h2>';
        if (empty($remote_caps)) {
            echo '<p>Remote provider diagnostics unavailable.</p>';
        } else {
            $remote_status = sanitize_text_field((string) ($remote_caps['status'] ?? 'unknown'));
            echo '<p><strong>Status:</strong> ' . esc_html($remote_status) . '</p>';
            $remote_warnings = isset($remote_caps['warnings']) && is_array($remote_caps['warnings']) ? $remote_caps['warnings'] : array();
            if (!empty($remote_warnings)) {
                echo '<ul style="list-style:disc;padding-left:18px;">';
                foreach ($remote_warnings as $warning) {
                    echo '<li>' . esc_html((string) $warning) . '</li>';
                }
                echo '</ul>';
            }
        }

        if (!empty($provider_diag['engines']) && is_array($provider_diag['engines'])) {
            echo '<h2>Provider Capabilities</h2><ul style="list-style:disc;padding-left:18px;">';
            foreach ($provider_diag['engines'] as $slug => $caps) {
                if (!is_array($caps)) {
                    continue;
                }
                $ready = !empty($caps['ready']) ? 'ready' : 'not ready';
                $status_text = sanitize_text_field((string) ($caps['status'] ?? 'unknown'));
                echo '<li><strong>' . esc_html((string) $slug) . '</strong>: ' . esc_html($ready . ' (' . $status_text . ')') . '</li>';
            }
            echo '</ul>';
        }

        if (!empty($warnings)) {
            echo '<h2>Warnings</h2><ul style="list-style:disc;padding-left:18px;">';
            foreach ($warnings as $warning) {
                echo '<li>' . esc_html((string) $warning) . '</li>';
            }
            echo '</ul>';
        }

        echo '<h2>OCR Review Queue Summary</h2>';
        if (empty($status_counts)) {
            echo '<p>No OCR review items yet.</p>';
        } else {
            echo '<table class="widefat striped" style="max-width:920px"><thead><tr><th>Status</th><th>Count</th></tr></thead><tbody>';
            foreach ($status_counts as $status => $count) {
                echo '<tr><td>' . esc_html(self::review_status_label((string) $status)) . '</td><td>' . esc_html((string) (int) $count) . '</td></tr>';
            }
            echo '</tbody></table>';
        }

        if (!empty($failure_counts)) {
            echo '<h3>Top Failure Reasons</h3>';
            echo '<table class="widefat striped" style="max-width:920px"><thead><tr><th>Failure</th><th>Count</th><th>Recommendation</th></tr></thead><tbody>';
            foreach ($failure_counts as $failure_code => $count) {
                $meta = function_exists('dcb_ocr_failure_meta') ? dcb_ocr_failure_meta((string) $failure_code) : array('label' => $failure_code, 'recommendation' => 'Review OCR diagnostics.');
                echo '<tr><td>' . esc_html((string) ($meta['label'] ?? $failure_code)) . '</td><td>' . esc_html((string) (int) $count) . '</td><td>' . esc_html((string) ($meta['recommendation'] ?? 'Review OCR diagnostics.')) . '</td></tr>';
            }
            echo '</tbody></table>';
        }

        echo '<h2>Smoke Validation</h2>';
        $smoke = dcb_ocr_smoke_validation($diag);
        echo '<p><strong>' . esc_html(!empty($smoke['ok']) ? 'OK' : 'Failed') . '</strong></p>';
        if (!empty($smoke['messages']) && is_array($smoke['messages'])) {
            echo '<ul style="list-style:disc;padding-left:18px;">';
            foreach ($smoke['messages'] as $message) {
                echo '<li>' . esc_html((string) $message) . '</li>';
            }
            echo '</ul>';
        }

        echo '<h2>Recent OCR Runtime Logs</h2>';
        if (empty($logs)) {
            echo '<p>No OCR logs yet.</p>';
        } else {
            echo '<table class="widefat striped"><thead><tr><th>Time</th><th>Engine</th><th>File</th><th>Confidence</th><th>Warning</th></tr></thead><tbody>';
            foreach ($logs as $row) {
                if (!is_array($row)) {
                    continue;
                }
                echo '<tr>';
                echo '<td>' . esc_html((string) ($row['time'] ?? '')) . '</td>';
                echo '<td>' . esc_html((string) ($row['engine'] ?? '')) . '</td>';
                echo '<td>' . esc_html((string) ($row['source_file_path'] ?? '')) . '</td>';
                echo '<td>' . esc_html((string) ($row['confidence_proxy'] ?? '0')) . '</td>';
                echo '<td>' . esc_html((string) ($row['warning_message'] ?? '')) . '</td>';
                echo '</tr>';
            }
            echo '</tbody></table>';
        }

        echo '</div>';
    }

    public static function review_queue_columns(array $columns): array {
        if (!isset($columns['title'])) {
            return $columns;
        }

        return array(
            'cb' => $columns['cb'] ?? '',
            'title' => __('Review Item', 'document-center-builder'),
            'dcb_ocr_status' => __('Status', 'document-center-builder'),
            'dcb_ocr_provider' => __('Provider/Mode', 'document-center-builder'),
            'dcb_ocr_source' => __('Source', 'document-center-builder'),
            'dcb_ocr_capture' => __('Capture Risk', 'document-center-builder'),
            'dcb_ocr_trace' => __('Traceability', 'document-center-builder'),
            'dcb_ocr_confidence' => __('Confidence', 'document-center-builder'),
            'dcb_ocr_failure' => __('Failure Reason', 'document-center-builder'),
            'dcb_ocr_candidates' => __('Candidates/Blocks', 'document-center-builder'),
            'date' => $columns['date'] ?? __('Date', 'document-center-builder'),
        );
    }

    public static function review_queue_column_content(string $column, int $post_id): void {
        if ($column === 'dcb_ocr_status') {
            $status = sanitize_key((string) get_post_meta($post_id, '_dcb_ocr_review_status', true));
            echo esc_html(self::review_status_label($status));
            return;
        }

        if ($column === 'dcb_ocr_provider') {
            $provider = sanitize_key((string) get_post_meta($post_id, '_dcb_ocr_review_provider', true));
            $mode = sanitize_key((string) get_post_meta($post_id, '_dcb_ocr_review_mode', true));
            $engine = sanitize_text_field((string) get_post_meta($post_id, '_dcb_ocr_review_engine', true));
            $out = $provider !== '' ? $provider : 'local';
            if ($mode !== '') {
                $out .= ' / ' . $mode;
            }
            if ($engine !== '') {
                $out .= ' (' . $engine . ')';
            }
            echo esc_html($out);
            return;
        }

        if ($column === 'dcb_ocr_source') {
            $capture_meta = self::review_capture_meta($post_id);
            $source = sanitize_key((string) ($capture_meta['input_source_type'] ?? 'unknown'));
            echo esc_html(self::source_label($source));
            return;
        }

        if ($column === 'dcb_ocr_capture') {
            $capture_meta = self::review_capture_meta($post_id);
            $status = sanitize_key((string) get_post_meta($post_id, '_dcb_ocr_review_status', true));
            if ($status === '') {
                $status = 'pending_review';
            }

            $warning_count = max(0, (int) ($capture_meta['capture_warning_count'] ?? 0));
            $risk_bucket = sanitize_key((string) ($capture_meta['capture_risk_bucket'] ?? (function_exists('dcb_ocr_capture_risk_bucket') ? dcb_ocr_capture_risk_bucket($warning_count) : 'clean')));
            $unresolved = function_exists('dcb_ocr_review_unresolved_capture_risk')
                ? dcb_ocr_review_unresolved_capture_risk($status, $warning_count)
                : ($warning_count > 0 && !in_array($status, array('approved', 'rejected'), true));

            $line = ucfirst($risk_bucket) . ' • ' . $warning_count . ' warning' . ($warning_count === 1 ? '' : 's');
            if ($unresolved) {
                $line .= ' • unresolved';
            }

            echo esc_html($line);
            return;
        }

        if ($column === 'dcb_ocr_trace') {
            $upload_log_id = (int) get_post_meta($post_id, '_dcb_ocr_review_upload_log_id', true);
            $submission_id = (int) get_post_meta($post_id, '_dcb_ocr_review_linked_submission_id', true);
            $trace_id = sanitize_text_field((string) get_post_meta($post_id, '_dcb_ocr_review_trace_id', true));
            $parts = array();
            if ($upload_log_id > 0) {
                $parts[] = 'Upload #' . $upload_log_id;
            }
            if ($submission_id > 0) {
                $parts[] = 'Submission #' . $submission_id;
            }
            echo esc_html(!empty($parts) ? implode(' / ', $parts) : '—');
            if ($trace_id !== '') {
                echo '<br/><small>' . esc_html($trace_id) . '</small>';
            }
            return;
        }

        if ($column === 'dcb_ocr_confidence') {
            $confidence = round((float) get_post_meta($post_id, '_dcb_ocr_review_confidence', true), 4);
            $bucket = sanitize_key((string) get_post_meta($post_id, '_dcb_ocr_review_confidence_bucket', true));
            echo esc_html((string) $confidence . ($bucket !== '' ? ' (' . $bucket . ')' : ''));
            return;
        }

        if ($column === 'dcb_ocr_failure') {
            $failure = sanitize_key((string) get_post_meta($post_id, '_dcb_ocr_review_failure_reason', true));
            if ($failure === '') {
                echo '—';
                return;
            }
            $meta = function_exists('dcb_ocr_failure_meta') ? dcb_ocr_failure_meta($failure) : array('label' => $failure, 'recommendation' => 'Review OCR diagnostics.');
            echo esc_html((string) ($meta['label'] ?? $failure));
            return;
        }

        if ($column === 'dcb_ocr_candidates') {
            $candidate_count = (int) get_post_meta($post_id, '_dcb_ocr_review_candidate_field_count', true);
            $block_count = (int) get_post_meta($post_id, '_dcb_ocr_review_block_count', true);
            echo esc_html((string) $candidate_count . ' / ' . (string) $block_count);
            return;
        }
    }

    public static function render_review_queue_filters(string $post_type): void {
        if ($post_type !== 'dcb_ocr_review_queue') {
            return;
        }

        $selected_status = isset($_GET['dcb_ocr_status_filter']) ? sanitize_key((string) $_GET['dcb_ocr_status_filter']) : '';
        $selected_source = isset($_GET['dcb_ocr_source_filter']) ? sanitize_key((string) $_GET['dcb_ocr_source_filter']) : '';
        $selected_risk = isset($_GET['dcb_ocr_capture_risk_filter']) ? sanitize_key((string) $_GET['dcb_ocr_capture_risk_filter']) : '';

        $statuses = function_exists('dcb_ocr_review_statuses') ? (array) dcb_ocr_review_statuses() : array();
        $sources = array(
            'scan' => 'Scan',
            'photo' => 'Photo',
            'pdf' => 'PDF',
            'document' => 'Document',
            'text' => 'Text',
            'unknown' => 'Unknown',
        );
        $risks = array(
            'clean' => 'Clean',
            'moderate' => 'Moderate',
            'high' => 'High',
            'at_risk' => 'At Risk (1+ warning)',
            'unresolved' => 'Unresolved Risk',
        );

        echo '<select name="dcb_ocr_status_filter">';
        echo '<option value="">All statuses</option>';
        foreach ($statuses as $status_key => $label) {
            $status_key = sanitize_key((string) $status_key);
            if ($status_key === '') {
                continue;
            }
            echo '<option value="' . esc_attr($status_key) . '" ' . selected($selected_status, $status_key, false) . '>' . esc_html((string) $label) . '</option>';
        }
        echo '</select>';

        echo '<select name="dcb_ocr_source_filter">';
        echo '<option value="">All sources</option>';
        foreach ($sources as $key => $label) {
            echo '<option value="' . esc_attr((string) $key) . '" ' . selected($selected_source, (string) $key, false) . '>' . esc_html((string) $label) . '</option>';
        }
        echo '</select>';

        echo '<select name="dcb_ocr_capture_risk_filter">';
        echo '<option value="">All risk levels</option>';
        foreach ($risks as $key => $label) {
            echo '<option value="' . esc_attr((string) $key) . '" ' . selected($selected_risk, (string) $key, false) . '>' . esc_html((string) $label) . '</option>';
        }
        echo '</select>';
    }

    public static function apply_review_queue_filters($query) {
        if (!($query instanceof WP_Query) || !is_admin() || !$query->is_main_query()) {
            return $query;
        }

        global $pagenow;
        if ($pagenow !== 'edit.php') {
            return $query;
        }

        $post_type = sanitize_key((string) ($query->query['post_type'] ?? ''));
        if ($post_type !== 'dcb_ocr_review_queue') {
            return $query;
        }

        $meta_query = (array) $query->get('meta_query');

        $status = isset($_GET['dcb_ocr_status_filter']) ? sanitize_key((string) $_GET['dcb_ocr_status_filter']) : '';
        if ($status !== '') {
            $meta_query[] = array(
                'key' => '_dcb_ocr_review_status',
                'value' => $status,
                'compare' => '=',
            );
        }

        $source = isset($_GET['dcb_ocr_source_filter']) ? sanitize_key((string) $_GET['dcb_ocr_source_filter']) : '';
        if ($source !== '') {
            $meta_query[] = array(
                'key' => '_dcb_ocr_review_source_type',
                'value' => $source,
                'compare' => '=',
            );
        }

        $risk = isset($_GET['dcb_ocr_capture_risk_filter']) ? sanitize_key((string) $_GET['dcb_ocr_capture_risk_filter']) : '';
        if ($risk !== '') {
            if ($risk === 'at_risk') {
                $meta_query[] = array(
                    'key' => '_dcb_ocr_review_capture_warning_count',
                    'value' => 1,
                    'type' => 'NUMERIC',
                    'compare' => '>=',
                );
            } elseif ($risk === 'unresolved') {
                $meta_query[] = array(
                    'key' => '_dcb_ocr_review_capture_risk_unresolved',
                    'value' => '1',
                    'compare' => '=',
                );
            } else {
                $meta_query[] = array(
                    'key' => '_dcb_ocr_review_capture_risk_bucket',
                    'value' => $risk,
                    'compare' => '=',
                );
            }
        }

        if (!empty($meta_query)) {
            $query->set('meta_query', $meta_query);
        }

        return $query;
    }

    public static function review_queue_row_actions(array $actions, WP_Post $post): array {
        if ($post->post_type !== 'dcb_ocr_review_queue' || !DCB_Permissions::can(DCB_Permissions::CAP_RUN_OCR_TOOLS)) {
            return $actions;
        }

        $id = (int) $post->ID;
        $make_url = static function (string $task) use ($id): string {
            $base = admin_url('admin-post.php?action=dcb_ocr_review_action&review_id=' . $id . '&task=' . sanitize_key($task));
            return wp_nonce_url($base, 'dcb_ocr_review_action_' . $id, 'dcb_ocr_review_nonce');
        };

        $actions['dcb_ocr_approve'] = '<a href="' . esc_url($make_url('approve')) . '">Mark Approved</a>';
        $actions['dcb_ocr_corrected'] = '<a href="' . esc_url($make_url('corrected')) . '">Mark Corrected</a>';
        $actions['dcb_ocr_reject'] = '<a href="' . esc_url($make_url('reject')) . '">Reject</a>';
        $actions['dcb_ocr_reprocess'] = '<a href="' . esc_url($make_url('reprocess')) . '">Reprocess OCR</a>';
        $actions['dcb_ocr_promote'] = '<a href="' . esc_url($make_url('promote_draft')) . '">Promote Draft</a>';

        return $actions;
    }

    public static function register_review_meta_box(): void {
        add_meta_box(
            'dcb-ocr-review-meta',
            __('OCR Review Operations', 'document-center-builder'),
            array(__CLASS__, 'render_review_meta_box'),
            'dcb_ocr_review_queue',
            'normal',
            'high'
        );
    }

    public static function render_review_meta_box(WP_Post $post): void {
        if (!DCB_Permissions::can(DCB_Permissions::CAP_RUN_OCR_TOOLS)) {
            echo '<p>Unauthorized.</p>';
            return;
        }

        $review_id = (int) $post->ID;
        $status = sanitize_key((string) get_post_meta($review_id, '_dcb_ocr_review_status', true));
        if ($status === '') {
            $status = 'pending_review';
        }
        $provider = sanitize_key((string) get_post_meta($review_id, '_dcb_ocr_review_provider', true));
        $mode = sanitize_key((string) get_post_meta($review_id, '_dcb_ocr_review_mode', true));
        $engine = sanitize_text_field((string) get_post_meta($review_id, '_dcb_ocr_review_engine', true));
        $confidence = round((float) get_post_meta($review_id, '_dcb_ocr_review_confidence', true), 4);
        $failure = sanitize_key((string) get_post_meta($review_id, '_dcb_ocr_review_failure_reason', true));
        $recommendation = sanitize_text_field((string) get_post_meta($review_id, '_dcb_ocr_review_recommendation', true));
        $provenance_raw = (string) get_post_meta($review_id, '_dcb_ocr_review_provenance', true);
        $provenance = json_decode($provenance_raw, true);
        if (!is_array($provenance)) {
            $provenance = array();
        }
        $capture_meta = self::review_capture_meta($review_id);
        $source_type = sanitize_key((string) ($capture_meta['input_source_type'] ?? 'unknown'));
        $capture_warning_count = max(0, (int) ($capture_meta['capture_warning_count'] ?? 0));
        $capture_risk_bucket = sanitize_key((string) ($capture_meta['capture_risk_bucket'] ?? (function_exists('dcb_ocr_capture_risk_bucket') ? dcb_ocr_capture_risk_bucket($capture_warning_count) : 'clean')));
        $capture_recommendations = isset($capture_meta['capture_recommendations']) && is_array($capture_meta['capture_recommendations']) ? $capture_meta['capture_recommendations'] : array();
        $normalization_improvement_proxy = round(max(0.0, min(1.0, (float) ($capture_meta['normalization_improvement_proxy'] ?? 0.0))), 4);
        $extraction_raw = (string) get_post_meta($review_id, '_dcb_ocr_review_extraction', true);
        $extraction = json_decode($extraction_raw, true);
        if (!is_array($extraction)) {
            $extraction = array();
        }
        $normalization_warnings = isset($extraction['input_normalization']['warnings']) && is_array($extraction['input_normalization']['warnings'])
            ? $extraction['input_normalization']['warnings']
            : array();
        $unresolved_capture_risk = function_exists('dcb_ocr_review_unresolved_capture_risk')
            ? dcb_ocr_review_unresolved_capture_risk($status, $capture_warning_count)
            : ($capture_warning_count > 0 && !in_array($status, array('approved', 'rejected'), true));
        $text = sanitize_textarea_field((string) get_post_meta($review_id, '_dcb_ocr_review_text', true));
        $corrected_summary = sanitize_textarea_field((string) get_post_meta($review_id, '_dcb_ocr_review_corrected_text_summary', true));

        echo '<p><strong>Status:</strong> ' . esc_html(self::review_status_label($status)) . '</p>';
        echo '<p><strong>Provider:</strong> ' . esc_html($provider !== '' ? $provider : 'local') . ' | <strong>Mode:</strong> ' . esc_html($mode !== '' ? $mode : 'local') . ' | <strong>Engine:</strong> ' . esc_html($engine !== '' ? $engine : 'n/a') . '</p>';
        echo '<p><strong>Confidence:</strong> ' . esc_html((string) $confidence) . ' | <strong>Failure:</strong> ' . esc_html($failure !== '' ? (string) ($failure) : 'none') . '</p>';
        if ($recommendation !== '') {
            echo '<p><strong>Recommendation:</strong> ' . esc_html($recommendation) . '</p>';
        }

        echo '<h4 style="margin-top:18px;">Capture Diagnostics</h4>';
        echo '<table class="widefat striped" style="max-width:920px"><tbody>';
        echo '<tr><th style="width:240px">Source Type</th><td>' . esc_html(self::source_label($source_type)) . '</td></tr>';
        echo '<tr><th>Capture Risk</th><td>' . esc_html(ucfirst($capture_risk_bucket) . ' (' . $capture_warning_count . ' warning' . ($capture_warning_count === 1 ? '' : 's') . ')') . ($unresolved_capture_risk ? ' <strong>— unresolved</strong>' : '') . '</td></tr>';
        echo '<tr><th>Normalization Improvement Proxy</th><td>' . esc_html((string) $normalization_improvement_proxy) . '</td></tr>';
        echo '</tbody></table>';

        if (!empty($normalization_warnings)) {
            echo '<p><strong>Capture Warnings</strong></p><ul style="list-style:disc;padding-left:18px;">';
            foreach ($normalization_warnings as $warning) {
                if (!is_array($warning)) {
                    continue;
                }
                $message = sanitize_text_field((string) ($warning['message'] ?? 'Capture warning'));
                if ($message === '') {
                    continue;
                }
                echo '<li>' . esc_html($message) . '</li>';
            }
            echo '</ul>';
        }

        if (!empty($capture_recommendations)) {
            echo '<p><strong>Capture Recommendations</strong></p><ul style="list-style:disc;padding-left:18px;">';
            foreach ($capture_recommendations as $tip) {
                $tip = sanitize_text_field((string) $tip);
                if ($tip === '') {
                    continue;
                }
                echo '<li>' . esc_html($tip) . '</li>';
            }
            echo '</ul>';
        }

        $sample = $corrected_summary !== '' ? $corrected_summary : $text;
        if ($sample !== '') {
            echo '<p><strong>Extracted Summary</strong></p>';
            echo '<textarea readonly rows="5" style="width:100%;">' . esc_textarea($sample) . '</textarea>';
        }

        if (!empty($provenance)) {
            echo '<p><strong>Provenance</strong></p>';
            echo '<pre style="max-height:180px;overflow:auto;background:#f6f7f7;padding:8px;">' . esc_html((string) wp_json_encode($provenance, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) . '</pre>';
        }

        echo '<hr/>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field('dcb_ocr_save_corrections_' . $review_id, 'dcb_ocr_corrections_nonce');
        echo '<input type="hidden" name="action" value="dcb_ocr_save_corrections" />';
        echo '<input type="hidden" name="review_id" value="' . esc_attr((string) $review_id) . '" />';
        echo '<p><label for="dcb-ocr-corrected-summary"><strong>Corrected Text Summary</strong></label><br/>';
        echo '<textarea id="dcb-ocr-corrected-summary" name="corrected_text_summary" rows="4" style="width:100%;">' . esc_textarea($corrected_summary) . '</textarea></p>';
        echo '<p><label for="dcb-ocr-candidates-json"><strong>Corrected Candidate Fields JSON (optional)</strong></label><br/>';
        $existing_candidates_raw = (string) get_post_meta($review_id, '_dcb_ocr_review_corrected_candidates', true);
        echo '<textarea id="dcb-ocr-candidates-json" name="candidate_fields_json" rows="6" style="width:100%;" class="code">' . esc_textarea($existing_candidates_raw !== '' ? $existing_candidates_raw : '[]') . '</textarea></p>';
        submit_button(__('Save Manual Corrections', 'document-center-builder'), 'secondary', 'submit', false);
        echo '</form>';
    }

    public static function handle_save_corrections(): void {
        if (!DCB_Permissions::can(DCB_Permissions::CAP_RUN_OCR_TOOLS)) {
            wp_die('Unauthorized');
        }

        $review_id = isset($_POST['review_id']) ? (int) $_POST['review_id'] : 0;
        check_admin_referer('dcb_ocr_save_corrections_' . $review_id, 'dcb_ocr_corrections_nonce');
        if ($review_id < 1) {
            wp_die('Missing review id');
        }

        $summary = sanitize_textarea_field((string) ($_POST['corrected_text_summary'] ?? ''));
        $candidate_json = isset($_POST['candidate_fields_json']) ? (string) wp_unslash($_POST['candidate_fields_json']) : '[]';
        $candidates = json_decode($candidate_json, true);
        if (!is_array($candidates)) {
            $candidates = array();
        }

        if (function_exists('dcb_ocr_review_apply_manual_corrections')) {
            dcb_ocr_review_apply_manual_corrections($review_id, array(
                'text_summary' => $summary,
                'candidate_fields' => $candidates,
            ));
        }

        wp_safe_redirect(admin_url('post.php?post=' . $review_id . '&action=edit'));
        exit;
    }

    public static function handle_review_action(): void {
        if (!DCB_Permissions::can(DCB_Permissions::CAP_RUN_OCR_TOOLS)) {
            wp_die('Unauthorized');
        }

        $review_id = isset($_GET['review_id']) ? (int) $_GET['review_id'] : 0;
        $task = sanitize_key((string) ($_GET['task'] ?? ''));
        check_admin_referer('dcb_ocr_review_action_' . $review_id, 'dcb_ocr_review_nonce');

        if ($review_id < 1 || $task === '') {
            wp_die('Invalid action');
        }

        if ($task === 'approve' && function_exists('dcb_ocr_review_update_status')) {
            dcb_ocr_review_update_status($review_id, 'approved', 'Approved from queue action.');
        } elseif ($task === 'corrected' && function_exists('dcb_ocr_review_update_status')) {
            dcb_ocr_review_update_status($review_id, 'corrected', 'Marked corrected from queue action.');
        } elseif ($task === 'reject' && function_exists('dcb_ocr_review_update_status')) {
            dcb_ocr_review_update_status($review_id, 'rejected', 'Rejected from queue action.');
        } elseif ($task === 'reprocess' && function_exists('dcb_ocr_review_reprocess')) {
            $mode = sanitize_key((string) ($_GET['mode'] ?? ''));
            dcb_ocr_review_reprocess($review_id, $mode);
        } elseif ($task === 'promote_draft' && function_exists('dcb_ocr_review_promote_builder_draft')) {
            dcb_ocr_review_promote_builder_draft($review_id);
        }

        $back = admin_url('post.php?post=' . $review_id . '&action=edit');
        if ($task === 'approve' || $task === 'corrected' || $task === 'reject' || $task === 'reprocess' || $task === 'promote_draft') {
            $back = admin_url('edit.php?post_type=dcb_ocr_review_queue');
        }
        wp_safe_redirect($back);
        exit;
    }

    private static function review_status_label(string $status): string {
        $status = sanitize_key($status);
        $statuses = function_exists('dcb_ocr_review_statuses') ? dcb_ocr_review_statuses() : array();
        if ($status === '') {
            $status = 'pending_review';
        }
        return isset($statuses[$status]) ? (string) $statuses[$status] : ucfirst(str_replace('_', ' ', $status));
    }

    private static function review_capture_meta(int $post_id): array {
        $raw = (string) get_post_meta($post_id, '_dcb_ocr_review_capture_meta', true);
        $meta = json_decode($raw, true);
        if (!is_array($meta)) {
            $meta = array();
        }

        $warning_count = isset($meta['capture_warning_count']) ? (int) $meta['capture_warning_count'] : (int) get_post_meta($post_id, '_dcb_ocr_review_capture_warning_count', true);
        if ($warning_count < 0) {
            $warning_count = 0;
        }

        $source = sanitize_key((string) ($meta['input_source_type'] ?? get_post_meta($post_id, '_dcb_ocr_review_source_type', true)));
        if ($source === '') {
            $source = 'unknown';
        }

        $bucket = sanitize_key((string) ($meta['capture_risk_bucket'] ?? get_post_meta($post_id, '_dcb_ocr_review_capture_risk_bucket', true)));
        if ($bucket === '') {
            $bucket = function_exists('dcb_ocr_capture_risk_bucket') ? dcb_ocr_capture_risk_bucket($warning_count) : 'clean';
        }

        $recommendations = isset($meta['capture_recommendations']) && is_array($meta['capture_recommendations']) ? $meta['capture_recommendations'] : array();
        $clean_recommendations = array();
        foreach ($recommendations as $tip) {
            $value = sanitize_text_field((string) $tip);
            if ($value !== '') {
                $clean_recommendations[] = $value;
            }
        }

        return array(
            'input_source_type' => $source,
            'capture_warning_count' => $warning_count,
            'capture_risk_bucket' => $bucket,
            'normalization_improvement_proxy' => isset($meta['normalization_improvement_proxy']) ? (float) $meta['normalization_improvement_proxy'] : 0.0,
            'capture_recommendations' => array_values(array_unique($clean_recommendations)),
        );
    }

    private static function source_label(string $source): string {
        $source = sanitize_key($source);
        $labels = array(
            'scan' => 'Scan',
            'photo' => 'Photo',
            'pdf' => 'PDF',
            'document' => 'Document',
            'text' => 'Text',
            'unknown' => 'Unknown',
        );
        return isset($labels[$source]) ? $labels[$source] : ucfirst(str_replace('_', ' ', $source));
    }
}
