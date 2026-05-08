// Report — Figma "Screen 14 — Report". Renders the AgentForge patient
// "Report" page: toolbar with report-type segmented control, date-range
// pill, Print and Download PDF actions, and a centered "paper" document
// with letterhead, patient block, allergies, active problems, and current
// medications.
//
// 1:1 port of the static PHP mock previously at
// /interface/patient_file/report/copilot_report.php. Demo-only / static —
// no DB. The PHP outer shell still owns the navy top nav and patient
// header2 banner; this component renders only the toolbar + paper.

import { useState } from 'react';
import type { BootContext } from '../../shared/lib/bootContext';
import styles from './Report.module.css';

type ReportType = 'comprehensive' | 'demographics' | 'visit' | 'custom';

type ReportTypeOption = {
  readonly key: ReportType;
  readonly label: string;
};

type Problem = {
  readonly icd: string;
  readonly name: string;
  readonly meta: string;
};

type Medication = {
  readonly name: string;
  readonly dose: string;
  readonly freq: string;
  readonly refill: string;
};

const REPORT_TYPES: readonly ReportTypeOption[] = [
  { key: 'comprehensive', label: 'Comprehensive' },
  { key: 'demographics',  label: 'Demographics only' },
  { key: 'visit',         label: 'Visit summary' },
  { key: 'custom',        label: 'Custom…' },
];

const PROBLEMS: readonly Problem[] = [
  { icd: 'E11.9', name: 'Type 2 Diabetes Mellitus, without complications', meta: 'Onset 2019 • Active' },
  { icd: 'I10',   name: 'Essential (primary) hypertension',                 meta: 'Onset 2017 • Active' },
  { icd: 'E03.9', name: 'Hypothyroidism, unspecified',                       meta: 'Onset 2021 • Active' },
  { icd: 'M17.0', name: 'Bilateral primary osteoarthritis of knee',          meta: 'Onset 2022 • Active' },
];

const MEDS: readonly Medication[] = [
  { name: 'Metformin',     dose: '1000 mg', freq: 'BID with meals',              refill: 'Refilled 2024-10-30' },
  { name: 'Lisinopril',    dose: '10 mg',   freq: 'Daily — increased 04/01/2026', refill: 'Refilled 2024-10-30' },
  { name: 'Levothyroxine', dose: '50 mcg',  freq: 'Daily, AM',                    refill: 'Refilled 2024-10-30' },
  { name: 'Atorvastatin',  dose: '40 mg',   freq: 'Nightly',                      refill: 'Refilled 2024-07-17' },
];

type ReportProps = {
  readonly boot: BootContext;
};

export function Report(_props: ReportProps): JSX.Element {
  const [selected, setSelected] = useState<ReportType>('comprehensive');

  return (
    <>
      <header className={styles.toolbar}>
        <div className={styles.title}>Patient Report</div>
        <div className={styles.seg} role="tablist">
          {REPORT_TYPES.map((opt) => {
            const active = opt.key === selected;
            const cls = active ? `${styles.segOpt} ${styles.segOptActive}` : styles.segOpt;
            return (
              <button
                key={opt.key}
                type="button"
                role="tab"
                aria-selected={active}
                className={cls}
                onClick={() => setSelected(opt.key)}
              >
                {opt.label}
              </button>
            );
          })}
        </div>
        <div className={styles.spacer} />
        <span className={styles.pill}>
          <span>{'📅'}</span>
          <span className={styles.pillLabel}>Last 12 months</span>
          <span className={styles.pillCaret}>{'▾'}</span>
        </span>
        <button type="button" className={`${styles.pill} ${styles.print}`}>
          {'⎙'} Print
        </button>
        <button type="button" className={styles.pdfBtn}>Download PDF</button>
      </header>

      <main className={styles.stage}>
        <article className={styles.paper}>
          <div className={styles.letterhead}>
            <div className={styles.letterheadTop}>
              <div className={styles.logo} />
              <div>
                <div className={styles.name}>Riverside Family Medicine</div>
                <div className={styles.addr}>
                  847 Main Street, Suite 200 {'•'} Austin, TX 78701 {'•'} (512) 555-0142
                </div>
              </div>
            </div>
            <div className={styles.doctitle}>PATIENT REPORT</div>
            <div className={styles.gen}>Generated 04/29/2026 by Dr. Eduardo Rivera, MD</div>
            <div className={styles.rule} />
          </div>

          <section className={styles.section}>
            <div className={styles.head}>PATIENT</div>
            <div className={styles.patBox}>
              <div className={styles.col}>
                <div className={styles.nm}>Margaret Chen</div>
                <div className={styles.sub}>F {'•'} 68 years {'•'} DOB 03/14/1958</div>
                <div className={styles.sub}>MRN #004821 {'•'} Member since 2017</div>
              </div>
              <div className={styles.col}>
                <div className={styles.ins}>Insurance: Blue Cross Blue Shield PPO</div>
                <div className={styles.sub}>Group #BCBS-7281 {'•'} Member ID 4QF23-991</div>
                <div className={styles.sub}>Primary Provider: Dr. Eduardo Rivera, MD</div>
              </div>
            </div>
          </section>

          <section className={styles.section}>
            <div className={styles.head}>ALLERGIES &amp; REACTIONS</div>
            <div className={styles.allergyBox}>
              <div>Penicillin {'—'} Mild reaction (itching, rash). Reviewed 02/18/2026.</div>
              <div>Sulfa drugs {'—'} Mild skin reaction. Reviewed 02/18/2026.</div>
            </div>
          </section>

          <section className={styles.section}>
            <div className={styles.head}>ACTIVE PROBLEMS</div>
            <div className={styles.table}>
              {PROBLEMS.map((p) => (
                <div key={p.icd} className={styles.row}>
                  <span className={styles.icd}>{p.icd}</span>
                  <span className={styles.icdName}>{p.name}</span>
                  <span className={styles.flex} />
                  <span className={styles.meta}>{p.meta}</span>
                </div>
              ))}
            </div>
          </section>

          <section className={styles.section}>
            <div className={styles.head}>{`CURRENT MEDICATIONS (${MEDS.length})`}</div>
            <div className={styles.table}>
              {MEDS.map((m) => (
                <div key={m.name} className={`${styles.row} ${styles.med}`}>
                  <span className={styles.medName}>{m.name}</span>
                  <span className={styles.medDose}>{m.dose}</span>
                  <span className={styles.medSep}>{'•'}</span>
                  <span className={styles.medFreq}>{m.freq}</span>
                  <span className={styles.flex} />
                  <span className={styles.medRefill}>{m.refill}</span>
                </div>
              ))}
            </div>
          </section>
        </article>
      </main>
    </>
  );
}
