// Dashboard — Figma "Screen 11 — Dashboard". Renders the Patient Dashboard
// body that lives inside the patient chart iframe: 4 vital metric cards
// (BP / A1C / LDL / BMI) + 3 summary panels (Allergies, Active Problems,
// Current Medications) + 2 detail panels (Recent Lab Results, Recent Visits).
//
// This is a 1:1 port of the PHP-rendered mock previously at
// /interface/patient_file/summary/copilot_dashboard.php. State is hardcoded
// demo data matching the Figma reference exactly. The navy top nav, the
// patient demographics banner (header2), and the patient navtab strip
// (Dashboard / History / Co-Pilot / …) are owned by the parent shell — this
// component renders ONLY the body.

import type { BootContext } from '../../shared/lib/bootContext';
import styles from './Dashboard.module.css';

type Vital = {
  readonly label: string;
  readonly value: string;
  readonly unit: string;
  readonly trend: string;
  readonly trendArrow: '↓' | '↑';
  readonly trendDir: 'down' | 'up';
};

type ListItem = {
  readonly icon: string;
  readonly tone: 'alert' | 'note' | 'med';
  readonly name: string;
  readonly sub: string;
};

type LabRow = {
  readonly test: string;
  readonly value: string;
  readonly status: 'normal' | 'high';
  readonly range: string;
  readonly date: string;
};

type Visit = {
  readonly title: string;
  readonly sub: string;
};

const VITALS: readonly Vital[] = [
  { label: 'BP',  value: '130/82', unit: 'mmHg',  trend: 'from 145/90', trendArrow: '↓', trendDir: 'down' },
  { label: 'A1C', value: '7.9%',   unit: 'current', trend: 'from 7.2%',   trendArrow: '↑', trendDir: 'up'   },
  { label: 'LDL', value: '98',     unit: 'mg/dL', trend: 'from 112',    trendArrow: '↓', trendDir: 'down' },
  { label: 'BMI', value: '29.4',   unit: 'kg/m²', trend: 'from 28.8',   trendArrow: '↑', trendDir: 'up'   },
];

const ALLERGIES: readonly ListItem[] = [
  { icon: '⚠', tone: 'alert', name: 'Penicillin',                 sub: 'Mild — itching, rash' },
  { icon: '⚠', tone: 'alert', name: 'Sulfa drugs',                sub: 'Mild — skin reaction' },
  { icon: '+', tone: 'note',  name: 'No food allergies recorded', sub: 'Reviewed 02/18/2026' },
];

const PROBLEMS: readonly ListItem[] = [
  { icon: '\u{1FA7A}', tone: 'note', name: 'Type 2 Diabetes Mellitus', sub: 'Since 2019 • Active' },
  { icon: '\u{1FA7A}', tone: 'note', name: 'Hypertension',             sub: 'Since 2017 • Active' },
  { icon: '\u{1FA7A}', tone: 'note', name: 'Hypothyroidism',           sub: 'Since 2021 • Active' },
  { icon: '\u{1FA7A}', tone: 'note', name: 'Osteoarthritis (knees)',   sub: 'Since 2022 • Active' },
];

const MEDICATIONS: readonly ListItem[] = [
  { icon: '\u{1F48A}', tone: 'med', name: 'Metformin 1000 mg',    sub: 'BID with meals' },
  { icon: '\u{1F48A}', tone: 'med', name: 'Lisinopril 10 mg',     sub: 'Daily — increased 04/01' },
  { icon: '\u{1F48A}', tone: 'med', name: 'Levothyroxine 50 mcg', sub: 'Daily, AM' },
  { icon: '\u{1F48A}', tone: 'med', name: 'Atorvastatin 40 mg',   sub: 'Nightly' },
];

const LABS: readonly LabRow[] = [
  { test: 'HbA1c',       value: '7.9 %',     status: 'high',   range: '<7.0',    date: '04/12/2026' },
  { test: 'LDL',         value: '98 mg/dL',  status: 'normal', range: '<100',    date: '04/12/2026' },
  { test: 'Creatinine',  value: '1.04 mg/dL', status: 'normal', range: '0.6–1.2', date: '04/12/2026' },
  { test: 'TSH',         value: '2.4 mIU/L', status: 'normal', range: '0.4–4.0', date: '04/12/2026' },
  { test: 'Microalbumin', value: '32 mg/g',  status: 'high',   range: '<30',     date: '04/12/2026' },
];

const VISITS: readonly Visit[] = [
  { title: 'Annual physical — Dr. Rivera',     sub: '02/18/2026 • 30 min • Signed' },
  { title: 'Diabetes follow-up — Dr. Rivera',  sub: '11/15/2025 • 20 min • Signed' },
  { title: 'Lab review — Dr. Chen',            sub: '08/22/2025 • Telehealth • Signed' },
  { title: 'Annual physical — Dr. Rivera',     sub: '02/12/2025 • 30 min • Signed' },
  { title: 'Acute visit (URI) — Dr. Patel',    sub: '10/04/2024 • 15 min • Signed' },
];

type DashboardProps = {
  readonly boot: BootContext;
};

export function Dashboard(_props: DashboardProps): JSX.Element {
  return (
    <main className={styles.dash}>

      {/* Vitals row — 4 KPI cards */}
      <section className={styles.vitals}>
        {VITALS.map((v) => {
          const trendClass = v.trendDir === 'down'
            ? `${styles.trend} ${styles.trendDown}`
            : `${styles.trend} ${styles.trendUp}`;
          return (
            <div key={v.label} className={`${styles.card} ${styles.vital}`}>
              <div className={styles.lbl}>{v.label}</div>
              <div className={styles.row}>
                <span className={styles.val}>{v.value}</span>
                <span className={styles.unit}>{v.unit}</span>
              </div>
              <div className={trendClass}>
                <span className={styles.dot} />
                <span>{v.trendArrow} {v.trend}</span>
              </div>
            </div>
          );
        })}
      </section>

      {/* Allergies / Active Problems / Current Medications */}
      <section className={styles.threeUp}>
        <ListPanel title="Allergies"           link="View all" items={ALLERGIES} />
        <ListPanel title="Active Problems"     link="View all" items={PROBLEMS} />
        <ListPanel title="Current Medications" link="View all" items={MEDICATIONS} />
      </section>

      {/* Recent Lab Results / Recent Visits */}
      <section className={styles.twoUp}>

        <div className={styles.card}>
          <div className={styles.cardHead}>
            <span className={styles.cardTitle}>Recent Lab Results</span>
            <span className={styles.spacer} />
            <a className={styles.cardLink} href="#">View trends →</a>
          </div>
          <table className={styles.labTable}>
            <thead>
              <tr>
                <th>Test</th>
                <th>Value</th>
                <th>Range</th>
                <th>Date</th>
              </tr>
            </thead>
            <tbody>
              {LABS.map((l) => {
                const valClass = l.status === 'high'
                  ? `${styles.valCell} ${styles.valHigh}`
                  : `${styles.valCell} ${styles.valNormal}`;
                return (
                  <tr key={l.test}>
                    <td className={styles.testName}>{l.test}</td>
                    <td>
                      <span className={valClass}>
                        <span className={styles.dot} />
                        {l.value}
                      </span>
                    </td>
                    <td><span className={styles.range}>{l.range}</span></td>
                    <td><span className={styles.date}>{l.date}</span></td>
                  </tr>
                );
              })}
            </tbody>
          </table>
        </div>

        <div className={styles.card}>
          <div className={styles.cardHead}>
            <span className={styles.cardTitle}>Recent Visits</span>
            <span className={styles.spacer} />
            <a className={styles.cardLink} href="#">View all</a>
          </div>
          <div className={styles.visitList}>
            {VISITS.map((v, i) => (
              <div key={`${v.title}-${i}`} className={styles.visit}>
                <span className={`${styles.itemIcon} ${styles.iconVisit}`}>{'\u{1F4C5}'}</span>
                <div className={styles.body}>
                  <span className={styles.itemName}>{v.title}</span>
                  <span className={styles.itemSub}>{v.sub}</span>
                </div>
              </div>
            ))}
          </div>
        </div>

      </section>

    </main>
  );
}

type ListPanelProps = {
  readonly title: string;
  readonly link: string;
  readonly items: readonly ListItem[];
};

function ListPanel({ title, link, items }: ListPanelProps): JSX.Element {
  return (
    <div className={styles.card}>
      <div className={styles.cardHead}>
        <span className={styles.cardTitle}>{title}</span>
        <span className={styles.spacer} />
        <a className={styles.cardLink} href="#">{link}</a>
      </div>
      <div className={styles.list}>
        {items.map((it, i) => {
          const iconClass =
            it.tone === 'alert' ? `${styles.itemIcon} ${styles.iconAlert}` :
            it.tone === 'med'   ? `${styles.itemIcon} ${styles.iconMed}` :
                                  `${styles.itemIcon} ${styles.iconNote}`;
          return (
            <div key={`${it.name}-${i}`} className={styles.listItem}>
              <span className={iconClass}>{it.icon}</span>
              <div className={styles.body}>
                <span className={styles.itemName}>{it.name}</span>
                <span className={styles.itemSub}>{it.sub}</span>
              </div>
            </div>
          );
        })}
      </div>
    </div>
  );
}
