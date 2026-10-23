/** Reconstructed from the saved v0.4.8 deployment bundle; not recovered original source. */
export function PricingStep({
  pricing,
  options,
  onPricingChange,
  onOptionsChange,
}) {
  const equipmentSubtotal = pricing.subtotal || 0,
    updateRebate = (rebateIndex, changes) => {
      const rebates = (options.rebates || []).map((rebate, currentIndex) =>
        currentIndex === rebateIndex
          ? {
              ...rebate,
              ...changes,
            }
          : rebate,
      );
      onOptionsChange({
        rebates,
      });
    },
    addRebate = () => {
      const rebates = [
        ...(options.rebates || []),
        {
          name: "",
          amount: 0,
        },
      ];
      onOptionsChange({
        rebates,
      });
    },
    removeRebate = (rebateIndex) => {
      const rebates = (options.rebates || []).filter(
        (rebate, currentIndex) => currentIndex !== rebateIndex,
      );
      onOptionsChange({
        rebates,
      });
    };
  // Rebate/note changes do not recalculate the manually entered project total.
  return (
    <section className="tc-step">
      <h2 className="tc-step-title">{"Pricing and options"}</h2>
      <p className="tc-step-help">
        {
          "Sales rates from eligible Zoho Books items seed the subtotal. Override subtotal and total as needed for labor, permits, discounts, and package pricing."
        }
      </p>
      <div className="tc-field-grid">
        <label className="tc-field">
          <span className="tc-field-label">{"Equipment subtotal"}</span>
          <input
            type="number"
            inputMode="decimal"
            className="tc-input"
            min="0"
            step="0.01"
            value={pricing.subtotal || ""}
            onChange={(event) =>
              onPricingChange({
                subtotal: parseFloat(event.target.value) || 0,
              })
            }
            placeholder="0.00"
          />
          <span className="tc-field-note">
            {"Auto-filled from selected CRM Sales Price, editable."}
          </span>
        </label>
        <label className="tc-field">
          <span className="tc-field-label">{"Total project cost"}</span>
          <input
            type="number"
            inputMode="decimal"
            className="tc-input"
            min="0"
            step="0.01"
            value={pricing.total || ""}
            onChange={(event) =>
              onPricingChange({
                total: parseFloat(event.target.value) || 0,
              })
            }
            placeholder="0.00"
          />
          <button
            type="button"
            className="tc-btn tc-btn-ghost tc-btn-sm"
            onClick={() =>
              onPricingChange({
                total: equipmentSubtotal,
              })
            }
          >
            {"Use subtotal"}
          </button>
        </label>
        <label className="tc-field">
          <span className="tc-field-label">{"Deposit (%)"}</span>
          <input
            type="number"
            inputMode="numeric"
            className="tc-input"
            min="0"
            max="100"
            step="5"
            value={pricing.deposit_percent ?? 35}
            onChange={(event) =>
              onPricingChange({
                deposit_percent: parseInt(event.target.value, 10) || 0,
              })
            }
          />
        </label>
      </div>
      <fieldset className="tc-fieldset">
        <legend>{"Proposal notes"}</legend>
        <label className="tc-check">
          <input
            type="checkbox"
            checked={Number(options.warranty_parts_years) === 10}
            onChange={(event) =>
              onOptionsChange({
                warranty_parts_years: event.target.checked ? 10 : 0,
              })
            }
          />
          <span>{"Include 10-year parts warranty"}</span>
        </label>
        <label className="tc-check">
          <input
            type="checkbox"
            checked={Number(options.warranty_labor_years) === 10}
            onChange={(event) =>
              onOptionsChange({
                warranty_labor_years: event.target.checked ? 10 : 0,
              })
            }
          />
          <span>{"Include 10-year labor warranty"}</span>
        </label>
        <label className="tc-check">
          <input
            type="checkbox"
            checked={!!options.credit_card_fee_applies}
            onChange={(event) =>
              onOptionsChange({
                credit_card_fee_applies: event.target.checked,
              })
            }
          />
          <span>{"Note additional credit card fee"}</span>
        </label>
        <label className="tc-check">
          <input
            type="checkbox"
            checked={!!options.financing_fee_applies}
            onChange={(event) =>
              onOptionsChange({
                financing_fee_applies: event.target.checked,
              })
            }
          />
          <span>{"Note additional financing fee"}</span>
        </label>
        <span className="tc-field-help">
          {
            "Checked items appear as separate notes in Books and on the customer proposal."
          }
        </span>
      </fieldset>
      <fieldset className="tc-fieldset">
        <legend>{"Rebates"}</legend>
        {(options.rebates || []).map((rebate, rebateIndex) => (
          <div className="tc-rebate-row" key={rebateIndex}>
            <input
              type="text"
              className="tc-input"
              placeholder="Rebate name (e.g. ETOWN Instant Rebate)"
              value={rebate.name}
              onChange={(event) =>
                updateRebate(rebateIndex, {
                  name: event.target.value,
                })
              }
            />
            <input
              type="number"
              inputMode="decimal"
              className="tc-input tc-input-sm"
              placeholder="Amount"
              value={rebate.amount || ""}
              onChange={(event) =>
                updateRebate(rebateIndex, {
                  amount: parseFloat(event.target.value) || 0,
                })
              }
            />
            <button
              type="button"
              className="tc-btn tc-btn-ghost tc-btn-sm"
              onClick={() => removeRebate(rebateIndex)}
              aria-label="Remove rebate"
            >
              {"\xD7"}
            </button>
          </div>
        ))}
        <button
          type="button"
          className="tc-btn tc-btn-ghost tc-btn-sm"
          onClick={addRebate}
        >
          {"+ Add rebate"}
        </button>
      </fieldset>
      <fieldset className="tc-fieldset">
        <legend>{"Financing"}</legend>
        <label className="tc-check">
          <input
            type="checkbox"
            checked={!!options.financing_requested}
            onChange={(event) =>
              onOptionsChange({
                financing_requested: event.target.checked,
              })
            }
          />
          <span>{"Customer requests financing"}</span>
        </label>
        {options.financing_requested && (
          <label className="tc-field">
            <span className="tc-field-label">{"Term (months)"}</span>
            <select
              className="tc-input"
              value={options.financing_term_months || 60}
              onChange={(event) =>
                onOptionsChange({
                  financing_term_months: parseInt(event.target.value, 10),
                })
              }
            >
              {[24, 36, 48, 60, 72, 84, 120].map((termMonths) => (
                <option value={termMonths} key={termMonths}>
                  {termMonths}
                  {" months"}
                </option>
              ))}
            </select>
          </label>
        )}
      </fieldset>
      <label className="tc-field">
        <span className="tc-field-label">{"Special notes (optional)"}</span>
        <textarea
          className="tc-input tc-textarea"
          rows={3}
          maxLength={2e3}
          value={options.special_notes || ""}
          onChange={(event) =>
            onOptionsChange({
              special_notes: event.target.value,
            })
          }
          placeholder='e.g. Line chimney with 4" stainless liner, existing thermostat wiring compatible.'
        />
      </label>
    </section>
  );
}
