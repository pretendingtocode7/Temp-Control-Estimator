// Retail-only public capability fixtures. Even the synthetic bearer is redacted.
import { expect } from "@playwright/test";
import { origin, permitParagraphs } from "./fixtures.js";

export const publicPath = "/?tc_estimate_choices=1";
const syntheticToken = `${"a".repeat(32)}.${"b".repeat(64)}`;
const tokenPattern = /[a-f0-9]{32}\.[a-f0-9]{64}/gi;
export const redact = (value) =>
  String(value).replace(tokenPattern, "[REDACTED-TOKEN]");
export const approvalUrl =
  "https://books.zoho.com/portal/synthetic/estimate/fixture-review";
const prices = [
  [8000, 10000, 12000],
  [9000, 11000, 13000],
];
export const publicSystems = ["Lower System", "Upper System"].map(
  (label, index) => ({
    system_number: index + 1,
    system_label: label,
    selected_choice_id: `choice-${index}-0`,
    choices: ["Silver", "Gold", "Platinum"].map((tier, position) => ({
      choice_id: `choice-${index}-${position}`,
      label: tier,
      sales_price: prices[index][position],
      items: [
        {
          name: `Synthetic ${label.split(" ")[0]} ${tier} Package`,
          display_name: `Synthetic ${label.split(" ")[0]} ${tier} Package`,
          short_description: `${tier} installation scope — café`,
          brand: "Synthetic",
          model: `PUBLIC-${index}-${position}`,
          tons: 2,
          btu_input: 80000,
          afue: 96,
        },
      ],
    })),
  }),
);
export function retailTotal(selections) {
  return (
    500 +
    selections.reduce((sum, selection) => {
      const system = publicSystems.find(
        (row) => row.system_number === selection.system_number,
      );
      return (
        sum +
        system.choices.find(
          (choice) => choice.choice_id === selection.choice_id,
        ).sales_price
      );
    }, 0)
  );
}
export function publicView({
  revision = 1,
  selections,
  state = "ready",
  pending,
  review = false,
} = {}) {
  const systems = structuredClone(publicSystems);
  if (selections)
    for (const selection of selections)
      systems.find(
        (system) => system.system_number === selection.system_number,
      ).selected_choice_id = selection.choice_id;
  const chosen = systems.map(({ system_number, selected_choice_id }) => ({
    system_number,
    choice_id: selected_choice_id,
  }));
  return {
    revision,
    state,
    systems,
    project_adjustment: 500,
    total: retailTotal(chosen),
    deposit_percent: 35,
    addons: [
      {
        addon_key: "maintenance",
        name: "New Residential System Maintenance Agreement",
        rate: 0,
        quantity: 1,
        description: "Renews at $349 for one unit.",
        renewal_rate: 349,
        renewal_units: 1,
      },
      {
        addon_key: "permits",
        name: "Permits & Inspections",
        rate: 0,
        quantity: 1,
        description: permitParagraphs.join("\n\n"),
      },
    ],
    review_url: review ? approvalUrl : "",
    expires_at: 1893456000,
    crm_sync_pending: false,
    ...(pending ? { pending } : {}),
  };
}

export async function installCustomerFixture(
  page,
  { mode = "saved", token = "valid", holdView = false } = {},
) {
  const state = {
    requests: [],
    outside: [],
    errors: [],
    console: [],
    credentialLeak: false,
    views: 0,
    applies: [],
    mode,
    revision: 1,
    selections: undefined,
    saved: false,
    heldApply: null,
    releaseView: null,
  };
  const safe = (value) => {
    const text = String(value);
    if (tokenPattern.test(text)) state.credentialLeak = true;
    tokenPattern.lastIndex = 0;
    return redact(text);
  };
  page.on("console", (message) =>
    state.console.push({ type: message.type(), text: safe(message.text()) }),
  );
  page.on("pageerror", (error) => state.errors.push(safe(error.message)));
  await page.addInitScript(
    ({ secret, missing, malformed }) => {
      if (!missing)
        history.replaceState(
          null,
          "",
          `${location.pathname}${location.search}#token=${malformed ? "invalid" : secret}`,
        );
      let id = 0;
      Object.defineProperty(crypto, "randomUUID", {
        value: () =>
          `00000000-0000-4000-8000-${String(++id).padStart(12, "0")}`,
      });
      window.__customerWorkerRegistrations = [];
      Object.defineProperty(navigator, "serviceWorker", {
        value: {
          controller: null,
          register: async (...args) => {
            window.__customerWorkerRegistrations.push(args);
            return { addEventListener() {}, installing: null };
          },
        },
      });
    },
    {
      secret: syntheticToken,
      missing: token === "missing",
      malformed: token === "malformed",
    },
  );
  await page.route("**/*", async (route) => {
    const request = route.request(),
      url = new URL(request.url());
    expect(
      url.href.includes(syntheticToken),
      "no capability in any request URL",
    ).toBe(false);
    expect(
      url.searchParams.has("token"),
      "capability is never a query parameter",
    ).toBe(false);
    if (url.origin !== origin) {
      state.outside.push(redact(request.url()));
      return route.abort("blockedbyclient");
    }
    if (!url.pathname.startsWith("/wp-json/")) return route.continue();
    const endpoint = url.pathname.replace("/wp-json/tc-estimate/v1", "");
    expect(["/customer-choices/view", "/customer-choices/apply"]).toContain(
      endpoint,
    );
    expect(request.method()).toBe("POST");
    const headers = await request.allHeaders();
    expect(headers["x-wp-nonce"]).toBeUndefined();
    expect(headers.cookie).toBeUndefined();
    expect(headers.referer).toBeUndefined();
    const body = JSON.parse(request.postData());
    expect(
      body.token === syntheticToken,
      "public body has the in-memory fixture capability",
    ).toBe(true);
    const sanitized = JSON.parse(redact(JSON.stringify(body)));
    state.requests.push({
      method: request.method(),
      endpoint,
      url: `${url.origin}${url.pathname}`,
      body: sanitized,
      staffNoncePresent: false,
      cookiePresent: false,
      referrerPresent: false,
    });
    const respond = (data, status = 200) =>
      route.fulfill({
        status,
        contentType: "application/json",
        headers: { "Cache-Control": "no-store" },
        body: JSON.stringify({ ok: true, data }),
      });
    const fail = (code, status = 409) =>
      route.fulfill({
        status,
        contentType: "application/json",
        body: JSON.stringify({
          ok: false,
          error: {
            code: `tc_estimate_choices_${code}`,
            message: `Untrusted provider echo ${syntheticToken}`,
          },
        }),
      });
    if (endpoint === "/customer-choices/view") {
      state.views++;
      expect(Object.keys(body)).toEqual(["token"]);
      if (token === "expired") return fail("unavailable", 410);
      if (holdView) {
        state.releaseView = () => respond(publicView());
        return;
      }
      if (state.mode === "pending" && state.applies.length) {
        const original = state.applies[0];
        return respond(
          publicView({
            revision: original.revision,
            selections: original.selections,
            state: "pending",
            pending: {
              request_id: original.request_id,
              revision: original.revision,
              selections: original.selections,
              status: "pending",
            },
          }),
        );
      }
      return respond(
        publicView({
          revision: state.revision,
          selections: state.selections,
          state: state.saved ? "saved" : "ready",
          review: state.saved,
        }),
      );
    }
    expect(Object.keys(body).sort()).toEqual([
      "request_id",
      "revision",
      "selections",
      "token",
    ]);
    expect(Number.isInteger(body.revision)).toBe(true);
    expect(body.selections).toHaveLength(2);
    for (const selection of body.selections)
      expect(Object.keys(selection).sort()).toEqual([
        "choice_id",
        "system_number",
      ]);
    expect(typeof body.request_id).toBe("string");
    expect(body.request_id.length).toBeGreaterThan(10);
    state.applies.push(sanitized);
    if (state.mode === "stale") {
      state.revision = 2;
      return fail("stale_revision");
    }
    if (state.mode === "timeout") {
      state.heldApply = route;
      return;
    }
    if (state.mode === "pending")
      return respond(
        {
          saved: false,
          state: "pending",
          revision: body.revision,
          total: retailTotal(body.selections),
          review_url: "",
          crm_sync_pending: false,
          pending: {
            request_id: body.request_id,
            revision: body.revision,
            selections: body.selections,
            status: "pending",
          },
        },
        202,
      );
    state.saved = true;
    state.revision = 2;
    state.selections = body.selections;
    return respond({
      saved: true,
      state: state.mode === "books_updated" ? "books_updated" : "saved",
      revision: 2,
      total: retailTotal(body.selections),
      selections: body.selections,
      review_url: approvalUrl,
      crm_sync_pending: state.mode === "books_updated",
    });
  });
  return state;
}
