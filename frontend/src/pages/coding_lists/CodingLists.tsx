// Coding & Lists — Figma "Screen 63 — Coding & Lists". Renders the AgentForge
// admin sub-page for code systems (ICD-10, CPT, SNOMED, RxNorm, LOINC) and
// custom lists (allergies, pharmacies, insurance plans). Layout: grouped
// admin sidebar (Lists active under Clinical) + page head with Sync all /
// New list buttons + filter row (search + Type/Status/Source dropdowns) +
// table with NAME / TYPE / SIZE / SOURCE / STATUS / ACTIONS columns.
//
// This is a 1:1 port of the PHP-rendered mock previously at
// /interface/super/copilot_coding_lists.php. Data is hardcoded demo content
// matching the Figma node 131:2. The original PHP version is preserved at
// copilot_coding_lists.php.bak for side-by-side diffing. Wiring to a real
// /apis/copilot/admin/lists endpoint is a follow-up.
//
// The navy top nav is intentionally NOT rendered here; the PHP outer shell
// at /interface/main/tabs/main.php still owns it via the #maimain iframe.
//
// Reference: frontend/.fidelity-references/coding_lists-figma-2026-05-07.png

import { useMemo, useState } from 'react';
import type { BootContext } from '../../shared/lib/bootContext';
import styles from './CodingLists.module.css';

type StatusTone = 'good' | 'info';

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

type ListRow = {
  readonly id: string;
  readonly name: string;
  readonly type: string;
  readonly size: string;
  readonly source: string;
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
const ACTIVE_KEY = 'lists';

const ALL_LISTS: readonly ListRow[] = [
  { id: 'icd10',    name: 'ICD-10 (clinical)',       type: 'Code system', size: '~70,000 entries',  source: '2026 release',   status: 'Synced', tone: 'good' },
  { id: 'cpt',      name: 'CPT / HCPCS',             type: 'Code system', size: '~10,400 entries',  source: '2026 release',   status: 'Synced', tone: 'good' },
  { id: 'snomed',   name: 'SNOMED CT',               type: 'Code system', size: 'Subset (~80k)',    source: '2025-09 update', status: 'Synced', tone: 'good' },
  { id: 'rxnorm',   name: 'RxNorm',                  type: 'Code system', size: '~150,000 entries', source: 'Daily sync',     status: 'Synced', tone: 'good' },
  { id: 'loinc',    name: 'LOINC',                   type: 'Code system', size: '~95,000 entries',  source: '2026-04 update', status: 'Synced', tone: 'good' },
  { id: 'allerg',   name: 'Allergies (custom list)', type: 'List',        size: '38 entries',       source: 'In-house',       status: 'Local',  tone: 'info' },
  { id: 'pharm',    name: 'Pharmacies (favorites)',  type: 'List',        size: '12 entries',       source: 'In-house',       status: 'Local',  tone: 'info' },
  { id: 'insure',   name: 'Insurance plans',         type: 'List',        size: '47 entries',       source: 'In-house',       status: 'Local',  tone: 'info' },
];

type TypeFilter = 'all' | 'code' | 'list';
type StatusFilter = 'all' | 'synced' | 'local';
type SourceFilter = 'all' | 'external' | 'inhouse';

type CodingListsProps = {
  readonly boot: BootContext;
};

export function CodingLists(_props: CodingListsProps): JSX.Element {
  const [q, setQ]                       = useState<string>('');
  const [typeFilter, setTypeFilter]     = useState<TypeFilter>('all');
  const [statusFilter, setStatusFilter] = useState<StatusFilter>('all');
  const [sourceFilter, setSourceFilter] = useState<SourceFilter>('all');

  const visible = useMemo<readonly ListRow[]>(() => {
    return ALL_LISTS.filter((r) => {
      if (typeFilter === 'code' && r.type !== 'Code system') return false;
      if (typeFilter === 'list' && r.type !== 'List') return false;
      if (statusFilter === 'synced' && r.status !== 'Synced') return false;
      if (statusFilter === 'local' && r.status !== 'Local') return false;
      if (sourceFilter === 'external' && r.source === 'In-house') return false;
      if (sourceFilter === 'inhouse'  && r.source !== 'In-house') return false;
      if (q.trim() !== '') {
        const needle = q.trim().toLowerCase();
        const hay = `${r.name} ${r.type} ${r.source}`.toLowerCase();
        if (!hay.includes(needle)) return false;
      }
      return true;
    });
  }, [q, typeFilter, statusFilter, sourceFilter]);

  return (
    <>
      <header className={styles.pageHead}>
        <div className={styles.titleBlock}>
          <span className={styles.title}>Coding &amp; Lists</span>
          <span className={styles.dot}>•</span>
          <span className={styles.metaLight}>
            8 lists · 5 synced from external · 3 local
          </span>
        </div>
        <div className={styles.spacer} />
        <button type="button" className={styles.btnGhost}>
          <span aria-hidden="true">↻</span>
          <span>Sync all</span>
        </button>
        <button type="button" className={styles.btnPrimary}>
          + New list
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
                placeholder="Search code systems and lists"
                value={q}
                onChange={(e) => setQ(e.target.value)}
              />
            </div>

            <FilterDropdown
              label="Type"
              value={typeFilter}
              options={[
                { value: 'all',  label: 'All' },
                { value: 'code', label: 'Code system' },
                { value: 'list', label: 'List' },
              ]}
              onChange={(v) => setTypeFilter(v as TypeFilter)}
              width={108}
            />
            <FilterDropdown
              label="Status"
              value={statusFilter}
              options={[
                { value: 'all',    label: 'All' },
                { value: 'synced', label: 'Synced' },
                { value: 'local',  label: 'Local' },
              ]}
              onChange={(v) => setStatusFilter(v as StatusFilter)}
              width={108}
            />
            <FilterDropdown
              label="Source"
              value={sourceFilter}
              options={[
                { value: 'all',      label: 'All' },
                { value: 'external', label: 'External' },
                { value: 'inhouse',  label: 'In-house' },
              ]}
              onChange={(v) => setSourceFilter(v as SourceFilter)}
              width={116}
            />
          </div>

          <div className={styles.tableCard}>
            <div className={styles.tableHead}>
              <div className={`${styles.thCell} ${styles.colName}`}>NAME</div>
              <div className={`${styles.thCell} ${styles.colType}`}>TYPE</div>
              <div className={`${styles.thCell} ${styles.colSize}`}>SIZE</div>
              <div className={`${styles.thCell} ${styles.colSource}`}>SOURCE</div>
              <div className={`${styles.thCell} ${styles.colStatus}`}>STATUS</div>
              <div className={`${styles.thCell} ${styles.colActions}`}>ACTIONS</div>
            </div>
            <div className={styles.tableBody}>
              {visible.length === 0 ? (
                <div className={styles.empty}>
                  <div className={styles.emptyLbl}>NO MATCHING LISTS</div>
                  Adjust your filters or add a new list.
                </div>
              ) : (
                visible.map((r) => (
                  <div key={r.id} className={styles.row}>
                    <div className={`${styles.tdCell} ${styles.colName} ${styles.tdName}`}>{r.name}</div>
                    <div className={`${styles.tdCell} ${styles.colType} ${styles.tdMuted}`}>{r.type}</div>
                    <div className={`${styles.tdCell} ${styles.colSize} ${styles.tdMuted}`}>{r.size}</div>
                    <div className={`${styles.tdCell} ${styles.colSource} ${styles.tdMuted}`}>{r.source}</div>
                    <div className={`${styles.tdCell} ${styles.colStatus}`}>
                      <span className={`${styles.pill} ${toneClass(r.tone)}`}>{r.status}</span>
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

function toneClass(tone: StatusTone): string {
  switch (tone) {
    case 'good': return styles['pillGood'] ?? '';
    case 'info': return styles['pillInfo'] ?? '';
  }
}
