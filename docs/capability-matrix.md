# Capability Matrix

The plugin now uses granular capabilities:

- `dcb_manage_forms`
- `dcb_review_submissions`
- `dcb_manage_workflows`
- `dcb_manage_settings`
- `dcb_run_ocr_tools`

## Areas mapped

### Forms
- Builder page access/save
- Dashboard primary menu access

### Submissions/Review
- Submission post type read/edit UI
- Submission row actions (print/export)
- Reviewer queue page
- Submission detail metabox rendering

### Workflow operations
- Transition actions
- Correction requests
- Finalize action
- Bulk workflow state updates

### Settings
- Settings page view/save

### OCR tools
- OCR diagnostics page
- OCR smoke validation AJAX
- OCR review queue/log post types

## Default role grant on activation
- Administrator: all DCB capabilities
- Editor: all DCB capabilities

(Adjust role policy as needed in deployment-specific governance.)
