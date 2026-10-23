/** Reconstructed from the saved v0.4.8 deployment bundle; not recovered original source. */
import * as React from "react";
import { cx, sumEquipment } from "./lib/utils.js";
import { createApi } from "./lib/api.js";
import {
  buildPayload,
  canAdvance,
  createInitialState,
  draftReducer,
  STEPS,
  STEP_LABELS,
} from "./model.js";
import { CustomerStep } from "./components/CustomerStep.jsx";
import { SystemsStep } from "./components/SystemsStep.jsx";
import { PricingStep } from "./components/PricingStep.jsx";
import { ReviewStep } from "./components/ReviewStep.jsx";
import { SuccessStep } from "./components/SuccessStep.jsx";

export function Progress({ steps, labels, current }) {
  const currentIndex = steps.indexOf(current);
  return (
    <nav className="tc-progress" aria-label="Estimate progress">
      <ol>
        {steps.map((step, index) => {
          const status =
            index < currentIndex
              ? "done"
              : index === currentIndex
                ? "current"
                : "pending";
          return (
            <li className={cx("tc-progress-step", `is-${status}`)} key={step}>
              <span className="tc-progress-num" aria-hidden="true">
                {index + 1}
              </span>
              <span className="tc-progress-label">{labels[step]}</span>
            </li>
          );
        })}
      </ol>
    </nav>
  );
}

export function OfflineBanner() {
  return (
    <div className="tc-offline" role="status">
      <span className="tc-offline-dot" aria-hidden="true" />
      {
        "Offline \u2014 you can browse cached equipment, but customer search and submit need a connection."
      }
    </div>
  );
}

export function App({ restUrl, ajaxUrl, nonce, brand }) {
  const api = React.useMemo(
    () => createApi({ restUrl, ajaxUrl, nonce }),
    [restUrl, ajaxUrl, nonce],
  );
  const [draft, dispatch] = React.useReducer(
    draftReducer,
    undefined,
    createInitialState,
  );
  const [submitState, setSubmitState] = React.useState({
    status: "idle",
    error: null,
  });
  const [online, setOnline] = React.useState(navigator.onLine);
  const [templateError, setTemplateError] = React.useState(null);

  React.useEffect(() => {
    const updateOnline = () => setOnline(navigator.onLine);
    window.addEventListener("online", updateOnline);
    window.addEventListener("offline", updateOnline);
    return () => {
      window.removeEventListener("online", updateOnline);
      window.removeEventListener("offline", updateOnline);
    };
  }, []);

  // Preserve the reference: catalog subtotal follows systems; project total is manual.
  React.useEffect(() => {
    const subtotal = sumEquipment(draft.systems);
    if (subtotal !== draft.pricing.subtotal)
      dispatch({ type: "setPricing", pricing: { subtotal } });
  }, [JSON.stringify(draft.systems)]);

  React.useEffect(() => {
    let cancelled = false;
    (async () => {
      try {
        const templates = await api.listTemplates();
        if (cancelled) return;
        const template = Array.isArray(templates)
          ? templates.find(
              (candidate) => candidate.template_type === "full_replacement",
            ) || templates[0]
          : null;
        if (template) dispatch({ type: "setTemplate", template });
        else setTemplateError("The proposal layout is not configured.");
      } catch (error) {
        if (!cancelled)
          setTemplateError(
            error.message || "The proposal layout could not be loaded.",
          );
      }
    })();
    return () => {
      cancelled = true;
    };
  }, [api]);

  React.useEffect(() => {
    api.searchEquipment({ limit: 1 }).catch(() => {});
  }, [api]);
  const canContinue = React.useMemo(() => canAdvance(draft), [draft]);
  const goNext = React.useCallback(() => {
    const currentIndex = STEPS.indexOf(draft.step);
    if (currentIndex < 0 || currentIndex >= STEPS.length - 1) return;
    const step = STEPS[currentIndex + 1];
    dispatch({ type: "setStep", step });
    if (step === "review") dispatch({ type: "allocateIdempotencyKey" });
  }, [draft.step]);
  const goBack = React.useCallback(() => {
    const currentIndex = STEPS.indexOf(draft.step);
    if (currentIndex > 0)
      dispatch({ type: "setStep", step: STEPS[currentIndex - 1] });
  }, [draft.step]);
  const getPayload = React.useCallback(
    (includeBooksEdits = true) => buildPayload(draft, includeBooksEdits),
    [draft],
  );
  const submitDraft = React.useCallback(async () => {
    if (!draft.idempotency_key) {
      dispatch({ type: "allocateIdempotencyKey" });
      return;
    }
    setSubmitState({ status: "submitting", error: null });
    try {
      const result = await api.generate(getPayload(), draft.idempotency_key);
      dispatch({ type: "setResult", result });
      setSubmitState({ status: "success", error: null });
    } catch (error) {
      setSubmitState({ status: "error", error });
    }
  }, [api, getPayload, draft.idempotency_key]);
  const startOver = React.useCallback(() => {
    // Deliberate reference parity: reset also clears template. See known-issues inventory.
    dispatch({ type: "reset" });
    setSubmitState({ status: "idle", error: null });
  }, []);
  return (
    <div className="tc-app" data-step={draft.step}>
      <header className="tc-header">
        <span className="tc-header-title">{"Estimate Builder"}</span>
        <span className="tc-header-sub">{brand?.name || "Temp Control"}</span>
      </header>
      {!online && <OfflineBanner />}
      {draft.step !== "success" && (
        <Progress
          steps={STEPS.filter((x) => x !== "success")}
          labels={STEP_LABELS}
          current={draft.step}
        />
      )}
      <main className="tc-main">
        {templateError && (
          <p className="tc-err tc-err-block">{templateError}</p>
        )}
        {draft.step === "customer" && (
          <CustomerStep
            api={api}
            customer={draft.customer}
            onSelect={(x) =>
              dispatch({
                type: "setCustomer",
                customer: x,
              })
            }
          />
        )}
        {draft.step === "systems" && (
          <SystemsStep
            api={api}
            systems={draft.systems}
            template={draft.template}
            onChange={(x) =>
              dispatch({
                type: "setSystems",
                systems: x,
              })
            }
          />
        )}
        {draft.step === "pricing" && (
          <PricingStep
            pricing={draft.pricing}
            options={draft.options}
            onPricingChange={(x) =>
              dispatch({
                type: "setPricing",
                pricing: x,
              })
            }
            onOptionsChange={(x) =>
              dispatch({
                type: "setOptions",
                options: x,
              })
            }
          />
        )}
        {draft.step === "review" && (
          <ReviewStep
            api={api}
            payload={getPayload(false)}
            draft={draft}
            submitState={submitState}
            onSubmit={submitDraft}
            onPreview={(x) =>
              dispatch({
                type: "setPreviewHtml",
                html: x,
              })
            }
            onBooksChange={(x) =>
              dispatch({
                type: "setBooksEdits",
                booksEdits: x,
              })
            }
            onRetryNewKey={() => {
              (dispatch({
                type: "resetIdempotencyKey",
              }),
                dispatch({
                  type: "allocateIdempotencyKey",
                }),
                setSubmitState({
                  status: "idle",
                  error: null,
                }));
            }}
          />
        )}
        {draft.step === "success" && (
          <SuccessStep
            api={api}
            result={draft.result}
            proposalHtml={draft.preview_html}
            booksEdits={draft.books_edits}
            customerName={draft.customer?.name || ""}
            onProposalChange={(x) =>
              dispatch({
                type: "setPreviewHtml",
                html: x,
              })
            }
            onBooksChange={(x) =>
              dispatch({
                type: "setBooksEdits",
                booksEdits: x,
              })
            }
            onStartOver={startOver}
          />
        )}
      </main>
      {draft.step !== "success" && (
        <footer className="tc-footer">
          <button
            type="button"
            className="tc-btn tc-btn-ghost"
            onClick={goBack}
            disabled={draft.step === "customer"}
          >
            {"Back"}
          </button>
          {draft.step === "review" ? (
            <button
              type="button"
              className="tc-btn tc-btn-primary"
              onClick={submitDraft}
              disabled={
                submitState.status === "submitting" ||
                !draft.preview_html ||
                !draft.books_edits
              }
            >
              {submitState.status === "submitting"
                ? "Creating\u2026"
                : !draft.preview_html || !draft.books_edits
                  ? "Waiting for preview\u2026"
                  : "Create draft estimate"}
            </button>
          ) : (
            <button
              type="button"
              className="tc-btn tc-btn-primary"
              onClick={goNext}
              disabled={!canContinue}
            >
              {"Next"}
            </button>
          )}
        </footer>
      )}
    </div>
  );
}
