# Keep Alive Wire Contract (keepalive.v1 / schema 2)

Canonical Client ↔ Server contract for Phases 3B.1–3B.3.

## Identity

| Field | Canonical meaning |
|-------|-------------------|
| `tenant_id` | Server-owned `installation_id` from Client API Key. Client-supplied value must match or is rejected. |
| `node_id` | Stable Keep Alive Node id (per tenant). |
| `campaign_id` | Campaign UUID (client-facing primary id). |
| `campaign_uuid` | Same UUID (explicit alias). |
| `agent_uuid` | Client agent owned by the authenticated API key. |

## Assignment row versioning

| Field | Role |
|-------|------|
| `row_version` | **Canonical** per-assignment row version (monotonic on re-assign/update). |
| `version` | Backward-compatible alias of `row_version` (Phase 3B.1). |
| `assignment_generation` | Node-level generation; bumps when the assignment set changes. Snapshot/events require the current generation. |

## Registration

- `POST /client/keepalive/register` is idempotent on `(tenant_id, node_id)`.
- Always returns `campaign_access_authorized: false`.
- Does not create Campaign assignments.

## Snapshot & events (3B.3)

- Access requires explicit active Node↔Campaign assignment + matching `assignment_generation`.
- Protocol: `keepalive.v1`, `schema_version: 2`.
- Never returns cookies, credentials, tokens, lease tokens, or Browser Profile secrets.
- Event feed uses campaign-scoped durable `event_sequence` (additive table). Transient `progress` is never durable history.

## Event types

`job_reserved` | `job_claimed` | `job_started` | `job_completed` | `job_failed` | `job_unknown` | `screenshot_uploaded`
