/** Keep Alive Socket envelope helpers (schema v2). */

export const ENVELOPE_VERSION = 2;

export const EVENT_TYPES = Object.freeze({
  NOTIFY: 'ka.notify',
  ASSIGNMENT_CHANGED: 'ka.assignment_changed',
  ROUTE_INVALIDATED: 'ka.route_invalidated',
  RECOVER_HINT: 'ka.recover_hint',
});

const ALLOWED = new Set(Object.values(EVENT_TYPES));

/**
 * @param {string} type
 * @param {Record<string, unknown>} fields
 */
export function buildEnvelope(type, fields) {
  if (!ALLOWED.has(type)) {
    throw new Error('envelope_type_invalid');
  }
  return {
    v: ENVELOPE_VERSION,
    t: type,
    ts: new Date().toISOString(),
    ...fields,
  };
}

/**
 * Validate server publish body. Client-supplied rooms are never trusted from sockets —
 * publish path is server-to-gateway only.
 * @param {unknown} body
 * @returns {{ ok: true, event: string, rooms: string[], envelope: Record<string, unknown> } | { ok: false, error: string }}
 */
export function parsePublishBody(body) {
  if (!body || typeof body !== 'object') {
    return { ok: false, error: 'publish_invalid' };
  }
  const event = String(/** @type {any} */ (body).event || /** @type {any} */ (body).t || '');
  if (!ALLOWED.has(event)) {
    return { ok: false, error: 'event_type_invalid' };
  }
  const roomsRaw = /** @type {any} */ (body).rooms;
  if (!Array.isArray(roomsRaw) || roomsRaw.length === 0) {
    return { ok: false, error: 'rooms_required' };
  }
  const rooms = [];
  for (const r of roomsRaw) {
    const room = String(r || '').trim();
    if (!room.startsWith('tenant:') || !room.includes(':campaign:') || !room.includes(':node:')) {
      return { ok: false, error: 'room_format_invalid' };
    }
    rooms.push(room);
  }
  const tenantId = String(/** @type {any} */ (body).tenant_id || '');
  const campaignId = String(/** @type {any} */ (body).campaign_id || '');
  if (!tenantId || !campaignId) {
    return { ok: false, error: 'tenant_campaign_required' };
  }
  for (const room of rooms) {
    if (!room.startsWith(`tenant:${tenantId}:campaign:${campaignId}:`)) {
      return { ok: false, error: 'room_tenant_campaign_mismatch' };
    }
  }

  const envelope = buildEnvelope(event, {
    tenant_id: tenantId,
    campaign_id: campaignId,
    node_id: /** @type {any} */ (body).node_id ?? null,
    seq_hint: /** @type {any} */ (body).seq_hint ?? null,
    route_generation: /** @type {any} */ (body).route_generation ?? null,
    assignment_generation: /** @type {any} */ (body).assignment_generation ?? null,
    reason: /** @type {any} */ (body).reason ?? null,
  });

  return { ok: true, event, rooms, envelope };
}
