import React, { useEffect, useState } from 'react';
import { cls } from '../lib/format.js';

const TYPE_LABELS = {
  full_replacement: 'Full Replacement',
  ac_only: 'AC Only',
  furnace_only: 'Furnace Only',
  maintenance: 'Maintenance',
  service_repair: 'Service / Repair',
};

export default function TemplateStep({ api, template, onSelect }) {
  const [items, setItems] = useState([]);
  const [status, setStatus] = useState('loading');
  const [error, setError] = useState(null);

  useEffect(() => {
    let cancelled = false;
    (async () => {
      setStatus('loading');
      try {
        const data = await api.listTemplates();
        if (cancelled) return;
        setItems(Array.isArray(data) ? data : []);
        setStatus('idle');
      } catch (err) {
        if (cancelled) return;
        setError(err.message || 'Could not load templates.');
        setStatus('error');
      }
    })();
    return () => {
      cancelled = true;
    };
  }, [api]);

  const groups = items.reduce((acc, t) => {
    const key = t.template_type || 'other';
    (acc[key] = acc[key] || []).push(t);
    return acc;
  }, {});

  return (
    <section className="tc-step">
      <h2 className="tc-step-title">Pick a template</h2>
      <p className="tc-step-help">
        Templates define the proposal body, required equipment slots, and warranty defaults.
      </p>

      {status === 'loading' && <p className="tc-muted">Loading templates…</p>}
      {status === 'error' && <p className="tc-err">{error}</p>}

      {status === 'idle' && Object.keys(groups).length === 0 && (
        <p className="tc-muted">
          No active templates. Ask an administrator to create one under Estimate Builder → Templates.
        </p>
      )}

      {Object.entries(groups).map(([typeKey, list]) => (
        <div key={typeKey} className="tc-tgroup">
          <h3 className="tc-tgroup-title">{TYPE_LABELS[typeKey] || typeKey}</h3>
          <ul className="tc-tlist">
            {list.map((t) => {
              const isSelected = template?.id === t.id;
              return (
                <li key={t.id}>
                  <button
                    type="button"
                    className={cls('tc-tcard', isSelected && 'is-selected')}
                    onClick={() => onSelect(t)}
                  >
                    <span className="tc-tcard-name">{t.name}</span>
                    <span className="tc-tcard-meta">
                      v{t.version} · Warranty {t.default_warranty_parts || 0}yr parts /{' '}
                      {t.default_warranty_labor || 0}yr labor
                    </span>
                  </button>
                </li>
              );
            })}
          </ul>
        </div>
      ))}
    </section>
  );
}
