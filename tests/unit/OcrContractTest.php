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
}
