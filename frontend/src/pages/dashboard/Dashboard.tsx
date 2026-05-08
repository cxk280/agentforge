// Dashboard — Figma "Screen 11 — Dashboard". Renders the Patient Dashboard
// body that lives inside the patient chart iframe: 4 vital metric cards
// (BP / A1C / LDL / BMI) + 3 summary panels (Allergies, Active Problems,
// Current Medications) + 2 detail panels (Recent Lab Results, Recent Visits).
//
// The PHP wrapper at copilot_dashboard.php queries form_vitals, form_observation,
// lists, prescriptions, and form_encounter for the current pid and JSON-encodes
// the result onto data-dashboard; the entry index.tsx parses it and hands it
// here as the `dashboard` prop. The navy top nav, the patient demographics
// banner (header2), and the patient navtab strip (Dashboard / History /
// Co-Pilot / …) are owned by the parent shell — this component renders ONLY
// the body.

import type { BootContext } from '../../shared/lib/bootContext';
import styles from './Dashboard.module.css';

// Server-side row shapes (mirror the PHP query in copilot_dashboard.php).

export type DashboardVital = {
  readonly label: string;
  readonly value: string;
  readonly unit: string;
  readonly trend: string;
  readonly trendArrow: '↓' | '↑';
  readonly trendDir: 'down' | 'up' | 'flat';
};

export type DashboardListItem = {
  readonly icon: string;
  readonly tone: 'alert' | 'note' | 'med';
  readonly name: string;
  readonly sub: string;
};

export type DashboardLabRow = {
  readonly test: string;
  readonly value: string;
  readonly status: 'normal' | 'high';
  readonly range: string;
  readonly date: string;
};

export type DashboardVisit = {
  readonly title: string;
  readonly sub: string;
};

// Server-side payload — six parallel arrays, one per panel. The PHP wrapper
// emits this shape on data-dashboard; the React side renders the panels.
export type DashboardPayload = {
  readonly vitals: readonly DashboardVital[];
  readonly allergies: readonly DashboardListItem[];
  readonly problems: readonly DashboardListItem[];
  readonly medications: readonly DashboardListItem[];
  readonly labs: readonly DashboardLabRow[];
  readonly visits: readonly DashboardVisit[];
};

type DashboardProps = {
  readonly boot: BootContext;
  readonly dashboard: DashboardPayload;
};

export function Dashboard({ dashboard }: DashboardProps): JSX.Element {
  const { vitals, allergies, problems, medications, labs, visits } = dashboard;

  return (
    <main className={styles.dash}>

      {/* Vitals row — 4 KPI cards */}
      <section className={styles.vitals}>
        {vitals.map((v) => {
          const trendClass = v.trendDir === 'down'
            ? `${styles.trend} ${styles.trendDown}`
            : v.trendDir === 'up'
              ? `${styles.trend} ${styles.trendUp}`
              : styles.trend;
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
        <ListPanel title="Allergies"           link="View all" items={allergies} />
        <ListPanel title="Active Problems"     link="View all" items={problems} />
        <ListPanel title="Current Medications" link="View all" items={medications} />
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
              {labs.map((l, i) => {
                const valClass = l.status === 'high'
                  ? `${styles.valCell} ${styles.valHigh}`
                  : `${styles.valCell} ${styles.valNormal}`;
                return (
                  <tr key={`${l.test}-${i}`}>
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
            {visits.map((v, i) => (
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
  readonly items: readonly DashboardListItem[];
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
