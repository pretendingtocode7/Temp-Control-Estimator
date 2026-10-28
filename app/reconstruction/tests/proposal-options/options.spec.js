import { test, expect } from "@playwright/test";
import fs from "node:fs";
import path from "node:path";
import { fileURLToPath } from "node:url";
import { createHash } from "node:crypto";
import {
  account,
  ac,
  offers,
  addons,
  combined,
  category2Values,
  permitParagraphs,
  installFixture,
  requestEvidence,
  assertFinalPayload,
} from "./fixtures.js";

const here = path.dirname(fileURLToPath(import.meta.url));
const app = path.resolve(here, "../..");
const repo = path.resolve(app, "../..");
const workspace = path.resolve(repo, "..");
const evidence =
  process.env.OPTIONS_EVIDENCE ||
  path.join(workspace, "options-tiers/browser-validation-final-public");
const sha = (bytes) => createHash("sha256").update(bytes).digest("hex");
function treeHashes(directory) {
  const result = {};
  const visit = (dir) => {
    for (const entry of fs.readdirSync(dir, { withFileTypes: true })) {
      const full = path.join(dir, entry.name);
      if (entry.isDirectory()) visit(full);
      else result[path.relative(directory, full)] = sha(fs.readFileSync(full));
    }
  };
  visit(directory);
  return result;
}
const preserved = () => ({
  original: treeHashes(path.join(repo, "docs/rebuild/parity")),
  v013: treeHashes(
    path.join(workspace, "efficiency-pricing/browser-validation"),
  ),
});
let prior;
test.beforeAll(() => {
  fs.mkdirSync(evidence, { recursive: true });
  prior = preserved();
});
test.afterAll(() =>
  expect(
    preserved(),
    "original85 and v0.4.13 evidence remain byte-identical",
  ).toEqual(prior),
);

async function open(context, settings) {
  const page = await context.newPage();
  const fixture = await installFixture(page, settings);
  await page.goto("/estimate-builder/");
  await page
    .getByRole("searchbox", { name: "Search customers" })
    .fill("Synthetic");
  await page
    .locator(".tc-results button")
    .filter({ hasText: account.name })
    .click();
  await page.getByRole("button", { name: "Next", exact: true }).click();
  return { page, fixture, screenshots: [] };
}
async function picker(page, system = page.locator(".tc-system").first()) {
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
async function choose(page, system, item) {
  const dialog = await picker(page, system);
  await dialog
    .getByRole("button", {
      name: item.selection_category === "ac" ? "AC" : "Package",
      exact: true,
    })
    .click();
  for (const name of ["Package level", "BTU", "Tonnage", "Efficiency (%)"])
    await dialog.getByRole("combobox", { name, exact: true }).selectOption("");
  await dialog
    .getByRole("searchbox", { name: "Search equipment", exact: true })
    .fill(item.name);
  await dialog.getByText(item.name, { exact: true }).click();
  await expect(dialog).toBeHidden();
  await expect(system.getByText(item.name, { exact: true })).toBeVisible();
}
async function pricing(page, expectedTotal, expectedCost) {
  await page.getByRole("button", { name: "Next", exact: true }).click();
  await expect(page.getByLabel("Project total", { exact: true })).toHaveValue(
    String(expectedTotal),
  );
  if (expectedCost !== undefined)
    await expect(
      page.getByLabel("Equipment subtotal (cost)", { exact: true }),
    ).toHaveValue(String(expectedCost));
}
async function back(page) {
  await page.getByRole("button", { name: "Back", exact: true }).click();
}

async function capture(run, name, project, dialog) {
  await run.page.evaluate(async () => {
    document.activeElement?.blur();
    window.scrollTo(0, 0);
    await document.fonts.ready;
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
    `${name}: no horizontal document overflow`,
  ).toBeLessThanOrEqual(layout.viewport[0] + 1);
  if (dialog) {
    const box = await dialog.boundingBox();
    expect(box.x).toBeGreaterThanOrEqual(-1);
    expect(box.y).toBeGreaterThanOrEqual(-1);
    expect(box.x + box.width).toBeLessThanOrEqual(layout.viewport[0] + 1);
    expect(box.y + box.height).toBeLessThanOrEqual(layout.viewport[1] + 1);
    for (const control of [
      dialog.getByRole("searchbox", { name: "Search equipment", exact: true }),
      ...["Package level", "BTU", "Tonnage", "Efficiency (%)"].map((label) =>
        dialog.getByRole("combobox", { name: label, exact: true }),
      ),
    ]) {
      const bounds = await control.boundingBox();
      expect(bounds).toBeTruthy();
      expect(bounds.x).toBeGreaterThanOrEqual(box.x - 1);
      expect(bounds.x + bounds.width).toBeLessThanOrEqual(
        box.x + box.width + 1,
      );
      expect(bounds.y).toBeGreaterThanOrEqual(box.y - 1);
      expect(bounds.y + bounds.height).toBeLessThanOrEqual(
        layout.viewport[1] + 1,
      );
    }
    layout.dialog = box;
  }
  const file = `${project}-${name}.png`,
    viewportFile = `${project}-${name}-viewport.png`;
  await run.page.screenshot({
    path: path.join(evidence, file),
    fullPage: true,
    animations: "disabled",
    caret: "hide",
  });
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
  expect(run.fixture.outside, "no outside requests").toEqual([]);
  expect(run.fixture.errors, "no browser exceptions").toEqual([]);
  const result = {
    scenario,
    viewport: project,
    browser: run.page.context().browser().version(),
    viewportSize: run.page.viewportSize(),
    originalEvidencePreserved: Object.keys(prior.original).length,
    v013EvidencePreserved: Object.keys(prior.v013).length,
    assets: Object.fromEntries(
      [
        "estimate-builder.js",
        "estimate-builder.css",
        "estimate-builder.js.map",
      ].map((name) => [
        name,
        sha(
          fs.readFileSync(
            path.join(process.env.OPTIONS_DIST || path.join(app, "dist"), name),
          ),
        ),
      ]),
    ),
    screenshots: run.screenshots,
    mockedRequests: requestEvidence(run.fixture.requests),
    outsideRequests: run.fixture.outside,
    browserErrors: run.fixture.errors,
    detailLookups: run.fixture.details,
    previewSource: run.fixture.previewSource,
    ...details,
  };
  if (run.fixture.pdf) {
    const file = `${project}-${scenario}.pdf`;
    fs.writeFileSync(path.join(evidence, file), run.fixture.pdfBytes);
    result.pdf = {
      ...run.fixture.pdf,
      file,
      sha256: sha(run.fixture.pdfBytes),
    };
    result.pdfPages = run.fixture.pdfImages.map((image, index) => {
      const pageFile = `${project}-${scenario}-pdf-page-${index + 1}.jpg`;
      fs.writeFileSync(path.join(evidence, pageFile), image.bytes);
      return {
        file: pageFile,
        width: image.width,
        height: image.height,
        sha256: sha(image.bytes),
      };
    });
  }
  fs.writeFileSync(
    path.join(evidence, `${project}-${scenario}.json`),
    JSON.stringify(result, null, 2) + "\n",
  );
  await run.page.close();
}

test("Package level is an exact AND filter with capacity, efficiency, search, pagination and stale response protection", async ({
  context,
}, info) => {
  const run = await open(context),
    { page, fixture } = run;
  const dialog = await picker(page);
  const category2 = dialog.getByRole("combobox", {
    name: "Package level",
    exact: true,
  });
  await expect(category2.locator("option")).toHaveText([
    "All package levels",
    ...category2Values,
  ]);
  await dialog
    .getByRole("searchbox", { name: "Search equipment", exact: true })
    .fill("Synthetic Combination");
  await expect(dialog.locator(".tc-eqcard")).toHaveCount(5);
  await category2.selectOption(category2Values[5]);
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
    dialog.getByText(combined[0].name, { exact: true }),
  ).toBeVisible();
  const narrowed = fixture.requests
    .filter((request) => request.query.selector === "1")
    .at(-1);
  expect(narrowed.query).toMatchObject({
    category: "package",
    category2: category2Values[5],
    q: "Synthetic Combination",
    btu: "100000",
    tons: "1.5",
    efficiency: "96",
    page: "1",
    limit: "50",
  });
  await capture(run, "category2-combined-filters", info.project.name, dialog);
  for (const name of ["BTU", "Tonnage", "Efficiency (%)"])
    await dialog.getByRole("combobox", { name, exact: true }).selectOption("");
  await dialog
    .getByRole("searchbox", { name: "Search equipment", exact: true })
    .fill("slow-category2");
  await expect
    .poll(() =>
      fixture.requests.some((request) => request.query.q === "slow-category2"),
    )
    .toBe(true);
  await category2.selectOption(category2Values[0]);
  await dialog
    .getByRole("searchbox", { name: "Search equipment", exact: true })
    .fill("");
  await expect(dialog.locator(".tc-eqcard")).toHaveCount(50);
  await expect.poll(() => fixture.delayedCompleted).toBeGreaterThanOrEqual(1);
  await expect(category2).toHaveValue(category2Values[0]);
  await expect(dialog.getByText(combined[0].name, { exact: true })).toHaveCount(
    0,
  );
  await dialog.getByRole("button", { name: "Next page", exact: true }).click();
  await expect(
    dialog.getByText("Synthetic Category2 Package 050", { exact: true }),
  ).toBeVisible();
  await dialog.getByRole("button", { name: "Next page", exact: true }).click();
  await expect(
    dialog.getByText("Synthetic Category2 Package 126", { exact: true }),
  ).toBeVisible();
  await expect(
    dialog.getByRole("button", { name: "Next page", exact: true }),
  ).toBeDisabled();
  await capture(run, "category2-third-page", info.project.name, dialog);
  await dialog
    .getByText("Synthetic Category2 Package 126", { exact: true })
    .click();
  expect(fixture.details).toHaveLength(1);
  const reset = await picker(page);
  await reset.getByRole("button", { name: "AC", exact: true }).click();
  await expect(
    reset.getByRole("combobox", { name: "Package level", exact: true }),
  ).toHaveValue("");
  await expect(reset.getByText(ac.name, { exact: true })).toBeVisible();
  await report(run, info.project.name, "category2-catalog", {
    combinedCounts: [5, 4, 3, 2, 1],
    exactCategory2Values: category2Values,
    staleResponseIgnored: true,
    filteredThirdPageReachable: true,
  });
});

async function configureSystem(run, systemIndex) {
  const { page } = run;
  const system = page.locator(".tc-system").nth(systemIndex);
  const label = ["Lower System", "Upper System"][systemIndex];
  await system
    .getByLabel(`System ${systemIndex + 1} label`, { exact: true })
    .fill(label);
  await choose(page, system, offers[systemIndex][0]);
  await system
    .getByRole("button", { name: "Add alternative", exact: true })
    .click();
  await system
    .getByLabel(`System ${systemIndex + 1} alternative 1 label`, {
      exact: true,
    })
    .fill("Silver");
  await system
    .getByLabel(`System ${systemIndex + 1} alternative 1 sales price`, {
      exact: true,
    })
    .fill(String(offers[systemIndex][0].fixture_sales_price));
  for (let tier = 1; tier < 3; tier++) {
    if (tier === 2)
      await system
        .getByRole("button", { name: "Add alternative", exact: true })
        .click();
    const choiceLabel = ["Silver", "Gold", "Platinum"][tier];
    await system
      .getByLabel(`System ${systemIndex + 1} alternative ${tier + 1} label`, {
        exact: true,
      })
      .fill(choiceLabel);
    await choose(page, system, offers[systemIndex][tier]);
    await system
      .getByLabel(
        `System ${systemIndex + 1} alternative ${tier + 1} sales price`,
        { exact: true },
      )
      .fill(String(offers[systemIndex][tier].fixture_sales_price));
    if (tier === 2)
      await system
        .getByRole("textbox", {
          name: "Edit Package estimate description",
          exact: true,
        })
        .fill(`${["Lower", "Upper"][systemIndex]} Platinum scope — café`);
  }
  await expect(
    system.getByRole("radio", {
      name: `Select Silver for ${label}`,
      exact: true,
    }),
  ).toBeChecked();
  await expect(system.getByRole("status")).toContainText("Editing Platinum.");
  await expect(system.getByRole("status")).toContainText("not selected");
  return system;
}

test("two physical systems keep three alternatives each but charge only selections through real PHP proposal and PDF", async ({
  context,
}, info) => {
  const run = await open(context),
    { page, fixture } = run;
  const lower = await configureSystem(run, 0);
  await page
    .getByRole("button", { name: "Add another system", exact: true })
    .click();
  const upper = await configureSystem(run, 1);
  await capture(run, "two-systems-six-alternatives", info.project.name);
  await pricing(page, 17000, 8500);
  await back(page);
  await lower
    .getByRole("radio", { name: "Select Gold for Lower System", exact: true })
    .check();
  await pricing(page, 19000, 9500);
  await back(page);
  await upper
    .getByRole("radio", {
      name: "Select Platinum for Upper System",
      exact: true,
    })
    .check();
  await pricing(page, 23000, 11500);
  await back(page);
  await lower
    .getByRole("radio", {
      name: "Select Platinum for Lower System",
      exact: true,
    })
    .check();
  await pricing(page, 25000, 12500);
  await back(page);
  // Editing an unselected offer changes neither selection, selected costs nor charge.
  await lower
    .getByLabel("System 1 alternative 1 sales price", { exact: true })
    .fill("8700");
  await lower
    .getByRole("button", {
      name: "Edit Silver equipment for Lower System",
      exact: true,
    })
    .click();
  await lower
    .getByRole("textbox", {
      name: "Edit Package estimate description",
      exact: true,
    })
    .fill("Unselected Silver edited independently — café");
  await expect(
    lower.getByRole("radio", {
      name: "Select Platinum for Lower System",
      exact: true,
    }),
  ).toBeChecked();
  await pricing(page, 25000, 12500);
  await back(page);
  await lower
    .getByLabel("System 1 alternative 1 sales price", { exact: true })
    .fill("8000");
  await lower
    .getByRole("button", {
      name: "Edit Silver equipment for Lower System",
      exact: true,
    })
    .click();
  await lower
    .getByRole("textbox", {
      name: "Edit Package estimate description",
      exact: true,
    })
    .fill(offers[0][0].short_description);
  await pricing(page, 25000, 12500);
  const maintenance = page.getByRole("checkbox", {
    name: `${addons.maintenance.name} ($0.00)`,
    exact: true,
  });
  const permits = page.getByRole("checkbox", {
    name: `${addons.permits.name} ($0.00)`,
    exact: true,
  });
  await expect(maintenance).not.toBeChecked();
  await expect(permits).not.toBeChecked();
  await maintenance.check();
  await permits.check();
  await expect(page.getByLabel("Project total", { exact: true })).toHaveValue(
    "25000",
  );
  for (const paragraph of permitParagraphs)
    await expect(page.getByText(paragraph, { exact: true })).toBeVisible();
  await expect(
    page.getByText("Renews at $349 for one unit.", { exact: true }),
  ).toBeVisible();
  await page.getByLabel("Project total", { exact: true }).fill("25500");
  await expect(
    page.getByText(/shared adjustment of \$500\.00, applied once/),
  ).toBeVisible();
  await expect(
    page.getByLabel("Equipment subtotal (cost)", { exact: true }),
  ).toHaveValue("12500");
  await page
    .getByRole("checkbox", {
      name: "Include 10-year parts warranty",
      exact: true,
    })
    .uncheck();
  await page
    .getByRole("checkbox", {
      name: "Include 10-year labor warranty",
      exact: true,
    })
    .uncheck();
  await capture(run, "selected-pricing-zero-options", info.project.name);
  await back(page);
  await lower
    .getByRole("radio", { name: "Select Gold for Lower System", exact: true })
    .check();
  await pricing(page, 23500, 11500);
  await back(page);
  await lower
    .getByRole("radio", {
      name: "Select Platinum for Lower System",
      exact: true,
    })
    .check();
  await pricing(page, 25500, 12500);
  await page.getByRole("button", { name: "Next", exact: true }).click();
  const polished = page.getByRole("textbox", {
    name: "Edit polished proposal",
    exact: true,
  });
  await expect(polished).toContainText("Upper Platinum");
  expect(fixture.previewSource.mode).toBe("actual-php-fixture");
  assertFinalPayload(fixture.previews.at(-1));
  const groups = polished.locator(".tc-proposal-system");
  await expect(groups).toHaveCount(2);
  for (const [systemIndex, label] of [
    "Lower System",
    "Upper System",
  ].entries()) {
    const group = groups.nth(systemIndex);
    await expect(group).toContainText(label);
    await expect(group.locator(".tc-proposal-choice")).toHaveCount(3);
    for (const [tierIndex, offer] of offers[systemIndex].entries()) {
      const choice = group.locator(".tc-proposal-choice").nth(tierIndex);
      await expect(choice).toContainText(offer.name);
      await expect(choice).toContainText(
        `$${offer.fixture_sales_price.toLocaleString("en-US")}.00`,
      );
      if (tierIndex === 2)
        await expect(choice).toContainText("Selected for project total");
      else await expect(choice).not.toContainText("Selected for project total");
    }
  }
  const html = await polished.innerHTML();
  expect((html.match(/Selected for project total/g) || []).length).toBe(2);
  for (const offer of offers.flat()) {
    expect((html.match(new RegExp(offer.name, "g")) || []).length).toBe(1);
    expect(html).not.toContain(`$${offer.rate.toLocaleString("en-US")}.00`);
    expect(html).not.toContain(
      `$${offer.fixture_purchase_cost.toLocaleString("en-US")}.00`,
    );
  }
  expect(html).not.toContain("purchase_cost");
  expect(html).not.toContain("equipment_cost");
  expect(html).not.toContain("$12,500.00");
  expect(html).not.toContain("$63,000.00");
  expect(html).toContain("$25,500.00");
  expect(html).toContain("$8,925.00");
  expect((html.match(/\$500\.00/g) || []).length).toBe(1);
  expect((html.match(/\$0\.00/g) || []).length).toBe(2);
  expect(html).toContain("Renews at $349 for one unit.");
  for (const paragraph of permitParagraphs)
    await expect(polished).toContainText(paragraph);
  await capture(run, "six-option-customer-proposal", info.project.name);
  await page.getByRole("tab", { name: "Books version", exact: true }).click();
  await expect(page.locator(".tc-books-line")).toHaveCount(4);
  for (const name of [
    offers[0][2].name,
    offers[1][2].name,
    addons.maintenance.name,
    addons.permits.name,
  ])
    await expect(
      page.locator(".tc-books-line").filter({ hasText: name }),
    ).toHaveCount(1);
  for (const offer of [offers[0][0], offers[0][1], offers[1][0], offers[1][1]])
    await expect(
      page.locator(".tc-books-line").filter({ hasText: offer.name }),
    ).toHaveCount(0);
  await capture(run, "books-selected-two-plus-zero-options", info.project.name);
  expect(fixture.generateCount).toBe(0);
  expect(fixture.sendCount).toBe(0);
  await page
    .getByRole("button", { name: "Create draft estimate", exact: true })
    .click();
  await expect(
    page.getByRole("heading", { name: "Draft estimate created", exact: true }),
  ).toBeVisible();
  expect(fixture.generateCount).toBe(1);
  expect(fixture.sendCount).toBe(0);
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
  expect(fixture.pdf.html).toContain("$25,500.00");
  expect(fixture.pdf.html).toContain("$8,925.00");
  expect(fixture.pdf.html).not.toContain("$63,000.00");
  await capture(run, "synthetic-draft-pdf-complete", info.project.name);
  await report(run, info.project.name, "two-systems-six-options", {
    selectedTotals: [17000, 19000, 23000, 25000],
    selectedCosts: [8500, 9500, 11500, 12500],
    unselectedEditDoesNotSelectOrCharge: true,
    catalogSubtotal: 14009,
    selectedInstalledSales: 25000,
    projectAdjustmentOnce: 500,
    projectTotal: 25500,
    deposit: 8925,
    optionalCurrentCharge: 0,
    futureMaintenanceRenewal: 349,
    sixOffersTwoSelections: true,
    booksSelectedEquipmentLines: 2,
    booksZeroAddonLines: 2,
    noPublicCustomerChooserClaim: true,
    noRealProviderOrEmail: true,
  });
});

test("optional items retry safely and checked unavailable options can be cleared without changing sales", async ({
  context,
}, info) => {
  const run = await open(context, { addonMode: "error" }),
    { page, fixture } = run;
  await choose(page, page.locator(".tc-system").first(), offers[0][0]);
  await pricing(page, 7001, 4000);
  const maintenance = page.getByRole("checkbox", {
    name: `${addons.maintenance.name} ($0.00)`,
    exact: true,
  });
  const permits = page.getByRole("checkbox", {
    name: `${addons.permits.name} ($0.00)`,
    exact: true,
  });
  await expect(page.getByRole("alert")).toContainText(
    "Synthetic optional items temporarily unavailable.",
  );
  await expect(maintenance).toBeDisabled();
  await expect(permits).toBeDisabled();
  await expect(maintenance).not.toBeChecked();
  await expect(permits).not.toBeChecked();
  fixture.addonMode = "available";
  await page
    .getByRole("button", { name: "Retry optional items", exact: true })
    .click();
  await expect(maintenance).toBeEnabled();
  await maintenance.check();
  await permits.check();
  await expect(page.getByLabel("Project total", { exact: true })).toHaveValue(
    "7001",
  );
  fixture.addonMode = "unavailable";
  await back(page);
  await pricing(page, 7001, 4000);
  await expect(
    page.getByText("The maintenance agreement is unavailable.", {
      exact: true,
    }),
  ).toBeVisible();
  await expect(maintenance).toBeChecked();
  await expect(maintenance).toBeEnabled();
  await maintenance.uncheck();
  await permits.uncheck();
  await expect(maintenance).toBeDisabled();
  await expect(permits).toBeDisabled();
  for (const paragraph of permitParagraphs)
    await expect(page.getByText(paragraph, { exact: true })).toHaveCount(0);
  await expect(page.getByLabel("Project total", { exact: true })).toHaveValue(
    "7001",
  );
  await capture(run, "unavailable-zero-options-can-clear", info.project.name);
  expect(fixture.previews).toHaveLength(0);
  expect(fixture.generateCount).toBe(0);
  expect(fixture.sendCount).toBe(0);
  await report(run, info.project.name, "optional-items-retry", {
    failedLoadDidNotSelect: true,
    explicitRetrySucceeded: true,
    selectedUnavailableOptionsCanBeUnchecked: true,
    clearedUnavailableOptionsDisabled: true,
    unchangedSalesTotal: 7001,
    noMutationRequested: true,
  });
});
