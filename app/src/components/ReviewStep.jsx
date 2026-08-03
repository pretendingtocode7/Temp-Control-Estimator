import React, { useEffect, useState } from 'react';
import { formatMoney, cls } from '../lib/format.js';
import { ApiError } from '../lib/api.js';

/**
 * Review + create. Calls /preview to render the current payload before any Zoho write.
 * Customer email is a separate explicit action after the Books draft is created.
 */
export default function ReviewStep({ api, payload, draft, submitState, onSubmit, onRetryNewKey }) {
  const [preview, setPreview] = useState({ status: 'loading', html: '', error: null });

  useEffect(() => {
    let cancelled = false;
    (async () => {
      setPreview({ status: 'loading', html: '', error: null });
      try {
        const data = await api.preview(payload);
        if (cancelled) return;
        setPreview({ status: 'idle', html: data.html || '', error: null });
      } catch (err) {
        if (cancelled) return;
        setPreview({
          status: 'error',
          html: '',
          error: err instanceof ApiError ? err.message : 'Preview failed.',
        });
      }
    })();
    return () => {
      cancelled = true;
    };
    // payload changes are fine to rerun — it's the whole point of this screen
  }, [api, JSON.stringify(payload)]);

  const isSubmitting = submitState.status === 'submitting';
  const err = submitState.error;
  const isInFlight = err instanceof ApiError && err.code === 'tc_estimate_in_flight';
  const isPriorError = err instanceof ApiError && err.code === 'tc_estimate_prior_error';

  return (
    <section className="tc-step">
      <h2 className="tc-step-title">Review estimate</h2>
      <p className="tc-step-help">
        Confirm the customer-facing proposal below. Creating the draft makes the Zoho Books
        estimate and CRM Deal, but does not email the customer. You will preview the actual
        Books estimate and choose when to send it on the next screen.
      </p>

      <dl className="tc-summary">
        <div>
          <dt>Customer</dt>
          <dd>{draft.customer?.name || '—'}</dd>
        </div>
        <div>
          <dt>Template</dt>
          <dd>{draft.template?.name || '—'}</dd>
        </div>
        <div>
          <dt>Systems</dt>
          <dd>{draft.systems.length}</dd>
        </div>
        <div>
          <dt>Total</dt>
          <dd>{formatMoney(draft.pricing.total || 0)}</dd>
        </div>
      </dl>

      {submitState.status === 'error' && (
        <div className="tc-err tc-err-block">
          <strong>Generation failed.</strong>
          <div>{err?.message || 'Unknown error.'}</div>
          <div className="tc-err-actions">
            {isPriorError ? (
              <button type="button" className="tc-btn tc-btn-secondary tc-btn-sm" onClick={onRetryNewKey}>
                Try again with a new key
              </button>
            ) : isInFlight ? (
              <button type="button" className="tc-btn tc-btn-secondary tc-btn-sm" onClick={onSubmit}>
                Wait and replay
              </button>
            ) : (
              <button type="button" className="tc-btn tc-btn-secondary tc-btn-sm" onClick={onSubmit}>
                Retry
              </button>
            )}
          </div>
        </div>
      )}

      <div className={cls('tc-preview', isSubmitting && 'is-submitting')}>
        <div className="tc-preview-head">Estimate preview — not yet sent</div>
        {preview.status === 'loading' && <p className="tc-muted">Rendering…</p>}
        {preview.status === 'error' && <p className="tc-err">{preview.error}</p>}
        {preview.status === 'idle' && (
          <div
            className="tc-preview-body"
            // Template body is generated server-side by Mustache.php which HTML-escapes
            // variables; see Token_Renderer::get_engine() entity_flags.
            dangerouslySetInnerHTML={{ __html: preview.html }}
          />
        )}
      </div>

      <p className="tc-muted tc-muted-sm">
        Idempotency key: <code>{draft.idempotency_key || '(unassigned)'}</code>
      </p>
    </section>
  );
}
