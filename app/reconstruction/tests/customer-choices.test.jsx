import * as React from "react";
import {
  act,
  cleanup,
  fireEvent,
  render,
  screen,
  waitFor,
} from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { CustomerChoicesPage } from "../src/components/CustomerChoicesPage.jsx";
import { CustomerChoicesError } from "../src/lib/customer-choices-api.js";

const token = `${"a".repeat(32)}.${"b".repeat(64)}`;
const restUrl = `${window.location.origin}/wp-json/tc-estimate/v1`;
const ids = [1, 2, 3, 4].map(
  (index) => `20000000-0000-4000-8000-${String(index).padStart(12, "0")}`,
);
const savedSelections = [
  { system_number: 1, choice_id: ids[1] },
  { system_number: 2, choice_id: ids[2] },
];
const reviewUrl = "https://books.zoho.com/portal/quote/fixture";
function viewData(overrides = {}) {
  return {
    revision: 1,
    state: "ready",
    project_adjustment: 250,
    total: 8250,
    deposit_percent: 35,
    systems: [
      {
        system_number: 1,
        system_label: "Upstairs",
        selected_choice_id: ids[0],
        choices: [
          {
            choice_id: ids[0],
            label: "Silver",
            sales_price: 5000,
            purchase_cost: 1337,
            items: [
              {
                name: "Upstairs Silver equipment",
                model: "S80",
                brand: "Fixture",
                short_description: "Silver installation scope",
                selection_category: "package",
              },
            ],
          },
          {
            choice_id: ids[1],
            label: "Gold",
            sales_price: 6500,
            items: [
              {
                name: "Upstairs Gold equipment",
                model: "G96",
                brand: "Fixture",
                short_description: "Gold installation scope",
                selection_category: "package",
              },
            ],
          },
        ],
      },
      {
        system_number: 2,
        system_label: "Downstairs",
        selected_choice_id: ids[2],
        choices: [
          {
            choice_id: ids[2],
            label: "Silver",
            sales_price: 3000,
            items: [
              {
                name: "Downstairs Silver equipment",
                short_description: "Downstairs scope",
                selection_category: "furnace",
              },
            ],
          },
          {
            choice_id: ids[3],
            label: "Gold",
            sales_price: 4500,
            items: [
              {
                name: "Downstairs Gold equipment",
                short_description: "Downstairs gold scope",
                selection_category: "furnace",
              },
            ],
          },
        ],
      },
    ],
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
        description: "Permit fees are not included in the project total.",
      },
    ],
    review_url: reviewUrl,
    internal_cost_note: "PRIVATE PURCHASE COST",
    crm_secret: "PRIVATE CRM DATA",
    ...overrides,
  };
}
function savedData(overrides = {}) {
  return {
    saved: true,
    state: "saved",
    revision: 2,
    total: 9750,
    selections: savedSelections,
    review_url: reviewUrl,
    crm_sync_pending: false,
    ...overrides,
  };
}
function safeError(code, status) {
  return Object.assign(new CustomerChoicesError(code, status), {
    message: `RAW PRIVATE ERROR ${token}`,
    code,
    status,
  });
}
function page(api) {
  return render(
    <CustomerChoicesPage
      restUrl={restUrl}
      brand={{ name: "Fixture HVAC" }}
      api={api}
    />,
  );
}
async function loaded() {
  return screen.findByRole("radio", { name: "Silver for Upstairs" });
}
async function chooseGold() {
  await loaded();
  fireEvent.click(screen.getByRole("radio", { name: "Gold for Upstairs" }));
}
beforeEach(() => {
  window.history.replaceState(null, "", `/customer-choices/#token=${token}`);
});
afterEach(() => {
  cleanup();
  vi.useRealTimers();
  vi.restoreAllMocks();
  window.history.replaceState(null, "", "/");
});

describe("homeowner equipment choices", () => {
  it("does not poll or resubmit a pending operation when time passes", async () => {
    vi.useFakeTimers();
    const api = {
      view: vi.fn().mockResolvedValue(viewData()),
      apply: vi
        .fn()
        .mockResolvedValue({ saved: false, state: "pending", revision: 1 }),
    };
    page(api);
    await act(async () => {});
    fireEvent.click(screen.getByRole("radio", { name: "Gold for Upstairs" }));
    fireEvent.click(screen.getByRole("button", { name: "Update my estimate" }));
    await act(async () => {});
    expect(screen.getByRole("button", { name: "Check update" })).toBeTruthy();
    expect(api.apply).toHaveBeenCalledTimes(1);
    await act(() => vi.advanceTimersByTimeAsync(60_000));
    expect(api.apply).toHaveBeenCalledTimes(1);
    expect(api.view).toHaveBeenCalledTimes(1);
  });

  it("views existing selections and recalculates retail totals without applying, approving or exposing internal data", async () => {
    const api = { view: vi.fn().mockResolvedValue(viewData()), apply: vi.fn() };
    page(api);
    await loaded();
    expect(api.view).toHaveBeenCalledExactlyOnceWith(token);
    expect(api.apply).not.toHaveBeenCalled();
    expect(
      screen.getByRole("heading", { name: "Choose your equipment" }),
    ).toBeTruthy();
    expect(
      screen.getByRole("radio", { name: "Silver for Upstairs" }).checked,
    ).toBe(true);
    expect(
      screen.getByRole("radio", { name: "Silver for Downstairs" }).checked,
    ).toBe(true);
    expect(screen.getByText("$8,250.00")).toBeTruthy();
    expect(
      screen.queryByRole("link", { name: "Review and approve in Zoho Books" }),
    ).toBeNull();
    fireEvent.click(screen.getByRole("radio", { name: "Gold for Upstairs" }));
    expect(screen.getByText("$9,750.00")).toBeTruthy();
    fireEvent.click(screen.getByRole("radio", { name: "Gold for Downstairs" }));
    expect(screen.getByText("$11,250.00")).toBeTruthy();
    expect(api.apply).not.toHaveBeenCalled();
    expect(
      screen.getAllByRole("heading", {
        name: /^New Residential System Maintenance Agreement/,
      }),
    ).toHaveLength(1);
    expect(
      screen.getAllByRole("heading", { name: /^Permits & Inspections/ }),
    ).toHaveLength(1);
    expect(screen.getByText("Renews at $349 for one unit.")).toBeTruthy();
    for (const forbidden of [
      "PRIVATE PURCHASE COST",
      "PRIVATE CRM DATA",
      "1337",
      "purchase_cost",
      token,
    ])
      expect(document.body.textContent).not.toContain(forbidden);
  });

  it("requires one explicit valid choice for every physical system before an update", async () => {
    const view = viewData();
    view.systems[1].selected_choice_id = null;
    const api = {
      view: vi.fn().mockResolvedValue(view),
      apply: vi.fn().mockResolvedValue(savedData()),
    };
    page(api);
    await loaded();
    const update = screen.getByRole("button", { name: "Update my estimate" });
    expect(update.disabled).toBe(true);
    fireEvent.click(update);
    expect(api.apply).not.toHaveBeenCalled();
    fireEvent.click(screen.getByRole("radio", { name: "Gold for Downstairs" }));
    expect(update.disabled).toBe(false);
    expect(api.apply).not.toHaveBeenCalled();
  });

  it("applies only after the explicit button, sends allowed identity fields and hides stale review confirmation after another choice", async () => {
    const api = {
      view: vi.fn().mockResolvedValue(viewData()),
      apply: vi.fn().mockResolvedValue(savedData()),
    };
    page(api);
    await chooseGold();
    expect(api.apply).not.toHaveBeenCalled();
    fireEvent.click(screen.getByRole("button", { name: "Update my estimate" }));
    const link = await screen.findByRole("link", {
      name: "Review and approve in Zoho Books",
    });
    expect(api.apply).toHaveBeenCalledTimes(1);
    const operation = api.apply.mock.calls[0][0];
    expect(operation).toEqual({
      token,
      revision: 1,
      request_id: expect.stringMatching(
        /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i,
      ),
      selections: savedSelections,
    });
    for (const key of [
      "equipment",
      "items",
      "purchase_cost",
      "total",
      "addons",
    ])
      expect(JSON.stringify(operation)).not.toContain(`"${key}"`);
    expect(link.getAttribute("href")).toBe(reviewUrl);
    expect(
      screen.getByText(
        "Your Books estimate has been updated. This does not approve the estimate or make a payment.",
      ),
    ).toBeTruthy();
    fireEvent.click(screen.getByRole("radio", { name: "Gold for Downstairs" }));
    expect(
      screen.queryByRole("link", { name: "Review and approve in Zoho Books" }),
    ).toBeNull();
    expect(api.apply).toHaveBeenCalledTimes(1);
    fireEvent.click(screen.getByRole("button", { name: "Update my estimate" }));
    await waitFor(() => expect(api.apply).toHaveBeenCalledTimes(2));
    const next = api.apply.mock.calls[1][0];
    expect(next.revision).toBe(2);
    expect(next.request_id).not.toBe(operation.request_id);
  });

  it.each(["pending", "network", "provider"])(
    "freezes uncertain %s updates and reconciles only the identical explicit operation",
    async (kind) => {
      const api = {
        view: vi.fn().mockResolvedValue(viewData()),
        apply: vi.fn(),
      };
      if (kind === "pending")
        api.apply
          .mockResolvedValueOnce({
            saved: false,
            state: "pending",
            revision: 1,
          })
          .mockResolvedValueOnce(savedData());
      else
        api.apply
          .mockRejectedValueOnce(safeError(kind, kind === "network" ? 0 : 502))
          .mockResolvedValueOnce(savedData());
      page(api);
      await chooseGold();
      fireEvent.click(
        screen.getByRole("button", { name: "Update my estimate" }),
      );
      const check = await screen.findByRole("button", { name: "Check update" });
      expect(api.apply).toHaveBeenCalledTimes(1);
      const operation = structuredClone(api.apply.mock.calls[0][0]);
      for (const radio of screen.getAllByRole("radio"))
        expect(radio.matches(":disabled")).toBe(true);
      await userEvent
        .setup()
        .click(screen.getByRole("radio", { name: "Silver for Upstairs" }));
      expect(
        screen.getByRole("radio", { name: "Gold for Upstairs" }).checked,
      ).toBe(true);
      await act(async () => {});
      expect(api.apply).toHaveBeenCalledTimes(1);
      expect(document.body.textContent).not.toContain("RAW PRIVATE ERROR");
      expect(document.body.textContent).not.toContain(token);
      fireEvent.click(check);
      await screen.findByRole("link", {
        name: "Review and approve in Zoho Books",
      });
      expect(api.apply).toHaveBeenCalledTimes(2);
      expect(api.apply.mock.calls[1][0]).toEqual(operation);
    },
  );

  it.each(["stale_revision", "changed"])(
    "requires a fresh view after %s and never retries the old update",
    async (code) => {
      const fresh = viewData({ revision: 3 });
      const api = {
        view: vi
          .fn()
          .mockResolvedValueOnce(viewData())
          .mockResolvedValueOnce(fresh),
        apply: vi
          .fn()
          .mockRejectedValueOnce(safeError(code, 409))
          .mockResolvedValueOnce(savedData({ revision: 4 })),
      };
      page(api);
      await chooseGold();
      fireEvent.click(
        screen.getByRole("button", { name: "Update my estimate" }),
      );
      const refresh = await screen.findByRole("button", {
        name: "Refresh estimate",
      });
      const previous = api.apply.mock.calls[0][0];
      const update = screen.queryByRole("button", {
        name: "Update my estimate",
      });
      expect(!update || update.disabled).toBe(true);
      expect(api.apply).toHaveBeenCalledTimes(1);
      expect(document.body.textContent).not.toContain(token);
      fireEvent.click(refresh);
      await waitFor(() => expect(api.view).toHaveBeenCalledTimes(2));
      await loaded();
      fireEvent.click(screen.getByRole("radio", { name: "Gold for Upstairs" }));
      fireEvent.click(
        screen.getByRole("button", { name: "Update my estimate" }),
      );
      await waitFor(() => expect(api.apply).toHaveBeenCalledTimes(2));
      expect(api.apply.mock.calls[1][0].revision).toBe(3);
      expect(api.apply.mock.calls[1][0].request_id).not.toBe(
        previous.request_id,
      );
    },
  );

  it("restores an existing pending operation from view and reuses its request ID, revision and selections", async () => {
    const operation = {
      request_id: "10000000-0000-4000-8000-000000000099",
      revision: 1,
      selections: savedSelections,
      status: "pending",
    };
    const api = {
      view: vi
        .fn()
        .mockResolvedValue(viewData({ state: "pending", pending: operation })),
      apply: vi.fn().mockResolvedValue(savedData()),
    };
    page(api);
    const check = await screen.findByRole("button", { name: "Check update" });
    expect(api.apply).not.toHaveBeenCalled();
    expect(
      screen.getByRole("radio", { name: "Gold for Upstairs" }).checked,
    ).toBe(true);
    expect(
      screen.getAllByRole("radio").every((radio) => radio.matches(":disabled")),
    ).toBe(true);
    fireEvent.click(check);
    await screen.findByRole("link", {
      name: "Review and approve in Zoho Books",
    });
    expect(api.apply).toHaveBeenCalledExactlyOnceWith({
      token,
      revision: operation.revision,
      request_id: operation.request_id,
      selections: operation.selections,
    });
  });

  it("keeps an update locked after Books changes while final work is pending, then checks the identical original operation", async () => {
    const api = {
      view: vi.fn().mockResolvedValue(viewData()),
      apply: vi
        .fn()
        .mockResolvedValueOnce(
          savedData({ state: "books_updated", crm_sync_pending: true }),
        )
        .mockResolvedValueOnce(savedData()),
    };
    page(api);
    await chooseGold();
    fireEvent.click(screen.getByRole("button", { name: "Update my estimate" }));
    const check = await screen.findByRole("button", { name: "Check update" });
    const operation = structuredClone(api.apply.mock.calls[0][0]);
    expect(operation.revision).toBe(1);
    expect(
      screen.getAllByRole("radio").every((radio) => radio.matches(":disabled")),
    ).toBe(true);
    expect(
      screen.queryByRole("link", { name: "Review and approve in Zoho Books" }),
    ).toBeNull();
    expect(document.body.textContent).not.toMatch(/\bCRM\b/i);
    await userEvent
      .setup()
      .click(screen.getByRole("radio", { name: "Silver for Upstairs" }));
    expect(
      screen.getByRole("radio", { name: "Gold for Upstairs" }).checked,
    ).toBe(true);
    expect(api.apply).toHaveBeenCalledTimes(1);
    fireEvent.click(check);
    await screen.findByRole("link", {
      name: "Review and approve in Zoho Books",
    });
    expect(api.apply.mock.calls[1][0]).toEqual(operation);
  });

  it("recovers the original pre-update revision from a Books-updated-but-pending reload", async () => {
    const operation = {
      request_id: "10000000-0000-4000-8000-000000000088",
      revision: 1,
      selections: savedSelections,
      status: "books_updated",
    };
    const api = {
      view: vi.fn().mockResolvedValue(
        viewData({
          revision: 2,
          state: "books_updated",
          crm_sync_pending: true,
          pending: operation,
        }),
      ),
      apply: vi.fn().mockResolvedValue(savedData()),
    };
    page(api);
    const check = await screen.findByRole("button", { name: "Check update" });
    expect(api.apply).not.toHaveBeenCalled();
    expect(
      screen.queryByRole("link", { name: "Review and approve in Zoho Books" }),
    ).toBeNull();
    expect(
      screen.getAllByRole("radio").every((radio) => radio.matches(":disabled")),
    ).toBe(true);
    fireEvent.click(check);
    await screen.findByRole("link", {
      name: "Review and approve in Zoho Books",
    });
    expect(api.apply).toHaveBeenCalledExactlyOnceWith({
      token,
      revision: 1,
      request_id: operation.request_id,
      selections: operation.selections,
    });
  });

  it.each([
    { selections: [{ system_number: 1, choice_id: ids[0] }] },
    { revision: 0 },
    { total: NaN },
  ])(
    "does not claim a confirmed save from inconsistent success fields %j",
    async (invalid) => {
      const api = {
        view: vi.fn().mockResolvedValue(viewData()),
        apply: vi.fn().mockResolvedValue(savedData(invalid)),
      };
      page(api);
      await chooseGold();
      fireEvent.click(
        screen.getByRole("button", { name: "Update my estimate" }),
      );
      await screen.findByRole("button", { name: "Check update" });
      expect(api.apply).toHaveBeenCalledTimes(1);
      expect(
        screen.queryByRole("link", {
          name: "Review and approve in Zoho Books",
        }),
      ).toBeNull();
      expect(
        screen
          .getAllByRole("radio")
          .every((radio) => radio.matches(":disabled")),
      ).toBe(true);
    },
  );

  it("uses the fragment only, keeps the token out of DOM/storage/logs and renders provider text without HTML execution", async () => {
    const setItem = vi.spyOn(Storage.prototype, "setItem");
    const getItem = vi.spyOn(Storage.prototype, "getItem");
    const logSpies = ["log", "warn", "error", "info", "debug"].map((method) =>
      vi.spyOn(console, method).mockImplementation(() => {}),
    );
    const malicious = '<img src=x onerror="alert(1)">';
    const view = viewData();
    view.systems[0].choices[0].items[0].name = malicious;
    const api = { view: vi.fn().mockResolvedValue(view), apply: vi.fn() };
    page(api);
    await loaded();
    expect(screen.getByText(malicious)).toBeTruthy();
    expect(document.querySelector("img")).toBeNull();
    expect(document.body.innerHTML).not.toContain(token);
    expect(window.location.search).toBe("");
    expect(window.location.hash).toBe(`#token=${token}`);
    expect(setItem).not.toHaveBeenCalled();
    expect(getItem).not.toHaveBeenCalled();
    for (const spy of logSpies)
      expect(JSON.stringify(spy.mock.calls)).not.toContain(token);
    expect(api.apply).not.toHaveBeenCalled();
  });

  it.each(["", "#token=invalid"])(
    "does not make a public API request from a missing or invalid fragment %s",
    async (fragment) => {
      window.history.replaceState(
        null,
        "",
        `/customer-choices/?token=${token}${fragment}`,
      );
      const api = { view: vi.fn(), apply: vi.fn() };
      page(api);
      await act(async () => {});
      expect(api.view).not.toHaveBeenCalled();
      expect(api.apply).not.toHaveBeenCalled();
      expect(document.body.innerHTML).not.toContain(token);
      const update = screen.queryByRole("button", {
        name: "Update my estimate",
      });
      expect(!update || update.disabled).toBe(true);
    },
  );

  it("retries loading explicitly without exposing a raw token-bearing view failure", async () => {
    const api = {
      view: vi
        .fn()
        .mockRejectedValueOnce(safeError("unavailable", 401))
        .mockResolvedValueOnce(viewData()),
      apply: vi.fn(),
    };
    page(api);
    const retry = await screen.findByRole("button", {
      name: "Retry loading estimate",
    });
    expect(document.body.textContent).not.toContain("RAW PRIVATE ERROR");
    expect(document.body.textContent).not.toContain(token);
    expect(api.view).toHaveBeenCalledTimes(1);
    fireEvent.click(retry);
    await loaded();
    expect(api.view.mock.calls).toEqual([[token], [token]]);
    expect(api.apply).not.toHaveBeenCalled();
  });

  it.each([
    "http://books.zoho.com/portal/quote/fixture",
    "https://evil.test/portal/quote/fixture",
    "https://books.zoho.com.evil.test/portal/quote/fixture",
    "javascript:alert(1)",
    "https://books.zoho.com:444/portal/quote/fixture",
    "https://owner:secret@books.zoho.com/portal/quote/fixture",
    "https://books.zoho.com/app#/estimates",
    "https://books.zoho.com/app/estimates",
    "https://books.zoho.com/%61pp/estimates",
    "https://books.zoho.com/portal/quote/fixture#admin",
    "https://books.zoho.com/portal/quote/fixture#%61dmin",
  ])("does not expose an unsafe review destination %s", async (review_url) => {
    const api = {
      view: vi.fn().mockResolvedValue(viewData({ state: "saved", review_url })),
      apply: vi.fn(),
    };
    page(api);
    await loaded();
    expect(
      screen.queryByRole("link", { name: "Review and approve in Zoho Books" }),
    ).toBeNull();
    expect(screen.getByText(/Books review link is unavailable/)).toBeTruthy();
    expect(api.apply).not.toHaveBeenCalled();
  });

  it.each([
    "https://books.zoho.com/portal/quote/fixture",
    "https://securebooks.zoho.eu/portal/quote/fixture",
    "https://books.zoho.com.au/portal/quote/fixture",
    "https://books.zoho.sa/portal/quote/fixture",
  ])(
    "allows a confirmed customer review destination %s without writing",
    async (review_url) => {
      const api = {
        view: vi
          .fn()
          .mockResolvedValue(viewData({ state: "saved", review_url })),
        apply: vi.fn(),
      };
      page(api);
      expect(
        (
          await screen.findByRole("link", {
            name: "Review and approve in Zoho Books",
          })
        ).getAttribute("href"),
      ).toBe(review_url);
      expect(api.apply).not.toHaveBeenCalled();
    },
  );
});
