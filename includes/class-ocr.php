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
        add_action('admin_post_dcb_ocr_review_patch_bridge', array(__CLASS__, 'handle_review_patch_bridge'));
        add_action('wp_ajax_dcb_ocr_review_patch_bridge', array(__CLASS__, 'handle_review_patch_bridge'));
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

        $canonical_graph = isset($extraction['ocr_canonical_form_graph']) && is_array($extraction['ocr_canonical_form_graph'])
            ? $extraction['ocr_canonical_form_graph']
            : array();
        $kpis = (!empty($canonical_graph) && function_exists('dcb_ocr_structural_kpi_payload'))
            ? dcb_ocr_structural_kpi_payload($canonical_graph, $extraction, array())
            : array();
        if (!empty($kpis)) {
            echo '<h4 style="margin-top:18px;">Structural Review KPIs</h4>';
            echo '<table class="widefat striped" style="max-width:920px"><tbody>';
            echo '<tr><th style="width:280px">False Positives (proxy)</th><td>' . esc_html((string) max(0, (int) ($kpis['false_positive_count'] ?? 0))) . '</td></tr>';
            echo '<tr><th>Approval Block Structural Quality</th><td>' . esc_html((string) round((float) ($kpis['approval_block_structural_quality'] ?? 0.0), 4)) . '</td></tr>';
            echo '<tr><th>Signature Endpoint Validity</th><td>' . esc_html((string) round((float) ($kpis['signature_endpoint_validity'] ?? 0.0), 4)) . '</td></tr>';
            echo '<tr><th>Group Membership Completeness</th><td>' . esc_html((string) round((float) ($kpis['group_membership_completeness'] ?? 0.0), 4)) . '</td></tr>';
            echo '<tr><th>Fillable vs Fixed Accuracy</th><td>' . esc_html((string) round((float) ($kpis['fillable_fixed_classification_accuracy'] ?? 0.0), 4)) . '</td></tr>';
            echo '<tr><th>Sparse Critical Field Completeness</th><td>' . esc_html((string) round((float) ($kpis['sparse_critical_field_set_completeness'] ?? 0.0), 4)) . '</td></tr>';
            echo '</tbody></table>';
        }

        $bridge_last_raw = (string) get_post_meta($review_id, '_dcb_ocr_review_patch_bridge_last_result', true);
        $bridge_last = json_decode($bridge_last_raw, true);
        if (is_array($bridge_last) && !empty($bridge_last['structural_kpis']['delta']) && is_array($bridge_last['structural_kpis']['delta'])) {
            $delta = $bridge_last['structural_kpis']['delta'];
            echo '<h4 style="margin-top:18px;">Latest Patch KPI Deltas</h4>';
            echo '<table class="widefat striped" style="max-width:920px"><tbody>';
            foreach (array(
                'false_positive_count' => 'False Positives',
                'approval_block_structural_quality' => 'Approval Block Structural Quality',
                'signature_endpoint_validity' => 'Signature Endpoint Validity',
                'group_membership_completeness' => 'Group Membership Completeness',
                'fillable_fixed_classification_accuracy' => 'Fillable vs Fixed Accuracy',
                'sparse_critical_field_set_completeness' => 'Sparse Critical Field Completeness',
            ) as $metric_key => $metric_label) {
                if (!isset($delta[$metric_key])) {
                    continue;
                }
                $val = is_numeric($delta[$metric_key]) ? round((float) $delta[$metric_key], 4) : $delta[$metric_key];
                echo '<tr><th style="width:280px">' . esc_html($metric_label) . '</th><td>' . esc_html((string) $val) . '</td></tr>';
            }
            echo '</tbody></table>';
        }
        if (is_array($bridge_last) && !empty($bridge_last['entities']) && is_array($bridge_last['entities'])) {
            echo '<h4 style="margin-top:18px;">Latest Entity Snapshot</h4>';
            echo '<p class="description">Snapshot returned by the last bridge validate/apply action for the selected stable IDs.</p>';
            echo '<table class="widefat striped" style="max-width:1180px"><thead><tr>';
            echo '<th style="width:190px">Stable ID</th><th style="width:120px">Entity Type</th><th style="width:70px">Page</th><th style="width:150px">Widget/Group</th><th>Current State</th>';
            echo '</tr></thead><tbody>';
            foreach ((array) $bridge_last['entities'] as $entity_row) {
                if (!is_array($entity_row)) {
                    continue;
                }
                $stable_id = sanitize_key((string) ($entity_row['stable_id'] ?? ''));
                if ($stable_id === '') {
                    continue;
                }
                $entity_type = sanitize_key((string) ($entity_row['entity_type'] ?? ''));
                $page_number = max(0, (int) ($entity_row['page_number'] ?? 0));
                $widget_or_group = sanitize_key((string) ($entity_row['widget_type'] ?? $entity_row['group_type'] ?? ''));

                $state_bits = array();
                $label_text = sanitize_text_field((string) ($entity_row['label_text'] ?? ''));
                if ($label_text !== '') {
                    $state_bits[] = 'label=' . $label_text;
                }
                $classification = sanitize_key((string) ($entity_row['classification'] ?? ''));
                if ($classification !== '') {
                    $state_bits[] = 'classification=' . $classification;
                }
                $group_memberships = isset($entity_row['group_memberships']) && is_array($entity_row['group_memberships']) ? array_values(array_filter(array_map('sanitize_key', $entity_row['group_memberships']))) : array();
                if (!empty($group_memberships)) {
                    $state_bits[] = 'groups=' . implode(',', $group_memberships);
                }
                $approval_memberships = isset($entity_row['approval_memberships']) && is_array($entity_row['approval_memberships']) ? array_values(array_filter(array_map('sanitize_key', $entity_row['approval_memberships']))) : array();
                if (!empty($approval_memberships)) {
                    $state_bits[] = 'approvals=' . implode(',', $approval_memberships);
                }
                $widget_ids = isset($entity_row['widget_ids']) && is_array($entity_row['widget_ids']) ? array_values(array_filter(array_map('sanitize_key', $entity_row['widget_ids']))) : array();
                if (!empty($widget_ids)) {
                    $state_bits[] = 'widget_ids=' . implode(',', $widget_ids);
                }
                $relation = sanitize_key((string) ($entity_row['relation'] ?? ''));
                if ($relation !== '') {
                    $state_bits[] = 'relation=' . $relation;
                }
                $from = sanitize_key((string) ($entity_row['from'] ?? ''));
                $to = sanitize_key((string) ($entity_row['to'] ?? ''));
                if ($from !== '' || $to !== '') {
                    $state_bits[] = 'from=' . $from . ';to=' . $to;
                }

                echo '<tr>';
                echo '<td><code>' . esc_html($stable_id) . '</code></td>';
                echo '<td>' . esc_html($entity_type) . '</td>';
                echo '<td>' . esc_html((string) $page_number) . '</td>';
                echo '<td>' . esc_html($widget_or_group) . '</td>';
                echo '<td>' . esc_html(!empty($state_bits) ? implode(' | ', $state_bits) : '—') . '</td>';
                echo '</tr>';
            }
            echo '</tbody></table>';
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
        $existing_patch_raw = (string) get_post_meta($review_id, '_dcb_ocr_review_canonical_graph_patch', true);
        echo '<p><label for="dcb-ocr-canonical-patch-json"><strong>Canonical Graph Patch JSON (optional)</strong></label><br/>';
        echo '<textarea id="dcb-ocr-canonical-patch-json" name="canonical_graph_patch_json" rows="8" style="width:100%;" class="code">' . esc_textarea($existing_patch_raw !== '' ? $existing_patch_raw : '{}') . '</textarea></p>';
        submit_button(__('Save Manual Corrections', 'document-center-builder'), 'secondary', 'submit', false);
        echo '</form>';

        echo '<hr/>';
        echo '<h4>Canonical Patch Bridge</h4>';
        echo '<p class="description">Validate/apply low-risk canonical graph edits by stable ID and inspect KPI deltas.</p>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field('dcb_ocr_review_patch_bridge_' . $review_id, 'dcb_ocr_patch_bridge_nonce');
        echo '<input type="hidden" name="action" value="dcb_ocr_review_patch_bridge" />';
        echo '<input type="hidden" name="review_id" value="' . esc_attr((string) $review_id) . '" />';
        echo '<input type="hidden" name="persist" value="1" />';
        echo '<p><label for="dcb-ocr-stable-ids-json"><strong>Stable IDs JSON (optional filter)</strong></label><br/>';
        echo '<textarea id="dcb-ocr-stable-ids-json" name="stable_ids_json" rows="3" style="width:100%;" class="code">[]</textarea></p>';
        echo '<p><label for="dcb-ocr-bridge-patch-json"><strong>Patch JSON</strong></label><br/>';
        echo '<textarea id="dcb-ocr-bridge-patch-json" name="canonical_graph_patch_json" rows="8" style="width:100%;" class="code">' . esc_textarea($existing_patch_raw !== '' ? $existing_patch_raw : '{}') . '</textarea></p>';

        echo '<h4 style="margin-top:12px;">Quick Patch Builder (Optional)</h4>';
        echo '<p class="description">Low-risk helpers for high-value fixes. Leave blank to skip any row. You can combine with Patch JSON above.</p>';
        echo '<table class="widefat striped" style="max-width:1080px"><tbody>';
        echo '<tr><th style="width:280px">False Positive Remove / Reclassify</th><td>';
        echo '<label>Stable ID <input type="text" name="quick_false_positive_stable_id" value="" style="width:180px" /></label> ';
        echo '<label>Action <select name="quick_false_positive_action"><option value="reclassify_fixed">Reclassify as fixed</option><option value="reclassify_fillable">Reclassify as fillable</option><option value="none">No change</option></select></label> ';
        echo '<label>Widget Type <input type="text" name="quick_false_positive_widget_type" value="" style="width:150px" placeholder="optional" /></label>';
        echo '</td></tr>';
        echo '<tr><th>Group Membership Fix</th><td>';
        echo '<label>Widget Stable ID <input type="text" name="quick_group_stable_id" value="" style="width:180px" /></label> ';
        echo '<label>Add groups (csv) <input type="text" name="quick_group_add_csv" value="" style="width:260px" /></label> ';
        echo '<label>Remove groups (csv) <input type="text" name="quick_group_remove_csv" value="" style="width:260px" /></label>';
        echo '</td></tr>';
        echo '<tr><th>Approval Block Membership Fix</th><td>';
        echo '<label>Widget Stable ID <input type="text" name="quick_approval_stable_id" value="" style="width:180px" /></label> ';
        echo '<label>Add approvals (csv) <input type="text" name="quick_approval_add_csv" value="" style="width:260px" /></label> ';
        echo '<label>Remove approvals (csv) <input type="text" name="quick_approval_remove_csv" value="" style="width:260px" /></label>';
        echo '</td></tr>';
        echo '<tr><th>Key Relation Fix</th><td>';
        echo '<label>From <input type="text" name="quick_relation_from" value="" style="width:180px" /></label> ';
        echo '<label>To <input type="text" name="quick_relation_to" value="" style="width:180px" /></label> ';
        echo '<label>Relation <input type="text" name="quick_relation_kind" value="paired_signature_date" style="width:200px" /></label> ';
        echo '<label>Decision <select name="quick_relation_decision"><option value="upsert">Upsert</option><option value="remove">Remove</option></select></label>';
        echo '</td></tr>';
        echo '<tr><th>Fillable vs Fixed Correction</th><td>';
        echo '<label>Stable ID <input type="text" name="quick_fillable_stable_id" value="" style="width:180px" /></label> ';
        echo '<label>Classification <select name="quick_fillable_classification"><option value="">No change</option><option value="fillable">fillable</option><option value="fixed_text">fixed_text</option></select></label>';
        echo '</td></tr>';
        echo '</tbody></table>';
        echo '<p>';
        submit_button(__('Validate Patch', 'document-center-builder'), 'secondary', 'submit', false, array('name' => 'validate_only', 'value' => '1'));
        echo ' ';
        submit_button(__('Apply Patch + Save', 'document-center-builder'), 'primary', 'submit', false, array('name' => 'apply_patch', 'value' => '1'));
        echo '</p>';
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
        $canonical_patch_json = isset($_POST['canonical_graph_patch_json']) ? (string) wp_unslash($_POST['canonical_graph_patch_json']) : '{}';
        $canonical_patch = json_decode($canonical_patch_json, true);
        if (!is_array($canonical_patch)) {
            $canonical_patch = array();
        }

        $payload = array(
            'text_summary' => $summary,
            'candidate_fields' => $candidates,
        );
        if (!empty($canonical_patch)) {
            $payload['canonical_graph_patch'] = $canonical_patch;
        }

        if (function_exists('dcb_ocr_review_apply_manual_corrections')) {
            dcb_ocr_review_apply_manual_corrections($review_id, $payload);
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

    public static function handle_review_patch_bridge(): void {
        if (!DCB_Permissions::can(DCB_Permissions::CAP_RUN_OCR_TOOLS)) {
            wp_die('Unauthorized');
        }

        $review_id = isset($_REQUEST['review_id']) ? (int) $_REQUEST['review_id'] : 0;
        if ($review_id < 1) {
            wp_die('Missing review id');
        }

        $nonce = isset($_REQUEST['dcb_ocr_patch_bridge_nonce'])
            ? sanitize_text_field((string) wp_unslash($_REQUEST['dcb_ocr_patch_bridge_nonce']))
            : (isset($_REQUEST['nonce']) ? sanitize_text_field((string) wp_unslash($_REQUEST['nonce'])) : '');
        if ($nonce === '' || !wp_verify_nonce($nonce, 'dcb_ocr_review_patch_bridge_' . $review_id)) {
            wp_die('Invalid nonce');
        }

        $stable_ids_json = isset($_REQUEST['stable_ids_json']) ? (string) wp_unslash($_REQUEST['stable_ids_json']) : '[]';
        $stable_ids = json_decode($stable_ids_json, true);
        if (!is_array($stable_ids)) {
            $stable_ids = array();
        }

        $patch_json = isset($_REQUEST['canonical_graph_patch_json']) ? (string) wp_unslash($_REQUEST['canonical_graph_patch_json']) : '{}';
        $patch = json_decode($patch_json, true);
        if (!is_array($patch)) {
            $patch = array();
        }
        $quick_patch = self::build_quick_patch_from_request($_REQUEST);
        if (!empty($quick_patch)) {
            $patch = self::merge_canonical_patch_payloads($patch, $quick_patch);
        }

        $request = array(
            'stable_ids' => $stable_ids,
            'canonical_graph_patch' => $patch,
            'validate_only' => !empty($_REQUEST['validate_only']),
            'apply_patch' => !empty($_REQUEST['apply_patch']),
            'persist' => !isset($_REQUEST['persist']) || (string) $_REQUEST['persist'] !== '0',
        );

        $result = function_exists('dcb_ocr_review_patch_bridge')
            ? dcb_ocr_review_patch_bridge($review_id, $request)
            : array('ok' => false, 'message' => 'Patch bridge unavailable.');

        if (function_exists('update_post_meta')) {
            update_post_meta($review_id, '_dcb_ocr_review_patch_bridge_last_result', wp_json_encode($result));
        }

        $wants_json = wp_doing_ajax() || (isset($_REQUEST['response']) && sanitize_key((string) $_REQUEST['response']) === 'json');
        if ($wants_json) {
            if (!empty($result['ok'])) {
                wp_send_json_success($result);
            }
            wp_send_json_error($result, 422);
        }

        $back = admin_url('post.php?post=' . $review_id . '&action=edit');
        wp_safe_redirect($back);
        exit;
    }

    private static function csv_to_stable_id_list(string $csv): array {
        $parts = preg_split('/[\s,]+/', strtolower(trim($csv)));
        if (!is_array($parts)) {
            return array();
        }
        $out = array();
        foreach ($parts as $part) {
            $id = sanitize_key((string) $part);
            if ($id !== '') {
                $out[] = $id;
            }
        }
        return array_values(array_unique($out));
    }

    private static function build_quick_patch_from_request(array $request): array {
        $widgets = array();
        $relations = array();
        $categories = array();

        $false_positive_stable_id = sanitize_key((string) ($request['quick_false_positive_stable_id'] ?? ''));
        $false_positive_action = sanitize_key((string) ($request['quick_false_positive_action'] ?? ''));
        $false_positive_widget_type = sanitize_key((string) ($request['quick_false_positive_widget_type'] ?? ''));
        if ($false_positive_stable_id !== '' && in_array($false_positive_action, array('reclassify_fixed', 'reclassify_fillable'), true)) {
            $widgets[$false_positive_stable_id] = array(
                'stable_id' => $false_positive_stable_id,
                'classification' => $false_positive_action === 'reclassify_fillable' ? 'fillable' : 'fixed_text',
                'widget_type' => $false_positive_widget_type !== '' ? $false_positive_widget_type : null,
            );
            $categories[] = 'false_positive_removal';
            $categories[] = 'fillable_fixed_classification_correction';
        }

        $group_widget_sid = sanitize_key((string) ($request['quick_group_stable_id'] ?? ''));
        $group_add = self::csv_to_stable_id_list((string) ($request['quick_group_add_csv'] ?? ''));
        $group_remove = self::csv_to_stable_id_list((string) ($request['quick_group_remove_csv'] ?? ''));
        if ($group_widget_sid !== '' && (!empty($group_add) || !empty($group_remove))) {
            if (!isset($widgets[$group_widget_sid])) {
                $widgets[$group_widget_sid] = array('stable_id' => $group_widget_sid);
            }
            $widgets[$group_widget_sid]['group_membership'] = array(
                'add' => $group_add,
                'remove' => $group_remove,
            );
            $categories[] = 'group_membership_correction';
        }

        $approval_widget_sid = sanitize_key((string) ($request['quick_approval_stable_id'] ?? ''));
        $approval_add = self::csv_to_stable_id_list((string) ($request['quick_approval_add_csv'] ?? ''));
        $approval_remove = self::csv_to_stable_id_list((string) ($request['quick_approval_remove_csv'] ?? ''));
        if ($approval_widget_sid !== '' && (!empty($approval_add) || !empty($approval_remove))) {
            if (!isset($widgets[$approval_widget_sid])) {
                $widgets[$approval_widget_sid] = array('stable_id' => $approval_widget_sid);
            }
            $widgets[$approval_widget_sid]['approval_block_membership'] = array(
                'add' => $approval_add,
                'remove' => $approval_remove,
            );
            $categories[] = 'approval_block_correction';
        }

        $relation_from = sanitize_key((string) ($request['quick_relation_from'] ?? ''));
        $relation_to = sanitize_key((string) ($request['quick_relation_to'] ?? ''));
        $relation_kind = sanitize_key((string) ($request['quick_relation_kind'] ?? 'paired_signature_date'));
        $relation_decision = sanitize_key((string) ($request['quick_relation_decision'] ?? 'upsert'));
        if ($relation_from !== '' && $relation_to !== '' && in_array($relation_decision, array('upsert', 'remove'), true)) {
            $relations[] = array(
                'decision' => $relation_decision,
                'from' => $relation_from,
                'to' => $relation_to,
                'relation' => $relation_kind !== '' ? $relation_kind : 'related_to',
            );
            $categories[] = 'relation_correction';
        }

        $fillable_sid = sanitize_key((string) ($request['quick_fillable_stable_id'] ?? ''));
        $fillable_classification = sanitize_key((string) ($request['quick_fillable_classification'] ?? ''));
        if ($fillable_sid !== '' && in_array($fillable_classification, array('fillable', 'fixed_text'), true)) {
            if (!isset($widgets[$fillable_sid])) {
                $widgets[$fillable_sid] = array('stable_id' => $fillable_sid);
            }
            $widgets[$fillable_sid]['classification'] = $fillable_classification;
            $categories[] = 'fillable_fixed_classification_correction';
        }

        if (empty($widgets) && empty($relations)) {
            return array();
        }

        return array(
            'patch_version' => '1.0',
            'patch_id' => 'quick_review_patch',
            'meta' => array(
                'source' => 'review_quick_patch',
                'patch_categories' => array_values(array_unique(array_filter(array_map('sanitize_key', $categories)))),
            ),
            'widgets' => array_values($widgets),
            'relations' => $relations,
        );
    }

    private static function merge_canonical_patch_payloads(array $base_patch, array $extra_patch): array {
        if (empty($base_patch)) {
            return $extra_patch;
        }
        if (empty($extra_patch)) {
            return $base_patch;
        }

        if (!isset($base_patch['widgets']) || !is_array($base_patch['widgets'])) {
            $base_patch['widgets'] = array();
        }
        if (!isset($base_patch['relations']) || !is_array($base_patch['relations'])) {
            $base_patch['relations'] = array();
        }

        $widget_map = array();
        foreach ($base_patch['widgets'] as $widget_row) {
            if (!is_array($widget_row)) {
                continue;
            }
            $sid = sanitize_key((string) ($widget_row['stable_id'] ?? ''));
            if ($sid === '') {
                continue;
            }
            $widget_map[$sid] = $widget_row;
        }

        foreach ((array) ($extra_patch['widgets'] ?? array()) as $widget_row) {
            if (!is_array($widget_row)) {
                continue;
            }
            $sid = sanitize_key((string) ($widget_row['stable_id'] ?? ''));
            if ($sid === '') {
                continue;
            }
            $existing = isset($widget_map[$sid]) && is_array($widget_map[$sid]) ? $widget_map[$sid] : array('stable_id' => $sid);
            foreach (array('label_text', 'widget_type', 'classification') as $field_key) {
                if (array_key_exists($field_key, $widget_row) && $widget_row[$field_key] !== null && $widget_row[$field_key] !== '') {
                    $existing[$field_key] = $widget_row[$field_key];
                }
            }
            foreach (array('group_membership', 'approval_block_membership') as $membership_key) {
                if (!isset($widget_row[$membership_key]) || !is_array($widget_row[$membership_key])) {
                    continue;
                }
                $existing_membership = isset($existing[$membership_key]) && is_array($existing[$membership_key]) ? $existing[$membership_key] : array();
                $existing_add = isset($existing_membership['add']) && is_array($existing_membership['add']) ? $existing_membership['add'] : array();
                $existing_remove = isset($existing_membership['remove']) && is_array($existing_membership['remove']) ? $existing_membership['remove'] : array();
                $incoming_add = isset($widget_row[$membership_key]['add']) && is_array($widget_row[$membership_key]['add']) ? $widget_row[$membership_key]['add'] : array();
                $incoming_remove = isset($widget_row[$membership_key]['remove']) && is_array($widget_row[$membership_key]['remove']) ? $widget_row[$membership_key]['remove'] : array();
                $existing[$membership_key] = array(
                    'add' => array_values(array_unique(array_filter(array_map('sanitize_key', array_merge($existing_add, $incoming_add))))),
                    'remove' => array_values(array_unique(array_filter(array_map('sanitize_key', array_merge($existing_remove, $incoming_remove))))),
                );
            }
            $widget_map[$sid] = $existing;
        }

        $base_patch['widgets'] = array_values($widget_map);
        $base_patch['relations'] = array_values(array_merge($base_patch['relations'], (array) ($extra_patch['relations'] ?? array())));

        $base_meta = isset($base_patch['meta']) && is_array($base_patch['meta']) ? $base_patch['meta'] : array();
        $extra_meta = isset($extra_patch['meta']) && is_array($extra_patch['meta']) ? $extra_patch['meta'] : array();
        $base_categories = isset($base_meta['patch_categories']) && is_array($base_meta['patch_categories']) ? $base_meta['patch_categories'] : array();
        $extra_categories = isset($extra_meta['patch_categories']) && is_array($extra_meta['patch_categories']) ? $extra_meta['patch_categories'] : array();
        $base_meta['patch_categories'] = array_values(array_unique(array_filter(array_map('sanitize_key', array_merge($base_categories, $extra_categories)))));
        if (!isset($base_meta['source']) || sanitize_key((string) $base_meta['source']) === '') {
            $base_meta['source'] = sanitize_key((string) ($extra_meta['source'] ?? 'review_patch'));
        }
        $base_patch['meta'] = $base_meta;

        return $base_patch;
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
