// readBootContext tests — pure DOM parsing, no React tree.

import { describe, expect, it } from 'vitest';
import { BootContextError, readBootContext } from './bootContext';

function makeMountEl(attrs: Record<string, string | null>): HTMLElement {
  const el = document.createElement('div');
  for (const [name, value] of Object.entries(attrs)) {
    if (value !== null) {
      el.setAttribute(name, value);
    }
  }
  return el;
}

describe('readBootContext', () => {
  it('parses a complete data-* attribute set', () => {
    const el = makeMountEl({
      'data-page': 'visit_history',
      'data-csrf': 'tok-abc',
      'data-user-id': '1',
      'data-patient-id': '8',
      'data-api-base': '/apis',
    });

    const ctx = readBootContext(el);

    expect(ctx).toStrictEqual({
      page: 'visit_history',
      csrf: 'tok-abc',
      userId: 1,
      patientId: 8,
      apiBase: '/apis',
    });
  });

  it('returns null for missing or empty patient/user ids', () => {
    const el = makeMountEl({
      'data-page': 'admin',
      'data-csrf': '',
      'data-patient-id': '',
    });
    const ctx = readBootContext(el);
    expect(ctx.userId).toBe(null);
    expect(ctx.patientId).toBe(null);
    expect(ctx.csrf).toBe('');
    expect(ctx.apiBase).toBe('/apis'); // default
  });

  it('rejects non-positive ids (sentinel values from PHP)', () => {
    const el = makeMountEl({
      'data-page': 'admin',
      'data-user-id': '0',
      'data-patient-id': '-1',
    });
    const ctx = readBootContext(el);
    expect(ctx.userId).toBe(null);
    expect(ctx.patientId).toBe(null);
  });

  it('throws BootContextError when data-page is missing', () => {
    const el = makeMountEl({ 'data-csrf': 'x' });
    expect(() => readBootContext(el)).toThrow(BootContextError);
  });
});
