import { test, expect } from '@playwright/test';
import fs from 'node:fs';
import path from 'node:path';
import { createHash } from 'node:crypto';
import { fileURLToPath } from 'node:url';
import { account, installFixture, normalizedRequests } from './fixtures.js';

const evidence = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../../../../docs/rebuild/parity');
const digest = (value) => createHash('sha256').update(value).digest('hex');

async function open(context, variant, options) {
  const page = await context.newPage();
  const fixture = await installFixture(page, options);
  await page.goto(`/${variant}/`);
  expect(fixture.errors, `${variant} bundle must boot without runtime exceptions`).toEqual([]);
  await expect(page.getByRole('heading', { name: 'Who is this estimate for?' })).toBeVisible();
  return { page, fixture, variant, snapshots: {} };
}

async function capture(run, label, project) {
  await run.page.evaluate(async () => {
    document.activeElement?.blur();
    window.scrollTo(0, 0);
    await document.fonts.ready;
    await new Promise((resolve) => requestAnimationFrame(() => requestAnimationFrame(resolve)));
  });
  const dom = await run.page.locator('#tc-estimate-builder-root').evaluate((root) => ({
    html: root.innerHTML,
    controls: Array.from(root.querySelectorAll('input,textarea,select,[contenteditable]')).map((element) => ({
      tag: element.tagName, type: element.getAttribute('type'), value: element.value ?? element.innerHTML,
      checked: element.checked, disabled: element.disabled, editable: element.getAttribute('contenteditable'),
    })),
  }));
  const png = await run.page.screenshot({ fullPage: true, animations: 'disabled', caret: 'hide' });
  fs.mkdirSync(evidence, { recursive: true });
  const name = `${project}-${label}-${run.variant}.png`;
  fs.writeFileSync(path.join(evidence, name), png);
  run.snapshots[label] = { dom, png, file: name };
}

async function compareImages(page, reference, rebuilt, mime = 'image/png') {
  return page.evaluate(async ({ left, right, mime }) => {
    const decode = (data) => new Promise((resolve, reject) => {
      const image = new Image();
      image.onload = () => resolve(image);
      image.onerror = reject;
      image.src = `data:${mime};base64,${data}`;
    });
    const [a, b] = await Promise.all([decode(left), decode(right)]);
    if (a.width !== b.width || a.height !== b.height) return { dimensionsEqual: false, reference: [a.width, a.height], rebuilt: [b.width, b.height] };
    const pixels = (image) => {
      const canvas = document.createElement('canvas');
      canvas.width = image.width; canvas.height = image.height;
      const context = canvas.getContext('2d');
      context.drawImage(image, 0, 0);
      return context.getImageData(0, 0, image.width, image.height).data;
    };
    const l = pixels(a), r = pixels(b);
    let changedPixels = 0, maxChannelDifference = 0;
    for (let index = 0; index < l.length; index += 4) {
      let changed = false;
      for (let channel = 0; channel < 4; channel++) {
        const delta = Math.abs(l[index + channel] - r[index + channel]);
        if (delta) changed = true;
        maxChannelDifference = Math.max(maxChannelDifference, delta);
      }
      if (changed) changedPixels++;
    }
    return { dimensionsEqual: true, width: a.width, height: a.height, changedPixels, maxChannelDifference };
  }, { left: reference.toString('base64'), right: rebuilt.toString('base64'), mime });
}

function canonicalRequests(requests) {
  const normalized = normalizedRequests(requests);
  return {
    reads: normalized.filter((entry) => entry.method === 'GET').sort((a, b) => JSON.stringify(a).localeCompare(JSON.stringify(b))),
    mutations: normalized.filter((entry) => entry.method !== 'GET'),
  };
}

async function compareRuns(runs, project, scenario) {
  const [reference, rebuilt] = runs;
  const report = {
    scenario, viewport: project, viewportSize: rebuilt.page.viewportSize(), browser: rebuilt.page.context().browser().version(),
    reference: 'saved v0.4.8 app/dist', rebuilt: 'reconstructed candidate dist',
    assets: Object.fromEntries(['reference', 'rebuilt'].map((variant) => {
      const assetRoot = path.resolve(evidence, variant === 'reference' ? '../../../app/dist' : '../../../app/reconstruction/dist');
      return [variant, Object.fromEntries(['estimate-builder.js', 'estimate-builder.css'].map((file) => [file, digest(fs.readFileSync(path.join(assetRoot, file)))]))];
    })),
    screens: {}, externalRequests: [], browserErrors: [], requestParity: false,
  };
  for (const [label, original] of Object.entries(reference.snapshots)) {
    const candidate = rebuilt.snapshots[label];
    expect(candidate, `missing rebuilt screen ${label}`).toBeTruthy();
    expect(candidate.dom, `DOM/value parity for ${label}`).toEqual(original.dom);
    const pixels = await compareImages(rebuilt.page, original.png, candidate.png);
    report.screens[label] = { ...pixels, reference: original.file, rebuilt: candidate.file, referenceSha256: digest(original.png), rebuiltSha256: digest(candidate.png), domSha256: digest(JSON.stringify(original.dom)) };
    expect(pixels.dimensionsEqual, `image dimensions for ${label}`).toBe(true);
    expect(pixels.changedPixels, `changed pixels for ${label}`).toBe(0);
  }
  expect(canonicalRequests(rebuilt.fixture.requests), 'mock API requests and mutation order').toEqual(canonicalRequests(reference.fixture.requests));
  report.requestParity = true;
  report.mockedApi = canonicalRequests(reference.fixture.requests);
  for (const run of runs) {
    expect(run.fixture.blocked, 'external hosts must never be contacted').toEqual([]);
    expect(run.fixture.errors, 'browser errors').toEqual([]);
    if (run.fixture.pdf) report[`${run.variant}Pdf`] = run.fixture.pdf;
  }
  if (reference.fixture.pdf) {
    expect(rebuilt.fixture.pdf.pages).toBe(reference.fixture.pdf.pages);
    expect(rebuilt.fixture.pdf.html).toBe(reference.fixture.pdf.html);
    expect(rebuilt.fixture.pdf.filename).toBe(reference.fixture.pdf.filename);
    expect(rebuilt.fixture.pdf.placements).toEqual(reference.fixture.pdf.placements);
    report.pdfPages = [];
    for (let index = 0; index < reference.fixture.pdfImages.length; index++) {
      const original = reference.fixture.pdfImages[index], candidate = rebuilt.fixture.pdfImages[index];
      const files = {};
      for (const [variant, image] of [['reference', original], ['rebuilt', candidate]]) {
        files[variant] = `${project}-${scenario}-${variant}-pdf-page-${index + 1}.jpg`;
        fs.writeFileSync(path.join(evidence, files[variant]), image.bytes);
      }
      const pixels = await compareImages(rebuilt.page, original.bytes, candidate.bytes, 'image/jpeg');
      expect(pixels.dimensionsEqual).toBe(true);
      expect(pixels.changedPixels, `PDF page ${index + 1} rendered raster pixels`).toBe(0);
      report.pdfPages.push({ page: index + 1, ...pixels, ...files, referenceSha256: digest(original.bytes), rebuiltSha256: digest(candidate.bytes) });
    }
    report.pdfComparison = 'The actual browser-generated PDFs have identical page raster pixels, image placements, Letter media boxes, page counts, filenames and UTF-8 proposal HTML. Binary PDF identity is not expected across jsPDF versions.';
  }
  fs.writeFileSync(path.join(evidence, `${project}-${scenario}.json`), `${JSON.stringify(report, null, 2)}\n`);
  await Promise.all(runs.map((run) => run.page.close()));
}

async function chooseCustomer(page) {
  await page.getByRole('searchbox', { name: 'Search customers' }).fill('Fixture');
  await page.locator('.tc-results button').filter({ hasText: account.name }).click();
  await expect(page.getByRole('button', { name: 'Next', exact: true })).toBeEnabled();
}

async function chooseItem(page, system, label, itemName, drawerCapture) {
  await system.getByRole('button', { name: `+ Add ${label.toLowerCase()}`, exact: true }).click();
  const drawer = page.getByRole('dialog', { name: `Select ${label}`, exact: true });
  await expect(drawer.locator('.tc-eqcard').first()).toBeVisible();
  if (drawerCapture) await drawerCapture(drawer);
  await drawer.getByText(itemName, { exact: true }).click();
  await expect(drawer).toBeHidden();
}

async function simpleReview(run) {
  const { page } = run;
  await chooseCustomer(page);
  await page.getByRole('button', { name: 'Next', exact: true }).click();
  await chooseItem(page, page.locator('.tc-system').first(), 'Furnace', 'Fixture Furnace');
  await page.getByRole('button', { name: 'Next', exact: true }).click();
  await page.getByLabel('Total project cost').fill('4000');
  await page.getByRole('button', { name: 'Next', exact: true }).click();
  await expect(page.getByRole('button', { name: 'Create draft estimate', exact: true })).toBeEnabled();
}

test('reference and reconstruction preserve the complete editable draft/PDF/send journey', async ({ context }, testInfo) => {
  const runs = [];
  for (const variant of ['reference', 'rebuilt']) {
    const run = await open(context, variant);
    runs.push(run);
    const { page, fixture } = run;
    await capture(run, 'customer-empty', testInfo.project.name);
    await chooseCustomer(page);
    await capture(run, 'customer-selected', testInfo.project.name);
    await page.getByRole('button', { name: 'Next', exact: true }).click();
    const firstSystem = page.locator('.tc-system').first();
    await chooseItem(page, firstSystem, 'Furnace', 'Fixture Furnace', async (drawer) => {
      await expect(drawer.locator('.tc-eqcard')).toHaveCount(2);
      const before = fixture.requests.length;
      await drawer.getByPlaceholder('Filter by brand, model, JS part, or MFG part').fill('JS-FURNACE');
      await expect(drawer.locator('.tc-eqcard')).toHaveCount(1);
      expect(fixture.requests.length).toBe(before);
      await capture(run, 'equipment-drawer', testInfo.project.name);
    });
    await firstSystem.getByLabel('Edit Furnace estimate description').fill('Fixture furnace scope — café-ready');
    await chooseItem(page, firstSystem, 'Evaporator Coil', 'Fixture Coil');
    await firstSystem.locator('summary').click();
    await firstSystem.getByRole('button', { name: 'Permit', exact: true }).click();
    await page.getByRole('dialog', { name: 'Select Permit', exact: true }).locator('.tc-eqcard').filter({ hasText: 'Fixture Municipal Permit' }).click();
    await firstSystem.getByLabel('Permit price').fill('350.25');
    await page.getByRole('button', { name: '+ Add another system', exact: true }).click();
    const secondSystem = page.locator('.tc-system').nth(1);
    await secondSystem.getByLabel('System 2 label').fill('Upstairs fixture');
    await chooseItem(page, secondSystem, 'Condenser', 'Fixture Condenser');
    await page.getByRole('button', { name: '+ Add another system', exact: true }).click();
    await expect(page.getByRole('button', { name: 'Next', exact: true })).toBeDisabled();
    await page.locator('.tc-system').nth(2).locator('.tc-system-head').getByRole('button', { name: 'Remove', exact: true }).click();
    await expect(page.locator('.tc-system')).toHaveCount(2);
    await capture(run, 'equipment-selected', testInfo.project.name);
    await page.getByRole('button', { name: 'Next', exact: true }).click();
    await expect(page.getByLabel('Equipment subtotal')).toHaveValue('8550.25');
    await expect(page.getByLabel('Total project cost')).toHaveValue('');
    await page.getByRole('button', { name: 'Use subtotal', exact: true }).click();
    await expect(page.getByLabel('Total project cost')).toHaveValue('8550.25');
    await page.getByLabel('Total project cost').fill('9200.50');
    await page.getByLabel('Deposit (%)').fill('40');
    await page.getByLabel('Include 10-year labor warranty').uncheck();
    await page.getByLabel('Note additional credit card fee').check();
    await page.getByLabel('Note additional financing fee').check();
    await page.getByRole('button', { name: '+ Add rebate', exact: true }).click();
    await page.getByPlaceholder('Rebate name (e.g. ETOWN Instant Rebate)').fill('Fixture rebate');
    await page.getByPlaceholder('Amount', { exact: true }).fill('300');
    await page.getByLabel('Customer requests financing').check();
    await page.getByLabel('Term (months)').selectOption('84');
    await page.getByLabel('Special notes (optional)').fill('Fixture notes — café');
    expect(Number(await page.getByLabel('Total project cost').inputValue())).toBe(9200.5);
    await capture(run, 'pricing-options', testInfo.project.name);
    await page.getByRole('button', { name: 'Next', exact: true }).click();
    await expect(page.getByRole('button', { name: 'Create draft estimate', exact: true })).toBeEnabled();
    await page.getByRole('textbox', { name: 'Edit polished proposal', exact: true }).fill('Edited polished proposal — café & approval');
    await capture(run, 'review-polished-edited', testInfo.project.name);
    await page.getByRole('tab', { name: 'Books version', exact: true }).click();
    await page.getByRole('textbox', { name: 'Books item description', exact: true }).first().fill('Edited Books furnace description');
    await page.getByRole('textbox', { name: 'Books customer notes', exact: true }).fill('Fixture approved Books notes');
    await page.getByRole('textbox', { name: 'Books terms and conditions', exact: true }).fill('Fixture customer terms');
    expect(fixture.requests.filter((entry) => entry.path === '/preview')).toHaveLength(1);
    await capture(run, 'review-books-edited', testInfo.project.name);
    expect(fixture.requests.filter((entry) => entry.path === '/generate' || entry.path === '/send-estimate')).toHaveLength(0);
    await page.getByRole('button', { name: 'Create draft estimate', exact: true }).click();
    await expect(page.getByRole('heading', { name: 'Draft estimate created', exact: true })).toBeVisible();
    expect(fixture.requests.filter((entry) => entry.path === '/send-estimate')).toHaveLength(0);
    await capture(run, 'draft-created', testInfo.project.name);
    await page.getByRole('tab', { name: 'Books version', exact: true }).click();
    await page.getByRole('textbox', { name: 'Books item description', exact: true }).first().fill('After-draft Books wording');
    await page.getByRole('textbox', { name: 'Books customer notes', exact: true }).fill('These notes were saved only in the local fixture.');
    await page.getByRole('button', { name: 'Save changes to Books', exact: true }).click();
    await expect(page.getByRole('button', { name: 'Books changes saved', exact: true })).toBeDisabled();
    await capture(run, 'draft-books-saved', testInfo.project.name);
    await page.getByRole('tab', { name: 'Polished version', exact: true }).click();
    const longHtml = `<h1>Fixture multi-page proposal — café</h1>${Array.from({ length: 48 }, (_, index) => `<p>Scope item ${index + 1}: Synthetic fixture installation, equipment selection, customer approval, and careful verification of each documented system.</p>`).join('')}<p>End of synthetic proposal.</p>`;
    await page.getByRole('textbox', { name: 'Edit polished proposal', exact: true }).evaluate((editor, html) => {
      editor.innerHTML = html;
      editor.dispatchEvent(new InputEvent('input', { bubbles: true, inputType: 'insertText' }));
    }, longHtml);
    await page.getByRole('button', { name: 'Send polished proposal to customer', exact: true }).click();
    await expect(page.getByText('Fixture email marked sent; no email was delivered.', { exact: true })).toBeVisible();
    expect(fixture.requests.filter((entry) => entry.path === '/send-estimate')).toHaveLength(1);
    expect(fixture.sendStatusCount).toBe(2);
    expect(fixture.pdf.pages).toBeGreaterThanOrEqual(2);
    await capture(run, 'sent', testInfo.project.name);
    await page.getByRole('button', { name: 'Start another estimate', exact: true }).click();
    await page.getByRole('searchbox', { name: 'Search customers' }).fill('Fixture');
    await page.locator('.tc-results button').filter({ hasText: account.name }).click();
    await expect(page.getByRole('button', { name: 'Next', exact: true })).toBeDisabled();
    await capture(run, 'restart-reference-edge', testInfo.project.name);
  }
  await compareRuns(runs, testInfo.project.name, 'complete-journey');
});

test('recognized nonce refresh and explicit in-flight replay preserve the attempt key', async ({ context }, testInfo) => {
  const runs = [];
  for (const variant of ['reference', 'rebuilt']) {
    const run = await open(context, variant, { expiredNonce: true, inFlight: true });
    runs.push(run);
    await simpleReview(run);
    await run.page.getByRole('button', { name: 'Create draft estimate', exact: true }).click();
    await expect(run.page.getByText('Synthetic draft is still processing.', { exact: true })).toBeVisible();
    expect(run.fixture.generateCount).toBe(1);
    await capture(run, 'generation-in-flight', testInfo.project.name);
    await run.page.getByRole('button', { name: 'Wait and replay', exact: true }).click();
    await expect(run.page.getByRole('heading', { name: 'Draft estimate created', exact: true })).toBeVisible();
    const generates = run.fixture.requests.filter((entry) => entry.path === '/generate');
    expect(generates).toHaveLength(2);
    expect(generates[0].headers.idempotency).toBe(generates[1].headers.idempotency);
    expect(run.fixture.nonceRefreshes).toBe(1);
    expect(generates[1].headers.nonce).toBe('fixture-refreshed-nonce');
    expect(run.fixture.requests.filter((entry) => entry.path === '/send-estimate')).toHaveLength(0);
    await capture(run, 'draft-replayed', testInfo.project.name);
  }
  await compareRuns(runs, testInfo.project.name, 'nonce-and-replay');
});

test('missing template and offline banner remain explicit without synthetic customer persistence', async ({ context }, testInfo) => {
  const runs = [];
  for (const variant of ['reference', 'rebuilt']) {
    const run = await open(context, variant, { noTemplates: true });
    runs.push(run);
    await expect(run.page.getByText('The proposal layout is not configured.', { exact: true })).toBeVisible();
    await run.page.evaluate(() => {
      Object.defineProperty(navigator, 'onLine', { configurable: true, value: false });
      window.dispatchEvent(new Event('offline'));
    });
    await expect(run.page.getByRole('status')).toContainText('Offline');
    await expect(run.page.getByRole('button', { name: 'Next', exact: true })).toBeDisabled();
    expect(await run.page.evaluate(() => [localStorage.length, sessionStorage.length])).toEqual([0, 0]);
    await capture(run, 'offline-missing-template', testInfo.project.name);
  }
  await compareRuns(runs, testInfo.project.name, 'offline-and-missing-template');
});

test('loss of status after a queued PDF send is pending and never triggers automatic resend', async ({ context }, testInfo) => {
  const runs = [];
  for (const variant of ['reference', 'rebuilt']) {
    const run = await open(context, variant, { statusConnectionLoss: true });
    runs.push(run);
    await simpleReview(run);
    await run.page.getByRole('textbox', { name: 'Edit polished proposal', exact: true }).fill('Fixture café proposal');
    await run.page.getByRole('button', { name: 'Create draft estimate', exact: true }).click();
    await expect(run.page.getByRole('heading', { name: 'Draft estimate created', exact: true })).toBeVisible();
    await run.page.getByRole('button', { name: 'Send polished proposal to customer', exact: true }).click();
    await expect(run.page.getByText('The email job is still running, but this page lost its status connection. Do not send it again; check the customer email status in a minute.', { exact: true })).toBeVisible();
    expect(run.fixture.requests.filter((entry) => entry.path === '/send-estimate')).toHaveLength(1);
    await expect(run.page.getByRole('button', { name: /Send polished proposal to customer|Save changes & send polished proposal/ })).toHaveCount(0);
    await capture(run, 'send-pending-connection-loss', testInfo.project.name);
  }
  await compareRuns(runs, testInfo.project.name, 'send-connection-loss');
});
