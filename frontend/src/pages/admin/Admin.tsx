// Admin — Figma "Screen 9 — Admin". Renders the AgentForge admin overview:
// breadcrumb header, left category sidebar, top-line stats, two action panels
// (Quick Actions / System), and a Recent Admin Activity feed.
//
// This is a 1:1 port of the PHP-rendered mock previously at
// /interface/super/copilot_admin.php (which itself included
// copilot_admin_sidebar.php as a partial). Sidebar items are now inlined into
// this component — there is no React-side partial. Data is hardcoded demo
// content matching the Figma; future work would source it from
// /apis/copilot/admin/* endpoints.

import type { BootContext } from '../../shared/lib/bootContext';
import styles from './Admin.module.css';

type SidebarItem = {
  readonly label: string;
  readonly href: string;
  readonly active: boolean;
};

type StatCard = {
  readonly label: string;
  readonly value: string;
  readonly sub: string;
  readonly dotColor: string;
};

type ActionItem = {
  readonly icon: string;
  readonly title: string;
  readonly desc: string;
  readonly href: string;
};

type ActivityRow = {
  readonly time: string;
  readonly tag: string;
  readonly desc: string;
};

const SIDEBAR_ITEMS: readonly SidebarItem[] = [
  { label: 'Overview',          href: '/interface/super/copilot_admin.php',             active: true  },
  { label: 'Practice Settings', href: '/interface/super/copilot_practice_settings.php', active: false },
  { label: 'Users & Groups',    href: '/interface/super/copilot_users.php',             active: false },
  { label: 'ACL',               href: '/interface/super/copilot_acl.php',               active: false },
  { label: 'Facilities',        href: '/interface/super/copilot_facilities.php',        active: false },
  { label: 'Forms & Layouts',   href: '/interface/super/copilot_forms_layouts.php',     active: false },
  { label: 'Templates',         href: '/interface/super/copilot_templates.php',         active: false },
  { label: 'Coding & Lists',    href: '/interface/super/copilot_coding_lists.php',      active: false },
  { label: 'Modules',           href: '/interface/super/copilot_module_installer.php',  active: false },
  { label: 'System',            href: '/interface/super/copilot_system.php',            active: false },
  { label: 'Logs & Audit',      href: '/interface/super/copilot_audit.php',             active: false },
];

const STATS: readonly StatCard[] = [
  { label: 'Active Users',     value: '47',     sub: '+3 this week',        dotColor: '#008C8C' },
  { label: 'Patients',         value: '12,408', sub: '+182 this month',     dotColor: '#4885D9' },
  { label: 'Encounters Today', value: '62',     sub: '8 awaiting sign-off', dotColor: '#FA8C33' },
  { label: 'System Health',    value: 'OK',     sub: 'All services up',     dotColor: '#26A65B' },
];

const QUICK_ACTIONS: readonly ActionItem[] = [
  { icon: '\u{1F464}', title: 'Add new user',       desc: 'Provision a clinical or admin account',   href: '/interface/super/copilot_users.php' },
  { icon: '\u{1F4CB}', title: 'Create form layout', desc: 'Add a custom intake or note form',        href: '/interface/super/copilot_forms_layouts.php' },
  { icon: '\u{1F3E5}', title: 'Add facility',       desc: 'Register a new clinic or location',       href: '/interface/super/copilot_facilities.php' },
  { icon: '\u{1F510}', title: 'Update ACL roles',   desc: 'Adjust permissions for an existing role', href: '/interface/super/copilot_acl.php' },
];

const SYSTEM_ACTIONS: readonly ActionItem[] = [
  { icon: '\u{1F504}', title: 'Run backup now',    desc: 'Database snapshot to local + S3',     href: '/interface/super/copilot_system.php' },
  { icon: '\u{1F4DC}', title: 'View audit log',    desc: 'Access events for last 24 hours',     href: '/interface/super/copilot_audit.php' },
  { icon: '\u{1F310}', title: 'Manage modules',    desc: 'Enable / disable installed modules',  href: '/interface/super/copilot_module_installer.php' },
  { icon: '⚙',    title: 'Site preferences',  desc: 'Globals.php and feature flags',       href: '/interface/super/copilot_practice_settings.php' },
];

const ACTIVITY: readonly ActivityRow[] = [
  { time: '09:42 AM',  tag: 'user.created',     desc: 'Dr. Allison Park added by Admin' },
  { time: '09:18 AM',  tag: 'module.enabled',   desc: 'Carecoordination module enabled' },
  { time: '08:51 AM',  tag: 'acl.updated',      desc: 'Nurse role granted patients/notes write' },
  { time: 'Yesterday', tag: 'backup.completed', desc: 'Daily backup uploaded to S3 (842 MB)' },
];

type AdminProps = {
  readonly boot: BootContext;
};

export function Admin(_props: AdminProps): JSX.Element {
  return (
    <>
      <header className={styles.header}>
        <div className={styles.title}>Admin</div>
        <div className={styles.bcrumb}>
          <span className={styles.bcrumbSep}>/</span>
          <span className={styles.bcrumbMid}>System</span>
          <span className={styles.bcrumbSep}>/</span>
          <span className={styles.bcrumbEnd}>Overview</span>
        </div>
      </header>

      <div className={styles.shell}>
        <aside className={styles.sidebar}>
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

          <section className={styles.stats}>
            {STATS.map((s) => (
              <div key={s.label} className={styles.stat}>
                <div className={styles.statLabel}>{s.label}</div>
                <div className={styles.statValue}>{s.value}</div>
                <div className={styles.statSub}>
                  <span className={styles.statDot} style={{ backgroundColor: s.dotColor }} />
                  <span>{s.sub}</span>
                </div>
              </div>
            ))}
          </section>

          <section className={styles.panels}>
            <ActionPanel title="Quick Actions" actions={QUICK_ACTIONS} />
            <ActionPanel title="System"        actions={SYSTEM_ACTIONS} />
          </section>

          <section className={styles.activity}>
            <div className={styles.activityHead}>
              <div className={styles.activityTitle}>Recent Admin Activity</div>
              <div className={styles.activitySpacer} />
              <a className={styles.activityLink} href="/interface/super/copilot_audit.php" target="_self">
                View all →
              </a>
            </div>
            {ACTIVITY.map((row) => (
              <div key={`${row.time}-${row.tag}`} className={styles.activityRow}>
                <div className={styles.activityTime}>{row.time}</div>
                <div className={styles.activityTag}>{row.tag}</div>
                <div className={styles.activityDesc}>{row.desc}</div>
              </div>
            ))}
          </section>

        </main>
      </div>
    </>
  );
}

type ActionPanelProps = {
  readonly title: string;
  readonly actions: readonly ActionItem[];
};

function ActionPanel({ title, actions }: ActionPanelProps): JSX.Element {
  return (
    <div className={styles.panel}>
      <div className={styles.panelHead}>
        <div className={styles.panelTitle}>{title}</div>
      </div>
      {actions.map((a) => (
        <a key={a.title} className={styles.action} href={a.href} target="_self">
          <span className={styles.actionIcon}>{a.icon}</span>
          <div className={styles.actionText}>
            <div className={styles.actionTitle}>{a.title}</div>
            <div className={styles.actionDesc}>{a.desc}</div>
          </div>
          <span className={styles.actionArrow}>→</span>
        </a>
      ))}
    </div>
  );
}
