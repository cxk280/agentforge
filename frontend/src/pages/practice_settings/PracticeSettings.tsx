// PracticeSettings — Figma "Screen 26 — Practice Settings". Renders the
// AgentForge admin sub-page archetype: ADMIN sidebar (Practice Settings
// active) + page header bar with "Reset to defaults" / "Save changes" CTAs +
// content sections (General, Patient Encounters, Security) of form fields
// and toggle switches.
//
// This is a 1:1 port of the PHP-rendered mock previously at
// /interface/super/copilot_practice_settings.php. Sidebar items are inlined
// here and mirror the canonical ADMIN list shared with copilot_admin.php.
// All field values are hardcoded demo content matching the Figma — wiring
// to real settings endpoints is a follow-up.

import { useState } from 'react';
import type { BootContext } from '../../shared/lib/bootContext';
import styles from './PracticeSettings.module.css';

type SidebarItem = {
  readonly label: string;
  readonly href: string;
  readonly active: boolean;
};

type FieldKind = 'text' | 'select' | 'toggle';

type Field = {
  readonly label: string;
  readonly kind: FieldKind;
  readonly value: string; // text/select: display value; toggle: 'on' | 'off'
};

type Section = {
  readonly title: string;
  readonly sub: string;
  readonly fields: readonly Field[];
};

const SIDEBAR_ITEMS: readonly SidebarItem[] = [
  { label: 'Overview',          href: '/interface/super/copilot_admin.php',             active: false },
  { label: 'Practice Settings', href: '/interface/super/copilot_practice_settings.php', active: true  },
  { label: 'Users & Groups',    href: '/interface/super/copilot_users.php',             active: false },
  { label: 'ACL',               href: '/interface/super/copilot_acl.php',               active: false },
  { label: 'Facilities',        href: '/interface/super/copilot_facilities.php',        active: false },
  { label: 'Forms & Layouts',   href: '/interface/super/copilot_forms_layouts.php',     active: false },
  { label: 'Templates',         href: '/interface/super/copilot_templates.php',         active: false },
  { label: 'Coding & Lists',    href: '/interface/super/copilot_coding_lists.php',      active: false },
  { label: 'Modules',           href: '/interface/super/copilot_modules_admin.php',     active: false },
  { label: 'System',            href: '/interface/super/copilot_system.php',            active: false },
  { label: 'Logs & Audit',      href: '/interface/super/copilot_logs.php',              active: false },
];

const SECTIONS: readonly Section[] = [
  {
    title: 'General',
    sub: 'Practice identity, time zone, and locale',
    fields: [
      { label: 'Practice name', kind: 'text',   value: 'Riverside Family Medicine' },
      { label: 'Time zone',     kind: 'select', value: 'America/Chicago (CDT)' },
      { label: 'Locale',        kind: 'select', value: 'English (US)' },
      { label: 'Date format',   kind: 'select', value: 'MM/DD/YYYY' },
    ],
  },
  {
    title: 'Patient Encounters',
    sub: '',
    fields: [
      { label: 'Default encounter type',    kind: 'select', value: 'Office Visit' },
      { label: 'Auto-lock after sign',      kind: 'toggle', value: 'on' },
      { label: 'Require diagnosis on sign', kind: 'toggle', value: 'on' },
      { label: 'Show Co-Pilot ✦ inline', kind: 'toggle', value: 'on' },
    ],
  },
  {
    title: 'Security',
    sub: '',
    fields: [
      { label: 'Session timeout',     kind: 'select', value: '15 minutes' },
      { label: 'Require 2FA',         kind: 'toggle', value: 'on' },
      { label: 'Password rotation',   kind: 'select', value: 'Every 90 days' },
      { label: 'Audit log retention', kind: 'select', value: '7 years' },
    ],
  },
];

type PracticeSettingsProps = {
  readonly boot: BootContext;
};

export function PracticeSettings(_props: PracticeSettingsProps): JSX.Element {
  return (
    <>
      <header className={styles.pageHead}>
        <div className={styles.pageHeadInfo}>
          <span className={styles.pageTitle}>Practice Settings</span>
          <span className={styles.pageSub}>Site-wide configuration • Saved 04/12/2026 09:14 AM by Admin</span>
        </div>
        <button type="button" className={`${styles.btn} ${styles.btnGhost}`}>Reset to defaults</button>
        <button type="button" className={`${styles.btn} ${styles.btnPrimary}`}>Save changes</button>
      </header>

      <div className={styles.shell}>
        <aside className={styles.sidebar}>
          <div className={styles.sideLbl}>ADMIN</div>
          {SIDEBAR_ITEMS.map((item) => {
            const cls = item.active
              ? `${styles.cat} ${styles.catActive}`
              : styles.cat;
            return (
              <a key={item.label} className={cls} href={item.href} target="_self">
                {item.label}
              </a>
            );
          })}
        </aside>

        <main className={styles.content}>
          {SECTIONS.map((sec) => (
            <SectionCard key={sec.title} section={sec} />
          ))}
        </main>
      </div>
    </>
  );
}

type SectionCardProps = {
  readonly section: Section;
};

function SectionCard({ section }: SectionCardProps): JSX.Element {
  return (
    <section className={styles.section}>
      <h2 className={styles.sectionTitle}>{section.title}</h2>
      {section.sub
        ? <div className={styles.sectionDesc}>{section.sub}</div>
        : <div className={styles.sectionDescEmpty} />
      }
      {section.fields.map((f) => (
        <FieldRow key={f.label} field={f} />
      ))}
    </section>
  );
}

type FieldRowProps = {
  readonly field: Field;
};

function FieldRow({ field }: FieldRowProps): JSX.Element {
  return (
    <div className={styles.row}>
      <label className={styles.rowLabel}>{field.label}</label>
      <div className={styles.ctrl}>
        {field.kind === 'toggle' ? (
          <ToggleSwitch initialOn={field.value === 'on'} ariaLabel={field.label} />
        ) : field.kind === 'select' ? (
          <select className={`${styles.input} ${styles.select}`} defaultValue={field.value}>
            <option>{field.value}</option>
          </select>
        ) : (
          <input className={styles.input} type="text" defaultValue={field.value} />
        )}
      </div>
    </div>
  );
}

type ToggleSwitchProps = {
  readonly initialOn: boolean;
  readonly ariaLabel: string;
};

function ToggleSwitch({ initialOn, ariaLabel }: ToggleSwitchProps): JSX.Element {
  const [on, setOn] = useState<boolean>(initialOn);
  const cls = on ? styles.toggle : `${styles.toggle} ${styles.toggleOff}`;
  return (
    <button
      type="button"
      role="switch"
      aria-checked={on}
      aria-label={ariaLabel}
      className={cls}
      onClick={() => setOn((prev) => !prev)}
    />
  );
}
