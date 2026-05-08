// Templates — Figma "Screen 65 — Templates". Renders the AgentForge admin
// sub-page for managing document templates: letters, labels, statements, and
// patient instructions. Layout: grouped admin sidebar (Templates active under
// Clinical) + page head with New template button + filter row (search +
// Type/Owner/Status dropdowns) + table with TEMPLATE / TYPE / LAST USED /
// OWNER / STATUS / ACTIONS columns.
//
// This is a 1:1 port of the PHP-rendered mock previously at
// /interface/super/copilot_templates.php. Data is hardcoded demo content
// matching the Figma node 139:2. The original PHP version is preserved at
// copilot_templates.php.bak. Wiring to a real /apis/copilot/admin/templates
// endpoint is a follow-up.
//
// The navy top nav is intentionally NOT rendered here; the PHP outer shell
// at /interface/main/tabs/main.php still owns it via the #maimain iframe.
//
// Reference: frontend/.fidelity-references/templates-figma-2026-05-07.png

import { useMemo, useState } from 'react';
import type { BootContext } from '../../shared/lib/bootContext';
import styles from './Templates.module.css';

type StatusTone = 'good' | 'warn';
type TypeTone = 'letter' | 'label' | 'statement' | 'instructions';

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

type TemplateRow = {
  readonly id: string;
  readonly name: string;
  readonly type: string;
  readonly typeTone: TypeTone;
  readonly lastUsed: string;
  readonly owner: string;
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
const ACTIVE_KEY = 'templates';

const ALL_TEMPLATES: readonly TemplateRow[] = [
  { id: 't1',  name: 'Lab results — Normal',         type: 'Letter',       typeTone: 'letter',       lastUsed: '2 hours ago',  owner: 'Dr. Rivera', status: 'Active', tone: 'good' },
  { id: 't2',  name: 'Referral — Cardiology',        type: 'Letter',       typeTone: 'letter',       lastUsed: '4 hours ago',  owner: 'Dr. Chen',   status: 'Active', tone: 'good' },
  { id: 't3',  name: 'Patient statement (monthly)',  type: 'Statement',    typeTone: 'statement',    lastUsed: 'Yesterday',    owner: 'Billing',    status: 'Active', tone: 'good' },
  { id: 't4',  name: 'Specimen label — generic',     type: 'Label',        typeTone: 'label',        lastUsed: 'Yesterday',    owner: 'Lab',        status: 'Active', tone: 'good' },
  { id: 't5',  name: 'Diabetes — home care plan',    type: 'Instructions', typeTone: 'instructions', lastUsed: '3 days ago',   owner: 'Nurse Mia',  status: 'Active', tone: 'good' },
  { id: 't6',  name: 'Hypertension — DASH diet',     type: 'Instructions', typeTone: 'instructions', lastUsed: '6 days ago',   owner: 'Nurse Mia',  status: 'Active', tone: 'good' },
  { id: 't7',  name: 'Prior auth — Imaging',         type: 'Letter',       typeTone: 'letter',       lastUsed: '1 week ago',   owner: 'Billing',    status: 'Active', tone: 'good' },
  { id: 't8',  name: 'Welcome packet — new patient', type: 'Letter',       typeTone: 'letter',       lastUsed: '2 weeks ago',  owner: 'Front Desk', status: 'Active', tone: 'good' },
  { id: 't9',  name: 'Order set — CHF baseline',     type: 'Instructions', typeTone: 'instructions', lastUsed: '3 weeks ago',  owner: 'Dr. Park',   status: 'Draft',  tone: 'warn' },
  { id: 't10', name: 'Refill request — generic',     type: 'Letter',       typeTone: 'letter',       lastUsed: '5 weeks ago',  owner: 'Pharmacy',   status: 'Active', tone: 'good' },
];

type TypeFilter   = 'all' | 'letter' | 'label' | 'statement' | 'instructions';
type OwnerFilter  = 'all' | 'provider' | 'nurse' | 'billing' | 'lab' | 'frontdesk' | 'pharmacy';
type StatusFilter = 'all' | 'active' | 'draft';

type TemplatesProps = {
  readonly boot: BootContext;
};

export function Templates(_props: TemplatesProps): JSX.Element {
  const [q, setQ]                       = useState<string>('');
  const [typeFilter, setTypeFilter]     = useState<TypeFilter>('all');
  const [ownerFilter, setOwnerFilter]   = useState<OwnerFilter>('all');
  const [statusFilter, setStatusFilter] = useState<StatusFilter>('active');

  const visible = useMemo<readonly TemplateRow[]>(() => {
    return ALL_TEMPLATES.filter((r) => {
      if (typeFilter !== 'all' && r.typeTone !== typeFilter) return false;
      if (statusFilter === 'active' && r.status !== 'Active') return false;
      if (statusFilter === 'draft'  && r.status !== 'Draft')  return false;
      // ownerFilter is intentionally a no-op for the static demo dataset.
      void ownerFilter;
      if (q.trim() !== '') {
        const needle = q.trim().toLowerCase();
        const hay = `${r.name} ${r.type} ${r.owner}`.toLowerCase();
        if (!hay.includes(needle)) return false;
      }
      return true;
    });
  }, [q, typeFilter, ownerFilter, statusFilter]);

  return (
    <>
      <header className={styles.pageHead}>
        <div className={styles.titleBlock}>
          <span className={styles.title}>Templates</span>
          <span className={styles.dot}>•</span>
          <span className={styles.metaLight}>
            12 templates · letters, labels, statements, instructions
          </span>
        </div>
        <div className={styles.spacer} />
        <button type="button" className={styles.btnPrimary}>
          + New template
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
                placeholder="Search templates by name"
                value={q}
                onChange={(e) => setQ(e.target.value)}
              />
            </div>

            <FilterDropdown
              label="Type"
              value={typeFilter}
              options={[
                { value: 'all',          label: 'All' },
                { value: 'letter',       label: 'Letter' },
                { value: 'label',        label: 'Label' },
                { value: 'statement',    label: 'Statement' },
                { value: 'instructions', label: 'Instructions' },
              ]}
              onChange={(v) => setTypeFilter(v as TypeFilter)}
              width={108}
            />
            <FilterDropdown
              label="Owner"
              value={ownerFilter}
              options={[
                { value: 'all',       label: 'All' },
                { value: 'provider',  label: 'Provider' },
                { value: 'nurse',     label: 'Nurse' },
                { value: 'billing',   label: 'Billing' },
                { value: 'lab',       label: 'Lab' },
                { value: 'frontdesk', label: 'Front Desk' },
                { value: 'pharmacy',  label: 'Pharmacy' },
              ]}
              onChange={(v) => setOwnerFilter(v as OwnerFilter)}
              width={116}
            />
            <FilterDropdown
              label="Status"
              value={statusFilter}
              options={[
                { value: 'all',    label: 'All' },
                { value: 'active', label: 'Active' },
                { value: 'draft',  label: 'Draft' },
              ]}
              onChange={(v) => setStatusFilter(v as StatusFilter)}
              width={120}
            />
          </div>

          <div className={styles.tableCard}>
            <div className={styles.tableHead}>
              <div className={`${styles.thCell} ${styles.colTpl}`}>TEMPLATE</div>
              <div className={`${styles.thCell} ${styles.colType}`}>TYPE</div>
              <div className={`${styles.thCell} ${styles.colUsed}`}>LAST USED</div>
              <div className={`${styles.thCell} ${styles.colOwner}`}>OWNER</div>
              <div className={`${styles.thCell} ${styles.colStatus}`}>STATUS</div>
              <div className={`${styles.thCell} ${styles.colActions}`}>ACTIONS</div>
            </div>
            <div className={styles.tableBody}>
              {visible.length === 0 ? (
                <div className={styles.empty}>
                  <div className={styles.emptyLbl}>NO MATCHING TEMPLATES</div>
                  Adjust your filters or add a new template.
                </div>
              ) : (
                visible.map((r) => (
                  <div key={r.id} className={styles.row}>
                    <div className={`${styles.tdCell} ${styles.colTpl} ${styles.tdName}`}>{r.name}</div>
                    <div className={`${styles.tdCell} ${styles.colType}`}>
                      <span className={`${styles.tag} ${typeClass(r.typeTone)}`}>{r.type}</span>
                    </div>
                    <div className={`${styles.tdCell} ${styles.colUsed} ${styles.tdMuted}`}>{r.lastUsed}</div>
                    <div className={`${styles.tdCell} ${styles.colOwner} ${styles.tdMuted}`}>{r.owner}</div>
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

function typeClass(tone: TypeTone): string {
  switch (tone) {
    case 'letter':       return styles['tagLetter']       ?? '';
    case 'label':        return styles['tagLabel']        ?? '';
    case 'statement':    return styles['tagStatement']    ?? '';
    case 'instructions': return styles['tagInstructions'] ?? '';
  }
}

function statusClass(tone: StatusTone): string {
  switch (tone) {
    case 'good': return styles['pillGood'] ?? '';
    case 'warn': return styles['pillWarn'] ?? '';
  }
}
