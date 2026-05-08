// PatientResults — Figma "Screen 36 — Patient Results".
//
// Static demo port of /interface/orders/copilot_results.php. The PHP wrapper
// owns the navy top nav, demographics banner and tab strip; this component
// renders only the page body — page header (counts + actions), filter strip
// (count pills + view toggle) and the timeline list grouped by month.
//
// Visual values verified against Figma file kj4MWNr8mpjZ2wVg1PbS0F node 82:2
// on 2026-05-07. Reference image at
// frontend/.fidelity-references/patient_results-figma-2026-05-07.png.

import { useState } from 'react';
import type { BootContext } from '../../shared/lib/bootContext';
import styles from './PatientResults.module.css';

type FilterKey = 'all' | 'labs' | 'imaging' | 'procedures' | 'documents' | 'abnormal';
type ViewMode = 'timeline' | 'table' | 'trends';
type ValueTone = 'plain' | 'warn' | 'danger';
type FlagTone = 'danger' | 'warn';
type Bucket = 'labs' | 'imaging' | 'procedures' | 'documents';

type ResultRow = {
  readonly date: string;
  readonly bucket: Bucket;
  readonly icon: '🧪' | '🩻' | '📄';
  readonly test: string;
  readonly src: string;
  readonly value: string;
  readonly valueTone: ValueTone;
  readonly delta: string;
  readonly isAbnormal: boolean;
  readonly flagLabel?: string | undefined;
  readonly flagTone?: FlagTone | undefined;
};

type MonthGroup = {
  readonly label: string;
  readonly rows: readonly ResultRow[];
};

export type ResultsPayload = {
  readonly months: readonly MonthGroup[];
  readonly counts: {
    readonly all: number;
    readonly labs: number;
    readonly imaging: number;
    readonly procedures: number;
    readonly documents: number;
    readonly abnormal: number;
  };
};

const MONTHS: readonly MonthGroup[] = [
  {
    label: 'April 2026',
    rows: [
      {
        date: '04/30', bucket: 'labs', icon: '🧪', test: 'HbA1c',
        src: 'Quest · Dr. Rivera',
        value: '7.9%', valueTone: 'danger', delta: '↑ from 7.2%',
        isAbnormal: true, flagLabel: 'Critical', flagTone: 'danger',
      },
      {
        date: '04/30', bucket: 'labs', icon: '🧪', test: 'CMP',
        src: 'Quest · Dr. Rivera',
        value: 'Within range', valueTone: 'plain', delta: '—',
        isAbnormal: false,
      },
      {
        date: '04/30', bucket: 'labs', icon: '🧪', test: 'Lipid panel',
        src: 'Quest · Dr. Rivera',
        value: 'LDL 142 (↑)', valueTone: 'warn', delta: '↑ from 98',
        isAbnormal: true, flagLabel: 'Abnormal', flagTone: 'warn',
      },
      {
        date: '04/12', bucket: 'labs', icon: '🧪', test: 'TSH',
        src: 'Quest · Dr. Rivera',
        value: '2.4 mIU/L', valueTone: 'plain', delta: 'Stable',
        isAbnormal: false,
      },
      {
        date: '04/12', bucket: 'labs', icon: '🧪', test: 'Microalbumin',
        src: 'Quest · Dr. Rivera',
        value: '32 mg/g (↑)', valueTone: 'warn', delta: '↑ from 24',
        isAbnormal: true, flagLabel: 'Abnormal', flagTone: 'warn',
      },
    ],
  },
  {
    label: 'February 2026',
    rows: [
      {
        date: '02/18', bucket: 'imaging', icon: '🩻', test: 'Mammogram',
        src: 'RFM Imaging · Dr. Park',
        value: 'BIRADS 2', valueTone: 'plain', delta: 'Benign findings',
        isAbnormal: false,
      },
      {
        date: '02/18', bucket: 'labs', icon: '🧪', test: 'Annual labs panel',
        src: 'Quest · Dr. Rivera',
        value: 'Within range', valueTone: 'plain', delta: '—',
        isAbnormal: false,
      },
    ],
  },
  {
    label: 'November 2025',
    rows: [
      {
        date: '11/15', bucket: 'labs', icon: '🧪', test: 'HbA1c',
        src: 'Quest · Dr. Rivera',
        value: '7.2%', valueTone: 'warn', delta: '↑ from 6.9%',
        isAbnormal: true, flagLabel: 'Abnormal', flagTone: 'warn',
      },
      {
        date: '11/15', bucket: 'labs', icon: '🧪', test: 'BMP',
        src: 'Quest · Dr. Rivera',
        value: 'Cr 1.04 (high-normal)', valueTone: 'plain', delta: '—',
        isAbnormal: false,
      },
    ],
  },
];

type PillSpec = {
  readonly key: FilterKey;
  readonly label: string;
  readonly count: number;
};

// Counts match the Figma header copy ("18 lab results · 5 imaging studies · 4
// documents") and the filter-pill badges (All 27 · Labs 18 · Imaging 5 ·
// Procedures 2 · Documents 2 · Abnormal 6).
const PILLS: readonly PillSpec[] = [
  { key: 'all',        label: 'All',        count: 27 },
  { key: 'labs',       label: 'Labs',       count: 18 },
  { key: 'imaging',    label: 'Imaging',    count: 5 },
  { key: 'procedures', label: 'Procedures', count: 2 },
  { key: 'documents',  label: 'Documents',  count: 2 },
  { key: 'abnormal',   label: 'Abnormal',   count: 6 },
];

const COUNTS_LINE = '18 lab results · 5 imaging studies · 4 documents';

type PatientResultsProps = {
  readonly boot: BootContext;
  readonly payload: ResultsPayload;
};

export function PatientResults({ payload }: PatientResultsProps): JSX.Element {
  const [filter, setFilter] = useState<FilterKey>('all');
  const [view, setView] = useState<ViewMode>('timeline');

  // Live months from the wrapper, demo otherwise.
  const liveMonths = payload.months;
  const sourceMonths: readonly MonthGroup[] = liveMonths.length > 0 ? liveMonths : MONTHS;

  // Apply the active filter to the source data.
  const filteredMonths: readonly MonthGroup[] = sourceMonths
    .map((m) => ({
      label: m.label,
      rows: m.rows.filter((r) => matchesFilter(r, filter)),
    }))
    .filter((m) => m.rows.length > 0);

  // Live pill counts when present, otherwise the existing 27/18/5/2/2/6 demo.
  const livePills: readonly PillSpec[] = liveMonths.length > 0
    ? [
        { key: 'all',        label: 'All',        count: payload.counts.all },
        { key: 'labs',       label: 'Labs',       count: payload.counts.labs },
        { key: 'imaging',    label: 'Imaging',    count: payload.counts.imaging },
        { key: 'procedures', label: 'Procedures', count: payload.counts.procedures },
        { key: 'documents',  label: 'Documents',  count: payload.counts.documents },
        { key: 'abnormal',   label: 'Abnormal',   count: payload.counts.abnormal },
      ]
    : PILLS;
  const liveCountsLine = liveMonths.length > 0
    ? `${payload.counts.labs} lab results · ${payload.counts.imaging} imaging studies · ${payload.counts.documents} documents`
    : COUNTS_LINE;

  return (
    <>
      <header className={styles.pageHead}>
        <div className={styles.pageHeadInfo}>
          <span className={styles.pageTitle}>Results</span>
          <span className={styles.dot}>·</span>
          <span className={styles.metaLight}>{liveCountsLine}</span>
        </div>
        <button type="button" className={styles.btnPrimary}>+ Order labs / imaging</button>
        <button type="button" className={styles.btnGhost}>? Help</button>
      </header>

      <div className={styles.filterDivider} />

      <div className={styles.filter}>
        <div className={styles.pills}>
          {livePills.map((p) => {
            const active = filter === p.key;
            const pillCls = active
              ? `${styles.pill} ${styles.pillActive}`
              : styles.pill;
            const ctCls = active
              ? `${styles.ct} ${styles.ctActive}`
              : styles.ct;
            return (
              <button
                key={p.key}
                type="button"
                className={pillCls}
                onClick={() => setFilter(p.key)}
                aria-pressed={active}
              >
                {p.label}
                <span className={ctCls}>{p.count}</span>
              </button>
            );
          })}
        </div>
        <div className={styles.right}>
          <span className={styles.viewLbl}>View</span>
          <div className={styles.viewToggle} role="tablist">
            <ViewItem mode="timeline" current={view} onSelect={setView}>Timeline</ViewItem>
            <ViewItem mode="table"    current={view} onSelect={setView}>Table</ViewItem>
            <ViewItem mode="trends"   current={view} onSelect={setView}>Trends</ViewItem>
          </div>
        </div>
      </div>

      <div className={styles.viewDivider} />

      <main className={styles.body}>
        {view === 'trends' ? (
          <div className={styles.trendsCta}>
            Trended-value charts live on the Lab Overview page.&nbsp;
            <a href="#">Open Lab Overview →</a>
          </div>
        ) : filteredMonths.length === 0 ? (
          <div className={styles.empty}>No results match the current filter.</div>
        ) : view === 'table' ? (
          <table className={styles.table}>
            <thead>
              <tr>
                <th>Date</th>
                <th>Test</th>
                <th>Source</th>
                <th>Value</th>
                <th>Reference</th>
                <th>Flag</th>
                <th></th>
              </tr>
            </thead>
            <tbody>
              {filteredMonths.flatMap((m) => m.rows.map((r, i) => (
                <tr key={`${m.label}-${i}`}>
                  <td>{r.date}</td>
                  <td>{r.test}</td>
                  <td>{r.src}</td>
                  <td className={valueClass(r.valueTone, styles)}>{r.value}</td>
                  <td>{r.delta}</td>
                  <td>{r.flagLabel !== undefined ? renderFlag(r.flagLabel, r.flagTone) : null}</td>
                  <td><a className={styles.viewLink} href="#">View →</a></td>
                </tr>
              )))}
            </tbody>
          </table>
        ) : (
          <div className={styles.timeline}>
            {filteredMonths.map((m) => (
              <section key={m.label}>
                <h3 className={styles.tlMonth}>{m.label}</h3>
                <div className={styles.tlRows}>
                  {m.rows.map((r, i) => (
                    <div key={`${m.label}-${i}`} className={styles.tlRow}>
                      <span className={styles.tlDate}>{r.date}</span>
                      <span className={styles.tlIcon}>{r.icon}</span>
                      <div className={styles.tlBodyCol}>
                        <span className={styles.tlName}>{r.test}</span>
                        <span className={styles.tlSrc}>{r.src}</span>
                      </div>
                      <div className={styles.tlVal}>
                        <span className={`${styles.tlNum} ${valueClass(r.valueTone, styles)}`}>{r.value}</span>
                        <span className={styles.tlDelta}>{r.delta}</span>
                      </div>
                      <div className={styles.tlPillCell}>
                        {r.flagLabel !== undefined && renderFlag(r.flagLabel, r.flagTone)}
                      </div>
                      <a className={styles.viewLink} href="#">View →</a>
                      <button type="button" className={styles.kebab} disabled title="Coming soon">⋯</button>
                    </div>
                  ))}
                </div>
              </section>
            ))}
          </div>
        )}
      </main>
    </>
  );
}

type ViewItemProps = {
  readonly mode: ViewMode;
  readonly current: ViewMode;
  readonly onSelect: (m: ViewMode) => void;
  readonly children: React.ReactNode;
};

function ViewItem({ mode, current, onSelect, children }: ViewItemProps): JSX.Element {
  const active = mode === current;
  const cls = active
    ? `${styles.viewItem} ${styles.viewItemActive}`
    : styles.viewItem;
  return (
    <button
      type="button"
      className={cls}
      role="tab"
      aria-selected={active}
      onClick={() => onSelect(mode)}
    >
      {children}
    </button>
  );
}

function matchesFilter(row: ResultRow, filter: FilterKey): boolean {
  if (filter === 'all') return true;
  if (filter === 'abnormal') return row.isAbnormal;
  return row.bucket === filter;
}

function valueClass(tone: ValueTone, s: typeof styles): string {
  if (tone === 'danger') return s['toneDanger'] ?? '';
  if (tone === 'warn') return s['toneWarn'] ?? '';
  return '';
}

function renderFlag(label: string, tone: FlagTone | undefined): JSX.Element {
  const cls = tone === 'danger'
    ? `${styles.flag} ${styles.flagDanger}`
    : `${styles.flag} ${styles.flagWarn}`;
  return <span className={cls}>{label}</span>;
}
