=== Document Center Builder ===
Contributors: document-center
Tags: forms, ocr, uploader, diagnostics
Requires at least: 6.4
Tested up to: 6.7
Requires PHP: 8.0
Stable tag: 0.3.8
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
- Optional Tutor LMS integration layer with structured form-to-course/lesson/quiz mapping
- OCR diagnostics, capability checks, and OCR review queue
- Generic upload portal + routing rules
- Setup & operations admin surface with first-run readiness checks
- Generic sample template pack and validated forms import/export flow

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
= 0.3.8 =
* Added Setup & Operations admin screen with first-run readiness checks (capabilities, OCR mode/config, upload writability, permalink/admin readiness).
* Added actionable diagnostics links and compact permissions matrix reference without changing capability grants.
* Added generic sample template pack (intake, consent/attestation, simple packet) for demo/testing startup.
* Added hardened import/export forms flow with explicit payload contract, validation, merge/replace import modes, and JSON export download action.
* Added conservative uninstall policy default with explicit opt-in data purge setting.
* Added operations helper layer (`includes/helpers-ops.php`) and smoke tests for readiness, import/export validation, sample pack, uninstall behavior, and permissions matrix.
* Added release hygiene cleanup for root-level release-noise artifact.

= 0.3.7 =
* Added dedicated Intake Trace Timeline admin screen keyed by `trace_id` for single-chain visibility from upload artifact through OCR review and submission workflow status.
* Added reusable timeline helper payload layer (`dcb_intake_trace_build_payload`, linked-id resolver, state summary, event builder, admin URL helper).
* Added timeline row action links from upload artifacts, OCR review queue, and submissions admin lists.
* Added new extension hook `dcb_intake_trace_timeline_payload` for timeline payload customization.
* Added smoke tests for timeline payload helpers, capability-gated timeline access, and trace row-action link generation.

= 0.3.6 =
* Added normalized intake channel model (`direct_upload`, `phone_photo`, `scanned_pdf`, `email_import`, `digital_only`) with channel adapter handling in upload + submission flows.
* Added stronger original-capture traceability metadata chain across upload artifact, OCR review item, and submission (`trace_id`, source/capture type, linked review/submission IDs, intake state).
* Added intake state synchronization across OCR review and workflow transitions for clearer correction/review/finalized lifecycle visibility.
* Added upload artifact admin surfacing (menu + compact columns) and expanded OCR review queue traceability column.
* Added compact intake traceability panel on submission admin detail view.
* Hardened upload portal resource center with packet-aware status table for required/optional forms, missing required, correction, and approved/finalized visibility.
* Added intake channel selector and enhanced form-link adapters from resource center rows to clean fillable form flows.
* Added reusable intake helper layer (`includes/helpers-intake.php`) and documented new hooks (`dcb_intake_source_channel`, `dcb_resource_center_payload`).

= 0.3.5 =
* Added OCR review queue usability pass with source/capture-risk columns and admin list filters (status, source type, capture risk, unresolved risk).
* Added reviewer-facing capture diagnostics panel in OCR review detail with warning/recommendation and normalization proxy summaries.
* Persisted scalar capture-risk metadata (`source_type`, warning count, risk bucket, unresolved flag) for reliable queue filtering and triage.
* Linked upload logs to OCR review items for clearer intake-to-review traceability.
* Polished intake upload portal UX with resource center guidance, richer confidence/capture result columns, and batch-level capture warning summaries.
* Added smoke tests for capture diagnostics metadata shaping and OCR review queue filter query behavior.

= 0.3.4 =
* Added local replay runner utility (`tests/ocr_local_replay_runner.php`) for operational fixture replay with text fallback and optional local binary support.
* Added before/after normalization diagnostics with text-length/confidence/warning deltas and per-stage attempt/application counts.
* Added unresolved capture-risk reporting and practical local triage summaries with optional JSON artifact export.
* Added reusable local replay diagnostics helper (`dcb_ocr_local_replay_before_after_diagnostics`) and hook (`dcb_ocr_local_replay_diagnostics`).
* Expanded real-world benchmark reporting with average confidence/warning/text-length deltas and unresolved capture-risk case count.
* Improved capture-quality risk heuristics and recommendations (dark/low-contrast/rotation-skew/crop-border/rasterization coverage warnings).
* Persisted source-capture metadata on OCR review queue items for later admin triage surfaces.

= 0.3.3 =
* Added actual binary replay path in real-world OCR benchmark smoke tests when local PDF/photo binaries are available, with clean fallback to sample text fixtures.
* Added per-case normalization diagnostics output for orientation/deskew/crop/contrast/max-dimension stage attempt/application and capture warning summaries.
* Added practical capture-quality heuristics for low-resolution, low-contrast, dark-image, rotation/skew-risk, and crop/border-risk detection.
* Added normalization effectiveness proxies (`normalization_improvement_proxy`, stage application counts, rasterization coverage, average capture warning count).
* Added capture-quality recommendations metadata in OCR normalization output for future admin/review UI surfacing.
* Improved real-input candidate scoring using normalization and capture-warning metadata while preserving builder compatibility.
* Added draft-level `source_capture_meta` and richer OCR review metadata for replay diagnostics and digital-twin fidelity continuity.
* Extended real-world fixture manifest with optional `required_local_binary` flag.

= 0.3.2 =
* Added OCR input normalization pipeline stages for orientation correction, deskew, crop/border cleanup, contrast cleanup, PDF raster-page normalization, and max-dimension normalization.
* Added lightweight capture-quality metadata and warnings for low-resolution/photo-risk inputs.
* Preserved secure engine-decoupled OCR architecture and capability hardening while enriching extraction metadata (`input_source_type`, `input_normalization`).
* Improved OCR document model fidelity with positional line metadata, layout regions, and signature/date pairing hints.
* Improved paper-to-builder draft quality for checkbox grouping, yes/no detection, and signature/date context.
* Added lightweight backward-compatible `digital_twin_hints` to preserve render/layout similarity to source forms without a canvas designer.
* Added real-world OCR fixture interface (`fixtures/ocr-realworld`) with optional local binary paths plus deterministic lightweight CI samples.
* Added real-world OCR benchmark smoke test with precision/recall/type/section/repeater/false-positive/review-cleanup-burden metrics.

= 0.3.1 =
* Added stronger OCR intermediate document model (pages, blocks, lines, anchors, section candidates, table/repeater hints, signature/date candidates) before draft generation.
* Improved OCR label-to-input understanding for colon labels, trailing blanks/underscores, checkbox markers, yes/no pairs, and signature/date cues.
* Expanded field type inference signals (text, textarea, select, checkbox, yes/no, date, time, phone, email, number, DOB, signature, initials) with safe builder-compatible mapping.
* Added structural OCR heuristics for section detection, grouped field context, and table/repeater hints to improve generated draft quality.
* Added stronger candidate scoring/ranking metadata and confidence reasoning to reduce instructional/heading false positives.
* Added deterministic correction-feedback reuse (`dcb_ocr_correction_rules`) for label aliases and type overrides based on human review corrections.
* Improved OCR-to-builder draft generation with better ordering, section assignment, repeater hints, template blocks, and default document nodes.
* Enriched OCR extraction payloads with quality model metadata/candidates while preserving backward compatibility.
* Added OCR benchmark fixtures covering clean/noisy/multi-page/checkbox-heavy/signature-heavy/table forms.
* Added OCR quality benchmark smoke test with precision/recall/type/section/repeater metrics and CI coverage.

= 0.3.0 =
* Productized Tutor LMS integration as an optional, isolated module with strict guardrails when Tutor is not installed.
* Replaced Tutor mapping JSON-only management with a structured settings UI for form-to-course/lesson/quiz mapping.
* Added per-mapping toggles for prerequisite gating, post-completion assignment attempts, and relationship metadata recording.
* Improved access gating diagnostics with mapped requirement context, detected completion state, and denial reason tracking.
* Strengthened completion relation metadata (`mapping_key`, `form_key`, `user_id`, `course_id`, `lesson_id`, `quiz_id`, `recorded_at`, `relation_type`, `trigger_source`) while preserving `_dcb_tutor_relation` compatibility.
* Added optional training-assignment adapter boundary (`dcb_tutor_training_assignment_adapter`) with guarded native fallback.
* Added Tutor lifecycle hooks for mapping resolution, gating outcomes, relation recording, and assignment attempt/completion/failure.
* Added Tutor integration admin visibility section on submission detail views.
* Added focused Tutor integration smoke test and CI coverage.

= 0.2.9 =
* Implemented signature service layer with normalized signature evidence persistence/retrieval and backward-compatible legacy evidence fallback.
* Expanded signature evidence model to include signer display name/user id, signature timestamps, mode, signature field/source context, and optional private request metadata.
* Added stronger finalized document experience with locked final rendering path and dedicated finalization evidence section.
* Improved print/final record output with clearer status badges and finalized record presentation.
* Added output template registry/selection architecture with default + compact templates and template selection/mapping filters.
* Strengthened normalized export metadata with output contract version, output version/template key, finalized context, and approval/workflow context.
* Hardened PDF adapter contract handling with explicit default contract + validator helpers while preserving adapter boundary.
* Persisted richer finalization metadata (`finalized_at`, `finalized_by`, `finalized_by_name`, `output_version`, `output_template_key`, output contract version).
* Added focused output/signatures smoke test and CI coverage.
* Removed release-noise artifact `test.txt` from plugin root.

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
