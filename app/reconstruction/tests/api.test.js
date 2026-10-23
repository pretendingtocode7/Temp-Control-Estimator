import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import {
  ApiError,
  createApi,
  GENERATE_TIMEOUT_MS,
  READ_TIMEOUT_MS,
  SEND_TIMEOUT_MS,
  UPDATE_TIMEOUT_MS,
} from "../src/lib/api.js";

const REST_URL = "https://fixture.example.test/wp-json/tc-estimate/v1";
const AJAX_URL = "https://fixture.example.test/wp-admin/admin-ajax.php";
const ESTIMATE_ID = "500000000000000001";
const JOB_ID = "00000000-0000-4000-8000-000000000001";
const payload = {
  template_id: 42,
  customer: { zoho_account_id: "200000000000000001" },
  systems: [
    {
      system_number: 1,
      equipment: { furnace: { item_id: "400000000000000001" } },
    },
  ],
  pricing: { subtotal: 1000, total: 1200 },
};

function response(status, body, rawText) {
  return {
    status,
    ok: status >= 200 && status < 300,
    text: vi
      .fn()
      .mockResolvedValue(
        rawText ?? (body === undefined ? "" : JSON.stringify(body)),
      ),
  };
}

function success(data, status = 200) {
  return response(status, { ok: true, data });
}

function createClient(overrides = {}) {
  return createApi({
    restUrl: REST_URL,
    nonce: "initial-nonce",
    ajaxUrl: AJAX_URL,
    ...overrides,
  });
}

function abortableFetch(_url, { signal }) {
  return new Promise((_resolve, reject) => {
    signal.addEventListener(
      "abort",
      () =>
        reject(
          Object.assign(new Error("Request aborted"), { name: "AbortError" }),
        ),
      { once: true },
    );
  });
}

describe("saved v0.4.8 API client contract", () => {
  let fetchMock;

  beforeEach(() => {
    vi.useFakeTimers();
    vi.spyOn(Math, "random").mockReturnValue(0);
    fetchMock = vi.fn();
    vi.stubGlobal("fetch", fetchMock);
  });

  afterEach(() => {
    vi.unstubAllGlobals();
    vi.restoreAllMocks();
    vi.useRealTimers();
  });

  it("requires boot data and uses session cookies, nonce and a normalized REST base", async () => {
    expect(() => createApi({ nonce: "nonce" })).toThrow(
      "createApi requires restUrl and nonce",
    );
    expect(() => createApi({ restUrl: REST_URL })).toThrow(
      "createApi requires restUrl and nonce",
    );
    fetchMock.mockResolvedValue(success([]));
    await createClient({ restUrl: REST_URL + "///" }).searchCustomers(
      "Test & Co",
      20,
    );
    const [url, options] = fetchMock.mock.calls[0];
    expect(url).toBe(REST_URL + "/customers?q=Test+%26+Co&limit=20");
    expect(options).toMatchObject({
      method: "GET",
      credentials: "same-origin",
      headers: { "X-WP-Nonce": "initial-nonce", Accept: "application/json" },
      body: undefined,
    });
    expect(options.headers).not.toHaveProperty("Content-Type");
    expect(options.signal).toBeInstanceOf(AbortSignal);
  });

  it("skips null/empty query fields but preserves explicit false and zero", async () => {
    fetchMock.mockResolvedValue(success([]));
    await createClient().searchEquipment({
      type: "",
      q: null,
      brand: false,
      limit: 0,
    });
    expect(fetchMock.mock.calls[0][0]).toBe(
      REST_URL + "/equipment?brand=false&limit=0",
    );
  });

  it("encodes individual resource IDs without converting large IDs to numbers", async () => {
    fetchMock.mockResolvedValue(success({}));
    const client = createClient();
    await client.getEquipment("400000000000000001/extra");
    await client.getTemplate("42/extra");
    await client.listTemplates("full_replacement");
    expect(fetchMock.mock.calls.map(([url]) => url)).toEqual([
      REST_URL + "/equipment/400000000000000001%2Fextra",
      REST_URL + "/templates/42%2Fextra",
      REST_URL + "/templates?type=full_replacement",
    ]);
  });

  it.each([
    { code: "rest_cookie_invalid_nonce", message: "Cookie check failed." },
    { ok: false, error: { code: "tc_estimate_bad_nonce", message: "Expired" } },
    { message: "Invalid or missing nonce." },
  ])(
    "refreshes a recognized nonce failure once and preserves the mutation attempt",
    async (failure) => {
      fetchMock
        .mockResolvedValueOnce(response(403, failure))
        .mockResolvedValueOnce(
          response(200, {
            success: true,
            data: {
              nonce: "fresh-nonce",
              restUrl: "https://ignored.example.test/new-base",
            },
          }),
        )
        .mockResolvedValueOnce(success({ estimate_id: ESTIMATE_ID }, 201));

      const result = await createClient().generate(payload, "same-attempt-key");
      expect(result).toEqual({ estimate_id: ESTIMATE_ID });
      expect(fetchMock).toHaveBeenCalledTimes(3);
      const [firstUrl, firstOptions] = fetchMock.mock.calls[0];
      const [refreshUrl, refreshOptions] = fetchMock.mock.calls[1];
      const [retryUrl, retryOptions] = fetchMock.mock.calls[2];
      expect(firstUrl).toBe(REST_URL + "/generate");
      expect(refreshUrl).toBe(AJAX_URL);
      expect(refreshOptions.method).toBe("POST");
      expect(refreshOptions.credentials).toBe("same-origin");
      expect(refreshOptions.body).toBeInstanceOf(URLSearchParams);
      expect(refreshOptions.body.get("action")).toBe("tc_estimate_rest_nonce");
      expect(refreshOptions.headers).toEqual({ Accept: "application/json" });
      expect(retryUrl).toBe(firstUrl); // Returned refresh restUrl is intentionally ignored.
      expect(retryOptions.body).toBe(firstOptions.body);
      expect(retryOptions.headers["Idempotency-Key"]).toBe("same-attempt-key");
      expect(retryOptions.headers["X-WP-Nonce"]).toBe("fresh-nonce");
    },
  );

  it("does not refresh again when the nonce retry also fails", async () => {
    fetchMock
      .mockResolvedValueOnce(
        response(403, { code: "rest_cookie_invalid_nonce" }),
      )
      .mockResolvedValueOnce(
        response(200, { success: true, data: { nonce: "still-bad" } }),
      )
      .mockResolvedValueOnce(
        response(403, {
          code: "rest_cookie_invalid_nonce",
          message: "Expired",
        }),
      );
    await expect(
      createClient().generate(payload, "attempt-key"),
    ).rejects.toMatchObject({
      code: "http_403",
      message: "Expired",
      status: 403,
    });
    expect(fetchMock).toHaveBeenCalledTimes(3);
  });

  it.each([401, 403])(
    "does not refresh ordinary permission failure HTTP%s",
    async (status) => {
      fetchMock.mockResolvedValue(
        response(status, {
          code: "tc_estimate_forbidden",
          message: "Insufficient permissions.",
        }),
      );
      await expect(
        createClient().searchCustomers("Fixture"),
      ).rejects.toMatchObject({
        code: `http_${status}`,
        message: "Insufficient permissions.",
      });
      expect(fetchMock).toHaveBeenCalledTimes(1);
    },
  );

  it("does not use nonce-message detection for a non-401/403 response", async () => {
    fetchMock.mockResolvedValue(
      response(400, { message: "Invalid or missing nonce." }),
    );
    await expect(createClient().preview(payload)).rejects.toMatchObject({
      code: "http_400",
    });
    expect(fetchMock).toHaveBeenCalledTimes(1);
  });

  it("reports unavailable or rejected nonce refresh without repeating a mutation", async () => {
    fetchMock.mockResolvedValue(
      response(403, { code: "tc_estimate_bad_nonce" }),
    );
    await expect(
      createClient({ ajaxUrl: undefined }).generate(payload, "attempt-key"),
    ).rejects.toMatchObject({
      code: "tc_estimate_nonce_refresh_unavailable",
      status: 403,
      body: null,
    });
    expect(fetchMock).toHaveBeenCalledTimes(1);

    fetchMock.mockReset();
    fetchMock
      .mockResolvedValueOnce(response(403, { code: "tc_estimate_bad_nonce" }))
      .mockResolvedValueOnce(
        response(401, {
          success: false,
          data: { code: "session_expired", message: "Log in again." },
        }),
      );
    await expect(
      createClient().generate(payload, "attempt-key"),
    ).rejects.toMatchObject({
      code: "session_expired",
      message: "Log in again.",
      status: 401,
    });
    expect(fetchMock).toHaveBeenCalledTimes(2);
  });

  it.each([408, 429, 500, 502])(
    "retries HTTP%s reads after the saved exponential delay",
    async (status) => {
      fetchMock
        .mockResolvedValueOnce(
          response(status, {
            ok: false,
            error: { message: "Temporary failure" },
          }),
        )
        .mockResolvedValueOnce(success([{ id: "200000000000000001" }]));
      const pending = createClient().searchCustomers("Fixture");
      await vi.advanceTimersByTimeAsync(199);
      expect(fetchMock).toHaveBeenCalledTimes(1);
      await vi.advanceTimersByTimeAsync(1);
      await expect(pending).resolves.toEqual([{ id: "200000000000000001" }]);
      expect(fetchMock).toHaveBeenCalledTimes(2);
    },
  );

  it("retries network failures twice, using 200ms then 400ms backoff", async () => {
    const failure = new TypeError("Network unavailable");
    fetchMock.mockRejectedValue(failure);
    const settled = createClient()
      .getTemplate(42)
      .catch((error) => error);
    await vi.advanceTimersByTimeAsync(199);
    expect(fetchMock).toHaveBeenCalledTimes(1);
    await vi.advanceTimersByTimeAsync(1);
    expect(fetchMock).toHaveBeenCalledTimes(2);
    await vi.advanceTimersByTimeAsync(399);
    expect(fetchMock).toHaveBeenCalledTimes(2);
    await vi.advanceTimersByTimeAsync(1);
    expect(fetchMock).toHaveBeenCalledTimes(3);
    expect(await settled).toBe(failure);
  });

  it("preserves the saved read-like retry for non-creating POST preview", async () => {
    fetchMock
      .mockRejectedValueOnce(new TypeError("Temporary network failure"))
      .mockResolvedValueOnce(success({ html: "<p>Fixture preview</p>" }));
    const pending = createClient().preview(payload);
    await vi.advanceTimersByTimeAsync(200);
    await expect(pending).resolves.toEqual({ html: "<p>Fixture preview</p>" });
    expect(fetchMock).toHaveBeenCalledTimes(2);
    for (const [url, options] of fetchMock.mock.calls) {
      expect(url).toBe(REST_URL + "/preview");
      expect(options.method).toBe("POST");
      expect(options.body).toBe(JSON.stringify(payload));
    }
  });

  it.each([
    ["generate", (client) => client.generate(payload, "attempt-key")],
    [
      "update-estimate",
      (client) => client.updateEstimate(ESTIMATE_ID, { notes: "Fixture" }),
    ],
    ["send-estimate", (client) => client.sendEstimate(ESTIMATE_ID)],
  ])("does not retry %s on gateway or network failure", async (path, call) => {
    fetchMock.mockResolvedValue(
      response(502, undefined, "<h1>Bad gateway</h1>"),
    );
    await expect(call(createClient())).rejects.toBeInstanceOf(ApiError);
    await vi.advanceTimersByTimeAsync(60_000);
    expect(fetchMock).toHaveBeenCalledTimes(1);
    expect(fetchMock.mock.calls[0][0]).toBe(REST_URL + "/" + path);

    fetchMock.mockReset().mockRejectedValue(new TypeError("Connection lost"));
    await expect(call(createClient())).rejects.toThrow("Connection lost");
    await vi.advanceTimersByTimeAsync(60_000);
    expect(fetchMock).toHaveBeenCalledTimes(1);
  });

  it("requires a generation idempotency key and transmits it with the exact JSON payload", async () => {
    const client = createClient();
    expect(() => client.generate(payload)).toThrow(
      "generate() requires an idempotency key",
    );
    expect(fetchMock).not.toHaveBeenCalled();
    fetchMock.mockResolvedValue(
      success({ estimate_id: ESTIMATE_ID, books_email_sent: false }, 201),
    );
    const result = await client.generate(payload, "fixture-unique-attempt");
    expect(result.books_email_sent).toBe(false);
    expect(fetchMock.mock.calls[0][1]).toMatchObject({
      method: "POST",
      credentials: "same-origin",
      headers: {
        "Content-Type": "application/json",
        "Idempotency-Key": "fixture-unique-attempt",
      },
      body: JSON.stringify(payload),
    });
  });

  it.each([
    [
      "generate",
      GENERATE_TIMEOUT_MS,
      (client) => client.generate(payload, "attempt-key"),
    ],
    [
      "update",
      UPDATE_TIMEOUT_MS,
      (client) => client.updateEstimate(ESTIMATE_ID, {}),
    ],
    ["send", SEND_TIMEOUT_MS, (client) => client.sendEstimate(ESTIMATE_ID)],
  ])(
    "aborts %s at its saved timeout without replaying the mutation",
    async (_name, timeoutMs, call) => {
      fetchMock.mockImplementation(abortableFetch);
      let completed = false;
      const settled = call(createClient()).catch((error) => {
        completed = true;
        return error;
      });
      await vi.advanceTimersByTimeAsync(timeoutMs - 1);
      expect(completed).toBe(false);
      expect(fetchMock.mock.calls[0][1].signal.aborted).toBe(false);
      await vi.advanceTimersByTimeAsync(1);
      expect((await settled).name).toBe("AbortError");
      expect(fetchMock.mock.calls[0][1].signal.aborted).toBe(true);
      expect(fetchMock).toHaveBeenCalledTimes(1);
    },
  );

  it("uses the 10-second timeout and only one retry for status reads", async () => {
    expect(READ_TIMEOUT_MS).toBe(10_000);
    fetchMock.mockImplementation(abortableFetch);
    const settled = createClient()
      .sendStatus(JOB_ID)
      .catch((error) => error);
    await vi.advanceTimersByTimeAsync(9_999);
    expect(fetchMock.mock.calls[0][1].signal.aborted).toBe(false);
    await vi.advanceTimersByTimeAsync(1);
    expect(fetchMock.mock.calls[0][1].signal.aborted).toBe(true);
    await vi.advanceTimersByTimeAsync(200);
    expect(fetchMock).toHaveBeenCalledTimes(2);
    await vi.advanceTimersByTimeAsync(10_000);
    expect((await settled).name).toBe("AbortError");
    expect(fetchMock).toHaveBeenCalledTimes(2);
  });

  it("retains the saved fetch-only timeout boundary instead of timing out response.text", async () => {
    let finishBody;
    const delayedBody = new Promise((resolve) => {
      finishBody = resolve;
    });
    fetchMock.mockResolvedValue({
      ok: true,
      status: 201,
      text: () => delayedBody,
    });
    const pending = createClient().generate(payload, "attempt-key");
    await vi.advanceTimersByTimeAsync(60_000);
    expect(fetchMock.mock.calls[0][1].signal.aborted).toBe(false);
    finishBody(
      JSON.stringify({ ok: true, data: { estimate_id: ESTIMATE_ID } }),
    );
    await expect(pending).resolves.toEqual({ estimate_id: ESTIMATE_ID });
  });

  it("transmits Books edits and the PDF send body without altering them", async () => {
    const client = createClient();
    const edits = {
      line_items: [
        { key: "system_1_furnace_0", description: "Edited fixture" },
      ],
      notes: "",
      terms: "Fixture terms",
    };
    const attachment = {
      filename: "Fixture-Proposal.pdf",
      data: "JVBERi0xLjQK",
      html_b64: "PHA+Rml4dHVyZTwvcD4=",
    };
    fetchMock
      .mockResolvedValueOnce(success({ estimate_id: ESTIMATE_ID, saved: true }))
      .mockResolvedValueOnce(
        success({ job_id: JOB_ID, status: "queued", sent: false }, 202),
      );
    await client.updateEstimate(ESTIMATE_ID, edits);
    await expect(
      client.sendEstimate(ESTIMATE_ID, attachment),
    ).resolves.toMatchObject({ status: "queued", sent: false });
    expect(JSON.parse(fetchMock.mock.calls[0][1].body)).toEqual({
      estimate_id: ESTIMATE_ID,
      books_edits: edits,
    });
    expect(JSON.parse(fetchMock.mock.calls[1][1].body)).toEqual({
      estimate_id: ESTIMATE_ID,
      pretty_attachment: attachment,
    });
  });

  it("accepts terminal send state and replay without polling or sending again by itself", async () => {
    const client = createClient();
    fetchMock
      .mockResolvedValueOnce(
        success({
          estimate_id: ESTIMATE_ID,
          status: "sent",
          sent: true,
          replayed: true,
        }),
      )
      .mockResolvedValueOnce(
        success({
          job_id: JOB_ID,
          estimate_id: ESTIMATE_ID,
          status: "error",
          sent: false,
          error_code: "fixture_mail_failed",
          message: "Mock mail failed.",
        }),
      );
    await expect(client.sendEstimate(ESTIMATE_ID)).resolves.toEqual({
      estimate_id: ESTIMATE_ID,
      status: "sent",
      sent: true,
      replayed: true,
    });
    expect(JSON.parse(fetchMock.mock.calls[0][1].body)).toEqual({
      estimate_id: ESTIMATE_ID,
    });
    await expect(client.sendStatus(JOB_ID)).resolves.toMatchObject({
      status: "error",
      sent: false,
      error_code: "fixture_mail_failed",
    });
    await vi.advanceTimersByTimeAsync(60_000);
    expect(fetchMock.mock.calls.map(([url]) => url)).toEqual([
      REST_URL + "/send-estimate",
      REST_URL + "/send-status?job_id=" + JOB_ID,
    ]);
  });

  it("returns endpoint errors and HTTP200 envelope errors as ApiError", async () => {
    const client = createClient();
    const endpointError = {
      ok: false,
      error: {
        code: "tc_estimate_bad_pricing",
        message: "Total must be positive.",
      },
    };
    fetchMock.mockResolvedValueOnce(response(400, endpointError));
    await expect(client.generate(payload, "attempt-key")).rejects.toMatchObject(
      {
        name: "ApiError",
        code: "tc_estimate_bad_pricing",
        message: "Total must be positive.",
        status: 400,
        body: endpointError,
      },
    );
    fetchMock.mockResolvedValueOnce(response(200, { ok: false }));
    await expect(client.generate(payload, "attempt-key")).rejects.toMatchObject(
      {
        code: "envelope_error",
        message: "Envelope reported failure.",
        status: 200,
      },
    );
  });

  it("preserves native WordPress messages with the saved http_STATUS error code", async () => {
    const body = {
      code: "rest_invalid_param",
      message: "Invalid parameter(s): fixture",
      data: { status: 400 },
    };
    fetchMock.mockResolvedValue(response(400, body));
    await expect(
      createClient().generate(payload, "attempt-key"),
    ).rejects.toMatchObject({ code: "http_400", message: body.message, body });
  });

  it("shapes gateway HTML and empty error bodies into bounded messages", async () => {
    fetchMock.mockResolvedValueOnce(
      response(
        502,
        undefined,
        "<h1>Bad gateway</h1>\n<p>" + "x".repeat(500) + "</p>",
      ),
    );
    const htmlError = await createClient()
      .generate(payload, "attempt-key")
      .catch((error) => error);
    expect(htmlError).toBeInstanceOf(ApiError);
    expect(htmlError.code).toBe("http_502");
    expect(htmlError.body).toBeNull();
    expect(htmlError.message).not.toContain("<h1>");
    expect(htmlError.message).toHaveLength(
      "Server returned 502: ".length + 300,
    );
    fetchMock.mockResolvedValueOnce(response(503));
    await expect(
      createClient().generate(payload, "attempt-key"),
    ).rejects.toMatchObject({
      code: "http_503",
      message: "Request failed with status 503",
      body: null,
    });
  });

  it.each([
    [undefined, "", null],
    [undefined, "<html>Unexpected success</html>", null],
    [{ raw: "legacy response" }, undefined, { raw: "legacy response" }],
    ["plain JSON string", undefined, "plain JSON string"],
  ])(
    "preserves successful non-envelope response behavior",
    async (body, text, expected) => {
      fetchMock.mockResolvedValue(response(200, body, text));
      await expect(
        createClient().generate(payload, "attempt-key"),
      ).resolves.toEqual(expected);
    },
  );

  it("shares the exact unfiltered limit=1 prewarm promise and waits before filtered search", async () => {
    let finishWarmup;
    fetchMock
      .mockImplementationOnce(
        () =>
          new Promise((resolve) => {
            finishWarmup = resolve;
          }),
      )
      .mockResolvedValueOnce(
        success([{ item_id: "400000000000000002", name: "Filtered fixture" }]),
      );
    const client = createClient();
    const warmup = client.searchEquipment({ limit: 1 });
    expect(client.searchEquipment({ limit: 1 })).toBe(warmup);
    const filtered = client.searchEquipment({
      type: "furnace",
      q: "Fixture",
      limit: 50,
    });
    expect(fetchMock).toHaveBeenCalledTimes(1);
    finishWarmup(success([{ item_id: "400000000000000001" }]));
    await expect(warmup).resolves.toEqual([{ item_id: "400000000000000001" }]);
    await expect(filtered).resolves.toEqual([
      { item_id: "400000000000000002", name: "Filtered fixture" },
    ]);
    expect(fetchMock.mock.calls.map(([url]) => url)).toEqual([
      REST_URL + "/equipment?limit=1",
      REST_URL + "/equipment?type=furnace&q=Fixture&limit=50",
    ]);
    expect(client.searchEquipment({ limit: 1 })).toBe(warmup);
    expect(fetchMock).toHaveBeenCalledTimes(2);
  });

  it("preserves a failed prewarm promise but lets regular searches proceed", async () => {
    fetchMock
      .mockResolvedValueOnce(
        response(400, {
          ok: false,
          error: { code: "fixture_warmup_failure", message: "Mock failure" },
        }),
      )
      .mockResolvedValueOnce(success([]));
    const client = createClient();
    const warmup = client.searchEquipment({ limit: 1 });
    await expect(warmup).rejects.toMatchObject({
      code: "fixture_warmup_failure",
    });
    expect(client.searchEquipment({ limit: 1 })).toBe(warmup);
    await expect(client.searchEquipment({ type: "permit" })).resolves.toEqual(
      [],
    );
    expect(fetchMock).toHaveBeenCalledTimes(2);
  });

  it("fails invalid mutation/status calls synchronously without fetching", () => {
    const client = createClient();
    expect(() => client.updateEstimate("", {})).toThrow(
      "updateEstimate() requires an estimate ID",
    );
    expect(() => client.sendEstimate(null)).toThrow(
      "sendEstimate() requires an estimate ID",
    );
    expect(() => client.sendStatus(undefined)).toThrow(
      "sendStatus() requires a job ID",
    );
    expect(fetchMock).not.toHaveBeenCalled();
  });
});
