=== Document Center Builder ===
Contributors: document-center
Tags: forms, ocr, uploader, diagnostics
Requires at least: 6.4
Tested up to: 6.7
Requires PHP: 8.0
Stable tag: 0.3.3
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Standalone reusable digital document/form plugin extracted from TherapyHub core modules.

== Description ==
Document Center Builder provides:
- Digital form definitions with sections/steps/repeaters schema
- Upgraded admin visual builder (create/duplicate/import/export tools)
- Conditional logic + hard stops + validation workflow
- OCR-assisted draft generation with provider abstraction (local/remote/auto)
- Remote OCR API mode over HTTPS with API key auth (no SSH)
- Ordered document nodes + template blocks + output template metadata
- Workflow statuses, assignees, queues, and activity timeline
- Reviewer queue screen and bulk workflow transitions
- Submission storage + signature evidence + finalization metadata
- Signature subsystem for typed/drawn flows and evidence packaging
- OCR diagnostics, capability checks, and OCR review queue
- Remote OCR contract strengthened with health/capabilities/extract expectations
- Granular plugin capability model (no blanket admin-only checks)
- Generic upload portal + routing rules
- Optional Tutor LMS integration layer (isolated module)

== Installation ==
1. Upload the plugin folder.
2. Activate the plugin.
3. Go to Document Center in wp-admin.
4. Configure settings and publish pages/shortcodes.

== Shortcodes ==
- [dcb_digital_forms_portal]
- [dcb_upload_portal]

== Changelog ==
= 0.3.3 =
* Added resilient per-module boot guards in loader to prevent dashboard-wide lockouts.
* Added emergency Document Center menu fallback when normal menu registration is interrupted.
* Added admin boot warning notice and structured module failure table for fast diagnosis.

= 0.3.2 =
* Rolled back bootstrap/loader hotfix complexity to a stable module boot sequence.
* Preserved current workflow/parity/CLI feature set while restoring reliable boot behavior.
* Kept permissive capability mapping (`read`) and non-fixed menu positioning for compatibility.

= 0.3.1 =
* Added guarded boot tracing with per-step diagnostics persisted to `dcb_boot_trace`.
* Added recovery dashboard instrumentation to surface the exact boot failure step/message.
* Added safe mode and fatal shutdown guard to reduce lockout risk after runtime fatals.
* Added fallback recovery routing for `admin.php?page=dcb-recovery-dashboard`.
* Added admin menu collision mitigations and additional dashboard access links.

= 0.3.0 =
* Added plugin capability model: `dcb_manage_forms`, `dcb_review_submissions`, `dcb_manage_workflows`, `dcb_manage_settings`, `dcb_run_ocr_tools`.
* Added reviewer queue admin screen with status filtering and workflow-focused UX.
* Added assignee selection by real users and role queue in workflow meta box.
* Added correction request UX and improved timeline presentation.
* Added bulk actions for common review state transitions.
* Implemented reusable signature subsystem (`DCB_Signatures`).
* Added explicit finalize action endpoint and row action.
* Formalized remote OCR API contract (`dcb-ocr-v1`) and added health/capabilities probing.
* Added OCR auth header setting and richer diagnostics details.
* Added repository abstraction (`DCB_Form_Repository`) and storage migration roadmap docs.
* Added PHPUnit test scaffolding and stronger CI workflow.

= 0.2.0 =
* Added migration/version runner.
* Added workflow engine scaffold with status transitions, assignees, timeline, and queue metadata.
* Added OCR provider architecture with local/remote/auto modes.
* Added remote OCR HTTPS API integration with API key auth and timeout/max-size settings.
* Added OCR review queue post type and provenance/failure metadata.
* Added PDF export adapter boundary via filter hook.
* Added optional Tutor LMS integration module and mapping settings.
* Upgraded builder UX with duplicate/import/export and schema editing surfaces.

= 0.1.0 =
* Initial extraction scaffold.
