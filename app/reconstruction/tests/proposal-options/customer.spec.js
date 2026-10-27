import { test, expect } from "@playwright/test";
import fs from "node:fs";
import path from "node:path";
import { fileURLToPath } from "node:url";
import { createHash } from "node:crypto";
import { origin, permitParagraphs } from "./fixtures.js";
import {
  publicPath,
  publicView,
  approvalUrl,
  installCustomerFixture,
  redact,
} from "./customer-fixtures.js";

// A Playwright trace includes raw POST bodies. Do not persist even fixture tokens.
test.use({ trace: "off" });
const app = path.resolve(path.dirname(fileURLToPath(import.meta.url)), "../..");
const workspace = path.resolve(app, "../../..");
const evidence =
  process.env.OPTIONS_EVIDENCE ||
  path.join(workspace, "options-tiers/browser-validation-final-public");
const sha = (bytes) => createHash("sha256").update(bytes).digest("hex");
function treeHashes(root) {
  const result = {};
  function walk(directory) {
    for (const entry of fs.readdirSync(directory, { withFileTypes: true })) {
      const file = path.join(directory, entry.name);
      if (entry.isDirectory()) walk(file);
      else result[path.relative(root, file)] = sha(fs.readFileSync(file));
    }
  }
  walk(root);
  return result;
}
let priorCore;
test.beforeAll(() => {
  fs.mkdirSync(evidence, { recursive: true });
  priorCore = treeHashes(
    path.join(workspace, "options-tiers/browser-validation"),
  );
  expect(JSON.stringify(publicView()).includes("item_id")).toBe(false);
});
test.afterAll(() =>
  expect(
    treeHashes(path.join(workspace, "options-tiers/browser-validation")),
    "completed six-case core evidence remains byte-identical",
  ).toEqual(priorCore),
);

async function openCustomer(context, settings = {}) {
  await context.addCookies([
    { name: "staff_fixture", value: "synthetic-only", url: origin },
  ]);
  const page = await context.newPage();
  const fixture = await installCustomerFixture(page, settings);
  if (settings.clock) await page.clock.install();
  await page.goto(publicPath);
  await expect(
    page.getByRole("heading", { name: "Choose your equipment", exact: true }),
  ).toBeVisible();
  return { page, fixture, screenshots: [] };
}
const total = (page) => page.locator(".tc-selected strong");
async function ready(run) {
  await expect(
    run.page.getByRole("group", { name: "Lower System", exact: true }),
  ).toBeVisible();
  await expect(run.page.getByRole("radio")).toHaveCount(6);
  await expect(total(run.page)).toHaveText("$17,500.00");
  expect(run.fixture.applies).toHaveLength(0);
}
async function chooseRetail(page) {
  await page
    .getByRole("radio", { name: "Gold for Lower System", exact: true })
    .check();
  await expect(total(page)).toHaveText("$19,500.00");
  await page
    .getByRole("radio", { name: "Platinum for Upper System", exact: true })
    .check();
  await expect(total(page)).toHaveText("$23,500.00");
}
async function capture(run, name, project) {
  await run.page.evaluate(async () => {
    document.activeElement?.blur();
    window.scrollTo(0, 0);
    await document.fonts.ready;
  });
  const bounds = await run.page.evaluate(() => ({
    viewport: [innerWidth, innerHeight],
    documentWidth: document.documentElement.scrollWidth,
  }));
  expect(
    bounds.documentWidth,
    "no horizontal public-page overflow",
  ).toBeLessThanOrEqual(bounds.viewport[0] + 1);
  for (const control of await run.page.getByRole("radio").all()) {
    const box = await control.boundingBox();
    expect(box.x).toBeGreaterThanOrEqual(-1);
    expect(box.x + box.width).toBeLessThanOrEqual(bounds.viewport[0] + 1);
  }
  const file = `${project}-customer-${name}.png`,
    viewportFile = `${project}-customer-${name}-viewport.png`;
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
  run.screenshots.push({ file, viewportFile, bounds });
}
async function report(run, project, scenario, details = {}) {
  expect(run.fixture.outside).toEqual([]);
  expect(run.fixture.errors).toEqual([]);
  expect(
    run.fixture.credentialLeak,
    "no bearer appeared in browser console/errors",
  ).toBe(false);
  expect(
    await run.page.evaluate(() => window.__customerWorkerRegistrations),
  ).toEqual([]);
  const boot = await run.page
    .locator("#tc-customer-choices-root")
    .evaluate((root) => JSON.parse(root.dataset.boot));
  expect(Object.keys(boot).sort()).toEqual(["brand", "restUrl"]);
  expect(await run.page.locator("#tc-estimate-builder-root").count()).toBe(0);
  const html = await run.page.locator("#tc-customer-choices-root").innerHTML();
  for (const key of [
    "purchase_cost",
    "equipment_cost",
    "markup",
    "zoho_account_id",
    "deal_id",
    "crm_rows",
    "options-fixture-nonce",
  ])
    expect(html.includes(key), `no private field ${key} in public DOM`).toBe(
      false,
    );
  const result = {
    scenario: `customer-${scenario}`,
    viewport: project,
    browser: run.page.context().browser().version(),
    bootKeys: Object.keys(boot).sort(),
    staffServiceWorkerRegistrations: 0,
    screenshots: run.screenshots,
    mockedRequests: run.fixture.requests,
    consoleMessages: run.fixture.console,
    browserErrors: run.fixture.errors,
    outsideRequests: run.fixture.outside,
    credentialLeak: false,
    completedCoreEvidencePreserved: Object.keys(priorCore).length,
    assets: Object.fromEntries(
      [
        "estimate-builder.js",
        "estimate-builder.css",
        "estimate-builder.js.map",
      ].map((file) => [
        file,
        sha(
          fs.readFileSync(
            path.join(process.env.OPTIONS_DIST || path.join(app, "dist"), file),
          ),
        ),
      ]),
    ),
    ...details,
  };
  const serialized = JSON.stringify(result, null, 2);
  expect(
    serialized === redact(serialized),
    "all persisted capability tokens are redacted",
  ).toBe(true);
  fs.writeFileSync(
    path.join(evidence, `${project}-customer-${scenario}.json`),
    serialized + "\n",
  );
  await run.page.close();
}

test("customer viewing and choosing do not write; only an explicit confirmed update reveals Books approval", async ({
  context,
}, info) => {
  const run = await openCustomer(context, { holdView: true }),
    { page, fixture } = run;
  await expect(page.getByRole("status")).toHaveText("Loading your estimate…");
  expect(fixture.applies).toHaveLength(0);
  await expect.poll(() => typeof fixture.releaseView).toBe("function");
  await fixture.releaseView();
  await ready(run);
  await expect(
    page.getByRole("link", {
      name: "Review and approve in Zoho Books",
      exact: true,
    }),
  ).toHaveCount(0);
  await chooseRetail(page);
  expect(fixture.applies).toHaveLength(0);
  await expect(
    page.getByText("Choosing options is not approval or payment.", {
      exact: false,
    }),
  ).toBeVisible();
  for (const paragraph of permitParagraphs)
    await expect(page.getByText(paragraph, { exact: false })).toBeVisible();
  await capture(run, "retail-selections-no-write", info.project.name);
  await page
    .getByRole("button", { name: "Update my estimate", exact: true })
    .click();
  await expect(page.getByRole("status")).toHaveText(
    "Your Books estimate has been updated. This does not approve the estimate or make a payment.",
  );
  expect(fixture.applies).toHaveLength(1);
  expect(fixture.applies[0].selections).toEqual([
    { system_number: 1, choice_id: "choice-0-1" },
    { system_number: 2, choice_id: "choice-1-2" },
  ]);
  const link = page.getByRole("link", {
    name: "Review and approve in Zoho Books",
    exact: true,
  });
  await expect(link).toHaveAttribute("href", approvalUrl);
  await expect(link).toHaveAttribute("rel", "noopener noreferrer");
  await expect(total(page)).toHaveText("$23,500.00");
  await capture(run, "saved-before-Books-approval", info.project.name);
  // Read the destination only; never follow the off-site approval link.
  await page
    .getByRole("radio", { name: "Silver for Lower System", exact: true })
    .check();
  await expect(link).toHaveCount(0);
  expect(fixture.applies).toHaveLength(1);
  await report(run, info.project.name, "explicit-update", {
    loadingAndSelectionWrites: 0,
    explicitApplyRequests: 1,
    retailTotals: [17500, 19500, 23500],
    commonAdjustmentOnce: 500,
    selectedCount: 2,
    addonChargeToday: 0,
    futureRenewalNotCharged: true,
    approvalLinkInspectedOnly: approvalUrl,
    choiceAloneDoesNotApproveOrPay: true,
    changingChoiceHidesPriorApprovalLink: true,
  });
});

test("customer pending update checks reuse the exact request identity and selections without automatic retry", async ({
  context,
}, info) => {
  const run = await openCustomer(context, { mode: "pending", clock: true }),
    { page, fixture } = run;
  await ready(run);
  await chooseRetail(page);
  await page
    .getByRole("button", { name: "Update my estimate", exact: true })
    .click();
  await expect(
    page.getByRole("button", { name: "Check update", exact: true }),
  ).toBeEnabled();
  await expect(page.getByRole("radio").first()).toBeDisabled();
  await page.clock.fastForward(10_000);
  expect(fixture.applies).toHaveLength(1);
  await page.reload();
  await expect(
    page.getByRole("button", { name: "Check update", exact: true }),
  ).toBeEnabled();
  expect(fixture.applies).toHaveLength(1);
  await expect(
    page.getByRole("link", {
      name: "Review and approve in Zoho Books",
      exact: true,
    }),
  ).toHaveCount(0);
  await page
    .getByRole("button", { name: "Refresh update status", exact: true })
    .click();
  await expect(
    page.getByRole("button", { name: "Check update", exact: true }),
  ).toBeEnabled();
  expect(fixture.applies).toHaveLength(1);
  await capture(run, "pending-requires-explicit-check", info.project.name);
  fixture.mode = "books_updated";
  await page.getByRole("button", { name: "Check update", exact: true }).click();
  await expect(
    page.getByRole("button", { name: "Check update", exact: true }),
  ).toBeEnabled();
  await expect(page.getByRole("radio").first()).toBeDisabled();
  await expect(
    page.getByRole("link", {
      name: "Review and approve in Zoho Books",
      exact: true,
    }),
  ).toHaveCount(0);
  expect(fixture.applies).toHaveLength(2);
  fixture.mode = "saved";
  await page.getByRole("button", { name: "Check update", exact: true }).click();
  await expect(
    page.getByRole("link", {
      name: "Review and approve in Zoho Books",
      exact: true,
    }),
  ).toHaveAttribute("href", approvalUrl);
  expect(fixture.applies).toHaveLength(3);
  expect(fixture.applies[1]).toEqual(fixture.applies[0]);
  expect(fixture.applies[2]).toEqual(fixture.applies[0]);
  await report(run, info.project.name, "pending-check", {
    automaticRetries: 0,
    explicitChecks: 2,
    statusRefreshReadOnly: true,
    pendingReloadRecoversOriginalOperation: true,
    partialBackendConfirmationKeepsApprovalGated: true,
    sameRequestIdRevisionSelections: true,
  });
});

test("customer timeout remains uncertain until checking the same original operation", async ({
  context,
}, info) => {
  const run = await openCustomer(context, { mode: "timeout", clock: true }),
    { page, fixture } = run;
  await ready(run);
  await chooseRetail(page);
  await page
    .getByRole("button", { name: "Update my estimate", exact: true })
    .click();
  await expect.poll(() => fixture.applies.length).toBe(1);
  await page.clock.fastForward(30_100);
  await expect(
    page.getByRole("button", { name: "Check update", exact: true }),
  ).toBeEnabled();
  await expect(page.getByRole("status")).toContainText(
    "We could not confirm the result.",
  );
  await expect(
    page.getByRole("link", {
      name: "Review and approve in Zoho Books",
      exact: true,
    }),
  ).toHaveCount(0);
  await page.clock.fastForward(10_000);
  expect(fixture.applies).toHaveLength(1);
  fixture.mode = "saved";
  await page.getByRole("button", { name: "Check update", exact: true }).click();
  await expect(
    page.getByRole("link", {
      name: "Review and approve in Zoho Books",
      exact: true,
    }),
  ).toHaveAttribute("href", approvalUrl);
  expect(fixture.applies).toHaveLength(2);
  expect(fixture.applies[1]).toEqual(fixture.applies[0]);
  await capture(run, "timeout-reconciled-same-operation", info.project.name);
  await report(run, info.project.name, "timeout-check", {
    realClientDeadlineMilliseconds: 30000,
    clockAdvancedMilliseconds: 40100,
    automaticRetries: 0,
    sameRequestIdRevisionSelections: true,
    confirmedOnlyAfterExplicitCheck: true,
  });
});

test("customer stale revision requires a fresh view rather than an automatic financial retry", async ({
  context,
}, info) => {
  const run = await openCustomer(context, { mode: "stale" }),
    { page, fixture } = run;
  await ready(run);
  await chooseRetail(page);
  await page
    .getByRole("button", { name: "Update my estimate", exact: true })
    .click();
  await expect(page.getByRole("alert")).toContainText("updated elsewhere");
  await expect(page.getByRole("radio").first()).toBeDisabled();
  await page
    .getByRole("button", { name: "Refresh estimate", exact: true })
    .click();
  await expect(
    page.getByRole("radio", { name: "Silver for Lower System", exact: true }),
  ).toBeChecked();
  await expect(
    page.getByRole("button", { name: "Update my estimate", exact: true }),
  ).toBeEnabled();
  await expect(total(page)).toHaveText("$17,500.00");
  expect(fixture.applies).toHaveLength(1);
  expect(fixture.views).toBe(2);
  await expect(
    page.getByRole("link", {
      name: "Review and approve in Zoho Books",
      exact: true,
    }),
  ).toHaveCount(0);
  await capture(run, "stale-revision-refreshed-read-only", info.project.name);
  await report(run, info.project.name, "stale-refresh", {
    rejectedRevision: 1,
    refreshedRevision: 2,
    automaticFinancialRetry: false,
    explicitApplyCount: 1,
    viewCount: 2,
  });
});

test("customer missing malformed and expired links expose a safe error and no write controls", async ({
  context,
}, info) => {
  for (const token of ["missing", "malformed", "expired"]) {
    const run = await openCustomer(context, { token });
    await expect(run.page.getByRole("alert")).toContainText(
      "unavailable or has expired",
    );
    await expect(
      run.page.getByRole("button", { name: "Update my estimate", exact: true }),
    ).toHaveCount(0);
    await expect(
      run.page.getByRole("link", {
        name: "Review and approve in Zoho Books",
        exact: true,
      }),
    ).toHaveCount(0);
    expect(run.fixture.applies).toHaveLength(0);
    expect(run.fixture.views).toBe(token === "expired" ? 1 : 0);
    await capture(run, `${token}-safe-error`, info.project.name);
    await report(run, info.project.name, `${token}-link`, {
      errorKind: token,
      noApplyRequests: true,
      backendErrorEchoNotDisplayedOrLogged: true,
    });
  }
});
