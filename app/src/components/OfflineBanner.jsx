import React from 'react';

export default function OfflineBanner() {
  return (
    <div className="tc-offline" role="status">
      <span className="tc-offline-dot" aria-hidden="true" />
      Offline — you can browse cached equipment, but customer search and submit need a
      connection.
    </div>
  );
}
