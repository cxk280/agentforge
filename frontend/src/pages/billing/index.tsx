// Billing entry — mounts the Billing component on #cp-root inside the PHP
// wrapper at /interface/billing/copilot_billing.php.
//
// The wrapper joins billing -> patient_data and reconciles each row
// against ar_activity (paid_total) so the Claim status reflects real
// payment state. Payload is JSON-encoded onto data-billing.

import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import { Billing } from './Billing';
import type { BillingPayload } from './Billing';
import { readBootContext } from '../../shared/lib/bootContext';

const mountEl = document.getElementById('cp-root');
if (!mountEl) {
  throw new Error('Billing entry: #cp-root mount node not found');
}

const boot = readBootContext(mountEl);

const EMPTY: BillingPayload = {
  claims: [],
  openClaimsCount: 0,
  paidCount: 0,
  totals: { submitted: '$0.00', paid: '$0.00', outstanding: '$0.00' },
};

function parsePayload(raw: string): BillingPayload {
  if (raw === '') return EMPTY;
  try {
    const parsed = JSON.parse(raw) as unknown;
    if (typeof parsed !== 'object' || parsed === null) return EMPTY;
    const o = parsed as Record<string, unknown>;
    const claims = Array.isArray(o['claims']) ? (o['claims'] as BillingPayload['claims']) : [];
    const openClaimsCount = typeof o['openClaimsCount'] === 'number' ? o['openClaimsCount'] : 0;
    const paidCount = typeof o['paidCount'] === 'number' ? o['paidCount'] : 0;
    const totals = (o['totals'] && typeof o['totals'] === 'object')
      ? (o['totals'] as BillingPayload['totals'])
      : EMPTY.totals;
    return { claims, openClaimsCount, paidCount, totals };
  } catch {
    return EMPTY;
  }
}

const payload = parsePayload(mountEl.dataset['billing'] ?? '');

createRoot(mountEl).render(
  <StrictMode>
    <Billing boot={boot} payload={payload} />
  </StrictMode>,
);
