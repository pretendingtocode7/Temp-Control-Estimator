import React, { useState } from 'react';
import { formatMoney } from '../lib/format.js';

export default function SuccessStep({ api, result, onStartOver }) {
  const [sendState, setSendState] = useState({
    status: result?.books_email_sent ? 'sent' : 'idle',
    message: result?.books_email_sent ? 'Estimate emailed to the customer.' : '',
  });

  if (!result) {
    return (
      <section className="tc-step tc-success">
        <h2 className="tc-step-title">Done</h2>
        <p className="tc-muted">No result to show. Start a new estimate.</p>
        <button type="button" className="tc-btn tc-btn-primary" onClick={onStartOver}>
          New estimate
        </button>
      </section>
    );
  }

  const sendEstimate = async () => {
    setSendState({ status: 'sending', message: '' });
    try {
      const sent = await api.sendEstimate(result.estimate_id);
      setSendState({
        status: 'sent',
        message: sent?.message || 'Estimate emailed to the customer.',
      });
    } catch (err) {
      setSendState({
        status: 'error',
        message: err?.message || 'The estimate email could not be sent.',
      });
    }
  };

  return (
    <section className="tc-step tc-success">
      <div className="tc-success-badge" aria-hidden="true">
        ✓
      </div>
      <h2 className="tc-step-title">Draft estimate created</h2>
      <p className="tc-step-help">
        {formatMoney(result.total || 0)} total. The Books estimate and CRM Deal are live,
        but the customer has not been emailed.
      </p>

      <div className="tc-review-before-send">
        <strong>Preview before sending</strong>
        <p>
          Open the actual Zoho Books estimate and confirm the item descriptions, pricing,
          and customer details. Return here to send it when everything looks right.
        </p>
      </div>

      <ul className="tc-links">
        {result.estimate_url && (
          <li>
            <a
              className="tc-btn tc-btn-secondary tc-btn-block"
              href={result.estimate_url}
              target="_blank"
              rel="noopener noreferrer"
            >
              Preview Books Estimate →
            </a>
            <div className="tc-links-meta">Estimate ID {result.estimate_id}</div>
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
              Open CRM Deal →
            </a>
            <div className="tc-links-meta">Deal ID {result.deal_id}</div>
          </li>
        )}
      </ul>

      {sendState.status === 'sent' ? (
        <p className="tc-send-confirmation">{sendState.message}</p>
      ) : (
        <button
          type="button"
          className="tc-btn tc-btn-primary tc-btn-block"
          onClick={sendEstimate}
          disabled={sendState.status === 'sending'}
        >
          {sendState.status === 'sending' ? 'Sending…' : 'Send estimate to customer'}
        </button>
      )}

      {sendState.status === 'error' && (
        <p className="tc-err tc-err-block">{sendState.message}</p>
      )}

      {result.replayed && (
        <p className="tc-muted tc-muted-sm">
          Replayed from a previous submission with the same idempotency key — no duplicate was created.
        </p>
      )}

      {result.duration_ms !== undefined && (
        <p className="tc-muted tc-muted-sm">Generated in {result.duration_ms} ms.</p>
      )}

      <button type="button" className="tc-btn tc-btn-ghost tc-btn-block" onClick={onStartOver}>
        Start another estimate
      </button>
    </section>
  );
}
