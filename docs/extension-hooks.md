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

## Actions

### `dcb_submission_completed`
Fires after submission is finalized/notification phase.

**Arguments**
1. `$submission_id` (`int`)
2. `$form_key` (`string`)
3. `$user_id` (`int`)

## Key settings
- `dcb_ocr_mode`
- `dcb_ocr_api_base_url`
- `dcb_ocr_api_key`
- `dcb_ocr_api_auth_header`
- `dcb_ocr_timeout_seconds`
- `dcb_ocr_max_file_size_mb`
- `dcb_forms_storage_mode`
