# Keep Alive 3B.6A — Socket.IO Data Routing Architecture

**Status:** Design / audit only (no implementation, no LIVE deploy)  
**Server baseline:** `a0bb175` (`seo-ops-addons-rpa` @ LAPTOP)  
**Client baseline (DESKTOP):** `7e69014` — **not present on LAPTOP**; Electron details below are contract-level assumptions for handoff  
**Protocol:** `keepalive.v1` / schema v2

---

## 0. Audit findings (LAPTOP evidence)

| Area | Finding |
|------|---------|
| Keep Alive HTTP | Register, assignments, snapshot, events, event ACK, artifact ACK exist (`keepalive.v1`) |
| Control plane | Job Claim/Lease/Fencing in `CampaignOrchestrator`; untouched by Keep Alive |
| Client connection | Bearer Client API Key; 120s `connection_token` lease; 90s online TTL from heartbeat |
| Socket.IO in addon | **None** — no Socket.IO/Redis pub-sub code in `seo-ops-addons-rpa` |
| Host Redis | `omnichannel-client` `.env` has Redis host config; `QUEUE_CONNECTION=database` — Redis is **available config**, not proven as LIVE Keep Alive fan-out |
| Laravel emit | No Reverb/Pusher/broadcasting dependency in host `composer.json` (as audited) — **PHP cannot natively host Socket.IO**; needs a sidecar gateway |
| LAN assumption | **Forbidden** — Clients/Nodes may be on disjoint networks; all routes must work via Internet/TLS |
| New Redis | Do **not** create a new Redis instance by default; reuse existing host Redis **only if** VPS already runs it for presence/fan-out |

---

## 1. Deployment topology

```text
                         ┌─────────────────────────┐
                         │  VPS (SEO-OPS + Addon)  │
                         │  Job Authority (HTTP)   │
                         │  Keep Alive HTTP APIs   │
                         │  Socket.IO Gateway *    │
                         │  (optional Redis)       │
                         └───────────┬─────────────┘
               HTTPS/WSS             │             HTTPS/WSS
        ┌────────────────────────────┼────────────────────────────┐
        │                            │                            │
┌───────▼───────┐          ┌─────────▼────────┐         ┌─────────▼────────┐
│ Executing     │          │ 24/7 Node A      │         │ 24/7 Node B      │
│ Client        │◄─relay──►│ (Recovery)       │         │ (Recovery)       │
│ SQLite journal│          │ SQLite journal   │         │ SQLite journal   │
└───────────────┘          └──────────────────┘         └──────────────────┘
```

### Topologies (user-chosen, not auto-balanced)

| Mode | Data path | When |
|------|-----------|------|
| **0 Node** | Client ↔ VPS only | No Keep Alive assignment for Campaign |
| **1 Node** | Client ↔ Node ↔ VPS (Node is relay + journal) | Single explicit assignment |
| **N Nodes** | Independent routes per (Client, Campaign, Node) | Multiple **user-assigned** Nodes; no load balancing |

\* Socket.IO Gateway = separate Node.js process beside Laravel (or nginx-proxied path). Laravel remains HTTP authority.

---

## 2. Control plane vs data plane

### Control plane (unchanged)

- Server is sole Job Authority (Claim / ACK / Progress / Result / fencing).
- Account Hard Pin, Browser Profile, Cookies stay on executing Client + server APIs.
- Keep Alive **never** carries Job execution tokens, lease tokens, or cookie material.

### Data plane (Keep Alive + Socket.IO)

Allowed envelopes only:

| Kind | Purpose | Durable? |
|------|---------|----------|
| `ka.notify` | Tiny “campaign sequence advanced” ping | No (hint only) |
| `ka.progress_summary` | Coalesced progress (percent/step) | No |
| `ka.route` | Route grant / revoke / generation bump | Session |
| `ka.presence` | Node online/offline hint | Session |
| Snapshot / events / ACK / artifact meta | **HTTP only** | Yes (existing 3B.3 / 3B.5A) |

**Rule:** Socket never replaces durable HTTP. Socket is a wake-up / coalesce channel. Gap-fill always via `GET .../events?from_sequence=`.

---

## 3. Minimal Socket.IO gateway design

### Why a sidecar (not PHP)

- Socket.IO is a Node protocol; Laravel/PHP is HTTP/JSON today.
- Lightest path: **one** `keepalive-gateway` Node process on VPS:
  - Auth handshake via short-lived **route ticket** minted by Laravel HTTP
  - Room model: `tenant:{tenant_id}:campaign:{campaign_id}:node:{node_id}`
  - Optional Redis adapter **only if** multiple gateway workers needed (default: single process, no new Redis)

### Auth handshake

1. Peer (Client or Node) calls Laravel:  
   `POST /client/keepalive/socket/ticket`  
   Body: `node_id?`, `campaign_ids[]`, `assignment_generation`, role=`executor|recovery`  
2. Server verifies API key + explicit assignments + generation.  
3. Returns `{ ticket, expires_at, gateway_url, rooms[], route_generation }`.  
4. Peer connects WSS → gateway with `auth.ticket`.  
5. Gateway validates ticket (HMAC/JWT signed by Laravel shared secret, TTL ≤ 120s, single-use or short replay window).

**Do not** put long-lived Client API Key on the Socket query string.

### Rooms & fan-out

- Recovery Node joins rooms for each assigned Campaign.
- Executing Client joins rooms only for Campaigns it runs **and** for which a route was granted.
- Gateway emits `ka.notify` to room when Laravel marks durable sequence advance (HTTP hook or outbox poll — see plan).
- **No per-step SQL** on Socket emit path.

### Reverse proxy / TLS

- Prefer `wss://{host}/keepalive/socket.io/` behind existing nginx TLS.
- Same origin policy as API host; sticky session not required for single-worker gateway.
- Rate-limit ticket mint + connection rate per tenant.

---

## 4. Client ↔ Node relay protocol

### Roles

| Role | Machine | Duties |
|------|---------|--------|
| Executor | Electron Client running Jobs | Executes Jobs; uploads Final Screenshot via existing HTTP; journals locally; may subscribe to VPS notify |
| Recovery Node | Electron Keep Alive Node | Subscribes to Campaign rooms; journals events; ACKs delivery to server; optional P2P/relay assist when Executor cannot reach VPS well |

### Relay modes

**A. Hub-only (default, Internet-safe)**  
Executor and Node each connect **only to VPS gateway**. Node receives notifies → HTTP gap-fill → journal → event ACK. No Client↔Node direct socket required.

**B. Assisted relay (optional, explicit)**  
When user enables “Node relay” for a Campaign **and** both peers present:
1. VPS issues mutual `relay_grant` (ticket pair, campaign-scoped, generation-bound).
2. Peers open **one** additional Socket to each other **or** use gateway as signaling only for WebRTC/datachannel later.
3. Phase 3B.6A recommendation: **start with Hub-only**; defer direct Client↔Node Socket until proven need (NAT traversal complexity).

### Envelope (tiny)

```json
{
  "v": 2,
  "t": "ka.notify",
  "tenant_id": "...",
  "campaign_id": "...",
  "node_id": "...",
  "route_generation": 7,
  "seq_hint": 42,
  "ts": "2026-11-20T07:00:00Z"
}
```

Max payload ~1–2 KB. Progress summaries coalesced ≥1s / ≤10/s per campaign peer.

### Idempotency

- `seq_hint` is advisory; durable truth = server `event_sequence`.
- Local SQLite upsert by `event_id` / `(campaign_id, event_sequence)`.
- Delivery ACK via existing HTTP `POST .../events/ack` (contiguous).

---

## 5. Authentication & tenant isolation

| Check | Where |
|-------|-------|
| Client API Key | All HTTP Keep Alive + ticket mint |
| `tenant_id` = key `installation_id` | Server-owned; reject mismatch |
| Explicit Node↔Campaign assignment | Snapshot/events/ACK/ticket rooms |
| `assignment_generation` / `route_generation` | Stale → 409; force re-ticket |
| Node revoked / assignment revoked | Drop rooms; refuse ticket |
| Cross-tenant room join | Impossible if rooms derived from verified ticket claims |

Nodes **do not** authenticate other Clients with shared secrets. VPS is the trust broker (tickets). Optional relay grants are also VPS-minted and campaign-scoped.

---

## 6. Route selection & fallback

### Discovery

1. Executor: after Campaign start context (local), call `GET .../keepalive/assignments?node_id=` **or** staff-assigned “executor route map” endpoint (future): list Nodes for Campaigns this Client runs.
2. For each Campaign with ≥1 assigned Recovery Node → mint socket ticket including those rooms.
3. **User** chose Nodes; server never auto-picks or load-balances.

### Single active data route (Client + Campaign)

- At most **one** `route_generation` active per `(tenant, campaign, executor_agent)`.
- If multiple Nodes assigned: Executor may subscribe to **all** Node rooms for redundancy **or** pin primary Node (user preference). Default recommendation: **fan-in notify from all assigned Nodes’ rooms is OK**; durable ACK remains per Node cursor (already independent in 3B.5A).
- Prevent duplicate **write** paths: only Executor uploads screenshots / job results (control plane). Recovery Nodes only journal + ACK delivery.

### Offline / fallback

| Condition | Behavior |
|-----------|----------|
| Node offline | Executor continues Jobs (control plane). Data plane falls back to **0-Node** (Client↔VPS HTTP). Node catches up via snapshot/events on reconnect |
| Gateway down | Peers keep HTTP polling backoff (bounded). No Job pause solely due to Socket loss |
| `assignment_generation` / `route_generation` bump | Invalidate tickets; re-mint; clear stale rooms |
| Split brain | Durable server sequence + ACK cursors win; local journals reconcile by sequence |

---

## 7. Performance budget & observability

| Budget | Target |
|--------|--------|
| Socket connections | ≤ 1 per peer to gateway; no per-campaign TCP |
| Notify rate | Coalesce; ≤ 10 emits/s/campaign room |
| Envelope size | ≤ 2 KiB |
| SQL on notify | 0 (emit from outbox worker / after durable write commit) |
| HTTP | Snapshot start, gap-fill, ACK, artifact verify/upload only |
| Local queues | Bounded 1k envelopes; drop oldest notify hints (never drop durable journal intent) |
| Backpressure | Gateway disconnect slow consumers; client resubscribes |

### Observability metrics

- `ka_socket_connected{role,tenant}`
- `ka_ticket_mint_total` / `ka_ticket_reject_total`
- `ka_notify_emit_total` / `ka_notify_coalesced_total`
- `ka_gap_fill_http_total`
- `ka_ack_cursor{node,campaign}`
- `ka_route_generation`
- Gateway lag: `seq_hint` vs DB `event_sequence_head`

---

## 8. Reliability model

```text
Executor Job lifecycle (control) ──HTTP──► VPS durable events/artifacts
        │                                      │
        │                              Socket notify (hint)
        │                                      ▼
        │                              Recovery Node
        │                                      │
        └── SQLite journal ◄── HTTP gap-fill ──┘
                                               │
                                               └── HTTP event/artifact ACK
```

- SQLite journals on Executor + each Recovery Node (event_id, sequence, payload hash).
- Redis: **optional** gateway adapter / presence only; not source of truth.
- Final Screenshot: upload via existing artifact path immediately after Job terminal (control plane); Nodes ACK metadata only after server checksum verified (3B.5A).
- **No automatic social-action replay** from Keep Alive journals.

---

## 9. Staged implementation plan (minimal)

| Stage | Scope | Exit criteria |
|-------|-------|---------------|
| **3B.6B** | Laravel `socket/ticket` + route_generation; OpenAPI; unit tests | Ticket auth proofs; no gateway yet |
| **3B.6C** | VPS `keepalive-gateway` (single worker, Hub-only); nginx WSS; Hub notify after durable event insert (outbox) | Client+Node connect; receive notify; HTTP gap-fill |
| **3B.6D** | Electron subscribers (DESKTOP): ticket, room join, coalesce, SQLite journal hook, ACK | E2E on staging with 0/1 Node topologies |
| **3B.6E** | Multi-Node rooms + generation invalidation + metrics | N-Node notify; independent ACK cursors |
| **3B.6F** (optional) | Direct Client↔Node relay grants | Only if Hub-only bandwidth/latency insufficient |

**Explicitly deferred:** Redis cluster, multi-gateway, WebRTC, automatic Node selection, Job permissions over Socket.

---

## 10. Risks & blockers

| Risk | Severity | Mitigation |
|------|----------|------------|
| Client `7e69014` not on LAPTOP | Med | Handoff via this doc + OpenAPI; Desktop implements ticket consumer |
| No Socket.IO in PHP | High | Sidecar gateway required |
| Redis unproven on LIVE | Med | Start single-worker gateway; reuse Redis only if already healthy |
| Notify without durable write | High | Emit only after `sa_keepalive_campaign_events` commit / outbox |
| Ticket leakage | High | Short TTL, audience bind, HTTPS-only |
| Treating Socket as Job bus | Critical | Hard schema allowlist; no lease/cookie fields |
| Duplicate routes | Med | `route_generation` + single ticket mint per executor session |
| NAT for direct relay | High | Defer direct Client↔Node; Hub-only first |
| nginx WSS path collision | Med | Dedicated `/keepalive/socket.io/` location |

---

## 11. Handoff requirements (DESKTOP Client 3B.6B+)

1. Consume Laravel ticket API (to be built in 3B.6B); never put raw API key on Socket URL.  
2. Maintain local SQLite journal keyed by `event_id` / sequence.  
3. On `ka.notify`: HTTP gap-fill then contiguous event ACK.  
4. Honor `assignment_generation` / `route_generation` invalidation.  
5. Do not claim Jobs or send cookies over Keep Alive channels.  
6. Support topologies 0 / 1 / N without assuming LAN.

---

## 12. Decision summary

| Decision | Choice |
|----------|--------|
| Job Pool / Queue via Socket? | **No** |
| Auto load-balance Nodes? | **No** — user assignment only |
| Default topology | Hub-only (Client↔VPS, Node↔VPS) |
| Durable truth | Existing HTTP snapshot/events/ACK |
| Socket role | Tiny notify + presence + route control |
| Redis | Optional later; not required for MVP |
| New Redis instance | **Not justified** for MVP |
