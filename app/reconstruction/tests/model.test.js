import { describe, it, expect, vi } from "vitest";
import {
  buildPayload,
  canAdvance,
  createInitialState,
  draftReducer,
} from "../src/model.js";
import {
  formatMoney,
  sumEquipment,
  filterEquipment,
  newIdempotencyKey,
} from "../src/lib/utils.js";

const fixture = () => ({
  ...createInitialState(),
  customer: {
    id: "2273343000000787001",
    name: "Synthetic Customer",
    email: "fixture@example.test",
    billing_address: { street: "Example Street" },
  },
  template: { id: 42 },
  systems: [
    {
      system_number: 1,
      system_label: "Main System",
      equipment: {
        furnace: {
          item_id: "2273343000000787011",
          name: "Fixture Furnace",
          rate: 3200,
          description: "",
          notes: "",
        },
        permit: {
          item_id: "2273343000000787012",
          rate: 0,
          description: "Municipal permit",
          notes: "Approved",
        },
        unused: null,
      },
    },
  ],
  pricing: { subtotal: 3200, total: 4000, deposit_percent: 35 },
  preview_html: "<p>Edited polished proposal</p>",
  books_edits: {
    line_items: [
      { key: "system_1_furnace_0", description: "Edited Books text" },
    ],
    notes: "",
    terms: "Fixture terms",
  },
});

describe("reference-compatible draft model", () => {
  it("starts fresh in memory with the v0.4.8 defaults", () => {
    const first = createInitialState(),
      second = createInitialState();
    expect(first.step).toBe("customer");
    expect(first.template).toBeNull();
    expect(first.systems[0]).toEqual({
      system_number: 1,
      system_label: "Main System",
      equipment: {},
    });
    expect(first.pricing).toEqual({
      subtotal: 0,
      total: 0,
      deposit_percent: 35,
    });
    expect(first.options).toMatchObject({
      warranty_parts_years: 10,
      warranty_labor_years: 10,
      financing_term_months: 60,
      financing_requested: false,
    });
    first.systems[0].equipment.furnace = {};
    expect(second.systems[0].equipment).toEqual({});
  });
  it.each([
    { type: "setCustomer", customer: { id: "new" } },
    { type: "setTemplate", template: { id: 2 } },
    { type: "setSystems", systems: [] },
    { type: "setOptions", options: { special_notes: "changed" } },
    { type: "setPricing", pricing: { total: 5000 } },
  ])("invalidates both previews after $type", (action) => {
    const next = draftReducer(fixture(), action);
    expect(next.preview_html).toBe("");
    expect(next.books_edits).toBeNull();
  });
  it("keeps manual project total when options/subtotal change", () => {
    let draft = fixture();
    draft = draftReducer(draft, {
      type: "setOptions",
      options: {
        rebates: [{ name: "Fixture rebate", amount: 900 }],
        financing_fee_applies: true,
      },
    });
    draft = draftReducer(draft, {
      type: "setPricing",
      pricing: { subtotal: 3500 },
    });
    expect(draft.pricing.total).toBe(4000);
    expect(draft.pricing.subtotal).toBe(3500);
  });
  it("uses template zero defaults and preserves defaults for absent fields", () => {
    const draft = draftReducer(fixture(), {
      type: "setTemplate",
      template: { id: 99, default_warranty_parts: 0 },
    });
    expect(draft.options.warranty_parts_years).toBe(0);
    expect(draft.options.warranty_labor_years).toBe(10);
  });
  it("allocates a key once across retry/navigation and explicitly replaces it only after reset", () => {
    let state = draftReducer(fixture(), { type: "allocateIdempotencyKey" });
    const key = state.idempotency_key;
    expect(key).toMatch(/^tc-[0-9a-f-]{36}$/i);
    state = draftReducer(state, { type: "allocateIdempotencyKey" });
    expect(state.idempotency_key).toBe(key);
    state = draftReducer(state, { type: "setStep", step: "review" });
    expect(state.idempotency_key).toBe(key);
    state = draftReducer(state, { type: "resetIdempotencyKey" });
    expect(state.idempotency_key).toBeNull();
    expect(
      draftReducer(state, { type: "allocateIdempotencyKey" }).idempotency_key,
    ).not.toBe(key);
  });
  it("retains the reference restart edge case: reset clears template and both previews", () => {
    expect(draftReducer(fixture(), { type: "reset" })).toEqual(
      createInitialState(),
    );
  });
  it("gates each step as the bundle does, without inventing required slots", () => {
    let draft = fixture();
    expect(canAdvance(draft)).toBe(true);
    expect(canAdvance({ ...draft, template: null })).toBe(false);
    draft = { ...draft, step: "systems" };
    expect(canAdvance(draft)).toBe(true);
    expect(
      canAdvance({ ...draft, systems: [...draft.systems, { equipment: {} }] }),
    ).toBe(false);
    expect(
      canAdvance({ ...draft, step: "pricing", pricing: { total: 0 } }),
    ).toBe(false);
    expect(canAdvance({ ...draft, step: "review" })).toBe(true);
    expect(canAdvance({ ...draft, step: "success" })).toBe(false);
  });
  it("sends string IDs and allowed selected-item fields, never hydrated customer/catalog or polished HTML", () => {
    const draft = fixture(),
      payload = buildPayload(draft);
    expect(payload.customer).toEqual({
      zoho_account_id: "2273343000000787001",
    });
    expect(payload.systems[0].equipment).toEqual({
      furnace: { item_id: "2273343000000787011", description: "" },
      permit: {
        item_id: "2273343000000787012",
        description: "Municipal permit",
        notes: "Approved",
        rate: 0,
      },
    });
    expect(payload.books_edits).toBe(draft.books_edits);
    expect(payload).not.toHaveProperty("preview_html");
    expect(payload).not.toHaveProperty("idempotency_key");
    expect(JSON.stringify(payload)).not.toContain("fixture@example.test");
    expect(buildPayload(draft, false)).not.toHaveProperty("books_edits");
  });
});

describe("pricing, catalog search and identity utilities", () => {
  it("rounds finite numeric rates only; project total/rebates are separate", () => {
    expect(
      sumEquipment([
        {
          equipment: {
            a: { rate: 0.1 },
            b: { rate: 0.2 },
            c: { rate: "9" },
            d: { rate: Infinity },
            e: null,
          },
        },
      ]),
    ).toBe(0.3);
    expect(sumEquipment(null)).toBe(0);
    expect(formatMoney(NaN)).toBe("$0.00");
    expect(formatMoney(1200.5)).toBe("$1,200.50");
  });
  it("filters the same seven catalog fields locally without modifying the array", () => {
    for (const field of [
      "brand",
      "model",
      "name",
      "sku",
      "js_part_number",
      "mfg_part_number",
      "short_description",
    ]) {
      const rows = [{ [field]: "Fixture NeedLe" }, { name: "other" }];
      expect(filterEquipment(rows, " needle ")).toEqual([rows[0]]);
      expect(filterEquipment(rows, "")).toBe(rows);
    }
  });
  it("uses a supplied crypto UUID and keeps the prefix", () => {
    const spy = vi
      .spyOn(crypto, "randomUUID")
      .mockReturnValue("00000000-0000-4000-8000-000000000000");
    expect(newIdempotencyKey("test")).toBe(
      "test-00000000-0000-4000-8000-000000000000",
    );
    spy.mockRestore();
  });
});
