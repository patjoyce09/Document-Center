<?php

if (!defined('ABSPATH')) {
    exit;
}

final class DCB_Integration_Tutor {
    public static function init(): void {
        add_filter('dcb_submission_access_allowed', array(__CLASS__, 'gate_access'), 10, 4);
        add_action('dcb_submission_completed', array(__CLASS__, 'record_completion_relation'), 10, 3);
    }

    public static function is_enabled(): bool {
        return get_option('dcb_tutor_integration_enabled', '0') === '1';
    }

    public static function gate_access(bool $allowed, string $form_key, int $user_id, array $context): bool {
        if (!self::is_enabled()) {
            return $allowed;
        }

        $mapping = get_option('dcb_tutor_mapping', array());
        if (!is_array($mapping) || !isset($mapping[$form_key]) || !is_array($mapping[$form_key])) {
            return $allowed;
        }

        $form_map = $mapping[$form_key];
        $requires_completion = !empty($form_map['require_course_completion']);
        $course_id = isset($form_map['course_id']) ? (int) $form_map['course_id'] : 0;

        if (!$requires_completion || $course_id < 1 || !function_exists('tutor_utils')) {
            return $allowed;
        }

        $completed = false;
        if (function_exists('tutor_utils') && method_exists(tutor_utils(), 'is_completed_course')) {
            $completed = (bool) tutor_utils()->is_completed_course($course_id, $user_id);
        }

        return $completed ? $allowed : false;
    }

    public static function record_completion_relation(int $submission_id, string $form_key, int $user_id): void {
        if (!self::is_enabled()) {
            return;
        }

        $mapping = get_option('dcb_tutor_mapping', array());
        if (!is_array($mapping) || !isset($mapping[$form_key]) || !is_array($mapping[$form_key])) {
            return;
        }

        $form_map = $mapping[$form_key];
        $course_id = isset($form_map['course_id']) ? (int) $form_map['course_id'] : 0;
        $lesson_id = isset($form_map['lesson_id']) ? (int) $form_map['lesson_id'] : 0;
        $quiz_id = isset($form_map['quiz_id']) ? (int) $form_map['quiz_id'] : 0;

        update_post_meta($submission_id, '_dcb_tutor_relation', array(
            'enabled' => true,
            'user_id' => $user_id,
            'course_id' => $course_id,
            'lesson_id' => $lesson_id,
            'quiz_id' => $quiz_id,
            'recorded_at' => current_time('mysql'),
        ));
    }
}
