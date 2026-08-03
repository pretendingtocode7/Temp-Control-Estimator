/**
 * REST API client.
 *
 * Pillars 20-25: timeouts (10s default), bounded retries (3 max),
 * exponential backoff with jitter. Matches the server-side Zoho_API posture.
 *
 * Every mutating call to /generate must carry an Idempotency-Key header.
 * The key is allocated once per estimate attempt by the App state machine —
 * see useEstimateDraft() in App.jsx.
 */

const DEFAULT_TIMEOUT_MS = 10_000;
const GENERATE_TIMEOUT_MS = 25_000; // Zoho function execute can legitimately take 8–12s.

export class ApiError extends Error {
  constructor(code, message, status, body) {
    super(message);
    this.name = 'ApiError';
    this.code = code;
    this.status = status;
    this.body = body;
  }
}

export function createApi({ restUrl, nonce, ajaxUrl }) {
  if (!restUrl || !nonce) {
    throw new Error('createApi requires restUrl and nonce');
  }

  // Trim trailing slash for consistent URL composition.
  const base = restUrl.replace(/\/+$/, '');
  let currentNonce = nonce;

  function isNonceFailure(res, parsed, text) {
    if (![401, 403].includes(res.status)) return false;
    const code = parsed?.code || parsed?.error?.code || '';
    const message = parsed?.message || parsed?.error?.message || text || '';
    return (
      code === 'rest_cookie_invalid_nonce' ||
      code === 'tc_estimate_bad_nonce' ||
      /cookie check failed|invalid or missing nonce/i.test(message)
    );
  }

  async function refreshNonce() {
    if (!ajaxUrl) {
      throw new ApiError('tc_estimate_nonce_refresh_unavailable', 'Could not refresh the WordPress session token.', 403, null);
    }
    const form = new URLSearchParams();
    form.set('action', 'tc_estimate_rest_nonce');
    const res = await fetchWithTimeout(
      ajaxUrl,
      {
        method: 'POST',
        credentials: 'same-origin',
        headers: { Accept: 'application/json' },
        body: form,
      },
      DEFAULT_TIMEOUT_MS
    );
    const text = await res.text();
    let parsed = null;
    if (text) {
      try {
        parsed = JSON.parse(text);
      } catch {
        // Leave parsed null.
      }
    }
    if (!res.ok || !parsed?.success || !parsed?.data?.nonce) {
      throw new ApiError(
        parsed?.data?.code || `http_${res.status}`,
        parsed?.data?.message || 'Your WordPress session expired. Reload the page and try again.',
        res.status,
        parsed
      );
    }
    currentNonce = parsed.data.nonce;
    return currentNonce;
  }

  async function fetchWithTimeout(url, opts, timeoutMs) {
    const controller = new AbortController();
    const timer = setTimeout(() => controller.abort(), timeoutMs);
    try {
      const res = await fetch(url, { ...opts, signal: controller.signal });
      return res;
    } finally {
      clearTimeout(timer);
    }
  }

  async function requestOnce(method, path, { query, body, headers, timeoutMs } = {}, allowNonceRefresh = true) {
    let url = base + path;
    if (query && Object.keys(query).length > 0) {
      const qs = new URLSearchParams();
      for (const [k, v] of Object.entries(query)) {
        if (v === undefined || v === null || v === '') continue;
        qs.set(k, String(v));
      }
      const qsStr = qs.toString();
      if (qsStr) url += (url.includes('?') ? '&' : '?') + qsStr;
    }

    const finalHeaders = {
      'X-WP-Nonce': currentNonce,
      Accept: 'application/json',
      ...headers,
    };
    if (body !== undefined) {
      finalHeaders['Content-Type'] = 'application/json';
    }

    const res = await fetchWithTimeout(
      url,
      {
        method,
        credentials: 'same-origin',
        headers: finalHeaders,
        body: body === undefined ? undefined : JSON.stringify(body),
      },
      timeoutMs || DEFAULT_TIMEOUT_MS
    );

    const text = await res.text();
    let parsed = null;
    if (text) {
      try {
        parsed = JSON.parse(text);
      } catch {
        // Non-JSON body — leave parsed as null.
      }
    }

    if (!res.ok) {
      if (allowNonceRefresh && isNonceFailure(res, parsed, text)) {
        await refreshNonce();
        return requestOnce(method, path, { query, body, headers, timeoutMs }, false);
      }
      const code = parsed?.error?.code || `http_${res.status}`;
      const msg =
        parsed?.error?.message ||
        parsed?.message ||
        parsed?.error?.body ||
        parsed?.error?.deluge_output ||
        `Request failed with status ${res.status}`;
      throw new ApiError(code, msg, res.status, parsed);
    }
    // The server-side envelope is { ok: true, data: ... } on success.
    if (parsed && typeof parsed === 'object' && 'ok' in parsed) {
      if (parsed.ok) return parsed.data;
      throw new ApiError(
        parsed.error?.code || 'envelope_error',
        parsed.error?.message || 'Envelope reported failure.',
        res.status,
        parsed
      );
    }
    return parsed;
  }

  async function requestWithRetry(method, path, opts = {}) {
    const maxRetries = opts.maxRetries ?? 2; // 3 total attempts
    let attempt = 0;
    let lastErr;
    while (attempt <= maxRetries) {
      try {
        return await requestOnce(method, path, opts);
      } catch (err) {
        lastErr = err;
        // Don't retry authz/validation/4xx (except 429 and 408).
        if (err instanceof ApiError && err.status) {
          if (err.status < 500 && err.status !== 429 && err.status !== 408) {
            throw err;
          }
        }
        if (attempt === maxRetries) throw err;
        const delayBase = 200 * 2 ** attempt;
        const jitter = Math.random() * delayBase * 0.3;
        await new Promise((r) => setTimeout(r, delayBase + jitter));
        attempt++;
      }
    }
    throw lastErr;
  }

  return {
    // Customers
    searchCustomers(q, limit = 20) {
      return requestWithRetry('GET', '/customers', { query: { q, limit } });
    },

    // Equipment catalog
    searchEquipment({ type, q, brand, limit = 50 } = {}) {
      return requestWithRetry('GET', '/equipment', { query: { type, q, brand, limit } });
    },
    getEquipment(id) {
      return requestWithRetry('GET', `/equipment/${encodeURIComponent(id)}`);
    },

    // Templates
    listTemplates(type) {
      return requestWithRetry('GET', '/templates', { query: { type } });
    },
    getTemplate(id) {
      return requestWithRetry('GET', `/templates/${encodeURIComponent(id)}`);
    },

    // Preview (no Zoho writes)
    preview(payload) {
      return requestWithRetry('POST', '/preview', { body: payload });
    },

    // Generate — NOT retried by the client; the server handles retry & idempotency.
    generate(payload, idempotencyKey) {
      if (!idempotencyKey) throw new Error('generate() requires an idempotency key');
      return requestOnce('POST', '/generate', {
        body: payload,
        headers: { 'Idempotency-Key': idempotencyKey },
        timeoutMs: GENERATE_TIMEOUT_MS,
      });
    },

    // Explicit customer-email step after the technician previews the Books draft.
    sendEstimate(estimateId) {
      if (!estimateId) throw new Error('sendEstimate() requires an estimate ID');
      return requestOnce('POST', '/send-estimate', {
        body: { estimate_id: estimateId },
        timeoutMs: GENERATE_TIMEOUT_MS,
      });
    },
  };
}
