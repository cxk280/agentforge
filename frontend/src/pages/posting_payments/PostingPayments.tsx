// PostingPayments — Figma "Screen 66 — Posting Payments" (node 143:2).
//
// AgentForge ERA / EOB posting workflow. Page header with Export + New batch
// post buttons; 4-card KPI strip (Envelopes ready, Posted today, Awaiting
// review, Avg post time); filter bar with date/payer/status/source dropdowns
// + search + bulk-post pill; primary table card with 9 EOB envelope rows.
// Per-row actions vary by status: Ready → Post / Skip, Review/Denied →
// Resolve / Skip, Posted → View only.
//
// New AgentForge replacement for upstream /interface/billing/sl_eob_search.php.
// The upstream PHP page is preserved unmodified; this component is the React
// version routed through /interface/billing/copilot_posting_payments.php.
//
// All data is hardcoded static demo content matching the Figma exactly — no
// DB calls, no real posting logic. Wiring to a real
// /apis/copilot/billing/eob endpoint is a follow-up.
//
// The navy top nav and patient header2 banner remain owned by the parent
// shell; this component renders only the page body.
//
// Reference: frontend/.fidelity-references/posting_payments-figma-2026-05-07.png

import { useState } from 'react';
import type { BootContext } from '../../shared/lib/bootContext';
import styles from './PostingPayments.module.css';

// ── Types ────────────────────────────────────────────────────────────

type StatusKey = 'ready' | 'review' | 'posted' | 'denied';

type Envelope = {
  readonly check: string;
  readonly payer: string;
  readonly patients: string;
  readonly received: string;
  readonly paid: string;
  readonly expected: string;
  readonly variance: string;
  readonly status: StatusKey;
  readonly statusLabel: string;
};

// ── Demo data — verbatim from Figma node 143:2 (Screen 66) ──────────

type Kpi = {
  readonly label: string;
  readonly value: string;
  readonly sub: string;
  readonly tone: 'navy' | 'green' | 'orange' | 'blue';
};

const KPIS: readonly Kpi[] = [
  { label: 'Envelopes ready', value: '24',       sub: '$48,920 to apply',           tone: 'navy'   },
  { label: 'Posted today',    value: '$18,420',  sub: '32 patients',                tone: 'green'  },
  { label: 'Awaiting review', value: '3',        sub: '$1,840 partial / disputes',  tone: 'orange' },
  { label: 'Avg post time',   value: '4.2 min',  sub: '↓ 18% week-over-week',       tone: 'blue'   },
];

const ROWS: readonly Envelope[] = [
  { check: 'EFT-7841', payer: 'Blue Cross PPO',     patients: '12', received: '04/30', paid: '$8,420.00', expected: '$8,420.00', variance: '—',       status: 'ready',  statusLabel: 'Ready'        },
  { check: 'EFT-7840', payer: 'Aetna HMO',          patients: '8',  received: '04/30', paid: '$3,180.00', expected: '$3,180.00', variance: '—',       status: 'ready',  statusLabel: 'Ready'        },
  { check: 'EFT-7839', payer: 'United Healthcare',  patients: '6',  received: '04/30', paid: '$2,940.00', expected: '$3,210.00', variance: '−$270',   status: 'review', statusLabel: 'Review'       },
  { check: 'CHK-2014', payer: 'Cigna PPO',          patients: '4',  received: '04/29', paid: '$1,520.00', expected: '$1,520.00', variance: '—',       status: 'ready',  statusLabel: 'Ready'        },
  { check: 'EFT-7838', payer: 'Medicare',           patients: '14', received: '04/29', paid: '$5,840.00', expected: '$5,840.00', variance: '—',       status: 'posted', statusLabel: 'Posted'       },
  { check: 'EFT-7837', payer: 'Medicaid TX',        patients: '5',  received: '04/29', paid: '$0.00',     expected: '$1,180.00', variance: '−$1,180', status: 'denied', statusLabel: 'Denied batch' },
  { check: 'CHK-2013', payer: 'Self-pay (Patient)', patients: '1',  received: '04/29', paid: '$152.00',   expected: '$152.00',   variance: '—',       status: 'ready',  statusLabel: 'Ready'        },
  { check: 'EFT-7836', payer: 'BCBS HMO',           patients: '9',  received: '04/28', paid: '$4,210.00', expected: '$4,090.00', variance: '+$120',   status: 'review', statusLabel: 'Review'       },
  { check: 'EFT-7835', payer: 'Humana',             patients: '3',  received: '04/28', paid: '$1,680.00', expected: '$1,680.00', variance: '—',       status: 'posted', statusLabel: 'Posted'       },
];

type FilterState = {
  readonly dateRange: string;
  readonly payer: string;
  readonly status: string;
  readonly source: string;
};

const DEFAULT_FILTERS: FilterState = {
  dateRange: 'Last 14 days',
  payer:     'All payers',
  status:    'Ready to post',
  source:    'ERA + EOB',
};

// ── Component ────────────────────────────────────────────────────────

type PostingPaymentsProps = {
  readonly boot: BootContext;
};

export function PostingPayments(_props: PostingPaymentsProps): JSX.Element {
  const [filters] = useState<FilterState>(DEFAULT_FILTERS);

  return (
    <>
      <header className={styles.header}>
        <div className={styles.titleBlock}>
          <span className={styles.title}>Posting Payments</span>
          <span className={styles.dot}>·</span>
          <span className={styles.subtitle}>
            ERA / EOB posting · 24 envelopes ready · 3 require attention
          </span>
        </div>
        <div className={styles.spacer} />
        <button className={styles.btnGhost} type="button">
          <span aria-hidden="true">⬇</span>
          <span>Export</span>
        </button>
        <button className={styles.btnPrimary} type="button">
          <span>+ New batch post</span>
        </button>
      </header>

      <main className={styles.content}>
        <div className={styles.kpiGrid}>
          {KPIS.map((k) => (
            <KpiCard key={k.label} kpi={k} />
          ))}
        </div>

        <div className={styles.filterBar}>
          <FilterDropdown label="DATE RANGE" value={filters.dateRange} width={152} />
          <FilterDropdown label="PAYER"      value={filters.payer}     width={140} />
          <FilterDropdown label="STATUS"     value={filters.status}    width={144} />
          <FilterDropdown label="SOURCE"     value={filters.source}    width={124} />
          <div className={styles.searchBox}>
            <span className={styles.searchIcon} aria-hidden="true">🔍</span>
            <span className={styles.searchPlaceholder}>Search by check #, payer, patient</span>
          </div>
          <div className={styles.spacer} />
          <button type="button" className={styles.bulkPill}>Bulk: Post (4)</button>
        </div>

        <div className={styles.tableCard}>
          <table className={styles.table}>
            <thead>
              <tr>
                <th className={styles.th}>CHECK / ERA #</th>
                <th className={styles.th}>PAYER</th>
                <th className={styles.th}>PATIENTS</th>
                <th className={styles.th}>RECEIVED</th>
                <th className={styles.th}>AMOUNT PAID</th>
                <th className={styles.th}>EXPECTED</th>
                <th className={styles.th}>VARIANCE</th>
                <th className={styles.th}>STATUS</th>
                <th className={styles.th}>ACTIONS</th>
              </tr>
            </thead>
            <tbody>
              {ROWS.map((row, i) => (
                <tr key={row.check} className={i % 2 === 1 ? styles.rowAlt : undefined}>
                  <td className={`${styles.td} ${styles.tdCheck}`}>{row.check}</td>
                  <td className={styles.td}>{row.payer}</td>
                  <td className={styles.td}>{row.patients}</td>
                  <td className={`${styles.td} ${styles.tdMuted}`}>{row.received}</td>
                  <td className={`${styles.td} ${styles.tdAmount}`}>{row.paid}</td>
                  <td className={styles.td}>{row.expected}</td>
                  <td className={`${styles.td} ${varianceClass(row.variance)}`}>{row.variance}</td>
                  <td className={styles.td}>
                    <span className={`${styles.pill} ${pillClass(row.status)}`}>
                      {row.statusLabel}
                    </span>
                  </td>
                  <td className={styles.td}>
                    <RowActions status={row.status} />
                  </td>
                </tr>
              ))}
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
  // Bracket access on a CSS module is string | undefined under
  // noUncheckedIndexedAccess — coerce so the className is always a string.
  switch (tone) {
    case 'navy':   return styles['valNavy']   ?? '';
    case 'green':  return styles['valGreen']  ?? '';
    case 'orange': return styles['valOrange'] ?? '';
    case 'blue':   return styles['valBlue']   ?? '';
  }
}

type FilterDropdownProps = {
  readonly label: string;
  readonly value: string;
  readonly width: number;
};

function FilterDropdown({ label, value, width }: FilterDropdownProps): JSX.Element {
  return (
    <div className={styles.dropdown} style={{ width: `${width}px` }}>
      <div className={styles.ddLabel}>{label}</div>
      <div className={styles.ddVal}>{value}</div>
      <span className={styles.ddCaret} aria-hidden="true">▾</span>
    </div>
  );
}

function pillClass(status: StatusKey): string {
  switch (status) {
    case 'ready':  return styles['pillReady']  ?? '';
    case 'review': return styles['pillReview'] ?? '';
    case 'posted': return styles['pillPosted'] ?? '';
    case 'denied': return styles['pillDenied'] ?? '';
  }
}

function varianceClass(v: string): string {
  if (v === '—') return styles['vNeutral'] ?? '';
  if (v.startsWith('+')) return styles['vPos'] ?? '';
  // includes the en-dash minus we use for negative variance
  return styles['vNeg'] ?? '';
}

type RowActionsProps = {
  readonly status: StatusKey;
};

function RowActions({ status }: RowActionsProps): JSX.Element {
  const showPost    = status === 'ready';
  const showResolve = status === 'review' || status === 'denied';
  const showSkip    = status === 'ready' || status === 'review';
  return (
    <span className={styles.actions}>
      <button type="button" className={styles.actView}>View</button>
      {showPost && (
        <button type="button" className={styles.actPrimary}>Post →</button>
      )}
      {showResolve && (
        <button type="button" className={styles.actWarn}>Resolve →</button>
      )}
      {showSkip && (
        <button type="button" className={styles.actSkip}>Skip</button>
      )}
    </span>
  );
}
