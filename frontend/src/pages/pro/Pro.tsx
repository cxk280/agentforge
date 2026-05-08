// Pro — Figma "Screen 20 — PRO" (file kj4MWNr8mpjZ2wVg1PbS0F, node 41:2).
// Patient Reported Outcomes view: an in-page header strip with title + meta +
// "Send to Patient Portal" CTA, four instrument summary cards (PHQ-9, GAD-7,
// PROMIS-29, DDS-17) with mini bar charts, and a Submission History table.
//
// 1:1 port of the PHP-rendered mock previously at
// /interface/easipro/copilot_pro.php. The PHP outer shell at
// /interface/main/tabs/main.php still owns the navy top nav and patient
// header2 banner; this React page renders only the navtab body content,
// matching the Calendar / Assessments migrations.
//
// All click handlers are static no-ops in the original PHP — the Send /
// Export CSV / View / Compare buttons are visual only. We preserve that as
// real <button>s with type="button" so future wiring is a one-line change.

import type { BootContext } from '../../shared/lib/bootContext';
import styles from './Pro.module.css';

type InstrumentTone = 'violet' | 'info' | 'warn' | 'teal';
type SeverityTone = 'mild' | 'moderate';
type ArrowTone = 'good' | 'bad';

type Instrument = {
  readonly name: string;
  readonly tone: InstrumentTone;
  readonly topic: string;
  readonly score: string;
  readonly max: string;
  readonly sev: string;
  readonly sevTone: SeverityTone;
  readonly arrow: string;
  readonly arrowTone: ArrowTone;
  readonly change: string;
  readonly bars: readonly number[];
};

type SubmissionRow = {
  readonly date: string;
  readonly inst: string;
  readonly score: string;
  readonly interp: string;
  readonly change: string;
  readonly changeTone: ArrowTone;
  readonly via: string;
};

const INSTRUMENTS: readonly Instrument[] = [
  {
    name: 'PHQ-9',
    tone: 'violet',
    topic: 'Depression',
    score: '8',
    max: '27',
    sev: 'Mild',
    sevTone: 'mild',
    arrow: '↓',
    arrowTone: 'good',
    change: 'from 12',
    bars: [80, 75, 70, 60, 55, 50, 45],
  },
  {
    name: 'GAD-7',
    tone: 'info',
    topic: 'Anxiety',
    score: '5',
    max: '21',
    sev: 'Mild',
    sevTone: 'mild',
    arrow: '↓',
    arrowTone: 'good',
    change: 'from 7',
    bars: [85, 78, 70, 62, 55, 50, 38],
  },
  {
    name: 'PROMIS-29',
    tone: 'warn',
    topic: 'Pain Intensity',
    score: '5',
    max: '10',
    sev: 'Moderate',
    sevTone: 'moderate',
    arrow: '↑',
    arrowTone: 'bad',
    change: 'from 4',
    bars: [40, 45, 50, 55, 60, 65, 75],
  },
  {
    name: 'DDS-17',
    tone: 'teal',
    topic: 'Diabetes Distress',
    score: '32',
    max: '102',
    sev: 'Moderate',
    sevTone: 'moderate',
    arrow: '↓',
    arrowTone: 'good',
    change: 'from 38',
    bars: [80, 78, 75, 70, 60, 55, 45],
  },
];

const ROWS: readonly SubmissionRow[] = [
  { date: '04/12/2026', inst: 'PHQ-9',          score: '8 / 27',   interp: 'Mild depression',    change: '↓ 1 from prev', changeTone: 'good', via: 'Patient Portal' },
  { date: '04/12/2026', inst: 'GAD-7',          score: '5 / 21',   interp: 'Mild anxiety',       change: '↓ 1 from prev', changeTone: 'good', via: 'Patient Portal' },
  { date: '04/12/2026', inst: 'PROMIS-29 Pain', score: '5 / 10',   interp: 'Moderate',           change: '↑ 1 from prev', changeTone: 'bad',  via: 'Patient Portal' },
  { date: '02/18/2026', inst: 'PHQ-9',          score: '9 / 27',   interp: 'Mild depression',    change: '↓ 1 from prev', changeTone: 'good', via: 'In-clinic tablet' },
  { date: '02/18/2026', inst: 'DDS-17',         score: '32 / 102', interp: 'Moderate distress',  change: '↓ 3 from prev', changeTone: 'good', via: 'In-clinic tablet' },
  { date: '11/15/2025', inst: 'PHQ-9',          score: '10 / 27',  interp: 'Mild depression',    change: '↓ 1 from prev', changeTone: 'good', via: 'Patient Portal' },
];

type ProProps = {
  readonly boot: BootContext;
};

export function Pro(_props: ProProps): JSX.Element {
  return (
    <>
      <header className={styles.head}>
        <div className={styles.title}>Patient Reported Outcomes</div>
        <div className={styles.bullet}>•</div>
        <div className={styles.meta}>EasiPRO connected • 6 instruments tracked</div>
        <div className={styles.spacer} />
        <button type="button" className={styles.cta}>
          <span>📨</span>
          <span>Send to Patient Portal</span>
        </button>
      </header>

      <main className={styles.body}>
        <section className={styles.instGrid}>
          {INSTRUMENTS.map((i) => (
            <InstrumentCard key={i.name} i={i} />
          ))}
        </section>

        <section className={styles.subCard}>
          <header className={styles.subHead}>
            <div className={styles.subTitle}>Submission History</div>
            <div className={styles.subSpacer} />
            <button type="button" className={styles.subLink}>Export CSV</button>
          </header>
          <table className={styles.subTable}>
            <thead>
              <tr>
                <th className={styles.colDate}>DATE</th>
                <th className={styles.colInst}>INSTRUMENT</th>
                <th className={styles.colScore}>SCORE</th>
                <th>INTERPRETATION</th>
                <th>CHANGE</th>
                <th className={styles.colVia}>ADMINISTERED VIA</th>
                <th>ACTIONS</th>
              </tr>
            </thead>
            <tbody>
              {ROWS.map((r, idx) => (
                <tr key={`${r.date}-${r.inst}-${idx}`}>
                  <td className={styles.colDate}>{r.date}</td>
                  <td className={styles.colInst}>{r.inst}</td>
                  <td className={styles.colScore}>{r.score}</td>
                  <td>{r.interp}</td>
                  <td className={r.changeTone === 'good' ? styles.changeGood : styles.changeBad}>
                    {r.change}
                  </td>
                  <td className={styles.colVia}>{r.via}</td>
                  <td>
                    <button type="button" className={styles.subAction}>View</button>
                    <button type="button" className={styles.subAction}>Compare</button>
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

type InstrumentCardProps = {
  readonly i: Instrument;
};

function InstrumentCard({ i }: InstrumentCardProps): JSX.Element {
  const dotCls = `${styles.dot} ${styles[`dot_${i.tone}`] ?? ''}`;
  const sevCls = `${styles.sevPill} ${styles[`sevPill_${i.sevTone}`] ?? ''}`;
  const arrowCls = `${styles.arrow} ${i.arrowTone === 'good' ? styles.arrowGood : styles.arrowBad}`;
  const barsCls = `${styles.bars} ${styles[`bars_${i.tone}`] ?? ''}`;

  return (
    <article className={styles.card}>
      <div className={styles.titleRow}>
        <span className={dotCls} />
        <span className={styles.name}>{i.name}</span>
        <span className={styles.cardBullet}>•</span>
        <span className={styles.topic}>{i.topic}</span>
      </div>
      <div className={styles.score}>
        <span className={styles.scoreNum}>{i.score}</span>
        <span className={styles.scoreMax}>/ {i.max}</span>
      </div>
      <div className={styles.cardMeta}>
        <span className={sevCls}>{i.sev}</span>
        <span className={arrowCls}>{i.arrow}</span>
        <span className={styles.change}>{i.change}</span>
      </div>
      <div className={barsCls}>
        {i.bars.map((h, idx) => (
          <span key={idx} className={styles.bar} style={{ height: `${h}%` }} />
        ))}
      </div>
    </article>
  );
}
