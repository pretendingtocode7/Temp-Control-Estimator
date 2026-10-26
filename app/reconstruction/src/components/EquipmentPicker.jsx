/** Reconstructed frontend, with the subsequently authorized unified catalog selector. */
import * as React from "react";
import { cx, formatMoney } from "../lib/utils.js";

export const EQUIPMENT_CATEGORIES = [
  { key: "package", label: "Package" },
  { key: "ac", label: "AC" },
  { key: "furnace", label: "Furnace" },
  { key: "coil", label: "Coil" },
];
const PAGE_SIZE = 50;
const SEARCH_DEBOUNCE_MS = 200;
const emptyFacets = () => ({ btus: [], tons: [], efficiencies: [] });

function ratingOptions(values, selected) {
  const ratings = new Set(
    (Array.isArray(values) ? values : [])
      .map(Number)
      .filter((value) => Number.isFinite(value) && value > 0),
  );
  // Retain a previously chosen rating if another filter narrows the facets.
  // Ratings are never inferred from item names or component capacities.
  if (selected && Number.isFinite(Number(selected)) && Number(selected) > 0)
    ratings.add(Number(selected));
  return [...ratings].sort((a, b) => a - b);
}

export function EquipmentPicker({
  api,
  initialCategory = "package",
  onClose,
  onPick,
}) {
  const [filters, setFilters] = React.useState({
    category: EQUIPMENT_CATEGORIES.some(({ key }) => key === initialCategory)
      ? initialCategory
      : "package",
    q: "",
    btu: "",
    tons: "",
    efficiency: "",
    page: 1,
  });
  const [result, setResult] = React.useState(null);
  const [facets, setFacets] = React.useState(emptyFacets);
  const [status, setStatus] = React.useState("loading");
  const [error, setError] = React.useState(null);
  const [retry, setRetry] = React.useState(0);
  const [selection, setSelection] = React.useState({
    status: "idle",
    item: null,
    error: null,
  });
  const drawerRef = React.useRef(null);
  const helpId = React.useId();
  const previousFocus = React.useRef(document.activeElement);
  const requestGeneration = React.useRef(0);
  const currentFilters = React.useRef(filters);
  const selectionAttempt = React.useRef(0);
  const selectingItem = React.useRef(false);

  function invalidateResults() {
    // Invalidate before React renders the new filters: even an old retained
    // result click handler cannot select an item from the previous request.
    requestGeneration.current += 1;
    selectionAttempt.current += 1;
    selectingItem.current = false;
    setSelection({ status: "idle", item: null, error: null });
    setResult(null);
    setStatus("loading");
    setError(null);
  }

  function changeFilters(changes, resetFacets = false) {
    invalidateResults();
    const next = { ...currentFilters.current, ...changes };
    currentFilters.current = next;
    setFilters(next);
    if (resetFacets) setFacets(emptyFacets());
  }

  React.useEffect(() => {
    const generation = ++requestGeneration.current;
    let cancelled = false;
    selectionAttempt.current += 1;
    selectingItem.current = false;
    setSelection({ status: "idle", item: null, error: null });
    setResult(null);
    setStatus("loading");
    setError(null);
    const timer = setTimeout(async () => {
      if (cancelled || generation !== requestGeneration.current) return;
      try {
        const response = await api.searchEquipmentSelector({
          ...filters,
          limit: PAGE_SIZE,
        });
        if (cancelled || generation !== requestGeneration.current) return;
        const items = Array.isArray(response?.items) ? response.items : [];
        setResult({
          items,
          total: Number.isFinite(response?.total)
            ? response.total
            : items.length,
          page: Number.isFinite(response?.page) ? response.page : filters.page,
          has_more: response?.has_more === true,
          generation,
        });
        setFacets({
          btus: Array.isArray(response?.facets?.btus)
            ? response.facets.btus
            : [],
          tons: Array.isArray(response?.facets?.tons)
            ? response.facets.tons
            : [],
          efficiencies: Array.isArray(response?.facets?.efficiencies)
            ? response.facets.efficiencies
            : [],
        });
        setStatus("idle");
      } catch (requestError) {
        if (cancelled || generation !== requestGeneration.current) return;
        setError(requestError?.message || "Could not load equipment.");
        setStatus("error");
      }
    }, SEARCH_DEBOUNCE_MS);
    return () => {
      cancelled = true;
      clearTimeout(timer);
      if (requestGeneration.current === generation)
        requestGeneration.current += 1;
    };
  }, [api, filters, retry]);

  React.useEffect(() => {
    const handleKeyDown = (event) => {
      if (event.key === "Escape") onClose();
      if (event.key !== "Tab") return;
      const controls = Array.from(
        drawerRef.current?.querySelectorAll(
          'button:not(:disabled), input:not(:disabled), select:not(:disabled), [tabindex="0"]',
        ) || [],
      );
      if (!controls.length) return;
      const first = controls[0];
      const last = controls[controls.length - 1];
      if (event.shiftKey && document.activeElement === first) {
        event.preventDefault();
        last.focus();
      } else if (!event.shiftKey && document.activeElement === last) {
        event.preventDefault();
        first.focus();
      }
    };
    document.addEventListener("keydown", handleKeyDown);
    return () => document.removeEventListener("keydown", handleKeyDown);
  }, [onClose]);

  React.useEffect(
    () => () => {
      if (previousFocus.current?.isConnected) previousFocus.current.focus();
    },
    [],
  );

  const ready =
    status === "idle" && result?.generation === requestGeneration.current;
  const items = ready ? result.items : [];
  const btus = ratingOptions(facets.btus, filters.btu);
  const tons = ratingOptions(facets.tons, filters.tons);
  const efficiencies = ratingOptions(
    facets.efficiencies,
    filters.efficiency,
  ).filter((rating) => rating <= 100);

  async function pick(item) {
    if (
      !ready ||
      selectingItem.current ||
      result.generation !== requestGeneration.current ||
      item.selection_category !== currentFilters.current.category ||
      !result.items.some((candidate) => candidate.item_id === item.item_id)
    )
      return;
    const generation = requestGeneration.current;
    const attempt = ++selectionAttempt.current;
    selectingItem.current = true;
    setSelection({ status: "loading", item, error: null });
    try {
      // Composite list rows can omit purchase mapping. Fetch only the chosen
      // item's detail; never load details for every catalog result eagerly.
      const detail = await api.getEquipment(item.item_id);
      if (
        generation !== requestGeneration.current ||
        attempt !== selectionAttempt.current
      )
        return;
      if (
        !detail ||
        detail.item_id !== item.item_id ||
        detail.selection_category !== item.selection_category ||
        detail.selection_category !== currentFilters.current.category ||
        !Number.isFinite(detail.rate) ||
        detail.rate !== item.rate
      )
        throw new Error(
          "The item details no longer match these results. Refresh the catalog and try again.",
        );
      selectingItem.current = false;
      setSelection({ status: "idle", item: null, error: null });
      onPick({ ...item, ...detail });
    } catch (requestError) {
      if (
        generation !== requestGeneration.current ||
        attempt !== selectionAttempt.current
      )
        return;
      selectingItem.current = false;
      setSelection({
        status: "error",
        item,
        error: requestError?.message || "Could not load the selected item.",
      });
    }
  }

  return (
    <div className="tc-drawer-backdrop" onClick={onClose}>
      <div
        className="tc-drawer tc-equipment-picker"
        ref={drawerRef}
        role="dialog"
        aria-modal="true"
        aria-label="Select equipment"
        onClick={(event) => event.stopPropagation()}
      >
        <header className="tc-drawer-head">
          <h3>Select equipment</h3>
          <button
            type="button"
            className="tc-btn tc-btn-ghost tc-btn-sm"
            onClick={onClose}
            aria-label="Close"
          >
            Close
          </button>
        </header>
        <div className="tc-selector-controls">
          <fieldset className="tc-selector-category">
            <legend className="tc-field-label">Equipment category</legend>
            <div className="tc-selector-categories">
              {EQUIPMENT_CATEGORIES.map(({ key, label }) => (
                <button
                  type="button"
                  key={key}
                  className={cx(
                    "tc-btn",
                    filters.category === key
                      ? "tc-btn-primary"
                      : "tc-btn-ghost",
                  )}
                  aria-pressed={filters.category === key}
                  onClick={() => {
                    if (filters.category !== key)
                      changeFilters(
                        {
                          category: key,
                          btu: "",
                          tons: "",
                          efficiency: "",
                          page: 1,
                        },
                        true,
                      );
                  }}
                >
                  {label}
                </button>
              ))}
            </div>
          </fieldset>
          <label className="tc-field">
            <span className="tc-field-label">Search equipment</span>
            <input
              type="search"
              className="tc-input"
              placeholder="Brand, model, name, SKU, JS or MFG part"
              value={filters.q}
              onChange={(event) =>
                changeFilters({ q: event.target.value, page: 1 })
              }
              autoFocus
            />
          </label>
          <div className="tc-selector-filters">
            <label className="tc-field">
              <span className="tc-field-label">BTU</span>
              <select
                className="tc-input"
                aria-label="BTU"
                aria-describedby={
                  ready && btus.length === 0 ? `${helpId}-btu` : undefined
                }
                value={filters.btu}
                onChange={(event) =>
                  changeFilters({ btu: event.target.value, page: 1 })
                }
              >
                <option value="">All BTU ratings</option>
                {btus.map((rating) => (
                  <option key={rating} value={String(rating)}>
                    {rating.toLocaleString("en-US")} BTU
                  </option>
                ))}
              </select>
              {ready && btus.length === 0 && (
                <span className="tc-field-help" id={`${helpId}-btu`}>
                  No BTU ratings available
                </span>
              )}
            </label>
            <label className="tc-field">
              <span className="tc-field-label">Tonnage</span>
              <select
                className="tc-input"
                aria-label="Tonnage"
                aria-describedby={
                  ready && tons.length === 0 ? `${helpId}-tons` : undefined
                }
                value={filters.tons}
                onChange={(event) =>
                  changeFilters({ tons: event.target.value, page: 1 })
                }
              >
                <option value="">All tonnages</option>
                {tons.map((rating) => (
                  <option key={rating} value={String(rating)}>
                    {rating} ton
                  </option>
                ))}
              </select>
              {ready && tons.length === 0 && (
                <span className="tc-field-help" id={`${helpId}-tons`}>
                  No tonnage ratings available
                </span>
              )}
            </label>
            <label className="tc-field tc-efficiency-field">
              <span className="tc-field-label">Efficiency (%)</span>
              <select
                className="tc-input"
                aria-label="Efficiency (%)"
                aria-describedby={
                  ready && efficiencies.length === 0
                    ? `${helpId}-efficiency`
                    : undefined
                }
                value={filters.efficiency}
                onChange={(event) =>
                  changeFilters({ efficiency: event.target.value, page: 1 })
                }
              >
                <option value="">All efficiencies</option>
                {efficiencies.map((rating) => (
                  <option key={rating} value={String(rating)}>
                    {rating}%
                  </option>
                ))}
              </select>
              {ready && efficiencies.length === 0 && (
                <span className="tc-field-help" id={`${helpId}-efficiency`}>
                  No efficiency ratings available
                </span>
              )}
            </label>
          </div>
        </div>
        {status === "loading" && (
          <p className="tc-muted tc-selector-status" role="status">
            Loading catalog…
          </p>
        )}
        {status === "error" && (
          <div className="tc-err-block" role="alert">
            <p>{error}</p>
            <button
              type="button"
              className="tc-btn tc-btn-ghost tc-btn-sm"
              onClick={() => {
                invalidateResults();
                setRetry((value) => value + 1);
              }}
            >
              Try again
            </button>
          </div>
        )}
        {ready && (
          <p className="tc-muted tc-selector-status" role="status">
            {result.total} matching {result.total === 1 ? "item" : "items"}
          </p>
        )}
        {selection.status === "loading" && (
          <p className="tc-muted tc-selector-status" role="status">
            Loading item cost…
          </p>
        )}
        {selection.status === "error" && (
          <div className="tc-err-block" role="alert">
            <p>{selection.error}</p>
            <button
              type="button"
              className="tc-btn tc-btn-ghost tc-btn-sm"
              onClick={() => pick(selection.item)}
            >
              Retry item details
            </button>
          </div>
        )}
        <ul
          className="tc-eqlist"
          aria-label="Equipment results"
          aria-busy={!ready}
        >
          {items.map((item) => (
            <li key={item.item_id}>
              <button
                type="button"
                className="tc-eqcard"
                disabled={
                  selection.status === "loading" ||
                  item.selection_category !== filters.category
                }
                onClick={() => pick(item)}
              >
                <div className="tc-eqcard-head">
                  <span className="tc-eqcard-brand">
                    {item.brand || "Books item"}
                  </span>
                  <span className="tc-eqcard-price">
                    Sales {formatMoney(item.rate || 0)}
                  </span>
                </div>
                <div className="tc-eqcard-model">
                  {item.name || item.model || item.sku || "Unnamed item"}
                </div>
                {item.short_description && (
                  <div className="tc-eqcard-desc">{item.short_description}</div>
                )}
                <div className="tc-eqcard-meta">
                  {item.js_part_number && <span>JS {item.js_part_number}</span>}
                  {item.mfg_part_number && (
                    <span>MFG {item.mfg_part_number}</span>
                  )}
                  {Number(item.btu_input) > 0 && (
                    <span>
                      {Number(item.btu_input).toLocaleString("en-US")} BTU
                    </span>
                  )}
                  {Number(item.tons) > 0 && <span>{item.tons} ton</span>}
                  {item.seer && <span>{item.seer} SEER</span>}
                  {Number(item.efficiency_percent) > 0 &&
                    Number(item.efficiency_percent) <= 100 && (
                      <span>{item.efficiency_percent}% efficiency</span>
                    )}
                  {item.stages && <span>{item.stages} stage</span>}
                </div>
              </button>
            </li>
          ))}
          {ready && items.length === 0 && (
            <li className="tc-muted tc-selector-empty">
              No items match these filters. Try another category or clear a
              filter.
            </li>
          )}
        </ul>
        <nav
          className="tc-selector-pagination"
          aria-label="Equipment result pages"
        >
          <button
            type="button"
            className="tc-btn tc-btn-ghost tc-btn-sm"
            disabled={!ready || filters.page <= 1}
            onClick={() => changeFilters({ page: filters.page - 1 })}
          >
            Previous page
          </button>
          <span className="tc-muted">
            Page {ready ? result.page : filters.page}
          </span>
          <button
            type="button"
            className="tc-btn tc-btn-ghost tc-btn-sm"
            disabled={!ready || !result.has_more}
            onClick={() => changeFilters({ page: filters.page + 1 })}
          >
            Next page
          </button>
        </nav>
      </div>
    </div>
  );
}
