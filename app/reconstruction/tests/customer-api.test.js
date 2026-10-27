import { afterEach, describe, expect, it, vi } from "vitest";
import {
  createCustomerChoicesApi,
  CustomerChoicesError,
} from "../src/lib/customer-choices-api.js";

const token = `${"a".repeat(32)}.${"b".repeat(64)}`;
const requestId = "10000000-0000-4000-8000-000000000001";
const selections = [
  { system_number: 1, choice_id: "20000000-0000-4000-8000-000000000001" },
];
const restUrl = `${window.location.origin}/wp-json/tc-estimate/v1`;
function response(data, status = 200) {
  return new Response(JSON.stringify({ ok: true, data }), {
    status,
    headers: { "Content-Type": "application/json" },
  });
}
async function rejection(promise) {
  try {
    await promise;
  } catch (error) {
    return error;
  }
  throw new Error("Expected a safe customer API error");
}
afterEach(() => {
  vi.unstubAllGlobals();
  vi.restoreAllMocks();
});

describe("public customer choice API", () => {
  it("posts only the fragment token for viewing with no cookies, cache, redirects or staff nonce", async () => {
    const logs = ["log", "warn", "error", "info", "debug"].map((method) =>
      vi.spyOn(console, method).mockImplementation(() => {}),
    );
    const storage = vi.spyOn(Storage.prototype, "setItem");
    const view = { revision: 1, state: "ready", systems: [], total: 0 };
    const fetch = vi.fn().mockResolvedValue(response(view));
    vi.stubGlobal("fetch", fetch);
    const api = createCustomerChoicesApi({ restUrl: restUrl + "/" });
    await expect(api.view(token)).resolves.toMatchObject(view);
    expect(fetch).toHaveBeenCalledTimes(1);
    const [url, options] = fetch.mock.calls[0];
    expect(url).toBe(`${restUrl}/customer-choices/view`);
    expect(url).not.toContain(token);
    expect(options).toMatchObject({
      method: "POST",
      credentials: "omit",
      mode: "same-origin",
      cache: "no-store",
      redirect: "error",
    });
    expect(JSON.parse(options.body)).toEqual({ token });
    const headers = new Headers(options.headers);
    expect(headers.get("Content-Type")).toMatch(/application\/json/);
    expect(headers.has("X-WP-Nonce")).toBe(false);
    expect(headers.has("Authorization")).toBe(false);
    expect(storage).not.toHaveBeenCalled();
    for (const log of logs)
      expect(JSON.stringify(log.mock.calls)).not.toContain(token);
  });

  it("sends one explicit apply operation with only allowed selection identity fields", async () => {
    const saved = {
      saved: true,
      state: "books_updated",
      revision: 2,
      selections,
      total: 5000,
      review_url: "https://books.zoho.com/portal/quote/fixture",
    };
    const fetch = vi.fn().mockResolvedValue(response(saved));
    vi.stubGlobal("fetch", fetch);
    const api = createCustomerChoicesApi({ restUrl });
    await expect(
      api.apply({
        token,
        revision: 1,
        request_id: requestId,
        selections: [
          {
            ...selections[0],
            purchase_cost: 1234,
            equipment: { internal: "private" },
          },
        ],
        purchase_cost: 999,
      }),
    ).resolves.toMatchObject(saved);
    const [url, options] = fetch.mock.calls[0];
    expect(url).toBe(`${restUrl}/customer-choices/apply`);
    expect(options).toMatchObject({
      method: "POST",
      credentials: "omit",
      mode: "same-origin",
      cache: "no-store",
      redirect: "error",
    });
    expect(JSON.parse(options.body)).toEqual({
      token,
      revision: 1,
      request_id: requestId,
      selections,
    });
    expect(options.body).not.toContain("purchase_cost");
    expect(options.body).not.toContain("private");
    expect(fetch).toHaveBeenCalledTimes(1);
  });

  it("returns a pending response without resubmitting or polling", async () => {
    const pending = {
      saved: false,
      state: "pending",
      revision: 1,
      request_id: requestId,
      selections,
    };
    const fetch = vi.fn().mockResolvedValue(response(pending, 202));
    vi.stubGlobal("fetch", fetch);
    const api = createCustomerChoicesApi({ restUrl });
    await expect(
      api.apply({ token, revision: 1, request_id: requestId, selections }),
    ).resolves.toMatchObject(pending);
    await Promise.resolve();
    expect(fetch).toHaveBeenCalledTimes(1);
  });

  it.each([
    ["unavailable", 401],
    ["changed", 409],
    ["stale_revision", 409],
    ["bad_selection", 400],
    ["in_flight", 409],
    ["rate_limited", 429],
    ["provider", 502],
  ])(
    "normalizes %s errors without exposing provider messages or token-bearing response bodies",
    async (code, status) => {
      const raw = `RAW PROVIDER MESSAGE ${token}`;
      const fetch = vi.fn().mockResolvedValue(
        new Response(
          JSON.stringify({
            ok: false,
            error: {
              code: `tc_estimate_choices_${code}`,
              message: raw,
              body: { private: raw },
            },
          }),
          { status },
        ),
      );
      vi.stubGlobal("fetch", fetch);
      const api = createCustomerChoicesApi({ restUrl });
      const error = await rejection(
        api.apply({ token, revision: 1, request_id: requestId, selections }),
      );
      expect(error).toBeInstanceOf(CustomerChoicesError);
      expect(error.code).toBe(code);
      expect(error.status).toBe(status);
      expect(String(error)).not.toContain(raw);
      expect(String(error)).not.toContain(token);
      expect(JSON.stringify(error)).not.toContain(token);
      for (const key of ["body", "response", "data", "token"])
        expect(error).not.toHaveProperty(key);
      expect(fetch).toHaveBeenCalledTimes(1);
    },
  );

  it.each(["network", "malformed"])(
    "does not retry an uncertain %s apply or surface its raw failure",
    async (failure) => {
      const raw = `SECRET INTERNAL DIAGNOSTIC ${token}`;
      const fetch =
        failure === "network"
          ? vi.fn().mockRejectedValue(new Error(raw))
          : vi.fn().mockResolvedValue(new Response(raw, { status: 503 }));
      vi.stubGlobal("fetch", fetch);
      const api = createCustomerChoicesApi({ restUrl });
      const error = await rejection(
        api.apply({ token, revision: 1, request_id: requestId, selections }),
      );
      expect(error).toBeInstanceOf(CustomerChoicesError);
      expect(String(error)).not.toContain(raw);
      expect(JSON.stringify(error)).not.toContain(token);
      await Promise.resolve();
      expect(fetch).toHaveBeenCalledTimes(1);
    },
  );
});
