/** Reconstructed from the saved v0.4.8 deployment bundle; not recovered original source. */
import {
  equipmentCostSummary,
  hasSystemChoices,
  newIdempotencyKey,
  selectedChoice,
  selectedEquipment,
  sumEquipment,
  sumSelectedSales,
} from "./lib/utils.js";

/** Strip hydrated catalog/customer fields at the same boundary as the ZIP client. */
function equipmentPayload(source) {
  const equipment = {};
  for (const [slot, item] of Object.entries(source || {})) {
    if (!item || !item.item_id) continue;
    equipment[slot] = { item_id: item.item_id };
    if (item.notes) equipment[slot].notes = item.notes;
    if (Object.prototype.hasOwnProperty.call(item, "description")) {
      equipment[slot].description = item.description;
    }
    // Only a municipal permit has a technician-editable price in v0.4.8.
    if (
      slot === "permit" &&
      Object.prototype.hasOwnProperty.call(item, "rate")
    ) {
      equipment[slot].rate = item.rate;
    }
  }
  return equipment;
}
export function buildPayload(draft, includeBooksEdits = true) {
  const systems = draft.systems.map((system) => {
    const result = {
      system_number: system.system_number,
      system_label: system.system_label,
      equipment: equipmentPayload(selectedEquipment(system)),
    };
    if (Array.isArray(system.choices)) {
      result.selected_choice_id = system.selected_choice_id;
      result.choices = system.choices.map((choice) => ({
        choice_id: choice.choice_id,
        label: choice.label,
        equipment: equipmentPayload(choice.equipment),
        sales_price: choice.sales_price,
      }));
    }
    return result;
  });
  const payload = {
    template_id: draft.template?.id,
    customer: { zoho_account_id: draft.customer?.id },
    systems,
    options: draft.options,
    pricing: {
      subtotal: draft.pricing.subtotal,
      total: draft.pricing.total,
      deposit_percent: draft.pricing.deposit_percent,
    },
  };
  if (hasSystemChoices(draft.systems))
    payload.pricing.project_adjustment = draft.pricing.project_adjustment || 0;
  if (includeBooksEdits && draft.books_edits)
    payload.books_edits = draft.books_edits;
  return payload;
}

export function canAdvance(draft) {
  switch (draft.step) {
    case "customer":
      return !!draft.customer?.id && !!draft.template?.id;
    case "systems":
      return (
        draft.systems.length > 0 &&
        draft.systems.every((system) => {
          const hasEquipment = (equipment) =>
            Object.values(equipment || {}).some((item) => item?.item_id);
          if (!Array.isArray(system.choices))
            return hasEquipment(system.equipment);
          return (
            system.choices.length > 0 &&
            system.choices.length <= 6 &&
            !!selectedChoice(system) &&
            new Set(system.choices.map((choice) => choice.choice_id)).size ===
              system.choices.length &&
            system.choices.every(
              (choice) =>
                !!choice.choice_id &&
                typeof choice.label === "string" &&
                choice.label.trim().length > 0 &&
                choice.label.length <= 60 &&
                hasEquipment(choice.equipment) &&
                Number.isFinite(choice.sales_price) &&
                choice.sales_price >= 0,
            )
          );
        })
      );
    case "pricing":
      return (draft.pricing.total || 0) > 0;
    case "review":
      return true;
    default:
      return false;
  }
}
export const STEPS = ["customer", "systems", "pricing", "review", "success"],
  STEP_LABELS = {
    customer: "Customer",
    systems: "Equipment",
    pricing: "Pricing",
    review: "Review",
    success: "Done",
  };
export function createInitialState() {
  return {
    step: "customer",
    customer: null,
    template: null,
    systems: [
      {
        system_number: 1,
        system_label: "Main System",
        equipment: {},
      },
    ],
    options: {
      warranty_parts_years: 10,
      warranty_labor_years: 10,
      special_notes: "",
      rebates: [],
      financing_requested: false,
      financing_term_months: 60,
      credit_card_fee_applies: false,
      financing_fee_applies: false,
      include_maintenance: false,
      include_permits: false,
    },
    pricing: {
      subtotal: 0,
      equipment_cost: 0,
      equipment_cost_manual: false,
      total: 0,
      total_manual: false,
      deposit_percent: 35,
    },
    idempotency_key: null,
    preview_html: "",
    books_edits: null,
    result: null,
  };
}
export function draftReducer(state, action) {
  switch (action.type) {
    case "setStep":
      return {
        ...state,
        step: action.step,
      };
    case "setCustomer":
      return {
        ...state,
        customer: action.customer,
        preview_html: "",
        books_edits: null,
      };
    case "setTemplate":
      return {
        ...state,
        template: action.template,
        options: {
          ...state.options,
          warranty_parts_years:
            action.template?.default_warranty_parts ??
            state.options.warranty_parts_years,
          warranty_labor_years:
            action.template?.default_warranty_labor ??
            state.options.warranty_labor_years,
        },
        preview_html: "",
        books_edits: null,
      };
    case "setSystems": {
      const systems = action.systems.map((system) =>
        Array.isArray(system.choices)
          ? {
              ...system,
              choices: system.choices.map((choice) => {
                if (choice.sales_price_manual) return choice;
                const sales_price = sumEquipment([
                  { equipment: choice.equipment },
                ]);
                return sales_price === choice.sales_price
                  ? choice
                  : { ...choice, sales_price };
              }),
            }
          : system,
      );
      const wasChoices = hasSystemChoices(state.systems);
      const choicesMode = hasSystemChoices(systems);
      const selectedSales = sumSelectedSales(systems);
      const salesSubtotal = sumEquipment(systems);
      const cost = equipmentCostSummary(systems);
      const adjustment = wasChoices
        ? state.pricing.project_adjustment || 0
        : state.pricing.total_manual
          ? Math.round((state.pricing.total - selectedSales) * 100) / 100
          : 0;
      return {
        ...state,
        systems,
        pricing: {
          ...state.pricing,
          // Keep the legacy Books subtotal as sales data; actual purchase cost
          // is internal only and is excluded by buildPayload's pricing allowlist.
          subtotal: salesSubtotal,
          total:
            choicesMode || wasChoices
              ? Math.round((selectedSales + adjustment) * 100) / 100
              : state.pricing.total_manual
                ? state.pricing.total
                : salesSubtotal,
          ...(choicesMode
            ? { project_adjustment: adjustment, total_manual: adjustment !== 0 }
            : wasChoices
              ? { project_adjustment: 0, total_manual: adjustment !== 0 }
              : {}),
          equipment_cost: state.pricing.equipment_cost_manual
            ? state.pricing.equipment_cost
            : cost.subtotal,
        },
        preview_html: "",
        books_edits: null,
      };
    }
    case "setOptions":
      return {
        ...state,
        options: {
          ...state.options,
          ...action.options,
        },
        preview_html: "",
        books_edits: null,
      };
    case "setPricing": {
      const pricing = { ...state.pricing, ...action.pricing };
      if (Object.prototype.hasOwnProperty.call(action.pricing, "total"))
        pricing.total_manual = action.pricing.total_manual !== false;
      if (
        hasSystemChoices(state.systems) &&
        Object.prototype.hasOwnProperty.call(action.pricing, "total")
      ) {
        const selectedSales = sumSelectedSales(state.systems);
        pricing.project_adjustment =
          action.pricing.total_manual === false
            ? 0
            : Math.round((pricing.total - selectedSales) * 100) / 100;
        pricing.total =
          Math.round((selectedSales + pricing.project_adjustment) * 100) / 100;
        pricing.total_manual = pricing.project_adjustment !== 0;
      }
      if (
        Object.prototype.hasOwnProperty.call(action.pricing, "equipment_cost")
      )
        pricing.equipment_cost_manual =
          action.pricing.equipment_cost_manual !== false;
      return {
        ...state,
        pricing,
        preview_html: "",
        books_edits: null,
      };
    }
    case "allocateIdempotencyKey":
      return state.idempotency_key
        ? state
        : {
            ...state,
            idempotency_key: newIdempotencyKey(),
          };
    case "resetIdempotencyKey":
      return {
        ...state,
        idempotency_key: null,
      };
    case "setResult":
      return {
        ...state,
        result: action.result,
        step: "success",
      };
    case "setPreviewHtml":
      return {
        ...state,
        preview_html: action.html || "",
      };
    case "setBooksEdits":
      return {
        ...state,
        books_edits: action.booksEdits || null,
      };
    case "reset":
      return createInitialState();
    default:
      return state;
  }
}
