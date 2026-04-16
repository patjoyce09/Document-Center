<?php

namespace DcbTests;

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/includes/helpers-ocr.php';
require_once dirname(__DIR__, 2) . '/includes/class-uploader.php';

final class OcrRuntimePersistenceTest extends TestCase {
    protected function setUp(): void {
        parent::setUp();

        $GLOBALS['dcb_options'] = array(
            'dcb_ocr_mode' => 'remote',
            'dcb_ocr_api_base_url' => 'https://ocr.example.test',
            'dcb_ocr_api_key' => 'secret-key',
            'dcb_ocr_api_auth_header' => 'X-API-Key',
            'dcb_ocr_timeout_seconds' => 10,
            'dcb_ocr_max_file_size_mb' => 15,
            'dcb_ocr_confidence_threshold' => 0.45,
        );
        $GLOBALS['dcb_post_meta'] = array();
        $GLOBALS['dcb_posts'] = array();
        $GLOBALS['dcb_next_post_id'] = 1000;
        unset(
            $GLOBALS['dcb_mock_remote_response'],
            $GLOBALS['dcb_mock_remote_responses'],
            $GLOBALS['dcb_mock_upload_result'],
            $GLOBALS['dcb_upload_runtime_ocr_warnings']
        );

        $_POST = array();
        $_GET = array();
        $_REQUEST = array();
        $_FILES = array();
    }

    public function testRemoteOcrSuccessRuntimeFlowIncludesCanonicalAndLegacyPayloads(): void {
        $tmp = tempnam(sys_get_temp_dir(), 'dcb_ocr_runtime_');
        file_put_contents((string) $tmp, 'sample');

        $GLOBALS['dcb_mock_remote_responses'] = array(
            $this->remoteResponse(200, array(
                'contract_version' => 'dcb-ocr-v1',
                'supported_file_types' => array('application/pdf', 'image/jpeg', 'image/png', 'image/webp'),
                'supports_pdf_text_extraction' => true,
                'supports_scanned_pdf_rasterization' => true,
                'supports_image_ocr' => true,
                'languages_available' => array('eng'),
            )),
            $this->remoteResponse(200, array(
                'request_id' => 'runtime-success-1',
                'provider' => 'hetzner-ocr',
                'provider_version' => '2026.04.15',
                'contract_version' => 'dcb-ocr-v1',
                'engine_used' => 'pdftotext',
                'text' => 'Invoice 123',
                'normalized_text' => 'invoice 123',
                'pages' => array(
                    array('page_number' => 1, 'engine' => 'pdftotext', 'extracted_text' => 'Invoice 123', 'text_length' => 11, 'confidence_proxy' => 0.95, 'warnings' => array()),
                ),
                'warnings' => array(),
                'failure_reason' => '',
                'confidence' => 0.95,
                'timings' => array('total_ms' => 10, 'validation_ms' => 1, 'extraction_ms' => 8, 'normalization_ms' => 1),
            )),
        );

        $result = \dcb_upload_extract_text_from_file((string) $tmp, 'application/pdf');
        @unlink((string) $tmp);

        $this->assertSame('Invoice 123', (string) ($result['text'] ?? ''));
        $this->assertSame('pdftotext', (string) ($result['engine_used'] ?? ''));
        $this->assertSame('hetzner-ocr', (string) ($result['provider'] ?? ''));
        $this->assertSame('runtime-success-1', (string) (($result['provenance']['request_id'] ?? '')));
        $this->assertArrayHasKey('timings', (array) ($result['provenance'] ?? array()));

        $legacy = (array) ($result['ocr'] ?? array());
        $this->assertSame('runtime-success-1', (string) ($legacy['request_id'] ?? ''));
        $this->assertSame('dcb-ocr-v1', (string) ($legacy['contract_version'] ?? ''));
        $this->assertSame('pdftotext', (string) ($legacy['engine_used'] ?? ''));
    }

    public function testRemoteAuthFailureAndTimeoutPathsPersistFailureReason(): void {
        $tmpA = tempnam(sys_get_temp_dir(), 'dcb_ocr_runtime_auth_');
        file_put_contents((string) $tmpA, 'sample');

        $GLOBALS['dcb_mock_remote_responses'] = array(
            $this->remoteResponse(200, array(
                'contract_version' => 'dcb-ocr-v1',
                'supported_file_types' => array('application/pdf'),
                'supports_pdf_text_extraction' => true,
                'supports_scanned_pdf_rasterization' => true,
                'supports_image_ocr' => true,
                'languages_available' => array('eng'),
            )),
            $this->remoteResponse(401, array('failure_reason' => 'auth_failed', 'message' => 'Invalid API key')),
        );

        $authResult = \dcb_upload_extract_text_from_file((string) $tmpA, 'application/pdf');
        @unlink((string) $tmpA);
        $this->assertSame('remote_auth_failed', (string) ($authResult['failure_reason'] ?? ''));

        $tmpB = tempnam(sys_get_temp_dir(), 'dcb_ocr_runtime_timeout_');
        file_put_contents((string) $tmpB, 'sample');

        $GLOBALS['dcb_mock_remote_responses'] = array(
            $this->remoteResponse(200, array(
                'contract_version' => 'dcb-ocr-v1',
                'supported_file_types' => array('application/pdf'),
                'supports_pdf_text_extraction' => true,
                'supports_scanned_pdf_rasterization' => true,
                'supports_image_ocr' => true,
                'languages_available' => array('eng'),
            )),
            new class extends \WP_Error {
                public function get_error_message(): string { return 'cURL error 28: Operation timed out'; }
                public function get_error_code(): string { return 'timeout'; }
            },
        );

        $timeoutResult = \dcb_upload_extract_text_from_file((string) $tmpB, 'application/pdf');
        @unlink((string) $tmpB);
        $this->assertSame('remote_timeout', (string) ($timeoutResult['failure_reason'] ?? ''));
    }

    public function testAutoModeRemoteEmptyFallsBackToLocalAndRecordsFallback(): void {
        \update_option('dcb_ocr_mode', 'auto');

        $tmp = tempnam(sys_get_temp_dir(), 'dcb_ocr_runtime_auto_');
        file_put_contents((string) $tmp, 'sample');

        $GLOBALS['dcb_mock_remote_responses'] = array(
            $this->remoteResponse(200, array('ok' => true, 'service' => 'hetzner-ocr', 'version' => '2026.04.15', 'contract_version' => 'dcb-ocr-v1')),
            $this->remoteResponse(200, array(
                'contract_version' => 'dcb-ocr-v1',
                'supported_file_types' => array('application/pdf', 'image/jpeg', 'image/png', 'image/webp'),
                'supports_pdf_text_extraction' => true,
                'supports_scanned_pdf_rasterization' => true,
                'supports_image_ocr' => true,
                'languages_available' => array('eng'),
            )),
            $this->remoteResponse(200, array(
                'contract_version' => 'dcb-ocr-v1',
                'supported_file_types' => array('application/pdf', 'image/jpeg', 'image/png', 'image/webp'),
                'supports_pdf_text_extraction' => true,
                'supports_scanned_pdf_rasterization' => true,
                'supports_image_ocr' => true,
                'languages_available' => array('eng'),
            )),
            $this->remoteResponse(200, array(
                'request_id' => 'runtime-empty-1',
                'provider' => 'hetzner-ocr',
                'provider_version' => '2026.04.15',
                'contract_version' => 'dcb-ocr-v1',
                'engine_used' => 'pdftotext',
                'text' => '',
                'normalized_text' => '',
                'pages' => array(),
                'warnings' => array(),
                'failure_reason' => '',
                'confidence' => 0,
                'timings' => array('total_ms' => 10),
            )),
        );

        $result = \dcb_upload_extract_text_from_file((string) $tmp, 'application/pdf');
        @unlink((string) $tmp);

        $this->assertSame('local', (string) (($result['provenance']['mode'] ?? '')));
        $this->assertSame('remote', (string) (($result['provenance']['fallback_from'] ?? '')));
        $this->assertSame('empty_extraction', (string) (($result['provenance']['fallback_reason'] ?? '')));

        $warnings = (array) ($result['warnings'] ?? array());
        $codes = array_map(static function ($row) {
            return is_array($row) ? (string) ($row['code'] ?? '') : '';
        }, $warnings);
        $this->assertContains('auto_fallback_to_local', $codes);

        $stats = (array) \get_option('dcb_ocr_remote_runtime_stats', array());
        $this->assertGreaterThanOrEqual(1, (int) ($stats['fallback_count'] ?? 0));
    }

    public function testProvenancePersistsIntoReviewQueueMetadata(): void {
        $tmp = tempnam(sys_get_temp_dir(), 'dcb_ocr_runtime_review_');
        file_put_contents((string) $tmp, 'sample');

        $GLOBALS['dcb_mock_remote_responses'] = array(
            $this->remoteResponse(200, array(
                'contract_version' => 'dcb-ocr-v1',
                'supported_file_types' => array('application/pdf'),
                'supports_pdf_text_extraction' => true,
                'supports_scanned_pdf_rasterization' => true,
                'supports_image_ocr' => true,
                'languages_available' => array('eng'),
            )),
            $this->remoteResponse(401, array('failure_reason' => 'auth_failed', 'message' => 'Invalid API key')),
        );

        $result = \dcb_upload_extract_text_from_file((string) $tmp, 'application/pdf');
        @unlink((string) $tmp);
        $this->assertSame('remote_auth_failed', (string) ($result['failure_reason'] ?? ''));

        $reviewId = $this->findLatestPostIdByType('dcb_ocr_review_queue');
        $this->assertGreaterThan(0, $reviewId);

        $this->assertNotSame('', (string) \get_post_meta($reviewId, '_dcb_ocr_review_request_id', true));
        $this->assertSame('remote', (string) \get_post_meta($reviewId, '_dcb_ocr_review_mode', true));
        $this->assertSame('dcb-ocr-v1', (string) \get_post_meta($reviewId, '_dcb_ocr_review_contract_version', true));
        $this->assertNotSame('', (string) \get_post_meta($reviewId, '_dcb_ocr_review_timings', true));
    }

    public function testUploaderRuntimeFlowPersistsProvenanceMetadata(): void {
        $tmp = tempnam(sys_get_temp_dir(), 'dcb_ocr_runtime_upload_');
        file_put_contents((string) $tmp, 'sample');

        $GLOBALS['dcb_mock_remote_responses'] = array(
            $this->remoteResponse(200, array(
                'contract_version' => 'dcb-ocr-v1',
                'supported_file_types' => array('application/pdf', 'image/jpeg', 'image/png', 'image/webp'),
                'supports_pdf_text_extraction' => true,
                'supports_scanned_pdf_rasterization' => true,
                'supports_image_ocr' => true,
                'languages_available' => array('eng'),
            )),
            $this->remoteResponse(200, array(
                'request_id' => 'runtime-upload-1',
                'provider' => 'hetzner-ocr',
                'provider_version' => '2026.04.15',
                'contract_version' => 'dcb-ocr-v1',
                'engine_used' => 'pdftotext',
                'text' => 'Upload OCR text',
                'normalized_text' => 'upload ocr text',
                'pages' => array(),
                'warnings' => array(),
                'failure_reason' => '',
                'confidence' => 0.9,
                'timings' => array('total_ms' => 9),
            )),
        );

        $_POST = array('nonce' => 'valid', 'typeHint' => 'intake');
        $_REQUEST = $_POST;
        $_FILES = array(
            'files' => array(
                'name' => array('sample.pdf'),
                'type' => array('application/pdf'),
                'tmp_name' => array($tmp),
                'error' => array(UPLOAD_ERR_OK),
                'size' => array((int) filesize($tmp)),
            ),
        );

        try {
            \DCB_Uploader::upload_files_ajax();
            $this->fail('Expected JSON response halt.');
        } catch (\DCB_Test_Halt $halt) {
            $this->assertSame('json_success', $halt->kind);
            $payload = is_array($halt->payload) ? $halt->payload : array();
            $rows = isset($payload['results']) && is_array($payload['results']) ? $payload['results'] : array();
            $this->assertNotEmpty($rows);
            $logId = (int) ($rows[0]['logId'] ?? 0);
            $this->assertGreaterThan(0, $logId);

            $this->assertSame('runtime-upload-1', (string) \get_post_meta($logId, '_dcb_upload_ocr_request_id', true));
            $this->assertSame('hetzner-ocr', (string) \get_post_meta($logId, '_dcb_upload_ocr_provider', true));
            $this->assertSame('2026.04.15', (string) \get_post_meta($logId, '_dcb_upload_ocr_provider_version', true));
            $this->assertSame('dcb-ocr-v1', (string) \get_post_meta($logId, '_dcb_upload_ocr_contract_version', true));
            $this->assertSame('pdftotext', (string) \get_post_meta($logId, '_dcb_upload_ocr_engine_used', true));
            $this->assertNotSame('', (string) \get_post_meta($logId, '_dcb_upload_ocr_meta', true));
        }
    }

    public function testRemoteProbeHistoryAndHealthMarkersPersist(): void {
        $_POST = array('dcb_ocr_remote_probe_nonce' => 'valid');
        $_REQUEST = $_POST;

        $GLOBALS['dcb_mock_remote_responses'] = array(
            $this->remoteResponse(200, array('ok' => true, 'service' => 'hetzner-ocr', 'version' => '2026.04.15', 'contract_version' => 'dcb-ocr-v1')),
            $this->remoteResponse(200, array(
                'contract_version' => 'dcb-ocr-v1',
                'supported_file_types' => array('application/pdf', 'image/jpeg', 'image/png', 'image/webp'),
                'supports_pdf_text_extraction' => true,
                'supports_scanned_pdf_rasterization' => true,
                'supports_image_ocr' => true,
                'languages_available' => array('eng'),
            )),
        );

        try {
            \DCB_OCR::remote_probe_action();
            $this->fail('Expected redirect.');
        } catch (\DCB_Test_Halt $halt) {
            $this->assertSame('redirect', $halt->kind);
        }

        $_POST = array('dcb_ocr_remote_probe_nonce' => 'valid');
        $_REQUEST = $_POST;
        $GLOBALS['dcb_mock_remote_responses'] = array(
            $this->remoteResponse(401, array('failure_reason' => 'auth_failed', 'message' => 'Invalid API key')),
            $this->remoteResponse(401, array('failure_reason' => 'auth_failed', 'message' => 'Invalid API key')),
        );

        try {
            \DCB_OCR::remote_probe_action();
            $this->fail('Expected redirect.');
        } catch (\DCB_Test_Halt $halt) {
            $this->assertSame('redirect', $halt->kind);
        }

        $history = (array) \get_option('dcb_ocr_remote_probe_history', array());
        $this->assertGreaterThanOrEqual(2, count($history));
        $this->assertNotSame('', (string) \get_option('dcb_ocr_remote_last_success_at', ''));
        $this->assertNotSame('', (string) \get_option('dcb_ocr_remote_last_failure_at', ''));
        $this->assertGreaterThanOrEqual(1, (int) \get_option('dcb_ocr_remote_unhealthy_streak', 0));
    }

    private function remoteResponse(int $statusCode, array $body): array {
        return array(
            'response' => array('code' => $statusCode),
            'body' => \wp_json_encode($body),
        );
    }

    private function findLatestPostIdByType(string $postType): int {
        $latest = 0;
        foreach ((array) ($GLOBALS['dcb_posts'] ?? array()) as $post) {
            if (!$post instanceof \WP_Post) {
                continue;
            }
            if ((string) $post->post_type !== $postType) {
                continue;
            }
            if ((int) $post->ID > $latest) {
                $latest = (int) $post->ID;
            }
        }
        return $latest;
    }
}
