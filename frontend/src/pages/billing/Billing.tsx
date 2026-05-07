// Billing — Figma "Screen 27 — Billing Manager (archetype)".
// Billing-financial archetype page. Renders 4 KPI cards (Open claims,
// Submitted 30d, Paid 30d, Outstanding A/R), a status filter strip with
// search/date-range, and a claims table with status pills and per-row
// actions (View / Submit / Resolve).
//
// 1:1 port of the PHP-rendered page previously at
// /interface/billing/copilot_billing.php. The original was static (no DB);
// the React port keeps the same shape with hardcoded static demo data
// matching the Figma exactly. The navy top nav and header2 banner from
// the outer shell remain out of scope per the migration plan.

import { useState } from 'react';
import type { BootContext } from '../../shared/lib/bootContext';
import styles from './Billing.module.css';

// ── Types ────────────────────────────────────────────────────────────

type FilterKey = 'all' | 'open' | 'submitted' | 'paid' | 'denied' | 'voided';

type StatusKey =
  | 'submitted'
  | 'paid'
  | 'denied'
  | 'pending'
  | 'outstanding';

type ActionKind = 'view' | 'submit' | 'resolve';

type Claim = {
  readonly claimNo: string;
  readonly patient: string;
  readonly dos: string;
  readonly cpt: string;
  readonly insurer: string;
  readonly amount: string;
  readonly status: StatusKey;
  readonly statusLabel: string;
  readonly updated: string;
  readonly action: ActionKind;
};

// ── Demo data — verbatim from Figma node 56:2 (Screen 27) ───────────

type Kpi = {
  readonly label: string;
  readonly value: string;
  readonly sub: string;
  readonly tone: 'navy' | 'blue' | 'green' | 'orange';
};

const KPIS: readonly Kpi[] = [
  { label: 'Open claims',     value: '128',      sub: 'Avg age 18 days',          tone: 'navy'   },
  { label: 'Submitted (30d)', value: '$48,920',  sub: '94 claims to payers',      tone: 'blue'   },
  { label: 'Paid (30d)',      value: '$41,180',  sub: '84% first-pass acceptance',tone: 'green'  },
  { label: 'Outstanding A/R', value: '$132,840', sub: '$28k in 90+ aging',        tone: 'orange' },
];

// 8 rows transcribed from the Figma. Matches the PHP page exactly.
const CLAIMS: readonly Claim[] = [
  { claimNo: 'CLM-9402', patient: 'Margaret Chen',  dos: 'Apr 12', cpt: '99213', insurer: 'BCBS PPO',  amount: '$152.00', status: 'submitted',   statusLabel: 'Submitted',   updated: '2h ago',     action: 'view'    },
  { claimNo: 'CLM-9401', patient: 'Ted Shaw',       dos: 'Apr 12', cpt: '99214', insurer: 'Aetna HMO', amount: '$215.00', status: 'paid',        statusLabel: 'Paid',        updated: '5h ago',     action: 'view'    },
  { claimNo: 'CLM-9399', patient: 'Linda Martinez', dos: 'Apr 11', cpt: '99215', insurer: 'United HC', amount: '$310.00', status: 'denied',      statusLabel: 'Denied',      updated: 'Yesterday',  action: 'resolve' },
  { claimNo: 'CLM-9398', patient: 'David Kim',      dos: 'Apr 11', cpt: '99213', insurer: 'Cigna PPO', amount: '$152.00', status: 'pending',     statusLabel: 'Pending',     updated: 'Yesterday',  action: 'submit'  },
  { claimNo: 'CLM-9397', patient: 'Allison Park',   dos: 'Apr 11', cpt: '99396', insurer: 'Medicare',  amount: '$280.00', status: 'paid',        statusLabel: 'Paid',        updated: 'Yesterday',  action: 'view'    },
  { claimNo: 'CLM-9395', patient: 'Robert Hayes',   dos: 'Apr 10', cpt: '80050', insurer: 'BCBS PPO',  amount: '$184.00', status: 'submitted',   statusLabel: 'Submitted',   updated: '2 days ago', action: 'view'    },
  { claimNo: 'CLM-9394', patient: 'Carol Bennett',  dos: 'Apr 10', cpt: '99213', insurer: 'Self-pay',  amount: '$152.00', status: 'outstanding', statusLabel: 'Outstanding', updated: '2 days ago', action: 'submit'  },
  { claimNo: 'CLM-9392', patient: 'James Wong',     dos: 'Apr 9',  cpt: '99214', insurer: 'United HC', amount: '$215.00', status: 'paid',        statusLabel: 'Paid',        updated: '3 days ago', action: 'view'    },
];

// Filter bucket → predicate. Mirrors the original PHP visual filter.
function matchesFilter(filter: FilterKey, claim: Claim): boolean {
  switch (filter) {
    case 'all':       return true;
    case 'open':      return claim.status === 'pending' || claim.status === 'outstanding';
    case 'submitted': return claim.status === 'submitted';
    case 'paid':      return claim.status === 'paid';
    case 'denied':    return claim.status === 'denied';
    case 'voided':    return false;
  }
}

// ── Component ────────────────────────────────────────────────────────

type BillingProps = {
  readonly boot: BootContext;
};

export function Billing(_props: BillingProps): JSX.Element {
  const [filter, setFilter] = useState<FilterKey>('all');

  const visibleRows = CLAIMS.filter((c) => matchesFilter(filter, c));

  return (
    <>
      <header className={styles.header}>
        <div className={styles.titleBlock}>
          <span className={styles.title}>Billing Manager</span>
          <span className={styles.dot}>•</span>
          <span className={styles.subtitle}>128 open claims • 14 awaiting submission</span>
        </div>
        <div className={styles.spacer} />
        <button className={styles.btnGhost} type="button">
          <span aria-hidden="true">⬇</span>
          <span>Export</span>
        </button>
        <button className={styles.btnPrimary} type="button">
          <span>+ New claim</span>
        </button>
      </header>

      <main className={styles.content}>
        <div className={styles.kpiGrid}>
          {KPIS.map((k) => (
            <KpiCard key={k.label} kpi={k} />
          ))}
        </div>

        <div className={styles.filterBar} role="tablist">
          <FilterPill k="all"       current={filter} onSelect={setFilter}>All</FilterPill>
          <FilterPill k="open"      current={filter} onSelect={setFilter}>Open</FilterPill>
          <FilterPill k="submitted" current={filter} onSelect={setFilter}>Submitted</FilterPill>
          <FilterPill k="paid"      current={filter} onSelect={setFilter}>Paid</FilterPill>
          <FilterPill k="denied"    current={filter} onSelect={setFilter}>Denied</FilterPill>
          <FilterPill k="voided"    current={filter} onSelect={setFilter}>Voided</FilterPill>
          <div className={styles.spacer} />
          <button type="button" className={styles.utilPill}>
            <span aria-hidden="true">📅</span>
            <span>Last 30 days</span>
          </button>
          <button type="button" className={styles.utilPill}>
            <span aria-hidden="true">🔍</span>
            <span className={styles.utilMuted}>Search claims</span>
          </button>
          <button type="button" className={styles.bulkPill}>Bulk: Submit (4)</button>
        </div>

        <div className={styles.tableCard}>
          <table className={styles.table}>
            <thead>
              <tr>
                <th className={styles.th}>CLAIM #</th>
                <th className={styles.th}>PATIENT</th>
                <th className={styles.th}>DOS</th>
                <th className={styles.th}>CPT</th>
                <th className={styles.th}>INSURER</th>
                <th className={styles.th}>AMOUNT</th>
                <th className={styles.th}>STATUS</th>
                <th className={styles.th}>UPDATED</th>
                <th className={styles.th}>ACTIONS</th>
              </tr>
            </thead>
            <tbody>
              {visibleRows.length === 0 ? (
                <tr>
                  <td colSpan={9} className={styles.emptyRow}>
                    No claims match the current filter.
                  </td>
                </tr>
              ) : (
                visibleRows.map((row) => (
                  <tr key={row.claimNo}>
                    <td className={`${styles.td} ${styles.tdMuted}`}>{row.claimNo}</td>
                    <td className={`${styles.td} ${styles.tdPatient}`}>{row.patient}</td>
                    <td className={`${styles.td} ${styles.tdMuted}`}>{row.dos}</td>
                    <td className={styles.td}>
                      <span className={styles.cptChip}>{row.cpt}</span>
                    </td>
                    <td className={`${styles.td} ${styles.tdMuted}`}>{row.insurer}</td>
                    <td className={`${styles.td} ${styles.tdAmount}`}>{row.amount}</td>
                    <td className={styles.td}>
                      <span className={`${styles.pill} ${pillClass(row.status)}`}>
                        {row.statusLabel}
                      </span>
                    </td>
                    <td className={`${styles.td} ${styles.tdUpdated}`}>{row.updated}</td>
                    <td className={styles.td}>
                      <RowActions kind={row.action} />
                    </td>
                  </tr>
                ))
              )}
            </tbody>
          </table>
        </div>
      </main>
    </>
  );
}

// ── Subcomponents ────────────────────────────────────────────────────

type KpiCardProps = {
  readonly kpi: Kpi;
};

function KpiCard({ kpi }: KpiCardProps): JSX.Element {
  const valCls = `${styles.kpiVal} ${kpiToneClass(kpi.tone)}`;
  return (
    <div className={styles.kpi}>
      <span className={styles.kpiLabel}>{kpi.label}</span>
      <span className={valCls}>{kpi.value}</span>
      <span className={styles.kpiSub}>{kpi.sub}</span>
    </div>
  );
}

function kpiToneClass(tone: Kpi['tone']): string {
  switch (tone) {
    case 'navy':   return styles['valNavy']   ?? '';
    case 'blue':   return styles['valBlue']   ?? '';
    case 'green':  return styles['valGreen']  ?? '';
    case 'orange': return styles['valOrange'] ?? '';
  }
}

type FilterPillProps = {
  readonly k: FilterKey;
  readonly current: FilterKey;
  readonly onSelect: (k: FilterKey) => void;
  readonly children: React.ReactNode;
};

function FilterPill({ k, current, onSelect, children }: FilterPillProps): JSX.Element {
  const active = k === current;
  const cls = active ? `${styles.pillBtn} ${styles.pillBtnActive}` : styles.pillBtn;
  return (
    <button
      type="button"
      role="tab"
      aria-selected={active}
      className={cls}
      onClick={() => onSelect(k)}
    >
      {children}
    </button>
  );
}

function pillClass(status: StatusKey): string {
  switch (status) {
    case 'submitted':   return styles['statusSubmitted']   ?? '';
    case 'paid':        return styles['statusPaid']        ?? '';
    case 'denied':      return styles['statusDenied']      ?? '';
    case 'pending':     return styles['statusPending']     ?? '';
    case 'outstanding': return styles['statusOutstanding'] ?? '';
  }
}

type RowActionsProps = {
  readonly kind: ActionKind;
};

function RowActions({ kind }: RowActionsProps): JSX.Element {
  return (
    <span className={styles.rowActions}>
      <button type="button" className={styles.actView}>View</button>
      {kind === 'submit' && (
        <button type="button" className={styles.actPrimary}>Submit →</button>
      )}
      {kind === 'resolve' && (
        <button type="button" className={styles.actWarn}>Resolve →</button>
      )}
      {kind === 'view' && (
        <span className={styles.actDots} aria-hidden="true">⋯</span>
      )}
    </span>
  );
}
