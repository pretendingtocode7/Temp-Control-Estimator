/**
 * Small pure helpers. Kept framework-free so they stay testable.
 */

export function formatMoney(amount) {
  const n = Number.isFinite(amount) ? amount : 0;
  return new Intl.NumberFormat('en-US', {
    style: 'currency',
    currency: 'USD',
    maximumFractionDigits: 2,
  }).format(n);
}

export function sumLineItems(systems) {
  let subtotal = 0;
  for (const s of systems || []) {
    for (const [, item] of Object.entries(s.equipment || {})) {
      if (item && Number.isFinite(item.rate)) {
        subtotal += item.rate;
      }
    }
  }
  return Math.round(subtotal * 100) / 100;
}

export function cls(...parts) {
  return parts.filter(Boolean).join(' ');
}

// Keep technicians typing "3036" finding "AC8B3036A1B". Basic case-insensitive
// substring match suffices at the client layer; server-side search handles the
// big filters.
export function localFilter(items, query) {
  if (!query) return items;
  const q = String(query).toLowerCase().trim();
  if (!q) return items;
  return items.filter((it) => {
    const hay = `${it.brand || ''} ${it.model || ''} ${it.name || ''} ${it.sku || ''} ${it.js_part_number || ''} ${it.mfg_part_number || ''} ${it.short_description || ''}`.toLowerCase();
    return hay.includes(q);
  });
}
