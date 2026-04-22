<?php

if (!defined('ABSPATH')) {
    exit;
}

function dcb_get_policy_versions(): array {
    return array(
        'consent_text_version' => (string) get_option('dcb_policy_consent_text_version', 'consent-default'),
        'attestation_text_version' => (string) get_option('dcb_policy_attestation_text_version', 'attestation-default'),
    );
}

function dcb_signature_data_is_valid(string $data_url): bool {
    $data_url = trim($data_url);
    if ($data_url === '' || strpos($data_url, 'data:image/png;base64,') !== 0) {
        return false;
    }

    $encoded = substr($data_url, strlen('data:image/png;base64,'));
    if ($encoded === false || strlen($encoded) < 80) {
        return false;
    }

    $decoded = base64_decode($encoded, true);
    if (!is_string($decoded) || strlen($decoded) < 40 || strlen($decoded) > 450000) {
        return false;
    }

    return strpos($decoded, "\x89PNG") === 0;
}

function dcb_decode_json_meta($value): array {
    if (is_array($value)) {
        return $value;
    }
    if (!is_string($value) || $value === '') {
        return array();
    }
    $decoded = json_decode($value, true);
    return is_array($decoded) ? $decoded : array();
}

function dcb_output_templates(): array {
    $templates = array(
        'dcb-digital-form-v1' => array(
            'label' => 'Default Digital Form',
            'header' => 'summary_header_v1',
            'field_layout' => 'label_value_table_v1',
            'signature_block' => 'signature_auto_v1',
            'footer' => 'evidence_footer_v1',
            'print_compact' => false,
        ),
        'dcb-digital-form-compact-v1' => array(
            'label' => 'Compact Digital Form',
            'header' => 'summary_header_v1',
            'field_layout' => 'label_value_table_compact_v1',
            'signature_block' => 'signature_auto_v1',
            'footer' => 'evidence_footer_v1',
            'print_compact' => true,
        ),
    );

    return function_exists('apply_filters') ? (array) apply_filters('dcb_output_templates', $templates) : $templates;
}

function dcb_output_template_key_for_submission(int $submission_id, array $normalized_submission, string $view = 'admin'): string {
    $existing = sanitize_key((string) get_post_meta($submission_id, '_dcb_output_template_key', true));
    $default = $existing !== '' ? $existing : 'dcb-digital-form-v1';
    $templates = dcb_output_templates();
    if (!isset($templates[$default])) {
        $default = 'dcb-digital-form-v1';
    }

    if (function_exists('apply_filters')) {
        $default = sanitize_key((string) apply_filters('dcb_output_template_key', $default, $submission_id, $normalized_submission, $view));
    }

    return isset($templates[$default]) ? $default : 'dcb-digital-form-v1';
}

function dcb_output_template_mapping(string $template_key, array $signature): array {
    $templates = dcb_output_templates();
    $template = isset($templates[$template_key]) && is_array($templates[$template_key]) ? $templates[$template_key] : ($templates['dcb-digital-form-v1'] ?? array());
    $signature_mode = sanitize_key((string) ($signature['mode'] ?? 'typed'));

    $mapping = array(
        'template_key' => $template_key,
        'header' => (string) ($template['header'] ?? 'summary_header_v1'),
        'field_layout' => (string) ($template['field_layout'] ?? 'label_value_table_v1'),
        'signature_block' => $signature_mode === 'drawn' ? 'signature_drawn_block_v1' : 'signature_typed_block_v1',
        'footer' => (string) ($template['footer'] ?? 'evidence_footer_v1'),
        'print_compact' => !empty($template['print_compact']),
        'output_contract_version' => '2.0',
    );

    return function_exists('apply_filters') ? (array) apply_filters('dcb_output_template_mapping', $mapping, $template_key, $signature) : $mapping;
}

function dcb_workflow_render_context(int $submission_id): array {
    $status = sanitize_key((string) get_post_meta($submission_id, '_dcb_workflow_status', true));
    if ($status === '') {
        $status = 'submitted';
    }

    $timeline = get_post_meta($submission_id, '_dcb_workflow_timeline', true);
    if (!is_array($timeline)) {
        $timeline = array();
    }

    $approved_at = '';
    $approved_by = '';
    $workflow_finalized_at = '';
    $workflow_finalized_by = '';
    foreach ($timeline as $row) {
        if (!is_array($row)) {
            continue;
        }
        $event = sanitize_key((string) ($row['event'] ?? ''));
        if ($event === 'approved') {
            $approved_at = sanitize_text_field((string) ($row['time'] ?? ''));
            $approved_by = sanitize_text_field((string) ($row['actor_name'] ?? ''));
        }
        if ($event === 'finalized') {
            $workflow_finalized_at = sanitize_text_field((string) ($row['time'] ?? ''));
            $workflow_finalized_by = sanitize_text_field((string) ($row['actor_name'] ?? ''));
        }
    }

    return array(
        'status' => $status,
        'timeline_count' => count($timeline),
        'approved_at' => $approved_at,
        'approved_by' => $approved_by,
        'workflow_finalized_at' => $workflow_finalized_at,
        'workflow_finalized_by' => $workflow_finalized_by,
    );
}

function dcb_get_submission_form_definition(int $submission_id): array {
    $snapshot = dcb_decode_json_meta(get_post_meta($submission_id, '_dcb_form_snapshot', true));
    if (!empty($snapshot)) {
        $normalized_snapshot = dcb_normalize_single_form($snapshot);
        if (is_array($normalized_snapshot)) {
            if (!isset($normalized_snapshot['label']) || (string) $normalized_snapshot['label'] === '') {
                $normalized_snapshot['label'] = (string) get_post_meta($submission_id, '_dcb_form_label', true);
            }
            if (!isset($normalized_snapshot['version']) || (int) $normalized_snapshot['version'] < 1) {
                $normalized_snapshot['version'] = max(1, (int) get_post_meta($submission_id, '_dcb_form_version', true));
            }
            return $normalized_snapshot;
        }
    }

    $form_key = sanitize_key((string) get_post_meta($submission_id, '_dcb_form_key', true));
    $forms = dcb_form_definitions(false);
    $form = isset($forms[$form_key]) && is_array($forms[$form_key]) ? $forms[$form_key] : array();
    if (!isset($form['label'])) {
        $form['label'] = (string) get_post_meta($submission_id, '_dcb_form_label', true);
    }
    if (!isset($form['fields']) || !is_array($form['fields'])) {
        $form['fields'] = array();
    }
    if (!isset($form['version']) || (int) $form['version'] < 1) {
        $form['version'] = max(1, (int) get_post_meta($submission_id, '_dcb_form_version', true));
    }
    return $form;
}

function dcb_normalize_submission_payload(int $submission_id): array {
    $post = get_post($submission_id);
    if (!$post instanceof WP_Post || $post->post_type !== 'dcb_form_submission') {
        return array();
    }

    $form = dcb_get_submission_form_definition($submission_id);
    $form_key = sanitize_key((string) get_post_meta($submission_id, '_dcb_form_key', true));
    $form_label = (string) get_post_meta($submission_id, '_dcb_form_label', true);
    if ($form_label === '') {
        $form_label = (string) ($form['label'] ?? $form_key);
    }

    $submitted_by = (int) get_post_meta($submission_id, '_dcb_form_submitted_by', true);
    $submitted_email = (string) get_post_meta($submission_id, '_dcb_form_submitted_by_email', true);
    $submitted_name = (string) get_post_meta($submission_id, '_dcb_form_submitted_by_name', true);

    $clean_data = dcb_decode_json_meta(get_post_meta($submission_id, '_dcb_form_data', true));
    $field_map = array();
    foreach ((array) ($form['fields'] ?? array()) as $field) {
        if (!is_array($field)) {
            continue;
        }
        $key = sanitize_key((string) ($field['key'] ?? ''));
        if ($key === '') {
            continue;
        }
        $field_map[$key] = array(
            'label' => (string) ($field['label'] ?? $key),
            'type' => (string) ($field['type'] ?? 'text'),
            'options' => isset($field['options']) && is_array($field['options']) ? $field['options'] : array(),
        );
    }

    $normalized_fields = array();
    foreach ($clean_data as $key => $value) {
        $clean_key = sanitize_key((string) $key);
        if ($clean_key === '') {
            continue;
        }
        $raw_val = is_scalar($value) ? (string) $value : '';
        $display_val = $raw_val;
        $field_type = (string) ($field_map[$clean_key]['type'] ?? 'text');
        $field_options = isset($field_map[$clean_key]['options']) && is_array($field_map[$clean_key]['options']) ? $field_map[$clean_key]['options'] : array();

        if ($field_type === 'checkbox') {
            $display_val = $raw_val === '1' ? 'Yes' : 'No';
        } elseif (($field_type === 'select' || $field_type === 'radio' || $field_type === 'yes_no') && $raw_val !== '' && isset($field_options[$raw_val])) {
            $display_val = (string) $field_options[$raw_val];
        }

        if ($raw_val === '') {
            $display_val = '—';
        }
        $normalized_fields[] = array(
            'key' => $clean_key,
            'label' => (string) ($field_map[$clean_key]['label'] ?? $clean_key),
            'type' => $field_type,
            'value' => $raw_val,
            'display_value' => $display_val,
        );
    }

    $signature = class_exists('DCB_Signatures')
        ? DCB_Signatures::get_submission_signature($submission_id)
        : array(
            'mode' => sanitize_key((string) get_post_meta($submission_id, '_dcb_form_signature_mode', true)),
            'signature_value' => (string) ($clean_data['signature_name'] ?? ''),
            'signer_display_name' => (string) get_post_meta($submission_id, '_dcb_form_signer_identity', true),
            'signer_user_id' => (int) get_post_meta($submission_id, '_dcb_form_submitted_by', true),
            'signature_timestamp' => (string) get_post_meta($submission_id, '_dcb_form_signature_timestamp', true),
            'signature_date' => (string) ($clean_data['signature_date'] ?? ''),
            'drawn_signature_hash' => (string) get_post_meta($submission_id, '_dcb_form_signature_drawn_sha256', true),
            'drawn_signature_available' => (string) get_post_meta($submission_id, '_dcb_form_signature_drawn_data', true) !== '',
        );

    $consent_text_version = (string) get_post_meta($submission_id, '_dcb_form_consent_text_version', true);
    $attestation_text_version = (string) get_post_meta($submission_id, '_dcb_form_attestation_text_version', true);
    $policy_versions = dcb_get_policy_versions();
    if ($consent_text_version === '') {
        $consent_text_version = (string) ($signature['consent_text_version'] ?? $policy_versions['consent_text_version']);
    }
    if ($attestation_text_version === '') {
        $attestation_text_version = (string) ($signature['attestation_text_version'] ?? $policy_versions['attestation_text_version']);
    }

    $workflow_context = dcb_workflow_render_context($submission_id);
    $output_version = (string) get_post_meta($submission_id, '_dcb_form_output_version', true);
    if ($output_version === '' && defined('DCB_VERSION')) {
        $output_version = DCB_VERSION;
    }
    $template_key = dcb_output_template_key_for_submission($submission_id, array(), 'final');

    $final_document_title = sprintf('%s — Final Submission #%d', $form_label !== '' ? $form_label : 'Digital Form', $submission_id);

    $normalized = array(
        'submission_id' => $submission_id,
        'form_key' => $form_key,
        'form_name' => $form_label,
        'form_version' => max(1, (int) ($form['version'] ?? get_post_meta($submission_id, '_dcb_form_version', true))),
        'submitted_timestamp' => (string) get_post_meta($submission_id, '_dcb_form_submitted_at', true),
        'submitter' => array('user_id' => $submitted_by, 'name' => $submitted_name, 'email' => $submitted_email),
        'hard_stop_passed' => (string) get_post_meta($submission_id, '_dcb_form_qa_passed', true) === '1',
        'fields' => $normalized_fields,
        'signature' => array(
            'mode' => (string) ($signature['mode'] ?? 'typed'),
            'typed_signature' => (string) ($signature['signature_value'] ?? ''),
            'signer_identity' => (string) ($signature['signer_display_name'] ?? ''),
            'signer_user_id' => (int) ($signature['signer_user_id'] ?? 0),
            'signature_date' => (string) ($signature['signature_date'] ?? ''),
            'signature_timestamp' => (string) ($signature['signature_timestamp'] ?? ''),
            'signature_timestamp_client' => (string) ($signature['signature_timestamp_client'] ?? ''),
            'signature_field_key' => (string) ($signature['signature_field_key'] ?? 'signature_name'),
            'signature_source' => (string) ($signature['signature_source'] ?? 'submission_form'),
            'drawn_signature_hash' => (string) ($signature['drawn_signature_hash'] ?? ''),
            'drawn_signature_available' => !empty($signature['drawn_signature_available']),
            'ip' => (string) ($signature['ip'] ?? ''),
            'user_agent' => (string) ($signature['user_agent'] ?? ''),
        ),
        'consent' => array(
            'consent_value' => (string) ($clean_data['esign_consent'] ?? ''),
            'attestation_value' => (string) ($clean_data['attest_truth'] ?? ''),
            'consent_text_version' => $consent_text_version,
            'attestation_text_version' => $attestation_text_version,
        ),
        'payload_hash' => (string) get_post_meta($submission_id, '_dcb_form_payload_hash', true),
        'workflow' => $workflow_context,
        'finalization' => array(
            'finalized_at' => (string) get_post_meta($submission_id, '_dcb_form_finalized_at', true),
            'finalized_by' => (int) get_post_meta($submission_id, '_dcb_form_finalized_by', true),
            'finalized_by_name' => (string) get_post_meta($submission_id, '_dcb_form_finalized_by_name', true),
            'output_version' => $output_version,
            'output_template_key' => $template_key,
            'approval_context' => array(
                'approved_at' => (string) ($workflow_context['approved_at'] ?? ''),
                'approved_by' => (string) ($workflow_context['approved_by'] ?? ''),
                'workflow_status' => (string) ($workflow_context['status'] ?? ''),
            ),
        ),
    );

    $mapping = dcb_output_template_mapping($template_key, (array) ($normalized['signature'] ?? array()));

    return array(
        'normalized_submission_data' => $normalized,
        'render_template_mapping' => $mapping,
        'export_context' => array(
            'export_contract_version' => '2.0',
            'output_template_key' => $template_key,
            'output_version' => $output_version,
            'finalized_at' => (string) ($normalized['finalization']['finalized_at'] ?? ''),
        ),
        'final_document_title' => $final_document_title,
    );
}

function dcb_render_field_value_chip(array $field): string {
    $label = (string) ($field['label'] ?? 'Field');
    $raw = (string) ($field['value'] ?? '');
    $display = (string) ($field['display_value'] ?? '—');
    $type = sanitize_key((string) ($field['type'] ?? 'text'));

    $value_markup = '<span style="font-weight:600;">' . esc_html($display !== '' ? $display : '—') . '</span>';
    if ($type === 'checkbox') {
        $checked = $raw === '1';
        $value_markup = '<span style="display:inline-flex;align-items:center;gap:6px;"><span style="display:inline-block;width:14px;height:14px;border:1px solid #99a7bf;border-radius:3px;text-align:center;line-height:13px;font-size:11px;">' . ($checked ? '&#10003;' : '&nbsp;') . '</span><span>' . esc_html($checked ? 'Yes' : 'No') . '</span></span>';
    }

    return '<div style="display:grid;grid-template-columns:minmax(180px,260px) 1fr;gap:8px;align-items:start;margin:6px 0;">'
        . '<div style="color:#3d4a60;font-weight:600;">' . esc_html($label) . '</div>'
        . '<div>' . $value_markup . '</div>'
        . '</div>';
}

function dcb_render_template_block_html(array $block): string {
    $type = sanitize_key((string) ($block['type'] ?? 'paragraph'));
    $text = (string) ($block['text'] ?? '');
    if ($type === 'heading' || $type === 'section_header') {
        $level = min(6, max(1, (int) ($block['level'] ?? ($type === 'heading' ? 2 : 3))));
        return '<h' . $level . ' style="margin:12px 0 8px;">' . esc_html($text) . '</h' . $level . '>';
    }
    if ($type === 'divider') {
        return '<hr style="margin:12px 0;border:none;border-top:1px solid #dbe3ee;" />';
    }
    return '<p style="margin:8px 0;white-space:pre-line;">' . esc_html($text) . '</p>';
}

function dcb_render_status_badge_html(string $status, string $view = 'admin'): string {
    $status = sanitize_key($status);
    if ($status === '') {
        $status = 'submitted';
    }

    $palette = array(
        'draft' => array('#f0f4fa', '#365079', '#cfd9ea'),
        'submitted' => array('#eef6ff', '#0b4f94', '#b9d5f4'),
        'in_review' => array('#fff7e8', '#8a5a00', '#f3d496'),
        'needs_correction' => array('#fff1f1', '#8f1f1f', '#efb8b8'),
        'approved' => array('#edf9f0', '#176a31', '#b3e3c0'),
        'rejected' => array('#fff0f0', '#8f2222', '#f0b7b7'),
        'finalized' => array('#e9f7f1', '#0f5b42', '#9ed7bf'),
    );

    $set = isset($palette[$status]) ? $palette[$status] : $palette['submitted'];
    $label = strtoupper(str_replace('_', ' ', $status));
    $weight = $status === 'approved' || $status === 'finalized' ? '800' : '700';
    $padding = $view === 'print' || $view === 'final' ? '6px 12px' : '4px 8px';

    return '<span style="display:inline-block;padding:' . esc_attr($padding) . ';border:1px solid ' . esc_attr($set[2]) . ';border-radius:999px;background:' . esc_attr($set[0]) . ';color:' . esc_attr($set[1]) . ';font-size:12px;font-weight:' . esc_attr($weight) . ';letter-spacing:.03em;">' . esc_html($label) . '</span>';
}

function dcb_render_signature_section(array $signature, int $submission_id, string $view = 'admin'): string {
    $mode = sanitize_key((string) ($signature['mode'] ?? 'typed'));
    $typed = (string) ($signature['typed_signature'] ?? '');
    $signer = (string) ($signature['signer_identity'] ?? '');
    $signer_user_id = (int) ($signature['signer_user_id'] ?? 0);
    $signature_date = (string) ($signature['signature_date'] ?? '');
    $signature_timestamp = (string) ($signature['signature_timestamp'] ?? '');
    $signature_field_key = (string) ($signature['signature_field_key'] ?? 'signature_name');
    $signature_source = (string) ($signature['signature_source'] ?? 'submission_form');
    $drawn_hash = (string) ($signature['drawn_signature_hash'] ?? '');
    $drawn = (string) get_post_meta($submission_id, '_dcb_form_signature_drawn_data', true);
    $show_private = function_exists('apply_filters') ? (bool) apply_filters('dcb_output_include_private_signature_meta', $view !== 'public', $submission_id, $view) : true;

    $html = '<div style="margin-top:14px;">';
    $html .= '<h3 style="margin-top:0;">Signature Evidence</h3>';
    $html .= '<div style="display:grid;gap:6px;">';
    $html .= '<div><strong>Mode:</strong> ' . esc_html($mode !== '' ? $mode : 'typed') . '</div>';
    $html .= '<div><strong>Signer:</strong> ' . esc_html($signer !== '' ? $signer : '—') . '</div>';
    if ($signer_user_id > 0) {
        $html .= '<div><strong>Signer User ID:</strong> ' . esc_html((string) $signer_user_id) . '</div>';
    }
    $html .= '<div><strong>Typed Signature:</strong> ' . esc_html($typed !== '' ? $typed : '—') . '</div>';
    $html .= '<div><strong>Signature Date:</strong> ' . esc_html($signature_date !== '' ? $signature_date : '—') . '</div>';
    $html .= '<div><strong>Signature Timestamp:</strong> ' . esc_html($signature_timestamp !== '' ? $signature_timestamp : '—') . '</div>';
    $html .= '<div><strong>Signature Field:</strong> ' . esc_html($signature_field_key) . ' <strong style="margin-left:8px;">Source:</strong> ' . esc_html($signature_source) . '</div>';
    if ($drawn_hash !== '') {
        $html .= '<div><strong>Drawn Signature SHA256:</strong> <code>' . esc_html($drawn_hash) . '</code></div>';
    }
    if ($show_private) {
        $ip = (string) ($signature['ip'] ?? '');
        $ua = (string) ($signature['user_agent'] ?? '');
        if ($ip !== '') {
            $html .= '<div><strong>IP:</strong> ' . esc_html($ip) . '</div>';
        }
        if ($ua !== '') {
            $html .= '<div><strong>User Agent:</strong> ' . esc_html($ua) . '</div>';
        }
    }
    $html .= '</div>';

    if ($mode === 'drawn' && $drawn !== '') {
        $html .= '<p><strong>Drawn Signature Preview:</strong><br><img alt="Drawn signature" style="max-width:360px;border:1px solid #d8e0ee;border-radius:8px;padding:6px;background:#fff;" src="' . esc_attr($drawn) . '" /></p>';
    }

    $html .= '</div>';
    return $html;
}

function dcb_render_finalization_section(array $normalized, int $submission_id): string {
    $finalization = isset($normalized['finalization']) && is_array($normalized['finalization']) ? $normalized['finalization'] : array();
    $workflow = isset($normalized['workflow']) && is_array($normalized['workflow']) ? $normalized['workflow'] : array();

    $finalized_by = (int) ($finalization['finalized_by'] ?? 0);
    $finalized_by_name = sanitize_text_field((string) ($finalization['finalized_by_name'] ?? ''));
    if ($finalized_by_name === '' && $finalized_by > 0) {
        $user = get_user_by('id', $finalized_by);
        if ($user instanceof WP_User) {
            $finalized_by_name = (string) $user->display_name;
        }
    }

    $html = '<div style="margin-top:14px;border:1px solid #dbe5ee;border-radius:8px;background:#f8fbff;padding:12px;">';
    $html .= '<h3 style="margin-top:0;">Finalization Evidence</h3>';
    $html .= '<ul style="margin:0;padding-left:18px;">';
    $html .= '<li><strong>Finalized At:</strong> ' . esc_html((string) ($finalization['finalized_at'] ?? '—')) . '</li>';
    $html .= '<li><strong>Finalized By:</strong> ' . esc_html($finalized_by_name !== '' ? $finalized_by_name : ($finalized_by > 0 ? 'User #' . $finalized_by : '—')) . '</li>';
    $html .= '<li><strong>Output Version:</strong> ' . esc_html((string) ($finalization['output_version'] ?? '—')) . '</li>';
    $html .= '<li><strong>Output Template:</strong> ' . esc_html((string) ($finalization['output_template_key'] ?? 'dcb-digital-form-v1')) . '</li>';
    $html .= '<li><strong>Workflow Status:</strong> ' . esc_html((string) ($workflow['status'] ?? 'submitted')) . '</li>';
    $approved_at = (string) ($finalization['approval_context']['approved_at'] ?? '');
    $approved_by = (string) ($finalization['approval_context']['approved_by'] ?? '');
    if ($approved_at !== '' || $approved_by !== '') {
        $html .= '<li><strong>Approved Context:</strong> ' . esc_html(trim($approved_at . ($approved_by !== '' ? ' by ' . $approved_by : ''))) . '</li>';
    }
    $html .= '</ul>';
    $html .= '</div>';

    return $html;
}

function dcb_render_document_body(array $form_definition, array $field_values, array $signature, int $submission_id, string $view = 'admin'): string {
    $template_blocks = isset($form_definition['template_blocks']) && is_array($form_definition['template_blocks']) ? array_values($form_definition['template_blocks']) : array();
    $document_nodes = isset($form_definition['document_nodes']) && is_array($form_definition['document_nodes']) ? array_values($form_definition['document_nodes']) : array();
    $fields_for_resolver = array();
    foreach ($field_values as $field_key => $field_meta) {
        $fields_for_resolver[] = array('key' => (string) $field_key);
    }
    $resolved_nodes = dcb_resolve_document_nodes($document_nodes, $template_blocks, $fields_for_resolver);

    $html = '<div class="dcb-rendered-document" style="border:1px solid #dde5f0;border-radius:10px;padding:14px;background:#fff;">';

    $rendered_field_keys = array();
    $has_rendered_any_nodes = false;
    $has_document_nodes = !empty($document_nodes);
    if ($has_document_nodes) {
        foreach ((array) ($resolved_nodes['nodes'] ?? array()) as $node) {
            if (!is_array($node) || empty($node['resolved'])) {
                continue;
            }
            $node_type = sanitize_key((string) ($node['type'] ?? ''));
            if ($node_type === 'block') {
                $index = isset($node['block_index']) && is_numeric($node['block_index']) ? (int) $node['block_index'] : -1;
                if ($index >= 0 && isset($template_blocks[$index]) && is_array($template_blocks[$index])) {
                    $html .= dcb_render_template_block_html($template_blocks[$index]);
                    $has_rendered_any_nodes = true;
                }
            } elseif ($node_type === 'field') {
                $field_key = sanitize_key((string) ($node['field_key'] ?? ''));
                if ($field_key !== '' && isset($field_values[$field_key]) && is_array($field_values[$field_key])) {
                    $html .= dcb_render_field_value_chip($field_values[$field_key]);
                    $rendered_field_keys[$field_key] = true;
                    $has_rendered_any_nodes = true;
                }
            }
        }
    }

    if (!$has_document_nodes || !$has_rendered_any_nodes) {
        foreach ($template_blocks as $block) {
            if (is_array($block)) {
                $html .= dcb_render_template_block_html($block);
            }
        }
    }

    $remaining_fields = array();
    foreach ($field_values as $key => $field) {
        if (!isset($rendered_field_keys[$key])) {
            $remaining_fields[] = $field;
        }
    }

    if (!empty($remaining_fields)) {
        $html .= '<h3 style="margin-top:14px;">Form Values</h3>';
        foreach ($remaining_fields as $field) {
            if (is_array($field)) {
                $html .= dcb_render_field_value_chip($field);
            }
        }
    }

    $html .= dcb_render_signature_section($signature, $submission_id, $view);
    $html .= '</div>';

    return $html;
}

function dcb_render_submission_html(int $submission_id, string $view = 'admin'): string {
    $payload = dcb_normalize_submission_payload($submission_id);
    if (empty($payload)) {
        return '<p>No submission payload available.</p>';
    }

    $normalized = (array) ($payload['normalized_submission_data'] ?? array());
    $fields = isset($normalized['fields']) && is_array($normalized['fields']) ? $normalized['fields'] : array();
    $sig = isset($normalized['signature']) && is_array($normalized['signature']) ? $normalized['signature'] : array();
    $consent = isset($normalized['consent']) && is_array($normalized['consent']) ? $normalized['consent'] : array();

    $form_definition = dcb_get_submission_form_definition($submission_id);

    $field_values = array();
    foreach ($fields as $field) {
        if (!is_array($field)) {
            continue;
        }
        $key = sanitize_key((string) ($field['key'] ?? ''));
        if ($key === '') {
            continue;
        }
        $field_values[$key] = array(
            'label' => (string) ($field['label'] ?? $key),
            'value' => (string) ($field['value'] ?? ''),
            'display_value' => (string) ($field['display_value'] ?? ''),
            'type' => (string) ($field['type'] ?? 'text'),
        );
    }

    $is_final_view = in_array($view, array('final', 'print', 'final_print'), true);
    $wrapper_class = $is_final_view ? 'dcb-submission-final-wrap' : 'dcb-submission-admin-wrap';
    $html = '<div class="' . esc_attr($wrapper_class) . '">';
    $html .= '<h2>' . esc_html((string) ($payload['final_document_title'] ?? 'Digital Form Submission')) . '</h2>';
    $workflow_status = sanitize_key((string) ($normalized['workflow']['status'] ?? 'submitted'));
    $status_badge = dcb_render_status_badge_html($workflow_status, $is_final_view ? 'print' : 'admin');
    $html .= '<p>' . $status_badge . ($is_final_view ? ' <span style="margin-left:8px;font-size:12px;color:#2e4a6f;">LOCKED FINAL RECORD</span>' : '') . '</p>';
    $html .= '<p><strong>Form:</strong> ' . esc_html((string) ($normalized['form_name'] ?? '')) . ' <strong style="margin-left:8px;">Version:</strong> ' . esc_html((string) ($normalized['form_version'] ?? 1)) . '</p>';
    $html .= '<p><strong>Submitter:</strong> ' . esc_html((string) (($normalized['submitter']['name'] ?? '') ?: ($normalized['submitter']['email'] ?? 'Unknown'))) . '</p>';
    $html .= '<p><strong>Submitted:</strong> ' . esc_html((string) ($normalized['submitted_timestamp'] ?? '')) . '</p>';
    $html .= '<p><strong>Payload Hash:</strong> <code>' . esc_html((string) ($normalized['payload_hash'] ?? '')) . '</code></p>';

    $html .= dcb_render_document_body($form_definition, $field_values, $sig, $submission_id, $view);
    $html .= dcb_render_finalization_section($normalized, $submission_id);
    $html .= '<h3>Consent / Attestation</h3>';
    $html .= '<ul>';
    $html .= '<li><strong>Consent Value:</strong> ' . esc_html((string) ($consent['consent_value'] ?? '')) . '</li>';
    $html .= '<li><strong>Attestation Value:</strong> ' . esc_html((string) ($consent['attestation_value'] ?? '')) . '</li>';
    $html .= '<li><strong>Consent Text Version:</strong> ' . esc_html((string) ($consent['consent_text_version'] ?? '')) . '</li>';
    $html .= '<li><strong>Attestation Text Version:</strong> ' . esc_html((string) ($consent['attestation_text_version'] ?? '')) . '</li>';
    $html .= '</ul>';
    $html .= '</div>';

    return function_exists('apply_filters') ? (string) apply_filters('dcb_output_render_html', $html, $submission_id, $view, $payload) : $html;
}

function dcb_finalize_submission_output(int $submission_id, int $finalized_by = 0): void {
    $payload = dcb_normalize_submission_payload($submission_id);
    if (empty($payload)) {
        return;
    }

    $template_key = dcb_output_template_key_for_submission($submission_id, (array) ($payload['normalized_submission_data'] ?? array()), 'final');
    $rendered_final_html = dcb_render_submission_html($submission_id, 'final');
    $rendered_admin_html = dcb_render_submission_html($submission_id, 'admin');

    $finalized_by_name = '';
    if ($finalized_by > 0) {
        $user = get_user_by('id', $finalized_by);
        if ($user instanceof WP_User) {
            $finalized_by_name = (string) $user->display_name;
        }
    }

    update_post_meta($submission_id, '_dcb_form_rendered_html', $rendered_final_html);
    update_post_meta($submission_id, '_dcb_form_rendered_html_final', $rendered_final_html);
    update_post_meta($submission_id, '_dcb_form_rendered_html_admin', $rendered_admin_html);
    update_post_meta($submission_id, '_dcb_form_output_version', defined('DCB_VERSION') ? DCB_VERSION : '0');
    update_post_meta($submission_id, '_dcb_form_finalized_at', current_time('mysql'));
    update_post_meta($submission_id, '_dcb_form_finalized_by', max(0, $finalized_by));
    update_post_meta($submission_id, '_dcb_form_finalized_by_name', $finalized_by_name);
    update_post_meta($submission_id, '_dcb_form_final_document_title', (string) ($payload['final_document_title'] ?? 'Digital Form Submission'));
    update_post_meta($submission_id, '_dcb_form_normalized_export', wp_json_encode((array) ($payload['normalized_submission_data'] ?? array())));
    update_post_meta($submission_id, '_dcb_form_render_template_mapping', wp_json_encode((array) ($payload['render_template_mapping'] ?? array())));
    update_post_meta($submission_id, '_dcb_output_template_key', $template_key);
    update_post_meta($submission_id, '_dcb_output_contract_version', '2.0');

    if (function_exists('do_action')) {
        do_action('dcb_output_finalized', $submission_id, $template_key, $payload);
    }
}

function dcb_pdf_export_adapter_default_contract(int $submission_id, array $payload): array {
    return array(
        'ok' => false,
        'mime' => 'application/pdf',
        'filename' => 'dcb-submission-' . $submission_id . '.pdf',
        'binary' => '',
        'message' => 'No PDF adapter installed.',
        'contract_version' => '2.0',
        'submission_id' => $submission_id,
        'payload' => $payload,
    );
}

function dcb_pdf_export_adapter_validate_result($adapter_result): array {
    if (!is_array($adapter_result)) {
        return array('ok' => false, 'message' => 'PDF adapter returned non-array response.');
    }

    $ok = !empty($adapter_result['ok']);
    $binary = isset($adapter_result['binary']) && is_string($adapter_result['binary']) ? $adapter_result['binary'] : '';
    $mime = isset($adapter_result['mime']) && is_string($adapter_result['mime']) ? $adapter_result['mime'] : 'application/pdf';
    $filename = isset($adapter_result['filename']) && is_string($adapter_result['filename']) ? $adapter_result['filename'] : 'document.pdf';
    $message = isset($adapter_result['message']) && is_string($adapter_result['message']) ? $adapter_result['message'] : '';

    if (!$ok) {
        return array('ok' => false, 'message' => $message !== '' ? $message : 'PDF adapter reported failure.');
    }
    if ($binary === '') {
        return array('ok' => false, 'message' => 'PDF adapter returned empty binary payload.');
    }

    return array(
        'ok' => true,
        'mime' => $mime,
        'filename' => $filename,
        'binary' => $binary,
        'message' => $message,
    );
}

function dcb_send_submission_notification(int $submission_id): array {
    $payload = dcb_normalize_submission_payload($submission_id);
    if (empty($payload)) {
        return array('sent' => false, 'recipients' => array());
    }

    $normalized = (array) ($payload['normalized_submission_data'] ?? array());
    $form = dcb_get_submission_form_definition($submission_id);
    $submitted_by_name = (string) (($normalized['submitter']['name'] ?? '') ?: 'User');
    $submitted_by_email = (string) ($normalized['submitter']['email'] ?? '');
    $form_label = (string) ($normalized['form_name'] ?? 'Digital Form');
    $payload_hash = (string) ($normalized['payload_hash'] ?? '');

    $recipients = dcb_parse_emails((string) ($form['recipients'] ?? ''));
    $routing_rules = isset($form['routing_rules']) && is_array($form['routing_rules']) ? $form['routing_rules'] : array();
    $field_values = array();
    foreach ((array) ($normalized['fields'] ?? array()) as $row) {
        if (!is_array($row)) {
            continue;
        }
        $f_key = sanitize_key((string) ($row['key'] ?? ''));
        if ($f_key === '') {
            continue;
        }
        $field_values[$f_key] = (string) ($row['value'] ?? '');
    }

    $queue = 'default';
    $assignee_role = '';
    foreach ($routing_rules as $rule) {
        if (!is_array($rule)) {
            continue;
        }
        $conditions = isset($rule['when']) && is_array($rule['when']) ? $rule['when'] : array();
        $matched = true;
        foreach ($conditions as $condition) {
            if (is_array($condition) && !dcb_condition_matches($condition, $field_values)) {
                $matched = false;
                break;
            }
        }
        if (!$matched) {
            continue;
        }

        $notify = isset($rule['notify']) && is_array($rule['notify']) ? $rule['notify'] : array();
        if (!empty($notify)) {
            $recipients = array_values(array_unique(array_merge($recipients, array_map('sanitize_email', $notify))));
        }
        $queue = sanitize_key((string) ($rule['queue'] ?? 'default'));
        $assignee_role = sanitize_key((string) ($rule['assignee_role'] ?? ''));
        break;
    }

    if (empty($recipients)) {
        $recipients = dcb_parse_emails((string) get_option('dcb_upload_default_recipients', ''));
    }
    if (empty($recipients)) {
        $recipients = array(get_option('admin_email'));
    }

    $subject = '[Document Center] ' . $form_label . ' - ' . $submitted_by_name;
    $body = "A digital form has been submitted.\n\n"
        . "Submission ID: {$submission_id}\n"
        . "Form: {$form_label}\n"
        . "Submitted By: {$submitted_by_name}\n"
        . "Email: {$submitted_by_email}\n"
        . 'Timestamp: ' . (string) ($normalized['submitted_timestamp'] ?? current_time('mysql')) . "\n"
        . "Payload Hash: {$payload_hash}\n";

    $sent = wp_mail($recipients, $subject, $body);
    update_post_meta($submission_id, '_dcb_form_emailed_to', implode(', ', $recipients));
    update_post_meta($submission_id, '_dcb_form_email_status', $sent ? 'sent' : 'failed');
    update_post_meta($submission_id, '_dcb_workflow_queue', $queue);
    if ($assignee_role !== '') {
        update_post_meta($submission_id, '_dcb_workflow_assignee_role', $assignee_role);
    }

    if (class_exists('DCB_Workflow')) {
        DCB_Workflow::add_timeline($submission_id, 'notification_sent', array(
            'queue' => $queue,
            'assignee_role' => $assignee_role,
            'recipient_count' => count($recipients),
            'email_status' => $sent ? 'sent' : 'failed',
        ));
    }

    return array('sent' => $sent, 'recipients' => $recipients, 'subject' => $subject);
}
