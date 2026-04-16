<?php

namespace DcbTests;

use PHPUnit\Framework\TestCase;

final class WorkflowAdminPostTest extends TestCase {
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
        $_POST = array();
        $_REQUEST = array();
    }

    public function testHandleTransitionFinalizesFromApprovedState(): void {
        $submissionId = (int) \wp_insert_post(array(
            'post_type' => 'dcb_form_submission',
            'post_status' => 'publish',
            'post_title' => 'Finalize Transition Submission',
        ));
        \update_post_meta($submissionId, '_dcb_workflow_status', 'approved');

        $_POST = array(
            'submission_id' => $submissionId,
            'dcb_workflow_nonce' => 'valid',
            'action_replay_token' => 'tokmeta1',
            'to_status' => 'finalized',
            'assignee_user_id' => 0,
            'assignee_role' => '',
            'note' => 'Finalize now',
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

    public function testHandleTransitionEnforcesCapabilityAndNonce(): void {
        $submissionId = (int) \wp_insert_post(array(
            'post_type' => 'dcb_form_submission',
            'post_status' => 'publish',
            'post_title' => 'Security Transition Submission',
        ));

        $GLOBALS['dcb_current_caps']['dcb_manage_workflows'] = false;
        $_POST = array(
            'submission_id' => $submissionId,
            'dcb_workflow_nonce' => 'valid',
            'action_replay_token' => 'tokmeta2',
            'to_status' => 'in_review',
        );
        $_REQUEST = $_POST;

        try {
            \DCB_Workflow::handle_transition();
            $this->fail('Expected unauthorized halt.');
        } catch (\DCB_Test_Halt $halt) {
            $this->assertSame('die', $halt->kind);
            $this->assertSame('Unauthorized', $halt->payload);
        }

        $GLOBALS['dcb_current_caps']['dcb_manage_workflows'] = true;
        $_POST = array(
            'submission_id' => $submissionId,
            'dcb_workflow_nonce' => 'invalid',
            'action_replay_token' => 'tokmeta3',
            'to_status' => 'in_review',
        );
        $_REQUEST = $_POST;

        try {
            \DCB_Workflow::handle_transition();
            $this->fail('Expected nonce failure halt.');
        } catch (\DCB_Test_Halt $halt) {
            $this->assertSame('nonce_failed', $halt->kind);
        }
    }
}
