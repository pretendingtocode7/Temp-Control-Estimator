// Synthetic local-only catalog and business responses. No provider is contacted.
import { expect } from "@playwright/test";
import fs from "node:fs";
import path from "node:path";
import { createHash } from "node:crypto";
import { inspectFixturePdf } from "../parity/pdf-inspect.js";

export const origin = "http://127.0.0.1:4191";
export const category2Values = [
  "Standard-Efficiency SILVER",
  "High-Efficiency SILVER",
  "Standard-Efficiency GOLD",
  "High-Efficiency GOLD",
  "Standard-Efficiency PLATINUM",
  "High-Efficiency PLATINUM",
];
export const permitParagraphs = [
  "Permit fees are not included in the project total.",
  "Temp Control will prepare and complete all required permit paperwork and submit the necessary documentation to the local township on the homeowner’s behalf.",
  "All work will be performed in accordance with local building codes and manufacturer specifications. Permit payment, pickup and scheduling of Inspections are the responsibility of the homeowner.",
];
export const account = {
  id: "20000000001",
  name: "Synthetic Customer",
  email: "customer@example.test",
  phone: "555-0100",
  billing_address: {
    street: "100 Example Street",
    city: "ExampleCity",
    state: "NJ",
    zip: "00000",
    country: "USA",
  },
};
export const addonNames = {
  maintenance: "New Residential System Maintenance Agreement",
  permits: "Permits & Inspections",
};
export const addons = {
  maintenance: {
    available: true,
    addon_key: "maintenance",
    item_id: "90000000007",
    name: addonNames.maintenance,
    rate: 0,
    quantity: 1,
    renewal_rate: 349,
    renewal_units: 1,
    source: "zoho_books",
  },
  permits: {
    available: true,
    addon_key: "permits",
    item_id: "90000000008",
    name: addonNames.permits,
    rate: 0,
    quantity: 1,
    source: "zoho_books",
  },
};
const item = (id, name, details = {}) => ({
  item_id: String(id),
  name,
  model: `FIXTURE-${id}`,
  brand: "Synthetic",
  sku: `FIX-${id}`,
  selection_category: "package",
  equipment_type: "package",
  is_composite: true,
  source: "zoho_books",
  tags: ["Package"],
  short_description: `${name} scope — café`,
  rate: 7000,
  purchase_cost: null,
  purchase_cost_status: "unknown",
  purchase_cost_source: null,
  category_2: category2Values[0],
  tons: 2,
  btu_input: 80000,
  efficiency_percent: 80,
  ...details,
});
const tierNames = ["Silver", "Gold", "Platinum"];
export const offers = ["Lower", "Upper"].map((system, systemIndex) =>
  tierNames.map((tier, tierIndex) => {
    const offset = systemIndex * 3 + tierIndex;
    return item(90000000001 + offset, `Synthetic ${system} ${tier} Package`, {
      rate: 7001 + offset,
      model: `FIXTURE-${offset}`,
      short_description: `Synthetic installation scope ${offset}`,
      category_2: category2Values[tierIndex * 2 + systemIndex],
      fixture_purchase_cost: [4000, 5000, 6000, 4500, 5500, 6500][offset],
      fixture_sales_price: [
        [8000, 10000, 12000],
        [9000, 11000, 13000],
      ][systemIndex][tierIndex],
    });
  }),
);
export const paginated = Array.from({ length: 130 }, (_, index) =>
  item(
    91000000001 + index,
    `Synthetic Category2 Package ${String(index + 1).padStart(3, "0")}`,
  ),
);
export const combined = [
  item(92000000001, "Synthetic Combination Target", {
    category_2: category2Values[5],
    btu_input: 100000,
    tons: 1.5,
    efficiency_percent: 96,
  }),
  item(92000000002, "Synthetic Combination Other Category2", {
    category_2: category2Values[4],
    btu_input: 100000,
    tons: 1.5,
    efficiency_percent: 96,
  }),
  item(92000000003, "Synthetic Combination Other BTU", {
    category_2: category2Values[5],
    btu_input: 80000,
    tons: 1.5,
    efficiency_percent: 96,
  }),
  item(92000000004, "Synthetic Combination Other Tons", {
    category_2: category2Values[5],
    btu_input: 100000,
    tons: 3,
    efficiency_percent: 96,
  }),
  item(92000000005, "Synthetic Combination Other Efficiency", {
    category_2: category2Values[5],
    btu_input: 100000,
    tons: 1.5,
    efficiency_percent: 80,
  }),
];
export const ac = item(93000000001, "Synthetic AC", {
  selection_category: "ac",
  equipment_type: "condenser",
  is_composite: false,
  category_2: null,
  purchase_cost: 1200,
  purchase_cost_status: "known",
  purchase_cost_source: "books_purchase_rate",
});
export const allItems = [...offers.flat(), ...paginated, ...combined, ac];
const publicItem = ({
  fixture_purchase_cost,
  fixture_sales_price,
  ...catalog
}) => catalog;
const detailFor = (catalog) => ({
  ...publicItem(catalog),
  purchase_cost: catalog.fixture_purchase_cost ?? catalog.purchase_cost ?? 3500,
  purchase_cost_status: "known",
  purchase_cost_source: catalog.is_composite
    ? "books_mapped_items"
    : "books_purchase_rate",
});
const rendererPath = process.env.OPTIONS_PREVIEW_FIXTURE;
const rendererBytes = rendererPath ? fs.readFileSync(rendererPath) : null;
const rendererEnvelope = rendererBytes ? JSON.parse(rendererBytes) : null;
const rendererFixture = rendererEnvelope?.data || rendererEnvelope;
export const previewSource = rendererFixture
  ? {
      mode: "actual-php-fixture",
      file: path.basename(rendererPath),
      sha256: createHash("sha256").update(rendererBytes).digest("hex"),
    }
  : { mode: "synthetic-browser-fixture" };
export const template = {
  id: rendererFixture?.template_id || 1,
  name: "Synthetic Alternatives Proposal",
  template_type: "full_replacement",
  default_warranty_parts: 10,
  default_warranty_labor: 10,
  active: true,
  version: 1,
  required_slots: [],
  supports_financing: true,
  supports_rebates: true,
};

export function assertFinalPayload(payload) {
  expect(payload.customer).toEqual({ zoho_account_id: account.id });
  expect(payload.systems).toHaveLength(2);
  const ids = [];
  for (const [systemIndex, system] of payload.systems.entries()) {
    expect(system.system_number).toBe(systemIndex + 1);
    expect(system.system_label).toBe(
      ["Lower System", "Upper System"][systemIndex],
    );
    expect(system.choices).toHaveLength(3);
    for (const [tierIndex, choice] of system.choices.entries()) {
      ids.push(choice.choice_id);
      expect(choice.label).toBe(tierNames[tierIndex]);
      expect(choice.sales_price).toBe(
        offers[systemIndex][tierIndex].fixture_sales_price,
      );
      expect(Object.keys(choice.equipment)).toEqual(["package"]);
      expect(choice.equipment.package.item_id).toBe(
        offers[systemIndex][tierIndex].item_id,
      );
      expect(choice.equipment.package.description).toBe(
        tierIndex === 2
          ? `${["Lower", "Upper"][systemIndex]} Platinum scope — café`
          : offers[systemIndex][tierIndex].short_description,
      );
    }
    expect(system.selected_choice_id).toBe(system.choices[2].choice_id);
    expect(system.equipment).toEqual(system.choices[2].equipment);
  }
  expect(new Set(ids).size).toBe(6);
  expect(payload.pricing).toEqual({
    subtotal: 14009,
    total: 25500,
    deposit_percent: 35,
    project_adjustment: 500,
  });
  expect(payload.options).toMatchObject({
    include_maintenance: true,
    include_permits: true,
  });
  for (const privateName of [
    "purchase_cost",
    "equipment_cost",
    "sales_price_manual",
    "fixture_",
  ])
    expect(JSON.stringify(payload)).not.toContain(privateName);
}

function previewFor(payload) {
  assertFinalPayload(payload);
  if (process.env.OPTIONS_REQUIRE_PHP_FIXTURE === "1")
    expect(previewSource.mode).toBe("actual-php-fixture");
  if (rendererFixture) return rendererFixture;
  throw new Error(
    "A real PHP-rendered six-option fixture is required for the proposal scenario.",
  );
}

export async function installFixture(page, { addonMode = "available" } = {}) {
  const state = {
    requests: [],
    outside: [],
    errors: [],
    details: [],
    previews: [],
    generated: [],
    generateCount: 0,
    sendCount: 0,
    sendStatusCount: 0,
    addonRequests: 0,
    addonMode,
    delayedCompleted: 0,
    previewSource,
  };
  page.on("pageerror", (error) => state.errors.push(error.message));
  await page
    .context()
    .addCookies([
      { name: "fixture_session", value: "synthetic-only", url: origin },
    ]);
  await page.addInitScript(() => {
    let id = 0;
    Object.defineProperty(crypto, "randomUUID", {
      value: () => `00000000-0000-4000-8000-${String(++id).padStart(12, "0")}`,
    });
    Object.defineProperty(navigator, "serviceWorker", {
      value: {
        register: async () => ({ addEventListener() {}, installing: null }),
        controller: null,
      },
    });
  });
  await page.route("**/*", async (route) => {
    const request = route.request(),
      url = new URL(request.url());
    if (url.origin !== origin) {
      state.outside.push(request.url());
      return route.abort("blockedbyclient");
    }
    if (!url.pathname.startsWith("/wp-json/")) return route.continue();
    const headers = await request.allHeaders();
    expect(headers.cookie).toContain("fixture_session=synthetic-only");
    expect(headers["x-wp-nonce"]).toBe("options-fixture-nonce");
    const body = request.postData()
      ? JSON.parse(request.postData())
      : undefined;
    const endpoint = url.pathname.replace("/wp-json/tc-estimate/v1", "");
    state.requests.push({
      method: request.method(),
      endpoint,
      query: Object.fromEntries(url.searchParams),
      body,
      idempotency: headers["idempotency-key"],
    });
    const respond = (data, status = 200) =>
      route.fulfill({
        status,
        contentType: "application/json",
        body: JSON.stringify({ ok: true, data }),
      });
    if (endpoint === "/templates") return respond([template]);
    if (endpoint === "/customers")
      return respond(
        url.searchParams.get("q")?.toLowerCase().includes("synthetic")
          ? [account]
          : [],
      );
    if (endpoint === "/equipment/addons") {
      state.addonRequests++;
      expect(request.method()).toBe("GET");
      if (state.addonMode === "error")
        return route.fulfill({
          status: 503,
          contentType: "application/json",
          body: JSON.stringify({
            ok: false,
            error: {
              code: "synthetic_addons_unavailable",
              message: "Synthetic optional items temporarily unavailable.",
            },
          }),
        });
      return respond(
        state.addonMode === "unavailable"
          ? { maintenance: { available: false }, permits: { available: false } }
          : addons,
      );
    }
    if (endpoint === "/equipment" && url.searchParams.get("selector") === "1") {
      expect(request.method()).toBe("GET");
      const query = Object.fromEntries(url.searchParams),
        category = query.category || "package";
      expect(["package", "ac", "furnace", "coil"]).toContain(category);
      expect(query.limit).toBe("50");
      if (query.category2) expect(category2Values).toContain(query.category2);
      const categoryItems = allItems.filter(
        (entry) => entry.selection_category === category,
      );
      let rows = categoryItems;
      if (query.q === "slow-category2") {
        rows = [combined[0]];
        await new Promise((resolve) => setTimeout(resolve, 650));
      } else if (query.q)
        rows = rows.filter((entry) =>
          `${entry.name} ${entry.model} ${entry.sku}`
            .toLowerCase()
            .includes(query.q.toLowerCase().trim()),
        );
      for (const [field, property] of [
        ["category2", "category_2"],
        ["btu", "btu_input"],
        ["tons", "tons"],
        ["efficiency", "efficiency_percent"],
      ])
        if (query[field])
          rows = rows.filter(
            (entry) =>
              entry[property] ===
              (field === "category2" ? query[field] : Number(query[field])),
          );
      const pageNumber = Number(query.page || 1);
      const result = await respond({
        items: rows
          .slice((pageNumber - 1) * 50, pageNumber * 50)
          .map(publicItem),
        total: rows.length,
        page: pageNumber,
        per_page: 50,
        has_more: pageNumber * 50 < rows.length,
        facets: {
          btus: [80000, 100000],
          tons: [1.5, 2, 3],
          efficiencies: [80, 96],
          category2: category2Values.filter((value) =>
            categoryItems.some((entry) => entry.category_2 === value),
          ),
        },
      });
      if (query.q === "slow-category2") state.delayedCompleted++;
      return result;
    }
    const detailId = endpoint.match(/^\/equipment\/(\d+)$/)?.[1];
    if (detailId) {
      const catalog = allItems.find((entry) => entry.item_id === detailId);
      expect(catalog).toBeTruthy();
      state.details.push(detailId);
      return respond(detailFor(catalog));
    }
    if (endpoint === "/equipment")
      return respond(
        allItems
          .slice(0, Number(url.searchParams.get("limit") || 50))
          .map(publicItem),
      );
    if (endpoint === "/preview") {
      state.previews.push(body);
      return respond(previewFor(body));
    }
    if (endpoint === "/generate") {
      assertFinalPayload(body);
      state.generated.push(body);
      state.generateCount++;
      expect(headers["idempotency-key"]).toMatch(/^tc-/);
      const lines = body.books_edits.line_items;
      expect(lines.map((line) => line.item_id)).toEqual([
        offers[0][2].item_id,
        offers[1][2].item_id,
        addons.maintenance.item_id,
        addons.permits.item_id,
      ]);
      expect(lines.slice(2).map((line) => line.rate)).toEqual([0, 0]);
      return respond(
        {
          estimate_id: "50000000001",
          estimate_number: "EST-OPTIONS-FIXTURE-1",
          estimate_status: "draft",
          estimate_url: "https://books.example.test/estimate/50000000001",
          deal_id: "60000000001",
          deal_url: "https://crm.example.test/deal/60000000001",
          total: 25500,
          subtotal: 14009,
          books_email_sent: false,
          idempotency_key: headers["idempotency-key"],
          duration_ms: 17,
        },
        201,
      );
    }
    if (endpoint === "/send-estimate") {
      state.sendCount++;
      expect(body.estimate_id).toBe("50000000001");
      const attachment = body.pretty_attachment,
        pdf = Buffer.from(attachment.data, "base64");
      expect(pdf.subarray(0, 5).toString()).toBe("%PDF-");
      expect(pdf.length).toBeLessThan(4 * 1024 * 1024);
      const inspection = inspectFixturePdf(pdf);
      const boxes = [
        ...pdf.toString("latin1").matchAll(/\/MediaBox\s*\[([^\]]+)\]/g),
      ].map((match) => match[1].trim().split(/\s+/).map(Number));
      expect(boxes.length).toBeGreaterThanOrEqual(2);
      for (const box of boxes) expect(box).toEqual([0, 0, 612, 792]);
      expect(inspection.images).toHaveLength(boxes.length);
      expect(inspection.placements).toHaveLength(boxes.length);
      const html = Buffer.from(attachment.html_b64, "base64").toString("utf8");
      expect(html).toContain("café");
      state.pdf = {
        filename: attachment.filename,
        bytes: pdf.length,
        pages: boxes.length,
        mediaBoxes: boxes,
        placements: inspection.placements,
        html,
      };
      state.pdfBytes = pdf;
      state.pdfImages = inspection.images;
      return respond(
        {
          job_id: "options-fixture-job",
          estimate_id: "50000000001",
          status: "queued",
          sent: false,
          message: "Synthetic PDF queued locally.",
        },
        202,
      );
    }
    if (endpoint === "/send-status") {
      state.sendStatusCount++;
      return respond({
        job_id: "options-fixture-job",
        estimate_id: "50000000001",
        status: "sent",
        sent: true,
        message: "Synthetic PDF marked sent; no email was delivered.",
      });
    }
    throw new Error(
      `Unexpected local fixture endpoint ${request.method()} ${endpoint}`,
    );
  });
  return state;
}

export function requestEvidence(requests) {
  return requests.map((request) =>
    request.endpoint === "/send-estimate"
      ? {
          ...request,
          body: {
            ...request.body,
            pretty_attachment: {
              filename: request.body.pretty_attachment.filename,
              html: Buffer.from(
                request.body.pretty_attachment.html_b64,
                "base64",
              ).toString("utf8"),
              pdf: "actual-letter-pdf-verified-separately",
            },
          },
        }
      : request,
  );
}
