<?php

namespace DcbTests;

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/includes/class-settings.php';
require_once dirname(__DIR__, 2) . '/includes/class-diagnostics.php';

final class OperationsUsabilityTest extends TestCase {
    protected function setUp(): void {
        parent::setUp();
        $GLOBALS['dcb_options'] = array(
            'dcb_workflow_default_status' => 'submitted',
            'dcb_workflow_enable_activity_timeline' => '1',
        );
        $GLOBALS['dcb_post_meta'] = array();
        $GLOBALS['dcb_posts'] = array();
        $GLOBALS['dcb_next_post_id'] = 1000;
        $GLOBALS['dcb_registered_post_types'] = array();
        $GLOBALS['dcb_sent_mails'] = array();
        $GLOBALS['dcb_current_caps'] = array(
            'dcb_manage_forms' => true,
            'dcb_review_submissions' => true,
            'dcb_manage_workflows' => true,
            'dcb_manage_settings' => true,
            'dcb_run_ocr_tools' => true,
        );
        $_POST = array();
        $_REQUEST = array();

        \DCB_Form_Repository::register_post_type();
    }

    public function testManualParityTriggerActionProducesSummaryNotice(): void {
        \update_option('dcb_forms_storage_mode', 'cpt');
        \update_option('dcb_forms_custom', array(
            'intake' => array('label' => 'Intake', 'version' => 1, 'fields' => array()),
        ));
        \DCB_Form_Repository::save_all_raw(array(
            'intake' => array('label' => 'Intake Updated', 'version' => 2, 'fields' => array()),
        ));

        $_POST = array(
            'dcb_parity_check_nonce' => 'valid',
        );
        $_REQUEST = $_POST;

        try {
            \DCB_Diagnostics::run_parity_check_now();
            $this->fail('Expected redirect halt.');
        } catch (\DCB_Test_Halt $halt) {
            $this->assertSame('redirect', $halt->kind);
            $url = (string) $halt->payload;
            $this->assertStringContainsString('migration_notice=', $url);
            $this->assertStringContainsString('Parity+check', $url);
        }
    }

    public function testAuditDrilldownDataIsAvailableForRecordedAction(): void {
        $submissionId = (int) \wp_insert_post(array(
            'post_type' => 'dcb_form_submission',
            'post_status' => 'publish',
            'post_title' => 'Audit Drilldown Submission',
        ));

        $_POST = array(
            'dcb_workflow_queue_nonce' => 'valid',
            'action_replay_token' => 'tok_audit_1',
            'queue_action' => 'quick_assign',
            'submission_id' => $submissionId,
            'assignee_user_id' => 11,
        );
        $_REQUEST = $_POST;

        try {
            \DCB_Workflow::handle_queue_action();
            $this->fail('Expected redirect halt.');
        } catch (\DCB_Test_Halt $halt) {
            $this->assertSame('redirect', $halt->kind);
        }

        $rows = \DCB_Workflow::action_audit_rows();
        $this->assertNotEmpty($rows);
        $latest = (array) end($rows);
        $this->assertArrayHasKey('id', $latest);

        $entry = \DCB_Workflow::action_audit_entry((int) ($latest['id'] ?? 0));
        $this->assertIsArray($entry);
        $this->assertArrayHasKey('details', (array) $entry);
        $this->assertArrayHasKey('submission_ids', (array) $entry);
    }

    public function testDriftSeverityClassificationThresholds(): void {
        $this->assertSame('info', \DCB_Form_Repository::classify_drift_severity(array(
            'missing_count' => 0,
            'extra_count' => 0,
            'checksum_mismatch_count' => 0,
            'verified_ratio' => 100,
        )));

        $this->assertSame('warn', \DCB_Form_Repository::classify_drift_severity(array(
            'missing_count' => 1,
            'extra_count' => 0,
            'checksum_mismatch_count' => 1,
            'verified_ratio' => 98,
        )));

        $this->assertSame('critical', \DCB_Form_Repository::classify_drift_severity(array(
            'missing_count' => 6,
            'extra_count' => 0,
            'checksum_mismatch_count' => 0,
            'verified_ratio' => 70,
        )));
    }

    public function testCriticalDriftTriggersAlertEmailWhenEnabled(): void {
        \update_option('dcb_forms_storage_mode', 'cpt');
        \update_option('dcb_forms_parity_alert_enabled', '1');
        \update_option('dcb_forms_parity_alert_email', 'ops@example.com');

        $source = array();
        for ($i = 1; $i <= 6; $i++) {
            $source['form_' . $i] = array('label' => 'Form ' . $i, 'version' => 1, 'fields' => array());
        }
        \update_option('dcb_forms_custom', $source);
        \DCB_Form_Repository::save_all_raw(array());

        $result = \DCB_Form_Repository::run_parity_check('manual_test');
        $this->assertSame('critical', (string) ($result['severity'] ?? ''));

        $mails = (array) ($GLOBALS['dcb_sent_mails'] ?? array());
        $this->assertNotEmpty($mails);
        $first = (array) $mails[0];
        $this->assertSame('ops@example.com', (string) ($first['to'] ?? ''));
        $this->assertStringContainsString('Critical parity drift', (string) ($first['subject'] ?? ''));
    }
}
