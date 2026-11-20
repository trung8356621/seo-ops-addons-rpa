import http from 'node:http';
import { pathToFileURL } from 'node:url';
import { Server } from 'socket.io';
import { createTicketVerifier, extractHandshakeTicket } from './auth.js';
import { parsePublishBody, EVENT_TYPES } from './envelopes.js';
import {
  LIMITS,
  createRoomRateLimiter,
  createBoundedQueue,
  payloadWithinLimit,
} from './limits.js';

/**
 * @param {Record<string, string|undefined>} env
 */
export function loadConfig(env = process.env) {
  const port = Number(env.KA_GATEWAY_PORT || 3015);
  const path = env.KA_GATEWAY_PATH || '/keepalive/socket.io/';
  const verifyUrl =
    env.KA_GATEWAY_VERIFY_URL ||
    'http://127.0.0.1/api/v1/social-automation/keepalive-gateway/socket/verify';
  const gatewaySecret = env.KA_GATEWAY_SECRET || '';
  const publishSecret = env.KA_GATEWAY_PUBLISH_SECRET || gatewaySecret;
  const host = env.KA_GATEWAY_HOST || '127.0.0.1';

  return { port, path, verifyUrl, gatewaySecret, publishSecret, host };
}

/**
 * Create gateway app (http + socket.io). Injectable for tests.
 * @param {object} opts
 * @param {ReturnType<typeof loadConfig>} opts.config
 * @param {ReturnType<typeof createTicketVerifier>} [opts.verifier]
 * @param {(input: string|URL|Request, init?: RequestInit) => Promise<Response>} [opts.fetchImpl]
 */
export function createGateway({ config, verifier, fetchImpl = fetch }) {
  const ticketVerifier =
    verifier ||
    createTicketVerifier({
      verifyUrl: config.verifyUrl,
      gatewaySecret: config.gatewaySecret,
      fetchImpl,
    });

  const rateLimiter = createRoomRateLimiter();
  const publishQueue = createBoundedQueue(LIMITS.maxQueuedPublishes);
  /** @type {Map<string, { tenant_id: string, role: string, rooms: string[], connected_at: number }>} */
  const sessions = new Map();

  const metrics = {
    connected: 0,
    ticket_ok: 0,
    ticket_reject: 0,
    publish_ok: 0,
    publish_reject: 0,
    emit_coalesced: 0,
    emit_dropped_rate: 0,
    started_at: Date.now(),
  };

  const httpServer = http.createServer((req, res) => {
    const url = new URL(req.url || '/', `http://${req.headers.host || 'localhost'}`);

    if (req.method === 'GET' && url.pathname === '/health') {
      const mem = process.memoryUsage();
      writeJson(res, 200, {
        ok: true,
        service: 'keepalive-gateway',
        workers: 1,
        connected: metrics.connected,
        uptime_ms: Date.now() - metrics.started_at,
        queue_length: publishQueue.length,
        queue_dropped: publishQueue.dropped,
        memory: {
          rss: mem.rss,
          heapUsed: mem.heapUsed,
          heapTotal: mem.heapTotal,
        },
        metrics,
      });
      return;
    }

    if (req.method === 'POST' && url.pathname === '/internal/publish') {
      void handlePublish(req, res);
      return;
    }

    writeJson(res, 404, { ok: false, error: 'not_found' });
  });

  const io = new Server(httpServer, {
    path: config.path,
    transports: ['websocket'],
    allowUpgrades: false,
    cors: { origin: false },
    maxHttpBufferSize: LIMITS.maxPayloadBytes,
  });

  io.use(async (socket, next) => {
    try {
      const ticket = extractHandshakeTicket(socket);
      if (!ticket) {
        metrics.ticket_reject += 1;
        return next(new Error('socket_ticket_invalid'));
      }
      const result = await ticketVerifier.verify(ticket);
      if (!result.ok) {
        metrics.ticket_reject += 1;
        return next(new Error(result.error));
      }
      const claims = result.claims;
      socket.data.ka = claims;
      metrics.ticket_ok += 1;
      next();
    } catch {
      metrics.ticket_reject += 1;
      next(new Error('socket_ticket_invalid'));
    }
  });

  io.on('connection', (socket) => {
    const claims = socket.data.ka;
    const rooms = Array.isArray(claims?.rooms) ? claims.rooms : [];
    metrics.connected += 1;
    sessions.set(socket.id, {
      tenant_id: String(claims.tenant_id || ''),
      role: String(claims.role || ''),
      rooms,
      connected_at: Date.now(),
    });

    for (const room of rooms) {
      void socket.join(room);
    }

    // Clients never dictate rooms. Ignore join/leave attempts.
    socket.on('join', () => {
      socket.emit(EVENT_TYPES.ROUTE_INVALIDATED, {
        v: 2,
        t: EVENT_TYPES.ROUTE_INVALIDATED,
        reason: 'client_room_control_forbidden',
        ts: new Date().toISOString(),
      });
    });

    socket.on('disconnect', () => {
      metrics.connected = Math.max(0, metrics.connected - 1);
      sessions.delete(socket.id);
    });
  });

  /**
   * @param {import('node:http').IncomingMessage} req
   * @param {import('node:http').ServerResponse} res
   */
  async function handlePublish(req, res) {
    const secret = String(req.headers['x-keepalive-gateway-secret'] || '');
    if (!config.publishSecret || secret !== config.publishSecret) {
      metrics.publish_reject += 1;
      writeJson(res, 401, { ok: false, error: 'gateway_unauthorized' });
      return;
    }

    let body;
    try {
      body = await readJson(req);
    } catch {
      metrics.publish_reject += 1;
      writeJson(res, 422, { ok: false, error: 'publish_invalid' });
      return;
    }

    const parsed = parsePublishBody(body);
    if (!parsed.ok) {
      metrics.publish_reject += 1;
      writeJson(res, 422, { ok: false, error: parsed.error });
      return;
    }
    if (parsed.rooms.length > LIMITS.maxPublishRooms) {
      metrics.publish_reject += 1;
      writeJson(res, 422, { ok: false, error: 'too_many_rooms' });
      return;
    }
    if (!payloadWithinLimit(parsed.envelope)) {
      metrics.publish_reject += 1;
      writeJson(res, 422, { ok: false, error: 'payload_too_large' });
      return;
    }

    let emitted = 0;
    let rateLimited = 0;
    for (const room of parsed.rooms) {
      if (!rateLimiter.allow(room)) {
        rateLimited += 1;
        metrics.emit_dropped_rate += 1;
        continue;
      }
      io.to(room).emit(parsed.event, parsed.envelope);
      emitted += 1;
    }

    metrics.publish_ok += 1;
    writeJson(res, 200, {
      ok: true,
      emitted,
      rate_limited: rateLimited,
      event: parsed.event,
    });
  }

  return {
    httpServer,
    io,
    metrics,
    sessions,
    publishQueue,
    rateLimiter,
    async listen() {
      await new Promise((resolve) => {
        httpServer.listen(config.port, config.host, resolve);
      });
      return { host: config.host, port: config.port, path: config.path };
    },
    async close() {
      await new Promise((resolve) => {
        io.close(() => resolve(undefined));
      });
      if (httpServer.listening) {
        await new Promise((resolve, reject) => {
          httpServer.close((err) => (err ? reject(err) : resolve(undefined)));
        });
      }
    },
  };
}

/**
 * @param {import('node:http').IncomingMessage} req
 */
function readJson(req) {
  return new Promise((resolve, reject) => {
    const chunks = [];
    let size = 0;
    req.on('data', (c) => {
      size += c.length;
      if (size > LIMITS.maxPayloadBytes * 4) {
        reject(new Error('body_too_large'));
        req.destroy();
        return;
      }
      chunks.push(c);
    });
    req.on('end', () => {
      try {
        resolve(JSON.parse(Buffer.concat(chunks).toString('utf8') || '{}'));
      } catch (e) {
        reject(e);
      }
    });
    req.on('error', reject);
  });
}

/**
 * @param {import('node:http').ServerResponse} res
 * @param {number} status
 * @param {unknown} body
 */
function writeJson(res, status, body) {
  const raw = JSON.stringify(body);
  res.writeHead(status, {
    'Content-Type': 'application/json',
    'Content-Length': Buffer.byteLength(raw),
  });
  res.end(raw);
}

async function main() {
  const config = loadConfig();
  if (!config.gatewaySecret) {
    console.error('[keepalive-gateway] KA_GATEWAY_SECRET is required');
    process.exit(1);
  }
  const gw = createGateway({ config });
  const addr = await gw.listen();
  console.log(
    `[keepalive-gateway] listening http://${addr.host}:${addr.port} path=${addr.path} (single worker)`,
  );

  const shutdown = async (signal) => {
    console.log(`[keepalive-gateway] ${signal} — closing`);
    try {
      await gw.close();
      process.exit(0);
    } catch (e) {
      console.error(e);
      process.exit(1);
    }
  };
  process.on('SIGINT', () => void shutdown('SIGINT'));
  process.on('SIGTERM', () => void shutdown('SIGTERM'));
}

const entry = process.argv[1] ? pathToFileURL(process.argv[1]).href : '';
if (entry === import.meta.url || process.env.KA_GATEWAY_RUN === '1') {
  void main();
}
