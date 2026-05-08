// Prescription Report — Figma "Screen 44 — Prescription Report" (node 92:2,
// fileKey kj4MWNr8mpjZ2wVg1PbS0F). 1:1 port of the PHP-rendered mock at
// /interface/reports/copilot_prescription_report.php.
//
// Layout: left sub-nav (Reports categories), then a main column with a
// PageHead (title + meta + Export CSV / Run buttons), a 5-tile KPI strip,
// and a 2-column grid: "Top 12 Medications" bar list (488px) + "Recent
// Prescriptions" filter-pill table (688px). Navy top-nav lives in the
// outer shell, not here.
//
// Demo data is hardcoded to match Figma exactly — same numbers, same
// drug names, same tab/row text.

import { useState } from 'react';
import type { BootContext } from '../../shared/lib/bootContext';
import styles from './PrescriptionReport.module.css';

type SidebarItem = {
  readonly label: string;
  readonly active?: boolean;
};

type SidebarGroup = {
  readonly label: string;
  readonly items: readonly SidebarItem[];
};

const SIDEBAR: readonly SidebarGroup[] = [
  {
    label: 'Clinical',
    items: [
      { label: 'Patient List' },
      { label: 'Prescriptions', active: true },
      { label: 'Lab Trends' },
      { label: 'Quality Measures' },
      { label: 'Immunizations' },
      { label: 'Encounters' },
    ],
  },
  {
    label: 'Financial',
    items: [
      { label: 'Daily Cash' },
      { label: 'Aging' },
      { label: 'Payer Mix' },
      { label: 'Collections' },
    ],
  },
  {
    label: 'Operations',
    items: [
      { label: 'Visit Volume' },
      { label: 'Provider Productivity' },
      { label: 'No-shows' },
    ],
  },
  {
    label: 'Electronic',
    items: [
      { label: 'Submissions' },
      { label: 'CCDA Exports' },
      { label: 'HIE Sync' },
      { label: 'Public Health' },
    ],
  },
];

type Kpi = {
  readonly label: string;
  readonly value: string;
  readonly tone?: 'red' | 'green' | 'orange';
};

const KPIS: readonly Kpi[] = [
  { label: 'Total Rx',         value: '4,128' },
  { label: 'Avg per provider', value: '687' },
  { label: 'Controlled',       value: '62',  tone: 'red' },
  { label: 'Generic %',        value: '94%', tone: 'green' },
  { label: 'Refills denied',   value: '41',  tone: 'orange' },
];

type TopMed = {
  readonly drug: string;
  readonly indication: string;
  readonly count: number;
};

// Counts come straight from Figma node 92:90–92:158. Bar widths are
// computed at render time as count / TOP_MAX * 100.
const TOP_MEDS: readonly TopMed[] = [
  { drug: 'Lisinopril 10 mg',     indication: 'HTN',           count: 428 },
  { drug: 'Atorvastatin 40 mg',   indication: 'Hyperlipidemia', count: 376 },
  { drug: 'Metformin 1000 mg',    indication: 'T2DM',          count: 341 },
  { drug: 'Levothyroxine 50 mcg', indication: 'Hypothyroid',   count: 287 },
  { drug: 'Albuterol HFA',        indication: 'Asthma/COPD',   count: 244 },
  { drug: 'Amlodipine 5 mg',      indication: 'HTN',           count: 228 },
  { drug: 'Omeprazole 20 mg',     indication: 'GERD',          count: 201 },
  { drug: 'Sertraline 50 mg',     indication: 'Depression',    count: 187 },
  { drug: 'Metoprolol 50 mg',     indication: 'HTN/CAD',       count: 164 },
  { drug: 'HCTZ 25 mg',           indication: 'HTN',           count: 142 },
  { drug: 'Gabapentin 300 mg',    indication: 'Neuropathy',    count: 124 },
  { drug: 'Vitamin D 50,000 IU',  indication: 'Deficiency',    count: 118 },
];
const TOP_MAX = TOP_MEDS[0]?.count ?? 1;

type RecentTab = 'all' | 'controlled' | 'generic' | 'brand' | 'denied';

const TABS: readonly { id: RecentTab; label: string }[] = [
  { id: 'all',        label: 'All' },
  { id: 'controlled', label: 'Controlled' },
  { id: 'generic',    label: 'Generic' },
  { id: 'brand',      label: 'Brand-only' },
  { id: 'denied',     label: 'Denied' },
];

type RxTag = 'ctl' | 'brand' | null;

type RecentRow = {
  readonly time: string;
  readonly patient: string;
  readonly drug: string;
  readonly note: string;
  readonly provider: string;
  readonly tag: RxTag;
};

export type PrescriptionPayload = {
  readonly rows: readonly RecentRow[];
  readonly total: number;
};

// Every row, in order, taken from Figma nodes 92:176–92:264.
const RECENT: readonly RecentRow[] = [
  { time: '04/30 14:22', patient: 'Margaret Chen', drug: 'Metformin 1000 mg',    note: 'Refill x90d',           provider: 'Dr. Rivera', tag: null },
  { time: '04/30 13:14', patient: 'Allison Park',  drug: 'Tramadol 50 mg',       note: 'New · Schedule IV',     provider: 'Dr. Chen',   tag: 'ctl' },
  { time: '04/30 11:45', patient: 'Linda Martinez',drug: 'Lisinopril 10 mg',     note: 'Renewal',               provider: 'Dr. Rivera', tag: null },
  { time: '04/30 10:22', patient: 'David Kim',     drug: 'Atorvastatin 40 mg',   note: 'Renewal',               provider: 'Dr. Chen',   tag: null },
  { time: '04/30 09:30', patient: 'Carlos Mendez', drug: 'Levothyroxine 50 mcg', note: 'Refill x90d',           provider: 'Dr. Chen',   tag: null },
  { time: '04/30 08:45', patient: 'Emily Foster',  drug: 'Synthroid 75 mcg',     note: 'New · brand requested', provider: 'Dr. Rivera', tag: 'brand' },
  { time: '04/29 16:20', patient: 'James Brown',   drug: 'HCTZ 25 mg',           note: 'Refill',                provider: 'Dr. Chen',   tag: null },
  { time: '04/29 15:10', patient: 'Helen Garcia',  drug: 'Sertraline 50 mg',     note: 'New',                   provider: 'Dr. Patel',  tag: null },
  { time: '04/29 14:30', patient: 'Marcus Webb',   drug: 'Lorazepam 0.5 mg',     note: 'New · Schedule IV',     provider: 'NP Jones',   tag: 'ctl' },
  { time: '04/29 12:15', patient: 'Mike Tan',      drug: 'Gabapentin 300 mg',    note: 'Renewal',               provider: 'Dr. Chen',   tag: null },
  { time: '04/29 10:50', patient: 'Robert Hayes',  drug: 'Omeprazole 20 mg',     note: 'Refill',                provider: 'NP Jones',   tag: null },
  { time: '04/29 09:30', patient: 'Soo-Yeon Kim',  drug: 'Vitamin D 50,000 IU',  note: 'New',                   provider: 'Dr. Patel',  tag: null },
];

type PrescriptionReportProps = {
  readonly boot: BootContext;
  readonly payload: PrescriptionPayload;
};

export function PrescriptionReport({ payload }: PrescriptionReportProps): JSX.Element {
  const [activeTab, setActiveTab] = useState<RecentTab>('all');
  const liveRows = payload.rows;
  const recent: readonly RecentRow[] = liveRows.length > 0 ? liveRows : RECENT;

  return (
    <div className={styles.shell}>
      <aside className={styles.sidebar}>
        <div className={styles.sideHeader}>REPORTS</div>
        {SIDEBAR.map((group) => (
          <div key={group.label} className={styles.sideGroup}>
            <div className={styles.sideGroupLabel}>{group.label}</div>
            {group.items.map((item) => {
              const cls = item.active
                ? `${styles.sideItem} ${styles.sideItemActive}`
                : styles.sideItem;
              return (
                <a key={item.label} className={cls} href="#" tabIndex={0}>
                  {item.label}
                </a>
              );
            })}
          </div>
        ))}
      </aside>

      <div className={styles.main}>
        <header className={styles.pageHead}>
          <div className={styles.titleRow}>
            <span className={styles.title}>Prescription Report</span>
            <span className={styles.metaDot}>·</span>
            <span className={styles.meta}>
              Q1 2026 · 4,128 Rx written · 62 controlled substances
            </span>
          </div>
          <div className={styles.headSpacer} />
          <button className={styles.exportBtn} type="button">
            <span aria-hidden="true">⬇</span>
            <span>Export CSV</span>
          </button>
          <button className={styles.runBtn} type="button">Run</button>
        </header>

        <main className={styles.content}>
          <div className={styles.kpiStrip}>
            {KPIS.map((kpi, i) => (
              <div key={kpi.label} className={styles.kpiTile}>
                <span className={styles.kpiLabel}>{kpi.label}</span>
                <span
                  className={
                    kpi.tone
                      ? `${styles.kpiVal} ${styles[`kpiVal_${kpi.tone}`]}`
                      : styles.kpiVal
                  }
                >
                  {kpi.value}
                </span>
                {i < KPIS.length - 1 && <div className={styles.kpiDivider} />}
              </div>
            ))}
          </div>

          <div className={styles.cols}>
            <section className={styles.medsPanel}>
              <div className={styles.medsHead}>TOP 12 MEDICATIONS</div>
              <div className={styles.medsSub}>By Rx volume, Q1 2026</div>
              <ol className={styles.medsList}>
                {TOP_MEDS.map((m, i) => {
                  const w = Math.round((m.count / TOP_MAX) * 100);
                  return (
                    <li key={m.drug} className={styles.medsRow}>
                      <span className={styles.medsNum}>{i + 1}.</span>
                      <div className={styles.medsBody}>
                        <span className={styles.medsName}>{m.drug}</span>
                        <span className={styles.medsInd}>{m.indication}</span>
                        <span className={styles.medsBar}>
                          <i style={{ width: `${w}%` }} />
                        </span>
                      </div>
                      <span className={styles.medsCount}>{m.count}</span>
                    </li>
                  );
                })}
              </ol>
            </section>

            <section className={styles.recentPanel}>
              <div className={styles.recentHead}>RECENT PRESCRIPTIONS</div>
              <div className={styles.tabs} role="tablist">
                {TABS.map((t) => {
                  const active = t.id === activeTab;
                  const cls = active
                    ? `${styles.tab} ${styles.tabActive}`
                    : styles.tab;
                  return (
                    <button
                      key={t.id}
                      role="tab"
                      type="button"
                      aria-selected={active}
                      className={cls}
                      onClick={() => setActiveTab(t.id)}
                    >
                      {t.label}
                    </button>
                  );
                })}
              </div>
              <div className={styles.tabsRule} />

              <table className={styles.table}>
                <tbody>
                  {recent.map((r, i) => (
                    <tr
                      key={`${r.time}-${r.patient}`}
                      className={i % 2 === 1 ? styles.rowAlt : undefined}
                    >
                      <td className={styles.cTime}>{r.time}</td>
                      <td className={styles.cPat}>{r.patient}</td>
                      <td className={styles.cDrug}>{r.drug}</td>
                      <td className={styles.cNote}>{r.note}</td>
                      <td className={styles.cProv}>{r.provider}</td>
                      <td className={styles.cTag}>
                        {r.tag === 'ctl' && (
                          <span className={`${styles.pill} ${styles.pillCtl}`}>Ctl</span>
                        )}
                        {r.tag === 'brand' && (
                          <span className={`${styles.pill} ${styles.pillBrand}`}>Brand</span>
                        )}
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </section>
          </div>
        </main>
      </div>
    </div>
  );
}
