<?php

namespace DcbTests;

use PHPUnit\Framework\TestCase;

final class QueueActionsTest extends TestCase {
    protected function setUp(): void {
        parent::setUp();
        $GLOBALS['dcb_options'] = array('dcb_workflow_default_status' => 'submitted', 'dcb_workflow_enable_activity_timeline' => '1');
        $GLOBALS['dcb_post_meta'] = array();
        $GLOBALS['dcb_posts'] = array();
        $GLOBALS['dcb_next_post_id'] = 1000;
        $GLOBALS['dcb_current_caps'] = array(
            'dcb_manage_forms' => true,
            'dcb_review_submissions' => true,
            'dcb_manage_workflows' => true,
            'dcb_manage_settings' => true,
            'dcb_run_ocr_tools' => true,
        );
        unset($GLOBALS['dcb_missing_user_ids']);
        $_POST = array();
        $_REQUEST = array();
    }

    public function testQuickAssignQueueActionUpdatesAssignee(): void {
        $submissionId = (int) \wp_insert_post(array(
            'post_type' => 'dcb_form_submission',
            'post_status' => 'publish',
            'post_title' => 'Queue Assign Submission',
        ));

        $_POST = array(
            'dcb_workflow_queue_nonce' => 'valid',
            'queue_action' => 'quick_assign',
            'submission_id' => $submissionId,
            'assignee_user_id' => 12,
        );
        $_REQUEST = $_POST;

        try {
            \DCB_Workflow::handle_queue_action();
            $this->fail('Expected redirect halt.');
        } catch (\DCB_Test_Halt $halt) {
            $this->assertSame('redirect', $halt->kind);
        }

        $this->assertSame(12, (int) \get_post_meta($submissionId, '_dcb_workflow_assignee_user_id', true));
    }

    public function testQuickTransitionSkipsInvalidTransitionAndReportsNotice(): void {
        $submissionId = (int) \wp_insert_post(array(
            'post_type' => 'dcb_form_submission',
            'post_status' => 'publish',
            'post_title' => 'Queue Transition Submission',
        ));
        \update_post_meta($submissionId, '_dcb_workflow_status', 'submitted');

        $_POST = array(
            'dcb_workflow_queue_nonce' => 'valid',
            'queue_action' => 'quick_transition',
            'submission_id' => $submissionId,
            'to_status' => 'finalized',
            'from_status' => 'submitted',
        );
        $_REQUEST = $_POST;

        try {
            \DCB_Workflow::handle_queue_action();
            $this->fail('Expected redirect halt.');
        } catch (\DCB_Test_Halt $halt) {
            $this->assertSame('redirect', $halt->kind);
            $notice = $this->extractNoticeFromRedirect((string) $halt->payload);
            $this->assertStringContainsString('invalid transition', strtolower($notice));
        }

        $this->assertSame('submitted', \DCB_Workflow::get_status($submissionId));
    }

    public function testBulkTransitionFinalizesApprovedSubmission(): void {
        $submissionId = (int) \wp_insert_post(array(
            'post_type' => 'dcb_form_submission',
            'post_status' => 'publish',
            'post_title' => 'Bulk Transition Submission',
        ));
        \update_post_meta($submissionId, '_dcb_workflow_status', 'approved');

        $_POST = array(
            'dcb_workflow_queue_nonce' => 'valid',
            'queue_action' => 'bulk_transition',
            'submission_ids' => array($submissionId),
            'bulk_to_status' => 'finalized',
        );
        $_REQUEST = $_POST;

        try {
            \DCB_Workflow::handle_queue_action();
            $this->fail('Expected redirect halt.');
        } catch (\DCB_Test_Halt $halt) {
            $this->assertSame('redirect', $halt->kind);
        }

        $this->assertSame('finalized', \DCB_Workflow::get_status($submissionId));
        $this->assertSame('1', (string) \get_post_meta($submissionId, '_dcb_render_finalized', true));
    }

    public function testQueueActionRejectsInvalidAssignee(): void {
        $submissionId = (int) \wp_insert_post(array(
            'post_type' => 'dcb_form_submission',
            'post_status' => 'publish',
            'post_title' => 'Invalid Assignee Submission',
        ));

        $GLOBALS['dcb_missing_user_ids'] = array(99);

        $_POST = array(
            'dcb_workflow_queue_nonce' => 'valid',
            'queue_action' => 'quick_assign',
            'submission_id' => $submissionId,
            'assignee_user_id' => 99,
        );
        $_REQUEST = $_POST;

        try {
            \DCB_Workflow::handle_queue_action();
            $this->fail('Expected redirect halt.');
        } catch (\DCB_Test_Halt $halt) {
            $this->assertSame('redirect', $halt->kind);
            $notice = $this->extractNoticeFromRedirect((string) $halt->payload);
            $this->assertStringContainsString('invalid assignee', strtolower($notice));
        }

        $this->assertSame(0, (int) \get_post_meta($submissionId, '_dcb_workflow_assignee_user_id', true));
    }

    public function testQuickAssignRejectsStaleStateToken(): void {
        $submissionId = (int) \wp_insert_post(array(
            'post_type' => 'dcb_form_submission',
            'post_status' => 'publish',
            'post_title' => 'Stale Assign Submission',
        ));

        $validToken = \DCB_Workflow::queue_state_token($submissionId);
        \update_post_meta($submissionId, '_dcb_workflow_assignee_user_id', 11);

        $_POST = array(
            'dcb_workflow_queue_nonce' => 'valid',
            'queue_action' => 'quick_assign',
            'submission_id' => $submissionId,
            'assignee_user_id' => 12,
            'expected_assignee_user_id' => 0,
            'state_token' => $validToken,
        );
        $_REQUEST = $_POST;

        try {
            \DCB_Workflow::handle_queue_action();
            $this->fail('Expected redirect halt.');
        } catch (\DCB_Test_Halt $halt) {
            $this->assertSame('redirect', $halt->kind);
            $notice = $this->extractNoticeFromRedirect((string) $halt->payload);
            $this->assertStringContainsString('state changed', strtolower($notice));
        }

        $this->assertSame(11, (int) \get_post_meta($submissionId, '_dcb_workflow_assignee_user_id', true));
    }

    public function testQuickTransitionRejectsStaleStateToken(): void {
        $submissionId = (int) \wp_insert_post(array(
            'post_type' => 'dcb_form_submission',
            'post_status' => 'publish',
            'post_title' => 'Stale Transition Submission',
        ));
        \update_post_meta($submissionId, '_dcb_workflow_status', 'submitted');

        $validToken = \DCB_Workflow::queue_state_token($submissionId);
        \update_post_meta($submissionId, '_dcb_workflow_status', 'in_review');

        $_POST = array(
            'dcb_workflow_queue_nonce' => 'valid',
            'queue_action' => 'quick_transition',
            'submission_id' => $submissionId,
            'to_status' => 'approved',
            'from_status' => 'submitted',
            'state_token' => $validToken,
        );
        $_REQUEST = $_POST;

        try {
            \DCB_Workflow::handle_queue_action();
            $this->fail('Expected redirect halt.');
        } catch (\DCB_Test_Halt $halt) {
            $this->assertSame('redirect', $halt->kind);
            $notice = $this->extractNoticeFromRedirect((string) $halt->payload);
            $this->assertStringContainsString('state changed', strtolower($notice));
        }

        $this->assertSame('in_review', \DCB_Workflow::get_status($submissionId));
    }

    public function testQuickAssignProtectsFinalizedSubmission(): void {
        $submissionId = (int) \wp_insert_post(array(
            'post_type' => 'dcb_form_submission',
            'post_status' => 'publish',
            'post_title' => 'Finalized Protected Submission',
        ));
        \update_post_meta($submissionId, '_dcb_workflow_status', 'finalized');

        $_POST = array(
            'dcb_workflow_queue_nonce' => 'valid',
            'queue_action' => 'quick_assign',
            'submission_id' => $submissionId,
            'assignee_user_id' => 12,
            'expected_assignee_user_id' => 0,
            'state_token' => \DCB_Workflow::queue_state_token($submissionId),
        );
        $_REQUEST = $_POST;

        try {
            \DCB_Workflow::handle_queue_action();
            $this->fail('Expected redirect halt.');
        } catch (\DCB_Test_Halt $halt) {
            $this->assertSame('redirect', $halt->kind);
            $notice = $this->extractNoticeFromRedirect((string) $halt->payload);
            $this->assertStringContainsString('finalized submissions are protected', strtolower($notice));
        }

        $this->assertSame(0, (int) \get_post_meta($submissionId, '_dcb_workflow_assignee_user_id', true));
    }

    public function testBulkAssignSkipsRowsWithStaleTokens(): void {
        $firstId = (int) \wp_insert_post(array(
            'post_type' => 'dcb_form_submission',
            'post_status' => 'publish',
            'post_title' => 'Bulk Token First',
        ));
        $secondId = (int) \wp_insert_post(array(
            'post_type' => 'dcb_form_submission',
            'post_status' => 'publish',
            'post_title' => 'Bulk Token Second',
        ));

        $firstToken = \DCB_Workflow::queue_state_token($firstId);
        $secondToken = \DCB_Workflow::queue_state_token($secondId);
        \update_post_meta($secondId, '_dcb_workflow_assignee_user_id', 11);

        $_POST = array(
            'dcb_workflow_queue_nonce' => 'valid',
            'queue_action' => 'bulk_assign',
            'submission_ids' => array($firstId, $secondId),
            'bulk_assignee_user_id' => 12,
            'state_tokens' => array(
                $firstId => $firstToken,
                $secondId => $secondToken,
            ),
        );
        $_REQUEST = $_POST;

        try {
            \DCB_Workflow::handle_queue_action();
            $this->fail('Expected redirect halt.');
        } catch (\DCB_Test_Halt $halt) {
            $this->assertSame('redirect', $halt->kind);
            $notice = $this->extractNoticeFromRedirect((string) $halt->payload);
            $this->assertStringContainsString('state changed', strtolower($notice));
        }

        $this->assertSame(12, (int) \get_post_meta($firstId, '_dcb_workflow_assignee_user_id', true));
        $this->assertSame(11, (int) \get_post_meta($secondId, '_dcb_workflow_assignee_user_id', true));
    }

    public function testQueueActionEnforcesCapabilityAndNonce(): void {
        $submissionId = (int) \wp_insert_post(array(
            'post_type' => 'dcb_form_submission',
            'post_status' => 'publish',
            'post_title' => 'Security Queue Submission',
        ));

        $GLOBALS['dcb_current_caps']['dcb_manage_workflows'] = false;
        $_POST = array('dcb_workflow_queue_nonce' => 'valid', 'queue_action' => 'quick_assign', 'submission_id' => $submissionId, 'assignee_user_id' => 11);
        $_REQUEST = $_POST;

        try {
            \DCB_Workflow::handle_queue_action();
            $this->fail('Expected unauthorized halt.');
        } catch (\DCB_Test_Halt $halt) {
            $this->assertSame('die', $halt->kind);
            $this->assertSame('Unauthorized', $halt->payload);
        }

        $GLOBALS['dcb_current_caps']['dcb_manage_workflows'] = true;
        $_POST = array('dcb_workflow_queue_nonce' => 'invalid', 'queue_action' => 'quick_assign', 'submission_id' => $submissionId, 'assignee_user_id' => 11);
        $_REQUEST = $_POST;

        try {
            \DCB_Workflow::handle_queue_action();
            $this->fail('Expected nonce failure halt.');
        } catch (\DCB_Test_Halt $halt) {
            $this->assertSame('nonce_failed', $halt->kind);
        }
    }

    private function extractNoticeFromRedirect(string $url): string {
        $parts = parse_url($url);
        if (!is_array($parts) || empty($parts['query'])) {
            return '';
        }

        $query = array();
        parse_str((string) $parts['query'], $query);
        return isset($query['queue_notice']) ? (string) $query['queue_notice'] : '';
    }
}
