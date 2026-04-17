# Setup & Operations Guide

## Purpose

This guide covers first-run setup, operations checks, OCR expectations, intake traceability, and release discipline for Document Center Builder.

## First Run Checklist

Open **Document Center → Setup & Operations** and confirm:

1. Capability model readiness (`dcb_manage_forms`, `dcb_review_submissions`, `dcb_manage_workflows`, `dcb_manage_settings`, `dcb_run_ocr_tools`)
2. OCR mode/config readiness (local/remote/auto)
3. Upload directory writable status
4. Permalink/admin readiness

## System Health Snapshot

Open **Document Center → Dashboard** for a compact system-health snapshot panel showing:

- setup readiness summary
- OCR mode/health status
- upload/storage note
- OCR review queue status counts
- unresolved OCR risk count
- workflow status summary
- last setup/import/export/sample action

The snapshot includes direct links to Setup & Operations, OCR diagnostics/review queue, intake trace timeline/upload artifacts, and workflow queues.

## OCR Workflow Expectations

- OCR remains service-decoupled.
- Remote OCR requires HTTPS API base URL + API key.
- Auto mode can run local fallback when remote is incomplete.
- Queue health and diagnostics remain in **OCR Diagnostics**.

## Intake / Resource Center / Traceability

- Intake state model and resource center payload remain generic and reusable.
- Use **Intake Trace Timeline** for chain-first trace debugging by `trace_id`.
- Timeline payload can be extended using `dcb_intake_trace_timeline_payload`.

## Chart Routing Prototype (First EMR-Facing Wedge)

- Chart routing remains generic and connector-boundary-first.
- Start in **Document Center → Chart Routing Queue** for confidence-tier review before any route/attach action.
- Modes are controlled with `dcb_chart_routing_mode` (`none_manual`, `api`, `bot`, `report_import`).
- Weak evidence (especially name-only) must stay in manual review and must not auto-route.
- Use hooks/adapters for external EMR integration; do not hardcode vendor URLs, selectors, or credentials in core plugin code.

## Forms Import / Export

- Use Setup & Operations to export forms JSON.
- Import accepts either structured export payloads or compatible form maps.
- Import validation sanitizes and rejects malformed payloads.
- Import mode supports:
  - **Merge**: keep existing forms and overlay incoming keys
  - **Replace**: replace existing forms with validated import set

## Sample Template Pack

Setup & Operations provides a generic demo pack:

- `generic_intake_form`
- `consent_attestation_form`
- `simple_document_packet`

The pack is filterable using `dcb_sample_template_pack`.

## Uninstall Behavior

Default uninstall mode is conservative:

- Keeps plugin data unless explicit purge is enabled in Settings.
- To purge all plugin options + custom post type data on uninstall, enable:
  - **Settings → Uninstall Cleanup → Purge plugin options/data when plugin is uninstalled**

## Weekly Admin Digest (Optional Scaffold)

- Disabled by default in settings.
- Internal-only summary payload scaffold (no external telemetry).
- Intended as a boundary for future optional scheduled digest workflows.

## Release Hygiene

For each release:

1. Keep `document-center-builder.php` version and `readme.txt` stable tag aligned.
2. Add changelog notes for operational/admin behavior changes.
3. Keep smoke tests updated in CI.
4. Remove release-noise artifacts from plugin root.

For packaging automation details, see `docs/release-packaging.md`.
