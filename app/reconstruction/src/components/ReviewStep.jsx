/** Reconstructed from the saved v0.4.8 deployment bundle; not recovered original source. */
import * as React from "react";
import { ApiError } from "./../lib/api.js";
import { formatMoney } from "./../lib/utils.js";
import { cx } from "./../lib/utils.js";
import { ProposalEditor } from "./Editors.jsx";
import { BooksEditor } from "./Editors.jsx";
export function ReviewStep({
  api: api,
  payload: payload,
  draft: draft,
  submitState: submitState,
  onSubmit: onSubmit,
  onRetryNewKey: onRetryNewKey,
  onPreview: onPreview,
  onBooksChange: onBooksChange,
}) {
  const [activeTab, setActiveTab] = React.useState("polished");
  const [previewState, setPreviewState] = React.useState({
    status: "loading",
    serverHtml: "",
    error: null,
  });
  React.useEffect(() => {
    let cancelled = false;
    const loadPreview = async () => {
      setPreviewState({ status: "loading", serverHtml: "", error: null });
      try {
        const response = await api.preview(payload);
        if (cancelled) return;
        setPreviewState({
          status: "idle",
          serverHtml: response.html || "",
          error: null,
        });
        // A preview refresh must not overwrite independent technician edits.
        if (!draft.preview_html) onPreview?.(response.html || "");
        if (!draft.books_edits && response.books_preview)
          onBooksChange?.(response.books_preview);
      } catch (error) {
        if (cancelled) return;
        setPreviewState({
          status: "error",
          serverHtml: "",
          error: error instanceof ApiError ? error.message : "Preview failed.",
        });
      }
    };
    loadPreview();
    return () => {
      cancelled = true;
    };
  }, [api, JSON.stringify(payload)]);
  const isSubmitting = submitState.status === "submitting",
    submitError = submitState.error,
    isInFlight =
      submitError instanceof ApiError &&
      submitError.code === "tc_estimate_in_flight",
    isPriorError =
      submitError instanceof ApiError &&
      submitError.code === "tc_estimate_prior_error";
  return (
    <section className="tc-step">
      <h2 className="tc-step-title">{"Review estimate"}</h2>
      <p className="tc-step-help">
        {
          "Edit both versions below before creating the draft. The polished version becomes the customer PDF attachment; the Books version controls the item descriptions, notes, and terms saved in Zoho Books. Nothing is emailed from this screen."
        }
      </p>
      <dl className="tc-summary">
        <div>
          <dt>{"Customer"}</dt>
          <dd>{draft.customer?.name || "\u2014"}</dd>
        </div>
        <div>
          <dt>{"Template"}</dt>
          <dd>{draft.template?.name || "\u2014"}</dd>
        </div>
        <div>
          <dt>{"Systems"}</dt>
          <dd>{draft.systems.length}</dd>
        </div>
        <div>
          <dt>{"Total"}</dt>
          <dd>{formatMoney(draft.pricing.total || 0)}</dd>
        </div>
      </dl>
      {submitState.status === "error" && (
        <div className="tc-err tc-err-block">
          <strong>{"Generation failed."}</strong>
          <div>{submitError?.message || "Unknown error."}</div>
          <div className="tc-err-actions">
            {isPriorError ? (
              <button
                type="button"
                className="tc-btn tc-btn-secondary tc-btn-sm"
                onClick={onRetryNewKey}
              >
                {"Try again with a new key"}
              </button>
            ) : isInFlight ? (
              <button
                type="button"
                className="tc-btn tc-btn-secondary tc-btn-sm"
                onClick={onSubmit}
              >
                {"Wait and replay"}
              </button>
            ) : (
              <button
                type="button"
                className="tc-btn tc-btn-secondary tc-btn-sm"
                onClick={onSubmit}
              >
                {"Retry"}
              </button>
            )}
          </div>
        </div>
      )}
      <div
        className="tc-editor-tabs"
        role="tablist"
        aria-label="Estimate versions"
      >
        <button
          type="button"
          className={cx(
            "tc-editor-tab",
            activeTab === "polished" && "is-active",
          )}
          onClick={() => setActiveTab("polished")}
          role="tab"
          aria-selected={activeTab === "polished"}
        >
          {"Polished version"}
        </button>
        <button
          type="button"
          className={cx("tc-editor-tab", activeTab === "books" && "is-active")}
          onClick={() => setActiveTab("books")}
          role="tab"
          aria-selected={activeTab === "books"}
        >
          {"Books version"}
        </button>
      </div>
      <div className={cx("tc-preview", isSubmitting && "is-submitting")}>
        <div className="tc-preview-head">
          {activeTab === "polished"
            ? "Polished proposal \u2014 click the document to edit"
            : "Zoho Books estimate fields \u2014 editable"}
        </div>
        {previewState.status === "loading" && (
          <p className="tc-muted">{"Rendering\u2026"}</p>
        )}
        {previewState.status === "error" && (
          <p className="tc-err">{previewState.error}</p>
        )}
        {previewState.status === "idle" && activeTab === "polished" && (
          <React.Fragment>
            <ProposalEditor
              html={draft.preview_html}
              onChange={onPreview}
              disabled={isSubmitting}
            />
            <button
              type="button"
              className="tc-btn tc-btn-ghost tc-btn-sm tc-reset-preview"
              onClick={() => onPreview?.(previewState.serverHtml)}
              disabled={isSubmitting}
            >
              {"Reset polished version"}
            </button>
          </React.Fragment>
        )}
        {previewState.status === "idle" && activeTab === "books" && (
          <BooksEditor
            value={draft.books_edits}
            onChange={onBooksChange}
            disabled={isSubmitting}
          />
        )}
      </div>
      <p className="tc-muted tc-muted-sm">
        {"Idempotency key: "}
        <code>{draft.idempotency_key || "(unassigned)"}</code>
      </p>
    </section>
  );
}
