/** Reconstructed from the saved v0.4.8 deployment bundle; not recovered original source. */
import * as React from "react";
import { ApiError } from "../lib/api.js";
import { cx } from "../lib/utils.js";
export function CustomerStep({ api, customer, onSelect }) {
  const [query, setQuery] = React.useState(""),
    [results, setResults] = React.useState([]),
    [status, setStatus] = React.useState("idle"),
    [error, setError] = React.useState(null),
    searchTimer = React.useRef();
  React.useEffect(() => {
    if (searchTimer.current) clearTimeout(searchTimer.current);
    if (query.trim().length < 2) {
      setResults([]);
      setStatus("idle");
      return;
    }
    // Match the saved UI's debounce; requests already in flight are not cancelled.
    searchTimer.current = setTimeout(async () => {
      setStatus("loading");
      setError(null);
      try {
        const searchResults = await api.searchCustomers(query.trim(), 20);
        setResults(Array.isArray(searchResults) ? searchResults : []);
        setStatus("idle");
      } catch (requestError) {
        setError(
          requestError instanceof ApiError
            ? requestError.message
            : "Search failed.",
        );
        setStatus("error");
      }
    }, 300);
    return () => clearTimeout(searchTimer.current);
  }, [query, api]);
  return (
    <section className="tc-step">
      <h2 className="tc-step-title">{"Who is this estimate for?"}</h2>
      <p className="tc-step-help">
        {
          "Search by customer name or address. The customer must already exist in Zoho CRM."
        }
      </p>
      <label className="tc-field">
        <span className="tc-field-label">{"Search customers"}</span>
        <input
          type="search"
          className="tc-input"
          placeholder="e.g. Balson or Oak Avenue"
          value={query}
          onChange={(event) => setQuery(event.target.value)}
          autoFocus={true}
        />
      </label>
      {status === "loading" && <p className="tc-muted">{"Searching\u2026"}</p>}
      {status === "error" && <p className="tc-err">{error}</p>}
      {customer && (
        <div className="tc-selected">
          <div>
            <div className="tc-selected-title">{"Selected"}</div>
            <div className="tc-selected-name">{customer.name}</div>
            {customer.billing_address?.street && (
              <div className="tc-selected-addr">
                {customer.billing_address.street}
                {", "}
                {customer.billing_address.city}
                {","} {customer.billing_address.state}{" "}
                {customer.billing_address.zip}
              </div>
            )}
          </div>
          <button
            type="button"
            className="tc-btn tc-btn-ghost tc-btn-sm"
            onClick={() => onSelect(null)}
          >
            {"Change"}
          </button>
        </div>
      )}
      {results.length > 0 && (
        <ul className="tc-results">
          {results.map((result) => {
            const isSelected = customer?.id === result.id;
            return (
              <li key={result.id}>
                <button
                  type="button"
                  className={cx("tc-result", isSelected && "is-selected")}
                  onClick={() => onSelect(result)}
                >
                  <span className="tc-result-name">{result.name}</span>
                  {result.billing_address?.street && (
                    <span className="tc-result-addr">
                      {result.billing_address.street}
                      {", "}
                      {result.billing_address.city}
                    </span>
                  )}
                </button>
              </li>
            );
          })}
        </ul>
      )}
      {query.trim().length >= 2 &&
        status === "idle" &&
        results.length === 0 &&
        !customer && (
          <p className="tc-muted">
            {"No matches. Create the account in Zoho CRM first, then retry."}
          </p>
        )}
    </section>
  );
}
