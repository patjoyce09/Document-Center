# Form Storage Backend Contract (DCB)

This document defines the runtime expectations for form storage backends used by `DCB_Form_Repository`.

## Required backend behaviors

For each storage mode (`option`, `cpt`, `table`), backend adapters must support:

1. `read_all(mode)`
   - Returns an associative array keyed by form key.
   - Returns empty array on missing backend data.
   - Must never return non-array data.

2. `write_all(mode, forms)`
   - Persists a complete associative form map.
   - Must preserve form keys exactly.

3. Mode safety
   - Invalid mode must be rejected or normalized to `option`.
   - Reads/writes must be deterministic for the same input payload.

## Current implementation details (this pass)

- Canonical legacy backend: `dcb_forms_custom` option.
- Real CPT backend: `dcb_form_definition` posts with payload/meta-backed storage.
- Table mode remains a shadow option backend (`dcb_forms_custom_table_shadow`) in this pass.
- Dual read toggle: `dcb_forms_storage_dual_read`.
- Dual write toggle: `dcb_forms_storage_dual_write`.

## Migration behavior

- `migrate_option_to_mode(target, dry_run=true)`
  - Dry run reports copy counts without writing.
  - Non-dry-run copies option payload into target shadow backend.
  - Migration metadata is stored in:
    - `dcb_forms_storage_last_migrated_at`
    - `dcb_forms_storage_last_migrated_target`

## Runtime diagnostics

`migration_readiness()` returns:

- active mode
- dual read/write flags
- source/target record counts
- basic target readiness indicators

## Future cutover notes

When real CPT/table persistence is implemented:

- keep dual-read enabled through one release cycle
- enable dual-write for rollback safety during early cutover
- only switch `dcb_forms_storage_mode` after migration counts match and builder/runtime parity is validated
