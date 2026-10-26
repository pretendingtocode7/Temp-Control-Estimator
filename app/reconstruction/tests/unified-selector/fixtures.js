import { expect } from "@playwright/test";
import { inspectFixturePdf } from "../parity/pdf-inspect.js";

export const origin = "http://127.0.0.1:4190";
export const account = {
  id: "200000000000000001",
  name: "Fixture Customer",
  email: "fixture@example.test",
  phone: "555-0100",
  billing_address: {
    street: "7 Example Street",
    city: "Fixture City",
    state: "NJ",
    zip: "08817",
    country: "USA",
  },
};
const item = (id, name, category, rate, details = {}) => ({
  item_id: `40000000000000${String(id).padStart(4, "0")}`,
  name,
  brand: "Fixture",
  model: `MODEL-${id}`,
  sku: `FIX-${id}`,
  short_description: `${name} synthetic scope`,
  rate,
  selection_category: category,
  equipment_type:
    {
      package: "other",
      ac: "condenser",
      furnace: "furnace",
      coil: "evaporator_coil",
    }[category] || category,
  is_composite: category === "package",
  source: "zoho_books",
  tags: ["Package"],
  ...details,
});
export const targetPackage = item(
  901,
  "Fixture Combination Package 100000 BTU 1.5 ton",
  "package",
  8800,
  {
    tons: 1.5,
    btu_input: 100000,
    js_part_number: "PKG-COMBINATION",
    mfg_part_number: "FIXTURE-PACKAGE-100000-18",
  },
);
export const ac = item(911, "Fixture AC 1.5 ton", "ac", 3300, {
  tons: 1.5,
  btu_input: null,
});
export const furnace = item(
  912,
  "Fixture Furnace 100000 BTU",
  "furnace",
  2800,
  { tons: null, btu_input: 100000 },
);
export const coil = item(913, "Fixture Coil 1.5 ton", "coil", 850, {
  tons: 1.5,
  btu_input: null,
});
export const thermostat = item(914, "Fixture Thermostat", "thermostat", 250, {
  tons: null,
  btu_input: null,
});
export const packages = Array.from({ length: 130 }, (_, index) =>
  item(
    index + 1,
    `Fixture Package ${String(index + 1).padStart(3, "0")}`,
    "package",
    6000 + index + 1,
    {
      btu_input: [80000, 100000, 120000][index % 3],
      tons: [1.5, 2, 3][index % 3],
    },
  ),
).concat([
  targetPackage,
  item(902, "Fixture Combination Package 80000 BTU 1.5 ton", "package", 8400, {
    btu_input: 80000,
    tons: 1.5,
  }),
  item(903, "Fixture Combination Package 100000 BTU 3 ton", "package", 9200, {
    btu_input: 100000,
    tons: 3,
  }),
]);
export const allItems = [...packages, ac, furnace, coil, thermostat];
export const template = {
  id: 42,
  name: "Fixture Full Replacement",
  template_type: "full_replacement",
  default_warranty_parts: 10,
  default_warranty_labor: 10,
  active: true,
  version: 3,
  required_slots: [],
  supports_financing: true,
  supports_rebates: true,
};
const escape = (value) =>
  String(value)
    .replaceAll("&", "&amp;")
    .replaceAll("<", "&lt;")
    .replaceAll(">", "&gt;")
    .replaceAll('"', "&quot;");

export function previewFor(payload) {
  const lines = payload.systems.flatMap((system) =>
    Object.entries(system.equipment).map(([slot, selected]) => {
      const catalog = allItems.find(
        (entry) => entry.item_id === selected.item_id,
      );
      expect(catalog, `known synthetic item ${selected.item_id}`).toBeTruthy();
      return {
        key: `system_${system.system_number}_${slot}_0`,
        item_id: catalog.item_id,
        name: catalog.name,
        description: selected.description ?? catalog.short_description,
        rate: catalog.rate,
      };
    }),
  );
  return {
    html: `<h1>Unified selector fixture proposal</h1><p>Prepared for ${account.name}</p><ul>${lines.map((line) => `<li>${escape(line.name)} — ${escape(line.description)}</li>`).join("")}</ul><p>Project total: $${Number(payload.pricing.total).toFixed(2)}</p>`,
    books_preview: {
      line_items: lines,
      notes: "Synthetic local approval notes.",
      terms: "Synthetic proposal valid for 30 days.",
    },
    view: {},
    template_id: 42,
    template_version: 3,
  };
}

export async function installFixture(page) {
  const state = {
    requests: [],
    outside: [],
    errors: [],
    delayedCompleted: 0,
    generateCount: 0,
    sendCount: 0,
    sendStatusCount: 0,
    previews: [],
    generated: [],
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
    expect(headers["x-wp-nonce"]).toBe("unified-fixture-nonce");
    const body = request.postData()
      ? JSON.parse(request.postData())
      : undefined;
    const endpoint = url.pathname.replace("/wp-json/tc-estimate/v1", "");
    state.requests.push({
      method: request.method(),
      endpoint,
      query: Object.fromEntries(url.searchParams),
      body,
      nonce: headers["x-wp-nonce"],
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
        url.searchParams.get("q")?.toLowerCase().includes("fixture")
          ? [account]
          : [],
      );
    if (endpoint === "/equipment" && url.searchParams.get("selector") === "1") {
      expect(request.method()).toBe("GET");
      const category = url.searchParams.get("category") || "package",
        query = (url.searchParams.get("q") || "").toLowerCase().trim();
      const btu = url.searchParams.get("btu") || "",
        tons = url.searchParams.get("tons") || "";
      const page = Number(url.searchParams.get("page") || 1),
        limit = Number(url.searchParams.get("limit") || 50);
      expect(["package", "ac", "furnace", "coil"]).toContain(category);
      expect(limit).toBe(50);
      let rows = allItems.filter(
        (entry) => entry.selection_category === category,
      );
      if (query === "slow-package") {
        rows = packages.slice(0, 2);
        await new Promise((resolve) => setTimeout(resolve, 500));
      } else if (query)
        rows = rows.filter((entry) =>
          `${entry.name} ${entry.model} ${entry.sku} ${entry.js_part_number || ""} ${entry.mfg_part_number || ""}`
            .toLowerCase()
            .includes(query),
        );
      if (btu) rows = rows.filter((entry) => entry.btu_input === Number(btu));
      if (tons) rows = rows.filter((entry) => entry.tons === Number(tons));
      const result = await respond({
        items: rows.slice((page - 1) * limit, page * limit),
        total: rows.length,
        page,
        per_page: limit,
        has_more: page * limit < rows.length,
        facets: { btus: [80000, 100000, 120000], tons: [1.5, 2, 3] },
      });
      if (query === "slow-package") state.delayedCompleted++;
      return result;
    }
    if (endpoint === "/equipment") {
      const type = url.searchParams.get("type");
      return respond(
        (type
          ? allItems.filter((entry) => entry.equipment_type === type)
          : allItems
        ).slice(0, Number(url.searchParams.get("limit") || 50)),
      );
    }
    if (endpoint === "/preview") {
      state.previews.push(body);
      return respond(previewFor(body));
    }
    if (endpoint === "/generate") {
      state.generateCount++;
      state.generated.push(body);
      expect(headers["idempotency-key"]).toMatch(/^tc-/);
      expect(body.customer).toEqual({ zoho_account_id: account.id });
      expect(body).not.toHaveProperty("preview_html");
      return respond(
        {
          estimate_id: "500000000000000001",
          estimate_number: "EST-UNIFIED-FIXTURE-1",
          estimate_status: "draft",
          estimate_url:
            "https://books.example.test/estimate/500000000000000001",
          deal_id: "600000000000000001",
          deal_url: "https://crm.example.test/deal/600000000000000001",
          total: body.pricing.total,
          subtotal: body.pricing.subtotal,
          books_email_sent: false,
          idempotency_key: headers["idempotency-key"],
          duration_ms: 17,
        },
        201,
      );
    }
    if (endpoint === "/update-estimate")
      return respond({
        estimate_id: body.estimate_id,
        saved: true,
        message: "Synthetic Books draft saved locally.",
      });
    if (endpoint === "/send-estimate") {
      state.sendCount++;
      expect(body.estimate_id).toBe("500000000000000001");
      const attachment = body.pretty_attachment,
        pdf = Buffer.from(attachment.data, "base64");
      expect(pdf.subarray(0, 5).toString()).toBe("%PDF-");
      expect(pdf.length).toBeLessThan(4 * 1024 * 1024);
      const inspection = inspectFixturePdf(pdf);
      const text = pdf.toString("latin1");
      const boxes = Array.from(
        text.matchAll(/\/MediaBox\s*\[([^\]]+)\]/g),
        (match) => match[1].trim().split(/\s+/).map(Number),
      );
      expect(boxes.length).toBeGreaterThan(0);
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
      state.pdfImages = inspection.images;
      return respond(
        {
          job_id: "unified-fixture-job",
          estimate_id: body.estimate_id,
          status: "queued",
          sent: false,
          message: "Synthetic PDF queued locally.",
        },
        202,
      );
    }
    if (endpoint === "/send-status") {
      state.sendStatusCount++;
      expect(url.searchParams.get("job_id")).toBe("unified-fixture-job");
      return respond({
        job_id: "unified-fixture-job",
        estimate_id: "500000000000000001",
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
