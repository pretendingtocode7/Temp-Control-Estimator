import React from 'react';
import { formatMoney } from '../lib/format.js';

export default function PricingStep({ pricing, options, onPricingChange, onOptionsChange }) {
  const subtotalHint = pricing.subtotal || 0;

  const updateRebate = (i, patch) => {
    const rebates = (options.rebates || []).map((r, idx) => (idx === i ? { ...r, ...patch } : r));
    onOptionsChange({ rebates });
  };
  const addRebate = () => {
    const rebates = [...(options.rebates || []), { name: '', amount: 0 }];
    onOptionsChange({ rebates });
  };
  const removeRebate = (i) => {
    const rebates = (options.rebates || []).filter((_, idx) => idx !== i);
    onOptionsChange({ rebates });
  };

  return (
    <section className="tc-step">
      <h2 className="tc-step-title">Pricing and options</h2>
      <p className="tc-step-help">
        Sales rates from eligible Zoho Books items seed the subtotal. Override subtotal and total as needed for
        labor, permits, discounts, and package pricing.
      </p>

      <div className="tc-field-grid">
        <label className="tc-field">
          <span className="tc-field-label">Equipment subtotal</span>
          <input
            type="number"
            inputMode="decimal"
            className="tc-input"
            min="0"
            step="0.01"
            value={pricing.subtotal || ''}
            onChange={(e) => onPricingChange({ subtotal: parseFloat(e.target.value) || 0 })}
            placeholder="0.00"
          />
          <span className="tc-field-note">Auto-filled from selected CRM Sales Price, editable.</span>
        </label>

        <label className="tc-field">
          <span className="tc-field-label">Total project cost</span>
          <input
            type="number"
            inputMode="decimal"
            className="tc-input"
            min="0"
            step="0.01"
            value={pricing.total || ''}
            onChange={(e) => onPricingChange({ total: parseFloat(e.target.value) || 0 })}
            placeholder="0.00"
          />
          <button
            type="button"
            className="tc-btn tc-btn-ghost tc-btn-sm"
            onClick={() => onPricingChange({ total: subtotalHint })}
          >
            Use subtotal
          </button>
        </label>

        <label className="tc-field">
          <span className="tc-field-label">Deposit (%)</span>
          <input
            type="number"
            inputMode="numeric"
            className="tc-input"
            min="0"
            max="100"
            step="5"
            value={pricing.deposit_percent ?? 35}
            onChange={(e) =>
              onPricingChange({ deposit_percent: parseInt(e.target.value, 10) || 0 })
            }
          />
        </label>
      </div>

      <fieldset className="tc-fieldset">
        <legend>Warranty</legend>
        <div className="tc-field-grid">
          <label className="tc-field">
            <span className="tc-field-label">Parts (years)</span>
            <input
              type="number"
              inputMode="numeric"
              className="tc-input"
              min="0"
              max="25"
              value={options.warranty_parts_years}
              onChange={(e) =>
                onOptionsChange({ warranty_parts_years: parseInt(e.target.value, 10) || 0 })
              }
            />
          </label>
          <label className="tc-field">
            <span className="tc-field-label">Labor (years)</span>
            <input
              type="number"
              inputMode="numeric"
              className="tc-input"
              min="0"
              max="25"
              value={options.warranty_labor_years}
              onChange={(e) =>
                onOptionsChange({ warranty_labor_years: parseInt(e.target.value, 10) || 0 })
              }
            />
          </label>
        </div>
      </fieldset>

      <fieldset className="tc-fieldset">
        <legend>Rebates</legend>
        {(options.rebates || []).map((r, i) => (
          <div key={i} className="tc-rebate-row">
            <input
              type="text"
              className="tc-input"
              placeholder="Rebate name (e.g. ETOWN Instant Rebate)"
              value={r.name}
              onChange={(e) => updateRebate(i, { name: e.target.value })}
            />
            <input
              type="number"
              inputMode="decimal"
              className="tc-input tc-input-sm"
              placeholder="Amount"
              value={r.amount || ''}
              onChange={(e) => updateRebate(i, { amount: parseFloat(e.target.value) || 0 })}
            />
            <button
              type="button"
              className="tc-btn tc-btn-ghost tc-btn-sm"
              onClick={() => removeRebate(i)}
              aria-label="Remove rebate"
            >
              ×
            </button>
          </div>
        ))}
        <button type="button" className="tc-btn tc-btn-ghost tc-btn-sm" onClick={addRebate}>
          + Add rebate
        </button>
      </fieldset>

      <fieldset className="tc-fieldset">
        <legend>Financing</legend>
        <label className="tc-check">
          <input
            type="checkbox"
            checked={!!options.financing_requested}
            onChange={(e) => onOptionsChange({ financing_requested: e.target.checked })}
          />
          <span>Customer requests financing</span>
        </label>
        {options.financing_requested && (
          <label className="tc-field">
            <span className="tc-field-label">Term (months)</span>
            <select
              className="tc-input"
              value={options.financing_term_months || 60}
              onChange={(e) =>
                onOptionsChange({ financing_term_months: parseInt(e.target.value, 10) })
              }
            >
              {[24, 36, 48, 60, 72, 84, 120].map((n) => (
                <option key={n} value={n}>
                  {n} months
                </option>
              ))}
            </select>
          </label>
        )}
      </fieldset>

      <label className="tc-field">
        <span className="tc-field-label">Special notes (optional)</span>
        <textarea
          className="tc-input tc-textarea"
          rows={3}
          maxLength={2000}
          value={options.special_notes || ''}
          onChange={(e) => onOptionsChange({ special_notes: e.target.value })}
          placeholder="e.g. Line chimney with 4&quot; stainless liner, existing thermostat wiring compatible."
        />
      </label>
    </section>
  );
}
