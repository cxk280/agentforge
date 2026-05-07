// QualityMeasures — Figma "Screen 46 — Quality Measures (CQM)".
// Reports → Clinical → Quality Measures sub-page. Top composite-score strip
// + 4×3 grid of CMS measure cards, with a left rail listing report
// categories. The navy top nav is intentionally omitted per the migration
// brief (the outer PHP shell at /interface/main/tabs/main.php still owns
// it).
//
// 1:1 port of the static mock formerly at
// /interface/reports/copilot_quality_measures.php — all values are
// hardcoded demo data matching Figma exactly so the visual output is
// frozen against the reference screenshot in
// frontend/.fidelity-references/quality_measures-figma-2026-05-07.png.

import type { BootContext } from '../../shared/lib/bootContext';
import styles from './QualityMeasures.module.css';

type Tone = 'good' | 'warn';

type Measure = {
  readonly code: string;
  readonly name: string;
  readonly pct: string;       // pre-formatted ("42%", "3.2%")
  readonly target: string;    // "Target ≥75%" / "Target <25%"
  readonly fillPct: number;   // bar fill, 0–100 (% of bar width)
  readonly markerPct: number; // target marker position, 0–100
  readonly note: string;
  readonly tone: Tone;
  readonly statusLabel: 'On target' | 'Below';
};

// Side-rail report categories. "Quality Measures" is the active item.
type SideItem = { readonly label: string; readonly active?: boolean };
type SideGroup = { readonly heading: string; readonly items: readonly SideItem[] };

const SIDE_GROUPS: readonly SideGroup[] = [
  {
    heading: 'Clinical',
    items: [
      { label: 'Patient List' },
      { label: 'Prescriptions' },
      { label: 'Lab Trends' },
      { label: 'Quality Measures', active: true },
      { label: 'Immunizations' },
      { label: 'Encounters' },
    ],
  },
  {
    heading: 'Financial',
    items: [
      { label: 'Daily Cash' },
      { label: 'Aging' },
      { label: 'Payer Mix' },
      { label: 'Collections' },
    ],
  },
  {
    heading: 'Operations',
    items: [
      { label: 'Visit Volume' },
      { label: 'Provider Productivity' },
      { label: 'No-shows' },
    ],
  },
  {
    heading: 'Electronic',
    items: [
      { label: 'Submissions' },
      { label: 'CCDA Exports' },
      { label: 'HIE Sync' },
      { label: 'Public Health' },
    ],
  },
];

// Card values verified against Figma node 94:2 on 2026-05-07. fillPct and
// markerPct come from Figma rect widths (out of the 352px bar): e.g. CMS122
// fill 147.84/352 = 42%, marker 102/352 ≈ 29% (mapped to its 25% target).
// We round marker positions to the integer target percent so the marker
// lines up cleanly with the labelled threshold.
const MEASURES: readonly Measure[] = [
  { code: 'CMS122', name: 'Diabetes A1C poor control',       pct: '42%',  target: 'Target <25%',  fillPct: 42, markerPct: 25, note: 'Below target — 313 outliers', tone: 'warn', statusLabel: 'Below' },
  { code: 'CMS131', name: 'Diabetes eye exam',               pct: '78%',  target: 'Target ≥75%',  fillPct: 78, markerPct: 75, note: 'Meeting target',                tone: 'good', statusLabel: 'On target' },
  { code: 'CMS125', name: 'Breast cancer screening',         pct: '71%',  target: 'Target ≥70%',  fillPct: 71, markerPct: 70, note: 'Just at threshold',             tone: 'good', statusLabel: 'On target' },
  { code: 'CMS124', name: 'Cervical cancer screening',       pct: '68%',  target: 'Target ≥70%',  fillPct: 68, markerPct: 70, note: '71 patients overdue',           tone: 'warn', statusLabel: 'Below' },
  { code: 'CMS130', name: 'Colorectal cancer screening',     pct: '64%',  target: 'Target ≥65%',  fillPct: 64, markerPct: 65, note: '124 patients overdue',          tone: 'warn', statusLabel: 'Below' },
  { code: 'CMS165', name: 'Controlling hypertension',        pct: '74%',  target: 'Target ≥70%',  fillPct: 74, markerPct: 70, note: 'Improving trend',               tone: 'good', statusLabel: 'On target' },
  { code: 'CMS156', name: 'Use of high-risk meds in elderly', pct: '3.2%', target: 'Target <5%',   fillPct: 3,  markerPct: 5,  note: 'Best in class',                 tone: 'good', statusLabel: 'On target' },
  { code: 'CMS147', name: 'Influenza vaccination',           pct: '62%',  target: 'Target ≥65%',  fillPct: 62, markerPct: 65, note: 'Season ending',                 tone: 'warn', statusLabel: 'Below' },
  { code: 'CMS68',  name: 'Documentation of meds',           pct: '94%',  target: 'Target ≥90%',  fillPct: 94, markerPct: 90, note: 'Strong',                        tone: 'good', statusLabel: 'On target' },
  { code: 'CMS69',  name: 'BMI screening + follow-up',       pct: '81%',  target: 'Target ≥75%',  fillPct: 81, markerPct: 75, note: 'On track',                      tone: 'good', statusLabel: 'On target' },
  { code: 'CMS166', name: 'Use of imaging in low back pain', pct: '22%',  target: 'Target <25%',  fillPct: 22, markerPct: 25, note: 'Lower is better',               tone: 'good', statusLabel: 'On target' },
  { code: 'CMS117', name: 'Childhood immunization status',   pct: '89%',  target: 'Target ≥85%',  fillPct: 89, markerPct: 85, note: 'Strong',                        tone: 'good', statusLabel: 'On target' },
];

type QualityMeasuresProps = {
  readonly boot: BootContext;
};

export function QualityMeasures(_props: QualityMeasuresProps): JSX.Element {
  return (
    <div className={styles.shell}>
      <aside className={styles.side}>
        <div className={styles.sideHeader}>REPORTS</div>
        {SIDE_GROUPS.map((group) => (
          <div key={group.heading} className={styles.sideGroup}>
            <div className={styles.sideGroupLabel}>{group.heading}</div>
            {group.items.map((item) => {
              const cls = item.active
                ? `${styles.sideItem} ${styles.sideItemActive}`
                : styles.sideItem;
              return (
                <a key={item.label} className={cls} href="#" onClick={(e) => e.preventDefault()}>
                  {item.label}
                </a>
              );
            })}
          </div>
        ))}
      </aside>

      <div className={styles.main}>
        <header className={styles.pagehead}>
          <div className={styles.titleRow}>
            <span className={styles.title}>Quality Measures (CQM)</span>
            <span className={styles.dot}>·</span>
            <span className={styles.metaLight}>CMS QPP · Q1 2026 · 22 measures · last calc 04/30</span>
          </div>
          <div className={styles.headSpacer} />
          <button type="button" className={styles.btnGhost}>Submit to CMS</button>
          <button type="button" className={styles.btnPrimary}>
            <span aria-hidden="true">⬇</span> Generate QRDA
          </button>
        </header>

        <main className={styles.content}>
          <section className={styles.scoreStrip}>
            <div className={styles.scoreCol}>
              <span className={styles.scoreLbl}>COMPOSITE QUALITY SCORE</span>
              <div className={styles.composite}>
                <span className={styles.compositeBig}>78.4</span>
                <span className={styles.compositeOf}>/ 100</span>
                <span className={styles.compositeDelta}>↑ +4.2 vs Q4 2025</span>
              </div>
            </div>
            <div className={styles.scoreCol}>
              <span className={styles.scoreLbl}>MIPS Category</span>
              <span className={styles.scoreVal}>Quality</span>
            </div>
            <div className={styles.scoreCol}>
              <span className={styles.scoreLbl}>Performance Year</span>
              <span className={styles.scoreVal}>2026</span>
            </div>
            <div className={styles.scoreCol}>
              <span className={styles.scoreLbl}>Submission deadline</span>
              <span className={styles.scoreVal}>03/31/2027</span>
            </div>
            <div className={styles.scoreCol}>
              <span className={styles.scoreLbl}>Eligible providers</span>
              <span className={`${styles.scoreVal} ${styles.scoreValGood}`}>6 / 6 enrolled</span>
            </div>
            <div className={styles.scoreCol}>
              <span className={styles.scoreLbl}>Estimated MIPS bonus</span>
              <span className={`${styles.scoreVal} ${styles.scoreValBonus}`}>+5.2%</span>
            </div>
          </section>

          <section className={styles.grid}>
            {MEASURES.map((m) => {
              const fillCls = m.tone === 'good' ? styles.fillGood : styles.fillWarn;
              const pctCls  = m.tone === 'good' ? styles.pctGood  : styles.pctWarn;
              const pillCls = m.tone === 'good' ? styles.pillGood : styles.pillWarn;
              return (
                <article key={m.code} className={styles.card}>
                  <div className={styles.cardHead}>
                    <span className={styles.code}>{m.code}</span>
                    <span className={`${styles.pill} ${pillCls}`}>{m.statusLabel}</span>
                  </div>
                  <div className={styles.name}>{m.name}</div>
                  <div className={styles.pctLine}>
                    <span className={`${styles.pct} ${pctCls}`}>{m.pct}</span>
                    <span className={styles.targetTxt}>{m.target}</span>
                  </div>
                  <div className={styles.bar}>
                    <span
                      className={`${styles.fill} ${fillCls}`}
                      style={{ width: `${m.fillPct}%` }}
                    />
                    <span
                      className={styles.marker}
                      style={{ left: `${m.markerPct}%` }}
                    />
                  </div>
                  <div className={styles.note}>{m.note}</div>
                </article>
              );
            })}
          </section>
        </main>
      </div>
    </div>
  );
}
