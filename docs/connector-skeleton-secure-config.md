# Real Connector Skeleton + Secure Config Boundary

## Purpose

This phase adds a **real connector package skeleton** using adapter injection only.

Core plugin classes keep connector boundaries generic. The provider package lives under:

- `providers/real-connector-skeleton/`

## Package Structure

- `bootstrap.php`
  - registers adapter injection hook for `api` mode + `provider_key=real_connector_skeleton`
- `class-real-connector-skeleton.php`
  - implements `DCB_Chart_Routing_Connector_Interface`

No vendor selectors/endpoints are hardcoded in core plugin code.

## Secure Config Boundary

Public connector config remains in:

- `dcb_chart_routing_connector_config`

Secret/token is isolated in:

- `dcb_chart_routing_connector_secret`

Behavior:

- secret is never echoed raw in admin UI
- secret is masked in display (`****1234` pattern)
- secret is resolved only at runtime for connector calls

Additional controls:

- `dcb_chart_routing_require_confirmation` (default `1`)
- `dcb_chart_routing_max_retry_attempts` (default `3`)

## Connector Validation

Use **Chart Routing Queue → Test Connector Readiness**.

Validation stores normalized payload in:

- `dcb_chart_routing_last_connector_validation`

Includes:

- `ok`
- `errors[]`
- `warnings[]`
- `checked_at`
- `mode`
- `provider_key`

## Retry-Ready Attach Result Model

Per queue item connector result metadata (`_dcb_chart_route_connector_result`) includes:

- `state`: `attempted|confirmed|attached|failed|retry_pending`
- `attempted`
- `confirmed`
- `attached`
- `retry_count`
- `retryable`
- `failure_reason`
- `message`
- `external_reference`
- timestamps (`attempted_at`, `confirmed_at`, `attached_at`, `failed_at`, `retry_pending_at`, `updated_at`)

Observability mirrors:

- `_dcb_chart_route_last_result_state`
- `_dcb_chart_route_last_failure_reason`
- `_dcb_chart_route_retry_count`
- `_dcb_chart_route_last_attempt_at`
- `_dcb_chart_route_last_attached_at`

## Guardrails

- Human confirmation remains required by default.
- Weak evidence is not auto-routed.
- Retry state is explicit; failures are tracked with reasons.
