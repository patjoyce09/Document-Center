# Document Center Extension Hooks

## Filters

### `dcb_submission_access_allowed`
Controls access to a form before rendering/submitting.

**Arguments**
1. `$allowed` (`bool`)
2. `$form_key` (`string`)
3. `$user_id` (`int`)
4. `$context` (`array`)

### `dcb_pdf_export_adapter`
Adapter boundary for PDF generation.

Return array shape:
- `ok` (`bool`)
- `mime` (`string`)
- `filename` (`string`)
- `binary` (`string`)
- `message` (`string`)
- `contract_version` (`string`, recommended: `2.0`)

### `dcb_workflow_statuses`
Override/extend workflow statuses.

**Arguments**
1. `$statuses` (`array`)

### `dcb_workflow_status_transitions`
Override/extend allowed status transitions.

**Arguments**
1. `$transitions` (`array`)

### `dcb_workflow_notification_payload`
Mutate payload before workflow notification triggers fire.

**Arguments**
1. `$payload` (`array`)
2. `$submission_id` (`int`)
3. `$event` (`string`)
4. `$context` (`array`)

### `dcb_ocr_review_item_created`
Fires when an OCR review queue item is created from extraction results.

**Arguments**
1. `$review_id` (`int`)
2. `$result` (`array`)

### `dcb_output_templates`
Extend output template registry for finalized/print rendering.

**Arguments**
1. `$templates` (`array`)

### `dcb_output_template_key`
Override selected output template key for a submission/render context.

**Arguments**
1. `$template_key` (`string`)
2. `$submission_id` (`int`)
3. `$normalized_submission` (`array`)
4. `$view` (`string`)

### `dcb_output_template_mapping`
Adjust template mapping block used in normalized export metadata.

**Arguments**
1. `$mapping` (`array`)
2. `$template_key` (`string`)
3. `$signature` (`array`)

### `dcb_signature_normalized_evidence`
Mutate normalized signature evidence before persistence/use.

**Arguments**
1. `$normalized` (`array`)
2. `$payload` (`array`)

### `dcb_output_include_private_signature_meta`
Control whether private request metadata (IP/user-agent) appears in rendered signature evidence.

**Arguments**
1. `$include` (`bool`)
2. `$submission_id` (`int`)
3. `$view` (`string`)

### `dcb_tutor_training_assignment_adapter`
Optional Tutor training-assignment adapter boundary used after submission completion.

Return `null` to allow native guarded fallback, or array shape:
- `attempted` (`bool`)
- `success` (`bool`)
- `message` (`string`)
- `method` (`string`)

**Arguments**
1. `$adapter_result` (`array|null`)
2. `$payload` (`array`)
3. `$mapping` (`array`)

### `dcb_ocr_document_model`
Filter normalized OCR intermediate document model before candidate extraction/draft generation.

**Arguments**
1. `$model` (`array`)
2. `$pages` (`array`)

### `dcb_ocr_candidate_enriched`
Filter enriched OCR field candidate after scoring/type inference and before dedupe.

**Arguments**
1. `$candidate` (`array`)
2. `$line_row` (`array`)
3. `$document_model` (`array`)
4. `$page_meta` (`array`)

### `dcb_ocr_input_normalization_page`
Filter per-page normalization metadata used before OCR fallback.

**Arguments**
1. `$page_meta` (`array`)
2. `$inspection` (`array`)
3. `$normalized_result` (`array`)
4. `$source_path` (`string`)

### `dcb_ocr_input_normalization_result`
Filter full OCR input normalization result payload.

**Arguments**
1. `$normalization` (`array`)
2. `$inspection` (`array`)
3. `$file_path` (`string`)

### `dcb_ocr_input_normalization_stage_counts`
Filter accumulated normalization stage attempt/application counts per replay page.

**Arguments**
1. `$stage_application_counts` (`array`)
2. `$stage_attempt_counts` (`array`)
3. `$page_meta` (`array`)
4. `$inspection` (`array`)

### `dcb_ocr_digital_twin_hints`
Filter lightweight digital twin render/layout hints produced for OCR-generated drafts.

**Arguments**
1. `$hints` (`array`)
2. `$document_model` (`array`)
3. `$fields` (`array`)

### `dcb_ocr_local_replay_diagnostics`
Filter local replay before/after diagnostics payload used by replay runner and benchmark triage flows.

**Arguments**
1. `$diagnostics` (`array`)
2. `$file_path` (`string`)
3. `$mime` (`string`)
4. `$inspection` (`array`)

### `dcb_intake_source_channel`
Filter normalized intake source channel for intake channel adapters.

**Arguments**
1. `$normalized_channel` (`string`)
2. `$raw_channel` (`string`)

### `dcb_resource_center_payload`
Filter user-facing resource center payload (rows + summary) before upload portal render.

**Arguments**
1. `$payload` (`array`)
2. `$user_id` (`int`)
3. `$forms` (`array`)

### `dcb_intake_trace_timeline_payload`
Filter assembled intake trace timeline payload before admin timeline rendering.

**Arguments**
1. `$timeline_payload` (`array`)
2. `$raw_payload` (`array`)

### `dcb_sample_template_pack`
Filter bundled generic sample templates before load into builder forms.

**Arguments**
1. `$pack` (`array`)

### `dcb_forms_export_payload`
Filter structured forms export payload before JSON download.

**Arguments**
1. `$payload` (`array`)
2. `$forms` (`array`)

### `dcb_license_update_boundary`
Filter isolated placeholder payload for future license/update boundary status.

**Arguments**
1. `$payload` (`array`)

## Actions

### `dcb_submission_completed`
Fires after submission is finalized/notification phase.

**Arguments**
1. `$submission_id` (`int`)
2. `$form_key` (`string`)
3. `$user_id` (`int`)

### `dcb_workflow_status_transition`
Fires on validated status transitions.

**Arguments**
1. `$submission_id` (`int`)
2. `$from_status` (`string`)
3. `$to_status` (`string`)
4. `$note` (`string`)

### `dcb_workflow_event`
Fires for each timeline event append.

**Arguments**
1. `$submission_id` (`int`)
2. `$row` (`array`)

### `dcb_workflow_event_{$event}`
Event-specific timeline hook (dynamic suffix).

**Arguments**
1. `$submission_id` (`int`)
2. `$row` (`array`)

### `dcb_workflow_trigger_notification`
Generic workflow notification trigger architecture.

**Arguments**
1. `$submission_id` (`int`)
2. `$event` (`string`)
3. `$payload` (`array`)

### `dcb_workflow_trigger_notification_{$event}`
Event-specific workflow notification trigger (dynamic suffix).

**Arguments**
1. `$submission_id` (`int`)
2. `$payload` (`array`)

### `dcb_ocr_review_status_changed`
Fires when an OCR review item status changes.

**Arguments**
1. `$review_id` (`int`)
2. `$from_status` (`string`)
3. `$to_status` (`string`)
4. `$note` (`string`)

### `dcb_ocr_review_corrected`
Fires when manual OCR corrections are saved.

**Arguments**
1. `$review_id` (`int`)
2. `$corrections` (`array`)

### `dcb_ocr_review_reprocessed`
Fires when OCR review item is reprocessed.

**Arguments**
1. `$review_id` (`int`)
2. `$result` (`array`)
3. `$mode` (`string`)

### `dcb_ocr_review_promoted_draft`
Fires when reviewed OCR output is promoted to builder-compatible draft payload.

**Arguments**
1. `$review_id` (`int`)
2. `$draft` (`array`)

### `dcb_signature_evidence_persisted`
Fires after signature evidence is normalized and persisted.

**Arguments**
1. `$submission_id` (`int`)
2. `$normalized` (`array`)

### `dcb_output_finalized`
Fires when finalized output metadata/html is refreshed.

**Arguments**
1. `$submission_id` (`int`)
2. `$template_key` (`string`)
3. `$payload` (`array`)

### `dcb_output_render_html`
Filter rendered submission HTML for admin/final/print contexts.

**Arguments**
1. `$html` (`string`)
2. `$submission_id` (`int`)
3. `$view` (`string`)
4. `$payload` (`array`)

### `dcb_tutor_mapping_resolved`
Fires when Tutor mapping is resolved for access or completion lifecycle steps.

**Arguments**
1. `$form_key` (`string`)
2. `$mapping` (`array`)
3. `$context` (`array`)

### `dcb_tutor_access_evaluated`
Fires when Tutor-gated access checks are evaluated.

**Arguments**
1. `$details` (`array`)

### `dcb_tutor_access_gated`
Fires when Tutor prerequisite checks deny access.

**Arguments**
1. `$details` (`array`)

### `dcb_tutor_completion_relation_recorded`
Fires after Tutor relation metadata is recorded on a submission.

**Arguments**
1. `$submission_id` (`int`)
2. `$relation` (`array`)
3. `$mapping` (`array`)

### `dcb_tutor_training_assignment_attempted`
Fires after a training-assignment attempt is executed.

**Arguments**
1. `$submission_id` (`int`)
2. `$assignment` (`array`)
3. `$mapping` (`array`)

### `dcb_tutor_training_assignment_completed`
Fires when a training-assignment attempt succeeds.

**Arguments**
1. `$submission_id` (`int`)
2. `$assignment` (`array`)
3. `$mapping` (`array`)

### `dcb_tutor_training_assignment_failed`
Fires when a training-assignment attempt fails or is skipped.

**Arguments**
1. `$submission_id` (`int`)
2. `$assignment` (`array`)
3. `$mapping` (`array`)

### `dcb_ocr_correction_rules_updated`
Fires when deterministic OCR correction-feedback rules are updated from review actions.

**Arguments**
1. `$rules` (`array`)
