// Assessments — Figma "Screen 13 — Assessments" (file kj4MWNr8mpjZ2wVg1PbS0F,
// node 34:2). Categories sidebar, page header bar with title + meta +
// "Assign Assessment" CTA, and a stack of assessment cards (due / done /
// scheduled).
//
// 1:1 port of the PHP-rendered mock previously at
// /interface/patient_file/assessments/copilot_assessments.php. The PHP outer
// shell at /interface/main/tabs/main.php still owns the navy top nav, left
// sidebar, and patient header2 banner; this React page renders only the
// content beneath those shells, exactly like Calendar.
//
// Data source: the PHP wrapper JSON-encodes the categories sidebar, the
// assessment cards, and the header summary onto data-categories /
// data-assessments / data-summary on #cp-root (currently hardcoded stubs —
// see TODO(real-data) in the wrapper). The entry index.tsx parses those
// attributes and passes them as props, mirroring the Finder + ExternalData
// pattern so the React side is consistent across pages even while the
// underlying data is still mock.

import type { BootContext } from '../../shared/lib/bootContext';
import styles from './Assessments.module.css';

export type AssessmentStatus = 'due' | 'done' | 'scheduled';

export type Category = {
  readonly label: string;
  readonly count: number;
  readonly active: boolean;
};

export type Assessment = {
  readonly status: AssessmentStatus;
  readonly title: string;
  readonly cat: string;
  readonly desc: string;
  readonly meta: string;
  readonly scoreLabel?: string | undefined;
  readonly scoreValue?: string | undefined;
};

export type AssessmentsSummary = {
  readonly due: number;
  readonly completed: number;
};

type AssessmentsProps = {
  readonly boot: BootContext;
  readonly categories: readonly Category[];
  readonly assessments: readonly Assessment[];
  readonly summary: AssessmentsSummary;
};

export function Assessments({
  categories,
  assessments,
  summary,
}: AssessmentsProps): JSX.Element {
  const metaLine = `${summary.due} due, ${summary.completed} completed`;

  return (
    <>
      <header className={styles.head}>
        <div className={styles.title}>Assessments</div>
        <div className={styles.bullet}>•</div>
        <div className={styles.meta}>{metaLine}</div>
        <div className={styles.spacer} />
        <button type="button" className={styles.cta}>
          <span className={styles.ctaPlus}>+</span>
          <span>Assign Assessment</span>
        </button>
      </header>

      <div className={styles.body}>
        <aside className={styles.side}>
          {categories.map((c) => {
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
          {assessments.map((a) => (
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
