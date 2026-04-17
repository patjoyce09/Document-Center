=== Document Center Builder ===
Contributors: document-center
Tags: forms, ocr, uploader, diagnostics
Requires at least: 6.4
Tested up to: 6.7
Requires PHP: 8.0
Stable tag: 0.2.8
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
- Submission storage + signature evidence + finalization metadata
- OCR diagnostics, capability checks, and OCR review queue
- Generic upload portal + routing rules
- Optional Tutor LMS integration layer (isolated module)

Capability model:
- dcb_manage_forms
- dcb_review_submissions
- dcb_manage_workflows
- dcb_manage_settings
- dcb_run_ocr_tools

On activation, capabilities are synced to roles:
- administrator: all DCB capabilities
- editor: submissions/workflow review subset by default

Role capability grants are filterable via dcb_permissions_role_caps.

== Installation ==
1. Upload the plugin folder.
2. Activate the plugin.
3. Go to Document Center in wp-admin.
4. Configure settings and publish pages/shortcodes.

== Shortcodes ==
- [dcb_digital_forms_portal]
- [dcb_upload_portal]

== Changelog ==
= 0.2.8 =
* Added OCR review item operational status model: `pending_review`, `corrected`, `approved`, `rejected`, `reprocessed`.
* Added OCR review queue admin operations: approve, corrected, reject, reprocess OCR, and promote reviewed draft payload.
* Added manual correction loop with corrected text summary + corrected candidate fields while preserving original machine extraction snapshot.
* Added OCR review revision trail metadata for auditability of status/correction/reprocess/promote actions.
* Added normalized OCR failure taxonomy and recommendations (`empty_extraction`, `low_confidence`, `remote_config_invalid`, `remote_api_key_missing`, `remote_request_failed`, `remote_http_error`, `max_file_size_exceeded`, `unsupported_mime`, `local_binary_missing`, `rasterization_failed`, `extraction_timeout`, `parse_failed`).
* Hardened remote OCR provider validation and extraction handling (HTTPS validation, API key checks, MIME/size guards, timeout/error mapping, defensive JSON response normalization).
* Expanded OCR diagnostics with remote config validation, selected engine visibility, queue status counts, and failure reason summary.
* Added OCR operations smoke test coverage and CI wiring.

= 0.2.7 =
* Added generic data-driven workflow routing rules with matching on form key, template id, status, field conditions, document type, and packet key.
* Added assignment target model supporting user, role, and queue assignments with backward-compatible `_dcb_workflow_assignee_user_id` behavior.
* Strengthened workflow status transition handling with explicit server-side validation and transition event mapping.
* Added structured review notes and correction request flow with actor/time attribution and timeline events.
* Added packet/bundle tracking model with required/received/approved/missing item state and completeness checks.
* Added role/capability-gated workflow queue views (my assigned, awaiting review, needs correction, finalized) plus configurable queue groups.
* Added workflow routing configuration admin page for routing rules, queue groups, and packet definitions.
* Added extensible workflow notification trigger hooks for status/assignment/routing/note events.
* Added focused workflow routing smoke test coverage to CI.

= 0.2.6 =
* Builder maturity pass: replaced JSON-first schema editing with structured editors for sections, steps/pages, repeaters, hard stops, template blocks, and document nodes.
* Added field-level condition builder with operator selection, target-field references, and inline validation feedback.
* Added structured hard-stop rule builder with label, severity/type metadata, and multi-condition rule editing.
* Added field template quick-insert controls for common inputs, attestation/signature packs, and OCR-friendly identity packs.
* Added dedicated builder validation panel surfacing duplicate keys, missing labels, broken references, malformed conditions, invalid hard-stop rules, and broken document nodes.
* Added richer builder preview with steps/sections grouping and document-node output ordering.
* Added OCR-assisted draft review workbench: extract OCR seed draft, review/edit candidate fields with confidence details, accept/reject rows, and apply accepted draft into builder before save.
* Preserved schema compatibility and import/export behavior while keeping advanced raw JSON fallback in the Advanced area.
* Kept OCR extraction service-decoupled and capability-gated (`dcb_run_ocr_tools`) in builder admin actions.

= 0.2.5 =
* Replaced placeholder read-based permissions with dedicated DCB capabilities and strict permission checks.
* Hardened admin pages, workflow/admin_post routes, OCR diagnostics/AJAX, renderer export actions, and submission admin surfaces.
* Added explicit custom post type capability maps for submissions, upload logs, and OCR review queue.
* Synced role grants on activation/init with filterable role-cap mapping for enterprise customization.

= 0.2.4 =
* Fixed recursive OCR diagnostics/provider capability calls that could cause blank Builder and OCR Diagnostics admin pages.

= 0.2.3 =
* Improved front-end shortcode detection using WordPress shortcode parsing so legacy and canonical portal tags load assets reliably even with attributes/format variations.

= 0.2.2 =
* Added backward-compatible shortcode aliases for legacy portal pages.
* Added legacy shortcode detection in front-end asset loading so uploader/forms render correctly.

= 0.2.1 =
* Fixed builder blank-page behavior by guarding form/diagnostics runtime calls and rendering fallback warnings.

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
