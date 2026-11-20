/** Bounds for Keep Alive Socket.IO data plane (advisory only). */

export const LIMITS = Object.freeze({
  maxPayloadBytes: 2048,
  maxEmitsPerSecondPerRoom: 10,
  maxQueuedPublishes: 1000,
  verifyTimeoutMs: 3000,
  publishTimeoutMs: 2000,
  maxRoomsPerTicket: 64,
  maxPublishRooms: 128,
});

/**
 * Token-bucket style room rate limiter.
 */
export function createRoomRateLimiter({
  maxPerSecond = LIMITS.maxEmitsPerSecondPerRoom,
  now = () => Date.now(),
} = {}) {
  /** @type {Map<string, { windowStart: number, count: number }>} */
  const windows = new Map();

  return {
    /**
     * @param {string} room
     * @returns {boolean} true if allowed
     */
    allow(room) {
      const t = now();
      const row = windows.get(room);
      if (!row || t - row.windowStart >= 1000) {
        windows.set(room, { windowStart: t, count: 1 });
        return true;
      }
      if (row.count >= maxPerSecond) {
        return false;
      }
      row.count += 1;
      return true;
    },
    size() {
      return windows.size;
    },
    clear() {
      windows.clear();
    },
  };
}

/**
 * Bounded FIFO queue; drops oldest on overflow.
 * @template T
 */
export function createBoundedQueue(max = LIMITS.maxQueuedPublishes) {
  /** @type {T[]} */
  const items = [];
  let dropped = 0;

  return {
    /** @param {T} item */
    push(item) {
      if (items.length >= max) {
        items.shift();
        dropped += 1;
      }
      items.push(item);
    },
    /** @returns {T|undefined} */
    shift() {
      return items.shift();
    },
    get length() {
      return items.length;
    },
    get dropped() {
      return dropped;
    },
    clear() {
      items.length = 0;
    },
  };
}

/**
 * @param {unknown} payload
 * @returns {boolean}
 */
export function payloadWithinLimit(payload) {
  try {
    const size = Buffer.byteLength(JSON.stringify(payload), 'utf8');
    return size <= LIMITS.maxPayloadBytes;
  } catch {
    return false;
  }
}
