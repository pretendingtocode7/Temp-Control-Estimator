import { expect } from '@playwright/test';
import { inspectFixturePdf } from './pdf-inspect.js';

export const account = {
  id: '200000000000000001', name: 'Fixture Customer', phone: '555-0100', email: 'fixture@example.test',
  billing_address: { street: '7 Example Street', city: 'Fixture City', state: 'NJ', zip: '08817', country: 'USA' },
};
export const items = [
  { item_id: '400000000000000001', equipment_type: 'furnace', name: 'Fixture Furnace', brand: 'Fixture', model: 'FURNACE-80', sku: 'F-80', js_part_number: 'JS-FURNACE', mfg_part_number: 'MFG-F80', short_description: '80k BTU furnace', rate: 3200, afue: 96, stages: 'Single', source: 'zoho_books' },
  { item_id: '400000000000000002', equipment_type: 'furnace', name: 'Alternate Furnace', brand: 'Alternate', model: 'ALT-60', short_description: 'Alternate fixture furnace', rate: 2800, afue: null, source: 'zoho_books' },
  { item_id: '400000000000000003', equipment_type: 'evaporator_coil', name: 'Fixture Coil', brand: 'Fixture', model: 'COIL-36', short_description: 'Matched fixture coil', rate: 900, tons: 3, source: 'zoho_books' },
  { item_id: '400000000000000004', equipment_type: 'permit', name: 'Fixture Municipal Permit', brand: '', model: '', short_description: 'Municipality permit', rate: 125, source: 'zoho_books' },
  { item_id: '400000000000000005', equipment_type: 'condenser', name: 'Fixture Condenser', brand: 'Fixture', model: 'COND-36', short_description: 'Three-ton fixture condenser', rate: 4100, seer: 14.3, tons: 3, refrigerant: 'R-410A', source: 'zoho_books' },
];
export const template = {
  id: 42, name: 'Fixture Full Replacement', template_type: 'full_replacement',
  default_warranty_parts: 10, default_warranty_labor: 10, active: true, version: 3,
  required_slots: [], supports_financing: true, supports_rebates: true,
};
const escapeHtml = (value) => String(value).replaceAll('&', '&amp;').replaceAll('<', '&lt;').replaceAll('>', '&gt;').replaceAll('"', '&quot;');
export function previewFor(payload) {
  const lines = payload.systems.flatMap((system) => Object.entries(system.equipment).map(([slot, selected]) => {
    const item = items.find((entry) => entry.item_id === selected.item_id);
    return { key: `system_${system.system_number}_${slot}_0`, item_id: item.item_id, name: item.name, description: selected.description ?? item.short_description, rate: slot === 'permit' ? selected.rate ?? item.rate : item.rate };
  }));
  const html = `<h1>Fixture Proposal</h1><p>Prepared for ${account.name}</p><h2>Equipment and scope</h2><ul>${lines.map((line) => `<li>${escapeHtml(line.name)} — ${escapeHtml(line.description)}</li>`).join('')}</ul><p>Project total: $${Number(payload.pricing.total).toFixed(2)}</p><p>Review and approval are separate from draft creation.</p>`;
  return { html, books_preview: { line_items: lines, notes: 'Please review this estimate and approve through Zoho Books when ready to proceed.', terms: 'Fixture proposal valid for 30 days.' }, view: {}, template_id: 42, template_version: 3 };
}

export async function installFixture(page, options = {}) {
  const state = { requests: [], blocked: [], errors: [], nonceRefreshes: 0, generateCount: 0, sendStatusCount: 0 };
  page.on('pageerror', (error) => state.errors.push(error.message));
  await page.context().addCookies([{ name: 'fixture_session', value: 'synthetic-only', url: 'http://127.0.0.1:4178' }]);
  await page.addInitScript(() => {
    let nextUuid = 0;
    Object.defineProperty(crypto, 'randomUUID', { value: () => `00000000-0000-4000-8000-${String(++nextUuid).padStart(12, '0')}` });
    Object.defineProperty(navigator, 'serviceWorker', { value: { register: async () => ({ addEventListener() {}, installing: null }), controller: null } });
  });
  await page.route('**/*', async (route) => {
    const request = route.request(), url = new URL(request.url());
    if (url.origin !== 'http://127.0.0.1:4178') {
      state.blocked.push(request.url());
      return route.abort('blockedbyclient');
    }
    if (!url.pathname.startsWith('/wp-json/') && !url.pathname.startsWith('/wp-admin/')) return route.continue();
    const body = request.postData() ? (() => { try { return JSON.parse(request.postData()); } catch { return request.postData(); } })() : undefined;
    const headers = await request.allHeaders();
    expect(headers.cookie).toContain('fixture_session=synthetic-only');
    if (url.pathname.startsWith('/wp-json/')) expect(headers['x-wp-nonce']).toMatch(/^fixture-(?:refreshed-)?nonce$/);
    state.requests.push({ method: request.method(), path: url.pathname.replace('/wp-json/tc-estimate/v1', ''), query: Object.fromEntries(url.searchParams), body, headers: { nonce: headers['x-wp-nonce'], idempotency: headers['idempotency-key'], cookie: headers.cookie } });
    const respond = (data, status = 200) => route.fulfill({ status, contentType: 'application/json', body: JSON.stringify({ ok: true, data }) });
    const error = (code, message, status = 409) => route.fulfill({ status, contentType: 'application/json', body: JSON.stringify({ ok: false, error: { code, message } }) });
    if (url.pathname === '/wp-admin/admin-ajax.php') {
      state.nonceRefreshes++;
      expect(request.postData()).toBe('action=tc_estimate_rest_nonce');
      return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ success: true, data: { nonce: 'fixture-refreshed-nonce', restUrl: 'http://127.0.0.1:4178/wp-json/tc-estimate/v1' } }) });
    }
    const endpoint = url.pathname.replace('/wp-json/tc-estimate/v1', '');
    if (endpoint === '/templates') {
      if (options.expiredNonce && !state.nonceRefreshes) return route.fulfill({ status: 403, contentType: 'application/json', body: JSON.stringify({ code: 'rest_cookie_invalid_nonce', message: 'Cookie check failed', data: { status: 403 } }) });
      return respond(options.noTemplates ? [] : [template]);
    }
    if (endpoint === '/customers') return respond(url.searchParams.get('q')?.toLowerCase().includes('fixture') ? [account] : []);
    if (endpoint === '/equipment') {
      const type = url.searchParams.get('type');
      return respond((type ? items.filter((item) => item.equipment_type === type) : items).slice(0, Number(url.searchParams.get('limit') || 50)));
    }
    if (endpoint === '/preview') return respond(previewFor(body));
    if (endpoint === '/generate') {
      state.generateCount++;
      expect(headers['idempotency-key']).toMatch(/^tc-[a-f0-9-]+$/);
      expect(body.customer).toEqual({ zoho_account_id: account.id });
      expect(body).not.toHaveProperty('preview_html');
      expect(body).not.toHaveProperty('html');
      if (options.inFlight && state.generateCount === 1) return error('tc_estimate_in_flight', 'Synthetic draft is still processing.');
      return respond({ estimate_id: '500000000000000001', estimate_number: options.inFlight ? undefined : 'EST-FIXTURE-1', estimate_status: 'draft', estimate_url: 'https://books.example.test/estimate/500000000000000001', deal_id: '600000000000000001', deal_url: 'https://crm.example.test/deal/600000000000000001', total: body.pricing.total, subtotal: body.pricing.subtotal, books_email_sent: false, idempotency_key: headers['idempotency-key'], replayed: options.inFlight || undefined, duration_ms: options.inFlight ? undefined : 17 }, options.inFlight ? 200 : 201);
    }
    if (endpoint === '/update-estimate') {
      expect(body.estimate_id).toBe('500000000000000001');
      return respond({ estimate_id: body.estimate_id, saved: true, message: 'Books draft updated.' });
    }
    if (endpoint === '/send-estimate') {
      const attachment = body.pretty_attachment;
      expect(body.estimate_id).toBe('500000000000000001');
      expect(attachment.filename).toBe('Temp-Control-Proposal-Fixture-Customer.pdf');
      const pdf = Buffer.from(attachment.data, 'base64'), text = pdf.toString('latin1');
      expect(text.slice(0, 5)).toBe('%PDF-');
      expect(pdf.length).toBeLessThan(4 * 1024 * 1024);
      expect(attachment.data).not.toMatch(/^data:/);
      const html = Buffer.from(attachment.html_b64, 'base64').toString('utf8');
      expect(html).toContain('café');
      state.pdf = { filename: attachment.filename, bytes: pdf.length, declaredBytes: attachment.size, pages: (text.match(/\/Type\s*\/Page\b/g) || []).length, letterPage: /\/MediaBox\s*\[\s*0\s+0\s+612(?:\.0*)?\s+792(?:\.0*)?\s*\]/.test(text), html };
      state.pdf.mediaBoxes = Array.from(text.matchAll(/\/MediaBox\s*\[([^\]]+)\]/g), (match) => match[1].trim().split(/\s+/).map(Number));
      const inspection = inspectFixturePdf(pdf);
      state.pdfImages = inspection.images;
      state.pdf.placements = inspection.placements;
      expect(state.pdf.pages).toBeGreaterThan(0);
      expect(state.pdf.letterPage).toBe(true);
      expect(state.pdf.mediaBoxes).toHaveLength(state.pdf.pages);
      for (const mediaBox of state.pdf.mediaBoxes) expect(mediaBox).toEqual([0, 0, 612, 792]);
      expect(attachment.size).toBeGreaterThanOrEqual(pdf.length);
      expect(attachment.size).toBeLessThanOrEqual(pdf.length + 2);
      expect(state.pdfImages).toHaveLength(state.pdf.pages);
      expect(state.pdf.placements).toHaveLength(state.pdf.pages);
      for (const placement of state.pdf.placements) {
        expect(placement.width).toBe(556);
        expect(placement.x).toBe(28);
        expect(placement.height).toBeGreaterThan(0);
        expect(placement.height).toBeLessThanOrEqual(736);
        expect(placement.y).toBeGreaterThanOrEqual(28);
        expect(placement.y + placement.height).toBeCloseTo(764, 1);
      }
      return respond({ job_id: 'fixture-job-1', estimate_id: body.estimate_id, status: 'queued', sent: false, message: 'Fixture email queued.' }, 202);
    }
    if (endpoint === '/send-status') {
      expect(url.searchParams.get('job_id')).toBe('fixture-job-1');
      state.sendStatusCount++;
      if (options.statusConnectionLoss) return error('synthetic_status_unavailable', 'Synthetic status connection lost.', 503);
      return respond({ job_id: 'fixture-job-1', estimate_id: '500000000000000001', status: state.sendStatusCount === 1 ? 'processing' : 'sent', sent: state.sendStatusCount > 1, message: state.sendStatusCount === 1 ? 'Fixture email processing.' : 'Fixture email marked sent; no email was delivered.' });
    }
    throw new Error(`Unexpected fixture endpoint: ${request.method()} ${url.pathname}`);
  });
  return state;
}

export function normalizedRequests(requests) {
  return requests.map((request) => {
    if (request.path !== '/send-estimate') return request;
    const attachment = request.body.pretty_attachment;
    return { ...request, body: { ...request.body, pretty_attachment: { filename: attachment.filename, html: Buffer.from(attachment.html_b64, 'base64').toString('utf8'), pdf: 'valid-letter-pdf-verified-separately' } } };
  });
}
