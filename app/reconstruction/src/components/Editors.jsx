/** Reconstructed from the saved v0.4.8 deployment bundle; not recovered original source. */
import * as React from "react";
import { formatMoney } from "./../lib/utils.js";
export function ProposalEditor({
  html: html,
  onChange: onChange,
  disabled = !1,
}) {
  const editorRef = React.useRef(null),
    lastHtmlRef = React.useRef("");
  React.useEffect(() => {
    !editorRef.current ||
      html === lastHtmlRef.current ||
      ((editorRef.current.innerHTML = html || ""),
      (lastHtmlRef.current = html || ""));
  }, [html]);
  const handleInput = (event) => {
      const nextHtml = event.currentTarget.innerHTML;
      ((lastHtmlRef.current = nextHtml), onChange?.(nextHtml));
    },
    handlePaste = (event) => {
      var clipboard;
      event.preventDefault();
      const plainText =
        ((clipboard = event.clipboardData) == null
          ? void 0
          : clipboard.getData("text/plain")) || "";
      document.execCommand("insertText", !1, plainText);
    };
  return (
    <div
      ref={editorRef}
      className="tc-preview-body tc-proposal-editor"
      contentEditable={!disabled}
      suppressContentEditableWarning={!0}
      role="textbox"
      aria-multiline="true"
      aria-label="Edit polished proposal"
      onInput={handleInput}
      onPaste={handlePaste}
    />
  );
}
export function BooksEditor({
  value: value,
  onChange: onChange,
  disabled = !1,
}) {
  if (!value)
    return <p className="tc-muted">{"Preparing the Books version\u2026"}</p>;
  const updateDescription = (lineIndex, description) => {
      const lineItems = (value.line_items || []).map((line, index) =>
        index === lineIndex
          ? {
              ...line,
              description: description,
            }
          : line,
      );
      onChange?.({
        ...value,
        line_items: lineItems,
      });
    },
    updateField = (field, text) => {
      onChange?.({
        ...value,
        [field]: text,
      });
    };
  return (
    <div className="tc-books-editor">
      <p className="tc-step-help">
        {
          "These descriptions, customer notes, and terms are the text saved on the Zoho Books estimate. Prices remain controlled on the Pricing step."
        }
      </p>
      {(value.line_items || []).map((line, lineIndex) => (
        <article
          className="tc-books-line"
          key={line.key || `${line.item_id}-${lineIndex}`}
        >
          <div className="tc-books-line-head">
            <strong>{line.name || `Item ${lineIndex + 1}`}</strong>
            <span>{formatMoney(line.rate || 0)}</span>
          </div>
          <label className="tc-field">
            <span className="tc-label">{"Books item description"}</span>
            <textarea
              className="tc-textarea"
              rows={3}
              maxLength={2e3}
              value={line.description || ""}
              onChange={(event) =>
                updateDescription(lineIndex, event.target.value)
              }
              disabled={disabled}
            />
          </label>
        </article>
      ))}
      <label className="tc-field">
        <span className="tc-label">{"Books customer notes"}</span>
        <textarea
          className="tc-textarea"
          rows={3}
          maxLength={5e3}
          value={value.notes || ""}
          onChange={(event) => updateField("notes", event.target.value)}
          disabled={disabled}
        />
      </label>
      <label className="tc-field">
        <span className="tc-label">{"Books terms and conditions"}</span>
        <textarea
          className="tc-textarea"
          rows={6}
          maxLength={5e3}
          value={value.terms || ""}
          onChange={(event) => updateField("terms", event.target.value)}
          disabled={disabled}
        />
      </label>
    </div>
  );
}
