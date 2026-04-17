<?php

if (!defined('ABSPATH')) {
    exit;
}

function dcb_allowed_field_types(): array {
    return array('text', 'email', 'date', 'time', 'number', 'select', 'checkbox', 'radio', 'yes_no');
}

function dcb_allowed_condition_operators(): array {
    return array('eq', 'neq', 'filled', 'not_filled', 'in', 'not_in', 'gt', 'gte', 'lt', 'lte');
}

function dcb_allowed_template_block_types(): array {
    return array('heading', 'paragraph', 'divider', 'section_header', 'spacer', 'image_placeholder', 'info_row', 'labeled_value_row', 'two_column_row');
}

function dcb_parse_emails(string $emails): array {
    $parts = array_map('trim', explode(',', strtolower($emails)));
    $parts = array_values(array_filter(array_map('sanitize_email', $parts), static function ($email) {
        return $email !== '';
    }));
    return array_values(array_unique($parts));
}

function dcb_normalize_options($options): array {
    $out = array();
    if (!is_array($options)) {
        return $out;
    }

    foreach ($options as $key => $value) {
        if (is_array($value)) {
            continue;
        }

        if (is_int($key)) {
            $label = sanitize_text_field((string) $value);
            $opt_key = sanitize_key($label);
            if ($opt_key === '') {
                continue;
            }
            $out[$opt_key] = $label;
            continue;
        }

        $opt_key = sanitize_key((string) $key);
        $label = sanitize_text_field((string) $value);
        if ($opt_key === '' || $label === '') {
            continue;
        }
        $out[$opt_key] = $label;
    }

    return $out;
}

function dcb_normalize_condition(array $condition): ?array {
    $field = sanitize_key((string) ($condition['field'] ?? ''));
    $operator = sanitize_key((string) ($condition['operator'] ?? 'eq'));
    $allowed = dcb_allowed_condition_operators();
    if ($field === '' || !in_array($operator, $allowed, true)) {
        return null;
    }

    $out = array(
        'field' => $field,
        'operator' => $operator,
    );

    if (isset($condition['values']) && is_array($condition['values'])) {
        $out['values'] = array_values(array_filter(array_map(static function ($v) {
            return sanitize_text_field((string) $v);
        }, $condition['values']), static function ($v) {
            return $v !== '';
        }));
    } elseif (array_key_exists('value', $condition)) {
        $out['value'] = sanitize_text_field((string) $condition['value']);
    }

    return $out;
}

function dcb_normalize_hard_stop_rule(array $stop): ?array {
    $message = sanitize_text_field((string) ($stop['message'] ?? ''));
    $when = isset($stop['when']) && is_array($stop['when']) ? $stop['when'] : array();
    if ($message === '' || empty($when)) {
        return null;
    }

    $clean_when = array();
    foreach ($when as $condition) {
        if (!is_array($condition)) {
            continue;
        }
        $clean = dcb_normalize_condition($condition);
        if ($clean !== null) {
            $clean_when[] = $clean;
        }
    }
    if (empty($clean_when)) {
        return null;
    }

    $normalized = array(
        'message' => $message,
        'when' => $clean_when,
    );

    $label = sanitize_text_field((string) ($stop['label'] ?? $stop['name'] ?? ''));
    if ($label !== '') {
        $normalized['label'] = $label;
    }

    $severity = sanitize_key((string) ($stop['severity'] ?? ''));
    if (in_array($severity, array('error', 'warning', 'info'), true)) {
        $normalized['severity'] = $severity;
    }

    $type = sanitize_key((string) ($stop['type'] ?? $stop['rule_type'] ?? ''));
    if ($type !== '') {
        $normalized['type'] = $type;
    }

    return $normalized;
}

function dcb_generate_block_id(): string {
    try {
        return 'blk_' . bin2hex(random_bytes(8));
    } catch (Throwable $e) {
        return 'blk_' . wp_generate_password(12, false, false);
    }
}

function dcb_normalize_template_block(array $block): ?array {
    $type = sanitize_key((string) ($block['type'] ?? 'paragraph'));
    if (!in_array($type, dcb_allowed_template_block_types(), true)) {
        return null;
    }

    $out = array('type' => $type);
    $raw_block_id = sanitize_key((string) ($block['block_id'] ?? $block['id'] ?? ''));
    if ($raw_block_id !== '') {
        $out['block_id'] = $raw_block_id;
    }

    if (isset($block['text'])) {
        $text = sanitize_textarea_field((string) $block['text']);
        if ($text !== '') {
            $out['text'] = $text;
        }
    }

    if ($type === 'heading' || $type === 'section_header') {
        $level = (int) ($block['level'] ?? ($type === 'heading' ? 2 : 3));
        $out['level'] = min(6, max(1, $level));
    }

    if ($type === 'spacer') {
        $height = (int) ($block['height'] ?? 20);
        $out['height'] = min(120, max(8, $height));
    }

    if ($type === 'image_placeholder') {
        $alt = sanitize_text_field((string) ($block['alt'] ?? 'Logo / Image'));
        $out['alt'] = $alt !== '' ? $alt : 'Logo / Image';
        $width = (int) ($block['width'] ?? 180);
        $out['width'] = min(640, max(60, $width));
        if (isset($block['image_url'])) {
            $url = esc_url_raw((string) $block['image_url']);
            if ($url !== '') {
                $out['image_url'] = $url;
            }
        }
    }

    if ($type === 'info_row') {
        $columns = isset($block['columns']) && is_array($block['columns']) ? $block['columns'] : array();
        $clean_columns = array();
        foreach ($columns as $column) {
            if (!is_array($column)) {
                continue;
            }
            $label = sanitize_text_field((string) ($column['label'] ?? ''));
            $value = sanitize_text_field((string) ($column['value'] ?? ''));
            if ($label === '' && $value === '') {
                continue;
            }
            $clean_columns[] = array('label' => $label, 'value' => $value);
        }
        if (empty($clean_columns)) {
            $clean_columns[] = array('label' => 'Label', 'value' => 'Value');
        }
        $out['columns'] = array_slice($clean_columns, 0, 4);
    }

    if ($type === 'labeled_value_row') {
        $out['label'] = sanitize_text_field((string) ($block['label'] ?? 'Label')) ?: 'Label';
        $out['value_text'] = sanitize_text_field((string) ($block['value_text'] ?? 'Value')) ?: 'Value';
    }

    if ($type === 'two_column_row') {
        $out['left_text'] = sanitize_text_field((string) ($block['left_text'] ?? 'Left Column')) ?: 'Left Column';
        $out['right_text'] = sanitize_text_field((string) ($block['right_text'] ?? 'Right Column')) ?: 'Right Column';
    }

    if (isset($block['source_page']) && is_numeric($block['source_page'])) {
        $out['source_page'] = max(1, (int) $block['source_page']);
    }
    if (isset($block['source_text_snippet'])) {
        $snippet = sanitize_text_field((string) $block['source_text_snippet']);
        if ($snippet !== '') {
            $out['source_text_snippet'] = $snippet;
        }
    }

    return $out;
}

function dcb_ensure_template_blocks_have_ids(array $template_blocks): array {
    $used = array();
    $out = array();

    foreach ($template_blocks as $idx => $block) {
        if (!is_array($block)) {
            continue;
        }
        $clean = $block;
        $candidate = sanitize_key((string) ($clean['block_id'] ?? ''));
        if ($candidate === '' || isset($used[$candidate])) {
            $candidate = 'blk_' . ($idx + 1);
            if (isset($used[$candidate])) {
                $candidate = dcb_generate_block_id();
                while (isset($used[$candidate])) {
                    $candidate = dcb_generate_block_id();
                }
            }
        }

        $clean['block_id'] = $candidate;
        $used[$candidate] = true;
        $out[] = $clean;
    }

    return $out;
}

function dcb_default_document_nodes(array $template_blocks, array $fields): array {
    $out = array();
    foreach ($template_blocks as $block) {
        if (!is_array($block)) {
            continue;
        }
        $block_id = sanitize_key((string) ($block['block_id'] ?? ''));
        if ($block_id === '') {
            continue;
        }
        $out[] = array('type' => 'block', 'block_id' => $block_id);
    }
    foreach ($fields as $field) {
        if (!is_array($field)) {
            continue;
        }
        $field_key = sanitize_key((string) ($field['key'] ?? ''));
        if ($field_key === '') {
            continue;
        }
        $out[] = array('type' => 'field', 'field_key' => $field_key);
    }
    return $out;
}

function dcb_resolve_document_nodes(array $document_nodes, array $template_blocks, array $fields): array {
    $block_by_id = array();
    $block_by_index = array_values($template_blocks);
    foreach ($template_blocks as $i => $block) {
        if (!is_array($block)) {
            continue;
        }
        $block_id = sanitize_key((string) ($block['block_id'] ?? ''));
        if ($block_id !== '') {
            $block_by_id[$block_id] = $i;
        }
    }

    $field_by_key = array();
    foreach ($fields as $field) {
        if (!is_array($field)) {
            continue;
        }
        $field_key = sanitize_key((string) ($field['key'] ?? ''));
        if ($field_key !== '') {
            $field_by_key[$field_key] = true;
        }
    }

    $resolved = array();
    $warnings = array();
    $migrated = 0;

    foreach ($document_nodes as $node_index => $node) {
        if (!is_array($node)) {
            $warnings[] = sprintf('Node #%d is not an object.', $node_index + 1);
            continue;
        }

        $type = sanitize_key((string) ($node['type'] ?? $node['node_type'] ?? ''));
        if (!in_array($type, array('block', 'field'), true)) {
            $warnings[] = sprintf('Node #%d has invalid type "%s".', $node_index + 1, $type !== '' ? $type : 'empty');
            continue;
        }

        if ($type === 'block') {
            $block_id = sanitize_key((string) ($node['block_id'] ?? $node['block_ref'] ?? ''));
            $legacy_index = isset($node['block_index']) && is_numeric($node['block_index']) ? (int) $node['block_index'] : null;

            if ($block_id === '' && $legacy_index !== null && $legacy_index >= 0 && isset($block_by_index[$legacy_index]) && is_array($block_by_index[$legacy_index])) {
                $block_id = sanitize_key((string) ($block_by_index[$legacy_index]['block_id'] ?? ''));
                if ($block_id !== '') {
                    $migrated++;
                }
            }

            if ($block_id === '' || !isset($block_by_id[$block_id])) {
                $warnings[] = sprintf('Node #%d references missing block "%s".', $node_index + 1, $block_id !== '' ? $block_id : '');
                $resolved[] = array('type' => 'block', 'block_id' => $block_id, 'block_index' => $legacy_index, 'resolved' => false);
                continue;
            }

            $resolved[] = array('type' => 'block', 'block_id' => $block_id, 'block_index' => (int) $block_by_id[$block_id], 'resolved' => true);
            continue;
        }

        $field_key = sanitize_key((string) ($node['field_key'] ?? $node['field'] ?? ''));
        if ($field_key === '' || !isset($field_by_key[$field_key])) {
            $warnings[] = sprintf('Node #%d references missing field "%s".', $node_index + 1, $field_key !== '' ? $field_key : '');
            $resolved[] = array('type' => 'field', 'field_key' => $field_key, 'resolved' => false);
            continue;
        }

        $resolved[] = array('type' => 'field', 'field_key' => $field_key, 'resolved' => true);
    }

    return array(
        'nodes' => $resolved,
        'warnings' => $warnings,
        'migrated_legacy_block_indexes' => $migrated,
    );
}

function dcb_normalize_document_nodes($nodes, array $template_blocks, array $fields): array {
    if (!is_array($nodes)) {
        return array();
    }

    $resolved = dcb_resolve_document_nodes($nodes, $template_blocks, $fields);
    $out = array();
    foreach ((array) ($resolved['nodes'] ?? array()) as $node) {
        if (!is_array($node)) {
            continue;
        }
        $type = sanitize_key((string) ($node['type'] ?? ''));
        if ($type === 'block') {
            $entry = array('type' => 'block', 'block_id' => sanitize_key((string) ($node['block_id'] ?? '')));
            if (isset($node['block_index']) && is_numeric($node['block_index'])) {
                $entry['block_index'] = max(0, (int) $node['block_index']);
            }
            $out[] = $entry;
            continue;
        }
        if ($type === 'field') {
            $out[] = array('type' => 'field', 'field_key' => sanitize_key((string) ($node['field_key'] ?? '')));
        }
    }

    return $out;
}

function dcb_normalize_ocr_meta($meta): array {
    if (!is_array($meta)) {
        return array();
    }
    $out = array();
    if (isset($meta['page_number']) && is_numeric($meta['page_number'])) {
        $out['page_number'] = max(1, (int) $meta['page_number']);
    }
    if (isset($meta['source_text_snippet'])) {
        $out['source_text_snippet'] = sanitize_text_field((string) $meta['source_text_snippet']);
    }
    if (isset($meta['confidence_bucket'])) {
        $bucket = sanitize_key((string) $meta['confidence_bucket']);
        if (in_array($bucket, array('low', 'medium', 'high'), true)) {
            $out['confidence_bucket'] = $bucket;
        }
    }
    if (isset($meta['confidence_score']) && is_numeric($meta['confidence_score'])) {
        $score = (float) $meta['confidence_score'];
        $out['confidence_score'] = round(max(0, min(1, $score)), 4);
    }
    foreach (array('suggested_type', 'signal', 'source_engine', 'source_text') as $key) {
        if (isset($meta[$key])) {
            $value = $key === 'source_text' ? sanitize_text_field((string) $meta[$key]) : sanitize_key((string) $meta[$key]);
            if ($value !== '') {
                $out[$key] = $value;
            }
        }
    }
    if (isset($meta['warning_state'])) {
        $warning = sanitize_key((string) $meta['warning_state']);
        if (in_array($warning, array('none', 'review_needed', 'ambiguous', 'duplicate_guess'), true)) {
            $out['warning_state'] = $warning;
        }
    }
    if (isset($meta['review_state'])) {
        $review = sanitize_key((string) $meta['review_state']);
        if (in_array($review, array('pending', 'confirmed', 'ignored', 'merged'), true)) {
            $out['review_state'] = $review;
        }
    }
    return $out;
}

function dcb_normalize_ocr_candidates($candidates): array {
    if (!is_array($candidates)) {
        return array();
    }

    $out = array();
    foreach ($candidates as $candidate) {
        if (!is_array($candidate)) {
            continue;
        }
        $row = array(
            'field_label' => sanitize_text_field((string) ($candidate['field_label'] ?? '')),
            'suggested_key' => sanitize_key((string) ($candidate['suggested_key'] ?? '')),
            'suggested_type' => sanitize_key((string) ($candidate['suggested_type'] ?? 'text')),
            'detected_type' => sanitize_key((string) ($candidate['detected_type'] ?? '')),
            'required_guess' => !empty($candidate['required_guess']),
            'page_number' => max(1, (int) ($candidate['page_number'] ?? 1)),
            'line_index' => max(0, (int) ($candidate['line_index'] ?? 0)),
            'section_hint' => sanitize_key((string) ($candidate['section_hint'] ?? '')),
            'source_text_snippet' => sanitize_text_field((string) ($candidate['source_text_snippet'] ?? '')),
            'confidence_bucket' => sanitize_key((string) ($candidate['confidence_bucket'] ?? 'low')),
            'confidence_score' => isset($candidate['confidence_score']) && is_numeric($candidate['confidence_score']) ? round(max(0, min(1, (float) $candidate['confidence_score'])), 4) : 0,
            'source_engine' => sanitize_key((string) ($candidate['source_engine'] ?? '')),
            'warning_state' => sanitize_key((string) ($candidate['warning_state'] ?? 'none')),
        );

        if ($row['field_label'] === '' || $row['suggested_key'] === '') {
            continue;
        }
        if (!in_array($row['confidence_bucket'], array('low', 'medium', 'high'), true)) {
            $row['confidence_bucket'] = 'low';
        }
        if ($row['detected_type'] === '') {
            unset($row['detected_type']);
        }
        if ($row['section_hint'] === '') {
            unset($row['section_hint']);
        }

        if (isset($candidate['confidence_reasons']) && is_array($candidate['confidence_reasons'])) {
            $reasons = array_values(array_filter(array_map('sanitize_key', $candidate['confidence_reasons'])));
            if (!empty($reasons)) {
                $row['confidence_reasons'] = $reasons;
            }
        }

        $out[] = $row;
    }

    return $out;
}

function dcb_normalize_ocr_review($review): array {
    if (!is_array($review)) {
        return array();
    }
    $out = array();
    foreach (array('origin', 'created_at') as $key) {
        if (isset($review[$key])) {
            $out[$key] = $key === 'origin' ? sanitize_key((string) $review[$key]) : sanitize_text_field((string) $review[$key]);
        }
    }

    if (isset($review['pipeline_stages']) && is_array($review['pipeline_stages'])) {
        $stages = array_values(array_filter(array_map(static function ($stage) {
            return sanitize_text_field((string) $stage);
        }, $review['pipeline_stages']), static function ($stage) {
            return $stage !== '';
        }));
        if (!empty($stages)) {
            $out['pipeline_stages'] = $stages;
        }
    }

    if (isset($review['field_confidence_counts']) && is_array($review['field_confidence_counts'])) {
        $out['field_confidence_counts'] = array(
            'low' => max(0, (int) ($review['field_confidence_counts']['low'] ?? 0)),
            'medium' => max(0, (int) ($review['field_confidence_counts']['medium'] ?? 0)),
            'high' => max(0, (int) ($review['field_confidence_counts']['high'] ?? 0)),
        );
    }

    if (isset($review['template_block_count']) && is_numeric($review['template_block_count'])) {
        $out['template_block_count'] = max(0, (int) $review['template_block_count']);
    }
    foreach (array('section_count', 'repeater_count', 'field_candidate_count', 'table_candidate_count', 'signature_candidate_count') as $count_key) {
        if (isset($review[$count_key]) && is_numeric($review[$count_key])) {
            $out[$count_key] = max(0, (int) $review[$count_key]);
        }
    }
    if (isset($review['model_version'])) {
        $out['model_version'] = sanitize_text_field((string) $review['model_version']);
    }
    if (isset($review['low_confidence_warning'])) {
        $out['low_confidence_warning'] = !empty($review['low_confidence_warning']);
    }

    if (isset($review['page_extraction']) && is_array($review['page_extraction'])) {
        $page_rows = array();
        foreach ($review['page_extraction'] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $bucket = sanitize_key((string) ($row['confidence_bucket'] ?? 'low'));
            if (!in_array($bucket, array('low', 'medium', 'high'), true)) {
                $bucket = 'low';
            }
            $proxy = round(max(0, min(1, (float) ($row['confidence_proxy'] ?? 0))), 4);
            $page_rows[] = array(
                'page_number' => max(1, (int) ($row['page_number'] ?? 1)),
                'engine' => sanitize_text_field((string) ($row['engine'] ?? 'none')),
                'text_length' => max(0, (int) ($row['text_length'] ?? 0)),
                'confidence_proxy' => $proxy,
                'confidence_bucket' => $bucket,
            );
        }
        if (!empty($page_rows)) {
            $out['page_extraction'] = $page_rows;
        }
    }

    return $out;
}

function dcb_normalize_sections($sections): array {
    if (!is_array($sections)) {
        return array();
    }
    $out = array();
    foreach ($sections as $section) {
        if (!is_array($section)) {
            continue;
        }
        $key = sanitize_key((string) ($section['key'] ?? ''));
        $label = sanitize_text_field((string) ($section['label'] ?? ''));
        if ($key === '' || $label === '') {
            continue;
        }
        $field_keys = array_values(array_filter(array_map('sanitize_key', (array) ($section['field_keys'] ?? array()))));
        $out[] = array('key' => $key, 'label' => $label, 'field_keys' => $field_keys);
    }
    return $out;
}

function dcb_normalize_steps($steps): array {
    if (!is_array($steps)) {
        return array();
    }
    $out = array();
    foreach ($steps as $step) {
        if (!is_array($step)) {
            continue;
        }
        $key = sanitize_key((string) ($step['key'] ?? ''));
        $label = sanitize_text_field((string) ($step['label'] ?? ''));
        if ($key === '' || $label === '') {
            continue;
        }
        $section_keys = array_values(array_filter(array_map('sanitize_key', (array) ($step['section_keys'] ?? array()))));
        $out[] = array('key' => $key, 'label' => $label, 'section_keys' => $section_keys);
    }
    return $out;
}

function dcb_normalize_repeaters($repeaters): array {
    if (!is_array($repeaters)) {
        return array();
    }
    $out = array();
    foreach ($repeaters as $repeater) {
        if (!is_array($repeater)) {
            continue;
        }
        $key = sanitize_key((string) ($repeater['key'] ?? ''));
        $label = sanitize_text_field((string) ($repeater['label'] ?? ''));
        if ($key === '' || $label === '') {
            continue;
        }
        $field_keys = array_values(array_filter(array_map('sanitize_key', (array) ($repeater['field_keys'] ?? array()))));
        $min = max(0, (int) ($repeater['min'] ?? 0));
        $max = max($min, (int) ($repeater['max'] ?? max(1, $min + 4)));
        $out[] = array('key' => $key, 'label' => $label, 'field_keys' => $field_keys, 'min' => $min, 'max' => $max);
    }
    return $out;
}

function dcb_normalize_routing_rules($rules): array {
    if (!is_array($rules)) {
        return array();
    }
    $out = array();
    foreach ($rules as $rule) {
        if (!is_array($rule)) {
            continue;
        }
        $name = sanitize_text_field((string) ($rule['name'] ?? 'Rule'));
        $queue = sanitize_key((string) ($rule['queue'] ?? 'default'));
        $when = isset($rule['when']) && is_array($rule['when']) ? $rule['when'] : array();
        $clean_when = array();
        foreach ($when as $condition) {
            if (!is_array($condition)) {
                continue;
            }
            $clean = dcb_normalize_condition($condition);
            if ($clean !== null) {
                $clean_when[] = $clean;
            }
        }
        $assignee_role = sanitize_key((string) ($rule['assignee_role'] ?? ''));
        $notify = dcb_parse_emails((string) ($rule['notify'] ?? ''));
        $out[] = array(
            'name' => $name,
            'queue' => $queue,
            'when' => $clean_when,
            'assignee_role' => $assignee_role,
            'notify' => $notify,
        );
    }
    return $out;
}

function dcb_normalize_single_form(array $form): ?array {
    $label = sanitize_text_field((string) ($form['label'] ?? ''));
    $recipients = sanitize_text_field((string) ($form['recipients'] ?? ''));
    $fields = isset($form['fields']) && is_array($form['fields']) ? $form['fields'] : array();
    $allowed_types = dcb_allowed_field_types();

    $normalized_fields = array();
    foreach ($fields as $field) {
        if (!is_array($field)) {
            continue;
        }

        $key = sanitize_key((string) ($field['key'] ?? ''));
        $field_label = sanitize_text_field((string) ($field['label'] ?? ''));
        $type = sanitize_key((string) ($field['type'] ?? 'text'));
        if ($key === '' || $field_label === '') {
            continue;
        }
        if (!in_array($type, $allowed_types, true)) {
            $type = 'text';
        }

        $normalized = array(
            'key' => $key,
            'label' => $field_label,
            'type' => $type,
            'required' => !empty($field['required']),
        );

        if (array_key_exists('min', $field) && is_numeric($field['min'])) {
            $normalized['min'] = (float) $field['min'];
        }
        if (array_key_exists('max', $field) && is_numeric($field['max'])) {
            $normalized['max'] = (float) $field['max'];
        }
        if ($type === 'select' || $type === 'radio' || $type === 'yes_no') {
            $normalized['options'] = dcb_normalize_options($field['options'] ?? array());
        }

        $conditions = isset($field['conditions']) && is_array($field['conditions']) ? $field['conditions'] : array();
        $clean_conditions = array();
        foreach ($conditions as $condition) {
            if (!is_array($condition)) {
                continue;
            }
            $clean = dcb_normalize_condition($condition);
            if ($clean !== null) {
                $clean_conditions[] = $clean;
            }
        }
        if (!empty($clean_conditions)) {
            $normalized['conditions'] = $clean_conditions;
        }

        $ocr_meta = dcb_normalize_ocr_meta($field['ocr_meta'] ?? array());
        if (!empty($ocr_meta)) {
            $normalized['ocr_meta'] = $ocr_meta;
        }

        $normalized_fields[] = $normalized;
    }

    $hard_stops = isset($form['hard_stops']) && is_array($form['hard_stops']) ? $form['hard_stops'] : array();
    $normalized_hard_stops = array();
    foreach ($hard_stops as $stop) {
        if (!is_array($stop)) {
            continue;
        }
        $clean_stop = dcb_normalize_hard_stop_rule($stop);
        if ($clean_stop !== null) {
            $normalized_hard_stops[] = $clean_stop;
        }
    }

    if ($label === '' && empty($normalized_fields) && $recipients === '' && empty($normalized_hard_stops)) {
        return null;
    }

    $raw_blocks = isset($form['template_blocks']) && is_array($form['template_blocks']) ? $form['template_blocks'] : array();
    $template_blocks = array();
    foreach ($raw_blocks as $block) {
        if (!is_array($block)) {
            continue;
        }
        $clean_block = dcb_normalize_template_block($block);
        if ($clean_block !== null) {
            $template_blocks[] = $clean_block;
        }
    }
    $template_blocks = dcb_ensure_template_blocks_have_ids($template_blocks);

    $version = isset($form['version']) && is_numeric($form['version']) ? (int) $form['version'] : 1;
    if ($version < 1) {
        $version = 1;
    }

    $normalized_form = array(
        'label' => $label,
        'recipients' => $recipients,
        'version' => $version,
        'template_blocks' => $template_blocks,
        'fields' => $normalized_fields,
        'hard_stops' => $normalized_hard_stops,
        'sections' => dcb_normalize_sections($form['sections'] ?? array()),
        'steps' => dcb_normalize_steps($form['steps'] ?? array()),
        'repeaters' => dcb_normalize_repeaters($form['repeaters'] ?? array()),
        'routing_rules' => dcb_normalize_routing_rules($form['routing_rules'] ?? array()),
        'required_bundles' => array_values(array_filter(array_map('sanitize_text_field', (array) ($form['required_bundles'] ?? array())))),
        'notification_triggers' => array_values(array_filter(array_map('sanitize_key', (array) ($form['notification_triggers'] ?? array())))),
    );

    $raw_document_nodes = isset($form['document_nodes']) && is_array($form['document_nodes']) ? $form['document_nodes'] : array();
    $resolved_nodes = dcb_resolve_document_nodes($raw_document_nodes, $template_blocks, $normalized_fields);
    $document_nodes = dcb_normalize_document_nodes($raw_document_nodes, $template_blocks, $normalized_fields);
    if (!empty($document_nodes) || array_key_exists('document_nodes', $form)) {
        $normalized_form['document_nodes'] = $document_nodes;
    }
    if (!empty($resolved_nodes['warnings'])) {
        $normalized_form['document_node_warnings'] = array_values(array_map('sanitize_text_field', (array) $resolved_nodes['warnings']));
    }

    $ocr_candidates = dcb_normalize_ocr_candidates($form['ocr_candidates'] ?? array());
    if (!empty($ocr_candidates)) {
        $normalized_form['ocr_candidates'] = $ocr_candidates;
    }

    $ocr_review = dcb_normalize_ocr_review($form['ocr_review'] ?? array());
    if (!empty($ocr_review)) {
        $normalized_form['ocr_review'] = $ocr_review;
    }

    return $normalized_form;
}

function dcb_structural_signature_payload(array $form): array {
    $clean_blocks = array();
    foreach ((array) ($form['template_blocks'] ?? array()) as $block) {
        if (!is_array($block)) {
            continue;
        }
        $entry = array('type' => sanitize_key((string) ($block['type'] ?? 'paragraph')));
        $entry_block_id = sanitize_key((string) ($block['block_id'] ?? ''));
        if ($entry_block_id !== '') {
            $entry['block_id'] = $entry_block_id;
        }
        foreach (array('text', 'alt', 'image_url', 'label', 'value_text', 'left_text', 'right_text') as $text_key) {
            if (isset($block[$text_key])) {
                $entry[$text_key] = sanitize_text_field((string) $block[$text_key]);
            }
        }
        foreach (array('level', 'height', 'width') as $num_key) {
            if (isset($block[$num_key]) && is_numeric($block[$num_key])) {
                $entry[$num_key] = (float) $block[$num_key];
            }
        }
        if (isset($block['columns']) && is_array($block['columns'])) {
            $entry['columns'] = array_values(array_map(static function ($col) {
                return array(
                    'label' => sanitize_text_field((string) ($col['label'] ?? '')),
                    'value' => sanitize_text_field((string) ($col['value'] ?? '')),
                );
            }, $block['columns']));
        }
        $clean_blocks[] = $entry;
    }
    $clean_blocks = dcb_ensure_template_blocks_have_ids($clean_blocks);

    $clean_fields = array();
    foreach ((array) ($form['fields'] ?? array()) as $field) {
        if (!is_array($field)) {
            continue;
        }
        $entry = array(
            'key' => sanitize_key((string) ($field['key'] ?? '')),
            'label' => sanitize_text_field((string) ($field['label'] ?? '')),
            'type' => sanitize_key((string) ($field['type'] ?? 'text')),
            'required' => !empty($field['required']),
        );
        if (isset($field['min']) && is_numeric($field['min'])) {
            $entry['min'] = (float) $field['min'];
        }
        if (isset($field['max']) && is_numeric($field['max'])) {
            $entry['max'] = (float) $field['max'];
        }
        if (isset($field['options']) && is_array($field['options'])) {
            $entry['options'] = dcb_normalize_options($field['options']);
        }
        if (isset($field['conditions']) && is_array($field['conditions'])) {
            $clean_conditions = array();
            foreach ($field['conditions'] as $condition) {
                if (!is_array($condition)) {
                    continue;
                }
                $clean = dcb_normalize_condition($condition);
                if ($clean !== null) {
                    $clean_conditions[] = $clean;
                }
            }
            if (!empty($clean_conditions)) {
                $entry['conditions'] = $clean_conditions;
            }
        }
        $clean_fields[] = $entry;
    }

    $clean_hard_stops = array();
    foreach ((array) ($form['hard_stops'] ?? array()) as $stop) {
        if (!is_array($stop)) {
            continue;
        }
        $clean_stop = dcb_normalize_hard_stop_rule($stop);
        if ($clean_stop !== null) {
            $clean_hard_stops[] = $clean_stop;
        }
    }

    $clean_document_nodes = dcb_normalize_document_nodes($form['document_nodes'] ?? array(), $clean_blocks, $clean_fields);

    return array(
        'label' => sanitize_text_field((string) ($form['label'] ?? '')),
        'recipients' => sanitize_text_field((string) ($form['recipients'] ?? '')),
        'template_blocks' => $clean_blocks,
        'document_nodes' => $clean_document_nodes,
        'fields' => $clean_fields,
        'hard_stops' => $clean_hard_stops,
        'sections' => dcb_normalize_sections($form['sections'] ?? array()),
        'steps' => dcb_normalize_steps($form['steps'] ?? array()),
        'repeaters' => dcb_normalize_repeaters($form['repeaters'] ?? array()),
        'routing_rules' => dcb_normalize_routing_rules($form['routing_rules'] ?? array()),
        'required_bundles' => array_values(array_filter(array_map('sanitize_text_field', (array) ($form['required_bundles'] ?? array())))),
        'notification_triggers' => array_values(array_filter(array_map('sanitize_key', (array) ($form['notification_triggers'] ?? array())))),
    );
}

function dcb_form_structure_signature(array $form): string {
    return hash('sha256', wp_json_encode(dcb_structural_signature_payload($form)));
}

function dcb_apply_versioning(array $incoming_form, ?array $existing_form): array {
    $incoming_version = isset($incoming_form['version']) ? (int) $incoming_form['version'] : 1;
    if ($incoming_version < 1) {
        $incoming_version = 1;
    }

    if ($existing_form === null) {
        $incoming_form['version'] = $incoming_version;
        return $incoming_form;
    }

    $existing_version = isset($existing_form['version']) ? (int) $existing_form['version'] : 1;
    if ($existing_version < 1) {
        $existing_version = 1;
    }

    $incoming_sig = dcb_form_structure_signature($incoming_form);
    $existing_sig = dcb_form_structure_signature($existing_form);

    $incoming_form['version'] = $incoming_sig !== $existing_sig ? ($existing_version + 1) : $existing_version;
    return $incoming_form;
}

function dcb_get_custom_forms(): array {
    $raw = get_option('dcb_forms_custom', array());
    if (!is_array($raw)) {
        return array();
    }

    $out = array();
    foreach ($raw as $form_key => $form) {
        if (!is_array($form)) {
            continue;
        }
        $key = sanitize_key((string) $form_key);
        if ($key === '') {
            continue;
        }
        $normalized = dcb_normalize_single_form($form);
        if ($normalized !== null) {
            $out[$key] = $normalized;
        }
    }

    return $out;
}

function dcb_form_definitions(bool $for_js = false): array {
    $forms = dcb_get_custom_forms();

    if (!$for_js) {
        return $forms;
    }

    $out = array();
    foreach ($forms as $key => $form) {
        $out[$key] = array(
            'label' => (string) ($form['label'] ?? $key),
            'recipients' => (string) ($form['recipients'] ?? ''),
            'version' => max(1, (int) ($form['version'] ?? 1)),
            'templateBlocks' => isset($form['template_blocks']) && is_array($form['template_blocks']) ? array_values($form['template_blocks']) : array(),
            'documentNodes' => isset($form['document_nodes']) && is_array($form['document_nodes']) ? array_values($form['document_nodes']) : array(),
            'documentNodeWarnings' => isset($form['document_node_warnings']) && is_array($form['document_node_warnings']) ? array_values($form['document_node_warnings']) : array(),
            'fields' => array_values(array_map(static function ($field) {
                return array(
                    'key' => (string) ($field['key'] ?? ''),
                    'label' => (string) ($field['label'] ?? ''),
                    'type' => (string) ($field['type'] ?? 'text'),
                    'required' => !empty($field['required']),
                    'options' => isset($field['options']) && is_array($field['options']) ? $field['options'] : array(),
                    'min' => isset($field['min']) ? (float) $field['min'] : null,
                    'max' => isset($field['max']) ? (float) $field['max'] : null,
                    'conditions' => isset($field['conditions']) && is_array($field['conditions']) ? $field['conditions'] : array(),
                    'ocr_meta' => isset($field['ocr_meta']) && is_array($field['ocr_meta']) ? $field['ocr_meta'] : array(),
                );
            }, (array) ($form['fields'] ?? array()))),
            'hardStops' => isset($form['hard_stops']) && is_array($form['hard_stops']) ? $form['hard_stops'] : array(),
            'sections' => isset($form['sections']) && is_array($form['sections']) ? $form['sections'] : array(),
            'steps' => isset($form['steps']) && is_array($form['steps']) ? $form['steps'] : array(),
            'repeaters' => isset($form['repeaters']) && is_array($form['repeaters']) ? $form['repeaters'] : array(),
            'routingRules' => isset($form['routing_rules']) && is_array($form['routing_rules']) ? $form['routing_rules'] : array(),
            'requiredBundles' => isset($form['required_bundles']) && is_array($form['required_bundles']) ? $form['required_bundles'] : array(),
            'notificationTriggers' => isset($form['notification_triggers']) && is_array($form['notification_triggers']) ? $form['notification_triggers'] : array(),
            'ocrReview' => isset($form['ocr_review']) && is_array($form['ocr_review']) ? $form['ocr_review'] : array(),
        );
    }

    return $out;
}

function dcb_condition_matches(array $condition, array $values): bool {
    $field = sanitize_key((string) ($condition['field'] ?? ''));
    $operator = sanitize_key((string) ($condition['operator'] ?? 'eq'));
    $left = isset($values[$field]) ? (string) $values[$field] : '';
    $value = isset($condition['value']) ? (string) $condition['value'] : '';
    $value_list = isset($condition['values']) && is_array($condition['values']) ? array_map('strval', $condition['values']) : array();

    switch ($operator) {
        case 'filled':
            return trim($left) !== '';
        case 'not_filled':
            return trim($left) === '';
        case 'neq':
            return $left !== $value;
        case 'in':
            return in_array($left, $value_list, true);
        case 'not_in':
            return !in_array($left, $value_list, true);
        case 'gt':
            return is_numeric($left) && is_numeric($value) && (float) $left > (float) $value;
        case 'gte':
            return is_numeric($left) && is_numeric($value) && (float) $left >= (float) $value;
        case 'lt':
            return is_numeric($left) && is_numeric($value) && (float) $left < (float) $value;
        case 'lte':
            return is_numeric($left) && is_numeric($value) && (float) $left <= (float) $value;
        case 'eq':
        default:
            return $left === $value;
    }
}

function dcb_field_is_visible(array $field, array $values): bool {
    $conditions = isset($field['conditions']) && is_array($field['conditions']) ? $field['conditions'] : array();
    if (empty($conditions)) {
        return true;
    }
    foreach ($conditions as $condition) {
        if (is_array($condition) && !dcb_condition_matches($condition, $values)) {
            return false;
        }
    }
    return true;
}

function dcb_apply_generic_hard_stops(array $form, array $clean, array $raw): array {
    $errors = array();
    $stops = isset($form['hard_stops']) && is_array($form['hard_stops']) ? $form['hard_stops'] : array();
    if (empty($stops)) {
        return $errors;
    }

    $values = array();
    foreach ($raw as $k => $v) {
        $values[sanitize_key((string) $k)] = is_scalar($v) ? (string) $v : '';
    }
    foreach ($clean as $k => $v) {
        $values[sanitize_key((string) $k)] = is_scalar($v) ? (string) $v : '';
    }

    foreach ($stops as $stop) {
        if (!is_array($stop)) {
            continue;
        }
        $normalized_stop = dcb_normalize_hard_stop_rule($stop);
        if ($normalized_stop === null) {
            continue;
        }
        $message = sanitize_text_field((string) ($normalized_stop['message'] ?? ''));
        $when = isset($normalized_stop['when']) && is_array($normalized_stop['when']) ? $normalized_stop['when'] : array();
        $matched = true;
        foreach ($when as $condition) {
            if (is_array($condition) && !dcb_condition_matches($condition, $values)) {
                $matched = false;
                break;
            }
        }
        if ($matched) {
            $errors[] = $message;
        }
    }

    return $errors;
}

function dcb_builder_validate_form_schema(array $form): array {
    $errors = array();
    $warnings = array();

    $fields = isset($form['fields']) && is_array($form['fields']) ? $form['fields'] : array();
    $sections = isset($form['sections']) && is_array($form['sections']) ? $form['sections'] : array();
    $steps = isset($form['steps']) && is_array($form['steps']) ? $form['steps'] : array();
    $repeaters = isset($form['repeaters']) && is_array($form['repeaters']) ? $form['repeaters'] : array();
    $template_blocks = isset($form['template_blocks']) && is_array($form['template_blocks']) ? $form['template_blocks'] : array();
    $document_nodes = isset($form['document_nodes']) && is_array($form['document_nodes']) ? $form['document_nodes'] : array();
    $hard_stops = isset($form['hard_stops']) && is_array($form['hard_stops']) ? $form['hard_stops'] : array();

    $field_key_counts = array();
    foreach ($fields as $index => $field) {
        if (!is_array($field)) {
            $warnings[] = sprintf('Field row #%d is not an object.', $index + 1);
            continue;
        }
        $key = sanitize_key((string) ($field['key'] ?? ''));
        $label = sanitize_text_field((string) ($field['label'] ?? ''));
        if ($key === '') {
            $errors[] = sprintf('Field row #%d is missing key.', $index + 1);
            continue;
        }
        if ($label === '') {
            $errors[] = sprintf('Field "%s" is missing label.', $key);
        }
        $field_key_counts[$key] = isset($field_key_counts[$key]) ? ((int) $field_key_counts[$key] + 1) : 1;

        $conditions = isset($field['conditions']) && is_array($field['conditions']) ? $field['conditions'] : array();
        foreach ($conditions as $cond_idx => $condition) {
            if (!is_array($condition) || dcb_normalize_condition($condition) === null) {
                $errors[] = sprintf('Field "%s" has invalid condition #%d.', $key, $cond_idx + 1);
            }
        }
    }

    foreach ($field_key_counts as $key => $count) {
        if ((int) $count > 1) {
            $errors[] = sprintf('Duplicate field key "%s".', $key);
        }
    }

    $field_key_set = array_fill_keys(array_keys($field_key_counts), true);

    $section_key_counts = array();
    foreach ($sections as $idx => $section) {
        if (!is_array($section)) {
            $warnings[] = sprintf('Section row #%d is not an object.', $idx + 1);
            continue;
        }
        $key = sanitize_key((string) ($section['key'] ?? ''));
        if ($key === '') {
            $errors[] = sprintf('Section row #%d is missing key.', $idx + 1);
            continue;
        }
        $section_key_counts[$key] = isset($section_key_counts[$key]) ? ((int) $section_key_counts[$key] + 1) : 1;

        foreach ((array) ($section['field_keys'] ?? array()) as $field_key) {
            $field_key = sanitize_key((string) $field_key);
            if ($field_key === '' || !isset($field_key_set[$field_key])) {
                $errors[] = sprintf('Section "%s" references missing field "%s".', $key, $field_key);
            }
        }
    }

    foreach ($section_key_counts as $key => $count) {
        if ((int) $count > 1) {
            $warnings[] = sprintf('Duplicate section key "%s".', $key);
        }
    }

    $section_key_set = array_fill_keys(array_keys($section_key_counts), true);
    foreach ($steps as $idx => $step) {
        if (!is_array($step)) {
            $warnings[] = sprintf('Step row #%d is not an object.', $idx + 1);
            continue;
        }
        $key = sanitize_key((string) ($step['key'] ?? ''));
        if ($key === '') {
            $errors[] = sprintf('Step row #%d is missing key.', $idx + 1);
            continue;
        }
        foreach ((array) ($step['section_keys'] ?? array()) as $section_key) {
            $section_key = sanitize_key((string) $section_key);
            if ($section_key === '' || !isset($section_key_set[$section_key])) {
                $errors[] = sprintf('Step "%s" references missing section "%s".', $key, $section_key);
            }
        }
    }

    foreach ($repeaters as $idx => $repeater) {
        if (!is_array($repeater)) {
            $warnings[] = sprintf('Repeater row #%d is not an object.', $idx + 1);
            continue;
        }
        $key = sanitize_key((string) ($repeater['key'] ?? ''));
        if ($key === '') {
            $errors[] = sprintf('Repeater row #%d is missing key.', $idx + 1);
            continue;
        }
        foreach ((array) ($repeater['field_keys'] ?? array()) as $field_key) {
            $field_key = sanitize_key((string) $field_key);
            if ($field_key === '' || !isset($field_key_set[$field_key])) {
                $errors[] = sprintf('Repeater "%s" references missing field "%s".', $key, $field_key);
            }
        }
    }

    foreach ($hard_stops as $idx => $stop) {
        if (!is_array($stop) || dcb_normalize_hard_stop_rule($stop) === null) {
            $errors[] = sprintf('Hard stop row #%d is invalid.', $idx + 1);
        }
    }

    $block_ids = array();
    foreach ($template_blocks as $block) {
        if (!is_array($block)) {
            continue;
        }
        $block_id = sanitize_key((string) ($block['block_id'] ?? ''));
        if ($block_id !== '') {
            $block_ids[$block_id] = true;
        }
    }

    foreach ($document_nodes as $idx => $node) {
        if (!is_array($node)) {
            $errors[] = sprintf('Document node row #%d is invalid.', $idx + 1);
            continue;
        }
        $type = sanitize_key((string) ($node['type'] ?? ''));
        if ($type === 'field') {
            $field_key = sanitize_key((string) ($node['field_key'] ?? ''));
            if ($field_key === '' || !isset($field_key_set[$field_key])) {
                $errors[] = sprintf('Document node #%d references missing field "%s".', $idx + 1, $field_key);
            }
            continue;
        }
        if ($type === 'block') {
            $block_id = sanitize_key((string) ($node['block_id'] ?? ''));
            if ($block_id === '' || !isset($block_ids[$block_id])) {
                $errors[] = sprintf('Document node #%d references missing block "%s".', $idx + 1, $block_id);
            }
            continue;
        }
        $errors[] = sprintf('Document node #%d has invalid type "%s".', $idx + 1, $type);
    }

    return array(
        'errors' => array_values(array_unique(array_filter($errors))),
        'warnings' => array_values(array_unique(array_filter($warnings))),
    );
}

function dcb_apply_ocr_candidate_review(array $draft_form, array $review_rows): array {
    $normalized_rows = dcb_normalize_ocr_candidates($review_rows);
    if (empty($normalized_rows)) {
        return $draft_form;
    }

    $accepted = array();
    foreach ($normalized_rows as $index => $candidate) {
        if (!is_array($candidate)) {
            continue;
        }
        $raw_row = isset($review_rows[$index]) && is_array($review_rows[$index]) ? $review_rows[$index] : array();
        $decision = sanitize_key((string) ($raw_row['decision'] ?? 'accept'));
        if ($decision === 'reject') {
            continue;
        }
        $accepted[] = $candidate;
    }

    $fields = array();
    foreach ($accepted as $candidate) {
        $key = sanitize_key((string) ($candidate['suggested_key'] ?? ''));
        $label = sanitize_text_field((string) ($candidate['field_label'] ?? ''));
        if ($key === '' || $label === '') {
            continue;
        }
        $type = sanitize_key((string) ($candidate['suggested_type'] ?? 'text'));
        if (!in_array($type, dcb_allowed_field_types(), true)) {
            $type = 'text';
        }
        $fields[] = array(
            'key' => $key,
            'label' => $label,
            'type' => $type,
            'required' => !empty($candidate['required_guess']),
            'ocr_meta' => array(
                'page_number' => max(1, (int) ($candidate['page_number'] ?? 1)),
                'source_text_snippet' => sanitize_text_field((string) ($candidate['source_text_snippet'] ?? '')),
                'confidence_bucket' => sanitize_key((string) ($candidate['confidence_bucket'] ?? 'low')),
                'confidence_score' => round(max(0, min(1, (float) ($candidate['confidence_score'] ?? 0))), 4),
                'suggested_type' => $type,
                'source_engine' => sanitize_key((string) ($candidate['source_engine'] ?? '')),
                'warning_state' => sanitize_key((string) ($candidate['warning_state'] ?? 'none')),
                'review_state' => 'confirmed',
            ),
        );
    }

    if (!empty($fields)) {
        $draft_form['fields'] = $fields;
    }

    $draft_form['ocr_candidates'] = $normalized_rows;
    if (!isset($draft_form['ocr_review']) || !is_array($draft_form['ocr_review'])) {
        $draft_form['ocr_review'] = array();
    }
    $draft_form['ocr_review']['accepted_count'] = count($fields);
    $draft_form['ocr_review']['reviewed_count'] = count($normalized_rows);

    return $draft_form;
}

function dcb_builder_preview_payload(array $form): array {
    $fields = isset($form['fields']) && is_array($form['fields']) ? $form['fields'] : array();
    $sections = isset($form['sections']) && is_array($form['sections']) ? $form['sections'] : array();
    $steps = isset($form['steps']) && is_array($form['steps']) ? $form['steps'] : array();
    $template_blocks = isset($form['template_blocks']) && is_array($form['template_blocks']) ? $form['template_blocks'] : array();
    $document_nodes = isset($form['document_nodes']) && is_array($form['document_nodes']) ? $form['document_nodes'] : array();

    $field_map = array();
    foreach ($fields as $field) {
        if (!is_array($field)) {
            continue;
        }
        $key = sanitize_key((string) ($field['key'] ?? ''));
        if ($key === '') {
            continue;
        }
        $field_map[$key] = array(
            'key' => $key,
            'label' => sanitize_text_field((string) ($field['label'] ?? $key)),
            'type' => sanitize_key((string) ($field['type'] ?? 'text')),
            'required' => !empty($field['required']),
        );
    }

    $block_map = array();
    foreach ($template_blocks as $block) {
        if (!is_array($block)) {
            continue;
        }
        $block_id = sanitize_key((string) ($block['block_id'] ?? ''));
        if ($block_id === '') {
            continue;
        }
        $block_map[$block_id] = array(
            'block_id' => $block_id,
            'type' => sanitize_key((string) ($block['type'] ?? 'paragraph')),
            'text' => sanitize_text_field((string) ($block['text'] ?? '')),
        );
    }

    $step_rows = array();
    foreach ($steps as $step) {
        if (!is_array($step)) {
            continue;
        }
        $step_key = sanitize_key((string) ($step['key'] ?? ''));
        $step_label = sanitize_text_field((string) ($step['label'] ?? $step_key));
        if ($step_key === '' && $step_label === '') {
            continue;
        }
        $section_keys = array_values(array_filter(array_map('sanitize_key', (array) ($step['section_keys'] ?? array()))));
        $section_rows = array();
        foreach ($sections as $section) {
            if (!is_array($section)) {
                continue;
            }
            $section_key = sanitize_key((string) ($section['key'] ?? ''));
            if (!empty($section_keys) && !in_array($section_key, $section_keys, true)) {
                continue;
            }
            $field_rows = array();
            foreach ((array) ($section['field_keys'] ?? array()) as $field_key) {
                $field_key = sanitize_key((string) $field_key);
                if ($field_key === '' || !isset($field_map[$field_key])) {
                    continue;
                }
                $field_rows[] = $field_map[$field_key];
            }
            $section_rows[] = array(
                'key' => $section_key,
                'label' => sanitize_text_field((string) ($section['label'] ?? $section_key)),
                'fields' => $field_rows,
            );
        }
        $step_rows[] = array(
            'key' => $step_key,
            'label' => $step_label,
            'sections' => $section_rows,
        );
    }

    $output_nodes = array();
    foreach ($document_nodes as $node) {
        if (!is_array($node)) {
            continue;
        }
        $type = sanitize_key((string) ($node['type'] ?? ''));
        if ($type === 'field') {
            $field_key = sanitize_key((string) ($node['field_key'] ?? ''));
            $output_nodes[] = array(
                'type' => 'field',
                'field_key' => $field_key,
                'field' => $field_key !== '' && isset($field_map[$field_key]) ? $field_map[$field_key] : null,
            );
            continue;
        }
        if ($type === 'block') {
            $block_id = sanitize_key((string) ($node['block_id'] ?? ''));
            $output_nodes[] = array(
                'type' => 'block',
                'block_id' => $block_id,
                'block' => $block_id !== '' && isset($block_map[$block_id]) ? $block_map[$block_id] : null,
            );
        }
    }

    return array(
        'steps' => $step_rows,
        'document_output' => $output_nodes,
        'template_blocks' => array_values($block_map),
        'field_order' => array_values($field_map),
    );
}

function dcb_validate_value(array $field, $value): array {
    $key = (string) ($field['key'] ?? 'field');
    $label = (string) ($field['label'] ?? $key);
    $type = (string) ($field['type'] ?? 'text');
    $required = !empty($field['required']);

    if (is_array($value)) {
        $value = '';
    }
    $value = trim((string) $value);

    if ($type === 'checkbox') {
        $checked = in_array($value, array('1', 'true', 'on', 'yes'), true);
        if ($required && !$checked) {
            return array('ok' => false, 'error' => $label . ' is required.');
        }
        return array('ok' => true, 'value' => $checked ? '1' : '');
    }

    if ($required && $value === '') {
        return array('ok' => false, 'error' => $label . ' is required.');
    }
    if ($value === '') {
        return array('ok' => true, 'value' => '');
    }

    if ($type === 'email' && !is_email($value)) {
        return array('ok' => false, 'error' => $label . ' must be a valid email.');
    }

    if (in_array($type, array('select', 'radio', 'yes_no'), true)) {
        $opts = isset($field['options']) && is_array($field['options']) ? array_map('strval', array_keys($field['options'])) : array();
        if (($type === 'yes_no') && empty($opts)) {
            $opts = array('yes', 'no');
        }
        if (!empty($opts) && !in_array($value, $opts, true)) {
            return array('ok' => false, 'error' => $label . ' has an invalid option selected.');
        }
    }

    if ($type === 'number') {
        if (!is_numeric($value)) {
            return array('ok' => false, 'error' => $label . ' must be numeric.');
        }
        $num = (float) $value;
        if (isset($field['min']) && $num < (float) $field['min']) {
            return array('ok' => false, 'error' => $label . ' is below minimum allowed value.');
        }
        if (isset($field['max']) && $num > (float) $field['max']) {
            return array('ok' => false, 'error' => $label . ' exceeds maximum allowed value.');
        }
        return array('ok' => true, 'value' => $num);
    }

    if ($type === 'date') {
        $ts = strtotime($value);
        if ($ts === false) {
            return array('ok' => false, 'error' => $label . ' must be a valid date.');
        }
    }

    if (isset($field['max']) && is_numeric($field['max']) && strlen($value) > (int) $field['max'] && $type !== 'number') {
        return array('ok' => false, 'error' => $label . ' is too long.');
    }

    return array('ok' => true, 'value' => sanitize_text_field($value));
}

function dcb_validate_submission(string $form_key, array $raw_values): array {
    $forms = dcb_form_definitions(false);
    if (!isset($forms[$form_key])) {
        return array('ok' => false, 'errors' => array('Unknown form selected.'));
    }

    $form = $forms[$form_key];
    $clean = array();
    $errors = array();

    foreach ((array) ($form['fields'] ?? array()) as $field) {
        $key = (string) ($field['key'] ?? '');
        if ($key === '') {
            continue;
        }

        if (!dcb_field_is_visible($field, $raw_values)) {
            $clean[$key] = '';
            continue;
        }

        $result = dcb_validate_value($field, $raw_values[$key] ?? '');
        if (empty($result['ok'])) {
            $errors[] = (string) ($result['error'] ?? ($key . ' is invalid.'));
        } else {
            $clean[$key] = $result['value'] ?? '';
        }
    }

    $errors = array_merge($errors, dcb_apply_generic_hard_stops($form, $clean, $raw_values));

    return array(
        'ok' => empty($errors),
        'errors' => $errors,
        'clean' => $clean,
        'form' => $form,
    );
}
