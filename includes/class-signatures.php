<?php

if (!defined('ABSPATH')) {
    exit;
}

final class DCB_Signatures {
    public static function init(): void {
        // Signature subsystem bootstrap.
    }

    public static function allowed_modes(): array {
        return array('typed', 'drawn');
    }

    public static function normalize_mode(string $mode): string {
        $mode = sanitize_key($mode);
        return in_array($mode, self::allowed_modes(), true) ? $mode : 'typed';
    }

    public static function validate_payload(array $args): array {
        $mode = self::normalize_mode((string) ($args['mode'] ?? 'typed'));
        $drawn = trim((string) ($args['drawn_data'] ?? ''));
        $typed = trim((string) ($args['typed_signature'] ?? ''));
        $signer_identity = sanitize_text_field((string) ($args['signer_identity'] ?? ''));
        $errors = array();

        if ($signer_identity === '') {
            $errors[] = 'Signer identity is required.';
        }

        if ($mode === 'drawn') {
            if (!dcb_signature_data_is_valid($drawn)) {
                $errors[] = 'Drawn signature is missing or invalid. Please sign again before submitting.';
            }
        } else {
            if ($typed === '') {
                $errors[] = 'Typed signature is required.';
            }
        }

        return array(
            'ok' => empty($errors),
            'errors' => $errors,
            'mode' => $mode,
            'drawn_data' => $drawn,
            'typed_signature' => sanitize_text_field($typed),
            'signer_identity' => $signer_identity,
        );
    }

    public static function build_evidence_package(array $args): array {
        $signature_mode = self::normalize_mode((string) ($args['signature_mode'] ?? 'typed'));
        $signature_drawn_data = trim((string) ($args['signature_drawn_data'] ?? ''));
        $signer_identity = sanitize_text_field((string) ($args['signer_identity'] ?? ''));
        $signature_timestamp_client = sanitize_text_field((string) ($args['signature_timestamp_client'] ?? ''));
        $signature_timestamp_server = sanitize_text_field((string) ($args['signature_timestamp_server'] ?? current_time('mysql')));
        $consent_text_version = sanitize_text_field((string) ($args['consent_text_version'] ?? ''));
        $attestation_text_version = sanitize_text_field((string) ($args['attestation_text_version'] ?? ''));
        $clean = isset($args['clean']) && is_array($args['clean']) ? $args['clean'] : array();

        $payload_hash = hash_hmac('sha256', wp_json_encode($clean), wp_salt('auth'));
        $drawn_signature_hash = '';
        if ($signature_mode === 'drawn' && $signature_drawn_data !== '') {
            $drawn_signature_hash = hash('sha256', $signature_drawn_data);
        }

        $ip = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field((string) wp_unslash($_SERVER['REMOTE_ADDR'])) : '';
        $ua = isset($_SERVER['HTTP_USER_AGENT']) ? sanitize_text_field((string) wp_unslash($_SERVER['HTTP_USER_AGENT'])) : '';

        return array(
            'payload_hash' => $payload_hash,
            'drawn_signature_hash' => $drawn_signature_hash,
            'signature_mode' => $signature_mode,
            'signature_drawn_data' => $signature_drawn_data,
            'signer_identity' => $signer_identity,
            'evidence' => array(
                'consent' => (string) ($clean['esign_consent'] ?? ''),
                'attestation' => (string) ($clean['attest_truth'] ?? ''),
                'signature' => (string) ($clean['signature_name'] ?? ''),
                'signatureDate' => (string) ($clean['signature_date'] ?? ''),
                'signatureMode' => $signature_mode,
                'signerIdentity' => $signer_identity,
                'signatureTimestampClient' => $signature_timestamp_client,
                'signatureTimestamp' => $signature_timestamp_server,
                'drawnSignatureHash' => $drawn_signature_hash,
                'consentTextVersion' => $consent_text_version,
                'attestationTextVersion' => $attestation_text_version,
                'ip' => $ip,
                'userAgent' => mb_substr($ua, 0, 255),
                'timestamp' => $signature_timestamp_server,
                'hash' => $payload_hash,
            ),
        );
    }

    public static function signature_badge_text(string $mode): string {
        $mode = self::normalize_mode($mode);
        return $mode === 'drawn' ? 'Drawn Signature' : 'Typed Signature';
    }
}
