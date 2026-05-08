// Issues — Figma "Screen 17 — Issues" (file kj4MWNr8mpjZ2wVg1PbS0F, node 38:2).
//
// Patient-context Issues page. Static demo only — values mirror the Figma
// mock exactly so the side-by-side fidelity check passes. The PHP outer
// shell at /interface/main/tabs/main.php still owns the navy top nav and
// patient header2 banner; this file renders inside the #maimain iframe and
// skips those chrome elements.
//
// Wiring to real lists data (medical_problem / allergy rows) is a follow-up
// task; the original DB-driven PHP is preserved at copilot_issues.php.bak
// for reference.

import { useState } from 'react';
import type { BootContext } from '../../shared/lib/bootContext';
import styles from './Issues.module.css';

type StatusTab = 'active' | 'inactive' | 'resolved' | 'all';

type SeverityTone = 'warn' | 'good';

type IssueRow = {
  readonly icd: string;
  readonly title: string;
  readonly sub: string;
  readonly sev: string;
  readonly sevTone: SeverityTone;
};

type IssueGroup = {
  readonly title: string;
  readonly count: number;
  readonly dotTone: 'warn' | 'danger' | 'good';
  readonly rows: readonly IssueRow[];
};

const SUMMARY_LABEL = 'Active 9 • Inactive 4 • Resolved 12';

const STATUS_TABS: ReadonlyArray<{ readonly id: StatusTab; readonly label: string }> = [
  { id: 'active',   label: 'Active'   },
  { id: 'inactive', label: 'Inactive' },
  { id: 'resolved', label: 'Resolved' },
  { id: 'all',      label: 'All'      },
];

const GROUPS: readonly IssueGroup[] = [
  {
    title: 'Active — Medical Problems',
    count: 4,
    dotTone: 'warn',
    rows: [
      { icd: 'E11.9', title: 'Type 2 Diabetes Mellitus',     sub: 'Onset 2019 • Active',                  sev: 'MODERATE', sevTone: 'warn' },
      { icd: 'I10',   title: 'Essential Hypertension',       sub: 'Onset 2017 • Controlled w/ Lisinopril', sev: 'MODERATE', sevTone: 'warn' },
      { icd: 'E03.9', title: 'Hypothyroidism, unspecified',  sub: 'Onset 2021 • Levothyroxine 50mcg daily', sev: 'STABLE',   sevTone: 'good' },
      { icd: 'M17.0', title: 'Bilateral knee osteoarthritis', sub: 'Onset 2022 • Conservative management',  sev: 'MILD',     sevTone: 'good' },
    ],
  },
  {
    title: 'Active — Allergies & Risk Factors',
    count: 3,
    dotTone: 'danger',
    rows: [
      { icd: 'Z88.0', title: 'Allergy to Penicillin',                   sub: 'Documented 2017 • Itching, rash',  sev: 'MILD',     sevTone: 'good' },
      { icd: 'Z88.2', title: 'Allergy to Sulfa drugs',                  sub: 'Documented 2017 • Skin reaction',  sev: 'MILD',     sevTone: 'good' },
      { icd: 'Z83.3', title: 'Family hx of diabetes (mother, brother)', sub: 'Documented 2019 • Risk factor',    sev: 'ADVISORY', sevTone: 'good' },
    ],
  },
];

type IssuesProps = {
  readonly boot: BootContext;
};

export function Issues(_props: IssuesProps): JSX.Element {
  const [status, setStatus] = useState<StatusTab>('active');

  return (
    <>
      <header className={styles.head}>
        <div className={styles.title}>Issues</div>
        <div className={styles.bullet}>•</div>
        <div className={styles.meta}>{SUMMARY_LABEL}</div>
        <div className={styles.spacer} />
        <div className={styles.seg} role="tablist">
          {STATUS_TABS.map((t) => {
            const active = t.id === status;
            const cls = active ? `${styles.opt} ${styles.optActive}` : styles.opt;
            return (
              <button
                key={t.id}
                type="button"
                role="tab"
                aria-selected={active}
                className={cls}
                onClick={() => setStatus(t.id)}
              >
                {t.label}
              </button>
            );
          })}
        </div>
        <button type="button" className={styles.addBtn}>
          <span className={styles.plus}>+</span>
          <span>Add Issue</span>
        </button>
      </header>

      <main className={styles.body}>
        {GROUPS.map((grp) => (
          <section key={grp.title} className={styles.grpCard}>
            <header className={styles.grpHead}>
              <span className={`${styles.dot} ${dotClass(grp.dotTone)}`} />
              <span className={styles.grpTitle}>{grp.title}</span>
              <span className={styles.grpCount}>{grp.count}</span>
            </header>
            {grp.rows.map((r, i) => {
              const isLast = i === grp.rows.length - 1;
              const rowCls = isLast ? `${styles.row} ${styles.rowLast}` : styles.row;
              return (
                <div key={r.icd + r.title} className={rowCls}>
                  <span className={styles.icd}>{r.icd}</span>
                  <div className={styles.rowInfo}>
                    <div className={styles.rowTitle}>{r.title}</div>
                    <div className={styles.rowSub}>{r.sub}</div>
                  </div>
                  <span className={styles.rowSpacer} />
                  <span className={`${styles.sevPill} ${sevClass(r.sevTone)}`}>{r.sev}</span>
                  <span className={styles.kebab} aria-hidden="true">⋯</span>
                </div>
              );
            })}
          </section>
        ))}
      </main>
    </>
  );
}

function dotClass(tone: 'warn' | 'danger' | 'good'): string {
  if (tone === 'warn') {
    return styles.dotWarn ?? '';
  }
  if (tone === 'danger') {
    return styles.dotDanger ?? '';
  }
  return styles.dotGood ?? '';
}

function sevClass(tone: SeverityTone): string {
  if (tone === 'warn') {
    return styles.sevWarn ?? '';
  }
  return styles.sevGood ?? '';
}
