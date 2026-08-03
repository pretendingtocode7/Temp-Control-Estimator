import React from 'react';
import { cls } from '../lib/format.js';

export default function ProgressBar({ steps, labels, current }) {
  const idx = steps.indexOf(current);
  return (
    <nav className="tc-progress" aria-label="Estimate progress">
      <ol>
        {steps.map((s, i) => {
          const state = i < idx ? 'done' : i === idx ? 'current' : 'pending';
          return (
            <li key={s} className={cls('tc-progress-step', `is-${state}`)}>
              <span className="tc-progress-num" aria-hidden="true">
                {i + 1}
              </span>
              <span className="tc-progress-label">{labels[s]}</span>
            </li>
          );
        })}
      </ol>
    </nav>
  );
}
