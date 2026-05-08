// PatientModules — Figma "Screen 21 — Patient Modules" (file kj4MWNr8mpjZ2wVg1PbS0F,
// node 43:2). Renders the patient-context "Modules" navtab body: header with
// title, summary text, Active/Available/All segmented control, Browse
// Marketplace button; followed by two grouped grids (ACTIVE — CLINICAL and
// AVAILABLE — RECOMMENDED FOR THIS PATIENT) of module cards.
//
// Wired via the Finder pattern: the PHP wrapper at
// /interface/patient_file/modules/copilot_modules.php JSON-encodes the
// active-clinical + available rows and the summary counts onto data-* attrs
// on #cp-root, and the entry index.tsx parses + passes them as props. The
// arrays are still hardcoded stubs in the wrapper (see TODO(real-data) — no
// per-patient module-activation schema exists in this build), but the
// data-attribute → prop pipeline is consistent with Finder / External Data
// so swapping in a real SQL pull is a server-side-only change.
//
// The PHP wrapper is iframe-mounted under the navy top nav and patient
// header2 banner that the outer OpenEMR shell still renders, so this
// component intentionally skips both.
import { useState } from 'react';
import type { BootContext } from '../../shared/lib/bootContext';
import styles from './PatientModules.module.css';

type StatusFilter = 'active' | 'available' | 'all';

// Server-side row shape (mirrors the PHP arrays in copilot_modules.php).
// Tones are kept as plain strings so unknown values don't blow up the parser
// — the CSS lookup falls back to '' if a tone isn't recognized.
export type PatientModule = {
  readonly name: string;
  readonly icon: string;
  readonly iconTone: string;
  readonly version: string;
  readonly vendor: string;
  readonly description: string;
  readonly status: string;
  readonly statusTone: string;
  readonly action: string;
  readonly actionTone: string;
};

export type PatientModulesSummary = {
  readonly active: number;
  readonly available: number;
};

type Section = {
  readonly heading: string;
  readonly items: readonly PatientModule[];
};

type PatientModulesProps = {
  readonly boot: BootContext;
  readonly activeClinical: readonly PatientModule[];
  readonly available: readonly PatientModule[];
  readonly summary: PatientModulesSummary;
};

export function PatientModules(
  { activeClinical, available, summary }: PatientModulesProps,
): JSX.Element {
  const [filter, setFilter] = useState<StatusFilter>('active');

  const sections: readonly Section[] = [
    { heading: 'ACTIVE — CLINICAL',                         items: activeClinical },
    { heading: 'AVAILABLE — RECOMMENDED FOR THIS PATIENT',  items: available },
  ];

  const metaText =
    `Patient-context modules • ${summary.active} active, ${summary.available} available`;

  return (
    <>
      <header className={styles.head}>
        <div className={styles.title}>Modules</div>
        <div className={styles.bullet}>•</div>
        <div className={styles.meta}>{metaText}</div>
        <div className={styles.spacer} />
        <div className={styles.seg} role="tablist">
          <FilterTab mode="active"    current={filter} onSelect={setFilter}>Active</FilterTab>
          <FilterTab mode="available" current={filter} onSelect={setFilter}>Available</FilterTab>
          <FilterTab mode="all"       current={filter} onSelect={setFilter}>All</FilterTab>
        </div>
        <button type="button" className={styles.marketplace}>Browse Marketplace →</button>
      </header>

      <main className={styles.body}>
        {sections.map((section) => (
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
  readonly module: PatientModule;
};

function ModuleCard({ module: m }: ModuleCardProps): JSX.Element {
  const iconClass = `${styles.icon} ${styles[`icon_${m.iconTone}`] ?? ''}`.trim();
  const pillClass = `${styles.pill} ${styles[`pill_${m.statusTone}`] ?? ''}`.trim();
  const actionClass = `${styles.action} ${styles[`action_${m.actionTone}`] ?? ''}`.trim();

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
