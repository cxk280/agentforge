// Users & Groups — Figma "Screen 52 — Users & Groups". Renders the
// AgentForge admin sub-page for managing user accounts, groups, service
// accounts, and pending invites. Layout: left admin sidebar (grouped by
// Users & Access / Practice / Clinical / System) + main column with a
// page head (title + meta + Import CSV / New group / New user buttons),
// a tab strip (Users / Groups / Service accounts / Pending invites), a
// filter row (search + role + group + status + MFA dropdowns), and a
// paginated user table.
//
// This is a 1:1 port of the PHP-rendered mock previously at
// /interface/super/copilot_users.php. Data is hardcoded demo content
// matching the Figma. State is held in React but behaves as a static
// mock for now: tab/filter changes update local state and re-filter the
// in-memory user list. The original DB-backed implementation lives at
// copilot_users.php.bak.

import { useMemo, useState } from 'react';
import type { BootContext } from '../../shared/lib/bootContext';
import styles from './Users.module.css';

type TabKey = 'users' | 'groups' | 'services' | 'invites';
type RoleFilter = 'any' | 'provider' | 'nurse' | 'fd' | 'billing' | 'admin';
type StatusFilter = 'active' | 'inactive' | 'all';
type MfaFilter = 'any' | 'on' | 'off';
type Badge = '' | 'svc' | 'inactive' | 'warn';

type SidebarItem = {
  readonly label: string;
  readonly href: string;
  readonly active: boolean;
};

type SidebarGroup = {
  readonly label: string;
  readonly items: readonly SidebarItem[];
};

type UserRow = {
  readonly id: string;
  readonly name: string;
  readonly initials: string;
  readonly username: string;
  readonly email: string;          // '-' for empty
  readonly role: string;           // display label
  readonly roleKey: RoleFilter;    // for the role filter dropdown
  readonly groups: readonly string[];
  readonly mfaOn: boolean;
  readonly active: boolean;
  readonly lastLogin: string;      // pre-formatted relative phrase
  readonly badge: Badge;           // mini-pill on LAST LOGIN cell
  readonly isService: boolean;
};

type GroupRow = {
  readonly name: string;
  readonly members: number;
};

export type UsersPayload = {
  readonly users: readonly UserRow[];
  readonly groups: readonly GroupRow[];
  readonly activeCount: number;
  readonly serviceCount: number;
};

const SIDEBAR: readonly SidebarGroup[] = [
  {
    label: 'Users & Access',
    items: [
      { label: 'Users & Groups',  href: '/interface/super/copilot_users.php', active: true  },
      { label: 'ACL Editor',      href: '/interface/super/copilot_acl.php',   active: false },
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

// Demo users — read directly off the Figma frame (101:2). Order
// preserved.
const ALL_USERS: readonly UserRow[] = [
  { id: '1',  name: 'Eduardo Rivera, MD',  initials: 'ER', username: 'erivera',     email: 'erivera@rfm-tx.org',     role: 'Provider',         roleKey: 'provider', groups: ['Clinical', 'MIPS Lead'], mfaOn: true,  active: true,  lastLogin: '2 min ago',   badge: '',         isService: false },
  { id: '2',  name: 'Karen Chen, MD',      initials: 'KC', username: 'kchen',       email: 'kchen@rfm-tx.org',       role: 'Provider',         roleKey: 'provider', groups: ['Clinical'],              mfaOn: true,  active: true,  lastLogin: '12 min ago',  badge: '',         isService: false },
  { id: '3',  name: 'Rajesh Patel, MD',    initials: 'RP', username: 'rpatel',      email: 'rpatel@rfm-tx.org',      role: 'Provider',         roleKey: 'provider', groups: ['Clinical'],              mfaOn: true,  active: true,  lastLogin: '1h ago',      badge: '',         isService: false },
  { id: '4',  name: 'Sarah Jones, NP',     initials: 'SJ', username: 'sjones',      email: 'sjones@rfm-tx.org',      role: 'Provider (NP)',    roleKey: 'provider', groups: ['Clinical', 'Telehealth'], mfaOn: true,  active: true,  lastLogin: '3h ago',      badge: '',         isService: false },
  { id: '5',  name: 'Maria Gonzalez, RN',  initials: 'MG', username: 'mgonzalez',   email: 'mgonzalez@rfm-tx.org',   role: 'Nurse',            roleKey: 'nurse',    groups: ['Clinical', 'Vaccines'],  mfaOn: true,  active: true,  lastLogin: '30 min ago',  badge: '',         isService: false },
  { id: '6',  name: 'Sandra Park',         initials: 'SP', username: 'spark',       email: 'spark@rfm-tx.org',       role: 'Front desk',       roleKey: 'fd',       groups: ['Reception'],             mfaOn: true,  active: true,  lastLogin: '45 min ago',  badge: '',         isService: false },
  { id: '7',  name: 'Linda Brown',         initials: 'LB', username: 'lbrown',      email: 'lbrown@rfm-tx.org',      role: 'Billing',          roleKey: 'billing',  groups: ['Billing', 'Reports'],    mfaOn: true,  active: true,  lastLogin: '2h ago',      badge: '',         isService: false },
  { id: '8',  name: 'Marcus Webb',         initials: 'MW', username: 'mwebb',       email: 'mwebb@rfm-tx.org',       role: 'IT Admin',         roleKey: 'admin',    groups: ['System Admin'],          mfaOn: true,  active: true,  lastLogin: '1 day ago',   badge: '',         isService: false },
  { id: '9',  name: 'Brad Sweeney (BMET)', initials: 'BS', username: 'bsweeney',    email: 'bsweeney@rfm-tx.org',    role: 'Maintenance',      roleKey: 'any',      groups: ['Maintenance'],           mfaOn: false, active: true,  lastLogin: '3 days ago',  badge: 'warn',     isService: false },
  { id: '10', name: 'Hospitalist Coverage',initials: 'HC', username: 'hospital_svc',email: '-',                       role: 'Service account',  roleKey: 'any',      groups: ['Cross-coverage'],        mfaOn: false, active: true,  lastLogin: '7 days ago',  badge: 'svc',      isService: true  },
  { id: '11', name: 'Kelly Roberts',       initials: 'KR', username: 'kroberts',    email: 'kroberts@former.org',    role: 'Provider (locum)', roleKey: 'provider', groups: ['Clinical'],              mfaOn: false, active: false, lastLogin: '42 days ago', badge: 'inactive', isService: false },
  { id: '12', name: 'e-Rx Bridge',         initials: 'EB', username: 'erx_svc',     email: '-',                       role: 'Service account',  roleKey: 'any',      groups: ['Integrations'],          mfaOn: false, active: true,  lastLogin: '5 min ago',   badge: 'svc',      isService: true  },
];

const ALL_GROUPS: readonly GroupRow[] = [
  { name: 'Billing',         members: 2 },
  { name: 'Clinical',        members: 6 },
  { name: 'Cross-coverage',  members: 1 },
  { name: 'Integrations',    members: 1 },
  { name: 'Maintenance',     members: 1 },
  { name: 'MIPS Lead',       members: 1 },
  { name: 'Reception',       members: 1 },
  { name: 'Reports',         members: 1 },
  { name: 'System Admin',    members: 1 },
  { name: 'Telehealth',      members: 1 },
  { name: 'Vaccines',        members: 1 },
];

const TABS: ReadonlyArray<{ readonly key: TabKey; readonly label: string }> = [
  { key: 'users',    label: 'Users' },
  { key: 'groups',   label: 'Groups' },
  { key: 'services', label: 'Service accounts' },
  { key: 'invites',  label: 'Pending invites' },
];

type UsersProps = {
  readonly boot: BootContext;
  readonly payload: UsersPayload;
};

export function Users({ payload }: UsersProps): JSX.Element {
  const [tab, setTab]                 = useState<TabKey>('users');
  const [q, setQ]                     = useState<string>('');
  const [roleFilter, setRoleFilter]   = useState<RoleFilter>('any');
  const [groupFilter, setGroupFilter] = useState<string>('');
  const [statusFilter, setStatusFilter] = useState<StatusFilter>('active');
  const [mfaFilter, setMfaFilter]     = useState<MfaFilter>('any');

  // Live data when present, demo otherwise — payload.users / payload.groups
  // come from the wrapper's DB query.
  const allUsers: readonly UserRow[] = payload.users.length > 0 ? payload.users : ALL_USERS;
  const allGroups: readonly GroupRow[] = payload.groups.length > 0 ? payload.groups : ALL_GROUPS;
  const activeCount  = payload.users.length > 0 ? payload.activeCount : ALL_USERS.filter((u) => u.active && !u.isService).length;
  const groupsCount  = allGroups.length;
  const serviceCount = payload.users.length > 0 ? payload.serviceCount : ALL_USERS.filter((u) => u.isService).length;

  const visibleUsers = useMemo<readonly UserRow[]>(() => {
    return allUsers.filter((u) => {
      // Tab gating: services tab only shows services; users tab hides services.
      if (tab === 'users'    && u.isService) return false;
      if (tab === 'services' && !u.isService) return false;

      // Status (services tab default = all; users default = active).
      const effStatus: StatusFilter = statusFilter;
      if (effStatus === 'active'   && !u.active) return false;
      if (effStatus === 'inactive' &&  u.active) return false;

      // Role filter (only on users tab — services don't use it).
      if (tab === 'users' && roleFilter !== 'any' && u.roleKey !== roleFilter) return false;

      // Group filter
      if (groupFilter !== '' && !u.groups.includes(groupFilter)) return false;

      // MFA filter
      if (mfaFilter === 'on'  && !u.mfaOn) return false;
      if (mfaFilter === 'off' &&  u.mfaOn) return false;

      // Search box
      if (q.trim() !== '') {
        const needle = q.trim().toLowerCase();
        const hay = `${u.name} ${u.username} ${u.email}`.toLowerCase();
        if (!hay.includes(needle)) return false;
      }

      return true;
    });
  }, [tab, q, roleFilter, groupFilter, statusFilter, mfaFilter]);

  const defaultStatus: StatusFilter = tab === 'services' ? 'all' : 'active';
  const hasFilter =
    q !== '' ||
    roleFilter !== 'any' ||
    groupFilter !== '' ||
    statusFilter !== defaultStatus ||
    mfaFilter !== 'any';

  const resetFilters = (): void => {
    setQ('');
    setRoleFilter('any');
    setGroupFilter('');
    setStatusFilter(defaultStatus);
    setMfaFilter('any');
  };

  const switchTab = (next: TabKey): void => {
    setTab(next);
    // Status default depends on the destination tab.
    setStatusFilter(next === 'services' ? 'all' : 'active');
  };

  return (
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
          <div className={styles.pageheadTitle}>Users &amp; Groups</div>
          <span className={styles.pageheadDot}>·</span>
          <div className={styles.pageheadMeta}>
            {activeCount} active users · {groupsCount} groups · {serviceCount} service accounts
          </div>
          <div className={styles.pageheadSpacer} />
          <button type="button" className={styles.btnGhost}>
            <span className={styles.btnGhostIcon}>⤓</span>
            Import CSV
          </button>
          <button
            type="button"
            className={styles.btnGhost}
            onClick={() => switchTab('groups')}
          >
            <span className={styles.btnGhostIcon}>+</span>
            New group
          </button>
          <button type="button" className={styles.btnPrimary}>
            + New user
          </button>
        </header>

        <nav className={styles.tabs}>
          {TABS.map((t) => {
            const isActive = tab === t.key;
            const cls = isActive ? `${styles.tab} ${styles.tabActive}` : styles.tab;
            return (
              <button
                type="button"
                key={t.key}
                className={cls}
                onClick={() => switchTab(t.key)}
              >
                {t.label}
              </button>
            );
          })}
        </nav>

        <main className={styles.content}>
          {(tab === 'users' || tab === 'services') && (
            <>
              <div className={styles.filter}>
                <div className={styles.search}>
                  <span className={styles.searchIcon}>🔍</span>
                  <input
                    type="text"
                    placeholder="Search by name, email, or username"
                    value={q}
                    onChange={(e) => setQ(e.target.value)}
                  />
                </div>

                <select
                  className={styles.dd}
                  value={roleFilter}
                  onChange={(e) => setRoleFilter(e.target.value as RoleFilter)}
                  aria-label="Role"
                >
                  <option value="any">All</option>
                  <option value="provider">Provider</option>
                  <option value="nurse">Nurse</option>
                  <option value="fd">Front desk</option>
                  <option value="billing">Billing</option>
                  <option value="admin">IT Admin</option>
                </select>

                <select
                  className={styles.dd}
                  value={groupFilter}
                  onChange={(e) => setGroupFilter(e.target.value)}
                  aria-label="Group"
                >
                  <option value="">All</option>
                  {allGroups.map((g) => (
                    <option key={g.name} value={g.name}>{g.name}</option>
                  ))}
                </select>

                <select
                  className={styles.dd}
                  value={statusFilter}
                  onChange={(e) => setStatusFilter(e.target.value as StatusFilter)}
                  aria-label="Status"
                >
                  <option value="active">Active</option>
                  <option value="inactive">Inactive</option>
                  <option value="all">All</option>
                </select>

                <select
                  className={styles.dd}
                  value={mfaFilter}
                  onChange={(e) => setMfaFilter(e.target.value as MfaFilter)}
                  aria-label="MFA"
                >
                  <option value="any">Any</option>
                  <option value="on">MFA on</option>
                  <option value="off">MFA off</option>
                </select>

                {hasFilter && (
                  <button type="button" className={styles.reset} onClick={resetFilters}>
                    Reset
                  </button>
                )}
              </div>

              {visibleUsers.length === 0 ? (
                <div className={styles.empty}>
                  <div className={styles.emptyLbl}>NO MATCHING USERS</div>
                  Adjust your filters or add a new user.
                </div>
              ) : (
                <div className={styles.tblWrap}>
                  <table className={styles.tbl}>
                    <thead>
                      <tr>
                        <th className={styles.thChk}><input type="checkbox" /></th>
                        <th className={styles.thLeft}>NAME</th>
                        <th className={styles.thLeft}>USERNAME</th>
                        <th className={styles.thLeft}>EMAIL</th>
                        <th className={styles.thLeft}>ROLE</th>
                        <th className={styles.thLeft}>GROUPS</th>
                        <th className={styles.thLeft}>MFA</th>
                        <th className={styles.thLeft}>LAST LOGIN</th>
                      </tr>
                    </thead>
                    <tbody>
                      {visibleUsers.map((u) => (
                        <tr key={u.id}>
                          <td className={styles.tdChk}><input type="checkbox" /></td>
                          <td>
                            <span className={styles.userCell}>
                              <span className={styles.avatar}>{u.initials}</span>
                              <span className={styles.userName}>{u.name}</span>
                            </span>
                          </td>
                          <td className={styles.muted}>{u.username}</td>
                          <td className={styles.muted}>{u.email}</td>
                          <td className={styles.muted}>{u.role}</td>
                          <td className={styles.muted}>
                            {u.groups.length === 0 ? '-' : u.groups.join(', ')}
                          </td>
                          <td>
                            {u.mfaOn ? (
                              <span className={styles.mfaOn} title="MFA registered">✓</span>
                            ) : (
                              <span className={styles.mfaOff}>—</span>
                            )}
                          </td>
                          <td className={styles.muted}>
                            <span className={styles.lastLoginRow}>
                              <span>{u.lastLogin}</span>
                              {u.badge === 'svc' && (
                                <span className={`${styles.miniPill} ${styles.miniPillSvc}`}>svc</span>
                              )}
                              {u.badge === 'inactive' && (
                                <span className={`${styles.miniPill} ${styles.miniPillInactive}`}>inactive</span>
                              )}
                              {u.badge === 'warn' && (
                                <span className={styles.mfaWarn} title="MFA disabled">⚠</span>
                              )}
                            </span>
                          </td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
              )}
            </>
          )}

          {tab === 'groups' && (
            <>
              <form
                className={styles.newGrp}
                onSubmit={(e) => {
                  e.preventDefault();
                  // Mock — no persistence. Real impl POSTs to copilot_users.php.bak handler.
                }}
              >
                <input
                  type="text"
                  name="name"
                  placeholder="New group name (e.g. Clinical, Billing, Reception)"
                  maxLength={100}
                />
                <button type="submit" className={styles.btnPrimary}>+ Create group</button>
              </form>

              <div className={styles.tblWrap}>
                <table className={styles.tbl}>
                  <thead>
                    <tr>
                      <th className={styles.thLeft}>GROUP</th>
                      <th className={styles.thLeft}>MEMBERS</th>
                      <th />
                    </tr>
                  </thead>
                  <tbody>
                    {allGroups.map((g) => (
                      <tr key={g.name}>
                        <td className={styles.userName}>{g.name}</td>
                        <td className={styles.muted}>{g.members}</td>
                        <td className={styles.muted}>
                          <button
                            type="button"
                            className={styles.linkBtn}
                            onClick={() => {
                              setGroupFilter(g.name);
                              switchTab('users');
                            }}
                          >
                            View members
                          </button>
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            </>
          )}

          {tab === 'invites' && (
            <div className={styles.empty}>
              <div className={styles.emptyLbl}>NO PENDING INVITES</div>
              OpenEMR creates accounts directly — there is no invite queue.
            </div>
          )}
        </main>
      </div>
    </div>
  );
}
