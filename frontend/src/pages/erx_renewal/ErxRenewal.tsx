// ErxRenewal — Figma "Screen 41 — e-Rx Renewal".
//
// Refill request queue. Sub-tab strip (Active / New Rx / Renewals / EPCS /
// Pharmacy / Drug check), page head with title + counts + bulk actions,
// filter pill bar (All / Standard / PA required / Controlled / Out of refills),
// then a vertical stack of card-style refill request rows. Card layout
// matches the Figma node 88:67 (Margaret Chen, Metformin) row geometry:
// checkbox · avatar · patient block · drug block · status block · actions.
//
// Static demo: no DB, no POSTs, all rows are hard-coded from the Figma
// source. Selection state is local-only — clicking a row checkbox toggles
// the visual state, but does not change the bulk-action button counter
// (the Figma shows "6 selected" with 6 of 7 rows pre-checked, so we honor
// that as the initial state).

import { useState } from 'react';
import type { BootContext } from '../../shared/lib/bootContext';
import styles from './ErxRenewal.module.css';

type Subtab = 'active' | 'new_rx' | 'renewals' | 'epcs' | 'pharmacy' | 'drug_check';
type Filter = 'all' | 'standard' | 'pa' | 'controlled' | 'out';
type StatusTone = 'good' | 'warn' | 'violet';
type RefillTone = 'muted' | 'danger';

type RefillRow = {
  readonly id: number;
  readonly checked: boolean;
  readonly name: string;
  readonly mrn: string;
  readonly pharmacy: string;
  readonly drug: string;
  readonly sig: string;
  readonly refillLine: string;
  readonly refillTone: RefillTone;
  readonly statusLabel: string;
  readonly statusTone: StatusTone;
  readonly lastFilled: string;
  readonly subLine?: string | undefined;
};

const SUBTABS: ReadonlyArray<{ readonly key: Subtab; readonly label: string }> = [
  { key: 'active',     label: 'Active' },
  { key: 'new_rx',     label: 'New Rx' },
  { key: 'renewals',   label: 'Renewals' },
  { key: 'epcs',       label: 'EPCS' },
  { key: 'pharmacy',   label: 'Pharmacy' },
  { key: 'drug_check', label: 'Drug check' },
];

const FILTERS: ReadonlyArray<{ readonly key: Filter; readonly label: string; readonly count: number }> = [
  { key: 'all',        label: 'All',           count: 14 },
  { key: 'standard',   label: 'Standard',      count: 6 },
  { key: 'pa',         label: 'PA required',   count: 8 },
  { key: 'controlled', label: 'Controlled',    count: 2 },
  { key: 'out',        label: 'Out of refills', count: 4 },
];

// Verbatim from Figma node 88:2 — 7 rows, pre-checked on rows 1-3,5-7
// (Carlos Mendez row 4 unchecked). Counts: 14 pending · 8 PA · 2 controlled.
const ROWS: readonly RefillRow[] = [
  {
    id: 1,
    checked: true,
    name: 'Margaret Chen',
    mrn: '#004821',
    pharmacy: 'CVS Pharmacy #4291 · Austin',
    drug: 'Metformin 1000 mg',
    sig: 'Tab, BID with meals',
    refillLine: '3 of 5 refills used',
    refillTone: 'muted',
    statusLabel: 'Standard',
    statusTone: 'good',
    lastFilled: '04/02/2026',
    subLine: 'HbA1c 7.9% — consider increasing dose',
  },
  {
    id: 2,
    checked: true,
    name: 'Linda Martinez',
    mrn: '#003918',
    pharmacy: 'Walgreens #1872 · Austin',
    drug: 'Lisinopril 10 mg',
    sig: 'Tab, daily',
    refillLine: 'OUT OF REFILLS',
    refillTone: 'danger',
    statusLabel: 'Out of refills',
    statusTone: 'warn',
    lastFilled: '03/15/2026',
  },
  {
    id: 3,
    checked: true,
    name: 'David Kim',
    mrn: '#006102',
    pharmacy: 'CVS Pharmacy #2188 · Austin',
    drug: 'Atorvastatin 40 mg',
    sig: 'Tab, nightly',
    refillLine: '5 of 5 refills used',
    refillTone: 'muted',
    statusLabel: 'Out of refills',
    statusTone: 'warn',
    lastFilled: '03/22/2026',
  },
  {
    id: 4,
    checked: false,
    name: 'Carlos Mendez',
    mrn: '#004102',
    pharmacy: 'HEB Pharmacy · Austin',
    drug: 'Levothyroxine 50 mcg',
    sig: 'Tab, daily AM',
    refillLine: '2 of 5 refills used',
    refillTone: 'muted',
    statusLabel: 'Standard',
    statusTone: 'good',
    lastFilled: '04/12/2026',
  },
  {
    id: 5,
    checked: true,
    name: 'Allison Park',
    mrn: '#002745',
    pharmacy: 'CVS Pharmacy #4291',
    drug: 'Tramadol 50 mg',
    sig: 'Cap, q6h PRN pain',
    refillLine: '—',
    refillTone: 'muted',
    statusLabel: 'Controlled — Schedule IV',
    statusTone: 'warn',
    lastFilled: '04/15/2026',
    subLine: 'Requires EPCS · last dispensed 17 days ago',
  },
  {
    id: 6,
    checked: true,
    name: 'Emily Foster',
    mrn: '#005544',
    pharmacy: 'Walmart Pharmacy · Round Rock',
    drug: 'Levothyroxine 75 mcg',
    sig: 'Tab, daily AM',
    refillLine: 'PA required (Synthroid brand)',
    refillTone: 'muted',
    statusLabel: 'Prior auth needed',
    statusTone: 'violet',
    lastFilled: '03/28/2026',
    subLine: 'Insurance prefers generic — switch?',
  },
  {
    id: 7,
    checked: true,
    name: 'James Brown',
    mrn: '#002188',
    pharmacy: 'HEB Pharmacy',
    drug: 'Hydrochlorothiazide 25 mg',
    sig: 'Tab, daily',
    refillLine: '4 of 5 refills used',
    refillTone: 'muted',
    statusLabel: 'Standard',
    statusTone: 'good',
    lastFilled: '04/05/2026',
  },
];

const PENDING_TOTAL = 14;
const PA_TOTAL = 8;
const CONTROLLED_TOTAL = 2;
// Figma shows "Approve & sign 6 selected" — 6 of 7 rows pre-checked.
const SELECTED_COUNT = 6;

type ErxRenewalProps = {
  readonly boot: BootContext;
};

export function ErxRenewal(_props: ErxRenewalProps): JSX.Element {
  const [subtab, setSubtab] = useState<Subtab>('renewals');
  const [filter, setFilter] = useState<Filter>('all');
  const [checked, setChecked] = useState<ReadonlySet<number>>(
    () => new Set(ROWS.filter((r) => r.checked).map((r) => r.id)),
  );

  const toggleChecked = (id: number): void => {
    setChecked((prev) => {
      const next = new Set(prev);
      if (next.has(id)) {
        next.delete(id);
      } else {
        next.add(id);
      }
      return next;
    });
  };

  return (
    <>
      <div className={styles.subtabs} role="tablist">
        {SUBTABS.map((t) => {
          const active = t.key === subtab;
          const cls = active ? `${styles.subtab} ${styles.subtabActive}` : styles.subtab;
          return (
            <button
              key={t.key}
              type="button"
              role="tab"
              aria-selected={active}
              className={cls}
              onClick={() => setSubtab(t.key)}
            >
              {t.label}
            </button>
          );
        })}
      </div>

      <header className={styles.pagehead}>
        <div className={styles.headInfo}>
          <span className={styles.title}>Refill Requests</span>
          <span className={styles.dot}>·</span>
          <span className={styles.metaLight}>
            {PENDING_TOTAL} pending &nbsp;·&nbsp; {PA_TOTAL} PA required &nbsp;·&nbsp; {CONTROLLED_TOTAL} controlled
          </span>
        </div>
        <div className={styles.headSpacer} />
        <button type="button" className={styles.btnGhost}>Deny</button>
        <button type="button" className={styles.btnPrimary}>
          <span className={styles.checkIc}>✓</span> Approve &amp; sign {SELECTED_COUNT} selected
        </button>
        <button type="button" className={styles.help} disabled title="Out of scope">? Help</button>
      </header>

      <div className={styles.filterBar}>
        {FILTERS.map((f) => {
          const active = f.key === filter;
          const cls = active ? `${styles.pill} ${styles.pillActive}` : styles.pill;
          const ctCls = active ? `${styles.pillCt} ${styles.pillCtActive}` : styles.pillCt;
          return (
            <button
              key={f.key}
              type="button"
              className={cls}
              onClick={() => setFilter(f.key)}
            >
              {f.label}
              <span className={ctCls}>{f.count}</span>
            </button>
          );
        })}
      </div>

      <main className={styles.content}>
        {ROWS.map((row) => (
          <RefillCard
            key={row.id}
            row={row}
            checked={checked.has(row.id)}
            onToggle={() => toggleChecked(row.id)}
          />
        ))}
      </main>
    </>
  );
}

type RefillCardProps = {
  readonly row: RefillRow;
  readonly checked: boolean;
  readonly onToggle: () => void;
};

function RefillCard({ row, checked, onToggle }: RefillCardProps): JSX.Element {
  const cbCls = checked ? `${styles.cb} ${styles.cbOn}` : styles.cb;
  const refillCls = row.refillTone === 'danger'
    ? `${styles.refills} ${styles.refillsDanger}`
    : styles.refills;
  const statusCls = (() => {
    if (row.statusTone === 'warn')   return `${styles.statusPill} ${styles.statusWarn}`;
    if (row.statusTone === 'violet') return `${styles.statusPill} ${styles.statusViolet}`;
    return `${styles.statusPill} ${styles.statusGood}`;
  })();

  return (
    <div className={styles.card}>
      <button
        type="button"
        className={cbCls}
        aria-pressed={checked}
        aria-label={`Select ${row.name}`}
        onClick={onToggle}
      >
        {checked && <span className={styles.cbCheck}>✓</span>}
      </button>

      <div className={styles.avatar} aria-hidden="true">
        {initials(row.name)}
      </div>

      <div className={styles.pt}>
        <div className={styles.ptLine}>
          <span className={styles.ptName}>{row.name}</span>
          <span className={styles.ptMrn}>{row.mrn}</span>
        </div>
        <div className={styles.ptPharm}>{row.pharmacy}</div>
      </div>

      <div className={styles.rx}>
        <span className={styles.rxIc} aria-hidden="true">💊</span>
        <div className={styles.rxBody}>
          <span className={styles.rxName}>{row.drug}</span>
          <span className={styles.rxSig}>{row.sig}</span>
          <span className={refillCls}>{row.refillLine}</span>
        </div>
      </div>

      <div className={styles.st}>
        <span className={statusCls}>{row.statusLabel}</span>
        <span className={styles.lastFilled}>Last filled: {row.lastFilled}</span>
        {row.subLine !== undefined && row.subLine !== '' && (
          <span className={styles.subLine}>
            <span className={styles.subStar} aria-hidden="true">✦</span> {row.subLine}
          </span>
        )}
      </div>

      <div className={styles.act}>
        <button type="button" className={styles.btnGhostSm}>Deny</button>
        <button type="button" className={styles.btnGhostSm}>
          Edit <span className={styles.caret}>▾</span>
        </button>
        <button type="button" className={styles.btnPrimarySm}>
          <span className={styles.checkIc}>✓</span> Approve &amp; sign
        </button>
      </div>
    </div>
  );
}

function initials(name: string): string {
  const parts = name.trim().split(/\s+/);
  const first = parts[0] ?? '';
  const last = parts.length > 1 ? (parts[parts.length - 1] ?? '') : '';
  return ((first[0] ?? '') + (last[0] ?? '')).toUpperCase();
}
