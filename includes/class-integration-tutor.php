<?php

if (!defined('ABSPATH')) {
    exit;
}

final class DCB_Integration_Tutor {
    private const LAST_ACCESS_META_KEY = '_dcb_tutor_last_access_gate';

    public static function init(): void {
        add_filter('dcb_submission_access_allowed', array(__CLASS__, 'gate_access'), 10, 4);
        add_action('dcb_submission_completed', array(__CLASS__, 'record_completion_relation'), 10, 3);
    }

    public static function is_enabled(): bool {
        return get_option('dcb_tutor_integration_enabled', '0') === '1';
    }

    public static function is_tutor_available(): bool {
        return function_exists('tutor_utils');
    }

    public static function defaults_for_mapping(string $form_key = ''): array {
        return array(
            'form_key' => sanitize_key($form_key),
            'course_id' => 0,
            'lesson_id' => 0,
            'quiz_id' => 0,
            'require_course_completion' => false,
            'assign_training_after_completion' => false,
            'record_relationship_metadata' => true,
            'relation_type' => 'form_completion',
            'trigger_source' => 'dcb_submission_completed',
        );
    }

    public static function normalize_mapping_row(array $row, string $fallback_key = ''): array {
        $defaults = self::defaults_for_mapping($fallback_key);
        $form_key = sanitize_key((string) ($row['form_key'] ?? $fallback_key));
        if ($form_key === '') {
            $form_key = sanitize_key((string) $fallback_key);
        }

        return array(
            'form_key' => $form_key,
            'course_id' => max(0, (int) ($row['course_id'] ?? 0)),
            'lesson_id' => max(0, (int) ($row['lesson_id'] ?? 0)),
            'quiz_id' => max(0, (int) ($row['quiz_id'] ?? 0)),
            'require_course_completion' => !empty($row['require_course_completion']),
            'assign_training_after_completion' => !empty($row['assign_training_after_completion']),
            'record_relationship_metadata' => isset($row['record_relationship_metadata']) ? !empty($row['record_relationship_metadata']) : true,
            'relation_type' => sanitize_key((string) ($row['relation_type'] ?? $defaults['relation_type'])),
            'trigger_source' => sanitize_key((string) ($row['trigger_source'] ?? $defaults['trigger_source'])),
        );
    }

    public static function get_mapping(): array {
        $raw = get_option('dcb_tutor_mapping', array());
        if (!is_array($raw)) {
            return array();
        }

        $normalized = array();
        foreach ($raw as $form_key => $row) {
            if (!is_array($row)) {
                continue;
            }
            $clean_key = sanitize_key((string) $form_key);
            if ($clean_key === '') {
                continue;
            }
            $normalized[$clean_key] = self::normalize_mapping_row($row, $clean_key);
        }

        return $normalized;
    }

    public static function get_mapping_for_form(string $form_key): array {
        $mapping = self::get_mapping();
        $form_key = sanitize_key($form_key);
        if ($form_key === '' || empty($mapping[$form_key]) || !is_array($mapping[$form_key])) {
            return array();
        }

        return self::normalize_mapping_row($mapping[$form_key], $form_key);
    }

    public static function get_mapped_form_keys(): array {
        $mapping = self::get_mapping();
        return array_keys($mapping);
    }

    private static function detect_completion_state(int $user_id, array $mapping): array {
        $state = array(
            'course_completed' => false,
            'lesson_completed' => false,
            'quiz_completed' => false,
            'checks_available' => false,
        );

        if ($user_id < 1 || !self::is_tutor_available()) {
            return $state;
        }

        $utils = tutor_utils();
        if (!is_object($utils)) {
            return $state;
        }

        if (method_exists($utils, 'is_completed_course') && (int) ($mapping['course_id'] ?? 0) > 0) {
            $state['checks_available'] = true;
            $state['course_completed'] = (bool) $utils->is_completed_course((int) $mapping['course_id'], $user_id);
        }

        if (method_exists($utils, 'is_completed_lesson') && (int) ($mapping['lesson_id'] ?? 0) > 0) {
            $state['checks_available'] = true;
            $state['lesson_completed'] = (bool) $utils->is_completed_lesson((int) $mapping['lesson_id'], $user_id);
        }

        if (method_exists($utils, 'is_completed_quiz') && (int) ($mapping['quiz_id'] ?? 0) > 0) {
            $state['checks_available'] = true;
            $state['quiz_completed'] = (bool) $utils->is_completed_quiz((int) $mapping['quiz_id'], $user_id);
        }

        return $state;
    }

    private static function remember_access_gate_result(int $user_id, array $details): void {
        if ($user_id < 1 || !function_exists('update_user_meta')) {
            return;
        }
        update_user_meta($user_id, self::LAST_ACCESS_META_KEY, $details);
    }

    public static function get_last_access_gate_result(int $user_id): array {
        if ($user_id < 1 || !function_exists('get_user_meta')) {
            return array();
        }
        $data = get_user_meta($user_id, self::LAST_ACCESS_META_KEY, true);
        return is_array($data) ? $data : array();
    }

    public static function access_denial_message(string $form_key, int $user_id): string {
        $data = self::get_last_access_gate_result($user_id);
        $blocked_requirement = sanitize_key((string) ($data['blocked_requirement'] ?? ''));
        $mapped_form_key = sanitize_key((string) ($data['form_key'] ?? ''));
        if ($mapped_form_key !== '' && $mapped_form_key !== sanitize_key($form_key)) {
            return 'Access denied for this form. Prerequisites were not met.';
        }

        if ($blocked_requirement === 'course_completion') {
            $course_id = max(0, (int) ($data['mapping']['course_id'] ?? 0));
            if ($course_id > 0) {
                return 'Access denied. Required Tutor course completion was not detected for course ID ' . $course_id . '.';
            }
            return 'Access denied. Required Tutor course completion was not detected.';
        }

        return 'Access denied for this form. Prerequisites were not met.';
    }

    private static function relation_payload(int $submission_id, string $form_key, int $user_id, array $mapping): array {
        return array(
            'enabled' => true,
            'mapping_key' => sanitize_key($form_key),
            'form_key' => sanitize_key($form_key),
            'user_id' => max(0, $user_id),
            'course_id' => max(0, (int) ($mapping['course_id'] ?? 0)),
            'lesson_id' => max(0, (int) ($mapping['lesson_id'] ?? 0)),
            'quiz_id' => max(0, (int) ($mapping['quiz_id'] ?? 0)),
            'relation_type' => sanitize_key((string) ($mapping['relation_type'] ?? 'form_completion')),
            'trigger_source' => sanitize_key((string) ($mapping['trigger_source'] ?? 'dcb_submission_completed')),
            'recorded_at' => current_time('mysql'),
            'submission_id' => $submission_id,
        );
    }

    private static function attempt_training_assignment(int $submission_id, int $user_id, string $form_key, array $mapping): array {
        $payload = array(
            'submission_id' => $submission_id,
            'user_id' => max(0, $user_id),
            'form_key' => sanitize_key($form_key),
            'course_id' => max(0, (int) ($mapping['course_id'] ?? 0)),
            'lesson_id' => max(0, (int) ($mapping['lesson_id'] ?? 0)),
            'quiz_id' => max(0, (int) ($mapping['quiz_id'] ?? 0)),
        );

        $adapter_result = function_exists('apply_filters')
            ? apply_filters('dcb_tutor_training_assignment_adapter', null, $payload, $mapping)
            : null;

        if (is_array($adapter_result)) {
            return array(
                'attempted' => !empty($adapter_result['attempted']),
                'success' => !empty($adapter_result['success']),
                'message' => sanitize_text_field((string) ($adapter_result['message'] ?? 'Adapter path')),
                'method' => sanitize_key((string) ($adapter_result['method'] ?? 'adapter')),
                'course_id' => $payload['course_id'],
            );
        }

        if (!self::is_tutor_available() || $payload['user_id'] < 1 || $payload['course_id'] < 1) {
            return array(
                'attempted' => false,
                'success' => false,
                'message' => 'Tutor assignment adapter not available.',
                'method' => 'none',
                'course_id' => $payload['course_id'],
            );
        }

        $utils = tutor_utils();
        if (!is_object($utils) || !method_exists($utils, 'do_enroll')) {
            return array(
                'attempted' => false,
                'success' => false,
                'message' => 'Tutor native enroll method unavailable.',
                'method' => 'native_unavailable',
                'course_id' => $payload['course_id'],
            );
        }

        try {
            $ok = (bool) $utils->do_enroll($payload['course_id'], $payload['user_id']);
            return array(
                'attempted' => true,
                'success' => $ok,
                'message' => $ok ? 'Tutor course assignment completed.' : 'Tutor course assignment failed.',
                'method' => 'native_do_enroll',
                'course_id' => $payload['course_id'],
            );
        } catch (Throwable $e) {
            return array(
                'attempted' => true,
                'success' => false,
                'message' => 'Tutor assignment exception: ' . sanitize_text_field($e->getMessage()),
                'method' => 'native_exception',
                'course_id' => $payload['course_id'],
            );
        }
    }

    public static function gate_access(bool $allowed, string $form_key, int $user_id, array $context): bool {
        if (!self::is_enabled()) {
            return $allowed;
        }

        $form_key = sanitize_key($form_key);
        $mapping = self::get_mapping_for_form($form_key);
        if (empty($mapping)) {
            return $allowed;
        }

        $state = self::detect_completion_state($user_id, $mapping);
        $requires_completion = !empty($mapping['require_course_completion']);
        $course_id = max(0, (int) ($mapping['course_id'] ?? 0));

        $blocked_requirement = '';
        $decision = $allowed;
        if ($requires_completion && $course_id > 0) {
            $decision = !empty($state['course_completed']) ? $allowed : false;
            if (!$decision) {
                $blocked_requirement = 'course_completion';
            }
        }

        $details = array(
            'form_key' => $form_key,
            'mapping' => $mapping,
            'requires_completion' => $requires_completion,
            'blocked_requirement' => $blocked_requirement,
            'completion_state' => $state,
            'tutor_available' => self::is_tutor_available(),
            'allowed_in' => (bool) $allowed,
            'allowed_out' => (bool) $decision,
            'user_id' => max(0, $user_id),
            'context' => $context,
            'evaluated_at' => current_time('mysql'),
        );

        self::remember_access_gate_result($user_id, $details);

        if (function_exists('do_action')) {
            do_action('dcb_tutor_mapping_resolved', $form_key, $mapping, $details);
            do_action('dcb_tutor_access_evaluated', $details);
            if (!$decision) {
                do_action('dcb_tutor_access_gated', $details);
            }
        }

        return (bool) $decision;
    }

    public static function record_completion_relation(int $submission_id, string $form_key, int $user_id): void {
        if (!self::is_enabled()) {
            return;
        }

        $form_key = sanitize_key($form_key);
        $mapping = self::get_mapping_for_form($form_key);
        if (empty($mapping)) {
            return;
        }

        if (function_exists('do_action')) {
            do_action('dcb_tutor_mapping_resolved', $form_key, $mapping, array(
                'source' => 'record_completion_relation',
                'submission_id' => $submission_id,
                'user_id' => $user_id,
            ));
        }

        if (!empty($mapping['record_relationship_metadata'])) {
            $relation = self::relation_payload($submission_id, $form_key, $user_id, $mapping);
            update_post_meta($submission_id, '_dcb_tutor_relation', $relation);
            update_post_meta($submission_id, '_dcb_tutor_relation_v2', $relation);

            if (function_exists('do_action')) {
                do_action('dcb_tutor_completion_relation_recorded', $submission_id, $relation, $mapping);
            }
        }

        if (!empty($mapping['assign_training_after_completion'])) {
            $assignment = self::attempt_training_assignment($submission_id, $user_id, $form_key, $mapping);
            update_post_meta($submission_id, '_dcb_tutor_training_assignment', $assignment);

            if (function_exists('do_action')) {
                do_action('dcb_tutor_training_assignment_attempted', $submission_id, $assignment, $mapping);
                if (!empty($assignment['success'])) {
                    do_action('dcb_tutor_training_assignment_completed', $submission_id, $assignment, $mapping);
                } else {
                    do_action('dcb_tutor_training_assignment_failed', $submission_id, $assignment, $mapping);
                }
            }
        }
    }

    public static function render_submission_integration_meta(int $submission_id): string {
        $relation = get_post_meta($submission_id, '_dcb_tutor_relation_v2', true);
        if (!is_array($relation) || empty($relation)) {
            $legacy = get_post_meta($submission_id, '_dcb_tutor_relation', true);
            $relation = is_array($legacy) ? $legacy : array();
        }
        $assignment = get_post_meta($submission_id, '_dcb_tutor_training_assignment', true);
        if (!is_array($assignment)) {
            $assignment = array();
        }

        if (empty($relation) && empty($assignment)) {
            return '<div style="margin-top:14px;border:1px solid #e3e8ef;border-radius:8px;padding:12px;background:#fbfcfe;"><h3 style="margin-top:0;">Tutor Integration</h3><p style="margin:0;">No Tutor relationship metadata was recorded for this submission.</p></div>';
        }

        $html = '<div style="margin-top:14px;border:1px solid #e3e8ef;border-radius:8px;padding:12px;background:#fbfcfe;">';
        $html .= '<h3 style="margin-top:0;">Tutor Integration</h3>';
        if (!empty($relation)) {
            $html .= '<ul style="margin:0 0 10px 0;padding-left:18px;">';
            $html .= '<li><strong>Form Key:</strong> ' . esc_html((string) ($relation['form_key'] ?? '')) . '</li>';
            $html .= '<li><strong>User ID:</strong> ' . esc_html((string) ($relation['user_id'] ?? 0)) . '</li>';
            $html .= '<li><strong>Course / Lesson / Quiz:</strong> ' . esc_html((string) ($relation['course_id'] ?? 0) . ' / ' . (string) ($relation['lesson_id'] ?? 0) . ' / ' . (string) ($relation['quiz_id'] ?? 0)) . '</li>';
            $html .= '<li><strong>Relation Type:</strong> ' . esc_html((string) ($relation['relation_type'] ?? 'form_completion')) . '</li>';
            $html .= '<li><strong>Trigger Source:</strong> ' . esc_html((string) ($relation['trigger_source'] ?? 'dcb_submission_completed')) . '</li>';
            $html .= '<li><strong>Recorded At:</strong> ' . esc_html((string) ($relation['recorded_at'] ?? '')) . '</li>';
            $html .= '</ul>';
        }

        if (!empty($assignment)) {
            $status = !empty($assignment['success']) ? 'success' : (!empty($assignment['attempted']) ? 'failed' : 'skipped');
            $html .= '<p style="margin:0;"><strong>Training Assignment:</strong> ' . esc_html(strtoupper($status)) . ' — ' . esc_html((string) ($assignment['message'] ?? '')) . '</p>';
        }

        $html .= '</div>';
        return $html;
    }

    public static function render_settings_rows(): void {
        $mapping = self::get_mapping();
        $form_defs = function_exists('dcb_form_definitions') ? dcb_form_definitions(false) : array();
        $form_keys = array();
        if (is_array($form_defs)) {
            foreach ($form_defs as $form_key => $definition) {
                $clean = sanitize_key((string) $form_key);
                if ($clean !== '') {
                    $form_keys[] = $clean;
                }
            }
        }
        $form_keys = array_values(array_unique(array_merge($form_keys, array_keys($mapping))));
        sort($form_keys);

        $rows = array();
        foreach ($form_keys as $form_key) {
            $rows[] = !empty($mapping[$form_key]) ? self::normalize_mapping_row((array) $mapping[$form_key], $form_key) : self::defaults_for_mapping($form_key);
        }
        if (empty($rows)) {
            $rows[] = self::defaults_for_mapping('');
        }
        $rows[] = self::defaults_for_mapping('');

        echo '<tr><th scope="row">Tutor Mapping Rules</th><td>';
        echo '<table class="widefat striped" style="max-width:1200px;"><thead><tr>';
        echo '<th>Form Key</th><th>Course ID</th><th>Lesson ID</th><th>Quiz ID</th><th>Require Completion</th><th>Assign After Completion</th><th>Record Relation</th>';
        echo '</tr></thead><tbody>';

        foreach ($rows as $index => $row) {
            echo '<tr>';
            echo '<td><input type="text" name="dcb_tutor_rows[' . esc_attr((string) $index) . '][form_key]" value="' . esc_attr((string) ($row['form_key'] ?? '')) . '" class="regular-text" placeholder="intake_form" /></td>';
            echo '<td><input type="number" min="0" step="1" name="dcb_tutor_rows[' . esc_attr((string) $index) . '][course_id]" value="' . esc_attr((string) ($row['course_id'] ?? 0)) . '" style="width:110px;" /></td>';
            echo '<td><input type="number" min="0" step="1" name="dcb_tutor_rows[' . esc_attr((string) $index) . '][lesson_id]" value="' . esc_attr((string) ($row['lesson_id'] ?? 0)) . '" style="width:110px;" /></td>';
            echo '<td><input type="number" min="0" step="1" name="dcb_tutor_rows[' . esc_attr((string) $index) . '][quiz_id]" value="' . esc_attr((string) ($row['quiz_id'] ?? 0)) . '" style="width:110px;" /></td>';
            echo '<td><label><input type="checkbox" name="dcb_tutor_rows[' . esc_attr((string) $index) . '][require_course_completion]" value="1" ' . checked(!empty($row['require_course_completion']), true, false) . ' /> Required</label></td>';
            echo '<td><label><input type="checkbox" name="dcb_tutor_rows[' . esc_attr((string) $index) . '][assign_training_after_completion]" value="1" ' . checked(!empty($row['assign_training_after_completion']), true, false) . ' /> Assign</label></td>';
            echo '<td><label><input type="checkbox" name="dcb_tutor_rows[' . esc_attr((string) $index) . '][record_relationship_metadata]" value="1" ' . checked(!empty($row['record_relationship_metadata']), true, false) . ' /> Record</label></td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
        echo '<p class="description">Tutor integration is optional. Configure by form key. Empty rows are ignored.</p>';
        echo '</td></tr>';

        $mapping_raw = wp_json_encode($mapping, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if (!is_string($mapping_raw)) {
            $mapping_raw = '{}';
        }

        echo '<tr><th scope="row"><label for="dcb_tutor_mapping_json">Tutor Mapping JSON (Advanced Fallback)</label></th><td>';
        echo '<textarea id="dcb_tutor_mapping_json" name="dcb_tutor_mapping_json" rows="8" class="large-text code" placeholder="Optional JSON override">' . esc_textarea($mapping_raw) . '</textarea>';
        echo '<p class="description">Optional advanced override. Leave as-is to use structured rows above.</p>';
        echo '</td></tr>';
    }

    public static function save_settings_from_post(array $post): void {
        $rows = isset($post['dcb_tutor_rows']) && is_array($post['dcb_tutor_rows']) ? $post['dcb_tutor_rows'] : array();
        $mapping = array();
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $form_key = sanitize_key((string) ($row['form_key'] ?? ''));
            if ($form_key === '') {
                continue;
            }
            $mapping[$form_key] = self::normalize_mapping_row($row, $form_key);
        }

        $mapping_raw = isset($post['dcb_tutor_mapping_json']) ? wp_unslash((string) $post['dcb_tutor_mapping_json']) : '';
        $mapping_raw = trim($mapping_raw);
        if ($mapping_raw !== '') {
            $decoded = json_decode($mapping_raw, true);
            if (is_array($decoded)) {
                $json_mapping = array();
                foreach ($decoded as $form_key => $row) {
                    if (!is_array($row)) {
                        continue;
                    }
                    $clean_key = sanitize_key((string) $form_key);
                    if ($clean_key === '') {
                        continue;
                    }
                    $json_mapping[$clean_key] = self::normalize_mapping_row($row, $clean_key);
                }
                $mapping = $json_mapping;
            }
        }

        update_option('dcb_tutor_mapping', $mapping, false);
    }

    public static function merge_submission_access_denial_message(string $message, string $form_key, int $user_id): string {
        $override = self::access_denial_message($form_key, $user_id);
        return $override !== '' ? $override : $message;
    }
}
