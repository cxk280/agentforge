// Batch Results — Figma "Screen 38 — Batch Results".
//
// Static demo port of the PHP-rendered mock previously at
// /interface/orders/copilot_batch_results.php. The original DB-driven page
// (KPIs, filter pills, per-patient result rows, sign-all/export/re-import
// actions) is preserved at copilot_batch_results.php.bak so a side-by-side
// screenshot diff stays possible.
//
// Visual values verified against Figma node 84:2 (kj4MWNr8mpjZ2wVg1PbS0F)
// on 2026-05-07. The navy top nav is rendered by the parent shell — this
// page only renders inside the #maimain iframe and so skips the nav per
// the migration brief.
//
// All data is hardcoded — no boot context lookups, no fetches.

import { useState } from 'react';
import type { BootContext } from '../../shared/lib/bootContext';
import styles from './BatchResults.module.css';

type FilterKey = 'all' | 'critical' | 'abnormal' | 'normal' | 'unsigned';
type FlagTone = 'critical' | 'abnormal' | 'normal';
type ValueTone = 'danger' | 'warn' | 'plain' | 'plainBold';
type DeltaTone = 'danger' | 'warn' | 'muted';

type Row = {
  readonly id: number;
  readonly checked: boolean;
  readonly name: string;
  readonly mrn: string;
  readonly test: string;
  readonly value: string;
  readonly valueTone: ValueTone;
  readonly ref: string;
  readonly delta: string;
  readonly deltaTone: DeltaTone;
  readonly drawn: string;
  readonly flag: string;
  readonly flagTone: FlagTone;
  readonly provider: string;
};

const RUN_ID = '2891';
const BATCH_RECEIVED = '04/30 06:14';
const PATIENT_COUNT = 47;

const KPI = {
  total: 47,
  normal: 38,
  abnormal: 6,
  critical: 3,
  signed: 12,
} as const;
const REVIEWED = 35;
const SIGN_ALL_COUNT = 47;

const TEMPLATE_OPTIONS: readonly string[] = [
  'A1C critical letter',
  'Lipid abnormal letter',
  'TSH critical letter',
  'Normal results letter',
];

const ROWS: readonly Row[] = [
  { id: 1, checked: true, name: 'Margaret Chen', mrn: '#004821', test: 'HbA1c',
    value: '7.9 %', valueTone: 'danger', ref: '< 7.0',
    delta: '↑ from 7.2%', deltaTone: 'danger',
    drawn: '04/30', flag: 'Critical', flagTone: 'critical', provider: 'Dr. Rivera' },
  { id: 2, checked: true, name: 'Emily Foster', mrn: '#005544', test: 'TSH',
    value: '12.4 mIU/L', valueTone: 'danger', ref: '0.4–4.0',
    delta: '↑↑ from 4.8', deltaTone: 'danger',
    drawn: '04/30', flag: 'Critical', flagTone: 'critical', provider: 'Dr. Rivera' },
  { id: 3, checked: true, name: 'Linda Martinez', mrn: '#003918', test: 'LDL',
    value: '142 mg/dL', valueTone: 'warn', ref: '< 100',
    delta: '↑ from 98', deltaTone: 'warn',
    drawn: '04/30', flag: 'Abnormal', flagTone: 'abnormal', provider: 'Dr. Rivera' },
  { id: 4, checked: true, name: 'Robert Hayes', mrn: '#001821', test: 'Glucose (fasting)',
    value: '108 mg/dL', valueTone: 'warn', ref: '70–99',
    delta: '↑ from 98', deltaTone: 'warn',
    drawn: '04/30', flag: 'Abnormal', flagTone: 'abnormal', provider: 'NP Jones' },
  { id: 5, checked: false, name: 'David Kim', mrn: '#006102', test: 'BUN',
    value: '24 mg/dL', valueTone: 'warn', ref: '7–20',
    delta: '↑ from 18', deltaTone: 'warn',
    drawn: '04/30', flag: 'Abnormal', flagTone: 'abnormal', provider: 'Dr. Chen' },
  { id: 6, checked: true, name: 'Allison Park', mrn: '#002745', test: 'Hgb',
    value: '9.8 g/dL', valueTone: 'danger', ref: '12.0–15.5',
    delta: '↓ from 11.2', deltaTone: 'danger',
    drawn: '04/30', flag: 'Critical', flagTone: 'critical', provider: 'Dr. Chen' },
  { id: 7, checked: false, name: 'Carlos Mendez', mrn: '#004102', test: 'HbA1c',
    value: '6.4 %', valueTone: 'plainBold', ref: '< 7.0',
    delta: '→ stable', deltaTone: 'muted',
    drawn: '04/30', flag: 'Normal', flagTone: 'normal', provider: 'Dr. Chen' },
  { id: 8, checked: true, name: 'Helen Garcia', mrn: '#003021', test: 'UA — leuk esterase',
    value: '+ Positive', valueTone: 'warn', ref: 'Negative',
    delta: 'New finding', deltaTone: 'warn',
    drawn: '04/30', flag: 'Abnormal', flagTone: 'abnormal', provider: 'Dr. Patel' },
  { id: 9, checked: false, name: 'James Brown', mrn: '#002188', test: 'BMP',
    value: 'Within range', valueTone: 'plainBold', ref: '—',
    delta: '—', deltaTone: 'muted',
    drawn: '04/30', flag: 'Normal', flagTone: 'normal', provider: 'Dr. Chen' },
  { id: 10, checked: false, name: 'Marcus Webb', mrn: '#004411', test: 'Lipid panel',
    value: 'Within range', valueTone: 'plainBold', ref: '—',
    delta: '—', deltaTone: 'muted',
    drawn: '04/30', flag: 'Normal', flagTone: 'normal', provider: 'Dr. Rivera' },
  { id: 11, checked: false, name: 'Mike Tan', mrn: '#003456', test: 'TSH',
    value: '2.1 mIU/L', valueTone: 'plainBold', ref: '0.4–4.0',
    delta: '→ stable', deltaTone: 'muted',
    drawn: '04/30', flag: 'Normal', flagTone: 'normal', provider: 'Dr. Chen' },
  { id: 12, checked: false, name: 'Sarah Wilson', mrn: '#005891', test: 'HbA1c',
    value: '5.6 %', valueTone: 'plainBold', ref: '< 7.0',
    delta: '→ stable', deltaTone: 'muted',
    drawn: '04/30', flag: 'Normal', flagTone: 'normal', provider: 'Dr. Rivera' },
  { id: 13, checked: false, name: 'Soo-Yeon Kim', mrn: '#005903', test: 'CBC',
    value: 'Within range', valueTone: 'plainBold', ref: '—',
    delta: '—', deltaTone: 'muted',
    drawn: '04/30', flag: 'Normal', flagTone: 'normal', provider: 'Dr. Patel' },
];

type BatchResultsProps = {
  readonly boot: BootContext;
};

export function BatchResults(_props: BatchResultsProps): JSX.Element {
  const [filter, setFilter] = useState<FilterKey>('all');
  const [template, setTemplate] = useState<string>(TEMPLATE_OPTIONS[0] ?? '');

  const reviewedPct = KPI.total > 0 ? Math.round((REVIEWED / KPI.total) * 100) : 0;
  const unsignedCount = KPI.total - KPI.signed;

  const visibleRows = ROWS.filter((r) => {
    if (filter === 'all') { return true; }
    if (filter === 'critical') { return r.flagTone === 'critical'; }
    if (filter === 'abnormal') { return r.flagTone === 'abnormal'; }
    if (filter === 'normal')   { return r.flagTone === 'normal'; }
    return r.checked;
  });

  return (
    <>
      <header className={styles.pagehead}>
        <div className={styles.titleBlock}>
          <span className={styles.title}>Batch Results</span>
          <span className={styles.dot}>·</span>
          <span className={styles.metaLight}>
            Quest run #{RUN_ID} · {PATIENT_COUNT} patients · received {BATCH_RECEIVED}
          </span>
        </div>
        <div className={styles.spacer} />
        <button type="button" className={`${styles.btn} ${styles.btnGhost}`}>⤓ Export</button>
        <button type="button" className={`${styles.btn} ${styles.btnGhost}`}>⬆ Re-import</button>
        <button type="button" className={`${styles.btn} ${styles.btnPrimary}`}>
          Sign all ({SIGN_ALL_COUNT})
        </button>
        <span className={styles.helpPill}>? Help</span>
      </header>

      <main className={styles.content}>
        <section className={styles.kpiStrip}>
          <div className={styles.kpiCell}>
            <span className={styles.kpiLbl}>Total</span>
            <span className={styles.kpiVal}>{KPI.total}</span>
          </div>
          <div className={styles.kpiDivider} />
          <div className={styles.kpiCell}>
            <span className={styles.kpiLbl}>Normal</span>
            <span className={`${styles.kpiVal} ${styles.kpiGreen}`}>{KPI.normal}</span>
          </div>
          <div className={styles.kpiDivider} />
          <div className={styles.kpiCell}>
            <span className={styles.kpiLbl}>Abnormal</span>
            <span className={`${styles.kpiVal} ${styles.kpiOrange}`}>{KPI.abnormal}</span>
          </div>
          <div className={styles.kpiDivider} />
          <div className={styles.kpiCell}>
            <span className={styles.kpiLbl}>Critical</span>
            <span className={`${styles.kpiVal} ${styles.kpiRed}`}>{KPI.critical}</span>
          </div>
          <div className={styles.kpiDivider} />
          <div className={styles.kpiCellWide}>
            <div className={styles.kpiSignedTop}>
              <div>
                <span className={styles.kpiLbl}>Already signed</span>
                <span className={styles.kpiVal}>{KPI.signed}</span>
              </div>
              <div className={styles.progressWrap}>
                <span className={styles.progress}>
                  <i style={{ width: `${reviewedPct}%` }} />
                </span>
                <span className={styles.progressSub}>
                  {REVIEWED} / {KPI.total} reviewed
                </span>
              </div>
            </div>
          </div>
        </section>

        <section className={styles.filterBar}>
          <FilterPill k="all"      label="All"      count={KPI.total}    current={filter} onSelect={setFilter} />
          <FilterPill k="critical" label="Critical" count={KPI.critical} current={filter} onSelect={setFilter} />
          <FilterPill k="abnormal" label="Abnormal" count={KPI.abnormal} current={filter} onSelect={setFilter} />
          <FilterPill k="normal"   label="Normal"   count={KPI.normal}   current={filter} onSelect={setFilter} />
          <FilterPill k="unsigned" label="Unsigned" count={unsignedCount} current={filter} onSelect={setFilter} />
          <div className={styles.filterRight}>
            <span className={styles.filterLbl}>Apply template</span>
            <span className={styles.dropdown}>
              <select
                value={template}
                onChange={(e) => setTemplate(e.target.value)}
                className={styles.dropdownSelect}
              >
                {TEMPLATE_OPTIONS.map((t) => (
                  <option key={t} value={t}>{t}</option>
                ))}
              </select>
              <span className={styles.caret}>▾</span>
            </span>
          </div>
        </section>

        <section className={styles.tableWrap}>
          <table className={styles.table}>
            <thead>
              <tr>
                <th className={styles.cb}><span className={`${styles.cbBox} ${styles.cbBoxOn}`} aria-hidden>–</span></th>
                <th>PATIENT</th>
                <th>MRN</th>
                <th>TEST</th>
                <th>VALUE</th>
                <th>REF RANGE</th>
                <th>DELTA</th>
                <th>DRAWN</th>
                <th>FLAG</th>
                <th>PROVIDER</th>
                <th className={styles.act} />
              </tr>
            </thead>
            <tbody>
              {visibleRows.length === 0 && (
                <tr>
                  <td colSpan={11} className={styles.emptyRow}>
                    No results match the current filter.
                  </td>
                </tr>
              )}
              {visibleRows.map((r) => (
                <tr key={r.id}>
                  <td className={styles.cb}>
                    <span
                      className={r.checked ? `${styles.cbBox} ${styles.cbBoxOn}` : styles.cbBox}
                      aria-hidden
                    >
                      {r.checked ? '✓' : ''}
                    </span>
                  </td>
                  <td className={styles.bold}>{r.name}</td>
                  <td className={styles.mrn}>{r.mrn}</td>
                  <td className={styles.mutedSoft}>{r.test}</td>
                  <td>
                    <span className={styles[`v_${r.valueTone}`] ?? ''}>{r.value}</span>
                  </td>
                  <td className={styles.mutedSoft}>{r.ref}</td>
                  <td>
                    <span className={styles[`d_${r.deltaTone}`] ?? ''}>{r.delta}</span>
                  </td>
                  <td className={styles.mutedSoft}>{r.drawn}</td>
                  <td>
                    <span className={`${styles.pill} ${styles[`pill_${r.flagTone}`] ?? ''}`}>
                      {r.flag}
                    </span>
                  </td>
                  <td className={styles.mutedSoft}>{r.provider}</td>
                  <td className={styles.act}>
                    <span className={styles.kebab} aria-hidden>⋯</span>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </section>
      </main>
    </>
  );
}

type FilterPillProps = {
  readonly k: FilterKey;
  readonly label: string;
  readonly count: number;
  readonly current: FilterKey;
  readonly onSelect: (k: FilterKey) => void;
};

function FilterPill({ k, label, count, current, onSelect }: FilterPillProps): JSX.Element {
  const active = k === current;
  const cls = active
    ? `${styles.pillBtn} ${styles.pillBtnActive}`
    : styles.pillBtn;
  const ctCls = active
    ? `${styles.pillCt} ${styles.pillCtActive}`
    : styles.pillCt;
  return (
    <button type="button" className={cls} onClick={() => onSelect(k)}>
      {label}
      <span className={ctCls}>{count}</span>
    </button>
  );
}
