// Parses the data-* attributes the PHP wrapper writes onto the React mount node
// into a typed BootContext. This is the JS-side "parse, don't validate"
// boundary — after readBootContext, the rest of the React tree consumes a
// type that guarantees its own shape.
//
// Why data attributes (not window.__INITIAL_STATE__):
//  - CSP-friendly (no inline <script> with dynamic content; nothing to nonce).
//  - Mount-node-scoped — no global pollution between iframes/tabs.
//  - Trivially testable: in tests, set attributes on a fixture div.

export type BootContext = {
  readonly page: string;
  readonly csrf: string;
  readonly userId: number | null;
  readonly patientId: number | null;
  readonly apiBase: string;
};

export class BootContextError extends Error {
  constructor(message: string) {
    super(message);
    this.name = 'BootContextError';
  }
}

export function readBootContext(el: HTMLElement): BootContext {
  const page = el.dataset['page'];
  if (!page) {
    throw new BootContextError('Missing data-page on mount node');
  }
  const csrf = el.dataset['csrf'] ?? '';
  const apiBase = el.dataset['apiBase'] ?? '/apis';

  return {
    page,
    csrf,
    userId: parseOptionalInt(el.dataset['userId']),
    patientId: parseOptionalInt(el.dataset['patientId']),
    apiBase,
  };
}

function parseOptionalInt(raw: string | undefined): number | null {
  if (raw === undefined || raw === '') {
    return null;
  }
  const n = Number.parseInt(raw, 10);
  return Number.isFinite(n) && n > 0 ? n : null;
}
