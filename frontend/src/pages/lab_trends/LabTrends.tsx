// LabTrends — Figma "Screen 45 — Lab Trends Report". Renders the AgentForge
// practice-wide HbA1c distribution dashboard: 5-up KPI strip, binned
// distribution bar chart with target line callout, by-provider goal-attainment
// bars, and an outliers table for clinic follow-up.
//
// This is a 1:1 port of the PHP-rendered page previously at
// /interface/reports/copilot_lab_trends.php. All data is static demo data
// matching the Figma mock exactly. The navy top-nav and left sub-nav are
// rendered by the parent shell (PHP wrapper / outer iframe), so this
// component only renders the page body — pagehead + content area.
//
// Verified against Figma node 93:2 on 2026-05-07.

import type { BootContext } from '../../shared/lib/bootContext';
import styles from './LabTrends.module.css';

type DistTone = 'good' | 'warn' | 'danger';

type DistBin = {
  readonly label: string;
  readonly pct: number; // height-percent in the chart
  readonly tone: DistTone;
  readonly showLabel: boolean;
};

type ProviderRow = {
  readonly name: string;
  readonly num: number;
  readonly denom: number;
  readonly pct: number;
};

type OutlierRow = {
  readonly name: string;
  readonly mrn: string;
  readonly value: string;
  readonly provider: string;
  readonly lastDate: string;
};

// 13 bins exactly as drawn in Figma. Pct values are the % shown above the
// bar — bar height is the pct mapped over the chart's vertical axis.
const DIST_BINS: readonly DistBin[] = [
  { label: '<5.5', pct: 2,  tone: 'good',   showLabel: true  },
  { label: '5.5',  pct: 4,  tone: 'good',   showLabel: true  },
  { label: '6.0',  pct: 12, tone: 'good',   showLabel: true  },
  { label: '6.5',  pct: 18, tone: 'good',   showLabel: true  },
  { label: '7.0',  pct: 22, tone: 'warn',   showLabel: true  },
  { label: '7.5',  pct: 16, tone: 'warn',   showLabel: true  },
  { label: '8.0',  pct: 11, tone: 'warn',   showLabel: true  },
  { label: '8.5',  pct: 7,  tone: 'warn',   showLabel: true  },
  { label: '9.0',  pct: 4,  tone: 'danger', showLabel: true  },
  { label: '9.5',  pct: 2,  tone: 'danger', showLabel: true  },
  { label: '10.0', pct: 1,  tone: 'danger', showLabel: false },
  { label: '10.5', pct: 0.5, tone: 'danger', showLabel: false },
  { label: '11+',  pct: 0.5, tone: 'danger', showLabel: false },
];

const PROVIDERS: readonly ProviderRow[] = [
  { name: 'Dr. E. Rivera',    num: 228, denom: 512, pct: 45 },
  { name: 'Dr. K. Chen',      num: 189, denom: 438, pct: 43 },
  { name: 'Dr. R. Patel',     num: 160, denom: 397, pct: 40 },
  { name: 'NP Jones',         num: 108, denom: 288, pct: 38 },
  { name: 'Dr. M. Sandoval',  num: 74,  denom: 212, pct: 35 },
];

const OUTLIERS: readonly OutlierRow[] = [
  { name: 'Helen Garcia',    mrn: '#003021', value: '9.4%',           provider: 'Dr. Patel',  lastDate: 'Last A1C 04/12' },
  { name: 'Margaret Chen',   mrn: '#004821', value: '7.9% (rising)',  provider: 'Dr. Rivera', lastDate: 'Last A1C 04/30' },
  { name: 'Linda Martinez',  mrn: '#003918', value: '7.2% (rising)',  provider: 'Dr. Rivera', lastDate: 'Last A1C 04/30' },
  { name: 'James Brown',     mrn: '#002188', value: '7.4% (stable)',  provider: 'Dr. Chen',   lastDate: 'Last A1C 03/14' },
  { name: 'Robert Hayes',    mrn: '#001821', value: '7.1% (rising)',  provider: 'NP Jones',   lastDate: 'Last A1C 02/28' },
  { name: 'Patricia Vance',  mrn: '#005122', value: '9.8%',           provider: 'Dr. Chen',   lastDate: 'Last A1C 03/02' },
  { name: 'Donald Reyes',    mrn: '#005711', value: '10.1%',          provider: 'Dr. Patel',  lastDate: 'Last A1C 02/14' },
];

// Position of the "Target <7.0" callout — anchored to bin index 4 (the "7.0"
// bin) per the Figma. The line drops over that bin's column.
const TARGET_BIN_INDEX = 4;

type LabTrendsProps = {
  readonly boot: BootContext;
};

export function LabTrends(_props: LabTrendsProps): JSX.Element {
  // Compute target callout left position as percentage across the chart's
  // bar area. Each bar is 1/N of the row width.
  const n = DIST_BINS.length;
  const targetLeftPct = ((TARGET_BIN_INDEX + 0.5) / n) * 100;

  // Map raw pct (0-22) to bar height. Max pct = 22 → use 22 as the scale max
  // so the tallest bar fills 176px (the Figma height of the 22% bar).
  const MAX_PCT = 22;
  const CHART_INNER_HEIGHT = 176;

  return (
    <>
      <header className={styles.pagehead}>
        <div className={styles.titleRow}>
          <span className={styles.title}>Lab Trends</span>
          <span className={styles.dot}>•</span>
          <span className={styles.subtitle}>Practice-wide HbA1c distribution · 1,847 diabetic pts</span>
        </div>
        <div className={styles.spacer} />
        <div className={styles.labSelect}>
          <span>HbA1c · Diabetes</span>
          <span className={styles.caret}>▾</span>
        </div>
        <button className={styles.exportBtn} type="button">⬇ Export CSV</button>
        <button className={styles.runBtn} type="button">Run</button>
      </header>

      <main className={styles.content}>
        {/* 5-up KPI strip */}
        <section className={styles.kpiCard}>
          <div className={styles.kpi}>
            <span className={styles.kpiLabel}>Mean A1C</span>
            <span className={`${styles.kpiVal} ${styles.kpiNavy}`}>7.1%</span>
          </div>
          <span className={styles.kpiDivider} />
          <div className={styles.kpi}>
            <span className={styles.kpiLabel}>{'<7.0% (target)'}</span>
            <span className={`${styles.kpiVal} ${styles.kpiGreen}`}>42%</span>
          </div>
          <span className={styles.kpiDivider} />
          <div className={styles.kpi}>
            <span className={styles.kpiLabel}>7.0–8.9%</span>
            <span className={`${styles.kpiVal} ${styles.kpiOrange}`}>41%</span>
          </div>
          <span className={styles.kpiDivider} />
          <div className={styles.kpi}>
            <span className={styles.kpiLabel}>≥9.0% (poor)</span>
            <span className={`${styles.kpiVal} ${styles.kpiRed}`}>17%</span>
          </div>
          <span className={styles.kpiDivider} />
          <div className={styles.kpi}>
            <span className={styles.kpiLabel}>Tested in last 90d</span>
            <span className={`${styles.kpiVal} ${styles.kpiGreen}`}>82%</span>
          </div>
        </section>

        {/* Distribution histogram */}
        <section className={styles.histCard}>
          <h3 className={styles.histTitle}>HbA1c distribution · last 12 months</h3>
          <div className={styles.histDesc}>Each bar = % of diabetic patients in bin</div>

          <div className={styles.chartArea}>
            <div className={styles.targetLabel} style={{ left: `${targetLeftPct}%` }}>
              {'Target <7.0'}
            </div>
            <div
              className={styles.targetLine}
              style={{ left: `${targetLeftPct}%` }}
              aria-hidden="true"
            />
            <div className={styles.barsRow}>
              {DIST_BINS.map((bin) => {
                const heightPx = Math.max(4, (bin.pct / MAX_PCT) * CHART_INNER_HEIGHT);
                const toneClass =
                  bin.tone === 'good'   ? styles.barGood   :
                  bin.tone === 'warn'   ? styles.barWarn   :
                                          styles.barDanger;
                return (
                  <div key={bin.label} className={styles.barCol}>
                    <div className={styles.barInner}>
                      {bin.showLabel && (
                        <span className={styles.barPct}>{bin.pct}%</span>
                      )}
                      <div
                        className={`${styles.bar} ${toneClass}`}
                        style={{ height: `${heightPx}px` }}
                      />
                    </div>
                    <span className={styles.barXLabel}>{bin.label}</span>
                  </div>
                );
              })}
            </div>
          </div>
        </section>

        {/* Bottom row: Provider goal attainment + Outliers table */}
        <div className={styles.bottomRow}>
          <section className={styles.provCard}>
            <div className={styles.cardHead}>A1C BY PROVIDER</div>
            <div className={styles.cardSub}>{'% of diabetic pts at goal (<7.0)'}</div>
            <div className={styles.provList}>
              {PROVIDERS.map((p) => (
                <div key={p.name} className={styles.provRow}>
                  <div className={styles.provName}>{p.name}</div>
                  <div className={styles.provPct}>{p.pct}%</div>
                  <div className={styles.provRatio}>{p.num} / {p.denom} pts</div>
                  <div className={styles.provBar}>
                    <span className={styles.provBarFill} style={{ width: `${p.pct}%` }} />
                  </div>
                </div>
              ))}
            </div>
          </section>

          <section className={styles.outCard}>
            <div className={styles.cardHead}>OUTLIERS — A1C ≥ 9.0%</div>
            <div className={styles.cardSub}>313 patients · contact for diabetes ed referral</div>
            <div className={styles.outList}>
              {OUTLIERS.map((o, i) => {
                const rowClass = i % 2 === 1
                  ? `${styles.outRow} ${styles.outRowAlt}`
                  : styles.outRow;
                return (
                  <div key={o.mrn} className={rowClass}>
                    <span className={styles.outAvatar} aria-hidden="true" />
                    <span className={styles.outName}>{o.name}</span>
                    <span className={styles.outMrn}>{o.mrn}</span>
                    <span className={styles.outVal}>{o.value}</span>
                    <span className={styles.outProv}>{o.provider}</span>
                    <span className={styles.outLast}>{o.lastDate}</span>
                    <a className={styles.outOpen} href="#" onClick={(e) => e.preventDefault()}>
                      Open →
                    </a>
                  </div>
                );
              })}
            </div>
          </section>
        </div>
      </main>
    </>
  );
}
