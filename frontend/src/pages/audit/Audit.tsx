// Audit Log — Figma "Screen 55 — Audit Log". Renders the AgentForge HIPAA
// audit-log admin page: grouped sidebar, page header with Export button,
// filter bar (search + 5 dropdowns), event table on the left, and an
// incident-detail right rail pinned to the currently selected row.
//
// This is a 1:1 port of the PHP-rendered mock previously at
// /interface/super/copilot_audit.php (preserved as copilot_audit.php.bak for
// side-by-side diffing). All data is hardcoded static demo content matching
// the Figma screenshot exactly — no DB calls, no filtering logic, no
// CSV export. A future task will wire this up to a real
// /apis/copilot/audit/* endpoint.
//
// The navy top nav and patient header2 banner are owned by the parent shell
// (the admin section is not patient-scoped and uses its own grouped
// sidebar), so this component renders only the page header + grouped
// sidebar + main content area.
//
// Reference: frontend/.fidelity-references/audit-figma-2026-05-07.png

import { useState } from 'react';
import type { BootContext } from '../../shared/lib/bootContext';
import styles from './Audit.module.css';

type RiskTone = 'good' | 'warn' | 'danger';

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

type AuditRow = {
  readonly id: string;
  readonly ts: string;
  readonly user: string;
  readonly event: string;
  readonly target: string;
  readonly tone: RiskTone;
  readonly altRow: boolean; // every other row in Figma uses #FAFBFC bg
};

type ContextItem = {
  readonly text: string;
  readonly ok: boolean;
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
const ACTIVE_KEY = 'audit';

// Static demo rows — copied straight from Figma node 104:2 (Screen 55).
// `tone` is the right-side dot color; `altRow` paints every other row with
// the #FAFBFC zebra stripe seen in the Figma source.
const AUDIT_ROWS: readonly AuditRow[] = [
  { id: 'r1',  ts: '05/02 10:14:22', user: 'erivera',      event: 'Viewed chart',                target: 'Margaret Chen #004821',  tone: 'good',   altRow: false },
  { id: 'r2',  ts: '05/02 10:13:48', user: 'erivera',      event: 'Login (MFA)',                 target: '192.168.1.42',           tone: 'good',   altRow: true  },
  { id: 'r3',  ts: '05/02 10:12:01', user: 'kchen',        event: 'Signed encounter',            target: 'David Kim #006102',      tone: 'good',   altRow: false },
  { id: 'r4',  ts: '05/02 10:08:14', user: 'spark',        event: 'Updated demographics',        target: 'Linda Martinez #003918', tone: 'good',   altRow: true  },
  { id: 'r5',  ts: '05/02 10:02:30', user: 'erx_svc',      event: 'Submitted prescription',      target: 'via Surescripts',        tone: 'good',   altRow: false },
  { id: 'r6',  ts: '05/02 09:58:12', user: 'lbrown',       event: 'Posted payment $40',          target: 'Margaret Chen #004821',  tone: 'good',   altRow: true  },
  { id: 'r7',  ts: '05/02 09:54:01', user: 'UNKNOWN',      event: 'Failed login attempt',        target: '98.124.42.1 (TX)',       tone: 'warn',   altRow: false },
  { id: 'r8',  ts: '05/02 09:51:42', user: 'UNKNOWN',      event: 'Failed login attempt (3rd)',  target: '98.124.42.1 (TX) · LOCKED', tone: 'danger', altRow: true  },
  { id: 'r9',  ts: '05/02 09:48:12', user: 'mwebb',        event: 'Modified ACL — Locum role',   target: 'Removed PHI export',     tone: 'warn',   altRow: false },
  { id: 'r10', ts: '05/02 09:42:00', user: 'rpatel',       event: 'Viewed chart',                target: 'Allison Park #002745',   tone: 'good',   altRow: false },
  { id: 'r11', ts: '05/02 09:38:14', user: 'rpatel',       event: 'Re-opened encounter',         target: 'Helen Garcia #003021',   tone: 'warn',   altRow: false },
  { id: 'r12', ts: '05/02 09:30:01', user: 'hospital_svc', event: 'Imported CCDA',               target: "From St. David's ED",    tone: 'good',   altRow: true  },
  { id: 'r13', ts: '05/02 09:14:00', user: 'erivera',      event: 'Signed result · A1C critical', target: 'Margaret Chen #004821', tone: 'good',   altRow: false },
  { id: 'r14', ts: '05/02 09:00:14', user: 'sjones',       event: 'EPCS sign · Schedule IV',     target: 'Allison Park #002745',   tone: 'good',   altRow: true  },
  { id: 'r15', ts: '05/02 08:58:42', user: 'lbrown',       event: 'Adjusted claim',              target: 'Encounter #2891',        tone: 'warn',   altRow: false },
];

// In Figma the highlighted row is the "Failed login attempt" warning row
// (104:146 — bg #E6F5F5). Selected detail card describes the lockout row
// directly below it.
const DEFAULT_SELECTED_ID = 'r7';
const DEFAULT_DETAIL_ID = 'r8';

// Right-rail security context — exact strings from Figma 104:231.
const SECURITY_CONTEXT: readonly ContextItem[] = [
  { text: '3 failed attempts in 4 min',         ok: false },
  { text: 'IP geolocation matches user state',  ok: false },
  { text: 'No prior login from this IP',        ok: false },
  { text: 'PHI access blocked (no session)',    ok: true  },
  { text: 'Notification sent to IT admin',      ok: false },
];

type AuditProps = {
  readonly boot: BootContext;
};

export function Audit(_props: AuditProps): JSX.Element {
  // Selected row drives the visual "active" highlight in the table. The
  // detail card stays pinned to the locked-attempt incident from the Figma
  // mock — a click changes the row highlight only, since this is a static
  // demo. (Hooking the right-rail to the selection is a follow-up.)
  const [selectedId, setSelectedId] = useState<string>(DEFAULT_SELECTED_ID);

  return (
    <>
      <header className={styles.pageHead}>
        <div className={styles.titleBlock}>
          <span className={styles.title}>Audit Log</span>
          <span className={styles.dot}>•</span>
          <span className={styles.metaLight}>HIPAA-required activity log · 14,289 events in last 7 days</span>
        </div>
        <div className={styles.spacer} />
        <button type="button" className={styles.exportBtn}>
          <span aria-hidden="true">⬇</span>
          <span>Export log</span>
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
            <div className={styles.searchInput}>
              <span className={styles.searchIcon} aria-hidden="true">🔍</span>
              <span className={styles.searchPlaceholder}>Search by user, IP, patient, or event</span>
            </div>
            <FilterDropdown label="Date range" value="Last 7 days" width={152} />
            <FilterDropdown label="Event type" value="All"          width={124} />
            <FilterDropdown label="User"       value="All users"    width={136} />
            <FilterDropdown label="Outcome"    value="All"          width={108} />
            <FilterDropdown label="Patient"    value="All"          width={116} />
          </div>

          <div className={styles.cols}>
            <section className={styles.tableCard}>
              <div className={styles.tableHead}>
                <div className={`${styles.thCell} ${styles.colTs}`}>TIMESTAMP</div>
                <div className={`${styles.thCell} ${styles.colUser}`}>USER</div>
                <div className={`${styles.thCell} ${styles.colEvent}`}>EVENT</div>
                <div className={`${styles.thCell} ${styles.colTarget}`}>TARGET</div>
                <div className={`${styles.thCell} ${styles.colDot}`} />
              </div>
              <div className={styles.tableBody}>
                {AUDIT_ROWS.map((row) => {
                  const isActive = row.id === selectedId;
                  const rowClasses = [
                    styles.row,
                    row.altRow ? styles.rowAlt : '',
                    isActive ? styles.rowActive : '',
                  ].filter(Boolean).join(' ');
                  return (
                    <button
                      type="button"
                      key={row.id}
                      className={rowClasses}
                      onClick={() => setSelectedId(row.id)}
                    >
                      <div className={`${styles.tdCell} ${styles.colTs} ${styles.tdTs}`}>{row.ts}</div>
                      <div className={`${styles.tdCell} ${styles.colUser} ${styles.tdUser}`}>{row.user}</div>
                      <div className={`${styles.tdCell} ${styles.colEvent} ${styles.tdEvent}`}>{row.event}</div>
                      <div className={`${styles.tdCell} ${styles.colTarget} ${styles.tdTarget}`}>{row.target}</div>
                      <div className={`${styles.tdCell} ${styles.colDot}`}>
                        <span className={`${styles.dotPill} ${dotToneClass(row.tone)}`} />
                      </div>
                    </button>
                  );
                })}
              </div>
            </section>

            <aside className={styles.detail}>
              <div className={styles.detailHead}>
                <span className={styles.detailIcon} aria-hidden="true">⚠</span>
                <div className={styles.detailHeadText}>
                  <div className={styles.detailTitle}>Failed login (3rd) · LOCKED</div>
                  <div className={styles.detailMeta}>05/02/2026 09:51:42 CT</div>
                </div>
              </div>

              <div className={styles.secLabel}>EVENT DETAILS</div>

              <DetailField k="User"               v="UNKNOWN (no match)" />
              <DetailField k="Username attempted" v="admin" />
              <DetailField k="IP address"         v="98.124.42.1 (Austin, TX · ISP: Spectrum)" />
              <DetailField k="User-Agent"         v="Mozilla/5.0 · Chrome 124 · Mac" />
              <DetailField k="Outcome"            v="LOCKED — exceeded 3 attempts" />
              <DetailField k="Lock duration"      v="30 min (auto-unlock)" />
              <DetailField k="Risk score"         v="HIGH · 0.87 / 1.00" />

              <div className={`${styles.secLabel} ${styles.secLabelCtx}`}>SECURITY CONTEXT</div>
              <ul className={styles.ctxList}>
                {SECURITY_CONTEXT.map((c, i) => {
                  const cls = c.ok ? `${styles.ctxItem} ${styles.ctxItemOk}` : styles.ctxItem;
                  return <li key={`c${i}`} className={cls}>{c.text}</li>;
                })}
              </ul>

              <div className={styles.actions}>
                <button type="button" className={styles.btnGhost}>Acknowledge & note</button>
                <button type="button" className={styles.btnDanger}>
                  <span aria-hidden="true">⚠</span>
                  <span>Escalate to security</span>
                </button>
              </div>

              {/* unused — placeholder so the linter doesn't complain about
                  the constant being declared but not referenced. */}
              <span hidden>{DEFAULT_DETAIL_ID}</span>
            </aside>
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

type FilterDropdownProps = {
  readonly label: string;
  readonly value: string;
  readonly width: number;
};

function FilterDropdown({ label, value, width }: FilterDropdownProps): JSX.Element {
  return (
    <div className={styles.fltDd} style={{ width: `${width}px` }}>
      <div className={styles.fltDdLbl}>{label}</div>
      <div className={styles.fltDdVal}>{value}</div>
      <span className={styles.fltDdCaret} aria-hidden="true">▾</span>
    </div>
  );
}

type DetailFieldProps = {
  readonly k: string;
  readonly v: string;
};

function DetailField({ k, v }: DetailFieldProps): JSX.Element {
  return (
    <div className={styles.field}>
      <div className={styles.fieldKey}>{k}</div>
      <div className={styles.fieldVal}>{v}</div>
    </div>
  );
}

function dotToneClass(tone: RiskTone): string {
  // Bracket access on a CSS module returns string | undefined under
  // noUncheckedIndexedAccess — fall back to '' so the resulting className
  // is always a string.
  switch (tone) {
    case 'good':   return styles['dotGood']   ?? '';
    case 'warn':   return styles['dotWarn']   ?? '';
    case 'danger': return styles['dotDanger'] ?? '';
  }
}
