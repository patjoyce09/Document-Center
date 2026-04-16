<?php

if (!defined('ABSPATH')) {
    exit;
}

final class DCB_Renderer {
    public static function init(): void {
        add_action('admin_post_dcb_print_submission', array(__CLASS__, 'print_submission_action'));
        add_action('admin_post_dcb_export_submission', array(__CLASS__, 'export_submission_action'));
        add_action('admin_post_dcb_export_submission_pdf', array(__CLASS__, 'export_submission_pdf_action'));
    }

    private static function guard(string $action, int $submission_id): void {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }
        check_admin_referer($action . '_' . $submission_id);
        $post = get_post($submission_id);
        if (!$post instanceof WP_Post || $post->post_type !== 'dcb_form_submission') {
            wp_die('Invalid submission.');
        }
    }

    public static function submission_print_url(int $submission_id): string {
        return wp_nonce_url(admin_url('admin-post.php?action=dcb_print_submission&submission_id=' . $submission_id), 'dcb_print_submission_' . $submission_id);
    }

    public static function submission_export_url(int $submission_id): string {
        return wp_nonce_url(admin_url('admin-post.php?action=dcb_export_submission&submission_id=' . $submission_id), 'dcb_export_submission_' . $submission_id);
    }

    public static function submission_export_pdf_url(int $submission_id): string {
        return wp_nonce_url(admin_url('admin-post.php?action=dcb_export_submission_pdf&submission_id=' . $submission_id), 'dcb_export_submission_pdf_' . $submission_id);
    }

    public static function print_submission_action(): void {
        $submission_id = isset($_GET['submission_id']) ? (int) $_GET['submission_id'] : 0;
        self::guard('dcb_print_submission', $submission_id);

        dcb_finalize_submission_output($submission_id, get_current_user_id());
        $rendered = (string) get_post_meta($submission_id, '_dcb_form_rendered_html', true);
        if ($rendered === '') {
            $rendered = dcb_render_submission_html($submission_id, 'print');
        }

        nocache_headers();
        echo '<!doctype html><html><head><meta charset="utf-8" /><title>Document Submission ' . esc_html((string) $submission_id) . '</title>';
        echo '<style>body{font-family:Arial,sans-serif;padding:18px;color:#1d2c44}code{word-break:break-all}@media print{.no-print{display:none}}</style>';
        echo '</head><body>';
        echo '<p class="no-print"><button onclick="window.print()">Print</button></p>';
        echo $rendered; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        echo '</body></html>';
        exit;
    }

    public static function export_submission_action(): void {
        $submission_id = isset($_GET['submission_id']) ? (int) $_GET['submission_id'] : 0;
        self::guard('dcb_export_submission', $submission_id);

        dcb_finalize_submission_output($submission_id, get_current_user_id());
        $payload = dcb_normalize_submission_payload($submission_id);
        if (empty($payload)) {
            wp_die('Could not export submission payload.');
        }

        $filename = 'dcb-submission-' . $submission_id . '.json';
        nocache_headers();
        header('Content-Type: application/json; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . sanitize_file_name($filename) . '"');
        echo wp_json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        exit;
    }

    public static function export_submission_pdf_action(): void {
        $submission_id = isset($_GET['submission_id']) ? (int) $_GET['submission_id'] : 0;
        self::guard('dcb_export_submission_pdf', $submission_id);

        dcb_finalize_submission_output($submission_id, get_current_user_id());
        $payload = dcb_normalize_submission_payload($submission_id);
        if (empty($payload)) {
            wp_die('Could not export submission payload.');
        }

        $adapter_result = apply_filters('dcb_pdf_export_adapter', array(
            'ok' => false,
            'mime' => 'application/pdf',
            'filename' => 'dcb-submission-' . $submission_id . '.pdf',
            'binary' => '',
            'message' => 'No PDF adapter installed.',
        ), $submission_id, $payload);

        if (!is_array($adapter_result) || empty($adapter_result['ok']) || !is_string($adapter_result['binary']) || $adapter_result['binary'] === '') {
            wp_die(esc_html((string) ($adapter_result['message'] ?? 'PDF adapter failed.')));
        }

        nocache_headers();
        header('Content-Type: ' . sanitize_text_field((string) ($adapter_result['mime'] ?? 'application/pdf')));
        header('Content-Disposition: attachment; filename="' . sanitize_file_name((string) ($adapter_result['filename'] ?? ('dcb-submission-' . $submission_id . '.pdf'))) . '"');
        echo $adapter_result['binary']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        exit;
    }
}
