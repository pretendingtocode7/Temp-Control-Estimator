import React, { useEffect, useRef, useState } from 'react';
import { ApiError } from '../lib/api.js';
import { cls } from '../lib/format.js';

/**
 * Customer search step. Debounced to 300ms so typing doesn't hammer CRM.
 * Selection is recorded on the App draft — selecting a different account replaces the prior one.
 */
export default function CustomerStep({ api, customer, onSelect }) {
  const [query, setQuery] = useState('');
  const [results, setResults] = useState([]);
  const [status, setStatus] = useState('idle'); // idle | loading | error
  const [error, setError] = useState(null);
  const debounceRef = useRef();

  useEffect(() => {
    if (debounceRef.current) clearTimeout(debounceRef.current);
    if (query.trim().length < 2) {
      setResults([]);
      setStatus('idle');
      return;
    }
    debounceRef.current = setTimeout(async () => {
      setStatus('loading');
      setError(null);
      try {
        const data = await api.searchCustomers(query.trim(), 20);
        setResults(Array.isArray(data) ? data : []);
        setStatus('idle');
      } catch (err) {
        setError(err instanceof ApiError ? err.message : 'Search failed.');
        setStatus('error');
      }
    }, 300);
    return () => clearTimeout(debounceRef.current);
  }, [query, api]);

  return (
    <section className="tc-step">
      <h2 className="tc-step-title">Who is this estimate for?</h2>
      <p className="tc-step-help">
        Search by customer name or address. The customer must already exist in Zoho CRM.
      </p>

      <label className="tc-field">
        <span className="tc-field-label">Search customers</span>
        <input
          type="search"
          className="tc-input"
          placeholder="e.g. Balson or Oak Avenue"
          value={query}
          onChange={(e) => setQuery(e.target.value)}
          autoFocus
        />
      </label>

      {status === 'loading' && <p className="tc-muted">Searching…</p>}
      {status === 'error' && <p className="tc-err">{error}</p>}

      {customer && (
        <div className="tc-selected">
          <div>
            <div className="tc-selected-title">Selected</div>
            <div className="tc-selected-name">{customer.name}</div>
            {customer.billing_address?.street && (
              <div className="tc-selected-addr">
                {customer.billing_address.street}, {customer.billing_address.city},{' '}
                {customer.billing_address.state} {customer.billing_address.zip}
              </div>
            )}
          </div>
          <button
            type="button"
            className="tc-btn tc-btn-ghost tc-btn-sm"
            onClick={() => onSelect(null)}
          >
            Change
          </button>
        </div>
      )}

      {results.length > 0 && (
        <ul className="tc-results">
          {results.map((r) => {
            const isSelected = customer?.id === r.id;
            return (
              <li key={r.id}>
                <button
                  type="button"
                  className={cls('tc-result', isSelected && 'is-selected')}
                  onClick={() => onSelect(r)}
                >
                  <span className="tc-result-name">{r.name}</span>
                  {r.billing_address?.street && (
                    <span className="tc-result-addr">
                      {r.billing_address.street}, {r.billing_address.city}
                    </span>
                  )}
                </button>
              </li>
            );
          })}
        </ul>
      )}

      {query.trim().length >= 2 && status === 'idle' && results.length === 0 && !customer && (
        <p className="tc-muted">No matches. Create the account in Zoho CRM first, then retry.</p>
      )}
    </section>
  );
}
