// Forms & Layouts — Figma "Screen 64 — Forms & Layouts". Renders the
// AgentForge admin sub-page for managing intake/encounter form layouts. Layout:
// grouped admin sidebar (Forms active under Clinical) + page head with
// Import LBF / New form buttons + filter row (search + Category/Status/
// Last edit dropdowns) + table with FORM NAME / CATEGORY / FIELDS / LAST
// EDITED / STATUS / ACTIONS columns. Categories: Intake, Vitals, SOAP,
// Encounter, Custom.
//
// This is a 1:1 port of the PHP-rendered mock previously at
// /interface/super/copilot_forms_layouts.php. Data is hardcoded demo content
// matching the Figma node 136:2. The original PHP version is preserved at
// copilot_forms_layouts.php.bak. Wiring to a real /apis/copilot/admin/forms
// endpoint is a follow-up.
//
// The navy top nav is intentionally NOT rendered here; the PHP outer shell
// at /interface/main/tabs/main.php still owns it via the #maimain iframe.
//
// Reference: frontend/.fidelity-references/forms_layouts-figma-2026-05-07.png

import { useMemo, useState } from 'react';
import type { BootContext } from '../../shared/lib/bootContext';
import styles from './FormsLayouts.module.css';

type StatusTone = 'good' | 'warn' | 'neutral';
type CategoryTone = 'intake' | 'soap' | 'encounter' | 'vitals' | 'custom';

type SidebarItem = {
  readonly key: string;
  readonly label: string;
  readonly href: string;
};

type SidebarGroup = {
  readonly topLabel?: string | undefined;
  readonly subLabel?: string | undefined;
  readonly items: readonly SidebarItem[];
};

type FormRow = {
  readonly id: string;
  readonly name: string;
  readonly category: string;
  readonly catTone: CategoryTone;
  readonly fields: number;
  readonly lastEdited: string;
  readonly status: string;
  readonly tone: StatusTone;
};

const SIDEBAR_GROUPS: readonly SidebarGroup[] = [
  { topLabel: 'ADMIN', items: [] },
  {
    subLabel: 'Users & Access',
    items: [
      { key: 'users',           label: 'Users & Groups',  href: '/interface/super/copilot_users.php' },
      { key: 'acl',             label: 'ACL Editor',      href: '/interface/super/copilot_acl.php' },
      { key: 'sessions',        label: 'Active Sessions', href: '/interface/super/copilot_admin.php' },
      { key: 'password_policy', label: 'Password Policy', href: '/interface/super/copilot_admin.php' },
    ],
  },
  {
    subLabel: 'Practice',
    items: [
      { key: 'facilities', label: 'Facilities',         href: '/interface/super/copilot_facilities.php' },
      { key: 'providers',  label: 'Providers',          href: '/interface/super/copilot_users.php' },
      { key: 'schedule',   label: 'Schedule Templates', href: '/interface/super/copilot_templates.php' },
      { key: 'pricing',    label: 'Pricing',            href: '/interface/super/copilot_practice_settings.php' },
    ],
  },
  {
    subLabel: 'Clinical',
    items: [
      { key: 'forms',      label: 'Forms',       href: '/interface/super/copilot_forms_layouts.php' },
      { key: 'lists',      label: 'Lists',       href: '/interface/super/copilot_coding_lists.php' },
      { key: 'templates',  label: 'Templates',   href: '/interface/super/copilot_templates.php' },
      { key: 'issuetypes', label: 'Issue Types', href: '/interface/super/copilot_coding_lists.php' },
      { key: 'layouts',    label: 'Layouts',     href: '/interface/super/copilot_forms_layouts.php' },
    ],
  },
  {
    subLabel: 'System',
    items: [
      { key: 'audit',    label: 'Audit Log', href: '/interface/super/copilot_audit.php' },
      { key: 'backup',   label: 'Backup',    href: '/interface/super/copilot_system.php' },
      { key: 'globals',  label: 'Globals',   href: '/interface/super/copilot_admin.php' },
      { key: 'database', label: 'Database',  href: '/interface/super/copilot_db_debug.php' },
      { key: 'modules',  label: 'Modules',   href: '/interface/super/copilot_module_installer.php' },
    ],
  },
];
const ACTIVE_KEY = 'forms';

const ALL_FORMS: readonly FormRow[] = [
  { id: 'pi',  name: 'Patient Intake (new patient)', category: 'Intake',    catTone: 'intake',    fields: 38, lastEdited: '04/22 by Dr. Park',   status: 'Active',   tone: 'good' },
  { id: 'dm',  name: 'Diabetes Encounter Template',  category: 'SOAP',      catTone: 'soap',      fields: 24, lastEdited: '04/18 by Dr. Rivera', status: 'Active',   tone: 'good' },
  { id: 'htn', name: 'Hypertension Follow-up',       category: 'SOAP',      catTone: 'soap',      fields: 18, lastEdited: '04/14 by Dr. Chen',   status: 'Active',   tone: 'good' },
  { id: 'aw',  name: 'Annual Wellness',              category: 'Encounter', catTone: 'encounter', fields: 52, lastEdited: '03/30 by Admin',      status: 'Active',   tone: 'good' },
  { id: 'vqc', name: 'Vitals Quick-Capture',         category: 'Vitals',    catTone: 'vitals',    fields:  8, lastEdited: '03/22 by Nurse Mia',  status: 'Active',   tone: 'good' },
  { id: 'tc',  name: 'Telehealth Consent',           category: 'Custom',    catTone: 'custom',    fields:  6, lastEdited: '02/14 by Admin',      status: 'Active',   tone: 'good' },
  { id: 'pdi', name: 'Pediatric Intake',             category: 'Intake',    catTone: 'intake',    fields: 29, lastEdited: '04/24 by Dr. Park',   status: 'Draft',    tone: 'warn' },
  { id: 'old', name: 'Old Visit Form (legacy)',      category: 'Custom',    catTone: 'custom',    fields: 12, lastEdited: '06/08/2024',          status: 'Archived', tone: 'neutral' },
];

type CategoryFilter = 'all' | 'intake' | 'soap' | 'encounter' | 'vitals' | 'custom';
type StatusFilter   = 'all' | 'active' | 'draft' | 'archived';
type EditedFilter   = 'anytime' | 'week' | 'month';

type FormsLayoutsProps = {
  readonly boot: BootContext;
};

export function FormsLayouts(_props: FormsLayoutsProps): JSX.Element {
  const [q, setQ]                       = useState<string>('');
  const [catFilter, setCatFilter]       = useState<CategoryFilter>('all');
  const [statusFilter, setStatusFilter] = useState<StatusFilter>('active');
  const [editedFilter, setEditedFilter] = useState<EditedFilter>('anytime');

  const visible = useMemo<readonly FormRow[]>(() => {
    return ALL_FORMS.filter((r) => {
      if (catFilter !== 'all' && r.catTone !== catFilter) return false;
      if (statusFilter === 'active'   && r.status !== 'Active') return false;
      if (statusFilter === 'draft'    && r.status !== 'Draft') return false;
      if (statusFilter === 'archived' && r.status !== 'Archived') return false;
      // editedFilter is intentionally a no-op for the static demo dataset.
      void editedFilter;
      if (q.trim() !== '') {
        const needle = q.trim().toLowerCase();
        const hay = `${r.name} ${r.category}`.toLowerCase();
        if (!hay.includes(needle)) return false;
      }
      return true;
    });
  }, [q, catFilter, statusFilter, editedFilter]);

  return (
    <>
      <header className={styles.pageHead}>
        <div className={styles.titleBlock}>
          <span className={styles.title}>Forms &amp; Layouts</span>
          <span className={styles.dot}>•</span>
          <span className={styles.metaLight}>
            7 forms · 5 active · 1 draft · 1 archived
          </span>
        </div>
        <div className={styles.spacer} />
        <button type="button" className={styles.btnGhost}>
          <span aria-hidden="true">⤓</span>
          <span>Import LBF</span>
        </button>
        <button type="button" className={styles.btnPrimary}>
          + New form
        </button>
      </header>

      <div className={styles.shell}>
        <aside className={styles.sidebar}>
          {SIDEBAR_GROUPS.map((group, gi) => (
            <Group key={`g${gi}`} group={group} activeKey={ACTIVE_KEY} />
          ))}
        </aside>

        <main className={styles.main}>
          <div className={styles.filterBar}>
            <div className={styles.search}>
              <span className={styles.searchIcon} aria-hidden="true">🔍</span>
              <input
                type="text"
                placeholder="Search forms by name or section"
                value={q}
                onChange={(e) => setQ(e.target.value)}
              />
            </div>

            <FilterDropdown
              label="Category"
              value={catFilter}
              options={[
                { value: 'all',       label: 'All' },
                { value: 'intake',    label: 'Intake' },
                { value: 'soap',      label: 'SOAP' },
                { value: 'encounter', label: 'Encounter' },
                { value: 'vitals',    label: 'Vitals' },
                { value: 'custom',    label: 'Custom' },
              ]}
              onChange={(v) => setCatFilter(v as CategoryFilter)}
              width={124}
            />
            <FilterDropdown
              label="Status"
              value={statusFilter}
              options={[
                { value: 'all',      label: 'All' },
                { value: 'active',   label: 'Active' },
                { value: 'draft',    label: 'Draft' },
                { value: 'archived', label: 'Archived' },
              ]}
              onChange={(v) => setStatusFilter(v as StatusFilter)}
              width={124}
            />
            <FilterDropdown
              label="Last edit"
              value={editedFilter}
              options={[
                { value: 'anytime', label: 'Anytime' },
                { value: 'week',    label: 'This week' },
                { value: 'month',   label: 'This month' },
              ]}
              onChange={(v) => setEditedFilter(v as EditedFilter)}
              width={132}
            />
          </div>

          <div className={styles.tableCard}>
            <div className={styles.tableHead}>
              <div className={`${styles.thCell} ${styles.colName}`}>FORM NAME</div>
              <div className={`${styles.thCell} ${styles.colCat}`}>CATEGORY</div>
              <div className={`${styles.thCell} ${styles.colFields}`}>FIELDS</div>
              <div className={`${styles.thCell} ${styles.colEdited}`}>LAST EDITED</div>
              <div className={`${styles.thCell} ${styles.colStatus}`}>STATUS</div>
              <div className={`${styles.thCell} ${styles.colActions}`}>ACTIONS</div>
            </div>
            <div className={styles.tableBody}>
              {visible.length === 0 ? (
                <div className={styles.empty}>
                  <div className={styles.emptyLbl}>NO MATCHING FORMS</div>
                  Adjust your filters or add a new form.
                </div>
              ) : (
                visible.map((r) => (
                  <div key={r.id} className={styles.row}>
                    <div className={`${styles.tdCell} ${styles.colName} ${styles.tdName}`}>{r.name}</div>
                    <div className={`${styles.tdCell} ${styles.colCat}`}>
                      <span className={`${styles.tag} ${categoryClass(r.catTone)}`}>{r.category}</span>
                    </div>
                    <div className={`${styles.tdCell} ${styles.colFields} ${styles.tdMuted}`}>{r.fields}</div>
                    <div className={`${styles.tdCell} ${styles.colEdited} ${styles.tdMuted}`}>{r.lastEdited}</div>
                    <div className={`${styles.tdCell} ${styles.colStatus}`}>
                      <span className={`${styles.pill} ${statusClass(r.tone)}`}>{r.status}</span>
                    </div>
                    <div className={`${styles.tdCell} ${styles.colActions}`}>
                      <button type="button" className={styles.editBtn}>Edit</button>
                    </div>
                  </div>
                ))
              )}
            </div>
          </div>
        </main>
      </div>
    </>
  );
}

type GroupProps = {
  readonly group: SidebarGroup;
  readonly activeKey: string;
};

function Group({ group, activeKey }: GroupProps): JSX.Element {
  return (
    <>
      {group.topLabel !== undefined && (
        <div className={styles.grpLblTop}>{group.topLabel}</div>
      )}
      {group.subLabel !== undefined && (
        <div className={styles.grpLbl}>{group.subLabel}</div>
      )}
      {group.items.map((item) => {
        const cls = item.key === activeKey
          ? `${styles.cat} ${styles.catActive}`
          : styles.cat;
        return (
          <a key={item.key} className={cls} href={item.href} target="_self">
            {item.label}
          </a>
        );
      })}
    </>
  );
}

type FilterOption = {
  readonly value: string;
  readonly label: string;
};

type FilterDropdownProps = {
  readonly label: string;
  readonly value: string;
  readonly options: readonly FilterOption[];
  readonly onChange: (next: string) => void;
  readonly width: number;
};

function FilterDropdown({ label, value, options, onChange, width }: FilterDropdownProps): JSX.Element {
  return (
    <label className={styles.fltDd} style={{ width: `${width}px` }}>
      <span className={styles.fltDdLbl}>{label}</span>
      <select
        className={styles.fltDdSelect}
        value={value}
        onChange={(e) => onChange(e.target.value)}
      >
        {options.map((o) => (
          <option key={o.value} value={o.value}>{o.label}</option>
        ))}
      </select>
      <span className={styles.fltDdCaret} aria-hidden="true">▾</span>
    </label>
  );
}

function categoryClass(tone: CategoryTone): string {
  switch (tone) {
    case 'intake':    return styles['tagIntake']    ?? '';
    case 'soap':      return styles['tagSoap']      ?? '';
    case 'encounter': return styles['tagEncounter'] ?? '';
    case 'vitals':    return styles['tagVitals']    ?? '';
    case 'custom':    return styles['tagCustom']    ?? '';
  }
}

function statusClass(tone: StatusTone): string {
  switch (tone) {
    case 'good':    return styles['pillGood']    ?? '';
    case 'warn':    return styles['pillWarn']    ?? '';
    case 'neutral': return styles['pillNeutral'] ?? '';
  }
}
