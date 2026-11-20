# Keep Alive 3B.6C — Socket.IO Gateway & Post-Commit Notifications

**Status:** Implemented locally (not deployed)  
**Baseline:** `b3ccbf5` → this commit  
**Parents:** [3B.6A](KEEPALIVE_SOCKETIO_DATA_ROUTING_3B6A.md), [3B.6B](KEEPALIVE_SOCKET_TICKET_3B6B.md)

---

## 1. Gateway architecture

Isolated package: `gateway/` (Node.js ≥20, Socket.IO 4.x).

| Process | Role |
|---------|------|
| Laravel (PHP) | Ticket mint/verify, durable events, outbox, HTTP publisher |
| `keepalive-gateway` | Single-worker Socket.IO sidecar + `/internal/publish` |

```text
Peer --WSS--> gateway (auth.ticket) --HTTP--> Laravel /keepalive-gateway/socket/verify
Laravel --HTTP--> gateway /internal/publish --emit--> rooms
```

### Files

| Path | Purpose |
|------|---------|
| `gateway/src/server.js` | HTTP health + publish + Socket.IO |
| `gateway/src/auth.js` | Ticket verify via Laravel |
| `gateway/src/envelopes.js` | `ka.*` envelope parse/build |
| `gateway/src/limits.js` | Payload/rate/queue bounds |
| `gateway/test/gateway.test.js` | Node contract tests |
| `gateway/package.json` | Isolated deps (not Vite frontend) |

### Runtime rules

- WebSocket-only transport; path `/keepalive/socket.io/`
- Join **only** `authorized_rooms` from verify response
- Ignore client `join` / room claims
- No Client API Keys, cookies, Browser profiles, or Job tokens on the socket
- Reconnect requires a **new** ticket (jti consume)

---

## 2. Realtime events (advisory)

| Event | When |
|-------|------|
| `ka.notify` | Durable campaign sequence advanced (`seq_hint`) |
| `ka.assignment_changed` | Explicit assign/revoke |
| `ka.route_invalidated` | `route_generation` bumped |
| `ka.recover_hint` | Reserved (gap-fill hint) |

Envelope: `{ v: 2, t, tenant_id, campaign_id, seq_hint?, route_generation?, ts, … }`  
Max ~2 KiB. Room rate ≤10 emits/s. Publish queue bound 1000 (drop oldest).

Durable truth remains HTTP snapshot/events/ACK.

---

## 3. Post-commit notification design

### Audit finding (no Job path change)

- Durable Keep Alive rows are written in `EloquentKeepAliveFeedStore::projectDurableEvents` (own TX).
- Job claim/ACK/result in `CampaignOrchestrator` writes `sa_job_events` only — **not** Keep Alive durable feed.
- **3B.6C does not modify CampaignOrchestrator / claim / lease / fencing.**

### Mechanism

1. Inside the durable projection TX (when `projected > 0`): enqueue coalesced outbox row (`sa_keepalive_notify_outbox`) with server-resolved rooms.
2. `DB::afterCommit` → `KeepAliveNotifyService::flush()` → HTTP POST gateway `/internal/publish`.
3. Gateway failure → mark outbox failed + retry backoff; **never** rolls back durable events or Jobs.
4. Unconfigured publish URL/secret → `gateway_unconfigured` (pending rows **retained**; no soft-success discard).
5. Assign/revoke → enqueue `ka.assignment_changed` + `ka.route_invalidated`.
6. Artisan + scheduler: `social-automation:keepalive-notify-flush` every minute (`withoutOverlapping`) for retry when gateway was offline/unconfigured.

### Coalesce

`coalesce_key = notify|{tenant}|{campaign}` — pending row updated; max `seq_hint` wins. No per-socket SQL.

### Proposed future (not implemented)

Optional non-blocking `afterCommit` enqueue from Job event writers for lower latency. That would touch Job paths — deferred per 3B.6C stop rule. Until then, notify fires when durable projection runs (HTTP feed / future projector tick).

---

## 4. Server→gateway publish contract

`POST {KA_GATEWAY}/internal/publish`

Headers: `X-KeepAlive-Gateway-Secret: <shared>`

Body:

```json
{
  "event": "ka.notify",
  "tenant_id": "...",
  "campaign_id": "...",
  "rooms": ["tenant:...:campaign:...:node:..."],
  "seq_hint": 42
}
```

- Auth required; rooms must match tenant+campaign prefix
- Short timeout (2s), bounded retry (2)
- Not a public broadcast API

---

## 5. Deployment requirements (do not deploy yet)

### Node

- Node.js ≥ 20
- `cd gateway && npm ci --omit=dev && npm start`
- Single worker only (no cluster, no Redis adapter)

### Environment

| Variable | Purpose |
|----------|---------|
| `KA_GATEWAY_PORT` | Default `3015` |
| `KA_GATEWAY_HOST` | Default `127.0.0.1` |
| `KA_GATEWAY_PATH` | `/keepalive/socket.io/` |
| `KA_GATEWAY_SECRET` | Shared with Laravel verify + publish |
| `KA_GATEWAY_PUBLISH_SECRET` | Optional override for publish |
| `KA_GATEWAY_VERIFY_URL` | Full Laravel verify URL |
| Laravel `social-automation.keepalive_gateway_publish_url` | e.g. `http://127.0.0.1:3015/internal/publish` |
| Laravel `social-automation.keepalive_gateway_secret` | Same as `KA_GATEWAY_SECRET` |
| Laravel `social-automation.keepalive_socket_secret` | Ticket HMAC (3B.6B) |

### Nginx (sketch)

```nginx
location /keepalive/socket.io/ {
  proxy_pass http://127.0.0.1:3015;
  proxy_http_version 1.1;
  proxy_set_header Upgrade $http_upgrade;
  proxy_set_header Connection "upgrade";
  proxy_set_header Host $host;
  proxy_read_timeout 60s;
}
```

Do **not** expose `/internal/publish` publicly (firewall / listen 127.0.0.1 only).

### Health / resources / restart

- `GET /health` → connected count, queue, memory
- Memory: expect small RSS for single worker; alert on heap growth
- Restart: systemd `Restart=on-failure`; peers re-mint tickets and reconnect
- Schedule: `social-automation:keepalive-notify-flush` every minute (registered; non-overlapping)

### Migrations

`2026_11_20_180000_create_sa_keepalive_notify_outbox` — apply only when approved (not LIVE in this phase).

---

## 6. Desktop 3B.6D handoff

1. Mint ticket via `POST /client/keepalive/socket/ticket` (never put API key on Socket URL).
2. Connect WSS with `auth: { ticket }` to `/keepalive/socket.io/`.
3. On `ka.notify` / `ka.recover_hint`: HTTP gap-fill `GET .../events?from_sequence=` then event ACK.
4. On `ka.route_invalidated` / connect_error (`route_generation_stale` / replay): disconnect, re-mint, reconnect.
5. Local SQLite journal by `event_id` / sequence; no Job claim over Socket.
6. Support 0 / 1 / N Node topologies (multi-room fan-in OK).
