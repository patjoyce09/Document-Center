<?php

if (!defined('ABSPATH')) {
    exit;
}

final class DCB_Signatures {
    public static function init(): void {
        // Signature service is stateless and invoked from submissions/render helpers.
    }

    public static function normalize_mode(string $mode): string {
        $mode = sanitize_key($mode);
        return in_array($mode, array('typed', 'drawn'), true) ? $mode : 'typed';
    }

    public static function normalize_evidence(array $payload): array {
        $mode = self::normalize_mode((string) ($payload['mode'] ?? $payload['signatureMode'] ?? 'typed'));
        $signature_value = sanitize_text_field((string) ($payload['signature_value'] ?? $payload['signature'] ?? ''));
        $signer_display_name = sanitize_text_field((string) ($payload['signer_display_name'] ?? $payload['signerIdentity'] ?? ''));
        $signature_timestamp = sanitize_text_field((string) ($payload['signature_timestamp'] ?? $payload['signatureTimestamp'] ?? current_time('mysql')));
        $signature_timestamp_client = sanitize_text_field((string) ($payload['signature_timestamp_client'] ?? $payload['signatureTimestampClient'] ?? ''));
        $signature_date = sanitize_text_field((string) ($payload['signature_date'] ?? $payload['signatureDate'] ?? ''));
        $drawn_signature_hash = sanitize_text_field((string) ($payload['drawn_signature_hash'] ?? $payload['drawnSignatureHash'] ?? ''));

        $normalized = array(
            'mode' => $mode,
            'signature_value' => $signature_value,
            'signer_display_name' => $signer_display_name,
            'signer_user_id' => max(0, (int) ($payload['signer_user_id'] ?? $payload['signerUserId'] ?? 0)),
            'signature_timestamp' => $signature_timestamp,
            'signature_timestamp_client' => $signature_timestamp_client,
            'signature_date' => $signature_date,
            'signature_field_key' => sanitize_key((string) ($payload['signature_field_key'] ?? 'signature_name')),
            'signature_source' => sanitize_key((string) ($payload['signature_source'] ?? 'submission_form')),
            'drawn_signature_hash' => $drawn_signature_hash,
            'drawn_signature_available' => !empty($payload['drawn_signature_available']) || $drawn_signature_hash !== '',
        );

        if (isset($payload['ip'])) {
            $normalized['ip'] = sanitize_text_field((string) $payload['ip']);
        }
        if (isset($payload['user_agent'])) {
            $normalized['user_agent'] = sanitize_text_field((string) $payload['user_agent']);
        }
        if (isset($payload['consent_text_version'])) {
            $normalized['consent_text_version'] = sanitize_text_field((string) $payload['consent_text_version']);
        }
        if (isset($payload['attestation_text_version'])) {
            $normalized['attestation_text_version'] = sanitize_text_field((string) $payload['attestation_text_version']);
        }
        if (isset($payload['payload_hash'])) {
            $normalized['payload_hash'] = sanitize_text_field((string) $payload['payload_hash']);
        }

        if (function_exists('apply_filters')) {
            $normalized = (array) apply_filters('dcb_signature_normalized_evidence', $normalized, $payload);
        }

        return $normalized;
    }

    public static function persist_submission_signature(int $submission_id, array $payload): array {
        $normalized = self::normalize_evidence($payload);

        update_post_meta($submission_id, '_dcb_form_signature_mode', (string) $normalized['mode']);
        update_post_meta($submission_id, '_dcb_form_signer_identity', (string) $normalized['signer_display_name']);
        update_post_meta($submission_id, '_dcb_form_signature_timestamp', (string) $normalized['signature_timestamp']);
        update_post_meta($submission_id, '_dcb_form_signature_evidence_v2', wp_json_encode($normalized));

        if (!empty($payload['signature_drawn_data']) && is_string($payload['signature_drawn_data'])) {
            update_post_meta($submission_id, '_dcb_form_signature_drawn_data', $payload['signature_drawn_data']);
        }
        if (!empty($normalized['drawn_signature_hash'])) {
            update_post_meta($submission_id, '_dcb_form_signature_drawn_sha256', (string) $normalized['drawn_signature_hash']);
        }

        if (function_exists('do_action')) {
            do_action('dcb_signature_evidence_persisted', $submission_id, $normalized);
        }

        return $normalized;
    }

    public static function get_submission_signature(int $submission_id): array {
        $v2 = get_post_meta($submission_id, '_dcb_form_signature_evidence_v2', true);
        $decoded_v2 = is_string($v2) && $v2 !== '' ? json_decode($v2, true) : array();
        if (is_array($decoded_v2) && !empty($decoded_v2)) {
            return self::normalize_evidence($decoded_v2);
        }

        $legacy = get_post_meta($submission_id, '_dcb_form_esign_evidence', true);
        $legacy_decoded = is_string($legacy) && $legacy !== '' ? json_decode($legacy, true) : array();
        if (!is_array($legacy_decoded)) {
            $legacy_decoded = array();
        }

        $drawn_signature_hash = (string) get_post_meta($submission_id, '_dcb_form_signature_drawn_sha256', true);
        if ($drawn_signature_hash === '') {
            $drawn_data = (string) get_post_meta($submission_id, '_dcb_form_signature_drawn_data', true);
            if ($drawn_data !== '') {
                $drawn_signature_hash = hash('sha256', $drawn_data);
            }
        }

        $fallback = array(
            'mode' => (string) get_post_meta($submission_id, '_dcb_form_signature_mode', true),
            'signature' => (string) ($legacy_decoded['signature'] ?? ''),
            'signerIdentity' => (string) get_post_meta($submission_id, '_dcb_form_signer_identity', true),
            'signerUserId' => (int) get_post_meta($submission_id, '_dcb_form_submitted_by', true),
            'signatureTimestamp' => (string) get_post_meta($submission_id, '_dcb_form_signature_timestamp', true),
            'signatureTimestampClient' => (string) ($legacy_decoded['signatureTimestampClient'] ?? ''),
            'signatureDate' => (string) ($legacy_decoded['signatureDate'] ?? ''),
            'drawnSignatureHash' => $drawn_signature_hash,
            'ip' => (string) ($legacy_decoded['ip'] ?? ''),
            'user_agent' => (string) ($legacy_decoded['userAgent'] ?? ''),
            'consent_text_version' => (string) ($legacy_decoded['consentTextVersion'] ?? ''),
            'attestation_text_version' => (string) ($legacy_decoded['attestationTextVersion'] ?? ''),
            'payload_hash' => (string) ($legacy_decoded['hash'] ?? ''),
        );

        return self::normalize_evidence($fallback);
    }
}
