import { LIMITS } from './limits.js';

/**
 * @param {object} opts
 * @param {string} opts.verifyUrl
 * @param {string} opts.gatewaySecret
 * @param {(input: string|URL|Request, init?: RequestInit) => Promise<Response>} [opts.fetchImpl]
 * @param {number} [opts.timeoutMs]
 */
export function createTicketVerifier({
  verifyUrl,
  gatewaySecret,
  fetchImpl = fetch,
  timeoutMs = LIMITS.verifyTimeoutMs,
}) {
  if (!verifyUrl || !gatewaySecret) {
    throw new Error('verify_config_required');
  }

  return {
    /**
     * @param {string} ticket
     * @returns {Promise<{ ok: true, claims: Record<string, unknown> } | { ok: false, error: string, http?: number }>}
     */
    async verify(ticket) {
      const trimmed = String(ticket || '').trim();
      if (!trimmed.startsWith('ka_sock_')) {
        return { ok: false, error: 'socket_ticket_invalid' };
      }

      const ac = new AbortController();
      const timer = setTimeout(() => ac.abort(), timeoutMs);
      try {
        const res = await fetchImpl(verifyUrl, {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json',
            'X-KeepAlive-Gateway-Secret': gatewaySecret,
          },
          body: JSON.stringify({ ticket: trimmed, consume: true }),
          signal: ac.signal,
        });
        /** @type {any} */
        let body = null;
        try {
          body = await res.json();
        } catch {
          body = null;
        }
        if (!res.ok || !body?.ok) {
          const err = String(body?.error || body?.errors?.[0] || 'socket_ticket_invalid');
          return { ok: false, error: err, http: res.status };
        }
        const rooms = Array.isArray(body.authorized_rooms) ? body.authorized_rooms.map(String) : [];
        if (rooms.length === 0 || rooms.length > LIMITS.maxRoomsPerTicket) {
          return { ok: false, error: 'authorized_rooms_invalid' };
        }
        return {
          ok: true,
          claims: {
            tenant_id: String(body.tenant_id || ''),
            role: String(body.role || ''),
            node_id: body.node_id ?? null,
            campaign_ids: Array.isArray(body.campaign_ids) ? body.campaign_ids.map(String) : [],
            rooms,
            assignment_generation: Number(body.assignment_generation || 0),
            route_generation: Number(body.route_generation || 0),
            jti: String(body.jti || ''),
          },
        };
      } catch (e) {
        const name = e && typeof e === 'object' && 'name' in e ? String(e.name) : '';
        return { ok: false, error: name === 'AbortError' ? 'verify_timeout' : 'verify_unreachable' };
      } finally {
        clearTimeout(timer);
      }
    },
  };
}

/**
 * Extract ticket from Socket.IO handshake. Never reads API keys.
 * @param {any} socket
 * @returns {string}
 */
export function extractHandshakeTicket(socket) {
  const auth = socket?.handshake?.auth || {};
  const query = socket?.handshake?.query || {};
  const fromAuth = auth.ticket || auth.socket_ticket;
  if (fromAuth) {
    return String(fromAuth);
  }
  if (query.ticket) {
    return String(query.ticket);
  }
  return '';
}
