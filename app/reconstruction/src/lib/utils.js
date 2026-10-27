/** Reconstructed from the saved v0.4.8 deployment bundle; not recovered original source. */
export function uuid() {
  if (typeof crypto !== "undefined" && typeof crypto.randomUUID === "function")
    return crypto.randomUUID();
  const bytes = new Uint8Array(16);
  if (
    typeof crypto !== "undefined" &&
    typeof crypto.getRandomValues === "function"
  )
    crypto.getRandomValues(bytes);
  else
    for (let index = 0; index < 16; index++)
      bytes[index] = Math.floor(Math.random() * 256);
  bytes[6] = (bytes[6] & 15) | 64;
  bytes[8] = (bytes[8] & 63) | 128;
  const hex = Array.from(bytes, (byte) =>
    byte.toString(16).padStart(2, "0"),
  ).join("");
  return `${hex.slice(0, 8)}-${hex.slice(8, 12)}-${hex.slice(12, 16)}-${hex.slice(16, 20)}-${hex.slice(20)}`;
}
export function newIdempotencyKey(prefix = "tc") {
  return `${prefix}-${uuid()}`;
}
export function formatMoney(amount) {
  return new Intl.NumberFormat("en-US", {
    style: "currency",
    currency: "USD",
    maximumFractionDigits: 2,
  }).format(Number.isFinite(amount) ? amount : 0);
}
export function hasSystemChoices(systems) {
  return (systems || []).some((system) => Array.isArray(system.choices));
}
export function selectedChoice(system) {
  return Array.isArray(system?.choices)
    ? system.choices.find(
        (choice) => choice.choice_id === system.selected_choice_id,
      ) || null
    : null;
}
export function selectedEquipment(system) {
  // Once alternatives exist, a stale legacy equipment mirror is never used.
  return Array.isArray(system?.choices)
    ? selectedChoice(system)?.equipment || {}
    : system?.equipment || {};
}
export function systemSalesPrice(system) {
  if (!Array.isArray(system?.choices)) return sumEquipment([system]);
  const price = selectedChoice(system)?.sales_price;
  return Number.isFinite(price) && price >= 0 ? price : 0;
}
export function sumSelectedSales(systems) {
  return (
    Math.round(
      (systems || []).reduce(
        (sum, system) => sum + systemSalesPrice(system),
        0,
      ) * 100,
    ) / 100
  );
}
export function sumEquipment(systems) {
  let subtotal = 0;
  for (const system of systems || []) {
    for (const item of Object.values(selectedEquipment(system))) {
      if (item && Number.isFinite(item.rate)) subtotal += item.rate;
    }
  }
  return Math.round(subtotal * 100) / 100;
}
/** Purchase costs are separate from sales rates; unknown costs stay unknown. */
export function equipmentCostSummary(systems) {
  let knownSubtotal = 0;
  let missingCount = 0;
  let selectedCount = 0;
  for (const system of systems || []) {
    for (const item of Object.values(selectedEquipment(system))) {
      if (!item) continue;
      selectedCount += 1;
      if (Number.isFinite(item.purchase_cost) && item.purchase_cost >= 0)
        knownSubtotal += item.purchase_cost;
      else missingCount += 1;
    }
  }
  knownSubtotal = Math.round(knownSubtotal * 100) / 100;
  return {
    subtotal: missingCount === 0 ? knownSubtotal : null,
    knownSubtotal,
    missingCount,
    selectedCount,
  };
}
export function cx(...classes) {
  return classes.filter(Boolean).join(" ");
}
export function filterEquipment(items, query) {
  if (!query) return items;
  const search = String(query).toLowerCase().trim();
  if (!search) return items;
  return items.filter((item) =>
    `${item.brand || ""} ${item.model || ""} ${item.name || ""} ${item.sku || ""} ${item.js_part_number || ""} ${item.mfg_part_number || ""} ${item.short_description || ""}`
      .toLowerCase()
      .includes(search),
  );
}
