// NewPatient — Figma "Screen 22 — New / Search Patient". Renders the page
// header (title + bullet + subtitle + Help pill), a two-column body whose
// LEFT pane is "Find existing patient" (search input, status pill row,
// Recent list) and whose RIGHT pane is "Create new patient" (Identity,
// Contact, Insurance, Provider sections + Save-as-draft / Create patient
// CTAs).
//
// This is a 1:1 port of the visual layer of the PHP-rendered mock previously
// at /interface/new/copilot_new_patient.php. The original PHP was DB-backed
// (queried patient_data for the Recent list and ran INSERTs on POST); this
// React port uses hardcoded demo data matching the Figma frame so the shell
// can be migrated independently of the data layer. Wiring this back to a
// real /apis/copilot/patients endpoint is a follow-up.
//
// Search query, the active status tab, the highlighted recent row, and all
// form-field values are held in local React state and behave identically to
// the static Figma frame: search box empty with placeholder, "All" tab
// active, no recent row pre-selected, Identity pre-filled with Margaret
// Chen's demo values.

import { useState } from 'react';
import type { BootContext } from '../../shared/lib/bootContext';
import styles from './NewPatient.module.css';

type StatusTab = 'all' | 'active' | 'last7' | 'inactive';

type RecentPatient = {
  readonly id: number;
  readonly name: string;
  readonly mrn: string;
  readonly dob: string;
  readonly when: string;
  readonly avatarColor: string;
  readonly initials: string;
};

// Demo data lifted directly from the Figma frame (Screen 22). Avatar colors
// chosen from the palette already in use across Finder/Calendar.
const RECENT_PATIENTS: readonly RecentPatient[] = [
  {
    id: 1,
    name: 'Margaret Chen',
    mrn: '#004821',
    dob: '03/14/1958',
    when: 'Today',
    avatarColor: '#5FD0D0',
    initials: 'MC',
  },
  {
    id: 2,
    name: 'Ted Shaw',
    mrn: '#001',
    dob: '03/12/1965',
    when: 'Today',
    avatarColor: '#4785D9',
    initials: 'TS',
  },
  {
    id: 3,
    name: 'Linda Martinez',
    mrn: '#003918',
    dob: '11/02/1947',
    when: 'Yesterday',
    avatarColor: '#8561C7',
    initials: 'LM',
  },
  {
    id: 4,
    name: 'David Kim',
    mrn: '#006102',
    dob: '06/18/1981',
    when: 'Apr 28',
    avatarColor: '#FA8C33',
    initials: 'DK',
  },
  {
    id: 5,
    name: 'Allison Park',
    mrn: '#002745',
    dob: '09/30/1973',
    when: 'Apr 26',
    avatarColor: '#33A666',
    initials: 'AP',
  },
];

const STATUS_TABS: readonly { key: StatusTab; label: string }[] = [
  { key: 'all',      label: 'All' },
  { key: 'active',   label: 'Active' },
  { key: 'last7',    label: 'Last 7 days' },
  { key: 'inactive', label: 'Inactive' },
];

type NewPatientProps = {
  readonly boot: BootContext;
};

type FormState = {
  readonly fname: string;
  readonly lname: string;
  readonly dob: string;
  readonly sex: string;
  readonly phone: string;
  readonly email: string;
  readonly address: string;
  readonly plan: string;
  readonly groupNumber: string;
  readonly memberId: string;
  readonly provider: string;
  readonly facility: string;
};

const INITIAL_FORM: FormState = {
  fname: 'Margaret',
  lname: 'Chen',
  dob: '1958-03-14',
  sex: 'Female',
  phone: '(512) 555-0142',
  email: 'm.chen@example.com',
  address: '847 Main Street, Suite 200, Austin, TX 78701',
  plan: 'Blue Cross Blue Shield PPO',
  groupNumber: 'BCBS-7281',
  memberId: '4QF23-991',
  provider: 'Dr. Eduardo Rivera, MD',
  facility: 'Riverside Family Medicine',
};

export function NewPatient(_props: NewPatientProps): JSX.Element {
  const [query, setQuery] = useState<string>('');
  const [activeTab, setActiveTab] = useState<StatusTab>('all');
  const [selectedId, setSelectedId] = useState<number | null>(null);
  const [form, setForm] = useState<FormState>(INITIAL_FORM);

  const updateField = <K extends keyof FormState>(key: K, value: FormState[K]): void => {
    setForm((prev) => ({ ...prev, [key]: value }));
  };

  return (
    <>
      <header className={styles.head}>
        <div className={styles.title}>New / Search Patient</div>
        <div className={styles.bullet}>{'•'}</div>
        <div className={styles.meta}>Create or look up a patient record</div>
        <div className={styles.spacer} />
        <button type="button" className={styles.help}>? Help</button>
      </header>

      <main className={styles.body}>
        {/* LEFT — find existing */}
        <section className={`${styles.panel} ${styles.findPanel}`}>
          <div className={styles.panelLbl}>FIND EXISTING PATIENT</div>

          <label className={styles.searchWrap}>
            <span className={styles.searchIcon} aria-hidden="true">{'🔍'}</span>
            <input
              className={styles.searchInput}
              type="text"
              value={query}
              onChange={(e) => setQuery(e.target.value)}
              placeholder="Name, MRN, DOB, phone, or email"
              autoComplete="off"
              aria-label="Search patients"
            />
          </label>

          <div className={styles.tabs} role="tablist">
            {STATUS_TABS.map((t) => {
              const active = t.key === activeTab;
              const cls = active ? `${styles.tab} ${styles.tabActive}` : styles.tab;
              return (
                <button
                  key={t.key}
                  type="button"
                  className={cls}
                  role="tab"
                  aria-selected={active}
                  onClick={() => setActiveTab(t.key)}
                >
                  {t.label}
                </button>
              );
            })}
          </div>

          <div className={styles.recentLbl}>RECENT</div>

          <div className={styles.recList}>
            {RECENT_PATIENTS.map((r) => {
              const selected = r.id === selectedId;
              const rowCls = selected
                ? `${styles.recRow} ${styles.recRowSelected}`
                : styles.recRow;
              return (
                <button
                  key={r.id}
                  type="button"
                  className={rowCls}
                  onClick={() => setSelectedId(r.id)}
                  title={`Open ${r.name}`}
                >
                  <span
                    className={styles.recAvatar}
                    style={{ backgroundColor: r.avatarColor }}
                  >
                    {r.initials}
                  </span>
                  <span className={styles.recInfo}>
                    <span className={styles.recName}>{r.name}</span>
                    <span className={styles.recSub}>
                      MRN {r.mrn} {'•'} DOB {r.dob}
                    </span>
                  </span>
                  <span className={styles.recWhen}>{r.when}</span>
                </button>
              );
            })}
          </div>
        </section>

        {/* RIGHT — create new */}
        <section className={`${styles.panel} ${styles.createPanel}`}>
          <div className={styles.panelLbl}>CREATE NEW PATIENT</div>

          <div className={styles.section}>
            <h3 className={styles.sectionTitle}>Identity</h3>
            <div className={`${styles.grid} ${styles.gridTwo}`}>
              <Field label="First name">
                <input
                  className={styles.input}
                  type="text"
                  value={form.fname}
                  onChange={(e) => updateField('fname', e.target.value)}
                />
              </Field>
              <Field label="Last name">
                <input
                  className={styles.input}
                  type="text"
                  value={form.lname}
                  onChange={(e) => updateField('lname', e.target.value)}
                />
              </Field>
              <Field label="Date of birth">
                <input
                  className={styles.input}
                  type="date"
                  value={form.dob}
                  onChange={(e) => updateField('dob', e.target.value)}
                />
              </Field>
              <Field label="Sex">
                <select
                  className={`${styles.input} ${styles.select}`}
                  value={form.sex}
                  onChange={(e) => updateField('sex', e.target.value)}
                >
                  <option>Female</option>
                  <option>Male</option>
                  <option>Other</option>
                </select>
              </Field>
            </div>
          </div>

          <div className={styles.section}>
            <h3 className={styles.sectionTitle}>Contact</h3>
            <div className={`${styles.grid} ${styles.gridTwo}`}>
              <Field label="Phone">
                <input
                  className={styles.input}
                  type="text"
                  value={form.phone}
                  onChange={(e) => updateField('phone', e.target.value)}
                />
              </Field>
              <Field label="Email">
                <input
                  className={styles.input}
                  type="email"
                  value={form.email}
                  onChange={(e) => updateField('email', e.target.value)}
                />
              </Field>
            </div>
            <div className={`${styles.grid} ${styles.gridFull} ${styles.gridGap}`}>
              <Field label="Address">
                <input
                  className={styles.input}
                  type="text"
                  value={form.address}
                  onChange={(e) => updateField('address', e.target.value)}
                />
              </Field>
            </div>
          </div>

          <div className={styles.section}>
            <h3 className={styles.sectionTitle}>Insurance</h3>
            <div className={`${styles.grid} ${styles.gridThree}`}>
              <Field label="Plan">
                <select
                  className={`${styles.input} ${styles.select}`}
                  value={form.plan}
                  onChange={(e) => updateField('plan', e.target.value)}
                >
                  <option>Blue Cross Blue Shield PPO</option>
                  <option>Aetna HMO</option>
                  <option>Self-pay</option>
                </select>
              </Field>
              <Field label="Group #">
                <input
                  className={styles.input}
                  type="text"
                  value={form.groupNumber}
                  onChange={(e) => updateField('groupNumber', e.target.value)}
                />
              </Field>
              <Field label="Member ID">
                <input
                  className={styles.input}
                  type="text"
                  value={form.memberId}
                  onChange={(e) => updateField('memberId', e.target.value)}
                />
              </Field>
            </div>
          </div>

          <div className={styles.section}>
            <h3 className={styles.sectionTitle}>Provider</h3>
            <div className={`${styles.grid} ${styles.gridTwo}`}>
              <Field label="Primary provider">
                <select
                  className={`${styles.input} ${styles.select}`}
                  value={form.provider}
                  onChange={(e) => updateField('provider', e.target.value)}
                >
                  <option>Dr. Eduardo Rivera, MD</option>
                  <option>Dr. Allison Park, DO</option>
                  <option>Dr. James Patel, MD</option>
                </select>
              </Field>
              <Field label="Facility">
                <select
                  className={`${styles.input} ${styles.select}`}
                  value={form.facility}
                  onChange={(e) => updateField('facility', e.target.value)}
                >
                  <option>Riverside Family Medicine</option>
                  <option>Eastside Clinic</option>
                </select>
              </Field>
            </div>
          </div>

          <div className={styles.foot}>
            <button type="button" className={`${styles.btn} ${styles.btnSecondary}`}>
              Save as draft
            </button>
            <button type="button" className={`${styles.btn} ${styles.btnPrimary}`}>
              Create patient {'→'}
            </button>
          </div>
        </section>
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
