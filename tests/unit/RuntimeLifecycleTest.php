<?php

namespace DcbTests;

use PHPUnit\Framework\TestCase;

final class RuntimeLifecycleTest extends TestCase {
    protected function setUp(): void {
        parent::setUp();
        $GLOBALS['dcb_options'] = array(
            'dcb_workflow_default_status' => 'submitted',
            'dcb_workflow_enable_activity_timeline' => '1',
        );
        $GLOBALS['dcb_post_meta'] = array();
        $GLOBALS['dcb_user_meta'] = array();
        $GLOBALS['dcb_posts'] = array();
        $GLOBALS['dcb_next_post_id'] = 1000;
        $GLOBALS['dcb_current_caps'] = array(
            'dcb_manage_forms' => true,
            'dcb_review_submissions' => true,
            'dcb_manage_workflows' => true,
            'dcb_manage_settings' => true,
            'dcb_run_ocr_tools' => true,
        );
        unset($GLOBALS['dcb_submission_access_allowed'], $GLOBALS['dcb_mock_remote_response']);
        $_GET = array();
        $_POST = array();
        $_REQUEST = array();
    }

    public function testSubmissionLifecycleCreatesInReviewSubmission(): void {
        $_POST = array(
            'nonce' => 'valid',
            'form_key' => 'runtime_form',
            'fields' => wp_json_encode(array('full_name' => 'Alice Tester')),
            'signature_mode' => 'typed',
            'signer_identity' => 'Alice Tester',
        );
        $_REQUEST = $_POST;

        try {
            \DCB_Submissions::submit_ajax();
            $this->fail('Expected JSON response halt.');
        } catch (\DCB_Test_Halt $halt) {
            $this->assertSame('json_success', $halt->kind);
            $payload = is_array($halt->payload) ? $halt->payload : array();
            $submissionId = (int) ($payload['submissionId'] ?? 0);
            $this->assertGreaterThan(0, $submissionId);
            $this->assertSame('in_review', \DCB_Workflow::get_status($submissionId));
            $this->assertSame('1', (string) \get_post_meta($submissionId, '_dcb_render_finalized', true));
        }
    }

    public function testCorrectionRequestLifecycleMovesToNeedsCorrection(): void {
        $submissionId = (int) \wp_insert_post(array(
            'post_type' => 'dcb_form_submission',
            'post_status' => 'publish',
            'post_title' => 'Lifecycle Submission',
        ));
        \update_post_meta($submissionId, '_dcb_workflow_status', 'in_review');

        $_POST = array(
            'dcb_workflow_nonce' => 'valid',
            'action_replay_token' => 'toklife1',
            'submission_id' => $submissionId,
            'assignee_user_id' => 0,
            'assignee_role' => '',
            'note' => '',
            'correction_request' => 'Please correct missing date field.',
        );
        $_REQUEST = $_POST;

        try {
            \DCB_Workflow::handle_transition();
            $this->fail('Expected redirect halt.');
        } catch (\DCB_Test_Halt $halt) {
            $this->assertSame('redirect', $halt->kind);
        }

        $this->assertSame('needs_correction', \DCB_Workflow::get_status($submissionId));
        $timeline = \DCB_Workflow::get_timeline($submissionId);
        $this->assertNotEmpty($timeline);
        $events = array_map(static function ($row) {
            return is_array($row) ? (string) ($row['event'] ?? '') : '';
        }, $timeline);
        $this->assertContains('correction_request', $events);
    }

    public function testFinalizeTransitionLifecycleWritesFinalizedArtifacts(): void {
        $submissionId = (int) \wp_insert_post(array(
            'post_type' => 'dcb_form_submission',
            'post_status' => 'publish',
            'post_title' => 'Finalize Submission',
        ));
        \update_post_meta($submissionId, '_dcb_workflow_status', 'approved');

        $_POST = array(
            'dcb_workflow_nonce' => 'valid',
            'action_replay_token' => 'toklife2',
            'submission_id' => $submissionId,
            'to_status' => 'finalized',
            'assignee_user_id' => 0,
            'assignee_role' => '',
            'note' => 'Final QA pass complete.',
            'correction_request' => '',
        );
        $_REQUEST = $_POST;

        try {
            \DCB_Workflow::handle_transition();
            $this->fail('Expected redirect halt.');
        } catch (\DCB_Test_Halt $halt) {
            $this->assertSame('redirect', $halt->kind);
        }

        $this->assertSame('finalized', \DCB_Workflow::get_status($submissionId));
        $this->assertSame('1', (string) \get_post_meta($submissionId, '_dcb_render_finalized', true));
    }

    public function testPermissionAndNonceEnforcementOnKeyActions(): void {
        $submissionId = (int) \wp_insert_post(array(
            'post_type' => 'dcb_form_submission',
            'post_status' => 'publish',
            'post_title' => 'Security Submission',
        ));
        \update_post_meta($submissionId, '_dcb_workflow_status', 'submitted');

        $GLOBALS['dcb_current_caps']['dcb_manage_workflows'] = false;
        $_POST = array('submission_id' => $submissionId, 'dcb_workflow_nonce' => 'valid');
        $_POST['action_replay_token'] = 'toklife3';
        $_REQUEST = $_POST;

        try {
            \DCB_Workflow::handle_transition();
            $this->fail('Expected unauthorized halt.');
        } catch (\DCB_Test_Halt $halt) {
            $this->assertSame('die', $halt->kind);
            $this->assertSame('Unauthorized', $halt->payload);
        }

        $GLOBALS['dcb_current_caps']['dcb_manage_workflows'] = true;
        $_POST = array('submission_id' => $submissionId, 'dcb_workflow_nonce' => 'invalid', 'to_status' => 'in_review');
        $_POST['action_replay_token'] = 'toklife4';
        $_REQUEST = $_POST;

        try {
            \DCB_Workflow::handle_transition();
            $this->fail('Expected nonce failure halt.');
        } catch (\DCB_Test_Halt $halt) {
            $this->assertSame('nonce_failed', $halt->kind);
        }

        $_POST = array('nonce' => 'invalid', 'form_key' => 'runtime_form', 'fields' => '{}');
        $_REQUEST = $_POST;
        try {
            \DCB_Submissions::submit_ajax();
            $this->fail('Expected AJAX nonce failure halt.');
        } catch (\DCB_Test_Halt $halt) {
            $this->assertSame('nonce_failed', $halt->kind);
        }
    }
}
