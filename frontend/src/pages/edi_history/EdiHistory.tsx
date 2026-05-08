// EdiHistory — Figma "Screen 67 — EDI History" (node 145:2).
//
// AgentForge EDI batch transfer log. Page header with Sync now + Upload batch
// CTAs; single-card 5-KPI strip (Inbound 24h, Outbound 24h, Accepted, Pending,
// Failed); filter bar with All/Inbound/Outbound + transaction-type pills +
// date dropdown; primary table card with 10 batch rows.
//
// New AgentForge replacement for upstream /interface/billing/edih_view.php.
// The upstream PHP page is preserved unmodified; this component is the React
// version routed through /interface/billing/copilot_edi_history.php.
//
// All data is hardcoded static demo content matching the Figma exactly. The
// real EDI processing pipeline (edih_main.php / x12 partners) is out of scope
// for the demo — wiring is a follow-up.
//
// Reference: frontend/.fidelity-references/edi_history-figma-2026-05-07.png

import { useState } from 'react';
import type { BootContext } from '../../shared/lib/bootContext';
import styles from './EdiHistory.module.css';

// ── Types ────────────────────────────────────────────────────────────

type FilterKey = 'all' | 'inbound' | 'outbound' | 'p837' | 'p835' | 'p27x' | 'failed';

type StatusKey = 'accepted' | 'received' | 'ack' | 'pending' | 'failed';

type Direction = 'In' | 'Out';

type Batch = {
  readonly id: string;
  readonly type: string;
  readonly direction: Direction;
  readonly partner: string;
  readonly count: string;
  readonly size: string;
  readonly timestamp: string;
  readonly status: StatusKey;
  readonly statusLabel: string;
};

// ── Demo data — verbatim from Figma node 145:2 (Screen 67) ──────────

type Kpi = {
  readonly label: string;
  readonly value: string;
  readonly sub: string;
  readonly tone: 'navy' | 'green' | 'orange' | 'red';
};

const KPIS: readonly Kpi[] = [
  { label: 'Inbound (24h)',  value: '64',   sub: '12 ERAs · 52 271s',    tone: 'navy'   },
  { label: 'Outbound (24h)', value: '142',  sub: '78 837Ps · 64 270s',   tone: 'navy'   },
  { label: 'Accepted',       value: '198',  sub: '96.1% accept rate',    tone: 'green'  },
  { label: 'Pending',        value: '6',    sub: 'Avg age 38 min',       tone: 'orange' },
  { label: 'Failed',         value: '2',    sub: 'Bad cert · TX DSHS',   tone: 'red'    },
];

const PILL_COUNTS: Readonly<Record<FilterKey, number>> = {
  all:      206,
  inbound:  64,
  outbound: 142,
  p837:     78,
  p835:     12,
  p27x:     116,
  failed:   2,
};

const ROWS: readonly Batch[] = [
  { id: 'BATCH-2864', type: '837P',   direction: 'Out', partner: 'Blue Cross PPO',                count: '47',  size: '128 KB', timestamp: '04/30 13:00', status: 'accepted', statusLabel: 'Accepted'           },
  { id: 'BATCH-2863', type: '835',    direction: 'In',  partner: 'United Healthcare',             count: '12',  size: '84 KB',  timestamp: '04/30 12:14', status: 'received', statusLabel: 'Received'           },
  { id: 'BATCH-2862', type: '270',    direction: 'Out', partner: 'Aetna — eligibility',           count: '116', size: '38 KB',  timestamp: '04/30 11:30', status: 'accepted', statusLabel: 'Accepted'           },
  { id: 'BATCH-2861', type: '271',    direction: 'In',  partner: 'Aetna — eligibility',           count: '116', size: '92 KB',  timestamp: '04/30 11:31', status: 'received', statusLabel: 'Received'           },
  { id: 'BATCH-2860', type: '837I',   direction: 'Out', partner: 'UnitedHealth — institutional',  count: '3',   size: '24 KB',  timestamp: '04/30 09:00', status: 'accepted', statusLabel: 'Accepted'           },
  { id: 'BATCH-2859', type: '999',    direction: 'In',  partner: 'Blue Cross PPO',                count: '47',  size: '6 KB',   timestamp: '04/30 13:14', status: 'ack',      statusLabel: 'Acknowledged'       },
  { id: 'BATCH-2858', type: '837P',   direction: 'Out', partner: 'Cigna PPO',                     count: '18',  size: '64 KB',  timestamp: '04/30 08:00', status: 'pending',  statusLabel: 'Pending'            },
  { id: 'BATCH-2857', type: '277CA',  direction: 'In',  partner: 'Medicare — MAC J',              count: '24',  size: '18 KB',  timestamp: '04/30 07:30', status: 'received', statusLabel: 'Received'           },
  { id: 'BATCH-2856', type: '837P',   direction: 'Out', partner: 'Texas DSHS — STD report',       count: '4',   size: '12 KB',  timestamp: '04/29 16:30', status: 'failed',   statusLabel: 'Failed — bad cert'  },
  { id: 'BATCH-2855', type: '835',    direction: 'In',  partner: 'Cigna PPO',                     count: '8',   size: '52 KB',  timestamp: '04/29 15:00', status: 'received', statusLabel: 'Received'           },
];

// Filter bucket → predicate. Matches the Figma pill set semantically.
function matchesFilter(filter: FilterKey, b: Batch): boolean {
  switch (filter) {
    case 'all':      return true;
    case 'inbound':  return b.direction === 'In';
    case 'outbound': return b.direction === 'Out';
    case 'p837':     return b.type.startsWith('837');
    case 'p835':     return b.type === '835';
    case 'p27x':     return b.type === '270' || b.type === '271';
    case 'failed':   return b.status === 'failed';
  }
}

// ── Component ────────────────────────────────────────────────────────

type EdiHistoryProps = {
  readonly boot: BootContext;
};

export function EdiHistory(_props: EdiHistoryProps): JSX.Element {
  const [filter, setFilter] = useState<FilterKey>('all');
  const visibleRows = ROWS.filter((r) => matchesFilter(filter, r));

  return (
    <>
      <header className={styles.header}>
        <div className={styles.titleBlock}>
          <span className={styles.title}>EDI History</span>
          <span className={styles.dot}>·</span>
          <span className={styles.subtitle}>
            837/835/277/271 batch transfers · last sync 14 min ago
          </span>
        </div>
        <div className={styles.spacer} />
        <button className={styles.btnGhost} type="button">
          <span aria-hidden="true">⟳</span>
          <span>Sync now</span>
        </button>
        <button className={styles.btnPrimary} type="button">
          <span>+ Upload batch</span>
        </button>
      </header>

      <main className={styles.content}>
        <div className={styles.kpiCard}>
          {KPIS.map((k, i) => (
            <KpiSlot key={k.label} kpi={k} showSep={i > 0} />
          ))}
        </div>

        <div className={styles.filterBar} role="tablist">
          <FilterPill k="all"      current={filter} onSelect={setFilter} count={PILL_COUNTS.all}>All</FilterPill>
          <FilterPill k="inbound"  current={filter} onSelect={setFilter} count={PILL_COUNTS.inbound}>Inbound</FilterPill>
          <FilterPill k="outbound" current={filter} onSelect={setFilter} count={PILL_COUNTS.outbound}>Outbound</FilterPill>
          <FilterPill k="p837"     current={filter} onSelect={setFilter} count={PILL_COUNTS.p837}>837 (Claims)</FilterPill>
          <FilterPill k="p835"     current={filter} onSelect={setFilter} count={PILL_COUNTS.p835}>835 (ERA)</FilterPill>
          <FilterPill k="p27x"     current={filter} onSelect={setFilter} count={PILL_COUNTS.p27x}>270/271</FilterPill>
          <FilterPill k="failed"   current={filter} onSelect={setFilter} count={PILL_COUNTS.failed}>Failed</FilterPill>
          <div className={styles.spacer} />
          <button type="button" className={styles.dateDropdown}>
            <span aria-hidden="true">📅</span>
            <span>Last 7 days</span>
            <span aria-hidden="true" className={styles.caret}>▾</span>
          </button>
        </div>

        <div className={styles.tableCard}>
          <table className={styles.table}>
            <thead>
              <tr>
                <th className={styles.th}>BATCH ID</th>
                <th className={styles.th}>TYPE</th>
                <th className={styles.th}>DIRECTION</th>
                <th className={styles.th}>PARTNER</th>
                <th className={styles.th}>COUNT</th>
                <th className={styles.th}>SIZE</th>
                <th className={styles.th}>TIMESTAMP</th>
                <th className={styles.th}>STATUS</th>
                <th className={styles.th}>ACTIONS</th>
              </tr>
            </thead>
            <tbody>
              {visibleRows.length === 0 ? (
                <tr>
                  <td colSpan={9} className={styles.emptyRow}>
                    No batches match the current filter.
                  </td>
                </tr>
              ) : (
                visibleRows.map((row, i) => (
                  <tr key={row.id} className={i % 2 === 1 ? styles.rowAlt : undefined}>
                    <td className={`${styles.td} ${styles.tdId}`}>{row.id}</td>
                    <td className={styles.td}>
                      <span className={styles.typeChip}>{row.type}</span>
                    </td>
                    <td className={`${styles.td} ${dirClass(row.direction)}`}>
                      <span aria-hidden="true">{row.direction === 'Out' ? '↑' : '↓'}</span>
                      <span className={styles.dirText}>{row.direction === 'Out' ? 'Outbound' : 'Inbound'}</span>
                    </td>
                    <td className={styles.td}>{row.partner}</td>
                    <td className={`${styles.td} ${styles.tdCount}`}>{row.count}</td>
                    <td className={`${styles.td} ${styles.tdMuted}`}>{row.size}</td>
                    <td className={`${styles.td} ${styles.tdMuted}`}>{row.timestamp}</td>
                    <td className={styles.td}>
                      <span className={`${styles.pill} ${pillClass(row.status)}`}>
                        {row.statusLabel}
                      </span>
                    </td>
                    <td className={styles.td}>
                      <span className={styles.actions}>
                        <a className={styles.actLink} href="#view">View</a>
                        <span className={styles.actSep}>·</span>
                        <a className={styles.actLink} href="#download">Download</a>
                      </span>
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

type KpiSlotProps = {
  readonly kpi: Kpi;
  readonly showSep: boolean;
};

function KpiSlot({ kpi, showSep }: KpiSlotProps): JSX.Element {
  const valCls = `${styles.kpiVal} ${kpiToneClass(kpi.tone)}`;
  return (
    <>
      {showSep && <div className={styles.kpiSep} aria-hidden="true" />}
      <div className={styles.kpiSlot}>
        <span className={styles.kpiLabel}>{kpi.label}</span>
        <span className={valCls}>{kpi.value}</span>
        <span className={styles.kpiSub}>{kpi.sub}</span>
      </div>
    </>
  );
}

function kpiToneClass(tone: Kpi['tone']): string {
  switch (tone) {
    case 'navy':   return styles['valNavy']   ?? '';
    case 'green':  return styles['valGreen']  ?? '';
    case 'orange': return styles['valOrange'] ?? '';
    case 'red':    return styles['valRed']    ?? '';
  }
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

function pillClass(status: StatusKey): string {
  switch (status) {
    case 'accepted': return styles['pillAccepted'] ?? '';
    case 'received': return styles['pillReceived'] ?? '';
    case 'ack':      return styles['pillAck']      ?? '';
    case 'pending':  return styles['pillPending']  ?? '';
    case 'failed':   return styles['pillFailed']   ?? '';
  }
}

function dirClass(d: Direction): string {
  return d === 'Out' ? (styles['dirOut'] ?? '') : (styles['dirIn'] ?? '');
}
