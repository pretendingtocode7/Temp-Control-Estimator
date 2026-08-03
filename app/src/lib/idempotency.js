/**
 * Idempotency-Key generator.
 *
 * Policy:
 *   - One key per estimate attempt, allocated at Review step entry.
 *   - Reused across retries of the same attempt so the server replays the cached result.
 *   - Cleared when: success, explicit reset, or the user abandons the draft.
 */

function randomUuidV4() {
  // Prefer native crypto.randomUUID where available.
  if (typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function') {
    return crypto.randomUUID();
  }
  // Fallback: build v4 UUID from getRandomValues.
  const bytes = new Uint8Array(16);
  if (typeof crypto !== 'undefined' && typeof crypto.getRandomValues === 'function') {
    crypto.getRandomValues(bytes);
  } else {
    // Last resort — Math.random. Should not be reachable in a modern browser.
    for (let i = 0; i < 16; i++) bytes[i] = Math.floor(Math.random() * 256);
  }
  // Per RFC 4122 §4.4: set version (0100) and variant (10xx).
  bytes[6] = (bytes[6] & 0x0f) | 0x40;
  bytes[8] = (bytes[8] & 0x3f) | 0x80;
  const hex = Array.from(bytes, (b) => b.toString(16).padStart(2, '0')).join('');
  return `${hex.slice(0, 8)}-${hex.slice(8, 12)}-${hex.slice(12, 16)}-${hex.slice(16, 20)}-${hex.slice(20)}`;
}

export function newIdempotencyKey(prefix = 'tc') {
  return `${prefix}-${randomUuidV4()}`;
}
