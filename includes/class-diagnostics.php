<?php

if (!defined('ABSPATH')) {
    exit;
}

final class DCB_Diagnostics {
    public static function init(): void {
        add_action('admin_post_dcb_save_settings', array(__CLASS__, 'save_settings'));
    }

    public static function render_settings_page(): void {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }

        $defaults = DCB_Settings::defaults();

        $field = static function (string $option) use ($defaults): string {
            $value = get_option($option, $defaults[$option] ?? '');
            return is_scalar($value) ? (string) $value : '';
        };

        echo '<div class="wrap">';
        echo '<h1>Document Center Settings</h1>';

        if (isset($_GET['updated'])) {
            echo '<div class="notice notice-success"><p>Settings saved.</p></div>';
        }

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field('dcb_save_settings', 'dcb_settings_nonce');
        echo '<input type="hidden" name="action" value="dcb_save_settings" />';

        echo '<table class="form-table"><tbody>';

        self::render_text_row('Brand Label', 'dcb_brand_label', $field('dcb_brand_label'));
        self::render_text_row('Default Upload Recipients', 'dcb_upload_default_recipients', $field('dcb_upload_default_recipients'));
        self::render_text_row('Review Queue Recipients', 'dcb_upload_review_recipients', $field('dcb_upload_review_recipients'));
        self::render_text_row('Minimum OCR Confidence', 'dcb_upload_min_confidence', $field('dcb_upload_min_confidence'));
        self::render_text_row('Consent Text Version', 'dcb_policy_consent_text_version', $field('dcb_policy_consent_text_version'));
        self::render_text_row('Attestation Text Version', 'dcb_policy_attestation_text_version', $field('dcb_policy_attestation_text_version'));
        self::render_text_row('Tesseract Path', 'dcb_upload_tesseract_path', $field('dcb_upload_tesseract_path'));
        self::render_text_row('pdftotext Path', 'dcb_upload_pdftotext_path', $field('dcb_upload_pdftotext_path'));
        self::render_text_row('pdftoppm Path', 'dcb_upload_pdftoppm_path', $field('dcb_upload_pdftoppm_path'));

        $checked = $field('dcb_upload_email_attachments') === '1';
        echo '<tr><th scope="row">Email Attachments</th><td>';
        echo '<label><input type="checkbox" name="dcb_upload_email_attachments" value="1" ' . checked($checked, true, false) . ' /> Send attachments in upload emails</label>';
        echo '</td></tr>';

        echo '</tbody></table>';

        submit_button('Save Settings');
        echo '</form>';

        echo '</div>';
    }

    private static function render_text_row(string $label, string $name, string $value): void {
        echo '<tr><th scope="row"><label for="' . esc_attr($name) . '">' . esc_html($label) . '</label></th><td>';
        echo '<input type="text" class="regular-text" id="' . esc_attr($name) . '" name="' . esc_attr($name) . '" value="' . esc_attr($value) . '" />';
        echo '</td></tr>';
    }

    public static function save_settings(): void {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }

        if (!isset($_POST['dcb_settings_nonce']) || !wp_verify_nonce((string) $_POST['dcb_settings_nonce'], 'dcb_save_settings')) {
            wp_die('Security check failed');
        }

        $text_options = array(
            'dcb_brand_label',
            'dcb_upload_default_recipients',
            'dcb_upload_review_recipients',
            'dcb_policy_consent_text_version',
            'dcb_policy_attestation_text_version',
            'dcb_upload_tesseract_path',
            'dcb_upload_pdftotext_path',
            'dcb_upload_pdftoppm_path',
        );

        foreach ($text_options as $opt) {
            $value = sanitize_text_field((string) ($_POST[$opt] ?? ''));
            update_option($opt, $value, false);
        }

        $min_conf = isset($_POST['dcb_upload_min_confidence']) ? (float) $_POST['dcb_upload_min_confidence'] : 0.45;
        $min_conf = max(0.0, min(1.0, $min_conf));
        update_option('dcb_upload_min_confidence', $min_conf, false);

        update_option('dcb_upload_email_attachments', !empty($_POST['dcb_upload_email_attachments']) ? '1' : '0', false);

        wp_safe_redirect(add_query_arg(array('page' => 'dcb-settings', 'updated' => '1'), admin_url('admin.php')));
        exit;
    }
}
