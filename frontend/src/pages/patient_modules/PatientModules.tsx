// PatientModules — Figma "Screen 21 — Patient Modules" (file kj4MWNr8mpjZ2wVg1PbS0F,
// node 43:2). Renders the patient-context "Modules" navtab body: header with
// title, summary text, Active/Available/All segmented control, Browse
// Marketplace button; followed by two grouped grids (ACTIVE — CLINICAL and
// AVAILABLE — RECOMMENDED FOR THIS PATIENT) of module cards.
//
// 1:1 port of the static-HTML PHP mock previously at
// /interface/patient_file/modules/copilot_modules.php (preserved as
// copilot_modules.php.bak). Static demo data only — no DB, no API calls.
//
// The PHP wrapper is iframe-mounted under the navy top nav and patient
// header2 banner that the outer OpenEMR shell still renders, so this
// component intentionally skips both.
import { useState } from 'react';
import type { BootContext } from '../../shared/lib/bootContext';
import styles from './PatientModules.module.css';

type StatusFilter = 'active' | 'available' | 'all';
type IconTone = 'teal' | 'info' | 'violet' | 'mint' | 'warn' | 'pink';
type StatusTone = 'good' | 'warn' | 'neutral';
type ActionTone = 'primary' | 'warn' | 'secondary';

type Module = {
  readonly name: string;
  readonly icon: string;
  readonly iconTone: IconTone;
  readonly version: string;
  readonly vendor: string;
  readonly description: string;
  readonly status: string;
  readonly statusTone: StatusTone;
  readonly action: string;
  readonly actionTone: ActionTone;
};

type Section = {
  readonly heading: string;
  readonly items: readonly Module[];
};

const ACTIVE_CLINICAL: readonly Module[] = [
  {
    name: 'Care Coordination',
    icon: '🤝', iconTone: 'teal',
    version: 'v2.4.1', vendor: 'OpenEMR Foundation',
    description: 'Care plan, care team roster, transitions of care. Direct messaging integrated.',
    status: 'ACTIVE', statusTone: 'good',
    action: 'Open →', actionTone: 'primary',
  },
  {
    name: 'Clinical Decision Rules',
    icon: '✨', iconTone: 'info',
    version: 'v1.9.3', vendor: 'OpenEMR Foundation',
    description: 'CQM rules engine: drives reminders, alerts, and quality measure calculation.',
    status: 'ACTIVE', statusTone: 'good',
    action: 'Open →', actionTone: 'primary',
  },
  {
    name: 'EasiPRO',
    icon: '📊', iconTone: 'violet',
    version: 'v3.1.0', vendor: 'Northwestern',
    description: 'Patient-Reported Outcome instruments delivered through the Patient Portal.',
    status: 'ACTIVE', statusTone: 'good',
    action: 'Open →', actionTone: 'primary',
  },
  {
    name: 'ClinicalTables FHIR',
    icon: '🔗', iconTone: 'mint',
    version: 'v0.7.2', vendor: 'NLM',
    description: 'Code-set lookups for ICD-10, SNOMED, RxNorm via the FHIR ValueSet API.',
    status: 'UPDATE AVAILABLE', statusTone: 'warn',
    action: 'Update', actionTone: 'warn',
  },
];

const AVAILABLE: readonly Module[] = [
  {
    name: 'Diabetes Coach',
    icon: '🩸', iconTone: 'warn',
    version: 'v1.2.0', vendor: 'RiversideHealth',
    description: 'Glucose log integration, A1C trending, and Co-Pilot diabetes-focused prompts.',
    status: 'AVAILABLE', statusTone: 'neutral',
    action: 'Install', actionTone: 'secondary',
  },
  {
    name: 'Care Plan Templates',
    icon: '📋', iconTone: 'info',
    version: 'v0.9.1', vendor: 'OpenEMR Foundation',
    description: 'Condition-specific care plan templates with order sets and patient education.',
    status: 'AVAILABLE', statusTone: 'neutral',
    action: 'Install', actionTone: 'secondary',
  },
  {
    name: 'Pharmacy Sync',
    icon: '💊', iconTone: 'pink',
    version: 'v2.0.1', vendor: 'Surescripts',
    description: 'Two-way sync of medication history, including external prescriptions.',
    status: 'AVAILABLE', statusTone: 'neutral',
    action: 'Install', actionTone: 'secondary',
  },
  {
    name: 'Telehealth Studio',
    icon: '📹', iconTone: 'violet',
    version: 'v4.2.0', vendor: 'OpenEMR Foundation',
    description: 'Embedded video visits with screen-share, captioning, and visit recording.',
    status: 'AVAILABLE', statusTone: 'neutral',
    action: 'Install', actionTone: 'secondary',
  },
];

const SECTIONS: readonly Section[] = [
  { heading: 'ACTIVE — CLINICAL',                         items: ACTIVE_CLINICAL },
  { heading: 'AVAILABLE — RECOMMENDED FOR THIS PATIENT',  items: AVAILABLE },
];

type PatientModulesProps = {
  readonly boot: BootContext;
};

export function PatientModules(_props: PatientModulesProps): JSX.Element {
  const [filter, setFilter] = useState<StatusFilter>('active');

  return (
    <>
      <header className={styles.head}>
        <div className={styles.title}>Modules</div>
        <div className={styles.bullet}>•</div>
        <div className={styles.meta}>Patient-context modules • 4 active, 3 available</div>
        <div className={styles.spacer} />
        <div className={styles.seg} role="tablist">
          <FilterTab mode="active"    current={filter} onSelect={setFilter}>Active</FilterTab>
          <FilterTab mode="available" current={filter} onSelect={setFilter}>Available</FilterTab>
          <FilterTab mode="all"       current={filter} onSelect={setFilter}>All</FilterTab>
        </div>
        <button type="button" className={styles.marketplace}>Browse Marketplace →</button>
      </header>

      <main className={styles.body}>
        {SECTIONS.map((section) => (
          <section key={section.heading} className={styles.section}>
            <header className={styles.sectionHead}>
              <span className={styles.sectionLabel}>{section.heading}</span>
              <span className={styles.sectionRule} />
            </header>
            <div className={styles.grid}>
              {section.items.map((m) => (
                <ModuleCard key={m.name} module={m} />
              ))}
            </div>
          </section>
        ))}
      </main>
    </>
  );
}

type FilterTabProps = {
  readonly mode: StatusFilter;
  readonly current: StatusFilter;
  readonly onSelect: (mode: StatusFilter) => void;
  readonly children: React.ReactNode;
};

function FilterTab({ mode, current, onSelect, children }: FilterTabProps): JSX.Element {
  const active = mode === current;
  const cls = active ? `${styles.opt} ${styles.optActive}` : styles.opt;
  return (
    <button
      type="button"
      role="tab"
      aria-selected={active}
      className={cls}
      onClick={() => onSelect(mode)}
    >
      {children}
    </button>
  );
}

type ModuleCardProps = {
  readonly module: Module;
};

function ModuleCard({ module: m }: ModuleCardProps): JSX.Element {
  const iconClass = `${styles.icon} ${styles[`icon_${m.iconTone}`] ?? ''}`;
  const pillClass = `${styles.pill} ${styles[`pill_${m.statusTone}`] ?? ''}`;
  const actionClass = `${styles.action} ${styles[`action_${m.actionTone}`] ?? ''}`;

  return (
    <article className={styles.card}>
      <div className={styles.top}>
        <div className={iconClass}><span className={styles.iconGlyph}>{m.icon}</span></div>
        <div className={styles.info}>
          <div className={styles.name}>{m.name}</div>
          <div className={styles.sub}>
            <span className={styles.ver}>{m.version}</span>
            <span className={styles.subBullet}>•</span>
            <span>{m.vendor}</span>
          </div>
        </div>
      </div>
      <div className={styles.desc}>{m.description}</div>
      <div className={styles.foot}>
        <span className={pillClass}>
          <span className={styles.dot} />
          <span>{m.status}</span>
        </span>
        <span className={styles.footSpacer} />
        <button type="button" className={actionClass}>{m.action}</button>
      </div>
    </article>
  );
}
