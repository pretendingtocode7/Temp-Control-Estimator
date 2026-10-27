// @vitest-environment node
import { readFileSync } from "node:fs";
import { runInNewContext } from "node:vm";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

const source = readFileSync(
  new URL("../src/service-worker.js", import.meta.url),
  "utf8",
);
const ORIGIN = "https://example.test";
const DATA_CACHE = "tc-est-data-0.4.8";
const SHELL_CACHE = "tc-est-shell-0.4.8";
const catalogUrl = `${ORIGIN}/wp-json/tc-estimate/v1/equipment?q=furnace&limit=50`;

function deferred() {
  let resolve;
  const promise = new Promise((yes) => {
    resolve = yes;
  });
  return { promise, resolve };
}

// Execute the entire classic worker. Its only network boundary is this test double.
function loadWorker(fetchImplementation = async () => new Response("network")) {
  const listeners = new Map();
  const stores = new Map();
  const cacheObjects = new Map();
  const fetch = vi.fn(fetchImplementation);
  const self = {
    addEventListener: vi.fn((type, listener) => listeners.set(type, listener)),
    skipWaiting: vi.fn(async () => {}),
    clients: { claim: vi.fn(async () => {}) },
  };
  function cache(name) {
    if (!stores.has(name)) stores.set(name, new Map());
    if (!cacheObjects.has(name)) {
      const store = stores.get(name);
      cacheObjects.set(name, {
        put: vi.fn(async (request, response) => {
          store.set(request.url, response.clone());
        }),
        match: vi.fn(async (request) => store.get(request.url)?.clone()),
      });
    }
    return cacheObjects.get(name);
  }
  const caches = {
    open: vi.fn(async (name) => cache(name)),
    keys: vi.fn(async () => [...stores.keys()]),
    delete: vi.fn(async (name) => stores.delete(name)),
  };
  runInNewContext(source, {
    self,
    caches,
    fetch,
    URL,
    Response,
    AbortController,
    setTimeout,
    clearTimeout,
  });
  return {
    self,
    caches,
    fetch,
    cache,
    listeners,
    seed(name, url, response = new Response("cached")) {
      cache(name);
      stores.get(name).set(url, response);
    },
    async lifecycle(type) {
      const work = [];
      listeners.get(type)({ waitUntil: (promise) => work.push(promise) });
      await Promise.all(work);
      return work;
    },
    message(data) {
      listeners.get("message")({ data });
    },
    request(url, method = "GET") {
      const request = new Request(url, { method });
      let response;
      const respondWith = vi.fn((value) => {
        response = Promise.resolve(value);
      });
      listeners.get("fetch")({ request, respondWith });
      return { request, respondWith, response };
    },
  };
}

beforeEach(() => vi.useFakeTimers());
afterEach(() => {
  vi.clearAllTimers();
  vi.useRealTimers();
});

describe("reconstructed classic service worker", () => {
  it("installs immediately and honors only SKIP_WAITING messages", async () => {
    const worker = loadWorker();
    expect([...worker.listeners.keys()]).toEqual([
      "install",
      "activate",
      "message",
      "fetch",
    ]);
    expect(await worker.lifecycle("install")).toHaveLength(1);
    expect(worker.self.skipWaiting).toHaveBeenCalledTimes(1);
    worker.message(null);
    worker.message({ type: "OTHER" });
    expect(worker.self.skipWaiting).toHaveBeenCalledTimes(1);
    worker.message({ type: "SKIP_WAITING" });
    expect(worker.self.skipWaiting).toHaveBeenCalledTimes(2);
  });

  it("cleans older version caches, preserves other caches, then claims clients", async () => {
    const worker = loadWorker();
    for (const name of [
      "tc-est-data-0.4.7",
      "tc-est-shell-0.3.0",
      DATA_CACHE,
      SHELL_CACHE,
      "tc-est-custom-0.4.8",
      "another-app-cache",
    ]) {
      worker.seed(name, catalogUrl);
    }
    const cleanup = deferred();
    const deleteCache = worker.caches.delete.getMockImplementation();
    worker.caches.delete.mockImplementation(async (name) => {
      await cleanup.promise;
      return deleteCache(name);
    });
    const activation = worker.lifecycle("activate");
    await vi.advanceTimersByTimeAsync(0);
    expect(worker.caches.delete.mock.calls.flat()).toEqual([
      "tc-est-data-0.4.7",
      "tc-est-shell-0.3.0",
    ]);
    expect(worker.self.clients.claim).not.toHaveBeenCalled();
    cleanup.resolve();
    await activation;
    expect(worker.self.clients.claim).toHaveBeenCalledTimes(1);
    expect(
      worker.self.clients.claim.mock.invocationCallOrder[0],
    ).toBeGreaterThan(worker.caches.delete.mock.invocationCallOrder.at(-1));
  });

  it.each([
    ["GET", "/wp-json/tc-estimate/v1/customers?q=customer"],
    ["GET", "/wp-json/tc-estimate/v1/customer-choices/view"],
    ["POST", "/wp-json/tc-estimate/v1/customer-choices/view"],
    ["POST", "/wp-json/tc-estimate/v1/customer-choices/apply"],
    ["GET", "/wp-json/tc-estimate/v1/preview"],
    ["GET", "/wp-json/tc-estimate/v1/generate"],
    ["GET", "/wp-json/tc-estimate/v1/update-estimate"],
    ["GET", "/wp-json/tc-estimate/v1/send-estimate"],
    ["GET", "/wp-json/tc-estimate/v1/send-status?job_id=fixture"],
    ["GET", "/wp-json/tc-estimate/v1/webhook/accepted"],
    ["POST", "/wp-json/tc-estimate/v1/equipment"],
    ["POST", "/wp-json/tc-estimate/v1/generate"],
    ["POST", "/wp-json/tc-estimate/v1/update-estimate"],
    ["POST", "/wp-json/tc-estimate/v1/send-estimate"],
    ["DELETE", "/wp-json/tc-estimate/v1/templates/12"],
    ["GET", "/other/app.js"],
  ])("does not intercept %s %s", (method, path) => {
    const worker = loadWorker();
    const event = worker.request(ORIGIN + path, method);
    expect(event.respondWith).not.toHaveBeenCalled();
    expect(worker.caches.open).not.toHaveBeenCalled();
    expect(worker.fetch).not.toHaveBeenCalled();
  });

  it.each([
    "/wp-json/tc-estimate/v1/equipment",
    "/wp-json/tc-estimate/v1/equipment/10000000001",
    "/wp-json/tc-estimate/v1/templates?type=full_replacement",
    "/wp-json/tc-estimate/v1/templates/12",
  ])("fetches and caches successful catalog responses for %s", async (path) => {
    const worker = loadWorker(async () => new Response("fresh catalog"));
    const event = worker.request(ORIGIN + path);
    expect(await (await event.response).text()).toBe("fresh catalog");
    expect(worker.caches.open).toHaveBeenCalledWith(DATA_CACHE);
    expect(worker.fetch).toHaveBeenCalledWith(event.request, {
      credentials: "same-origin",
      signal: expect.any(AbortSignal),
    });
    expect(worker.cache(DATA_CACHE).put).toHaveBeenCalledWith(
      event.request,
      expect.any(Response),
    );
    expect(vi.getTimerCount()).toBe(0);
  });

  it("prefers fresh network catalog data and preserves its complete query key", async () => {
    const worker = loadWorker(async () => new Response("fresh furnace"));
    worker.seed(DATA_CACHE, catalogUrl, new Response("old furnace"));
    const event = worker.request(catalogUrl);
    expect(await (await event.response).text()).toBe("fresh furnace");
    expect(worker.cache(DATA_CACHE).match).not.toHaveBeenCalled();
    expect(
      await (
        await worker.cache(DATA_CACHE).match(new Request(catalogUrl))
      ).text(),
    ).toBe("fresh furnace");
    expect(worker.cache(DATA_CACHE).put.mock.calls[0][0].url).toBe(catalogUrl);
  });

  it("aborts catalog fetch at four seconds and falls back to that query's cache", async () => {
    let signal;
    const worker = loadWorker(
      (_request, options) =>
        new Promise((_resolve, reject) => {
          signal = options.signal;
          signal.addEventListener("abort", () => reject(new Error("aborted")));
        }),
    );
    worker.seed(DATA_CACHE, catalogUrl, new Response("offline furnace"));
    const event = worker.request(catalogUrl);
    await vi.advanceTimersByTimeAsync(3999);
    expect(signal.aborted).toBe(false);
    await vi.advanceTimersByTimeAsync(1);
    expect(signal.aborted).toBe(true);
    expect(await (await event.response).text()).toBe("offline furnace");
  });

  it("returns JSON 503 rather than substituting another query when offline", async () => {
    const worker = loadWorker(async () => {
      throw new Error("offline");
    });
    worker.seed(DATA_CACHE, catalogUrl, new Response("furnace only"));
    const response = await worker.request(
      catalogUrl.replace("furnace", "permit"),
    ).response;
    expect(response.status).toBe(503);
    expect(response.headers.get("Content-Type")).toBe("application/json");
    expect(await response.json()).toEqual({
      ok: false,
      error: {
        code: "offline",
        message: "Offline and no cached copy available.",
      },
    });
    expect(worker.cache(DATA_CACHE).put).not.toHaveBeenCalled();
  });

  it("returns catalog HTTP errors without stale fallback or caching", async () => {
    const worker = loadWorker(
      async () => new Response("forbidden", { status: 403 }),
    );
    worker.seed(DATA_CACHE, catalogUrl, new Response("stale success"));
    const response = await worker.request(catalogUrl).response;
    expect(response.status).toBe(403);
    expect(await response.text()).toBe("forbidden");
    expect(worker.cache(DATA_CACHE).match).not.toHaveBeenCalled();
    expect(worker.cache(DATA_CACHE).put).not.toHaveBeenCalled();
  });

  it.each([
    [DATA_CACHE, catalogUrl],
    [SHELL_CACHE, `${ORIGIN}/app/dist/estimate-builder.js`],
  ])(
    "does not block responses on background writes to %s",
    async (cacheName, url) => {
      const worker = loadWorker(async () => new Response("network response"));
      const write = deferred();
      worker.cache(cacheName).put.mockReturnValueOnce(write.promise);
      const response = await worker.request(url).response;
      expect(await response.text()).toBe("network response");
      expect(worker.cache(cacheName).put).toHaveBeenCalledTimes(1);
      write.resolve();
    },
  );

  it("preserves the reference's path matching for cross-origin requests", async () => {
    const worker = loadWorker();
    const event = worker.request(
      "https://catalog.example.test/wp-json/tc-estimate/v1/equipment?limit=1",
    );
    expect(await (await event.response).text()).toBe("network");
    expect(worker.fetch).toHaveBeenCalledWith(event.request, {
      credentials: "same-origin",
      signal: expect.any(AbortSignal),
    });
  });

  it.each(["js", "css"])(
    "serves cached shell .%s while refreshing its exact URL",
    async (extension) => {
      const network = deferred();
      const worker = loadWorker(() => network.promise);
      const url = `${ORIGIN}/plugins/example/app/dist/estimate-builder.${extension}?ver=0.4.8-123`;
      worker.seed(SHELL_CACHE, url, new Response("cached shell"));
      const event = worker.request(url);
      expect(await (await event.response).text()).toBe("cached shell");
      expect(worker.fetch).toHaveBeenCalledWith(event.request, {
        credentials: "same-origin",
      });
      expect(worker.cache(SHELL_CACHE).put).not.toHaveBeenCalled();
      network.resolve(new Response("refreshed shell"));
      await Promise.resolve();
      await Promise.resolve();
      expect(worker.cache(SHELL_CACHE).put).toHaveBeenCalledWith(
        event.request,
        expect.any(Response),
      );
      expect(
        await (await worker.cache(SHELL_CACHE).match(new Request(url))).text(),
      ).toBe("refreshed shell");
    },
  );

  it("uses network for uncached shell assets and caches success", async () => {
    const worker = loadWorker(async () => new Response("new shell"));
    const event = worker.request(
      `${ORIGIN}/app/dist/estimate-builder.js?ver=current`,
    );
    expect(await (await event.response).text()).toBe("new shell");
    expect(worker.cache(SHELL_CACHE).put).toHaveBeenCalledWith(
      event.request,
      expect.any(Response),
    );
  });

  it("keeps cached shell data when background refresh fails", async () => {
    const worker = loadWorker(async () => {
      throw new Error("offline");
    });
    const url = `${ORIGIN}/app/dist/estimate-builder.css`;
    worker.seed(SHELL_CACHE, url, new Response("cached CSS"));
    expect(await (await worker.request(url).response).text()).toBe(
      "cached CSS",
    );
    expect(worker.cache(SHELL_CACHE).put).not.toHaveBeenCalled();
  });

  it("returns empty 504 for an uncached shell when network fails", async () => {
    const worker = loadWorker(async () => {
      throw new Error("offline");
    });
    worker.seed(
      SHELL_CACHE,
      `${ORIGIN}/app/dist/estimate-builder.js?ver=older`,
      new Response("another version"),
    );
    const response = await worker.request(
      `${ORIGIN}/app/dist/estimate-builder.js?ver=uncached`,
    ).response;
    expect(response.status).toBe(504);
    expect(await response.text()).toBe("");
  });

  it("returns uncached shell HTTP errors without caching or converting to 504", async () => {
    const worker = loadWorker(
      async () => new Response("gateway error", { status: 502 }),
    );
    const response = await worker.request(
      `${ORIGIN}/app/dist/estimate-builder.js`,
    ).response;
    expect(response.status).toBe(502);
    expect(await response.text()).toBe("gateway error");
    expect(worker.cache(SHELL_CACHE).put).not.toHaveBeenCalled();
  });

  it("propagates cache infrastructure failures instead of inventing success", async () => {
    const worker = loadWorker();
    worker.caches.open.mockRejectedValueOnce(new Error("storage unavailable"));
    await expect(worker.request(catalogUrl).response).rejects.toThrow(
      "storage unavailable",
    );
    expect(worker.fetch).not.toHaveBeenCalled();
    worker
      .cache(SHELL_CACHE)
      .match.mockRejectedValueOnce(new Error("cache read failed"));
    await expect(
      worker.request(`${ORIGIN}/app/dist/estimate-builder.js`).response,
    ).rejects.toThrow("cache read failed");
    expect(worker.fetch).not.toHaveBeenCalled();
  });
});
