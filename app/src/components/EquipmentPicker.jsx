import React, { useEffect, useRef, useState } from 'react';
import { localFilter, formatMoney } from '../lib/format.js';

/**
 * Bottom-sheet style equipment picker. Loads the catalog for the requested equipment
 * type; when offline, the service worker returns whatever was cached in the last fetch.
 *
 * Search is done client-side against the loaded list — users are searching inside a
 * slot's inventory (10–50 items typically), not the whole catalog, so a local filter
 * is both faster and works offline.
 */
export default function EquipmentPicker({ api, type, label, onClose, onPick }) {
  const [items, setItems] = useState([]);
  const [query, setQuery] = useState('');
  const [status, setStatus] = useState('loading');
  const [error, setError] = useState(null);
  const drawerRef = useRef();

  useEffect(() => {
    let cancelled = false;
    (async () => {
      setStatus('loading');
      setError(null);
      try {
        const data = await api.searchEquipment({ type, limit: 100 });
        if (cancelled) return;
        setItems(Array.isArray(data) ? data : []);
        setStatus('idle');
      } catch (err) {
        if (cancelled) return;
        setError(err.message || 'Could not load equipment.');
        setStatus('error');
      }
    })();
    return () => {
      cancelled = true;
    };
  }, [api, type]);

  // Close on Escape, trap outside click via backdrop click handler.
  useEffect(() => {
    const onKey = (e) => {
      if (e.key === 'Escape') onClose();
    };
    document.addEventListener('keydown', onKey);
    return () => document.removeEventListener('keydown', onKey);
  }, [onClose]);

  const filtered = localFilter(items, query);

  return (
    <div className="tc-drawer-backdrop" onClick={onClose}>
      <div
        className="tc-drawer"
        ref={drawerRef}
        role="dialog"
        aria-modal="true"
        aria-label={`Select ${label}`}
        onClick={(e) => e.stopPropagation()}
      >
        <header className="tc-drawer-head">
          <h3>Select {label}</h3>
          <button
            type="button"
            className="tc-btn tc-btn-ghost tc-btn-sm"
            onClick={onClose}
            aria-label="Close"
          >
            Close
          </button>
        </header>

        <input
          type="search"
          className="tc-input"
          placeholder="Filter by brand, model, JS part, or MFG part"
          value={query}
          onChange={(e) => setQuery(e.target.value)}
          autoFocus
        />

        {status === 'loading' && <p className="tc-muted">Loading catalog…</p>}
        {status === 'error' && <p className="tc-err">{error}</p>}

        <ul className="tc-eqlist">
          {filtered.map((it) => (
            <li key={it.item_id}>
              <button type="button" className="tc-eqcard" onClick={() => onPick(it)}>
                <div className="tc-eqcard-head">
                  <span className="tc-eqcard-brand">{it.brand || it.equipment_type || 'Books item'}</span>
                  <span className="tc-eqcard-price">{formatMoney(it.rate || 0)}</span>
                </div>
                <div className="tc-eqcard-model">{it.name || it.model || it.sku || 'Unnamed part'}</div>
                {it.short_description && (
                  <div className="tc-eqcard-desc">{it.short_description}</div>
                )}
                <div className="tc-eqcard-meta">
                  {it.equipment_type ? <span>{it.equipment_type}</span> : <span>untyped</span>}
                  {it.js_part_number ? <span>JS {it.js_part_number}</span> : null}
                  {it.mfg_part_number ? <span>MFG {it.mfg_part_number}</span> : null}
                  {it.seer ? <span>{it.seer} SEER</span> : null}
                  {it.afue ? <span>{it.afue}% AFUE</span> : null}
                  {it.tons ? <span>{it.tons} ton</span> : null}
                  {it.stages ? <span>{it.stages} stage</span> : null}
                </div>
              </button>
            </li>
          ))}
          {status === 'idle' && filtered.length === 0 && (
            <li className="tc-muted">No eligible Books items match. Confirm cf_for_estimate is checked in Zoho Books.</li>
          )}
        </ul>
      </div>
    </div>
  );
}
