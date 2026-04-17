# Chart Routing Prototype (First EMR-Facing Feature)

## Purpose

This pass adds a **safe, generic Document-to-Chart Routing prototype**.

Scope for this phase:

1. Upload/import artifact
2. Extract patient/chart clues
3. Build candidate match list
4. Require confirmation/manual review for weak evidence
5. Route via connector boundary + audit trail

Out of scope for this phase:

- Full deep EMR writeback
- Vendor-specific endpoint/selectors in core plugin code
- Hardcoded TherapyHub flows

## Confidence Tiers

The prototype uses confidence tiers for patient/chart matching:

- `high_confidence`
- `medium_confidence`
- `low_confidence`
- `no_match`

Guardrails:

- Name-only evidence is always guarded and not auto-routed.
- Exact MRN/patient ID evidence is strongest.
- Name + DOB is strong evidence.
- Name + visit date + clinician can support schedule-based evidence.

## Document Type Classification

Lightweight generic labels:

- `consent`
- `intake`
- `physician_order`
- `visit_note`
- `eval`
- `miscellaneous`

Classification always preserves confidence/uncertainty.

## Queue + Manual Review Behavior

Admin queue: **Document Center → Chart Routing Queue**.

Each row keeps:

- Source artifact + trace link
- Extracted identifiers snapshot
- Candidate list summary with scores/reasons
- Confidence tier + score
- Document-type guess
- Route status + notes
- Audit trail events

Actions:

- Confirm top match
- Select alternate candidate
- Mark no reliable match
- Queue manual review
- Route/attach through connector boundary

## Connector Boundary

Core connector contract:

- `search_patient_candidates()`
- `resolve_chart_target()`
- `attach_document_to_chart()`
- `get_schedule_context()`
- `validate_connector_config()`

Included adapters:

- Manual/no-op connector (`none_manual`)
- Report-import placeholder (`report_import`)
- API/Bot placeholder extension path (`api`, `bot`) via hooks

Next-phase implementation adds an external package skeleton at:

- `providers/real-connector-skeleton/`

See `docs/connector-skeleton-secure-config.md` for secure config + retry-state details.

No vendor credentials or hardcoded EMR endpoints/selectors are embedded in core classes.
