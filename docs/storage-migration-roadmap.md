# Form Storage Migration Roadmap

Current storage:
- single option key: `dcb_forms_custom`

## Goal
Move to a scalable storage model while preserving current behavior.

## Target options
1. Form-definition CPT (+ revisions)
2. Custom tables

## Preparation already implemented
- `DCB_Form_Repository` abstraction added.
- `dcb_forms_storage_mode` option introduced (`option|cpt|table`).
- current code reads/writes through repository abstraction where practical.

## Recommended migration sequence
1. **Dual-read phase**
   - Repository reads primary store by mode.
   - If missing, fallback to option store.
2. **Backfill phase**
   - CLI/admin migration tool copies existing option forms into new store.
   - Preserve `version`, schema, and node metadata.
3. **Dual-write phase (temporary)**
   - Writes to both stores for safety.
4. **Cutover phase**
   - switch mode to `cpt` or `table`.
   - verify builder/runtime parity.
5. **Cleanup phase**
   - optional deprecation of option payload after stable release window.

## Data integrity considerations
- preserve form structural signature and version increments.
- preserve JSON import/export compatibility.
- keep `dcb_form_snapshot` behavior stable for existing submissions.

## Suggested table design (if table mode)
- `wp_dcb_forms`
  - `id`, `form_key` (unique), `label`, `version`, `schema_json`, `created_at`, `updated_at`
- `wp_dcb_form_revisions`
  - `id`, `form_id`, `version`, `schema_json`, `changed_by`, `created_at`

## Risk controls
- dry-run migration report.
- rollback toggle (`dcb_forms_storage_mode=option`).
- checksums pre/post migration.
