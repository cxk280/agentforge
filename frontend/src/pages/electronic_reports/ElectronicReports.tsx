// ElectronicReports — Figma "Screen 39 — Electronic Reports / Submissions".
// Cross-patient view of outbound electronic submissions (CCDA exports, payer
// claim batches, registry uploads, public-health reports, HIE deltas).
//
// 1:1 port of the PHP-rendered page previously at
// /interface/reports/copilot_electronic_reports.php. The original used a
// `cp_submissions` MySQL table with idempotent seed + POST handlers; for the
// React port we use **hardcoded static demo data matching the Figma exactly**
// (KPI counts, pill counts, and 13 table rows). The DB integration is dropped.
//
// The PHP wrapper renders the manifest + boot context onto #cp-root; this
// component owns the page header, KPI strip, filter pills, and table. The
// navy top nav and reports left sidebar from the Figma are out of scope per
// the migration plan (handled by the parent shell when wired in).
//
// Filter pill clicks update local state and narrow the visible rows by the
// type bucket (CCDA / Payer / Registry / Public health), matching the
// PHP page's GET-based ?filter= behavior visually.

import { useState } from 'react';
import type { BootContext } from '../../shared/lib/bootContext';
import styles from './ElectronicReports.module.css';

// ── Types ────────────────────────────────────────────────────────────

type FilterKey = 'all' | 'ccda' | 'payer' | 'registry' | 'public_health';

type StatusTone = 'good' | 'warn' | 'danger';

type Submission = {
  readonly submissionId: string;
  readonly type: string;
  readonly destination: string;
  readonly target: string;
  readonly sent: string;
  readonly status: string;
  readonly tone: StatusTone;
};

// ── Demo data — verbatim from Figma node 85:2 (Screen 39) ───────────

const KPIS = {
  sentToday: 142,
  acknowledged: 138,
  pending: 3,
  failed: 1,
  last30d: '3.8k',
} as const;

const PILL_COUNTS: Readonly<Record<FilterKey, number>> = {
  all: 142,
  ccda: 58,
  payer: 42,
  registry: 24,
  public_health: 18,
};

// 13 rows transcribed from the Figma. Sent column is the verbatim mock label
// (MM/DD HH:MM) — matches the figma copy exactly so the visual diff is clean.
const SUBMISSIONS: readonly Submission[] = [
  { submissionId: 'SUB-91204', type: 'CCDA — Continuity', destination: 'Cardiology Associates of Austin', target: 'Margaret Chen · Full chart', sent: '04/30 14:22', status: 'Acknowledged',     tone: 'good'   },
  { submissionId: 'SUB-91203', type: 'Payer 837P',        destination: 'Blue Cross PPO — claims batch',   target: '47 encounters',           sent: '04/30 13:00', status: 'Accepted',         tone: 'good'   },
  { submissionId: 'SUB-91202', type: 'CCDA — Referral',   destination: 'Endocrine Specialists of TX',     target: 'David Kim · Last 90d',    sent: '04/30 11:14', status: 'Awaiting ack',     tone: 'warn'   },
  { submissionId: 'SUB-91201', type: 'Public health',     destination: 'Texas DSHS — Immunization',       target: 'Pediatric batch (12)',    sent: '04/30 10:00', status: 'Acknowledged',     tone: 'good'   },
  { submissionId: 'SUB-91200', type: 'Registry — CQM',    destination: 'CMS QPP Q1 2026',                 target: 'Practice-wide submission',sent: '04/30 09:30', status: 'Pending',          tone: 'warn'   },
  { submissionId: 'SUB-91199', type: 'CCDA — Discharge',  destination: 'Patient portal — Margaret Chen',  target: 'Visit summary 04/30',     sent: '04/30 08:45', status: 'Delivered',        tone: 'good'   },
  { submissionId: 'SUB-91198', type: 'Payer 837I',        destination: 'UnitedHealth — institutional',    target: '3 facility encounters',   sent: '04/30 08:00', status: 'Accepted',         tone: 'good'   },
  { submissionId: 'SUB-91197', type: 'HIE Sync',          destination: 'CommonWell',                      target: 'Daily delta (124 patients)', sent: '04/30 06:00', status: 'Acknowledged',  tone: 'good'   },
  { submissionId: 'SUB-91196', type: 'Registry',          destination: 'Texas Cancer Registry',           target: 'Quarterly oncology export',  sent: '04/29 22:00', status: 'Acknowledged',  tone: 'good'   },
  { submissionId: 'SUB-91195', type: 'Public health',     destination: 'Texas DSHS — STD',                target: 'Mandatory case report',   sent: '04/29 16:30', status: 'FAILED — bad cert',tone: 'danger' },
  { submissionId: 'SUB-91194', type: 'CCDA — Continuity', destination: 'Mercy Home Health',               target: 'Linda Martinez · Care plan', sent: '04/29 15:14', status: 'Acknowledged',  tone: 'good'   },
  { submissionId: 'SUB-91193', type: 'Payer 270/271',     destination: 'Aetna — eligibility',             target: 'Daily eligibility check', sent: '04/29 14:00', status: 'Accepted',         tone: 'good'   },
  { submissionId: 'SUB-91192', type: 'CCDA — Continuity', destination: 'Imaging Center — Riverside',      target: 'Allison Park · MRI request', sent: '04/29 11:30', status: 'Awaiting ack',  tone: 'warn'   },
];

// Filter bucket → predicate. Mirrors the PHP `typeFilterFragment`.
function matchesFilter(filter: FilterKey, submission: Submission): boolean {
  switch (filter) {
    case 'all':           return true;
    case 'ccda':          return submission.type.startsWith('CCDA');
    case 'payer':         return submission.type.startsWith('Payer');
    case 'registry':      return submission.type.startsWith('Registry');
    case 'public_health': return submission.type === 'Public health';
  }
}

// ── Component ────────────────────────────────────────────────────────

type ElectronicReportsProps = {
  readonly boot: BootContext;
};

export function ElectronicReports(_props: ElectronicReportsProps): JSX.Element {
  const [filter, setFilter] = useState<FilterKey>('all');

  const visibleRows = SUBMISSIONS.filter((s) => matchesFilter(filter, s));

  return (
    <>
      <header className={styles.header}>
        <div className={styles.titleBlock}>
          <span className={styles.title}>Electronic Submissions</span>
          <span className={styles.dot}>·</span>
          <span className={styles.subtitle}>
            CCDA, payer reports, registry exports · last sync 14 min ago
          </span>
        </div>
        <div className={styles.spacer} />
        <button className={styles.btnGhost} type="button">
          <span aria-hidden="true">⟳</span>
          <span>Sync now</span>
        </button>
        <button className={styles.btnPrimary} type="button">
          <span>+ New submission</span>
        </button>
        <button className={styles.btnHelp} type="button" aria-label="Help">?</button>
      </header>

      <main className={styles.content}>
        <div className={styles.kpiCard}>
          <KPI label="Sent today"    value={String(KPIS.sentToday)} />
          <KPISep />
          <KPI label="Acknowledged"  value={String(KPIS.acknowledged)} valueClass={styles.valGreen} />
          <KPISep />
          <KPI label="Pending"       value={String(KPIS.pending)}      valueClass={styles.valOrange} />
          <KPISep />
          <KPI label="Failed"        value={String(KPIS.failed)}       valueClass={styles.valRed} />
          <KPISep />
          <KPI label="Last 30d"      value={KPIS.last30d} />
        </div>

        <div className={styles.filterBar} role="tablist">
          <FilterPill k="all"           current={filter} onSelect={setFilter} count={PILL_COUNTS.all}>All</FilterPill>
          <FilterPill k="ccda"          current={filter} onSelect={setFilter} count={PILL_COUNTS.ccda}>CCDA</FilterPill>
          <FilterPill k="payer"         current={filter} onSelect={setFilter} count={PILL_COUNTS.payer}>Payer</FilterPill>
          <FilterPill k="registry"      current={filter} onSelect={setFilter} count={PILL_COUNTS.registry}>Registry</FilterPill>
          <FilterPill k="public_health" current={filter} onSelect={setFilter} count={PILL_COUNTS.public_health}>Public health</FilterPill>
        </div>

        <div className={styles.tableCard}>
          <table className={styles.table}>
            <thead>
              <tr>
                <th className={styles.th}>SUBMISSION ID</th>
                <th className={styles.th}>TYPE</th>
                <th className={styles.th}>DESTINATION</th>
                <th className={styles.th}>PATIENT / RECORDS</th>
                <th className={styles.th}>SENT</th>
                <th className={styles.th}>STATUS</th>
                <th className={`${styles.th} ${styles.thAct}`} aria-label="Actions" />
              </tr>
            </thead>
            <tbody>
              {visibleRows.length === 0 ? (
                <tr>
                  <td colSpan={7} className={styles.emptyRow}>
                    No submissions match the current filter.
                  </td>
                </tr>
              ) : (
                visibleRows.map((row, i) => (
                  <tr key={row.submissionId} className={i % 2 === 1 ? styles.rowAlt : undefined}>
                    <td className={`${styles.td} ${styles.idCell}`}>
                      <a href={`?id=${encodeURIComponent(row.submissionId)}`}>{row.submissionId}</a>
                    </td>
                    <td className={styles.td}>{row.type}</td>
                    <td className={styles.td}>{row.destination}</td>
                    <td className={`${styles.td} ${styles.tdMuted}`}>{row.target}</td>
                    <td className={`${styles.td} ${styles.tdMuted}`}>{row.sent}</td>
                    <td className={styles.td}>
                      <span className={`${styles.pill} ${pillClass(row.tone)}`}>{row.status}</span>
                    </td>
                    <td className={`${styles.td} ${styles.tdAct}`} aria-label="Row actions">⋯</td>
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

type KPIProps = {
  readonly label: string;
  readonly value: string;
  readonly valueClass?: string | undefined;
};

function KPI({ label, value, valueClass }: KPIProps): JSX.Element {
  const valCls = valueClass ? `${styles.kpiVal} ${valueClass}` : styles.kpiVal;
  return (
    <div className={styles.kpiItem}>
      <span className={styles.kpiLabel}>{label}</span>
      <span className={valCls}>{value}</span>
    </div>
  );
}

function KPISep(): JSX.Element {
  return <div className={styles.kpiSep} aria-hidden="true" />;
}

type FilterPillProps = {
  readonly k: FilterKey;
  readonly current: FilterKey;
  readonly onSelect: (k: FilterKey) => void;
  readonly count: number;
  readonly children: React.ReactNode;
};

function FilterPill({ k, current, onSelect, count, children }: FilterPillProps): JSX.Element {
  const active = k === current;
  const cls = active ? `${styles.pillBtn} ${styles.pillBtnActive}` : styles.pillBtn;
  const ctCls = active ? `${styles.pillCt} ${styles.pillCtActive}` : styles.pillCt;
  return (
    <button
      type="button"
      role="tab"
      aria-selected={active}
      className={cls}
      onClick={() => onSelect(k)}
    >
      <span>{children}</span>
      <span className={ctCls}>{count}</span>
    </button>
  );
}

function pillClass(tone: StatusTone): string {
  switch (tone) {
    case 'good':   return styles['pillGood'] ?? '';
    case 'warn':   return styles['pillWarn'] ?? '';
    case 'danger': return styles['pillDanger'] ?? '';
  }
}
