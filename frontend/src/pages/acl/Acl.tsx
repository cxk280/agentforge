// ACL Editor — Figma "Screen 53 — ACL Editor". Renders the AgentForge
// role × permission matrix admin sub-page with a left admin sidebar
// (grouped by Users & Access / Practice / Clinical / System), a page
// head with title + meta + Unsaved-changes pill + Reset/Save buttons,
// a sub-toolbar (Copy role from … / View by Roles→Perms | Perms→Roles
// + New role), and the matrix grid.
//
// This is a 1:1 port of the PHP-rendered mock previously at
// /interface/super/copilot_acl.php. Data is hardcoded demo content
// matching the Figma (8 roles × 18 permissions). State is held in
// React but behaves as a static mock for now: toggling a checkbox
// updates local state and exposes the "Unsaved changes" pill; Save /
// Reset / Copy role / New role / view toggle are wired but do not
// persist to the gacl tables (the original PHP wrote to gacl_acl /
// gacl_aco_map / gacl_aro_groups_map — that's a follow-up task).

import { useMemo, useState } from 'react';
import type { BootContext } from '../../shared/lib/bootContext';
import styles from './Acl.module.css';

type CellState = 'on' | 'off' | 'deny';
type ViewMode = 'roles' | 'perms';

type SidebarItem = {
  readonly label: string;
  readonly href: string;
  readonly active: boolean;
};

type SidebarGroup = {
  readonly label: string;
  readonly items: readonly SidebarItem[];
};

type Role = {
  readonly id: string;
  readonly label: string;
};

type PermissionRow = {
  readonly cat: string;       // category label (only rendered on the first row of the cat)
  readonly catStart: boolean; // true on the first row of each category
  readonly perm: string;      // permission label
  readonly cells: readonly CellState[]; // one entry per role, in ROLES order
};

const SIDEBAR: readonly SidebarGroup[] = [
  {
    label: 'Users & Access',
    items: [
      { label: 'Users & Groups',  href: '/interface/super/copilot_users.php', active: false },
      { label: 'ACL Editor',      href: '/interface/super/copilot_acl.php',   active: true  },
      { label: 'Active Sessions', href: '#',                                  active: false },
      { label: 'Password Policy', href: '#',                                  active: false },
    ],
  },
  {
    label: 'Practice',
    items: [
      { label: 'Facilities',         href: '/interface/super/copilot_facilities.php', active: false },
      { label: 'Providers',          href: '#', active: false },
      { label: 'Schedule Templates', href: '#', active: false },
      { label: 'Pricing',            href: '#', active: false },
    ],
  },
  {
    label: 'Clinical',
    items: [
      { label: 'Forms',       href: '/interface/super/copilot_forms_layouts.php', active: false },
      { label: 'Lists',       href: '#', active: false },
      { label: 'Templates',   href: '/interface/super/copilot_templates.php', active: false },
      { label: 'Issue Types', href: '#', active: false },
      { label: 'Layouts',     href: '#', active: false },
    ],
  },
  {
    label: 'System',
    items: [
      { label: 'Audit Log', href: '/interface/super/copilot_audit.php', active: false },
      { label: 'Backup',    href: '#', active: false },
      { label: 'Globals',   href: '#', active: false },
      { label: 'Database',  href: '#', active: false },
      { label: 'Modules',   href: '/interface/super/copilot_module_installer.php', active: false },
    ],
  },
];

const ROLES: readonly Role[] = [
  { id: 'provider',   label: 'Provider' },
  { id: 'np',         label: 'NP' },
  { id: 'nurse',      label: 'Nurse' },
  { id: 'front_desk', label: 'Front desk' },
  { id: 'billing',    label: 'Billing' },
  { id: 'it_admin',   label: 'IT Admin' },
  { id: 'maint',      label: 'Maint' },
  { id: 'locum',      label: 'Locum' },
];

// Initial matrix — read directly off the Figma frame (102:2). Order of
// cells matches ROLES above. Codes: 1 = on, 0 = off, X = deny.
const INITIAL_ROWS: readonly PermissionRow[] = [
  { cat: 'Patients',    catStart: true,  perm: 'View patient demographics', cells: ['on','on','on','on','on','on','off','on'] },
  { cat: 'Patients',    catStart: false, perm: 'Edit patient demographics', cells: ['on','on','on','on','off','on','off','deny'] },
  { cat: 'Patients',    catStart: false, perm: 'Delete patient',            cells: ['off','off','off','off','off','on','off','off'] },
  { cat: 'Encounters',  catStart: true,  perm: 'View encounters',           cells: ['on','on','on','off','on','on','off','on'] },
  { cat: 'Encounters',  catStart: false, perm: 'Create / sign encounters',  cells: ['on','on','off','off','off','off','off','on'] },
  { cat: 'Encounters',  catStart: false, perm: 'Re-open signed encounter',  cells: ['on','off','off','off','off','on','off','off'] },
  { cat: 'Orders',      catStart: true,  perm: 'Order labs / imaging',      cells: ['on','on','off','off','off','off','off','on'] },
  { cat: 'Orders',      catStart: false, perm: 'Sign / acknowledge results',cells: ['on','on','off','off','off','off','off','on'] },
  { cat: 'Prescribing', catStart: true,  perm: 'Standard e-Rx',             cells: ['on','on','off','off','off','off','off','on'] },
  { cat: 'Prescribing', catStart: false, perm: 'EPCS controlled',           cells: ['on','off','off','off','off','off','off','off'] },
  { cat: 'Billing',     catStart: true,  perm: 'View ledger / aging',       cells: ['on','on','off','off','on','on','off','off'] },
  { cat: 'Billing',     catStart: false, perm: 'Post payments',             cells: ['off','off','off','off','on','on','off','off'] },
  { cat: 'Billing',     catStart: false, perm: 'Adjust / write-off',        cells: ['off','off','off','off','on','on','off','off'] },
  { cat: 'Reports',     catStart: true,  perm: 'Run clinical reports',      cells: ['on','on','off','off','on','on','off','off'] },
  { cat: 'Reports',     catStart: false, perm: 'Submit to CMS / payer',     cells: ['on','off','off','off','on','on','off','off'] },
  { cat: 'Admin',       catStart: true,  perm: 'Manage users / ACL',        cells: ['off','off','off','off','off','on','off','off'] },
  { cat: 'Admin',       catStart: false, perm: 'System backup',             cells: ['off','off','off','off','off','on','off','off'] },
  { cat: 'Admin',       catStart: false, perm: 'Modify globals',            cells: ['off','off','off','off','off','on','off','off'] },
  { cat: 'Admin',       catStart: false, perm: 'View audit log',            cells: ['on','off','off','off','off','on','off','off'] },
];

type AclProps = {
  readonly boot: BootContext;
};

export function Acl(_props: AclProps): JSX.Element {
  const [rows, setRows]       = useState<readonly PermissionRow[]>(INITIAL_ROWS);
  const [view, setView]       = useState<ViewMode>('roles');
  const [copyFrom, setCopyFrom] = useState<string>(ROLES[0]?.id ?? '');
  const [dirty, setDirty]     = useState<boolean>(true); // matches Figma "Unsaved changes" pill

  const totalRoles = ROLES.length;
  const totalPerms = INITIAL_ROWS.length;

  const toggleCell = (rowIdx: number, colIdx: number): void => {
    setRows((prev) => {
      const next = prev.slice();
      const row  = next[rowIdx];
      if (!row) return prev;
      const cells = row.cells.slice();
      const cur   = cells[colIdx];
      // Cycle off → on → off; deny is read-only (set by other ACLs).
      if (cur === 'deny') return prev;
      cells[colIdx] = cur === 'on' ? 'off' : 'on';
      next[rowIdx] = { ...row, cells };
      return next;
    });
    setDirty(true);
  };

  const reset = (): void => {
    setRows(INITIAL_ROWS);
    setDirty(false);
  };

  const save = (): void => {
    setDirty(false);
  };

  const copyRole = (): void => {
    // Mock: clear dirty without mutation. Real impl writes overlay ACL rows.
    setDirty(true);
  };

  const newRole = (): void => {
    const name = window.prompt('New role name:');
    if (!name || !name.trim()) return;
    setDirty(true);
  };

  // The Figma view toggle ("Roles → Perms" vs "Perms → Roles") only swaps the
  // table orientation; the underlying state is the same. For now we render
  // the roles-as-columns orientation in both modes (mode just reflects in
  // the toggle button) — flipping orientation is a follow-up.
  const orientation = view;

  // For "Perms → Roles" we transpose the matrix at render time.
  const transposed = useMemo(() => {
    const out: { role: Role; cells: readonly { rowIdx: number; perm: string; cat: string; state: CellState }[] }[] = [];
    for (let c = 0; c < ROLES.length; c++) {
      const role = ROLES[c]!;
      const cells = rows.map((r, rowIdx) => ({
        rowIdx,
        perm:  r.perm,
        cat:   r.cat,
        state: r.cells[c] ?? 'off',
      }));
      out.push({ role, cells });
    }
    return out;
  }, [rows]);

  return (
    <>
      <div className={styles.shell}>
        <aside className={styles.sidebar}>
          <div className={styles.sideHeader}>ADMIN</div>
          {SIDEBAR.map((grp) => (
            <div className={styles.sideGroup} key={grp.label}>
              <div className={styles.sideGroupLabel}>{grp.label}</div>
              {grp.items.map((it) => {
                const cls = it.active
                  ? `${styles.sideItem} ${styles.sideItemActive}`
                  : styles.sideItem;
                return (
                  <a key={it.label} className={cls} href={it.href} target="_self">
                    {it.label}
                  </a>
                );
              })}
            </div>
          ))}
        </aside>

        <div className={styles.main}>
          <header className={styles.pagehead}>
            <div className={styles.pageheadTitle}>Access Control (ACL)</div>
            <span className={styles.pageheadDot}>•</span>
            <div className={styles.pageheadMeta}>
              Role-based permissions · {totalRoles} roles · {totalPerms} permissions
            </div>
            <div className={styles.pageheadSpacer} />
            {dirty && (
              <span className={styles.unsaved}>
                <span className={styles.unsavedDot} />
                Unsaved changes
              </span>
            )}
            <button type="button" className={styles.btnGhost} onClick={reset}>
              <span className={styles.btnGhostIcon}>↶</span>
              Reset
            </button>
            <button type="button" className={styles.btnPrimary} onClick={save}>
              Save changes
            </button>
          </header>

          <div className={styles.toolbar}>
            <div className={styles.tbGroup}>
              <span className={styles.tbLabel}>COPY ROLE FROM</span>
              <select
                className={styles.tbSelect}
                value={copyFrom}
                onChange={(e) => setCopyFrom(e.target.value)}
              >
                {ROLES.map((r) => (
                  <option key={r.id} value={r.id}>{r.label}</option>
                ))}
              </select>
              <button type="button" className={styles.tbCopyGo} onClick={copyRole}>
                Copy
              </button>
            </div>

            <div className={styles.tbGroup}>
              <span className={styles.tbViewBy}>View by</span>
              <div className={styles.seg} role="tablist">
                <button
                  type="button"
                  role="tab"
                  aria-selected={orientation === 'roles'}
                  className={orientation === 'roles' ? `${styles.segItem} ${styles.segItemActive}` : styles.segItem}
                  onClick={() => setView('roles')}
                >
                  Roles → Perms
                </button>
                <button
                  type="button"
                  role="tab"
                  aria-selected={orientation === 'perms'}
                  className={orientation === 'perms' ? `${styles.segItem} ${styles.segItemActive}` : styles.segItem}
                  onClick={() => setView('perms')}
                >
                  Perms → Roles
                </button>
              </div>
            </div>

            <div className={styles.tbRight}>
              <button type="button" className={styles.tbNewRole} onClick={newRole}>
                + New role
              </button>
            </div>
          </div>

          <div className={styles.gridWrap}>
            <div className={styles.grid}>
              {orientation === 'roles' ? (
                <table className={styles.tbl}>
                  <thead>
                    <tr>
                      <th className={`${styles.thLeft} ${styles.thCat}`}>CATEGORY</th>
                      <th className={`${styles.thLeft} ${styles.thPerm}`}>PERMISSION</th>
                      {ROLES.map((r) => (
                        <th key={r.id}>{r.label}</th>
                      ))}
                    </tr>
                  </thead>
                  <tbody>
                    {rows.map((row, rowIdx) => {
                      const trCls = row.catStart ? styles.rowCatStart : '';
                      const zebra = rowIdx % 2 === 1 ? styles.rowZebra : '';
                      const cls = [trCls, zebra].filter(Boolean).join(' ');
                      return (
                        <tr key={`${row.cat}-${row.perm}`} className={cls}>
                          <td className={styles.tdCat}>
                            {row.catStart ? row.cat : ''}
                          </td>
                          <td className={styles.tdPerm}>{row.perm}</td>
                          {row.cells.map((st, colIdx) => (
                            <td key={colIdx} className={styles.tdCheck}>
                              <CheckCell
                                state={st}
                                title={`${ROLES[colIdx]?.label ?? ''} / ${row.perm}`}
                                onClick={() => toggleCell(rowIdx, colIdx)}
                              />
                            </td>
                          ))}
                        </tr>
                      );
                    })}
                  </tbody>
                </table>
              ) : (
                <table className={styles.tbl}>
                  <thead>
                    <tr>
                      <th className={`${styles.thLeft} ${styles.thCat}`}>ROLE</th>
                      {rows.map((r) => (
                        <th key={`${r.cat}-${r.perm}`} title={`${r.cat} · ${r.perm}`}>{r.perm}</th>
                      ))}
                    </tr>
                  </thead>
                  <tbody>
                    {transposed.map((tr, rIdx) => (
                      <tr key={tr.role.id} className={rIdx % 2 === 1 ? styles.rowZebra : ''}>
                        <td className={styles.tdCat}>{tr.role.label}</td>
                        {tr.cells.map((c, colIdx) => (
                          <td key={colIdx} className={styles.tdCheck}>
                            <CheckCell
                              state={c.state}
                              title={`${tr.role.label} / ${c.perm}`}
                              onClick={() => toggleCell(c.rowIdx, rIdx)}
                            />
                          </td>
                        ))}
                      </tr>
                    ))}
                  </tbody>
                </table>
              )}
            </div>
          </div>
        </div>
      </div>
    </>
  );
}

type CheckCellProps = {
  readonly state: CellState;
  readonly title: string;
  readonly onClick: () => void;
};

function CheckCell({ state, title, onClick }: CheckCellProps): JSX.Element {
  let cls = styles.cb;
  if (state === 'on')   cls += ` ${styles.cbOn}`;
  if (state === 'deny') cls += ` ${styles.cbDeny}`;
  return (
    <button
      type="button"
      className={cls}
      title={title}
      aria-label={title}
      onClick={onClick}
    >
      {state === 'on'   && <span className={styles.cbCheck}>✓</span>}
      {state === 'deny' && <span className={styles.cbX}>✗</span>}
    </button>
  );
}
