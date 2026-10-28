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
  equipmentCostSummary,
  filterEquipment,
  newIdempotencyKey,
  selectedEquipment,
  selectedChoice,
  systemSalesPrice,
  sumSelectedSales,
  hasSystemChoices,
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
  pricing: {
    subtotal: 3200,
    equipment_cost: 2000,
    equipment_cost_manual: true,
    total: 4000,
    total_manual: true,
    deposit_percent: 35,
  },
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
  it("starts fresh in memory with automatic cost and sales pricing enabled", () => {
    const first = createInitialState(),
      second = createInitialState();
    expect(first.step).toBe("customer");
    expect(first.template).toBeNull();
    expect(first.systems[0]).toEqual({
      system_number: 1,
      system_label: "Main System",
      equipment: {},
    });
    expect(first.pricing).toMatchObject({
      subtotal: 0,
      equipment_cost: 0,
      equipment_cost_manual: false,
      total: 0,
      total_manual: false,
      deposit_percent: 35,
    });
    expect(first.options).toMatchObject({
      warranty_parts_years: 10,
      warranty_labor_years: 10,
      financing_term_months: 60,
      financing_requested: false,
      include_maintenance: false,
      include_permits: false,
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
    expect(payload.pricing).toEqual({
      subtotal: 3200,
      total: 4000,
      deposit_percent: 35,
    });
    expect(payload.pricing).not.toHaveProperty("equipment_cost");
    expect(payload.pricing).not.toHaveProperty("equipment_cost_manual");
    expect(payload.pricing).not.toHaveProperty("total_manual");
    expect(payload.pricing).not.toHaveProperty("project_adjustment");
  });
  it("keeps a selected package as one priced item and sends its canonical slot without hydrated component data", () => {
    const draft = fixture();
    draft.systems[0].equipment = {
      package: {
        item_id: "2273343000000787099",
        selection_category: "package",
        is_composite: true,
        tons: 1.5,
        btu_input: 100000,
        rate: 6200,
        purchase_cost: 3200,
        description: "Edited complete package scope",
        component_items: [
          { item_id: "child", tons: 2, rate: 900, purchase_cost: 700 },
        ],
      },
      permit: {
        item_id: "permit",
        description: "Municipal permit",
        rate: 125,
        purchase_cost: 100,
      },
    };
    expect(sumEquipment(draft.systems)).toBe(6325);
    expect(equipmentCostSummary(draft.systems)).toEqual({
      subtotal: 3300,
      knownSubtotal: 3300,
      missingCount: 0,
      selectedCount: 2,
    });
    expect(buildPayload(draft).systems[0].equipment).toEqual({
      package: {
        item_id: "2273343000000787099",
        description: "Edited complete package scope",
      },
      permit: { item_id: "permit", description: "Municipal permit", rate: 125 },
    });
    expect(JSON.stringify(buildPayload(draft))).not.toContain(
      "component_items",
    );
  });

  it("updates sales total and purchase cost atomically with system selection until each is explicitly overridden", () => {
    const systems = (rate, purchase_cost) => [
      {
        system_number: 1,
        system_label: "Main System",
        equipment: { package: { item_id: "package", rate, purchase_cost } },
      },
    ];
    let state = draftReducer(createInitialState(), {
      type: "setSystems",
      systems: systems(5000, 3000),
    });
    expect(state.pricing).toMatchObject({
      subtotal: 5000,
      total: 5000,
      total_manual: false,
      equipment_cost: 3000,
      equipment_cost_manual: false,
    });
    state = draftReducer(state, {
      type: "setPricing",
      pricing: { total: 6200 },
    });
    state = draftReducer(state, {
      type: "setPricing",
      pricing: { equipment_cost: 3500 },
    });
    expect(state.pricing).toMatchObject({
      total_manual: true,
      equipment_cost_manual: true,
    });
    state = draftReducer(state, {
      type: "setSystems",
      systems: systems(5500, 3100),
    });
    expect(state.pricing).toMatchObject({
      subtotal: 5500,
      total: 6200,
      equipment_cost: 3500,
    });
    state = draftReducer(state, {
      type: "setPricing",
      pricing: { total: state.pricing.subtotal, total_manual: false },
    });
    state = draftReducer(state, {
      type: "setSystems",
      systems: systems(5800, 3300),
    });
    expect(state.pricing).toMatchObject({
      subtotal: 5800,
      total: 5800,
      total_manual: false,
      equipment_cost: 3500,
      equipment_cost_manual: true,
    });
    expect(buildPayload(state).pricing).toEqual({
      subtotal: 5800,
      total: 5800,
      deposit_percent: 35,
    });
  });

  it("keeps unknown purchase cost unknown instead of substituting sales and treats explicit zero overrides as manual", () => {
    const systems = [
      {
        system_number: 1,
        system_label: "Main System",
        equipment: {
          furnace: { item_id: "furnace", rate: 5000, purchase_cost: null },
          permit: { item_id: "permit", rate: 125, purchase_cost: 0 },
        },
      },
    ];
    let state = draftReducer(createInitialState(), {
      type: "setSystems",
      systems,
    });
    expect(state.pricing).toMatchObject({
      subtotal: 5125,
      total: 5125,
      equipment_cost: null,
      equipment_cost_manual: false,
    });
    state = draftReducer(state, {
      type: "setPricing",
      pricing: { equipment_cost: 0, total: 0 },
    });
    expect(state.pricing).toMatchObject({
      equipment_cost: 0,
      equipment_cost_manual: true,
      total: 0,
      total_manual: true,
    });
    state = draftReducer(state, { type: "setSystems", systems: [] });
    expect(state.pricing).toMatchObject({
      subtotal: 0,
      total: 0,
      equipment_cost: 0,
      equipment_cost_manual: true,
    });
    state = draftReducer(state, {
      type: "setPricing",
      pricing: {
        equipment_cost: 0,
        equipment_cost_manual: false,
        total: 0,
        total_manual: false,
      },
    });
    state = draftReducer(state, { type: "setSystems", systems });
    expect(state.pricing).toMatchObject({
      subtotal: 5125,
      total: 5125,
      equipment_cost: null,
      equipment_cost_manual: false,
    });
  });
});

const choiceIds = [
  "00000000-0000-4000-8000-000000000001",
  "00000000-0000-4000-8000-000000000002",
  "00000000-0000-4000-8000-000000000003",
  "00000000-0000-4000-8000-000000000004",
];
function quotedChoice(
  index,
  label,
  rate,
  purchase_cost,
  sales_price = rate,
  sales_price_manual = false,
) {
  return {
    choice_id: choiceIds[index],
    label,
    sales_price,
    sales_price_manual,
    equipment: {
      package: {
        item_id: `package-${index}`,
        name: `${label} package`,
        category_2: label,
        rate,
        purchase_cost,
        description: `${label} scope`,
        notes: `${label} notes`,
      },
    },
  };
}
function quotedSystem(number, choices, selectedIndex = 0) {
  return {
    system_number: number,
    system_label: `System ${number}`,
    system_id: `10000000-0000-4000-8000-${String(number).padStart(12, "0")}`,
    choices,
    selected_choice_id: choices[selectedIndex].choice_id,
  };
}

describe("alternatives within physical systems", () => {
  it("counts one selected alternative per physical system and excludes unknown inactive purchase cost", () => {
    const silver = quotedChoice(0, "Silver", 5000, 3000);
    const gold = quotedChoice(1, "Gold", 6000, 4000, 6500, true);
    const downstairs = quotedChoice(2, "Downstairs", 3000, 1000);
    const inactive = quotedChoice(3, "Platinum", 20000, null);
    const systems = [
      quotedSystem(1, [silver, gold], 1),
      quotedSystem(2, [downstairs, inactive]),
    ];
    expect(hasSystemChoices(systems)).toBe(true);
    expect(selectedChoice(systems[0])).toEqual(gold);
    expect(selectedEquipment(systems[0])).toEqual(gold.equipment);
    expect(systemSalesPrice(systems[0])).toBe(6500);
    expect(sumEquipment(systems)).toBe(9000);
    expect(sumSelectedSales(systems)).toBe(9500);
    expect(equipmentCostSummary(systems)).toEqual({
      subtotal: 5000,
      knownSubtotal: 5000,
      selectedCount: 2,
      missingCount: 0,
    });
    const state = draftReducer(createInitialState(), {
      type: "setSystems",
      systems,
    });
    expect(state.pricing).toMatchObject({
      subtotal: 9000,
      total: 9500,
      equipment_cost: 5000,
      project_adjustment: 0,
    });
  });

  it("supports unchanged legacy equipment and refuses a stale mirror when a selected choice ID is invalid", () => {
    const legacy = fixture().systems[0];
    expect(hasSystemChoices([legacy])).toBe(false);
    expect(selectedEquipment(legacy)).toEqual(legacy.equipment);
    expect(systemSalesPrice(legacy)).toBe(3200);
    const invalid = {
      ...quotedSystem(1, [quotedChoice(0, "Silver", 5000, 3000)]),
      selected_choice_id: choiceIds[3],
      equipment: legacy.equipment,
    };
    expect(selectedChoice(invalid)).toBeNull();
    expect(selectedEquipment(invalid)).toEqual({});
    expect(sumEquipment([invalid])).toBe(0);
    expect(sumSelectedSales([invalid])).toBe(0);
    expect(
      canAdvance({ ...fixture(), step: "systems", systems: [invalid] }),
    ).toBe(false);
    expect(
      buildPayload({ ...fixture(), systems: [invalid] }).systems[0].equipment,
    ).toEqual({});
  });

  it("recalculates each automatic alternative but preserves explicitly edited alternative sales prices", () => {
    const silver = quotedChoice(0, "Silver", 5000, 3000, 1, false);
    const gold = quotedChoice(1, "Gold", 6000, 4000, 6500, true);
    const systems = [quotedSystem(1, [silver, gold])];
    const state = draftReducer(createInitialState(), {
      type: "setSystems",
      systems,
    });
    expect(state.systems[0].choices[0].sales_price).toBe(5000);
    expect(state.systems[0].choices[1].sales_price).toBe(6500);
    expect(state.systems[0].choices[1].sales_price_manual).toBe(true);
    expect(state.pricing.total).toBe(5000);
    expect(silver.sales_price).toBe(1);
  });

  it.each([6200, 4500])(
    "preserves legacy manual total %i as a project adjustment and retains that adjustment when choices switch",
    (manualTotal) => {
      let state = draftReducer(createInitialState(), {
        type: "setSystems",
        systems: [
          {
            system_number: 1,
            system_label: "Main System",
            equipment: {
              package: {
                item_id: "package-0",
                rate: 5000,
                purchase_cost: 3000,
              },
            },
          },
        ],
      });
      state = draftReducer(state, {
        type: "setPricing",
        pricing: { total: manualTotal, equipment_cost: 3500 },
      });
      const silver = quotedChoice(0, "Silver", 5000, 3000);
      const gold = quotedChoice(1, "Gold", 6000, null, 6500, true);
      state = draftReducer(state, {
        type: "setSystems",
        systems: [quotedSystem(1, [silver, gold])],
      });
      const adjustment = manualTotal - 5000;
      expect(state.pricing).toMatchObject({
        total: manualTotal,
        project_adjustment: adjustment,
        equipment_cost: 3500,
        equipment_cost_manual: true,
      });
      state = draftReducer(state, {
        type: "setSystems",
        systems: [{ ...state.systems[0], selected_choice_id: gold.choice_id }],
      });
      expect(state.pricing.total).toBe(6500 + adjustment);
      expect(state.pricing.project_adjustment).toBe(adjustment);
      expect(state.pricing.equipment_cost).toBe(3500);
      expect(equipmentCostSummary(state.systems).missingCount).toBe(1);
      state = draftReducer(state, {
        type: "setPricing",
        pricing: { total: 8000 },
      });
      expect(state.pricing.project_adjustment).toBe(1500);
      state = draftReducer(state, {
        type: "setSystems",
        systems: [
          { ...state.systems[0], selected_choice_id: silver.choice_id },
        ],
      });
      expect(state.pricing.total).toBe(6500);
      expect(state.pricing.project_adjustment).toBe(1500);
    },
  );

  it("projects only selected equipment while sanitizing alternative metadata and excluding internal IDs, costs and flags", () => {
    const silver = quotedChoice(0, "Silver", 5000, 3000);
    const gold = quotedChoice(1, "Gold", 6000, null, 6500, true);
    const state = draftReducer(
      { ...fixture(), pricing: { ...createInitialState().pricing } },
      { type: "setSystems", systems: [quotedSystem(1, [silver, gold], 1)] },
    );
    const payload = buildPayload(state);
    expect(payload.systems[0].equipment).toEqual({
      package: {
        item_id: "package-1",
        description: "Gold scope",
        notes: "Gold notes",
      },
    });
    expect(payload.systems[0].selected_choice_id).toBe(gold.choice_id);
    expect(payload.systems[0].choices).toEqual([
      {
        choice_id: silver.choice_id,
        label: "Silver",
        sales_price: 5000,
        equipment: {
          package: {
            item_id: "package-0",
            description: "Silver scope",
            notes: "Silver notes",
          },
        },
      },
      {
        choice_id: gold.choice_id,
        label: "Gold",
        sales_price: 6500,
        equipment: {
          package: {
            item_id: "package-1",
            description: "Gold scope",
            notes: "Gold notes",
          },
        },
      },
    ]);
    expect(payload.pricing).toMatchObject({
      subtotal: 6000,
      total: 6500,
      project_adjustment: 0,
    });
    for (const forbidden of [
      "system_id",
      "purchase_cost",
      "equipment_cost",
      "sales_price_manual",
      "total_manual",
      "category_2",
    ])
      expect(JSON.stringify(payload)).not.toContain(`"${forbidden}"`);
  });

  it("sends free optional items once as project booleans and never charges their renewal rate", () => {
    let state = draftReducer(createInitialState(), {
      type: "setSystems",
      systems: [
        quotedSystem(1, [quotedChoice(0, "Silver", 5000, 3000)]),
        quotedSystem(2, [quotedChoice(1, "Gold", 6000, 4000)]),
      ],
    });
    state = draftReducer(state, {
      type: "setOptions",
      options: { include_maintenance: true, include_permits: true },
    });
    expect(state.pricing.total).toBe(11000);
    const payload = buildPayload(state);
    expect(payload.options).toMatchObject({
      include_maintenance: true,
      include_permits: true,
    });
    expect(payload.systems).toHaveLength(2);
    expect(
      payload.systems.every(
        (system) =>
          !Object.prototype.hasOwnProperty.call(
            system,
            "include_maintenance",
          ) && !Object.prototype.hasOwnProperty.call(system, "include_permits"),
      ),
    ).toBe(true);
    expect(JSON.stringify(payload)).not.toContain("349");
    expect(payload.pricing.total).toBe(11000);
  });
});

describe("pricing, catalog search and identity utilities", () => {
  it("reports known purchase cost separately from sales and never invents missing purchase costs", () => {
    const systems = [
      {
        equipment: {
          known: { item_id: "known", purchase_cost: 123.455, rate: 1000 },
          zero: { item_id: "zero", purchase_cost: 0, rate: 200 },
          unknown: { item_id: "unknown", purchase_cost: null, rate: 500 },
          text: { item_id: "text", purchase_cost: "15", rate: 500 },
          negative: { item_id: "negative", purchase_cost: -1, rate: 500 },
          infinite: { item_id: "infinite", purchase_cost: Infinity, rate: 500 },
          unused: null,
        },
      },
    ];
    expect(equipmentCostSummary(systems)).toEqual({
      subtotal: null,
      knownSubtotal: 123.46,
      missingCount: 4,
      selectedCount: 6,
    });
    expect(sumEquipment(systems)).toBe(3200);
    expect(equipmentCostSummary(null)).toEqual({
      subtotal: 0,
      knownSubtotal: 0,
      missingCount: 0,
      selectedCount: 0,
    });
    expect(
      equipmentCostSummary([
        { equipment: { a: { purchase_cost: 0.1 }, b: { purchase_cost: 0.2 } } },
      ]).subtotal,
    ).toBe(0.3);
  });

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
