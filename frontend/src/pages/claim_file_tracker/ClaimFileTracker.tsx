// ClaimFileTracker — Figma "Screen 68 — Claim File Tracker" (node 146:2).
//
// Cross-batch clearinghouse status board. Page header with Export CSV +
// Rebill selected CTAs; 5-pillar status board (Submitted, Acknowledged, Paid,
// Pending, Denied); filter bar with status pills + search; primary table
// card with 10 claim rows showing per-status timeline (Submitted → Ack →
// Paid) and per-row actions (View / Appeal / Resubmit) by status.
//
// New AgentForge replacement for upstream
// /interface/billing/billing_tracker.php. The upstream PHP page is preserved
// unmodified; this component is the React version routed through
// /interface/billing/copilot_claim_file_tracker.php.
//
// All data is hardcoded static demo content matching the Figma exactly. The
// real DataTables / library/ajax/billing_tracker_ajax.php integration is out
// of scope for the demo — wiring is a follow-up.
//
// Reference: frontend/.fidelity-references/claim_file_tracker-figma-2026-05-07.png

import { useState } from 'react';
import type { BootContext } from '../../shared/lib/bootContext';
import styles from './ClaimFileTracker.module.css';

// ── Types ────────────────────────────────────────────────────────────

type FilterKey = 'all' | 'submitted' | 'ack' | 'paid' | 'pending' | 'denied';

type StatusKey = 'submitted' | 'ack' | 'paid' | 'pending' | 'denied';

type Claim = {
  readonly claim: string;
  readonly patient: string;
  readonly payer: string;
  readonly amount: string;
  readonly submitted: string;
  readonly ack: string;
  readonly paid: string;
  readonly age: string;
  readonly status: StatusKey;
  readonly statusLabel: string;
};

// ── Demo data — verbatim from Figma node 146:2 (Screen 68) ──────────

type Pillar = {
  readonly key: StatusKey;
  readonly label: string;
  readonly count: string;
  readonly delta: string;
};

const PILLARS: readonly Pillar[] = [
  { key: 'submitted', label: 'Submitted',    count: '124', delta: '+18 today'        },
  { key: 'ack',       label: 'Acknowledged', count: '982', delta: '94% within 24h'   },
  { key: 'paid',      label: 'Paid',         count: '742', delta: '$184k YTD'        },
  { key: 'pending',   label: 'Pending',      count: '124', delta: 'Avg age 12 days'  },
  { key: 'denied',    label: 'Denied',       count: '18',  delta: '6 appealable'     },
];

const ROWS: readonly Claim[] = [
  { claim: 'CLM-9402', patient: 'Margaret Chen',  payer: 'Blue Cross PPO',    amount: '$152.00', submitted: '04/30', ack: '04/30', paid: '—',     age: '0d', status: 'submitted', statusLabel: 'Submitted'    },
  { claim: 'CLM-9401', patient: 'Ted Shaw',       payer: 'Aetna HMO',         amount: '$215.00', submitted: '04/30', ack: '04/30', paid: '—',     age: '0d', status: 'ack',       statusLabel: 'Acknowledged' },
  { claim: 'CLM-9399', patient: 'Linda Martinez', payer: 'United Healthcare', amount: '$310.00', submitted: '04/29', ack: '04/29', paid: '—',     age: '1d', status: 'denied',    statusLabel: 'Denied'       },
  { claim: 'CLM-9398', patient: 'David Kim',      payer: 'Cigna PPO',         amount: '$152.00', submitted: '04/29', ack: '—',     paid: '—',     age: '1d', status: 'pending',   statusLabel: 'Pending'      },
  { claim: 'CLM-9397', patient: 'Allison Park',   payer: 'Medicare',          amount: '$280.00', submitted: '04/28', ack: '04/28', paid: '04/30', age: '2d', status: 'paid',      statusLabel: 'Paid'         },
  { claim: 'CLM-9395', patient: 'Robert Hayes',   payer: 'Blue Cross PPO',    amount: '$184.00', submitted: '04/28', ack: '04/28', paid: '—',     age: '2d', status: 'submitted', statusLabel: 'Submitted'    },
  { claim: 'CLM-9394', patient: 'Carol Bennett',  payer: 'Self-pay',          amount: '$152.00', submitted: '04/28', ack: '—',     paid: '—',     age: '2d', status: 'pending',   statusLabel: 'Pending'      },
  { claim: 'CLM-9392', patient: 'James Wong',     payer: 'United Healthcare', amount: '$215.00', submitted: '04/27', ack: '04/27', paid: '04/29', age: '3d', status: 'paid',      statusLabel: 'Paid'         },
  { claim: 'CLM-9389', patient: 'Helen Garcia',   payer: 'Cigna PPO',         amount: '$340.00', submitted: '04/26', ack: '04/26', paid: '—',     age: '4d', status: 'denied',    statusLabel: 'Denied'       },
  { claim: 'CLM-9385', patient: 'Marco Lee',      payer: 'Medicare',          amount: '$420.00', submitted: '04/24', ack: '04/24', paid: '04/30', age: '6d', status: 'paid',      statusLabel: 'Paid'         },
];

function matchesFilter(filter: FilterKey, c: Claim): boolean {
  if (filter === 'all') return true;
  return c.status === filter;
}

// Aged claims (>3 days) get the warn tone in the Age column.
function ageClass(age: string): string {
  const days = parseInt(age.replace('d', ''), 10);
  if (Number.isFinite(days) && days >= 4) return styles['ageWarn'] ?? '';
  return styles['ageNormal'] ?? '';
}

// ── Component ────────────────────────────────────────────────────────

type ClaimFileTrackerProps = {
  readonly boot: BootContext;
};

export function ClaimFileTracker(_props: ClaimFileTrackerProps): JSX.Element {
  const [filter, setFilter] = useState<FilterKey>('all');
  const visibleRows = ROWS.filter((r) => matchesFilter(filter, r));

  return (
    <>
      <header className={styles.header}>
        <div className={styles.titleBlock}>
          <span className={styles.title}>Claim File Tracker</span>
          <span className={styles.dot}>·</span>
          <span className={styles.subtitle}>
            Cross-batch clearinghouse status · 1,284 claims tracked · 18 require attention
          </span>
        </div>
        <div className={styles.spacer} />
        <button className={styles.btnGhost} type="button">
          <span aria-hidden="true">⬇</span>
          <span>Export CSV</span>
        </button>
        <button className={styles.btnPrimary} type="button">
          <span>+ Rebill selected</span>
        </button>
      </header>

      <main className={styles.content}>
        <div className={styles.board}>
          {PILLARS.map((p) => (
            <PillarCard key={p.key} pillar={p} />
          ))}
        </div>

        <div className={styles.filterBar} role="tablist">
          <FilterPill k="all"       current={filter} onSelect={setFilter}>All</FilterPill>
          <FilterPill k="submitted" current={filter} onSelect={setFilter}>Submitted</FilterPill>
          <FilterPill k="ack"       current={filter} onSelect={setFilter}>Acknowledged</FilterPill>
          <FilterPill k="paid"      current={filter} onSelect={setFilter}>Paid</FilterPill>
          <FilterPill k="pending"   current={filter} onSelect={setFilter}>Pending</FilterPill>
          <FilterPill k="denied"    current={filter} onSelect={setFilter}>Denied</FilterPill>
          <div className={styles.spacer} />
          <div className={styles.searchBox}>
            <span className={styles.searchIcon} aria-hidden="true">🔍</span>
            <span className={styles.searchPlaceholder}>Search by claim #, patient, payer</span>
          </div>
        </div>

        <div className={styles.tableCard}>
          <table className={styles.table}>
            <thead>
              <tr>
                <th className={styles.th}>CLAIM #</th>
                <th className={styles.th}>PATIENT</th>
                <th className={styles.th}>PAYER</th>
                <th className={styles.th}>AMOUNT</th>
                <th className={styles.th}>SUBMITTED</th>
                <th className={styles.th}>ACK</th>
                <th className={styles.th}>PAID</th>
                <th className={styles.th}>AGE</th>
                <th className={styles.th}>STATUS</th>
                <th className={styles.th}>ACTIONS</th>
              </tr>
            </thead>
            <tbody>
              {visibleRows.length === 0 ? (
                <tr>
                  <td colSpan={10} className={styles.emptyRow}>
                    No claims match the current filter.
                  </td>
                </tr>
              ) : (
                visibleRows.map((row, i) => (
                  <tr key={row.claim} className={i % 2 === 1 ? styles.rowAlt : undefined}>
                    <td className={`${styles.td} ${styles.tdClaim}`}>{row.claim}</td>
                    <td className={`${styles.td} ${styles.tdPatient}`}>{row.patient}</td>
                    <td className={styles.td}>{row.payer}</td>
                    <td className={`${styles.td} ${styles.tdAmount}`}>{row.amount}</td>
                    <td className={`${styles.td} ${styles.tdMuted}`}>{row.submitted}</td>
                    <td className={`${styles.td} ${row.ack === '—' ? styles.tdEmpty : styles.tdMuted}`}>{row.ack}</td>
                    <td className={`${styles.td} ${row.paid === '—' ? styles.tdEmpty : styles.tdPaidDate}`}>{row.paid}</td>
                    <td className={`${styles.td} ${ageClass(row.age)}`}>{row.age}</td>
                    <td className={styles.td}>
                      <span className={`${styles.pill} ${pillClass(row.status)}`}>
                        {row.statusLabel}
                      </span>
                    </td>
                    <td className={styles.td}>
                      <RowActions status={row.status} />
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

type PillarCardProps = {
  readonly pillar: Pillar;
};

function PillarCard({ pillar }: PillarCardProps): JSX.Element {
  return (
    <div className={styles.pillar}>
      <span className={`${styles.pillarBadge} ${pillarBadgeClass(pillar.key)}`}>
        {pillar.label.toUpperCase()}
      </span>
      <span className={styles.pillarCount}>{pillar.count}</span>
      <span className={styles.pillarDelta}>{pillar.delta}</span>
    </div>
  );
}

function pillarBadgeClass(s: StatusKey): string {
  switch (s) {
    case 'submitted': return styles['badgeSubmitted'] ?? '';
    case 'ack':       return styles['badgeAck']       ?? '';
    case 'paid':      return styles['badgePaid']      ?? '';
    case 'pending':   return styles['badgePending']   ?? '';
    case 'denied':    return styles['badgeDenied']    ?? '';
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

function pillClass(s: StatusKey): string {
  switch (s) {
    case 'submitted': return styles['pillSubmitted'] ?? '';
    case 'ack':       return styles['pillAck']       ?? '';
    case 'paid':      return styles['pillPaid']      ?? '';
    case 'pending':   return styles['pillPending']   ?? '';
    case 'denied':    return styles['pillDenied']    ?? '';
  }
}

type RowActionsProps = {
  readonly status: StatusKey;
};

function RowActions({ status }: RowActionsProps): JSX.Element {
  const showAppeal   = status === 'denied';
  const showResubmit = status === 'pending';
  return (
    <span className={styles.actions}>
      <a className={styles.actLink} href="#view">View</a>
      {showAppeal && (
        <>
          <span className={styles.actSep}>·</span>
          <a className={`${styles.actLink} ${styles.actDanger}`} href="#appeal">Appeal →</a>
        </>
      )}
      {showResubmit && (
        <>
          <span className={styles.actSep}>·</span>
          <a className={`${styles.actLink} ${styles.actWarn}`} href="#resubmit">Resubmit →</a>
        </>
      )}
    </span>
  );
}
