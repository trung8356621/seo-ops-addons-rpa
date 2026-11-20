# Keep Alive Wire Contract (keepalive.v1 / schema 2)

Canonical Client ↔ Server contract for Phases 3B.1–3B.6B.

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

## Delivery ACKs (3B.5A)

These confirm Recovery Node delivery/persistence. **Not** Job Claim/ACK/Result.

### Event ACK — `POST /client/keepalive/events/ack`

Request: `node_id`, `assignment_generation`, `campaign_id`, `event_ids[]`, `last_contiguous_sequence`

Rules:
- Active assignment + matching generation required.
- `event_ids` must belong to the Campaign and form the contiguous range `(cursor+1)…last_contiguous_sequence`.
- Reject ACK beyond durable `event_sequence_head`.
- Idempotent when `last_contiguous_sequence <=` current cursor.
- Persist per-Node, per-Campaign `acked_through_sequence`.

Response: `ok`, `protocol_version`, `campaign_id`, `acked_through_sequence`, `acked_event_count`, `assignment_generation`

### Artifact ACK — `POST /client/keepalive/artifacts/ack`

Request: `node_id`, `campaign_id`, `assignment_generation`, `job_id`, `attempt`, `storage_key`, `sha256`

Rules:
- Server must already hold a verified screenshot artifact (`sa_artifacts`) with checksum.
- `storage_key` must equal full `storage_path` **or** `artifact_uuid` (never basename-only).
- `sha256` must match server `checksum_sha256`.
- Missing/unverifiable artifact → `artifact_not_ready` (409) or `artifact_unsupported` (422).
- Idempotent; never changes Job completion status.
- No binary upload in this phase.

## Socket ticket (3B.6B)

See [KEEPALIVE_SOCKET_TICKET_3B6B.md](KEEPALIVE_SOCKET_TICKET_3B6B.md).

### Mint — `POST /client/keepalive/socket/ticket`

- Auth: Client API Key. Tenant server-owned.
- Roles: `recovery` (node_id + assignment_generation) | `executor` (campaign_ids; multi-node rooms).
- Returns HMAC ticket `ka_sock_…`, `rooms[]`, `route_generation`, `gateway_url`, TTL ≤ 120s.
- Never embeds Client API Key. Renewal / reconnect = re-mint.

### Gateway verify — `POST /keepalive-gateway/socket/verify`

- Auth: `X-KeepAlive-Gateway-Secret` only.
- Validates signature, audience, expiry, route_generation, live assignments, optional jti consume.
- Gateway joins only `authorized_rooms`.

## Socket gateway (3B.6C)

See [KEEPALIVE_SOCKET_GATEWAY_3B6C.md](KEEPALIVE_SOCKET_GATEWAY_3B6C.md).

- Sidecar: `gateway/` Socket.IO (WS-only, Hub-only, single worker).
- Events: `ka.notify`, `ka.assignment_changed`, `ka.route_invalidated`, `ka.recover_hint` (advisory).
- Post-commit outbox → gateway `/internal/publish` (never blocks Jobs).
