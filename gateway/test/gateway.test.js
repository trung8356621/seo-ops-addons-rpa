import assert from 'node:assert/strict';
import { once } from 'node:events';
import test from 'node:test';
import { io as ioc } from 'socket.io-client';
import { createTicketVerifier, extractHandshakeTicket } from '../src/auth.js';
import { parsePublishBody, EVENT_TYPES, buildEnvelope } from '../src/envelopes.js';
import {
  LIMITS,
  createRoomRateLimiter,
  createBoundedQueue,
  payloadWithinLimit,
} from '../src/limits.js';
import { createGateway, loadConfig } from '../src/server.js';

test('envelope and publish body validation', () => {
  const env = buildEnvelope(EVENT_TYPES.NOTIFY, {
    tenant_id: 't1',
    campaign_id: 'c1',
    seq_hint: 3,
  });
  assert.equal(env.v, 2);
  assert.equal(env.t, 'ka.notify');
  assert.ok(payloadWithinLimit(env));

  const bad = parsePublishBody({
    event: 'ka.notify',
    tenant_id: 't1',
    campaign_id: 'c1',
    rooms: ['tenant:other:campaign:c1:node:n1'],
  });
  assert.equal(bad.ok, false);
  assert.equal(bad.error, 'room_tenant_campaign_mismatch');

  const ok = parsePublishBody({
    event: 'ka.notify',
    tenant_id: 't1',
    campaign_id: 'c1',
    rooms: ['tenant:t1:campaign:c1:node:n1', 'tenant:t1:campaign:c1:node:n2'],
    seq_hint: 9,
  });
  assert.equal(ok.ok, true);
  assert.equal(ok.rooms.length, 2);
});

test('rate limiter and bounded queue', () => {
  let now = 1000;
  const lim = createRoomRateLimiter({ maxPerSecond: 2, now: () => now });
  assert.equal(lim.allow('r1'), true);
  assert.equal(lim.allow('r1'), true);
  assert.equal(lim.allow('r1'), false);
  now = 2200;
  assert.equal(lim.allow('r1'), true);

  const q = createBoundedQueue(2);
  q.push(1);
  q.push(2);
  q.push(3);
  assert.equal(q.length, 2);
  assert.equal(q.dropped, 1);
  assert.equal(q.shift(), 2);
});

test('ticket verifier consumes Laravel verify contract', async () => {
  const verifier = createTicketVerifier({
    verifyUrl: 'http://verify.test/socket/verify',
    gatewaySecret: 'sec',
    fetchImpl: async (_url, init) => {
      assert.equal(init.headers['X-KeepAlive-Gateway-Secret'], 'sec');
      const body = JSON.parse(String(init.body));
      assert.equal(body.consume, true);
      if (body.ticket === 'ka_sock_replay') {
        return new Response(JSON.stringify({ ok: false, error: 'socket_ticket_replay' }), {
          status: 401,
        });
      }
      if (body.ticket === 'ka_sock_stale') {
        return new Response(JSON.stringify({ ok: false, error: 'route_generation_stale' }), {
          status: 409,
        });
      }
      if (body.ticket === 'ka_sock_ok') {
        return new Response(
          JSON.stringify({
            ok: true,
            tenant_id: 'tenant-a',
            role: 'recovery',
            authorized_rooms: [
              'tenant:tenant-a:campaign:11111111-1111-1111-1111-111111111111:node:node-a',
            ],
            campaign_ids: ['11111111-1111-1111-1111-111111111111'],
            assignment_generation: 2,
            route_generation: 3,
            jti: 'abc',
          }),
          { status: 200 },
        );
      }
      return new Response(JSON.stringify({ ok: false, error: 'socket_ticket_invalid' }), {
        status: 401,
      });
    },
  });

  const ok = await verifier.verify('ka_sock_ok');
  assert.equal(ok.ok, true);
  assert.equal(ok.claims.rooms.length, 1);

  const replay = await verifier.verify('ka_sock_replay');
  assert.equal(replay.ok, false);
  assert.equal(replay.error, 'socket_ticket_replay');

  const stale = await verifier.verify('ka_sock_stale');
  assert.equal(stale.ok, false);
  assert.equal(stale.error, 'route_generation_stale');
});

test('extractHandshakeTicket never reads api keys', () => {
  const ticket = extractHandshakeTicket({
    handshake: {
      auth: { ticket: 'ka_sock_x', api_key: 'sak_should_ignore' },
      query: { api_key: 'sak_also_ignore' },
    },
  });
  assert.equal(ticket, 'ka_sock_x');
});

test('gateway connect + publish multi-room; reject bad secret and cross-tenant rooms', async () => {
  const rooms = [
    'tenant:tenant-a:campaign:camp-1:node:node-a',
    'tenant:tenant-a:campaign:camp-1:node:node-b',
  ];
  let consumeCount = 0;
  const gw = createGateway({
    config: {
      ...loadConfig({
        KA_GATEWAY_PORT: '0',
        KA_GATEWAY_HOST: '127.0.0.1',
        KA_GATEWAY_SECRET: 'gw-secret',
        KA_GATEWAY_PATH: '/keepalive/socket.io/',
      }),
      port: 0,
    },
    verifier: {
      async verify(ticket) {
        consumeCount += 1;
        if (ticket === 'ka_sock_used') {
          return { ok: false, error: 'socket_ticket_replay' };
        }
        if (ticket !== 'ka_sock_fresh' && ticket !== 'ka_sock_fresh2') {
          return { ok: false, error: 'socket_ticket_invalid' };
        }
        return {
          ok: true,
          claims: {
            tenant_id: 'tenant-a',
            role: 'executor',
            node_id: null,
            campaign_ids: ['camp-1'],
            rooms,
            assignment_generation: 1,
            route_generation: 1,
            jti: `jti-${consumeCount}`,
          },
        };
      },
    },
  });

  await gw.listen();
  const addr = gw.httpServer.address();
  const port = typeof addr === 'object' && addr ? addr.port : 0;
  assert.ok(port > 0);

  const received = [];
  const client = ioc(`http://127.0.0.1:${port}`, {
    path: '/keepalive/socket.io/',
    transports: ['websocket'],
    auth: { ticket: 'ka_sock_fresh' },
  });
  client.on('ka.notify', (p) => received.push(p));
  await once(client, 'connect');

  // Replay same logical ticket id path — verifier rejects reused ticket.
  const replayClient = ioc(`http://127.0.0.1:${port}`, {
    path: '/keepalive/socket.io/',
    transports: ['websocket'],
    auth: { ticket: 'ka_sock_used' },
    reconnection: false,
  });
  const [replayErr] = await once(replayClient, 'connect_error');
  assert.match(String(replayErr.message), /socket_ticket_replay/);
  replayClient.close();

  // Unauthorized publish
  const unauth = await fetch(`http://127.0.0.1:${port}/internal/publish`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', 'X-KeepAlive-Gateway-Secret': 'wrong' },
    body: JSON.stringify({
      event: 'ka.notify',
      tenant_id: 'tenant-a',
      campaign_id: 'camp-1',
      rooms,
      seq_hint: 1,
    }),
  });
  assert.equal(unauth.status, 401);

  // Cross-tenant room rejected
  const cross = await fetch(`http://127.0.0.1:${port}/internal/publish`, {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'X-KeepAlive-Gateway-Secret': 'gw-secret',
    },
    body: JSON.stringify({
      event: 'ka.notify',
      tenant_id: 'tenant-a',
      campaign_id: 'camp-1',
      rooms: ['tenant:tenant-b:campaign:camp-1:node:node-a'],
      seq_hint: 1,
    }),
  });
  assert.equal(cross.status, 422);

  // Valid multi-room publish
  const pub = await fetch(`http://127.0.0.1:${port}/internal/publish`, {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'X-KeepAlive-Gateway-Secret': 'gw-secret',
    },
    body: JSON.stringify({
      event: 'ka.notify',
      tenant_id: 'tenant-a',
      campaign_id: 'camp-1',
      rooms,
      seq_hint: 7,
    }),
  });
  assert.equal(pub.status, 200);
  const pubBody = await pub.json();
  assert.equal(pubBody.ok, true);
  assert.equal(pubBody.emitted, 2);

  await new Promise((r) => setTimeout(r, 50));
  assert.ok(received.length >= 1);
  assert.equal(received[0].seq_hint, 7);
  assert.ok(JSON.stringify(received[0]).length <= LIMITS.maxPayloadBytes);

  // Reconnect requires a fresh ticket
  client.close();
  const client2 = ioc(`http://127.0.0.1:${port}`, {
    path: '/keepalive/socket.io/',
    transports: ['websocket'],
    auth: { ticket: 'ka_sock_fresh2' },
  });
  await once(client2, 'connect');
  client2.close();

  const health = await fetch(`http://127.0.0.1:${port}/health`);
  assert.equal(health.status, 200);
  const healthBody = await health.json();
  assert.equal(healthBody.ok, true);
  assert.equal(healthBody.workers, 1);

  await gw.close();
});

test('payload size bound', () => {
  const huge = buildEnvelope(EVENT_TYPES.NOTIFY, {
    tenant_id: 't',
    campaign_id: 'c',
    padding: 'x'.repeat(5000),
  });
  assert.equal(payloadWithinLimit(huge), false);
});
