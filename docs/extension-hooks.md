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
