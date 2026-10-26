import { test, expect } from "@playwright/test";
import fs from "node:fs";
import path from "node:path";
import { fileURLToPath } from "node:url";
import { createHash } from "node:crypto";
import {
  account,
  ac,
  furnace,
  coil,
  missingCost,
  zeroCost,
  targetPackage,
  installFixture,
  requestEvidence,
} from "./fixtures.js";

const here = path.dirname(fileURLToPath(import.meta.url));
const evidence =
  process.env.UNIFIED_SELECTOR_EVIDENCE ||
  path.resolve(here, "../../../../../unified-selector/browser-validation");
const repo = path.resolve(here, "../../../..");
const app = path.resolve(here, "../..");
const sha = (value) => createHash("sha256").update(value).digest("hex");
let priorEvidence;
const preserved = () =>
  Object.fromEntries(
    fs
      .readdirSync(path.join(repo, "docs/rebuild/parity"))
      .map((name) => [
        name,
        sha(fs.readFileSync(path.join(repo, "docs/rebuild/parity", name))),
      ]),
  );

test.beforeAll(() => {
  fs.mkdirSync(evidence, { recursive: true });
  priorEvidence = preserved();
});
test.afterAll(() => {
  expect(
    preserved(),
    "original committed parity evidence stays untouched",
  ).toEqual(priorEvidence);
});

async function open(context) {
  const page = await context.newPage(),
    fixture = await installFixture(page);
  await page.goto("/estimate-builder/");
  await expect(
    page.getByRole("heading", { name: "Who is this estimate for?" }),
  ).toBeVisible();
  await page
    .getByRole("searchbox", { name: "Search customers" })
    .fill("Fixture");
  await page
    .locator(".tc-results button")
    .filter({ hasText: account.name })
    .click();
  await expect(
    page.getByRole("button", { name: "Next", exact: true }),
  ).toBeEnabled();
  await page.getByRole("button", { name: "Next", exact: true }).click();
  return { page, fixture, screenshots: [] };
}

async function openPicker(page, system = page.locator(".tc-system").first()) {
  await expect(
    system.getByRole("button", { name: "Select equipment", exact: true }),
  ).toHaveCount(1);
  await system
    .getByRole("button", { name: "Select equipment", exact: true })
    .click();
  const dialog = page.getByRole("dialog", {
    name: "Select equipment",
    exact: true,
  });
  await expect(dialog).toBeVisible();
  return dialog;
}

async function choose(page, system, category, item) {
  const dialog = await openPicker(page, system);
  await dialog.getByRole("button", { name: category, exact: true }).click();
  await dialog
    .getByRole("combobox", { name: "BTU", exact: true })
    .selectOption("");
  await dialog
    .getByRole("combobox", { name: "Tonnage", exact: true })
    .selectOption("");
  await dialog
    .getByRole("combobox", { name: "Efficiency (%)", exact: true })
    .selectOption("");
  await dialog
    .getByRole("searchbox", { name: "Search equipment", exact: true })
    .fill(item.name);
  await dialog.getByText(item.name, { exact: true }).click();
  await expect(dialog).toBeHidden();
  await expect(system.getByText(item.name, { exact: true })).toBeVisible();
}

async function capture(run, label, project, dialog) {
  await run.page.evaluate(async () => {
    document.activeElement?.blur();
    window.scrollTo(0, 0);
    await document.fonts.ready;
    // Measure final layout, after the real drawer entrance animation completes.
    // The bounds assertions below remain strict; transient translateY is not clipping.
    await Promise.all(
      document
        .getAnimations()
        .filter(
          (animation) =>
            animation.effect?.getComputedTiming().iterations !== Infinity,
        )
        .map((animation) => animation.finished.catch(() => {})),
    );
    await new Promise((resolve) =>
      requestAnimationFrame(() => requestAnimationFrame(resolve)),
    );
  });
  const layout = await run.page.evaluate(() => ({
    viewport: [innerWidth, innerHeight],
    documentWidth: document.documentElement.scrollWidth,
    bodyWidth: document.body.scrollWidth,
  }));
  expect(
    layout.documentWidth,
    `${label} document has no horizontal overflow`,
  ).toBeLessThanOrEqual(layout.viewport[0] + 1);
  if (dialog) {
    const bounds = await dialog.boundingBox();
    expect(bounds.x).toBeGreaterThanOrEqual(-1);
    expect(bounds.x + bounds.width).toBeLessThanOrEqual(layout.viewport[0] + 1);
    expect(bounds.y).toBeGreaterThanOrEqual(-1);
    expect(bounds.y + bounds.height).toBeLessThanOrEqual(
      layout.viewport[1] + 1,
    );
    for (const control of [
      dialog.getByRole("searchbox", { name: "Search equipment", exact: true }),
      dialog.getByRole("combobox", { name: "BTU", exact: true }),
      dialog.getByRole("combobox", { name: "Tonnage", exact: true }),
      dialog.getByRole("combobox", { name: "Efficiency (%)", exact: true }),
      ...["Package", "AC", "Furnace", "Coil"].map((name) =>
        dialog.getByRole("button", { name, exact: true }),
      ),
    ]) {
      const box = await control.boundingBox();
      expect(box, "selector control is rendered").toBeTruthy();
      expect(box.x).toBeGreaterThanOrEqual(bounds.x - 1);
      expect(box.x + box.width).toBeLessThanOrEqual(
        bounds.x + bounds.width + 1,
      );
      expect(box.y).toBeGreaterThanOrEqual(bounds.y - 1);
      expect(box.y + box.height).toBeLessThanOrEqual(layout.viewport[1] + 1);
    }
    layout.dialog = bounds;
  }
  const file = `${project}-${label}.png`;
  await run.page.screenshot({
    path: path.join(evidence, file),
    fullPage: true,
    animations: "disabled",
    caret: "hide",
  });
  const viewportFile = `${project}-${label}-viewport.png`;
  await run.page.screenshot({
    path: path.join(evidence, viewportFile),
    fullPage: false,
    animations: "disabled",
    caret: "hide",
  });
  run.screenshots.push({
    file,
    viewportFile,
    layout,
    sha256: sha(fs.readFileSync(path.join(evidence, file))),
  });
}

async function report(run, project, scenario, details = {}) {
  expect(run.fixture.outside, "no outside-origin requests").toEqual([]);
  expect(run.fixture.errors, "no browser exceptions").toEqual([]);
  const result = {
    scenario,
    viewport: project,
    browser: run.page.context().browser().version(),
    viewportSize: run.page.viewportSize(),
    intentionalDesignChange: true,
    oldParityEvidencePreserved: Object.keys(priorEvidence).length,
    assets: Object.fromEntries(
      [
        "estimate-builder.js",
        "estimate-builder.css",
        "estimate-builder.js.map",
      ].map((name) => [
        name,
        sha(
          fs.readFileSync(
            path.join(
              process.env.UNIFIED_SELECTOR_DIST || path.join(app, "dist"),
              name,
            ),
          ),
        ),
      ]),
    ),
    screenshots: run.screenshots,
    mockedRequests: requestEvidence(run.fixture.requests),
    outsideRequests: run.fixture.outside,
    browserErrors: run.fixture.errors,
    previewSource: run.fixture.previewSource,
    detailLookups: run.fixture.details,
    ...details,
  };
  if (run.fixture.pdf) {
    result.pdf = run.fixture.pdf;
    const pdfFile = `${project}-${scenario}.pdf`;
    fs.writeFileSync(path.join(evidence, pdfFile), run.fixture.pdfBytes);
    result.pdf.file = pdfFile;
    result.pdf.sha256 = sha(run.fixture.pdfBytes);
    result.pdfPages = run.fixture.pdfImages.map((image, index) => {
      const file = `${project}-${scenario}-pdf-page-${index + 1}.jpg`;
      fs.writeFileSync(path.join(evidence, file), image.bytes);
      return {
        file,
        width: image.width,
        height: image.height,
        sha256: sha(image.bytes),
      };
    });
  }
  fs.writeFileSync(
    path.join(evidence, `${project}-${scenario}.json`),
    `${JSON.stringify(result, null, 2)}\n`,
  );
  await run.page.close();
}

test("combined Package filters, three catalog pages and category changes expose the correct selectable rows", async ({
  context,
}, info) => {
  const run = await open(context),
    { page, fixture } = run;
  const dialog = await openPicker(page);
  await expect(
    dialog.getByRole("button", { name: "Package", exact: true }),
  ).toHaveAttribute("aria-pressed", "true");
  await expect(dialog.locator(".tc-eqcard")).toHaveCount(50);
  expect(
    fixture.details,
    "list loading does not eagerly hydrate all package costs",
  ).toHaveLength(0);
  await expect(
    dialog.getByRole("button", { name: "Previous page", exact: true }),
  ).toBeDisabled();
  await dialog
    .getByRole("searchbox", { name: "Search equipment", exact: true })
    .fill("Fixture Combination");
  await expect(dialog.locator(".tc-eqcard")).toHaveCount(4);
  await dialog
    .getByRole("combobox", { name: "BTU", exact: true })
    .selectOption("100000");
  await expect(dialog.locator(".tc-eqcard")).toHaveCount(3);
  await dialog
    .getByRole("combobox", { name: "Tonnage", exact: true })
    .selectOption("1.5");
  await expect(dialog.locator(".tc-eqcard")).toHaveCount(2);
  await dialog
    .getByRole("combobox", { name: "Efficiency (%)", exact: true })
    .selectOption("96");
  await expect(dialog.locator(".tc-eqcard")).toHaveCount(1);
  await expect(
    dialog.getByText(targetPackage.name, { exact: true }),
  ).toBeVisible();
  await capture(
    run,
    "shared-selector-combined-filters",
    info.project.name,
    dialog,
  );
  const narrowed = fixture.requests
    .filter((request) => request.query.selector === "1")
    .at(-1);
  expect(narrowed.query).toMatchObject({
    category: "package",
    q: "Fixture Combination",
    btu: "100000",
    tons: "1.5",
    efficiency: "96",
    page: "1",
    limit: "50",
  });
  await dialog
    .getByRole("searchbox", { name: "Search equipment", exact: true })
    .fill("");
  await dialog
    .getByRole("combobox", { name: "BTU", exact: true })
    .selectOption("");
  await dialog
    .getByRole("combobox", { name: "Tonnage", exact: true })
    .selectOption("");
  await dialog
    .getByRole("combobox", { name: "Efficiency (%)", exact: true })
    .selectOption("");
  await expect(dialog.locator(".tc-eqcard")).toHaveCount(50);
  await dialog.getByRole("button", { name: "Next page", exact: true }).click();
  await expect(
    dialog.getByText("Fixture Package 051", { exact: true }),
  ).toBeVisible();
  await dialog.getByRole("button", { name: "Next page", exact: true }).click();
  await expect(
    dialog.getByText("Fixture Package 126", { exact: true }),
  ).toBeVisible();
  await dialog
    .getByText("Fixture Package 126", { exact: true })
    .scrollIntoViewIfNeeded();
  await expect(
    dialog.getByRole("button", { name: "Next page", exact: true }),
  ).toBeDisabled();
  await capture(run, "shared-selector-page-three", info.project.name, dialog);
  await dialog.getByText("Fixture Package 126", { exact: true }).click();
  await expect(
    page
      .locator(".tc-system")
      .first()
      .getByText("Fixture Package 126", { exact: true }),
  ).toBeVisible();
  expect(fixture.details).toHaveLength(1);
  const reopened = await openPicker(page);
  await reopened
    .getByRole("searchbox", { name: "Search equipment", exact: true })
    .fill("slow-package");
  await expect
    .poll(() =>
      fixture.requests.some((request) => request.query.q === "slow-package"),
    )
    .toBe(true);
  await reopened.getByRole("button", { name: "AC", exact: true }).click();
  await reopened
    .getByRole("searchbox", { name: "Search equipment", exact: true })
    .fill("Fixture AC");
  await expect(reopened.getByText(ac.name, { exact: true })).toBeVisible();
  await expect.poll(() => fixture.delayedCompleted).toBe(1);
  await expect(
    reopened.getByRole("button", { name: "AC", exact: true }),
  ).toHaveAttribute("aria-pressed", "true");
  await expect(reopened.locator(".tc-eqcard")).toHaveCount(1);
  await expect(
    reopened.getByText("Fixture Package 001", { exact: true }),
  ).toHaveCount(0);
  await reopened.getByText(ac.name, { exact: true }).click();
  await expect(
    page
      .locator(".tc-system")
      .first()
      .getByText("Fixture Package 126", { exact: true }),
  ).toHaveCount(0);
  await expect(
    page.locator(".tc-system").first().getByText(ac.name, { exact: true }),
  ).toBeVisible();
  await report(run, info.project.name, "selector-filters-pagination", {
    packageFixtureCount: 134,
    farItemOnThirdPageReachable: true,
    compoundFilterCounts: [4, 3, 2, 1],
    staleCategoryResponseIgnored: true,
  });
});

test("purchase costs and project sales stay distinct through replacements, a real PHP proposal and PDF", async ({
  context,
}, info) => {
  const run = await open(context),
    { page, fixture } = run;
  const first = page.locator(".tc-system").first();
  await choose(page, first, "AC", missingCost);
  await page.getByRole("button", { name: "Next", exact: true }).click();
  await expect(page.getByLabel("Equipment subtotal (cost)")).toHaveValue("");
  await expect(
    page
      .getByRole("status")
      .filter({ hasText: "Purchase cost is missing for 1" }),
  ).toContainText("Enter the equipment cost manually.");
  await expect(page.getByLabel("Project total", { exact: true })).toHaveValue(
    "4300",
  );
  await page.getByLabel("Project total", { exact: true }).fill("4500");
  await capture(run, "missing-cost-no-sales-fallback", info.project.name);
  await page.getByRole("button", { name: "Back", exact: true }).click();
  await choose(page, first, "Furnace", zeroCost);
  await first
    .locator(".tc-slot")
    .filter({ hasText: missingCost.name })
    .getByRole("button", { name: "Remove", exact: true })
    .click();
  await page.getByRole("button", { name: "Next", exact: true }).click();
  await expect(page.getByLabel("Equipment subtotal (cost)")).toHaveValue("0");
  await expect(
    page.getByRole("status").filter({ hasText: "Purchase cost is missing" }),
  ).toHaveCount(0);
  await expect(page.getByLabel("Project total", { exact: true })).toHaveValue(
    "4500",
  );
  await page
    .getByRole("button", { name: "Use equipment sales price", exact: true })
    .click();
  await expect(page.getByLabel("Project total", { exact: true })).toHaveValue(
    "1800",
  );
  await page.getByRole("button", { name: "Back", exact: true }).click();
  await choose(page, first, "Package", targetPackage);
  await page.getByRole("button", { name: "Next", exact: true }).click();
  await expect(page.getByLabel("Equipment subtotal (cost)")).toHaveValue(
    "5200",
  );
  await expect(page.getByLabel("Project total", { exact: true })).toHaveValue(
    "8800",
  );
  await page.getByRole("button", { name: "Back", exact: true }).click();
  await choose(page, first, "AC", ac);
  await expect(
    first.getByText(targetPackage.name, { exact: true }),
  ).toHaveCount(0);
  await choose(page, first, "Furnace", furnace);
  await expect(first.getByText(ac.name, { exact: true })).toBeVisible();
  await choose(page, first, "Coil", coil);
  await expect(first.getByText(ac.name, { exact: true })).toBeVisible();
  await expect(first.getByText(furnace.name, { exact: true })).toBeVisible();
  await first
    .locator(".tc-slot")
    .filter({ hasText: ac.name })
    .locator("textarea")
    .fill("Edited fixture AC scope — café");
  await expect(
    first.locator(".tc-slot").filter({ hasText: ac.name }).locator("textarea"),
  ).toHaveValue("Edited fixture AC scope — café");
  await page.getByRole("button", { name: "Next", exact: true }).click();
  await expect(page.getByLabel("Equipment subtotal (cost)")).toHaveValue(
    "3900",
  );
  await expect(page.getByLabel("Project total", { exact: true })).toHaveValue(
    "6950",
  );
  await page.getByRole("button", { name: "Back", exact: true }).click();
  await choose(page, first, "Package", targetPackage);
  for (const replaced of [ac, furnace, coil])
    await expect(first.getByText(replaced.name, { exact: true })).toHaveCount(
      0,
    );
  await first
    .locator(".tc-slot")
    .filter({ hasText: targetPackage.name })
    .locator("textarea")
    .fill("Edited package scope — café");
  await page
    .getByRole("button", { name: "+ Add another system", exact: true })
    .click();
  await expect(
    page.getByRole("button", { name: "Next", exact: true }),
  ).toBeDisabled();
  const second = page.locator(".tc-system").nth(1);
  await second.getByLabel("System 2 label").fill("Second System");
  await choose(page, second, "AC", ac);
  await capture(run, "selected-package-and-second-system", info.project.name);
  await page.getByRole("button", { name: "Next", exact: true }).click();
  await expect(page.getByLabel("Equipment subtotal (cost)")).toHaveValue(
    "7100",
  );
  await expect(page.getByLabel("Project total", { exact: true })).toHaveValue(
    "12100",
  );
  await page.getByLabel("Project total", { exact: true }).fill("14000");
  await expect(page.getByLabel("Equipment subtotal (cost)")).toHaveValue(
    "7100",
  );
  await capture(run, "purchase-cost-versus-project-sales", info.project.name);
  await page
    .getByLabel("Special notes (optional)")
    .fill("Unified selector fixture notes — café");
  await page.getByRole("button", { name: "Next", exact: true }).click();
  await expect(
    page.getByRole("button", { name: "Create draft estimate", exact: true }),
  ).toBeEnabled();
  const polished = page.getByRole("textbox", {
    name: "Edit polished proposal",
    exact: true,
  });
  await expect(polished).toContainText(targetPackage.name);
  if (process.env.UNIFIED_SELECTOR_REQUIRE_PHP_FIXTURE === "1")
    expect(fixture.previewSource.mode).toBe("actual-php-fixture");
  const documentLayout = await polished.evaluate((root, packageName) => {
    const headings = [...root.querySelectorAll("h1,h2,h3,h4,h5,h6")];
    const equipment = headings.find((heading) =>
      /^equipment to be installed$/i.test(heading.textContent.trim()),
    );
    const scope = headings.find((heading) =>
      /^scope of work$/i.test(heading.textContent.trim()),
    );
    const investment = headings.find((heading) =>
      /^investment$/i.test(heading.textContent.trim()),
    );
    const sectionText = (heading) => {
      if (!heading) return "";
      const nodes = [];
      for (
        let next = heading.nextElementSibling;
        next && !/^H[1-3]$/.test(next.tagName);
        next = next.nextElementSibling
      )
        nodes.push(next.textContent);
      return nodes.join(" ");
    };
    const text = root.textContent;
    return {
      equipmentFound: Boolean(equipment),
      scopeFound: Boolean(scope),
      investmentFound: Boolean(investment),
      equipmentBeforeScope: Boolean(
        equipment &&
        scope &&
        equipment.compareDocumentPosition(scope) &
          Node.DOCUMENT_POSITION_FOLLOWING,
      ),
      equipmentText: sectionText(equipment),
      summaryText: sectionText(investment),
      packageOccurrences: text.split(packageName).length - 1,
      documentCurrency: [...text.matchAll(/\$\s*[\d,]+(?:\.\d{2})?/g)].map(
        (match) => match[0],
      ),
      html: root.innerHTML,
    };
  }, targetPackage.name);
  expect(documentLayout.equipmentFound).toBe(true);
  expect(documentLayout.scopeFound).toBe(true);
  expect(documentLayout.investmentFound).toBe(true);
  expect(documentLayout.equipmentBeforeScope).toBe(true);
  expect(documentLayout.equipmentText).toContain(targetPackage.name);
  expect(documentLayout.equipmentText).toContain(ac.name);
  expect(documentLayout.equipmentText).not.toMatch(/\$\s*\d/);
  expect(documentLayout.packageOccurrences).toBe(1);
  expect(documentLayout.summaryText).toContain("$14,000.00");
  expect(documentLayout.summaryText).toContain("$4,900.00");
  expect(documentLayout.documentCurrency).toEqual(["$14,000.00", "$4,900.00"]);
  expect(documentLayout.html).not.toContain("purchase_cost");
  expect(documentLayout.html).not.toContain("equipment_cost");
  await capture(
    run,
    "php-customer-proposal-equipment-section",
    info.project.name,
  );
  expect(fixture.previews).toHaveLength(1);
  const payload = fixture.previews[0];
  expect(Object.keys(payload.systems[0].equipment)).toEqual(["package"]);
  expect(payload.systems[0].equipment.package).toEqual({
    item_id: targetPackage.item_id,
    description: "Edited package scope — café",
  });
  expect(payload.systems[1].equipment.condenser.item_id).toBe(ac.item_id);
  expect(payload.pricing).toEqual({
    subtotal: 12100,
    total: 14000,
    deposit_percent: 35,
  });
  expect(JSON.stringify(payload)).not.toContain("equipment_cost");
  expect(JSON.stringify(payload)).not.toContain("purchase_cost");
  expect(fixture.details).toHaveLength(8);
  await page.getByRole("tab", { name: "Books version", exact: true }).click();
  await expect(page.locator(".tc-books-line")).toHaveCount(2);
  const packageLine = page
    .locator(".tc-books-line")
    .filter({ hasText: targetPackage.name });
  await expect(packageLine).toHaveCount(1);
  await expect(packageLine).toContainText("$8,800.00");
  await packageLine
    .getByRole("textbox", { name: "Books item description", exact: true })
    .fill("Reviewed Books package description");
  expect(fixture.generateCount).toBe(0);
  expect(fixture.sendCount).toBe(0);
  await capture(run, "review-books-single-package-line", info.project.name);
  await page
    .getByRole("button", { name: "Create draft estimate", exact: true })
    .click();
  await expect(
    page.getByRole("heading", { name: "Draft estimate created", exact: true }),
  ).toBeVisible();
  expect(fixture.generateCount).toBe(1);
  expect(fixture.sendCount).toBe(0);
  const generated = fixture.generated[0];
  expect(Object.keys(generated.systems[0].equipment)).toEqual(["package"]);
  const packages = generated.books_edits.line_items.filter(
    (line) => line.item_id === targetPackage.item_id,
  );
  expect(packages).toHaveLength(1);
  expect(packages[0]).toMatchObject({
    key: "system_1_package_0",
    rate: 8800,
    description: "Reviewed Books package description",
  });
  expect(generated.pricing).toEqual({
    subtotal: 12100,
    total: 14000,
    deposit_percent: 35,
  });
  expect(JSON.stringify(generated)).not.toContain("equipment_cost");
  expect(JSON.stringify(generated)).not.toContain("purchase_cost");
  await page
    .getByRole("tab", { name: "Polished version", exact: true })
    .click();
  await page
    .getByRole("button", {
      name: "Send polished proposal to customer",
      exact: true,
    })
    .click();
  await expect(
    page.getByText("Synthetic PDF marked sent; no email was delivered.", {
      exact: true,
    }),
  ).toBeVisible();
  expect(fixture.sendCount).toBe(1);
  expect(fixture.sendStatusCount).toBe(1);
  expect(fixture.pdf.filename).toBe(
    "Temp-Control-Proposal-Fixture-Customer.pdf",
  );
  expect(fixture.pdf.html).toContain(targetPackage.name);
  expect(fixture.pdf.html).not.toContain("purchase_cost");
  expect(fixture.pdf.html).not.toContain("equipment_cost");
  expect(
    [...fixture.pdf.html.matchAll(/\$\s*[\d,]+(?:\.\d{2})?/g)].map(
      (match) => match[0],
    ),
  ).toEqual(["$14,000.00", "$4,900.00"]);
  await capture(run, "draft-and-synthetic-pdf-sent", info.project.name);
  await report(run, info.project.name, "package-individual-workflow", {
    canonicalPackageSlot: true,
    observedPurchaseCosts: [null, 0, 5200, 3900, 7100],
    observedSalesSeeds: [4300, 1800, 8800, 6950, 12100],
    manualSalesOverridePreserved: true,
    explicitSalesResetRestoresAutoSeed: true,
    detailHydrationPerPick: 8,
    documentLayout,
    manualTotal: 14000,
    oneBooksPackageLine: true,
    noRealEmailOrBooksTransaction: true,
  });
});
