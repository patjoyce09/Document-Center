# Intake Resource Center Model

## Intake Source Model

Normalized source channel values:

- `direct_upload`
- `phone_photo`
- `scanned_pdf`
- `email_import`
- `digital_only`

Capture type values:

- `direct_file`
- `photo_image`
- `scan_pdf`
- `email_attachment`
- `digital_manual`
- `unknown`

These values are generated through helper functions in [includes/helpers-intake.php](../includes/helpers-intake.php).

## Original → Review → Digital Twin → Submission Linkage

Traceability chain keys:

- Upload artifact (`dcb_upload_log`):
  - `_dcb_upload_trace_id`
  - `_dcb_upload_source_channel`
  - `_dcb_upload_capture_type`
  - `_dcb_upload_ocr_review_item_id`
  - `_dcb_upload_linked_submission_id`
  - `_dcb_upload_intake_state`
- OCR review item (`dcb_ocr_review_queue`):
  - `_dcb_ocr_review_trace_id`
  - `_dcb_ocr_review_upload_log_id`
  - `_dcb_ocr_review_linked_submission_id`
  - `_dcb_ocr_review_source_channel`
  - `_dcb_ocr_review_capture_type`
- Submission (`dcb_form_submission`):
  - `_dcb_intake_trace_id`
  - `_dcb_intake_upload_log_id`
  - `_dcb_intake_review_id`
  - `_dcb_intake_source_channel`
  - `_dcb_intake_capture_type`
  - `_dcb_intake_state`

## Resource Center Status Model

The upload portal resource center composes state from:

1. Form definitions (`dcb_form_definitions()`)
2. Packet definitions (`dcb_workflow_packet_definitions`) for required forms
3. User submissions (`dcb_form_submission`) for workflow status
4. User upload artifacts (`dcb_upload_log`) for upload volume + latest intake state

State labels shown to users map from normalized values:

- `uploaded`
- `ocr_review_pending`
- `correction_in_review`
- `returned_for_correction`
- `submitted`
- `approved`
- `finalized`
- `rejected`

## Hooks / Filters

- `dcb_intake_source_channel`
  - Filter normalized source channel
  - Args: `$normalized_channel`, `$raw_channel`

- `dcb_resource_center_payload`
  - Filter resource center payload before rendering in upload portal
  - Args: `$payload`, `$user_id`, `$forms`
