// CreateVisit — Figma "Screen 29 — Create Visit". Renders the page header
// (title + bullet + subtitle + Help pill), a two-column body whose LEFT card
// is the visit-builder (Visit Type 3x2 grid, Scheduling, Visit Template,
// Chief Complaint) and whose RIGHT rail is Quick Context (Last Visit, Open
// Orders, Care Gaps & Alerts, Co-Pilot Suggestion), and a bottom action bar
// (Cancel / Save draft / Start visit).
//
// This is a 1:1 port of the visual layer of the PHP-rendered mock previously
// at /interface/forms/newpatient/copilot_create_visit.php. The original PHP
// was DB-backed (queried users/facility/form_encounter/procedure_order/
// patient_reminders and ran an INSERT on POST); this React port uses
// hardcoded demo data matching the Figma frame so the shell can be migrated
// independently of the data layer. Wiring this back to a real
// /apis/copilot/visits endpoint is a follow-up.
//
// All field values, the selected visit-type card, and the template select
// value are held in local React state and behave identically to the static
// Figma frame: Office Visit selected, Dr. Eduardo Rivera + Riverside Family
// Medicine prefilled, 05/02/2026 10:30 AM 30 min Exam 3, Diabetes follow-up
// template, demo chief-complaint text from the mock.

import { useState } from 'react';
import type { BootContext } from '../../shared/lib/bootContext';
import styles from './CreateVisit.module.css';

type VisitTypeKey =
  | 'office'
  | 'telehealth'
  | 'annual'
  | 'followup'
  | 'acute'
  | 'procedure';

type VisitType = {
  readonly key: VisitTypeKey;
  readonly emoji: string;
  readonly name: string;
  readonly meta: string;
};

type OpenOrder = {
  readonly emoji: string;
  readonly name: string;
  readonly sub: string;
  readonly dotColor: string;
};

type CareGap = {
  readonly tone: 'warn' | 'info' | 'neutral';
  readonly emoji: string;
  readonly text: string;
};

// Demo data lifted directly from the Figma frame (Screen 29).
const VISIT_TYPES: readonly VisitType[] = [
  { key: 'office',     emoji: '\u{1FA7A}', name: 'Office Visit',     meta: 'In-person, 30 min' },
  { key: 'telehealth', emoji: '\u{1F4BB}', name: 'Telehealth',       meta: 'Video, 20 min' },
  { key: 'annual',     emoji: '\u{1F4CB}', name: 'Annual Physical',  meta: 'In-person, 60 min' },
  { key: 'followup',   emoji: '\u{1F501}', name: 'Follow-up',        meta: 'In-person, 15 min' },
  { key: 'acute',      emoji: '\u{1F6A8}', name: 'Acute / Same-day', meta: 'In-person, 20 min' },
  { key: 'procedure',  emoji: '\u{1F489}', name: 'Procedure',        meta: 'In-person, varies' },
];

const PROVIDERS: readonly string[] = [
  'Dr. Eduardo Rivera, MD',
  'Dr. Sarah Kim, DO',
  'Dr. James Patel, MD',
];

const FACILITIES: readonly string[] = [
  'Riverside Family Medicine',
  'Downtown Internal Medicine',
  'Eastside Urgent Care',
];

const DURATIONS: readonly string[] = ['15 min', '20 min', '30 min', '45 min', '60 min'];

const ROOMS: readonly string[] = ['Exam 1', 'Exam 2', 'Exam 3', 'Exam 4', 'Telehealth'];

const TEMPLATES: ReadonlyArray<{ readonly key: string; readonly label: string }> = [
  { key: 'diabetes_followup', label: 'Diabetes follow-up — vitals, A1C review, medication reconciliation, foot exam' },
  { key: 'annual_physical',   label: 'Annual Physical — full ROS, preventive screenings, immunizations' },
  { key: 'acute_visit',       label: 'Acute visit — focused HPI, exam, treatment plan' },
  { key: 'telehealth',        label: 'Telehealth — chief complaint, MDM, e-prescribe' },
  { key: 'blank',             label: 'Blank note — no template' },
];

const OPEN_ORDERS: readonly OpenOrder[] = [
  { emoji: '\u{1F4CB}', name: 'HbA1c lab',       sub: 'Due 04/30/2026',       dotColor: '#FA8C33' },
  { emoji: '\u{1F48A}', name: 'Metformin refill', sub: 'Pharmacy faxed 04/28', dotColor: '#1F8C4D' },
  { emoji: '\u{1FA7A}', name: 'Foot exam',        sub: 'Annual diabetic',       dotColor: '#4785D9' },
];

const CARE_GAPS: readonly CareGap[] = [
  { tone: 'warn',    emoji: '⚠',     text: 'Mammogram overdue (last 2023)' },
  { tone: 'info',    emoji: '\u{1F489}',  text: 'Flu shot — 2025/26 season' },
  { tone: 'neutral', emoji: '\u{1F9B4}',  text: 'DEXA scan — due Q3 2026' },
];

const PATIENT_NAME = 'Margaret Chen';
const DEFAULT_CHIEF_COMPLAINT =
  'Routine 3-month diabetes follow-up; pt also reports occasional knee pain on stairs (R > L).';

type CreateVisitProps = {
  readonly boot: BootContext;
};

export function CreateVisit(_props: CreateVisitProps): JSX.Element {
  const [visitType, setVisitType] = useState<VisitTypeKey>('office');
  const [provider, setProvider] = useState<string>(PROVIDERS[0] ?? '');
  const [facility, setFacility] = useState<string>(FACILITIES[0] ?? '');
  const [date, setDate] = useState<string>('05/02/2026');
  const [time, setTime] = useState<string>('10:30 AM');
  const [duration, setDuration] = useState<string>('30 min');
  const [room, setRoom] = useState<string>('Exam 3');
  const [template, setTemplate] = useState<string>('diabetes_followup');
  const [chiefComplaint, setChiefComplaint] = useState<string>(DEFAULT_CHIEF_COMPLAINT);

  return (
    <>
      <header className={styles.pageHead}>
        <div className={styles.titleRow}>
          <span className={styles.title}>Create Visit</span>
          <span className={styles.dot}>•</span>
          <span className={styles.subtitle}>Start a new encounter for {PATIENT_NAME}</span>
        </div>
        <button className={styles.helpPill} type="button">? Help</button>
      </header>

      <main className={styles.content}>
        <div className={styles.shell}>

          {/* LEFT: visit-builder */}
          <section className={styles.leftCard}>

            <div className={styles.section}>
              <div className={styles.secLbl}>VISIT TYPE</div>
              <div className={styles.vtGrid}>
                {VISIT_TYPES.map((vt) => {
                  const sel = vt.key === visitType;
                  const cls = sel ? `${styles.vtCard} ${styles.vtCardSel}` : styles.vtCard;
                  return (
                    <button
                      key={vt.key}
                      type="button"
                      className={cls}
                      onClick={() => setVisitType(vt.key)}
                    >
                      <span className={styles.vtEmoji}>{vt.emoji}</span>
                      <span className={styles.vtInfo}>
                        <span className={styles.vtName}>{vt.name}</span>
                        <span className={styles.vtMeta}>{vt.meta}</span>
                        {sel && <span className={styles.vtSelected}>{'✓'} Selected</span>}
                      </span>
                    </button>
                  );
                })}
              </div>
            </div>

            <div className={styles.section}>
              <div className={styles.secLbl}>SCHEDULING</div>
              <div className={styles.row2}>
                <Field label="Provider">
                  <select
                    className={`${styles.input} ${styles.select}`}
                    value={provider}
                    onChange={(e) => setProvider(e.target.value)}
                  >
                    {PROVIDERS.map((p) => <option key={p} value={p}>{p}</option>)}
                  </select>
                </Field>
                <Field label="Facility">
                  <select
                    className={`${styles.input} ${styles.select}`}
                    value={facility}
                    onChange={(e) => setFacility(e.target.value)}
                  >
                    {FACILITIES.map((f) => <option key={f} value={f}>{f}</option>)}
                  </select>
                </Field>
              </div>
              <div className={styles.row4}>
                <Field label="Date">
                  <input
                    type="text"
                    className={styles.input}
                    value={date}
                    onChange={(e) => setDate(e.target.value)}
                  />
                </Field>
                <Field label="Time">
                  <input
                    type="text"
                    className={styles.input}
                    value={time}
                    onChange={(e) => setTime(e.target.value)}
                  />
                </Field>
                <Field label="Duration">
                  <select
                    className={`${styles.input} ${styles.select}`}
                    value={duration}
                    onChange={(e) => setDuration(e.target.value)}
                  >
                    {DURATIONS.map((d) => <option key={d} value={d}>{d}</option>)}
                  </select>
                </Field>
                <Field label="Room">
                  <select
                    className={`${styles.input} ${styles.select}`}
                    value={room}
                    onChange={(e) => setRoom(e.target.value)}
                  >
                    {ROOMS.map((r) => <option key={r} value={r}>{r}</option>)}
                  </select>
                </Field>
              </div>
            </div>

            <div className={styles.section}>
              <div className={styles.secLbl}>VISIT TEMPLATE</div>
              <Field label="Apply template">
                <select
                  className={`${styles.input} ${styles.select}`}
                  value={template}
                  onChange={(e) => setTemplate(e.target.value)}
                >
                  {TEMPLATES.map((t) => <option key={t.key} value={t.key}>{t.label}</option>)}
                </select>
              </Field>
            </div>

            <div className={styles.section}>
              <div className={styles.secLbl}>CHIEF COMPLAINT / REASON</div>
              <textarea
                className={styles.textarea}
                value={chiefComplaint}
                onChange={(e) => setChiefComplaint(e.target.value)}
                placeholder="Briefly describe the reason for today's visit"
              />
            </div>

          </section>

          {/* RIGHT: Quick Context rail (single white card per Figma) */}
          <aside className={styles.rightCard}>

            <div className={styles.secLbl}>QUICK CONTEXT</div>

            <div className={styles.lastVisit}>
              <div className={styles.lvLbl}>LAST VISIT</div>
              <div className={styles.lvTitle}>Annual physical {'—'} Dr. Rivera</div>
              <div className={styles.lvMeta}>02/18/2026 {'•'} 30 min {'•'} Signed</div>
              <div className={styles.lvNotes}>
                Notes: BP slightly elevated, A1C trending up {'—'} recommended dietary review.
              </div>
            </div>

            <div className={styles.railLbl}>OPEN ORDERS</div>
            {OPEN_ORDERS.map((o) => (
              <div key={o.name} className={styles.orderRow}>
                <span className={styles.orderEmoji}>{o.emoji}</span>
                <span className={styles.orderInfo}>
                  <span className={styles.orderName}>{o.name}</span>
                  <span className={styles.orderSub}>{o.sub}</span>
                </span>
                <span className={styles.orderDot} style={{ backgroundColor: o.dotColor }} />
              </div>
            ))}

            <div className={styles.railLbl}>CARE GAPS &amp; ALERTS</div>
            {CARE_GAPS.map((g) => {
              const cls =
                g.tone === 'warn' ? `${styles.gap} ${styles.gapWarn}` :
                g.tone === 'info' ? `${styles.gap} ${styles.gapInfo}` :
                                    `${styles.gap} ${styles.gapNeutral}`;
              return (
                <div key={g.text} className={cls}>
                  <span className={styles.gapEmoji}>{g.emoji}</span>
                  <span>{g.text}</span>
                </div>
              );
            })}

            <div className={styles.cpCard}>
              <div className={styles.cpLbl}>{'✦'} CO-PILOT SUGGESTION</div>
              <div className={styles.cpTitle}>Pre-visit summary ready</div>
              <div className={styles.cpBody}>
                Last A1C 7.9% ({'↑'} from 7.2%). BP trending down. Consider GLP-1 discussion based on weight trend.
              </div>
              <button type="button" className={styles.cpOpen}>
                Open in Co-Pilot {'→'}
              </button>
            </div>

          </aside>

          {/* BOTTOM: action bar */}
          <div className={styles.actions}>
            <button type="button" className={styles.btnGhost}>Cancel</button>
            <button type="button" className={styles.btnGhost}>Save draft</button>
            <button type="button" className={styles.btnPrimary}>Start visit {'→'}</button>
          </div>

        </div>
      </main>
    </>
  );
}

type FieldProps = {
  readonly label: string;
  readonly children: React.ReactNode;
};

function Field({ label, children }: FieldProps): JSX.Element {
  return (
    <div className={styles.field}>
      <label className={styles.fieldLabel}>{label}</label>
      {children}
    </div>
  );
}
