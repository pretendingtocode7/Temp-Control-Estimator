import * as React from "react";
import { formatMoney, uuid } from "../lib/utils.js";
import {
  createCustomerChoicesApi,
  CustomerChoicesError,
  readCustomerChoiceToken,
  safeBooksReviewUrl,
} from "../lib/customer-choices-api.js";

function safeMessage(error) {
  return error instanceof CustomerChoicesError
    ? new CustomerChoicesError(error.code, error.status).message
    : "The estimate is temporarily unavailable. Please try again.";
}

function snapshot(raw) {
  const invalid = () => {
    throw new CustomerChoicesError("unavailable");
  };
  if (
    !Number.isInteger(raw?.revision) ||
    raw.revision < 1 ||
    !["ready", "pending", "saved", "books_updated"].includes(raw.state) ||
    !Array.isArray(raw.systems) ||
    !raw.systems.length ||
    raw.systems.length > 10 ||
    !Number.isFinite(raw.project_adjustment) ||
    !Number.isFinite(raw.total)
  )
    invalid();
  const numbers = new Set();
  const systems = raw.systems.map((system) => {
    if (
      !Number.isInteger(system.system_number) ||
      system.system_number < 1 ||
      numbers.has(system.system_number) ||
      !Array.isArray(system.choices) ||
      !system.choices.length ||
      system.choices.length > 6
    )
      invalid();
    numbers.add(system.system_number);
    const ids = new Set();
    const choices = system.choices.map((choice) => {
      if (
        typeof choice.choice_id !== "string" ||
        !choice.choice_id ||
        choice.choice_id.length > 100 ||
        ids.has(choice.choice_id) ||
        typeof choice.label !== "string" ||
        !Number.isFinite(choice.sales_price) ||
        choice.sales_price < 0
      )
        invalid();
      ids.add(choice.choice_id);
      return {
        choice_id: choice.choice_id,
        label: choice.label.slice(0, 60),
        sales_price: choice.sales_price,
        items: (Array.isArray(choice.items)
          ? choice.items.slice(0, 50)
          : []
        ).map((item) =>
          Object.fromEntries(
            [
              "name",
              "display_name",
              "model",
              "brand",
              "short_description",
              "selection_category",
              "category_2",
            ]
              .filter((key) => typeof item?.[key] === "string")
              .map((key) => [key, item[key].slice(0, 2000)])
              .concat(
                ["afue", "seer", "tons", "btu_input", "btu_output"]
                  .filter(
                    (key) => Number.isFinite(item?.[key]) && item[key] > 0,
                  )
                  .map((key) => [key, item[key]]),
              ),
          ),
        ),
      };
    });
    return {
      system_number: system.system_number,
      system_label:
        typeof system.system_label === "string"
          ? system.system_label.slice(0, 100)
          : `System ${system.system_number}`,
      selected_choice_id: ids.has(system.selected_choice_id)
        ? system.selected_choice_id
        : "",
      choices,
    };
  });
  const addons = (Array.isArray(raw.addons) ? raw.addons.slice(0, 2) : []).map(
    (addon) => {
      if (
        addon.rate !== 0 ||
        addon.quantity !== 1 ||
        !["maintenance", "permits"].includes(addon.addon_key)
      )
        invalid();
      return {
        addon_key: addon.addon_key,
        name:
          typeof addon.name === "string"
            ? addon.name.slice(0, 200)
            : "Included item",
        rate: 0,
        quantity: 1,
        description:
          typeof addon.description === "string"
            ? addon.description.slice(0, 5000)
            : "",
        ...(addon.addon_key === "maintenance" &&
        addon.renewal_rate === 349 &&
        addon.renewal_units === 1
          ? { renewal_rate: 349, renewal_units: 1 }
          : {}),
      };
    },
  );
  return {
    revision: raw.revision,
    state: raw.state,
    systems,
    project_adjustment: raw.project_adjustment,
    total: raw.total,
    deposit_percent: Number.isFinite(raw.deposit_percent)
      ? raw.deposit_percent
      : null,
    addons,
    review_url: safeBooksReviewUrl(raw.review_url),
    crm_sync_pending: raw.crm_sync_pending === true,
    pending: raw.pending || null,
  };
}

function operationFromPending(pending, systems) {
  if (
    !pending ||
    typeof pending.request_id !== "string" ||
    !pending.request_id ||
    pending.request_id.length > 100 ||
    !Number.isInteger(pending.revision) ||
    pending.revision < 1 ||
    !Array.isArray(pending.selections) ||
    pending.selections.length !== systems.length
  )
    return null;
  const selections = systems.map((system) => {
    const matching = pending.selections.filter(
      (selection) => selection.system_number === system.system_number,
    );
    return matching.length === 1 &&
      system.choices.some(
        (choice) => choice.choice_id === matching[0].choice_id,
      )
      ? {
          system_number: system.system_number,
          choice_id: matching[0].choice_id,
        }
      : null;
  });
  return selections.every(Boolean)
    ? { request_id: pending.request_id, revision: pending.revision, selections }
    : null;
}

const selectionMap = (selections) =>
  Object.fromEntries(
    selections.map(({ system_number, choice_id }) => [
      system_number,
      choice_id,
    ]),
  );

export function CustomerChoicesPage({ restUrl, brand, api }) {
  const [token] = React.useState(() => readCustomerChoiceToken());
  const client = React.useMemo(() => {
    try {
      return api || createCustomerChoicesApi({ restUrl });
    } catch {
      return null;
    }
  }, [api, restUrl]);
  const [page, setPage] = React.useState({
    status: "loading",
    view: null,
    error: "",
  });
  const [selections, setSelections] = React.useState({});
  const [phase, setPhase] = React.useState("ready");
  const [message, setMessage] = React.useState("");
  const operation = React.useRef(null);
  const generation = React.useRef(0);
  const busy = React.useRef(false);

  const load = React.useCallback(
    async (context = "initial") => {
      const current = ++generation.current;
      const previousOperation = operation.current;
      setPage((old) => ({ ...old, status: "loading", error: "" }));
      if (!token || !client) {
        setPage({
          status: "error",
          view: null,
          error:
            "This link is unavailable or has expired. Please contact Temp Control for a new link.",
        });
        return;
      }
      try {
        const view = snapshot(await client.view(token));
        if (current !== generation.current) return;
        const pending = operationFromPending(view.pending, view.systems);
        if (
          view.pending ||
          view.state === "pending" ||
          view.state === "books_updated" ||
          view.crm_sync_pending
        ) {
          operation.current = pending || previousOperation;
          setSelections(
            pending
              ? selectionMap(pending.selections)
              : Object.fromEntries(
                  view.systems.map((system) => [
                    system.system_number,
                    system.selected_choice_id,
                  ]),
                ),
          );
          setPhase("pending");
          setMessage(
            "An update is in progress. Check the update before making another change.",
          );
        } else if (context === "status" && previousOperation) {
          // A view alone cannot release an uncertain mutation. Reconcile the same
          // original operation explicitly, even if the latest view looks ready.
          operation.current = previousOperation;
          setSelections(selectionMap(previousOperation.selections));
          setPhase("pending");
          setMessage("Check the original update before making another change.");
        } else {
          operation.current = null;
          setSelections(
            Object.fromEntries(
              view.systems.map((system) => [
                system.system_number,
                system.selected_choice_id,
              ]),
            ),
          );
          setPhase(view.state === "saved" ? "saved" : "ready");
          setMessage("");
        }
        setPage({ status: "ready", view, error: "" });
      } catch (error) {
        if (current !== generation.current) return;
        if (previousOperation && context === "status") {
          setPage((old) => ({
            ...old,
            status: old.view ? "ready" : "error",
            error: "",
          }));
          setPhase("pending");
          setMessage(
            "The update still could not be confirmed. Keep checking the same update before making another change.",
          );
        } else
          setPage({ status: "error", view: null, error: safeMessage(error) });
      }
    },
    [client, token],
  );

  React.useEffect(() => {
    load();
    return () => {
      generation.current += 1;
    };
  }, [load]);

  const view = page.view;
  const complete =
    !!view &&
    view.systems.every((system) =>
      system.choices.some(
        (choice) => choice.choice_id === selections[system.system_number],
      ),
    );
  const selectedTotal = view
    ? Math.round(
        (view.project_adjustment +
          view.systems.reduce(
            (sum, system) =>
              sum +
              (system.choices.find(
                (choice) =>
                  choice.choice_id === selections[system.system_number],
              )?.sales_price || 0),
            0,
          )) *
          100,
      ) / 100
    : 0;
  const locked =
    ["pending", "submitting", "checking", "stale", "unavailable"].includes(
      phase,
    ) || page.status !== "ready";

  async function apply(checking = false) {
    if (
      busy.current ||
      !client ||
      !view ||
      (!checking && (!complete || locked))
    )
      return;
    const request = checking
      ? operation.current
      : {
          revision: view.revision,
          request_id: uuid(),
          selections: view.systems.map((system) => ({
            system_number: system.system_number,
            choice_id: selections[system.system_number],
          })),
        };
    if (!request) return;
    operation.current = request;
    busy.current = true;
    const current = generation.current;
    setPhase(checking ? "checking" : "submitting");
    setMessage(
      checking ? "Checking your update…" : "Updating your existing estimate…",
    );
    try {
      const response = await client.apply({ token, ...request });
      if (current !== generation.current) return;
      if (
        response?.saved === true &&
        ["saved", "books_updated"].includes(response.state)
      ) {
        const confirmed = operationFromPending(
          { ...response, request_id: request.request_id },
          view.systems,
        );
        if (
          !confirmed ||
          confirmed.selections.some(
            (selection) =>
              request.selections.find(
                (expected) =>
                  expected.system_number === selection.system_number,
              )?.choice_id !== selection.choice_id,
          ) ||
          !Number.isFinite(response.total)
        )
          throw new CustomerChoicesError("provider");
        setPage((old) => ({
          ...old,
          view: {
            ...old.view,
            revision: response.revision,
            review_url: safeBooksReviewUrl(response.review_url),
            state: response.state,
            total: response.total,
          },
        }));
        setSelections(selectionMap(confirmed.selections));
        if (
          response.crm_sync_pending === true ||
          response.state === "books_updated"
        ) {
          setPhase("pending");
          setMessage(
            "Your Books estimate was updated. We are finishing the remaining update. Check this update before making another change.",
          );
        } else {
          operation.current = null;
          setPhase("saved");
          setMessage(
            "Your Books estimate has been updated. This does not approve the estimate or make a payment.",
          );
        }
      } else {
        setPhase("pending");
        setMessage(
          "The update is still being checked. Check this same update before making another change.",
        );
      }
    } catch (error) {
      if (current !== generation.current) return;
      if (
        error instanceof CustomerChoicesError &&
        ["stale_revision", "changed"].includes(error.code)
      ) {
        operation.current = null;
        setPhase("stale");
        setMessage(safeMessage(error));
      } else if (
        error instanceof CustomerChoicesError &&
        error.code === "bad_selection"
      ) {
        operation.current = null;
        setPhase("ready");
        setMessage(safeMessage(error));
      } else if (
        error instanceof CustomerChoicesError &&
        error.code === "unavailable" &&
        error.status === 401
      ) {
        operation.current = null;
        setPhase("unavailable");
        setMessage(safeMessage(error));
      } else {
        setPhase("pending");
        setMessage(
          "We could not confirm the result. Check this same update before making another change.",
        );
      }
    } finally {
      busy.current = false;
    }
  }

  return (
    <div className="tc-app tc-customer-choices">
      <header className="tc-header">
        <span className="tc-header-title">
          {typeof brand?.name === "string" ? brand.name : "Temp Control"}
        </span>
      </header>
      <main className="tc-main">
        <h1 className="tc-step-title">Choose your equipment</h1>
        <p className="tc-step-help">
          Choose one option for each system, then update your existing estimate.
          Choosing options is not approval or payment.
        </p>
        {page.status === "loading" && (
          <p role="status">Loading your estimate…</p>
        )}
        {page.status === "error" && (
          <div className="tc-err-block" role="alert">
            <p>{page.error}</p>
            {token && (
              <button
                type="button"
                className="tc-btn tc-btn-secondary"
                onClick={() => load()}
              >
                Retry loading estimate
              </button>
            )}
          </div>
        )}
        {view && (
          <>
            {view.systems.map((system) => (
              <fieldset
                className="tc-fieldset"
                key={system.system_number}
                disabled={locked}
              >
                <legend>{system.system_label}</legend>
                {system.choices.map((choice) => (
                  <label
                    className={`tc-tcard ${selections[system.system_number] === choice.choice_id ? "is-selected" : ""}`}
                    key={choice.choice_id}
                  >
                    <span className="tc-check">
                      <input
                        type="radio"
                        name={`customer-system-${system.system_number}`}
                        aria-label={`${choice.label} for ${system.system_label}`}
                        checked={
                          selections[system.system_number] === choice.choice_id
                        }
                        onChange={() => {
                          if (locked) return;
                          setSelections((current) => ({
                            ...current,
                            [system.system_number]: choice.choice_id,
                          }));
                          setPhase("ready");
                          setMessage("");
                        }}
                      />
                      <strong>{choice.label}</strong>
                    </span>
                    <strong>{formatMoney(choice.sales_price)}</strong>
                    {choice.items.length > 0 && (
                      <ul>
                        {choice.items.map((item, index) => (
                          <li key={index}>
                            <span>
                              {item.display_name ||
                                item.name ||
                                [item.brand, item.model]
                                  .filter(Boolean)
                                  .join(" ") ||
                                "Equipment"}
                            </span>
                            <div className="tc-eqcard-meta">
                              {item.model && <span>Model {item.model}</span>}
                              {item.afue && <span>{item.afue}% AFUE</span>}
                              {item.seer && <span>{item.seer} SEER</span>}
                              {item.tons && <span>{item.tons} ton</span>}
                              {item.btu_input && (
                                <span>
                                  {item.btu_input.toLocaleString("en-US")} BTU
                                </span>
                              )}
                            </div>
                            {item.short_description && (
                              <p>{item.short_description}</p>
                            )}
                          </li>
                        ))}
                      </ul>
                    )}
                  </label>
                ))}
              </fieldset>
            ))}
            <div className="tc-selected">
              <div>
                <div className="tc-field-label">Project total</div>
                <strong>
                  {complete
                    ? formatMoney(
                        phase === "saved" ? view.total : selectedTotal,
                      )
                    : "Choose one option for each system"}
                </strong>
                {view.project_adjustment !== 0 && (
                  <p className="tc-field-help">
                    Includes a shared adjustment of{" "}
                    {formatMoney(view.project_adjustment)}, applied once.
                  </p>
                )}
              </div>
            </div>
            {view.addons.map((addon) => (
              <section className="tc-fieldset" key={addon.addon_key}>
                <h2 className="tc-step-title">{addon.name} — $0.00</h2>
                {addon.description && (
                  <p style={{ whiteSpace: "pre-line" }}>{addon.description}</p>
                )}
                {addon.addon_key === "maintenance" &&
                  addon.renewal_rate === 349 &&
                  !addon.description.includes(
                    "Renews at $349 for one unit.",
                  ) && <p>Renews at $349 for one unit.</p>}
              </section>
            ))}
            {view.addons.length > 0 && (
              <p className="tc-field-help">
                Included optional items are $0.00 on this estimate. A future
                maintenance renewal is not charged today.
              </p>
            )}
            {message && (
              <p
                role={
                  phase === "stale" || phase === "unavailable"
                    ? "alert"
                    : "status"
                }
              >
                {message}
              </p>
            )}
            {["pending", "checking"].includes(phase) ? (
              <div className="tc-err-block">
                {operation.current && (
                  <button
                    type="button"
                    className="tc-btn tc-btn-primary"
                    disabled={busy.current || phase === "checking"}
                    onClick={() => apply(true)}
                  >
                    {phase === "checking" ? "Checking update…" : "Check update"}
                  </button>
                )}
                <button
                  type="button"
                  className="tc-btn tc-btn-ghost"
                  disabled={busy.current}
                  onClick={() => load("status")}
                >
                  Refresh update status
                </button>
              </div>
            ) : phase === "stale" ? (
              <button
                type="button"
                className="tc-btn tc-btn-secondary"
                onClick={() => load()}
              >
                Refresh estimate
              </button>
            ) : (
              phase !== "unavailable" && (
                <button
                  type="button"
                  className="tc-btn tc-btn-primary"
                  disabled={!complete || locked}
                  onClick={() => apply(false)}
                >
                  {phase === "submitting"
                    ? "Updating estimate…"
                    : "Update my estimate"}
                </button>
              )
            )}
            {phase === "saved" && view.review_url && (
              <a
                className="tc-btn tc-btn-secondary"
                href={view.review_url}
                target="_blank"
                rel="noopener noreferrer"
                referrerPolicy="no-referrer"
              >
                Review and approve in Zoho Books
              </a>
            )}
            {phase === "saved" && !view.review_url && (
              <p role="status">
                Your estimate was updated, but the Books review link is
                unavailable. Please contact Temp Control to review and approve
                it.
              </p>
            )}
            <p className="tc-field-help">
              Approval and any payment happen separately in Zoho Books.
            </p>
          </>
        )}
      </main>
    </div>
  );
}
