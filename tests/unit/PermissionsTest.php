<?php

namespace DcbTests;

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/includes/class-permissions.php';

final class PermissionsTest extends TestCase {
    public function testCapabilityListContainsExpectedCaps(): void {
        $caps = \DCB_Permissions::all_caps();

        $this->assertContains('dcb_manage_forms', $caps);
        $this->assertContains('dcb_review_submissions', $caps);
        $this->assertContains('dcb_manage_workflows', $caps);
        $this->assertContains('dcb_manage_settings', $caps);
        $this->assertContains('dcb_run_ocr_tools', $caps);
    }
}
