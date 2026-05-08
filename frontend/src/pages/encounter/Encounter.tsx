// Encounter — Figma "Screen 23 — Encounter Detail". Renders the active-visit
// chart body: Today's Visit header + status, vitals strip, SOAP-tabbed left
// column (Subjective/Objective/Assessment/Plan) and a right rail with Active
// Orders, Diagnoses for this visit, and a Co-Pilot suggestion card.
//
// 1:1 port of the PHP-rendered mock previously at
// /interface/patient_file/encounter/copilot_encounter.php. State is held in
// React but behaves identically to the static version: hardcoded demo data
// (Margaret Chen, 99213 Office Visit, OPEN), Subjective tab is the default
// active tab, no DB. The navy nav and demographics banner live in the parent
// shell — this page renders only the body.
//
// Verified against Figma node 51:2 on 2026-05-07.

import { useState } from 'react';
import type { BootContext } from '../../shared/lib/bootContext';
import styles from './Encounter.module.css';

type SoapTab = 'Subjective' | 'Objective' | 'Assessment' | 'Plan';

type Vital = {
  readonly label: string;
  readonly value: string;
  readonly unit: string;
  readonly tone?: 'good' | undefined;
};

type RosItem = {
  readonly system: string;
  readonly kind: 'ok' | 'flag';
};

type OrderRow = {
  readonly type: string;
  readonly name: string;
  readonly meta: string;
};

type DiagnosisRow = {
  readonly code: string;
  readonly name: string;
};

const SOAP_TABS: readonly SoapTab[] = ['Subjective', 'Objective', 'Assessment', 'Plan'];

const VITALS: readonly Vital[] = [
  { label: 'BP',   value: '128/82', unit: 'mmHg' },
  { label: 'HR',   value: '74',     unit: 'bpm' },
  { label: 'TEMP', value: '98.4',   unit: '°F' },
  { label: 'SPO₂', value: '98',     unit: '%' },
  { label: 'WT',   value: '156',    unit: 'lbs' },
  { label: 'BMI',  value: '24.6',   unit: 'kg/m²', tone: 'good' },
];

const ROS: readonly RosItem[] = [
  { system: 'Constitutional', kind: 'ok' },
  { system: 'Cardio',         kind: 'ok' },
  { system: 'Pulmonary',      kind: 'ok' },
  { system: 'GI',             kind: 'ok' },
  { system: 'Endo',           kind: 'flag' },
  { system: 'Neuro',          kind: 'ok' },
  { system: 'MSK',            kind: 'ok' },
  { system: 'Skin',           kind: 'ok' },
];

const ORDERS: readonly OrderRow[] = [
  { type: 'Lab',     name: 'HbA1c (today)',         meta: 'In transit' },
  { type: 'Lab',     name: 'BMP (today)',           meta: 'In transit' },
  { type: 'Imaging', name: 'Foot Doppler (Apr 30)', meta: 'Scheduled' },
];

const DIAGNOSES: readonly DiagnosisRow[] = [
  { code: 'E11.9', name: 'Type 2 Diabetes Mellitus' },
  { code: 'I10',   name: 'Essential hypertension' },
];

const CHIEF_COMPLAINT =
  'Patient here for routine 3-month diabetes follow-up. Reports recent fasting BG averaging 138 mg/dL, occasional readings >200 after large meals.';

const HPI_PARAS: readonly string[] = [
  '68F with Type 2 DM (E11.9) on Metformin 1000 mg BID and Lisinopril 10 mg daily. A1C trend: 7.9% (Apr) → 7.4% (Feb) → 7.2% (Nov). Adherent to medication; struggling with portion control at family meals.',
  'Denies polyuria, polydipsia, blurred vision. No new chest pain, dyspnea on exertion, or peripheral edema. Adheres to home BP log — averages 128/82.',
];

type EncounterProps = {
  readonly boot: BootContext;
};

export function Encounter(_props: EncounterProps): JSX.Element {
  const [activeTab, setActiveTab] = useState<SoapTab>('Subjective');

  return (
    <>
      <header className={styles.head}>
        <div className={styles.title}>Today&apos;s Visit</div>
        <div className={styles.bullet}>•</div>
        <div className={styles.meta}>Office Visit, Level 3 (99213) — Dr. E. Rivera</div>
        <span className={styles.status}>
          <span className={styles.statusDot} aria-hidden="true" />
          OPEN
        </span>
        <div className={styles.spacer} />
        <button type="button" className={`${styles.btn} ${styles.btnGhost}`}>
          ⎙ Print
        </button>
        <button type="button" className={`${styles.btn} ${styles.btnGhost}`}>
          Save draft
        </button>
        <button type="button" className={`${styles.btn} ${styles.btnPrimary}`}>
          Sign &amp; lock →
        </button>
      </header>

      <section className={styles.vitals}>
        <span className={styles.vitalsLbl}>VITALS</span>
        {VITALS.map((v) => (
          <div key={v.label} className={styles.vitalCard}>
            <div className={styles.vitalLbl}>{v.label}</div>
            <div className={styles.vitalValRow}>
              <span
                className={
                  v.tone === 'good'
                    ? `${styles.vitalVal} ${styles.vitalValGood}`
                    : styles.vitalVal
                }
              >
                {v.value}
              </span>
              <span className={styles.vitalUnit}>{v.unit}</span>
            </div>
          </div>
        ))}
        <div className={styles.vitalsSpacer} />
        <button type="button" className={styles.addMeas}>+ Add measurement</button>
      </section>

      <main className={styles.body}>
        <section className={styles.soapPanel}>
          <div className={styles.soapTabs} role="tablist">
            {SOAP_TABS.map((tab) => {
              const active = tab === activeTab;
              return (
                <button
                  key={tab}
                  type="button"
                  role="tab"
                  aria-selected={active}
                  className={
                    active
                      ? `${styles.soapTab} ${styles.soapTabActive}`
                      : styles.soapTab
                  }
                  onClick={() => setActiveTab(tab)}
                >
                  {tab}
                </button>
              );
            })}
          </div>

          <h4 className={styles.soapHeading}>Chief Complaint</h4>
          <div className={styles.soapText}>{CHIEF_COMPLAINT}</div>

          <h4 className={styles.soapHeading}>History of Present Illness</h4>
          <div className={styles.soapText}>
            {HPI_PARAS.map((p, i) => (
              <p key={i} className={styles.hpiPara}>{p}</p>
            ))}
          </div>

          <h4 className={styles.soapHeading}>Review of Systems</h4>
          <div className={styles.ros}>
            {ROS.map((r) => (
              <span
                key={r.system}
                className={
                  r.kind === 'flag'
                    ? `${styles.pill} ${styles.pillFlag}`
                    : styles.pill
                }
              >
                {r.system} {r.kind === 'flag' ? '⚠' : '✓'}
              </span>
            ))}
          </div>
        </section>

        <aside className={styles.rail}>
          <div className={styles.railCard}>
            <div className={styles.railHead}>
              <h4 className={styles.railTitle}>Active Orders</h4>
              <span className={styles.cnt}>3</span>
              <div className={styles.spacer} />
              <button type="button" className={styles.addBtn}>+ Add</button>
            </div>
            {ORDERS.map((o, i) => (
              <div
                key={o.name}
                className={
                  i === ORDERS.length - 1
                    ? styles.railRow
                    : `${styles.railRow} ${styles.railRowDivider}`
                }
              >
                <span className={styles.tag}>{o.type}</span>
                <span className={styles.rowName}>{o.name}</span>
                <span className={styles.rowMeta}>{o.meta}</span>
              </div>
            ))}
          </div>

          <div className={styles.railCard}>
            <div className={styles.railHead}>
              <h4 className={styles.railTitle}>Diagnoses for this visit</h4>
              <span className={styles.cnt}>2</span>
              <div className={styles.spacer} />
              <button type="button" className={styles.addBtn}>+ Add</button>
            </div>
            {DIAGNOSES.map((d, i) => (
              <div
                key={d.code}
                className={
                  i === DIAGNOSES.length - 1
                    ? styles.railRow
                    : `${styles.railRow} ${styles.railRowDivider}`
                }
              >
                <span className={`${styles.tag} ${styles.tagDx}`}>{d.code}</span>
                <span className={styles.rowName}>{d.name}</span>
              </div>
            ))}
          </div>

          <div className={styles.copilotCard}>
            <div className={styles.copilotHead}>
              <span className={styles.copilotSparkle}>✦</span>
              <span>Co-Pilot suggestion</span>
            </div>
            <p className={styles.copilotBody}>
              A1C trending up — consider GLP-1 agonist if no improvement at 3-month
              recheck. Patient counseled on portion control.
            </p>
            <div className={styles.copilotActions}>
              <button type="button" className={styles.copilotInsert}>Insert into note</button>
              <button type="button" className={styles.copilotDismiss}>Dismiss</button>
            </div>
          </div>
        </aside>
      </main>
    </>
  );
}
