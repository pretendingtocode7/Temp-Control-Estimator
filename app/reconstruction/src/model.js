/** Reconstructed from the saved v0.4.8 deployment bundle; not recovered original source. */
import { newIdempotencyKey } from "./lib/utils.js";

/** Strip hydrated catalog/customer fields at the same boundary as the ZIP client. */
export function buildPayload(draft, includeBooksEdits = true) {
  const systems = draft.systems.map((system) => {
    const equipment = {};
    for (const [slot, item] of Object.entries(system.equipment || {})) {
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
    return {
      system_number: system.system_number,
      system_label: system.system_label,
      equipment,
    };
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
        draft.systems.every(
          (system) => Object.keys(system.equipment || {}).length > 0,
        )
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
    },
    pricing: {
      subtotal: 0,
      total: 0,
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
    case "setSystems":
      return {
        ...state,
        systems: action.systems,
        preview_html: "",
        books_edits: null,
      };
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
    case "setPricing":
      return {
        ...state,
        pricing: {
          ...state.pricing,
          ...action.pricing,
        },
        preview_html: "",
        books_edits: null,
      };
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
