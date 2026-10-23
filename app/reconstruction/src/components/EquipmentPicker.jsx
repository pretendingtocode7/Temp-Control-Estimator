/** Reconstructed from the saved v0.4.8 deployment bundle; not recovered original source. */
import * as React from "react";
import { filterEquipment, formatMoney } from "../lib/utils.js";
export function EquipmentPicker({ api, type, label, onClose, onPick }) {
  const [items, setItems] = React.useState([]),
    [query, setQuery] = React.useState(""),
    [status, setStatus] = React.useState("loading"),
    [error, setError] = React.useState(null),
    drawerRef = React.useRef();
  React.useEffect(() => {
    let cancelled = false;
    (async () => {
      setStatus("loading");
      setError(null);
      try {
        const catalogItems = await api.searchEquipment({
          type,
          limit: 100,
        });
        if (cancelled) return;
        setItems(Array.isArray(catalogItems) ? catalogItems : []);
        setStatus("idle");
      } catch (requestError) {
        if (cancelled) return;
        setError(requestError.message || "Could not load equipment.");
        setStatus("error");
      }
    })();
    return () => {
      cancelled = true;
    };
  }, [api, type]);
  React.useEffect(() => {
    const handleKeyDown = (event) => {
      if (event.key === "Escape") onClose();
    };
    document.addEventListener("keydown", handleKeyDown);
    return () => document.removeEventListener("keydown", handleKeyDown);
  }, [onClose]);
  // Filtering stays local to this type's returned catalog, as in the saved bundle.
  const filteredItems = filterEquipment(items, query);
  return (
    <div className="tc-drawer-backdrop" onClick={onClose}>
      <div
        className="tc-drawer"
        ref={drawerRef}
        role="dialog"
        aria-modal="true"
        aria-label={`Select ${label}`}
        onClick={(event) => event.stopPropagation()}
      >
        <header className="tc-drawer-head">
          <h3>
            {"Select "}
            {label}
          </h3>
          <button
            type="button"
            className="tc-btn tc-btn-ghost tc-btn-sm"
            onClick={onClose}
            aria-label="Close"
          >
            {"Close"}
          </button>
        </header>
        <input
          type="search"
          className="tc-input"
          placeholder="Filter by brand, model, JS part, or MFG part"
          value={query}
          onChange={(event) => setQuery(event.target.value)}
          autoFocus={true}
        />
        {status === "loading" && (
          <p className="tc-muted">{"Loading catalog\u2026"}</p>
        )}
        {status === "error" && <p className="tc-err">{error}</p>}
        <ul className="tc-eqlist">
          {filteredItems.map((item) => (
            <li key={item.item_id}>
              <button
                type="button"
                className="tc-eqcard"
                onClick={() => onPick(item)}
              >
                <div className="tc-eqcard-head">
                  <span className="tc-eqcard-brand">
                    {item.brand || item.equipment_type || "Books item"}
                  </span>
                  <span className="tc-eqcard-price">
                    {formatMoney(item.rate || 0)}
                  </span>
                </div>
                <div className="tc-eqcard-model">
                  {item.name || item.model || item.sku || "Unnamed part"}
                </div>
                {item.short_description && (
                  <div className="tc-eqcard-desc">{item.short_description}</div>
                )}
                <div className="tc-eqcard-meta">
                  {item.equipment_type ? (
                    <span>{item.equipment_type}</span>
                  ) : (
                    <span>{"untyped"}</span>
                  )}
                  {item.js_part_number ? (
                    <span>
                      {"JS "}
                      {item.js_part_number}
                    </span>
                  ) : null}
                  {item.mfg_part_number ? (
                    <span>
                      {"MFG "}
                      {item.mfg_part_number}
                    </span>
                  ) : null}
                  {item.seer ? (
                    <span>
                      {item.seer}
                      {" SEER"}
                    </span>
                  ) : null}
                  {item.afue ? (
                    <span>
                      {item.afue}
                      {"% AFUE"}
                    </span>
                  ) : null}
                  {item.tons ? (
                    <span>
                      {item.tons}
                      {" ton"}
                    </span>
                  ) : null}
                  {item.stages ? (
                    <span>
                      {item.stages}
                      {" stage"}
                    </span>
                  ) : null}
                </div>
              </button>
            </li>
          ))}
          {status === "idle" && filteredItems.length === 0 && (
            <li className="tc-muted">
              {
                "No eligible Books items match. Confirm cf_for_estimate is checked in Zoho Books."
              }
            </li>
          )}
        </ul>
      </div>
    </div>
  );
}
