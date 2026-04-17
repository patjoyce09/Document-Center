<?php

if (!defined('ABSPATH')) {
    exit;
}

final class DCB_Chart_Routing {
    private const POST_TYPE = 'dcb_chart_route_queue';

    public static function init(): void {
        add_action('init', array(__CLASS__, 'register_post_type'), 22);
        add_action('admin_menu', array(__CLASS__, 'register_admin_page'));
        add_action('admin_post_dcb_chart_routing_action', array(__CLASS__, 'handle_queue_action'));
        add_action('dcb_upload_artifact_logged', array(__CLASS__, 'handle_upload_artifact_logged'), 10, 2);
        add_action('dcb_ocr_review_status_changed', array(__CLASS__, 'handle_ocr_review_status_changed'), 20, 4);
    }

    public static function register_post_type(): void {
        register_post_type(self::POST_TYPE, array(
            'labels' => array(
                'name' => __('Chart Routing Queue', 'document-center-builder'),
                'singular_name' => __('Chart Routing Item', 'document-center-builder'),
            ),
            'public' => false,
            'show_ui' => false,
            'show_in_menu' => false,
            'supports' => array('title'),
            'capability_type' => array('dcb_chart_route_item', 'dcb_chart_route_items'),
            'capabilities' => self::post_type_capabilities(),
            'map_meta_cap' => true,
        ));
    }

    public static function register_admin_page(): void {
        add_submenu_page(
            'dcb-dashboard',
            __('Chart Routing Queue', 'document-center-builder'),
            __('Chart Routing Queue', 'document-center-builder'),
            DCB_Permissions::CAP_REVIEW_SUBMISSIONS,
            'dcb-chart-routing',
            array(__CLASS__, 'render_queue_page')
        );
    }

    private static function post_type_capabilities(): array {
        return array(
            'edit_post' => DCB_Permissions::CAP_MANAGE_WORKFLOWS,
            'read_post' => DCB_Permissions::CAP_REVIEW_SUBMISSIONS,
            'delete_post' => DCB_Permissions::CAP_MANAGE_WORKFLOWS,
            'edit_posts' => DCB_Permissions::CAP_MANAGE_WORKFLOWS,
            'edit_others_posts' => DCB_Permissions::CAP_MANAGE_WORKFLOWS,
            'publish_posts' => DCB_Permissions::CAP_MANAGE_WORKFLOWS,
            'read_private_posts' => DCB_Permissions::CAP_REVIEW_SUBMISSIONS,
            'delete_posts' => DCB_Permissions::CAP_MANAGE_WORKFLOWS,
            'delete_private_posts' => DCB_Permissions::CAP_MANAGE_WORKFLOWS,
            'delete_published_posts' => DCB_Permissions::CAP_MANAGE_WORKFLOWS,
            'delete_others_posts' => DCB_Permissions::CAP_MANAGE_WORKFLOWS,
            'edit_private_posts' => DCB_Permissions::CAP_MANAGE_WORKFLOWS,
            'edit_published_posts' => DCB_Permissions::CAP_MANAGE_WORKFLOWS,
            'create_posts' => DCB_Permissions::CAP_MANAGE_WORKFLOWS,
        );
    }

    private static function can_view_queue(): bool {
        return DCB_Permissions::can(DCB_Permissions::CAP_REVIEW_SUBMISSIONS) || DCB_Permissions::can(DCB_Permissions::CAP_MANAGE_WORKFLOWS);
    }

    private static function can_act_on_queue(): bool {
        return DCB_Permissions::can(DCB_Permissions::CAP_MANAGE_WORKFLOWS);
    }

    public static function handle_upload_artifact_logged(int $upload_log_id, array $context = array()): void {
        $upload_log_id = max(0, $upload_log_id);
        if ($upload_log_id < 1) {
            return;
        }

        $existing_queue_id = (int) get_post_meta($upload_log_id, '_dcb_upload_chart_route_queue_id', true);
        if ($existing_queue_id > 0) {
            return;
        }

        $trace_id = sanitize_text_field((string) ($context['trace_id'] ?? get_post_meta($upload_log_id, '_dcb_upload_trace_id', true)));
        $review_item_id = max(0, (int) ($context['review_item_id'] ?? get_post_meta($upload_log_id, '_dcb_upload_ocr_review_item_id', true)));
        $ocr_text = (string) ($context['ocr_text'] ?? get_post_meta($upload_log_id, '_dcb_upload_ocr_text', true));
        $hint = sanitize_key((string) ($context['hint'] ?? get_post_meta($upload_log_id, '_dcb_upload_hint', true)));
        $source_channel = sanitize_key((string) ($context['source_channel'] ?? get_post_meta($upload_log_id, '_dcb_upload_source_channel', true)));

        $identifiers = dcb_chart_routing_extract_identifiers(array(
            'ocr_text' => $ocr_text,
            'source_channel' => $source_channel,
            'document_type_hint' => $hint,
            'extracted_identifiers' => isset($context['extracted_identifiers']) && is_array($context['extracted_identifiers']) ? $context['extracted_identifiers'] : array(),
        ));

        $doc_type = dcb_chart_routing_classify_document_type(array(
            'ocr_text' => $ocr_text,
            'hint' => $hint,
        ));

        $connector = self::connector();
        $candidates = array();

        $connector_candidates = $connector->search_patient_candidates($identifiers, $context);
        if (!empty($connector_candidates)) {
            $candidates = array_merge($candidates, $connector_candidates);
        }

        $filtered_candidates = function_exists('apply_filters')
            ? apply_filters('dcb_chart_routing_candidate_pool', array(), $upload_log_id, $identifiers, $context)
            : array();
        if (is_array($filtered_candidates) && !empty($filtered_candidates)) {
            $candidates = array_merge($candidates, array_values(array_filter($filtered_candidates, 'is_array')));
        }

        $match = dcb_chart_routing_build_match_result($identifiers, $candidates);
        $route_status = self::status_for_match($match);

        $queue_id = wp_insert_post(array(
            'post_type' => self::POST_TYPE,
            'post_status' => 'publish',
            'post_title' => sprintf(
                'Chart Route — Upload %d — %s',
                $upload_log_id,
                current_time('mysql')
            ),
        ));

        if (is_wp_error($queue_id) || (int) $queue_id < 1) {
            return;
        }

        $queue_id = (int) $queue_id;
        $top_candidate = isset($match['top_candidate']) && is_array($match['top_candidate']) ? $match['top_candidate'] : array();
        $queue_payload = dcb_chart_routing_queue_payload_shape(array(
            'source_artifact_id' => $upload_log_id,
            'trace_id' => $trace_id,
            'extracted_identifiers' => $identifiers,
            'candidate_count' => count((array) ($match['candidates'] ?? array())),
            'confidence_tier' => (string) ($match['confidence_tier'] ?? 'no_match'),
            'confidence_score' => (float) ($match['confidence_score'] ?? 0.0),
            'document_type' => (string) ($doc_type['document_type'] ?? 'miscellaneous'),
            'document_type_confidence' => (float) ($doc_type['confidence'] ?? 0.0),
            'route_status' => $route_status,
            'connector_mode' => self::connector_mode(),
        ));

        update_post_meta($queue_id, '_dcb_chart_route_source_upload_log_id', $upload_log_id);
        update_post_meta($queue_id, '_dcb_chart_route_source_review_id', $review_item_id);
        update_post_meta($queue_id, '_dcb_chart_route_trace_id', $trace_id);
        update_post_meta($queue_id, '_dcb_chart_route_extracted_identifiers', $identifiers);
        update_post_meta($queue_id, '_dcb_chart_route_candidates', (array) ($match['candidates'] ?? array()));
        update_post_meta($queue_id, '_dcb_chart_route_confidence_tier', (string) ($match['confidence_tier'] ?? 'no_match'));
        update_post_meta($queue_id, '_dcb_chart_route_confidence_score', round((float) ($match['confidence_score'] ?? 0.0), 4));
        update_post_meta($queue_id, '_dcb_chart_route_document_type', $doc_type);
        update_post_meta($queue_id, '_dcb_chart_route_status', $route_status);
        update_post_meta($queue_id, '_dcb_chart_route_connector_mode', self::connector_mode());
        update_post_meta($queue_id, '_dcb_chart_route_selected_candidate', $top_candidate);
        update_post_meta($queue_id, '_dcb_chart_route_queue_payload', $queue_payload);
        update_post_meta($queue_id, '_dcb_chart_route_created_at', current_time('mysql'));

        if ($upload_log_id > 0) {
            update_post_meta($upload_log_id, '_dcb_upload_chart_route_queue_id', $queue_id);
        }
        if ($review_item_id > 0) {
            update_post_meta($review_item_id, '_dcb_ocr_review_chart_route_queue_id', $queue_id);
        }

        self::append_audit($queue_id, 'queued', array(
            'source_artifact_id' => $upload_log_id,
            'trace_id' => $trace_id,
            'extracted_identifiers_snapshot' => $identifiers,
            'candidate_list_summary' => self::candidate_summary((array) ($match['candidates'] ?? array())),
            'chosen_patient_target' => $top_candidate,
            'chosen_document_type' => (string) ($doc_type['document_type'] ?? 'miscellaneous'),
            'route_method' => self::route_method(),
            'result' => 'queued',
            'result_message' => 'Routing item queued for review.',
        ));
    }

    public static function handle_ocr_review_status_changed(int $review_id, string $from_status, string $to_status, string $note): void {
        $review_id = max(0, $review_id);
        if ($review_id < 1) {
            return;
        }

        $queue_ids = get_posts(array(
            'post_type' => self::POST_TYPE,
            'post_status' => 'publish',
            'posts_per_page' => 1,
            'fields' => 'ids',
            'meta_query' => array(
                array(
                    'key' => '_dcb_chart_route_source_review_id',
                    'value' => $review_id,
                    'compare' => '=',
                ),
            ),
        ));

        if (empty($queue_ids)) {
            return;
        }

        $queue_id = (int) $queue_ids[0];
        update_post_meta($queue_id, '_dcb_chart_route_last_review_status', sanitize_key($to_status));
        self::append_audit($queue_id, 'review_status_updated', array(
            'result' => 'review_status_updated',
            'result_message' => sprintf('OCR review status changed from %s to %s.', sanitize_key($from_status), sanitize_key($to_status)),
            'note' => sanitize_text_field($note),
        ));
    }

    private static function status_for_match(array $match): string {
        $tier = sanitize_key((string) ($match['confidence_tier'] ?? 'no_match'));
        $name_only = !empty($match['name_only_guardrail_triggered']);

        if ($tier === 'high_confidence' && !$name_only) {
            return 'ready_for_confirmation';
        }
        if ($tier === 'no_match') {
            return 'manual_review';
        }
        return 'needs_review';
    }

    private static function connector_mode(): string {
        $mode = sanitize_key((string) get_option('dcb_chart_routing_mode', 'none_manual'));
        $allowed = array_keys(dcb_chart_routing_mode_labels());
        return in_array($mode, $allowed, true) ? $mode : 'none_manual';
    }

    private static function route_method(): string {
        $mode = self::connector_mode();
        if ($mode === 'api' || $mode === 'bot' || $mode === 'report_import') {
            return $mode;
        }
        return 'manual';
    }

    private static function connector(): DCB_Chart_Routing_Connector_Interface {
        $mode = self::connector_mode();
        $config = get_option('dcb_chart_routing_connector_config', array());
        if (!is_array($config)) {
            $config = array();
        }

        $connector = DCB_Chart_Routing_Connector_Factory::create($mode, $config);
        $filtered = function_exists('apply_filters')
            ? apply_filters('dcb_chart_routing_connector_adapter', $connector, $mode, $config)
            : $connector;

        return $filtered instanceof DCB_Chart_Routing_Connector_Interface ? $filtered : $connector;
    }

    public static function render_queue_page(): void {
        if (!self::can_view_queue()) {
            wp_die('Unauthorized');
        }

        $items = get_posts(array(
            'post_type' => self::POST_TYPE,
            'post_status' => 'publish',
            'posts_per_page' => 50,
            'orderby' => 'date',
            'order' => 'DESC',
            'fields' => 'ids',
        ));

        echo '<div class="wrap">';
        echo '<h1>Chart Routing Queue</h1>';
        echo '<p>Prototype queue for document-to-chart routing. Weak matches are never auto-routed.</p>';

        if (isset($_GET['dcb_chart_route_notice'])) {
            echo '<div class="notice notice-success"><p>' . esc_html(sanitize_text_field((string) $_GET['dcb_chart_route_notice'])) . '</p></div>';
        }
        if (isset($_GET['dcb_chart_route_error'])) {
            echo '<div class="notice notice-error"><p>' . esc_html(sanitize_text_field((string) $_GET['dcb_chart_route_error'])) . '</p></div>';
        }

        echo '<table class="widefat striped"><thead><tr>';
        echo '<th style="width:65px">Queue ID</th>';
        echo '<th style="width:180px">Artifact / Trace</th>';
        echo '<th>Extracted Identifiers</th>';
        echo '<th>Candidates</th>';
        echo '<th style="width:120px">Confidence</th>';
        echo '<th style="width:110px">Doc Type</th>';
        echo '<th style="width:120px">Route Status</th>';
        echo '<th style="width:360px">Actions</th>';
        echo '</tr></thead><tbody>';

        if (empty($items)) {
            echo '<tr><td colspan="8">No chart-routing queue items yet.</td></tr>';
        }

        foreach ($items as $queue_id) {
            $queue_id = (int) $queue_id;
            $upload_log_id = (int) get_post_meta($queue_id, '_dcb_chart_route_source_upload_log_id', true);
            $trace_id = sanitize_text_field((string) get_post_meta($queue_id, '_dcb_chart_route_trace_id', true));
            $identifiers = get_post_meta($queue_id, '_dcb_chart_route_extracted_identifiers', true);
            if (!is_array($identifiers)) {
                $identifiers = array();
            }
            $candidates = get_post_meta($queue_id, '_dcb_chart_route_candidates', true);
            if (!is_array($candidates)) {
                $candidates = array();
            }
            $status = sanitize_key((string) get_post_meta($queue_id, '_dcb_chart_route_status', true));
            $confidence_tier = sanitize_key((string) get_post_meta($queue_id, '_dcb_chart_route_confidence_tier', true));
            $confidence_score = (float) get_post_meta($queue_id, '_dcb_chart_route_confidence_score', true);
            $doc_type = get_post_meta($queue_id, '_dcb_chart_route_document_type', true);
            $doc_type_key = is_array($doc_type) ? dcb_chart_routing_normalize_document_type((string) ($doc_type['document_type'] ?? 'miscellaneous')) : 'miscellaneous';

            $artifact_name = sanitize_text_field((string) get_post_meta($upload_log_id, '_dcb_upload_title', true));
            $artifact_link = $upload_log_id > 0 ? admin_url('post.php?post=' . $upload_log_id . '&action=edit') : '';

            echo '<tr>';
            echo '<td>#' . esc_html((string) $queue_id) . '</td>';
            echo '<td>';
            if ($artifact_link !== '') {
                echo '<a href="' . esc_url($artifact_link) . '">' . esc_html($artifact_name !== '' ? $artifact_name : ('Upload #' . $upload_log_id)) . '</a><br />';
            } else {
                echo esc_html($artifact_name !== '' ? $artifact_name : 'Upload artifact missing') . '<br />';
            }
            echo '<small>Trace: ' . esc_html($trace_id !== '' ? $trace_id : 'n/a') . '</small>';
            echo '</td>';

            echo '<td>' . wp_kses_post(self::render_identifier_list($identifiers)) . '</td>';
            echo '<td>' . wp_kses_post(self::render_candidate_list($candidates)) . '</td>';
            echo '<td><strong>' . esc_html(ucwords(str_replace('_', ' ', $confidence_tier))) . '</strong><br /><small>' . esc_html(number_format($confidence_score, 3)) . '</small></td>';
            echo '<td>' . esc_html((string) (dcb_chart_routing_document_types()[$doc_type_key] ?? 'Miscellaneous')) . '</td>';
            echo '<td>' . esc_html(ucwords(str_replace('_', ' ', $status))) . '</td>';

            echo '<td>';
            if (!self::can_act_on_queue()) {
                echo '<em>Requires workflow-management capability.</em>';
            } else {
                self::render_row_action_form($queue_id, $candidates, $doc_type_key);
            }
            echo '</td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
        echo '</div>';
    }

    private static function render_identifier_list(array $identifiers): string {
        $rows = array();
        foreach ($identifiers as $key => $value) {
            $key = sanitize_key((string) $key);
            if ($key === '' || !is_scalar($value) || (string) $value === '') {
                continue;
            }
            $label = ucwords(str_replace('_', ' ', $key));
            $rows[] = '<div><strong>' . esc_html($label) . ':</strong> ' . esc_html(self::mask_identifier_value($key, (string) $value)) . '</div>';
        }
        return !empty($rows) ? implode('', $rows) : '<em>None extracted</em>';
    }

    private static function render_candidate_list(array $candidates): string {
        if (empty($candidates)) {
            return '<em>No candidates</em>';
        }

        $rows = array();
        $max = min(3, count($candidates));
        for ($i = 0; $i < $max; $i++) {
            $row = isset($candidates[$i]) && is_array($candidates[$i]) ? $candidates[$i] : array();
            $name = sanitize_text_field((string) ($row['full_name'] ?? ($row['patient_name'] ?? 'Unknown')));
            $tier = sanitize_key((string) ($row['confidence_tier'] ?? 'low_confidence'));
            $score = (float) ($row['score'] ?? 0.0);
            $reasons = isset($row['reasons']) && is_array($row['reasons']) ? array_slice($row['reasons'], 0, 2) : array();
            $rows[] = '<div><strong>' . esc_html(self::mask_identifier_value('patient_name', $name)) . '</strong> (' . esc_html(number_format($score, 3)) . ', ' . esc_html($tier) . ')<br /><small>' . esc_html(implode('; ', array_map('sanitize_text_field', $reasons))) . '</small></div>';
        }

        return implode('<hr style="margin:6px 0" />', $rows);
    }

    private static function render_row_action_form(int $queue_id, array $candidates, string $doc_type_key): void {
        $candidate_options = array();
        foreach ($candidates as $row) {
            if (!is_array($row)) {
                continue;
            }
            $key = sanitize_text_field((string) ($row['candidate_key'] ?? ''));
            if ($key === '') {
                continue;
            }
            $label = sanitize_text_field((string) ($row['full_name'] ?? ($row['patient_name'] ?? $key)));
            $score = (float) ($row['score'] ?? 0.0);
            $candidate_options[$key] = $label . ' (' . number_format($score, 3) . ')';
        }

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field('dcb_chart_routing_action_' . $queue_id, 'dcb_chart_routing_nonce');
        echo '<input type="hidden" name="action" value="dcb_chart_routing_action" />';
        echo '<input type="hidden" name="queue_id" value="' . esc_attr((string) $queue_id) . '" />';

        echo '<p><label><strong>Candidate</strong><br />';
        echo '<select name="candidate_key" style="width:100%">';
        echo '<option value="">Top candidate</option>';
        foreach ($candidate_options as $key => $label) {
            echo '<option value="' . esc_attr((string) $key) . '">' . esc_html((string) $label) . '</option>';
        }
        echo '</select></label></p>';

        echo '<p><label><strong>Document Type</strong><br />';
        echo '<select name="document_type" style="width:100%">';
        foreach (dcb_chart_routing_document_types() as $type_key => $label) {
            echo '<option value="' . esc_attr((string) $type_key) . '" ' . selected($type_key, $doc_type_key, false) . '>' . esc_html((string) $label) . '</option>';
        }
        echo '</select></label></p>';

        echo '<p><label><strong>Routing Note</strong><br />';
        echo '<textarea name="routing_note" rows="2" style="width:100%"></textarea></label></p>';

        echo '<p style="display:flex;gap:6px;flex-wrap:wrap">';
        echo '<button class="button button-secondary" type="submit" name="task" value="confirm_match">Confirm Match</button>';
        echo '<button class="button button-secondary" type="submit" name="task" value="select_alternate_candidate">Select Candidate</button>';
        echo '<button class="button" type="submit" name="task" value="queue_manual_review">Queue Manual Review</button>';
        echo '<button class="button" type="submit" name="task" value="mark_no_reliable_match">No Reliable Match</button>';
        echo '<button class="button button-primary" type="submit" name="task" value="route_attach">Route / Attach</button>';
        echo '</p>';

        echo '</form>';
    }

    public static function handle_queue_action(): void {
        if (!self::can_act_on_queue()) {
            wp_die('Unauthorized');
        }

        $queue_id = isset($_POST['queue_id']) ? (int) $_POST['queue_id'] : 0;
        $task = sanitize_key((string) ($_POST['task'] ?? ''));
        $candidate_key = sanitize_text_field((string) ($_POST['candidate_key'] ?? ''));
        $document_type = dcb_chart_routing_normalize_document_type((string) ($_POST['document_type'] ?? 'miscellaneous'));
        $routing_note = sanitize_textarea_field((string) ($_POST['routing_note'] ?? ''));

        if ($queue_id < 1 || $task === '') {
            wp_die('Invalid action');
        }

        if (!isset($_POST['dcb_chart_routing_nonce']) || !wp_verify_nonce((string) $_POST['dcb_chart_routing_nonce'], 'dcb_chart_routing_action_' . $queue_id)) {
            wp_die('Security check failed');
        }

        $candidates = get_post_meta($queue_id, '_dcb_chart_route_candidates', true);
        if (!is_array($candidates)) {
            $candidates = array();
        }

        $selected = self::resolve_selected_candidate($candidates, $candidate_key);
        $status = sanitize_key((string) get_post_meta($queue_id, '_dcb_chart_route_status', true));
        if ($status === '') {
            $status = 'needs_review';
        }

        $message = 'Action completed.';
        $error = '';

        if ($task === 'confirm_match' || $task === 'select_alternate_candidate') {
            update_post_meta($queue_id, '_dcb_chart_route_selected_candidate', $selected);
            update_post_meta($queue_id, '_dcb_chart_route_status', 'match_confirmed');
            update_post_meta($queue_id, '_dcb_chart_route_selected_document_type', $document_type);
            self::append_audit($queue_id, $task, array(
                'chosen_patient_target' => $selected,
                'chosen_document_type' => $document_type,
                'route_method' => self::route_method(),
                'result' => 'confirmed',
                'result_message' => $task === 'confirm_match' ? 'Top match confirmed.' : 'Alternate candidate selected.',
                'note' => $routing_note,
            ));
            $message = $task === 'confirm_match' ? 'Match confirmed.' : 'Alternate candidate selected.';
        } elseif ($task === 'queue_manual_review') {
            update_post_meta($queue_id, '_dcb_chart_route_status', 'manual_review');
            self::append_audit($queue_id, 'queue_manual_review', array(
                'route_method' => self::route_method(),
                'result' => 'queued_manual_review',
                'result_message' => 'Queued for manual review.',
                'note' => $routing_note,
            ));
            $message = 'Queued for manual review.';
        } elseif ($task === 'mark_no_reliable_match') {
            update_post_meta($queue_id, '_dcb_chart_route_status', 'no_reliable_match');
            self::append_audit($queue_id, 'mark_no_reliable_match', array(
                'route_method' => self::route_method(),
                'result' => 'no_reliable_match',
                'result_message' => 'Marked no reliable match.',
                'note' => $routing_note,
            ));
            $message = 'Marked no reliable match.';
        } elseif ($task === 'route_attach') {
            if (empty($selected)) {
                $error = 'No patient/chart candidate selected.';
            } else {
                $connector = self::connector();
                $upload_log_id = (int) get_post_meta($queue_id, '_dcb_chart_route_source_upload_log_id', true);
                $trace_id = sanitize_text_field((string) get_post_meta($queue_id, '_dcb_chart_route_trace_id', true));
                $artifact = array(
                    'upload_log_id' => $upload_log_id,
                    'trace_id' => $trace_id,
                    'file_name' => sanitize_text_field((string) get_post_meta($upload_log_id, '_dcb_upload_title', true)),
                    'file_path' => sanitize_text_field((string) get_post_meta($upload_log_id, '_dcb_upload_path', true)),
                    'mime' => sanitize_text_field((string) get_post_meta($upload_log_id, '_dcb_upload_mime', true)),
                );

                $resolve = $connector->resolve_chart_target($selected, array(
                    'queue_id' => $queue_id,
                    'document_type' => $document_type,
                ));

                if (empty($resolve['ok'])) {
                    $error = sanitize_text_field((string) ($resolve['message'] ?? 'Could not resolve chart target.'));
                    update_post_meta($queue_id, '_dcb_chart_route_status', 'route_failed');
                    self::append_audit($queue_id, 'route_attach', array(
                        'chosen_patient_target' => $selected,
                        'chosen_document_type' => $document_type,
                        'route_method' => self::route_method(),
                        'result' => 'failed',
                        'result_message' => $error,
                        'note' => $routing_note,
                    ));
                } else {
                    $chart_target = isset($resolve['chart_target']) && is_array($resolve['chart_target']) ? $resolve['chart_target'] : array();
                    $attach = $connector->attach_document_to_chart($chart_target, $artifact, array(
                        'queue_id' => $queue_id,
                        'document_type' => $document_type,
                    ));

                    $ok = !empty($attach['ok']);
                    update_post_meta($queue_id, '_dcb_chart_route_selected_candidate', $selected);
                    update_post_meta($queue_id, '_dcb_chart_route_selected_document_type', $document_type);
                    update_post_meta($queue_id, '_dcb_chart_route_result', is_array($attach) ? $attach : array());
                    update_post_meta($queue_id, '_dcb_chart_route_status', $ok ? 'routed' : 'route_failed');

                    self::append_audit($queue_id, 'route_attach', array(
                        'chosen_patient_target' => $selected,
                        'chosen_document_type' => $document_type,
                        'route_method' => self::route_method(),
                        'result' => $ok ? 'success' : 'failed',
                        'result_message' => sanitize_text_field((string) ($attach['message'] ?? ($ok ? 'Routed.' : 'Route failed.'))),
                        'note' => $routing_note,
                    ));

                    if ($ok) {
                        $message = 'Route operation recorded.';
                    } else {
                        $error = sanitize_text_field((string) ($attach['message'] ?? 'Route failed.'));
                    }
                }
            }
        } else {
            wp_die('Unsupported task');
        }

        if ($routing_note !== '') {
            update_post_meta($queue_id, '_dcb_chart_route_last_note', $routing_note);
        }

        $args = array('page' => 'dcb-chart-routing');
        if ($error !== '') {
            $args['dcb_chart_route_error'] = rawurlencode($error);
        } else {
            $args['dcb_chart_route_notice'] = rawurlencode($message);
        }

        wp_safe_redirect(add_query_arg($args, admin_url('admin.php')));
        exit;
    }

    private static function resolve_selected_candidate(array $candidates, string $candidate_key): array {
        if ($candidate_key !== '') {
            foreach ($candidates as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $key = sanitize_text_field((string) ($row['candidate_key'] ?? ''));
                if ($key !== '' && hash_equals($key, $candidate_key)) {
                    return $row;
                }
            }
        }

        return !empty($candidates) && is_array($candidates[0]) ? $candidates[0] : array();
    }

    private static function mask_identifier_value(string $key, string $value): string {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        $key = sanitize_key($key);
        if ($key === 'patient_name') {
            $parts = array_values(array_filter(preg_split('/\s+/', $value) ?: array()));
            $masked_parts = array();
            foreach ($parts as $part) {
                $first = mb_substr($part, 0, 1);
                $masked_parts[] = $first . str_repeat('*', max(1, mb_strlen($part) - 1));
            }
            return implode(' ', $masked_parts);
        }

        if ($key === 'dob') {
            if (preg_match('/([0-9]{4})$/', $value, $m)) {
                return '**/**/' . $m[1];
            }
            return '**/**/**';
        }

        if ($key === 'mrn' || $key === 'patient_id') {
            $len = mb_strlen($value);
            if ($len <= 4) {
                return str_repeat('*', $len);
            }
            return str_repeat('*', $len - 4) . mb_substr($value, -4);
        }

        return $value;
    }

    private static function candidate_summary(array $candidates): array {
        $summary = array();
        $rows = array_slice($candidates, 0, 3);
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $summary[] = array(
                'candidate_key' => sanitize_text_field((string) ($row['candidate_key'] ?? '')),
                'name' => self::mask_identifier_value('patient_name', sanitize_text_field((string) ($row['full_name'] ?? ($row['patient_name'] ?? '')))),
                'score' => round((float) ($row['score'] ?? 0.0), 4),
                'confidence_tier' => sanitize_key((string) ($row['confidence_tier'] ?? 'low_confidence')),
                'evidence' => isset($row['evidence']) && is_array($row['evidence']) ? array_values(array_map('sanitize_key', $row['evidence'])) : array(),
            );
        }
        return $summary;
    }

    private static function append_audit(int $queue_id, string $event, array $payload): void {
        $rows = get_post_meta($queue_id, '_dcb_chart_route_audit_events', true);
        if (!is_array($rows)) {
            $rows = array();
        }

        $user = wp_get_current_user();
        $user_id = $user instanceof WP_User ? (int) $user->ID : 0;
        $user_name = $user instanceof WP_User ? (string) $user->display_name : '';

        $normalized = dcb_chart_routing_audit_payload_shape(array_merge($payload, array(
            'source_artifact_id' => (int) get_post_meta($queue_id, '_dcb_chart_route_source_upload_log_id', true),
            'trace_id' => sanitize_text_field((string) get_post_meta($queue_id, '_dcb_chart_route_trace_id', true)),
            'confirmed_by_user_id' => $user_id,
            'confirmed_by_name' => $user_name,
            'confirmed_at' => current_time('mysql'),
        )));

        $rows[] = array(
            'event' => sanitize_key($event),
            'time' => current_time('mysql'),
            'payload' => $normalized,
        );

        if (count($rows) > 250) {
            $rows = array_slice($rows, -250);
        }

        update_post_meta($queue_id, '_dcb_chart_route_audit_events', $rows);

        if (function_exists('do_action')) {
            do_action('dcb_chart_routing_audit_event', $queue_id, sanitize_key($event), $normalized);
        }
    }
}
