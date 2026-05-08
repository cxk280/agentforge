// Assessments — Figma "Screen 13 — Assessments" (file kj4MWNr8mpjZ2wVg1PbS0F,
// node 34:2). Static demo only — categories sidebar, page header bar with
// title + meta + "Assign Assessment" CTA, and a stack of assessment cards
// (due / done / scheduled).
//
// 1:1 port of the PHP-rendered mock previously at
// /interface/patient_file/assessments/copilot_assessments.php. The PHP outer
// shell at /interface/main/tabs/main.php still owns the navy top nav, left
// sidebar, and patient header2 banner; this React page renders only the
// content beneath those shells, exactly like Calendar.

import type { BootContext } from '../../shared/lib/bootContext';
import styles from './Assessments.module.css';

type AssessmentStatus = 'due' | 'done' | 'scheduled';

type Category = {
  readonly label: string;
  readonly count: number;
  readonly active: boolean;
};

type Assessment = {
  readonly status: AssessmentStatus;
  readonly title: string;
  readonly cat: string;
  readonly desc: string;
  readonly meta: string;
  readonly scoreLabel?: string | undefined;
  readonly scoreValue?: string | undefined;
};

const CATEGORIES: readonly Category[] = [
  { label: 'All',                count: 12, active: true  },
  { label: 'Due Now',            count: 4,  active: false },
  { label: 'Behavioral Health',  count: 6,  active: false },
  { label: 'Social Determinants', count: 2, active: false },
  { label: 'Functional',         count: 3,  active: false },
  { label: 'Risk Screening',     count: 1,  active: false },
  { label: 'Wellness',           count: 0,  active: false },
];

const ASSESSMENTS: readonly Assessment[] = [
  {
    status: 'due',
    title:  'PHQ-9 — Patient Health Questionnaire',
    cat:    'Behavioral Health',
    desc:   '9-item depression screening instrument',
    meta:   'Last taken 90 days ago',
  },
  {
    status: 'due',
    title:  'GAD-7 — Generalized Anxiety Disorder',
    cat:    'Behavioral Health',
    desc:   '7-item anxiety screening',
    meta:   'Never administered',
  },
  {
    status: 'due',
    title:  'AUDIT-C — Alcohol Use Disorders',
    cat:    'Behavioral Health',
    desc:   '3-item alcohol use screening',
    meta:   'Last taken 12 months ago',
  },
  {
    status: 'due',
    title:  'SDOH Assessment — PRAPARE',
    cat:    'Social Determinants',
    desc:   '21 questions covering housing, food, transportation, employment',
    meta:   'Never administered',
  },
  {
    status: 'done',
    title:  'Diabetes Distress Scale (DDS-17)',
    cat:    'Behavioral Health',
    desc:   'Screens for emotional distress related to diabetes management',
    meta:   'Score 32 — moderate distress • 02/18/2026',
    scoreLabel: 'SCORE',
    scoreValue: '32 / 102',
  },
  {
    status: 'done',
    title:  'Falls Risk Assessment (Stop-BANG)',
    cat:    'Risk Screening',
    desc:   'Identifies fall risk in older adults',
    meta:   'Low risk • 02/18/2026',
    scoreLabel: 'SCORE',
    scoreValue: 'Low',
  },
  {
    status: 'scheduled',
    title:  'Functional Status (Barthel Index)',
    cat:    'Functional',
    desc:   'Activities of Daily Living assessment',
    meta:   'Sent to patient portal • Due 11/20',
  },
];

type AssessmentsProps = {
  readonly boot: BootContext;
};

export function Assessments(_props: AssessmentsProps): JSX.Element {
  return (
    <>
      <header className={styles.head}>
        <div className={styles.title}>Assessments</div>
        <div className={styles.bullet}>•</div>
        <div className={styles.meta}>4 due, 8 completed</div>
        <div className={styles.spacer} />
        <button type="button" className={styles.cta}>
          <span className={styles.ctaPlus}>+</span>
          <span>Assign Assessment</span>
        </button>
      </header>

      <div className={styles.body}>
        <aside className={styles.side}>
          {CATEGORIES.map((c) => {
            const cls = c.active ? `${styles.cat} ${styles.catActive}` : styles.cat;
            return (
              <div key={c.label} className={cls}>
                <span className={styles.catLabel}>{c.label}</span>
                <span className={styles.catCount}>{c.count}</span>
              </div>
            );
          })}
        </aside>

        <main className={styles.list}>
          {ASSESSMENTS.map((a) => (
            <AssessmentCard key={a.title} a={a} />
          ))}
        </main>
      </div>
    </>
  );
}

type AssessmentCardProps = {
  readonly a: Assessment;
};

function AssessmentCard({ a }: AssessmentCardProps): JSX.Element {
  const avatarCls =
    a.status === 'due'
      ? `${styles.avatar} ${styles.avatarDue}`
      : a.status === 'done'
      ? `${styles.avatar} ${styles.avatarDone}`
      : `${styles.avatar} ${styles.avatarScheduled}`;

  const avatarGlyph = a.status === 'due' ? '!' : a.status === 'done' ? '✓' : '🕒';

  return (
    <article className={styles.card}>
      <div className={avatarCls}>{avatarGlyph}</div>
      <div className={styles.cardInfo}>
        <div className={styles.cardTitleRow}>
          <span className={styles.cardTitle}>{a.title}</span>
          <span className={styles.cardPill}>{a.cat}</span>
        </div>
        <div className={styles.cardDesc}>{a.desc}</div>
        <div className={styles.cardMeta}>{a.meta}</div>
      </div>
      <div className={styles.cardSpacer} />

      {a.status === 'due' && (
        <>
          <span className={`${styles.statusPill} ${styles.statusPillDue}`}>DUE NOW</span>
          <button type="button" className={`${styles.action} ${styles.actionPrimary}`}>
            Begin →
          </button>
        </>
      )}
      {a.status === 'scheduled' && (
        <>
          <span className={`${styles.statusPill} ${styles.statusPillScheduled}`}>SCHEDULED</span>
          <button type="button" className={`${styles.action} ${styles.actionSecondary}`}>
            Resend
          </button>
        </>
      )}
      {a.status === 'done' && (
        <>
          <div className={styles.score}>
            <span className={styles.scoreLabel}>{a.scoreLabel ?? 'SCORE'}</span>
            <span className={styles.scoreValue}>{a.scoreValue ?? ''}</span>
          </div>
          <button type="button" className={`${styles.action} ${styles.actionSecondary}`}>
            View
          </button>
        </>
      )}
    </article>
  );
}
