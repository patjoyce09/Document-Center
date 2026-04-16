<?php

namespace DcbTests;

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/includes/class-ocr-engine.php';

final class OcrContractTest extends TestCase {
    protected function setUp(): void {
        parent::setUp();
        $GLOBALS['dcb_options'] = array(
            'dcb_ocr_mode' => 'remote',
            'dcb_ocr_api_base_url' => 'https://ocr.example.test',
            'dcb_ocr_api_key' => 'secret-key',
            'dcb_ocr_api_auth_header' => 'X-API-Key',
            'dcb_ocr_timeout_seconds' => 10,
            'dcb_ocr_max_file_size_mb' => 15,
        );
    }

    public function testRemoteCapabilitiesExposeContractMetadata(): void {
        $engine = new \DCB_OCR_Engine_Remote();
        $caps = $engine->capabilities();

        $this->assertArrayHasKey('contract_version', $caps);
        $this->assertSame('dcb-ocr-v1', $caps['contract_version']);
        $this->assertArrayHasKey('auth_header', $caps);
    }

    public function testRemoteExtractRejectsInvalidResponseShape(): void {
        $tmp = tempnam(sys_get_temp_dir(), 'dcb_ocr_');
        file_put_contents((string) $tmp, 'sample');

        $GLOBALS['dcb_mock_remote_response'] = array(
            'response' => array('code' => 200),
            'body' => wp_json_encode(array(
                'contract_version' => 'dcb-ocr-v1',
                'result' => array(
                    'text' => 'ok',
                    'warnings' => 'not-an-array',
                ),
            )),
        );

        $engine = new \DCB_OCR_Engine_Remote();
        $result = $engine->extract((string) $tmp, 'text/plain');

        @unlink((string) $tmp);
        unset($GLOBALS['dcb_mock_remote_response']);

        $this->assertSame('remote_contract_invalid_shape', (string) ($result['failure_reason'] ?? ''));
    }

    public function testRemoteExtractAcceptsFlatContractResponseAndPersistsProvenance(): void {
        $tmp = tempnam(sys_get_temp_dir(), 'dcb_ocr_');
        file_put_contents((string) $tmp, 'sample');

        $GLOBALS['dcb_mock_remote_response'] = array(
            'response' => array('code' => 200),
            'body' => wp_json_encode(array(
                'request_id' => 'remote-123',
                'provider' => 'hetzner-ocr',
                'provider_version' => '2026.04.15',
                'contract_version' => 'dcb-ocr-v1',
                'engine_used' => 'pdftotext',
                'text' => 'Invoice Number 123',
                'normalized_text' => 'invoice number 123',
                'pages' => array(
                    array(
                        'page_number' => 1,
                        'engine' => 'pdftotext',
                        'extracted_text' => 'Invoice Number 123',
                        'text_length' => 18,
                        'confidence_proxy' => 0.96,
                        'warnings' => array(),
                    ),
                ),
                'warnings' => array(),
                'failure_reason' => '',
                'confidence' => 0.96,
                'timings' => array('total_ms' => 20, 'validation_ms' => 2, 'extraction_ms' => 15, 'normalization_ms' => 3),
            )),
        );

        $engine = new \DCB_OCR_Engine_Remote();
        $result = $engine->extract((string) $tmp, 'application/pdf');

        @unlink((string) $tmp);
        unset($GLOBALS['dcb_mock_remote_response']);

        $this->assertSame('', (string) ($result['failure_reason'] ?? ''));
        $this->assertSame('hetzner-ocr', (string) ($result['provider'] ?? ''));
        $this->assertSame('pdftotext', (string) ($result['engine_used'] ?? ''));
        $this->assertSame('remote-123', (string) (($result['provenance']['request_id'] ?? '')));
        $this->assertSame('2026.04.15', (string) (($result['provenance']['provider_version'] ?? '')));
        $this->assertSame('dcb-ocr-v1', (string) (($result['provenance']['contract_version'] ?? '')));
        $this->assertArrayHasKey('timings', (array) ($result['provenance'] ?? array()));
    }

    public function testRemoteExtractRejectsContractVersionMismatch(): void {
        $tmp = tempnam(sys_get_temp_dir(), 'dcb_ocr_');
        file_put_contents((string) $tmp, 'sample');

        $GLOBALS['dcb_mock_remote_response'] = array(
            'response' => array('code' => 200),
            'body' => wp_json_encode(array(
                'contract_version' => 'dcb-ocr-v2',
                'result' => array(
                    'text' => 'ok',
                    'warnings' => array(),
                    'pages' => array(),
                ),
            )),
        );

        $engine = new \DCB_OCR_Engine_Remote();
        $result = $engine->extract((string) $tmp, 'text/plain');

        @unlink((string) $tmp);
        unset($GLOBALS['dcb_mock_remote_response']);

        $this->assertSame('remote_contract_version_mismatch', (string) ($result['failure_reason'] ?? ''));
    }

    public function testRemoteExtractHandlesAuthFailure(): void {
        $tmp = tempnam(sys_get_temp_dir(), 'dcb_ocr_');
        file_put_contents((string) $tmp, 'sample');

        $GLOBALS['dcb_mock_remote_response'] = array(
            'response' => array('code' => 401),
            'body' => wp_json_encode(array('failure_reason' => 'auth_failed', 'message' => 'Invalid API key')),
        );

        $engine = new \DCB_OCR_Engine_Remote();
        $result = $engine->extract((string) $tmp, 'application/pdf');

        @unlink((string) $tmp);
        unset($GLOBALS['dcb_mock_remote_response']);

        $this->assertSame('remote_auth_failed', (string) ($result['failure_reason'] ?? ''));
    }

    public function testRemoteExtractHandlesTimeoutFailure(): void {
        $tmp = tempnam(sys_get_temp_dir(), 'dcb_ocr_');
        file_put_contents((string) $tmp, 'sample');

        $GLOBALS['dcb_mock_remote_response'] = new class extends \WP_Error {
            public function get_error_message(): string {
                return 'cURL error 28: Operation timed out';
            }
            public function get_error_code(): string {
                return 'timeout';
            }
        };

        $engine = new \DCB_OCR_Engine_Remote();
        $result = $engine->extract((string) $tmp, 'application/pdf');

        @unlink((string) $tmp);
        unset($GLOBALS['dcb_mock_remote_response']);

        $this->assertSame('remote_timeout', (string) ($result['failure_reason'] ?? ''));
    }

    public function testRemoteExtractEmptyTextGetsFailureReason(): void {
        $tmp = tempnam(sys_get_temp_dir(), 'dcb_ocr_');
        file_put_contents((string) $tmp, 'sample');

        $GLOBALS['dcb_mock_remote_response'] = array(
            'response' => array('code' => 200),
            'body' => wp_json_encode(array(
                'request_id' => 'remote-empty',
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

        $engine = new \DCB_OCR_Engine_Remote();
        $result = $engine->extract((string) $tmp, 'application/pdf');

        @unlink((string) $tmp);
        unset($GLOBALS['dcb_mock_remote_response']);

        $this->assertSame('empty_extraction', (string) ($result['failure_reason'] ?? ''));
        $this->assertNotEmpty((array) ($result['warnings'] ?? array()));
    }
}
