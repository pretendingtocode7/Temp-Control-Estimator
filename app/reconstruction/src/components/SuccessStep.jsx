/** Reconstructed from the saved v0.4.8 deployment bundle; not recovered original source. */
import * as React from "react";
import { createProposalAttachment } from "./../lib/pdf.js";
import { formatMoney } from "./../lib/utils.js";
import { cx } from "./../lib/utils.js";
import { ProposalEditor } from "./Editors.jsx";
import { BooksEditor } from "./Editors.jsx";
import { safeCustomerChoicesLink } from "../lib/customer-choices-api.js";
export const POLL_INTERVAL_MS = 2000;
export const MAX_SEND_POLLS = 60;
export function delay(milliseconds) {
  return new Promise((resolve) => setTimeout(resolve, milliseconds));
}
function CustomerChoiceLink({ api, estimateId, disabled }) {
  const [state, setState] = React.useState({
    status: "idle",
    url: "",
    message: "",
  });
  const busy = React.useRef(false);
  async function createLink() {
    if (busy.current || disabled) return;
    busy.current = true;
    setState({ status: "loading", url: "", message: "" });
    try {
      const response = await api.createCustomerChoiceLink(estimateId);
      if (response?.available !== true) {
        setState({
          status: "unavailable",
          url: "",
          message:
            "A customer selection link is not available for this estimate.",
        });
        return;
      }
      const url = safeCustomerChoicesLink(response.url);
      if (!url) throw new Error();
      setState({
        status: "available",
        url,
        message: "Creating this link does not send an email.",
      });
    } catch {
      setState({
        status: "error",
        url: "",
        message: "The customer selection link could not be created. Try again.",
      });
    } finally {
      busy.current = false;
    }
  }
  async function revokeLink() {
    if (busy.current || disabled) return;
    busy.current = true;
    setState((old) => ({ ...old, status: "revoking", message: "" }));
    try {
      const response = await api.revokeCustomerChoiceLink(estimateId);
      if (response?.revoked !== true) throw new Error();
      setState({
        status: "idle",
        url: "",
        message: "Customer selection link disabled.",
      });
    } catch {
      setState((old) => ({
        ...old,
        status: "available",
        message:
          "We could not confirm that the link was disabled. Try disabling it again.",
      }));
    } finally {
      busy.current = false;
    }
  }
  async function copyLink() {
    try {
      if (!navigator.clipboard?.writeText) throw new Error();
      await navigator.clipboard.writeText(state.url);
      setState((old) => ({
        ...old,
        message: "Customer selection link copied.",
      }));
    } catch {
      setState((old) => ({
        ...old,
        message: "Copy was unavailable. Select and copy the link above.",
      }));
    }
  }
  return (
    <section
      className="tc-fieldset tc-success-editor"
      aria-label="Customer package choices"
    >
      <h3 className="tc-step-title">Customer package choices</h3>
      <p className="tc-field-help">
        The customer can choose one option per physical system and update this
        existing Books estimate. Choosing an option is not approval or payment.
      </p>
      {state.url ? (
        <>
          <label className="tc-field">
            <span className="tc-field-label">Customer selection link</span>
            <input
              type="text"
              className="tc-input"
              aria-label="Customer selection link"
              readOnly
              value={state.url}
              onFocus={(event) => event.target.select()}
            />
          </label>
          <button
            type="button"
            className="tc-btn tc-btn-secondary"
            disabled={disabled || state.status === "revoking"}
            onClick={copyLink}
          >
            Copy customer selection link
          </button>
          {state.status !== "revoking" && (
            <a
              className="tc-btn tc-btn-ghost"
              href={state.url}
              target="_blank"
              rel="noopener noreferrer"
              referrerPolicy="no-referrer"
            >
              Open package choices
            </a>
          )}
          <button
            type="button"
            className="tc-btn tc-btn-ghost tc-btn-danger"
            disabled={disabled || state.status === "revoking"}
            onClick={revokeLink}
          >
            {state.status === "revoking"
              ? "Disabling customer selection link…"
              : "Disable customer selection link"}
          </button>
        </>
      ) : (
        <button
          type="button"
          className="tc-btn tc-btn-secondary"
          disabled={disabled || state.status === "loading"}
          onClick={createLink}
        >
          {state.status === "loading"
            ? "Creating customer selection link…"
            : "Create customer selection link"}
        </button>
      )}
      {disabled && (
        <p className="tc-field-help">
          Save any Books changes and wait for the current action to finish
          before changing this link.
        </p>
      )}
      {state.message && <p role="status">{state.message}</p>}
      <p className="tc-field-help">
        The explicit Send action includes a customer selection link in the
        email. Nothing is emailed when you create or disable a link here.
      </p>
    </section>
  );
}
export function SuccessStep({
  api,
  result,
  proposalHtml,
  booksEdits,
  customerName,
  hasChoices = false,
  onProposalChange,
  onBooksChange,
  onStartOver,
}) {
  const [activeTab, setActiveTab] = React.useState("polished");
  const [booksDirty, setBooksDirty] = React.useState(false);
  const [saveState, setSaveState] = React.useState({
    status: "saved",
    message: "The current Books fields were saved when the draft was created.",
  });
  const [sendState, setSendState] = React.useState({
    status: result?.books_email_sent ? "sent" : "idle",
    message: result?.books_email_sent
      ? "Estimate emailed to the customer."
      : "",
  });

  const handleBooksChange = (nextBooksEdits) => {
    onBooksChange?.(nextBooksEdits);
    setBooksDirty(true);
    setSaveState({ status: "dirty", message: "Unsaved Books changes." });
  };
  const saveBooksChanges = async () => {
    if (!booksEdits)
      throw new Error("The editable Books fields are unavailable.");
    setSaveState({ status: "saving", message: "Saving changes to Books…" });
    try {
      const response = await api.updateEstimate(result.estimate_id, booksEdits);
      setBooksDirty(false);
      setSaveState({
        status: "saved",
        message: response?.message || "Books draft updated.",
      });
      return response;
    } catch (error) {
      setSaveState({
        status: "error",
        message: error?.message || "The Books draft could not be updated.",
      });
      throw error;
    }
  };

  if (!result)
    return (
      <section className="tc-step tc-success">
        <h2 className="tc-step-title">{"Done"}</h2>
        <p className="tc-muted">{"No result to show. Start a new estimate."}</p>
        <button
          type="button"
          className="tc-btn tc-btn-primary"
          onClick={onStartOver}
        >
          {"New estimate"}
        </button>
      </section>
    );

  const sendProposal = async () => {
    let jobId = "";
    let jobFailed = false;
    setSendState({ status: "preparing", message: "" });
    try {
      if (booksDirty) await saveBooksChanges();
      if (!proposalHtml)
        throw new Error(
          "The polished proposal preview is unavailable. Start over and reload the preview before sending.",
        );
      const attachment = await createProposalAttachment({
        html: proposalHtml,
        customerName,
      });
      setSendState({
        status: "sending",
        message: "Uploading the proposal and starting the email…",
      });
      let job = await api.sendEstimate(result.estimate_id, attachment);
      jobId = job?.job_id || "";
      if (!job?.sent) {
        if (!job?.job_id)
          throw new Error("WordPress did not return an email job ID.");
        setSendState({
          status: "sending",
          message:
            job?.message ||
            "The polished email is queued. Waiting for the website to finish…",
        });
        for (let attempt = 0; attempt < MAX_SEND_POLLS; attempt += 1) {
          await delay(POLL_INTERVAL_MS);
          job = await api.sendStatus(job.job_id);
          if (job?.status === "sent" || job?.sent) break;
          if (job?.status === "error") {
            jobFailed = true;
            throw new Error(
              job?.message || "The website could not send the polished email.",
            );
          }
          setSendState({
            status: "sending",
            message:
              job?.message || "Waiting for the website to finish sending…",
          });
        }
      }
      if (!job?.sent && job?.status !== "sent") {
        setSendState({
          status: "pending",
          message:
            "The email is still processing in the background. Do not press Send again; check the customer email status in a minute.",
        });
        return;
      }
      setSendState({
        status: "sent",
        message: job?.message || "Estimate emailed to the customer.",
      });
    } catch (error) {
      // A known job can still send after the browser loses its status connection.
      // Preserve the reference's no-resend state unless the job explicitly failed.
      if (jobId && !jobFailed) {
        setSendState({
          status: "pending",
          message:
            "The email job is still running, but this page lost its status connection. Do not send it again; check the customer email status in a minute.",
        });
        return;
      }
      setSendState({
        status: "error",
        message: error?.message || "The estimate email could not be sent.",
      });
    }
  };
  return (
    <section className="tc-step tc-success">
      <div className="tc-success-badge" aria-hidden="true">
        {"\u2713"}
      </div>
      <h2 className="tc-step-title">{"Draft estimate created"}</h2>
      <p className="tc-step-help">
        {formatMoney(result.total || 0)}
        {
          " total. The Books estimate and CRM Deal are live, but the customer has not been emailed."
        }
      </p>
      <p className="tc-draft-status">
        {"Books status: "}
        <strong>{result.estimate_status || "draft"}</strong>
        {result.estimate_number ? ` \xB7 ${result.estimate_number}` : ""}
      </p>
      <div className="tc-review-before-send">
        <strong>{"Edit and preview before sending"}</strong>
        <p>
          {
            "You can still edit the polished attachment or the Books descriptions, notes, and terms here. Save Books changes before opening its preview."
          }
        </p>
      </div>
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
      <div className="tc-preview tc-success-editor">
        <div className="tc-preview-head">
          {activeTab === "polished"
            ? "Polished proposal \u2014 click the document to edit"
            : "Zoho Books estimate fields \u2014 editable"}
        </div>
        {activeTab === "polished" ? (
          <ProposalEditor
            html={proposalHtml}
            onChange={onProposalChange}
            disabled={["preparing", "sending", "pending"].includes(
              sendState.status,
            )}
          />
        ) : (
          <BooksEditor
            value={booksEdits}
            onChange={handleBooksChange}
            disabled={
              saveState.status === "saving" ||
              ["preparing", "sending", "pending"].includes(sendState.status)
            }
          />
        )}
      </div>
      {activeTab === "books" && (
        <React.Fragment>
          <button
            type="button"
            className="tc-btn tc-btn-secondary tc-btn-block"
            onClick={saveBooksChanges}
            disabled={
              !booksDirty ||
              saveState.status === "saving" ||
              ["preparing", "sending", "pending"].includes(sendState.status)
            }
          >
            {saveState.status === "saving"
              ? "Saving Books changes\u2026"
              : booksDirty
                ? "Save changes to Books"
                : "Books changes saved"}
          </button>
          <p
            className={cx(
              "tc-books-save-message",
              saveState.status === "error" && "tc-err",
            )}
          >
            {saveState.message}
          </p>
        </React.Fragment>
      )}
      <ul className="tc-links">
        {result.estimate_url && (
          <li>
            <a
              className="tc-btn tc-btn-secondary tc-btn-block"
              href={result.estimate_url}
              target="_blank"
              rel="noopener noreferrer"
            >
              {"Preview Books Estimate \u2192"}
            </a>
            <div className="tc-links-meta">
              {"Estimate ID "}
              {result.estimate_id}
            </div>
          </li>
        )}
        {result.deal_url && (
          <li>
            <a
              className="tc-btn tc-btn-secondary tc-btn-block"
              href={result.deal_url}
              target="_blank"
              rel="noopener noreferrer"
            >
              {"Open CRM Deal \u2192"}
            </a>
            <div className="tc-links-meta">
              {"Deal ID "}
              {result.deal_id}
            </div>
          </li>
        )}
      </ul>
      {hasChoices && (
        <CustomerChoiceLink
          key={result.estimate_id}
          api={api}
          estimateId={result.estimate_id}
          disabled={
            booksDirty ||
            saveState.status === "saving" ||
            ["preparing", "sending", "pending"].includes(sendState.status)
          }
        />
      )}
      <p className="tc-send-explainer">
        {
          "Sending saves any remaining Books edits, attaches the polished proposal PDF, and sends the branded Temp Control email from the website. Nothing is emailed until you press the button below."
        }
      </p>
      {sendState.status === "sent" ? (
        <p className="tc-send-confirmation">{sendState.message}</p>
      ) : sendState.status === "pending" ? (
        <p className="tc-send-confirmation">{sendState.message}</p>
      ) : (
        <button
          type="button"
          className="tc-btn tc-btn-primary tc-btn-block"
          onClick={sendProposal}
          disabled={["preparing", "sending", "pending"].includes(
            sendState.status,
          )}
        >
          {sendState.status === "preparing"
            ? "Preparing polished proposal\u2026"
            : sendState.status === "sending"
              ? "Sending in background\u2026"
              : booksDirty
                ? "Save changes & send polished proposal"
                : "Send polished proposal to customer"}
        </button>
      )}
      {sendState.status === "sending" && sendState.message && (
        <p className="tc-muted tc-muted-sm">{sendState.message}</p>
      )}
      {sendState.status === "error" && (
        <p className="tc-err tc-err-block">{sendState.message}</p>
      )}
      {result.replayed && (
        <p className="tc-muted tc-muted-sm">
          {
            "Replayed from a previous submission with the same idempotency key \u2014 no duplicate was created."
          }
        </p>
      )}
      {result.duration_ms !== void 0 && (
        <p className="tc-muted tc-muted-sm">
          {"Generated in "}
          {result.duration_ms}
          {" ms."}
        </p>
      )}
      <button
        type="button"
        className="tc-btn tc-btn-ghost tc-btn-block"
        onClick={onStartOver}
      >
        {"Start another estimate"}
      </button>
    </section>
  );
}
