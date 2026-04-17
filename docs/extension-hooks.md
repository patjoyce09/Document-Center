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
