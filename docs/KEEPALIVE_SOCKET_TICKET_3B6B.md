# Keep Alive 3B.6B — Socket Ticket & Route Generation

**Status:** Implemented (Laravel addon). Gateway sidecar added in 3B.6C (`docs/KEEPALIVE_SOCKET_GATEWAY_3B6C.md`); not LIVE-deployed.  
**Protocol:** `keepalive.v1` / schema 2  
**Design parent:** [KEEPALIVE_SOCKETIO_DATA_ROUTING_3B6A.md](KEEPALIVE_SOCKETIO_DATA_ROUTING_3B6A.md)

---

## Endpoints

| Method | Path | Auth |
|--------|------|------|
| `POST` | `/api/v1/social-automation/client/keepalive/socket/ticket` | Client API Key |
| `POST` | `/api/v1/social-automation/keepalive-gateway/socket/verify` | `X-KeepAlive-Gateway-Secret` |

Gateway verify **never** accepts Client API Keys.

---

## Ticket schema

Wire format: `ka_sock_{base64url(json)}.{hmac_sha256_hex}`

| Claim | Meaning |
|-------|---------|
| `v` | Ticket version `1` |
| `purpose` | `keepalive_socket` |
| `aud` | `keepalive-gateway` |
| `jti` | Unique id (replay / single-use consume) |
| `iat` / `exp` | Issued / expiry unix (TTL ≤ **120s**) |
| `tid` | Server tenant (`installation_id`) |
| `role` | `recovery` \| `executor` |
| `nid` | Node id (recovery) or `null` (executor) |
| `cid` | Authorized campaign UUID list |
| `rooms` | `tenant:{tid}:campaign:{uuid}:node:{nid}` |
| `agen` | `assignment_generation` snapshot |
| `rgen` | `route_generation` snapshot |
| `kid` | Client API key id (reference only; not the secret) |
| `aid` | Optional agent UUID |

Signing secret: `config('social-automation.keepalive_socket_secret')` or derived `ka-sock-v1|{app.key}`.

---

## route_generation lifecycle

| Subject | `subject_type` | `subject_key` |
|---------|----------------|---------------|
| Recovery Node | `node` | `node_id` |
| Executor Client | `executor` | `key:{client_api_key_id}` |

1. Starts at `0` when no row exists.
2. **Bump** on explicit Campaign assign or revoke for that Node (also bumps the minting key’s executor subject).
3. **Revoke** (`revoked_at` set + bump) blocks further mint/verify for that subject.
4. Mint embeds current `rgen`. Verify requires exact match → else `route_generation_stale` (409) → peer must **re-mint**.
5. Tables: `sa_keepalive_route_states`, `sa_keepalive_socket_ticket_jtis` (migration not applied to LIVE in this phase).

`assignment_generation` remains the Node assignment-set fence (recovery mint/verify). Executor tickets snapshot max agen across involved Nodes; room membership is re-checked live on verify.

---

## Roles & rooms

### Recovery

- Requires `node_id` + matching `assignment_generation`.
- `campaign_ids` optional (default = all active assignments for the Node).
- Rooms: one per requested Campaign for that Node only.

### Executor

- Requires non-empty `campaign_ids`.
- Each Campaign must have ≥1 active Keep Alive assignment in the tenant.
- Rooms: **all** active `(campaign, node)` pairs (multi-Node preserved; no load balancing).

---

## Gateway verification contract (3B.6C handoff)

Gateway may either:

**A. Call Laravel** `POST .../keepalive-gateway/socket/verify` with header `X-KeepAlive-Gateway-Secret`, body `{ ticket, consume?: true }`  
→ Use only `authorized_rooms` from the response.

**B. Local HMAC verify** using the shared socket signing secret (same algorithm as `KeepAliveSocketTicket`), then optionally call Laravel for jti consume + live assignment/rgen checks.

Rules:

- Do **not** put Client API Key on the Socket query string.
- Join **only** rooms listed in the ticket / verify response.
- On `route_generation_stale`, disconnect and instruct peer to re-mint.
- `consume=true` (default) → single-use jti for connect handshake.
- Renewal / reconnect: always **re-mint** a fresh ticket (≤120s TTL); never reuse a consumed ticket.

Gateway secret: `config('social-automation.keepalive_gateway_secret')` or derived `ka-gw-v1|{socket_signing_secret}`.

---

## Security guarantees (3B.6B)

| Threat | Mitigation |
|--------|------------|
| Cross-tenant | Server tenant from API key; reject mismatched `tenant_id` |
| Stale assignments | `assignment_generation` + live assignment re-check |
| Ticket replay | jti remember + consume |
| Privilege escalation | Role allowlist; recovery cannot claim other key’s Node; executor cannot invent Campaign rooms |
| API key leak to Socket | Ticket/HMAC only; gateway secret path separate |
| Job plane abuse | No claim/lease/cookie fields; no Job orchestrator hooks |

---

## Phase 3B.6C gateway handoff checklist

1. Deploy single-worker `keepalive-gateway` (Hub-only) beside Laravel; nginx `wss://…/keepalive/socket.io/`.
2. Share `keepalive_socket_secret` + `keepalive_gateway_secret` via env (not Client API Keys).
3. On connect: peer presents `auth.ticket` → gateway verifies (A or B above) → join `authorized_rooms`.
4. Emit `ka.notify` only after durable `sa_keepalive_campaign_events` commit / outbox.
5. Peers gap-fill via existing HTTP events + ACK (3B.3 / 3B.5A).
6. Apply socket route migration on target env when approved (not part of this LAPTOP commit deploy).
7. No Redis required for MVP single worker; no auto load balancing.
