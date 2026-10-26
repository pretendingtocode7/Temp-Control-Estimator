/** Reconstructed from the saved v0.4.8 deployment bundle; not recovered original source. */
export const READ_TIMEOUT_MS = 10_000;
export const GENERATE_TIMEOUT_MS = 25_000;
export const UPDATE_TIMEOUT_MS = 30_000;
export const SEND_TIMEOUT_MS = 30_000;

export class ApiError extends Error {
  constructor(code, message, status, body) {
    super(message);
    this.name = "ApiError";
    this.code = code;
    this.status = status;
    this.body = body;
  }
}

export function createApi({ restUrl, nonce, ajaxUrl }) {
  let catalogWarmupPromise = null;
  if (!restUrl || !nonce) {
    throw new Error("createApi requires restUrl and nonce");
  }

  const restBaseUrl = restUrl.replace(/\/+$/, "");
  let currentNonce = nonce;

  function isNonceFailure(response, parsedBody, responseText) {
    if (![401, 403].includes(response.status)) return false;

    const code = parsedBody?.code || parsedBody?.error?.code || "";
    const message =
      parsedBody?.message || parsedBody?.error?.message || responseText || "";

    return (
      code === "rest_cookie_invalid_nonce" ||
      code === "tc_estimate_bad_nonce" ||
      /cookie check failed|invalid or missing nonce/i.test(message)
    );
  }

  async function refreshNonce() {
    if (!ajaxUrl) {
      throw new ApiError(
        "tc_estimate_nonce_refresh_unavailable",
        "Could not refresh the WordPress session token.",
        403,
        null,
      );
    }

    const form = new URLSearchParams();
    form.set("action", "tc_estimate_rest_nonce");
    const response = await fetchWithTimeout(
      ajaxUrl,
      {
        method: "POST",
        credentials: "same-origin",
        headers: { Accept: "application/json" },
        body: form,
      },
      READ_TIMEOUT_MS,
    );
    const responseText = await response.text();
    let parsedBody = null;
    if (responseText) {
      try {
        parsedBody = JSON.parse(responseText);
      } catch {}
    }

    if (!response.ok || !parsedBody?.success || !parsedBody?.data?.nonce) {
      throw new ApiError(
        parsedBody?.data?.code || `http_${response.status}`,
        parsedBody?.data?.message ||
          "Your WordPress session expired. Reload the page and try again.",
        response.status,
        parsedBody,
      );
    }

    // The saved client refreshes only the nonce, retaining its original REST URL.
    currentNonce = parsedBody.data.nonce;
    return currentNonce;
  }

  async function fetchWithTimeout(url, options, timeoutMs) {
    const controller = new AbortController();
    const timeout = setTimeout(() => controller.abort(), timeoutMs);
    try {
      return await fetch(url, { ...options, signal: controller.signal });
    } finally {
      // Preserve v0.4.8 behavior: the timeout covers fetch, not response.text().
      clearTimeout(timeout);
    }
  }

  async function request(
    method,
    path,
    { query, body, headers, timeoutMs } = {},
    mayRefreshNonce = true,
  ) {
    let url = restBaseUrl + path;
    if (query && Object.keys(query).length > 0) {
      const search = new URLSearchParams();
      for (const [key, value] of Object.entries(query)) {
        if (value != null && value !== "") search.set(key, String(value));
      }
      const queryString = search.toString();
      if (queryString) url += (url.includes("?") ? "&" : "?") + queryString;
    }

    const requestHeaders = {
      "X-WP-Nonce": currentNonce,
      Accept: "application/json",
      ...headers,
    };
    if (body !== undefined) requestHeaders["Content-Type"] = "application/json";

    const response = await fetchWithTimeout(
      url,
      {
        method,
        credentials: "same-origin",
        headers: requestHeaders,
        body: body === undefined ? undefined : JSON.stringify(body),
      },
      timeoutMs || READ_TIMEOUT_MS,
    );
    const responseText = await response.text();
    let parsedBody = null;
    if (responseText) {
      try {
        parsedBody = JSON.parse(responseText);
      } catch {}
    }

    if (!response.ok) {
      if (
        mayRefreshNonce &&
        isNonceFailure(response, parsedBody, responseText)
      ) {
        await refreshNonce();
        return request(
          method,
          path,
          { query, body, headers, timeoutMs },
          false,
        );
      }

      // Native WordPress error messages are recognized, but their top-level
      // codes are not used here by the saved client. Keep that observable quirk.
      const code = parsedBody?.error?.code || `http_${response.status}`;
      const message =
        parsedBody?.error?.message ||
        parsedBody?.message ||
        parsedBody?.error?.body ||
        parsedBody?.error?.deluge_output ||
        (responseText
          ? `Server returned ${response.status}: ${responseText
              .replace(/<[^>]*>/g, " ")
              .replace(/\s+/g, " ")
              .trim()
              .slice(0, 300)}`
          : null) ||
        `Request failed with status ${response.status}`;
      throw new ApiError(code, message, response.status, parsedBody);
    }

    if (parsedBody && typeof parsedBody === "object" && "ok" in parsedBody) {
      if (parsedBody.ok) return parsedBody.data;
      throw new ApiError(
        parsedBody.error?.code || "envelope_error",
        parsedBody.error?.message || "Envelope reported failure.",
        response.status,
        parsedBody,
      );
    }

    // Empty and non-JSON successful responses remain null, matching the bundle.
    return parsedBody;
  }

  async function requestWithReadRetry(method, path, options = {}) {
    const maxRetries = options.maxRetries ?? 2;
    let attempt = 0;
    let lastError;

    while (attempt <= maxRetries) {
      try {
        return await request(method, path, options);
      } catch (error) {
        lastError = error;
        const isTerminalClientError =
          error instanceof ApiError &&
          error.status &&
          error.status < 500 &&
          error.status !== 429 &&
          error.status !== 408;
        if (isTerminalClientError || attempt === maxRetries) throw error;

        const baseDelay = 200 * 2 ** attempt;
        const jitter = Math.random() * baseDelay * 0.3;
        await new Promise((resolve) => setTimeout(resolve, baseDelay + jitter));
        attempt++;
      }
    }
    throw lastError;
  }

  return {
    searchCustomers(query, limit = 20) {
      return requestWithReadRetry("GET", "/customers", {
        query: { q: query, limit },
      });
    },

    searchEquipment({ type, q, brand, limit = 50 } = {}) {
      const search = () =>
        requestWithReadRetry("GET", "/equipment", {
          query: { type, q, brand, limit },
        });

      // One unfiltered limit=1 request primes PHP's catalog cache. Its promise
      // remains cached even if rejected. Other searches wait, then fetch their
      // own result; they do not reuse the warmup's single returned item.
      if (!type && !q && !brand && limit === 1) {
        catalogWarmupPromise = catalogWarmupPromise || search();
        return catalogWarmupPromise;
      }
      return catalogWarmupPromise
        ? catalogWarmupPromise.catch(() => null).then(search)
        : search();
    },

    getEquipment(id) {
      return requestWithReadRetry(
        "GET",
        `/equipment/${encodeURIComponent(id)}`,
      );
    },

    searchEquipmentSelector({
      category = "package",
      q,
      btu,
      tons,
      page = 1,
      limit = 50,
    } = {}) {
      const search = () =>
        requestWithReadRetry("GET", "/equipment", {
          query: { selector: 1, category, q, btu, tons, page, limit },
        });
      return catalogWarmupPromise
        ? catalogWarmupPromise.catch(() => null).then(search)
        : search();
    },

    listTemplates(type) {
      return requestWithReadRetry("GET", "/templates", { query: { type } });
    },

    getTemplate(id) {
      return requestWithReadRetry(
        "GET",
        `/templates/${encodeURIComponent(id)}`,
      );
    },

    // Preview is non-creating, so the saved client retries it like a read even
    // though the server route is POST. Creating/updating/sending do not use it.
    preview(payload) {
      return requestWithReadRetry("POST", "/preview", { body: payload });
    },

    generate(payload, idempotencyKey) {
      if (!idempotencyKey) {
        throw new Error("generate() requires an idempotency key");
      }
      return request("POST", "/generate", {
        body: payload,
        headers: { "Idempotency-Key": idempotencyKey },
        timeoutMs: GENERATE_TIMEOUT_MS,
      });
    },

    updateEstimate(estimateId, booksEdits) {
      if (!estimateId) {
        throw new Error("updateEstimate() requires an estimate ID");
      }
      return request("POST", "/update-estimate", {
        body: { estimate_id: estimateId, books_edits: booksEdits },
        timeoutMs: UPDATE_TIMEOUT_MS,
      });
    },

    sendEstimate(estimateId, prettyAttachment = null) {
      if (!estimateId) {
        throw new Error("sendEstimate() requires an estimate ID");
      }
      return request("POST", "/send-estimate", {
        body: {
          estimate_id: estimateId,
          ...(prettyAttachment ? { pretty_attachment: prettyAttachment } : {}),
        },
        timeoutMs: SEND_TIMEOUT_MS,
      });
    },

    sendStatus(jobId) {
      if (!jobId) throw new Error("sendStatus() requires a job ID");
      return requestWithReadRetry("GET", "/send-status", {
        query: { job_id: jobId },
        maxRetries: 1,
      });
    },
  };
}
