import React, { useCallback, useEffect, useMemo, useReducer, useState } from 'react';
import { createApi } from './lib/api.js';
import { newIdempotencyKey } from './lib/idempotency.js';
import { sumLineItems } from './lib/format.js';
import CustomerStep from './components/CustomerStep.jsx';
import TemplateStep from './components/TemplateStep.jsx';
import SystemsStep from './components/SystemsStep.jsx';
import PricingStep from './components/PricingStep.jsx';
import ReviewStep from './components/ReviewStep.jsx';
import SuccessStep from './components/SuccessStep.jsx';
import ProgressBar from './components/ProgressBar.jsx';
import OfflineBanner from './components/OfflineBanner.jsx';

/**
 * Wizard steps, in order.
 */
const STEPS = ['customer', 'template', 'systems', 'pricing', 'review', 'success'];

const STEP_LABELS = {
  customer: 'Customer',
  template: 'Template',
  systems: 'Equipment',
  pricing: 'Pricing',
  review: 'Review',
  success: 'Done',
};

/**
 * The draft estimate shape. This is the authoritative client-side state,
 * and also the shape we POST to /preview and /generate (with small transforms).
 */
function initialDraft() {
  return {
    step: 'customer',
    customer: null,
    template: null,
    systems: [
      {
        system_number: 1,
        system_label: 'Main System',
        equipment: {}, // { furnace: {item_id,...}, condenser: {...}, ... }
      },
    ],
    options: {
      warranty_parts_years: 10,
      warranty_labor_years: 10,
      special_notes: '',
      rebates: [],
      financing_requested: false,
      financing_term_months: 60,
    },
    pricing: {
      subtotal: 0,
      total: 0,
      deposit_percent: 35,
    },
    idempotency_key: null, // allocated at review step entry
    result: null, // populated after successful /generate
  };
}

function draftReducer(state, action) {
  switch (action.type) {
    case 'setStep':
      return { ...state, step: action.step };
    case 'setCustomer':
      return { ...state, customer: action.customer };
    case 'setTemplate':
      return {
        ...state,
        template: action.template,
        options: {
          ...state.options,
          warranty_parts_years:
            action.template?.default_warranty_parts ?? state.options.warranty_parts_years,
          warranty_labor_years:
            action.template?.default_warranty_labor ?? state.options.warranty_labor_years,
        },
      };
    case 'setSystems':
      return { ...state, systems: action.systems };
    case 'setOptions':
      return { ...state, options: { ...state.options, ...action.options } };
    case 'setPricing':
      return { ...state, pricing: { ...state.pricing, ...action.pricing } };
    case 'allocateIdempotencyKey':
      return state.idempotency_key
        ? state
        : { ...state, idempotency_key: newIdempotencyKey() };
    case 'resetIdempotencyKey':
      return { ...state, idempotency_key: null };
    case 'setResult':
      return { ...state, result: action.result, step: 'success' };
    case 'reset':
      return initialDraft();
    default:
      return state;
  }
}

export default function App({ restUrl, ajaxUrl, nonce, brand }) {
  const api = useMemo(() => createApi({ restUrl, ajaxUrl, nonce }), [restUrl, ajaxUrl, nonce]);
  const [draft, dispatch] = useReducer(draftReducer, undefined, initialDraft);
  const [submitState, setSubmitState] = useState({ status: 'idle', error: null });
  const [isOnline, setIsOnline] = useState(navigator.onLine);

  // Online/offline tracking for the top banner.
  useEffect(() => {
    function update() {
      setIsOnline(navigator.onLine);
    }
    window.addEventListener('online', update);
    window.addEventListener('offline', update);
    return () => {
      window.removeEventListener('online', update);
      window.removeEventListener('offline', update);
    };
  }, []);

  // Keep pricing.subtotal in sync with selected equipment — users can still override total.
  useEffect(() => {
    const subtotal = sumLineItems(draft.systems);
    if (subtotal !== draft.pricing.subtotal) {
      dispatch({ type: 'setPricing', pricing: { subtotal } });
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [JSON.stringify(draft.systems)]);

  const canAdvance = useMemo(() => {
    switch (draft.step) {
      case 'customer':
        return !!draft.customer?.id;
      case 'template':
        return !!draft.template?.id;
      case 'systems': {
        // Every system must have at least one piece of equipment.
        return (
          draft.systems.length > 0 &&
          draft.systems.every((s) => Object.keys(s.equipment || {}).length > 0)
        );
      }
      case 'pricing':
        return (draft.pricing.total || 0) > 0;
      case 'review':
        return true;
      default:
        return false;
    }
  }, [draft]);

  const goNext = useCallback(() => {
    const idx = STEPS.indexOf(draft.step);
    if (idx < 0 || idx >= STEPS.length - 1) return;
    const next = STEPS[idx + 1];
    dispatch({ type: 'setStep', step: next });
    // Allocate idempotency key as we enter review.
    if (next === 'review') {
      dispatch({ type: 'allocateIdempotencyKey' });
    }
  }, [draft.step]);

  const goBack = useCallback(() => {
    const idx = STEPS.indexOf(draft.step);
    if (idx <= 0) return;
    dispatch({ type: 'setStep', step: STEPS[idx - 1] });
  }, [draft.step]);

  /**
   * Build the payload shape that matches /preview and /generate.
   * Extracting this keeps the wire format in one place.
   */
  const buildPayload = useCallback(() => {
    // Keep only user-entered values plus item_id — the server re-hydrates catalog data
    // from Zoho Books and enforces cf_for_estimate again before creating anything.
    const systems = draft.systems.map((s) => {
      const equipment = {};
      for (const [slot, item] of Object.entries(s.equipment || {})) {
        if (item && item.item_id) {
          equipment[slot] = { item_id: item.item_id };
          if (item.notes) equipment[slot].notes = item.notes;
          if (Object.prototype.hasOwnProperty.call(item, 'description')) {
            equipment[slot].description = item.description;
          }
        }
      }
      return {
        system_number: s.system_number,
        system_label: s.system_label,
        equipment,
      };
    });

    return {
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
  }, [draft]);

  const submit = useCallback(async () => {
    if (!draft.idempotency_key) {
      dispatch({ type: 'allocateIdempotencyKey' });
      return;
    }
    setSubmitState({ status: 'submitting', error: null });
    try {
      const result = await api.generate(buildPayload(), draft.idempotency_key);
      dispatch({ type: 'setResult', result });
      setSubmitState({ status: 'success', error: null });
    } catch (err) {
      setSubmitState({ status: 'error', error: err });
    }
  }, [api, buildPayload, draft.idempotency_key]);

  const startOver = useCallback(() => {
    dispatch({ type: 'reset' });
    setSubmitState({ status: 'idle', error: null });
  }, []);

  return (
    <div className="tc-app" data-step={draft.step}>
      <header className="tc-header">
        <span className="tc-header-title">Estimate Builder</span>
        <span className="tc-header-sub">{brand?.name || 'Temp Control'}</span>
      </header>

      {!isOnline && <OfflineBanner />}

      {draft.step !== 'success' && (
        <ProgressBar
          steps={STEPS.filter((s) => s !== 'success')}
          labels={STEP_LABELS}
          current={draft.step}
        />
      )}

      <main className="tc-main">
        {draft.step === 'customer' && (
          <CustomerStep
            api={api}
            customer={draft.customer}
            onSelect={(customer) => dispatch({ type: 'setCustomer', customer })}
          />
        )}

        {draft.step === 'template' && (
          <TemplateStep
            api={api}
            template={draft.template}
            onSelect={(template) => dispatch({ type: 'setTemplate', template })}
          />
        )}

        {draft.step === 'systems' && (
          <SystemsStep
            api={api}
            systems={draft.systems}
            template={draft.template}
            onChange={(systems) => dispatch({ type: 'setSystems', systems })}
          />
        )}

        {draft.step === 'pricing' && (
          <PricingStep
            pricing={draft.pricing}
            options={draft.options}
            onPricingChange={(pricing) => dispatch({ type: 'setPricing', pricing })}
            onOptionsChange={(options) => dispatch({ type: 'setOptions', options })}
          />
        )}

        {draft.step === 'review' && (
          <ReviewStep
            api={api}
            payload={buildPayload()}
            draft={draft}
            submitState={submitState}
            onSubmit={submit}
            onRetryNewKey={() => {
              dispatch({ type: 'resetIdempotencyKey' });
              dispatch({ type: 'allocateIdempotencyKey' });
              setSubmitState({ status: 'idle', error: null });
            }}
          />
        )}

        {draft.step === 'success' && (
          <SuccessStep api={api} result={draft.result} onStartOver={startOver} />
        )}
      </main>

      {draft.step !== 'success' && (
        <footer className="tc-footer">
          <button
            type="button"
            className="tc-btn tc-btn-ghost"
            onClick={goBack}
            disabled={draft.step === 'customer'}
          >
            Back
          </button>
          {draft.step === 'review' ? (
            <button
              type="button"
              className="tc-btn tc-btn-primary"
              onClick={submit}
              disabled={submitState.status === 'submitting'}
            >
              {submitState.status === 'submitting' ? 'Creating…' : 'Create draft estimate'}
            </button>
          ) : (
            <button
              type="button"
              className="tc-btn tc-btn-primary"
              onClick={goNext}
              disabled={!canAdvance}
            >
              Next
            </button>
          )}
        </footer>
      )}
    </div>
  );
}
