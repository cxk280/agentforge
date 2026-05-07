// SystemStatus — Figma "Screen 56 — Backup / System Status". Renders the
// AgentForge admin Backup & System Status page: page header with Restore /
// Run-backup-now actions, three live status cards (Database / App Server /
// Storage), backup history table, and a System Info + Open Alerts pair on
// the right.
//
// This is a 1:1 port of the PHP-rendered page previously at
// /interface/super/copilot_system.php (preserved at copilot_system.php.bak).
// Demo content is hardcoded to mirror the Figma source exactly — the
// original PHP had wired live system stats (DB version, disk free, MFA gap,
// SSL cert expiry, etc.); those are intentionally elided here while we
// migrate to React. Future work would source them from
// /apis/copilot/system/* endpoints.
//
// Sidebar items are inlined (no React-side partial), matching the Admin
// page pattern.

import type { BootContext } from '../../shared/lib/bootContext';
import styles from './SystemStatus.module.css';

type SidebarItem = {
  readonly label: string;
  readonly active: boolean;
};

type SidebarGroup = {
  readonly label: string;
  readonly items: readonly SidebarItem[];
};

type StatusTone = 'good' | 'warn' | 'danger';

type AlertTone = 'warn' | 'info';

type BackupStatus = 'success' | 'warn' | 'danger';

type BackupRow = {
  readonly started: string;
  readonly type: string;
  readonly size: string;
  readonly duration: string;
  readonly location: string;
  readonly statLabel: string;
  readonly statTone: BackupStatus;
};

type AlertItem = {
  readonly tone: AlertTone;
  readonly title: string;
  readonly body: string;
};

type SysInfoRow = {
  readonly k: string;
  readonly v: string;
};

const SIDEBAR_GROUPS: readonly SidebarGroup[] = [
  {
    label: 'Users & Access',
    items: [
      { label: 'Users & Groups',  active: false },
      { label: 'ACL Editor',      active: false },
      { label: 'Active Sessions', active: false },
      { label: 'Password Policy', active: false },
    ],
  },
  {
    label: 'Practice',
    items: [
      { label: 'Facilities',         active: false },
      { label: 'Providers',          active: false },
      { label: 'Schedule Templates', active: false },
      { label: 'Pricing',            active: false },
    ],
  },
  {
    label: 'Clinical',
    items: [
      { label: 'Forms',       active: false },
      { label: 'Lists',       active: false },
      { label: 'Templates',   active: false },
      { label: 'Issue Types', active: false },
      { label: 'Layouts',     active: false },
    ],
  },
  {
    label: 'System',
    items: [
      { label: 'Audit Log', active: false },
      { label: 'Backup',    active: true  },
      { label: 'Globals',   active: false },
      { label: 'Database',  active: false },
      { label: 'Modules',   active: false },
    ],
  },
];

// Sparkline bar heights (px) — matches Figma node 105:80–105:103 (DB) and
// 105:111–105:134 (App). 24 bars per card, 4px wide each.
const DB_SPARK: readonly number[] = [
  12.13, 19.96, 23.80, 21.54, 17.70, 8.78, 4.07, 3.22, 6.83, 14.47,
  20.13, 22.17, 21.74, 18.93, 11.37, 4.90, 5.43, 8.94, 13.58, 22.23,
  25.70, 22.85, 17.61, 8.37,
];

const APP_SPARK: readonly number[] = [
  12.59, 21.43, 24.38, 21.14, 15.92, 12.29, 4.03, 4.59, 6.00, 15.59,
  19.26, 23.36, 24.42, 17.25, 10.92, 4.71, 5.83, 6.43, 16.07, 19.17,
  25.63, 24.16, 17.46, 11.06,
];

const BACKUP_HISTORY: readonly BackupRow[] = [
  { started: '05/02 02:00', type: 'Full',        size: '118 GB', duration: '42 min', location: 'S3 + local NFS',     statLabel: 'Success',              statTone: 'success' },
  { started: '05/01 02:00', type: 'Incremental', size: '12 GB',  duration: '6 min',  location: 'S3 + local NFS',     statLabel: 'Success',              statTone: 'success' },
  { started: '04/30 02:00', type: 'Incremental', size: '14 GB',  duration: '8 min',  location: 'S3 + local NFS',     statLabel: 'Success',              statTone: 'success' },
  { started: '04/29 02:00', type: 'Incremental', size: '11 GB',  duration: '6 min',  location: 'S3 + local NFS',     statLabel: 'Success',              statTone: 'success' },
  { started: '04/28 02:00', type: 'Incremental', size: '13 GB',  duration: '7 min',  location: 'S3 + local NFS',     statLabel: 'Success',              statTone: 'success' },
  { started: '04/27 02:00', type: 'Full',        size: '116 GB', duration: '40 min', location: 'S3 + local NFS',     statLabel: 'Success',              statTone: 'success' },
  { started: '04/26 02:00', type: 'Incremental', size: '9 GB',   duration: '5 min',  location: 'S3 + local NFS',     statLabel: 'Success',              statTone: 'success' },
  { started: '04/25 02:14', type: 'Incremental', size: '10 GB',  duration: '—', location: '—',             statLabel: 'Failed (NFS timeout)', statTone: 'danger'  },
  { started: '04/24 02:00', type: 'Incremental', size: '11 GB',  duration: '7 min',  location: 'S3 only · NFS down', statLabel: 'Partial',         statTone: 'warn'    },
  { started: '04/23 02:00', type: 'Incremental', size: '12 GB',  duration: '7 min',  location: 'S3 + local NFS',     statLabel: 'Success',              statTone: 'success' },
];

const SYS_INFO: readonly SysInfoRow[] = [
  { k: 'OpenEMR version', v: '7.0.4 (build 2026-04-21)' },
  { k: 'PHP',             v: '8.2.18' },
  { k: 'MariaDB',         v: '10.11.7' },
  { k: 'OS',              v: 'Ubuntu 22.04.4 LTS' },
  { k: 'Uptime',          v: '42 days, 6h' },
  { k: 'Last patched',    v: '04/28/2026' },
];

const ALERTS: readonly AlertItem[] = [
  { tone: 'warn', title: 'Storage 82% full',    body: 'Approaching 85% threshold · expand or prune logs' },
  { tone: 'warn', title: 'NFS retry recovered', body: '04/25 backup partial; 04/26+ healthy' },
  { tone: 'info', title: 'SSL cert renewal',    body: 'Expires in 38 days · auto-renew scheduled' },
  { tone: 'warn', title: 'MFA enrollment gap',  body: '3 users without MFA · enforce by 05/15' },
  { tone: 'info', title: 'New OpenEMR patch',   body: '7.0.5 available · contains security fix' },
];

type SystemStatusProps = {
  readonly boot: BootContext;
};

export function SystemStatus(_props: SystemStatusProps): JSX.Element {
  return (
    <>
      <header className={styles.header}>
        <div className={styles.titleBlock}>
          <span className={styles.title}>Backup &amp; System Status</span>
          <span className={styles.dotSep}>·</span>
          <span className={styles.subtitle}>Production environment · all systems operational</span>
        </div>
        <div className={styles.spacer} />
        <button type="button" className={styles.btnGhost}>
          <span className={styles.btnIcon} aria-hidden="true">⌫</span>
          <span>Restore</span>
        </button>
        <button type="button" className={styles.btnPrimary}>
          <span className={styles.btnIcon} aria-hidden="true">⬇</span>
          <span>Run backup now</span>
        </button>
      </header>

      <div className={styles.shell}>
        <aside className={styles.sidebar}>
          <div className={styles.sideHeader}>ADMIN</div>
          {SIDEBAR_GROUPS.map((g) => (
            <div key={g.label} className={styles.sideGroup}>
              <div className={styles.sideGroupLabel}>{g.label}</div>
              {g.items.map((item) => {
                const cls = item.active
                  ? `${styles.sideItem} ${styles.sideItemActive}`
                  : styles.sideItem;
                return (
                  <a key={item.label} href="#" className={cls} target="_self">
                    {item.label}
                  </a>
                );
              })}
            </div>
          ))}
        </aside>

        <main className={styles.content}>

          <section className={styles.statsRow}>
            <StatusCard
              name="Database"
              tone="good"
              pillLabel="Healthy"
              meta1="MariaDB 10.11 · Galera 3-node"
              meta2="24ms p95 · 142 conn"
            >
              <Spark heights={DB_SPARK} />
            </StatusCard>

            <StatusCard
              name="App Server"
              tone="good"
              pillLabel="Healthy"
              meta1="PHP 8.2 · Apache 2.4"
              meta2="68ms p95 · 4 workers"
            >
              <Spark heights={APP_SPARK} />
            </StatusCard>

            <StatusCard
              name="Storage"
              tone="warn"
              pillLabel="82% used"
              meta1="512 GB SSD · NFS mounted"
              meta2="418 GB / 512 GB"
            >
              <div className={styles.progressTrack}>
                <div className={styles.progressFill} style={{ width: '82%' }} />
              </div>
            </StatusCard>
          </section>

          <section className={styles.cols}>
            <div className={styles.bh}>
              <div className={styles.bhHead}>
                <span className={styles.bhLbl}>BACKUP HISTORY</span>
                <span className={styles.bhInfoPill}>Schedule: nightly 2:00 AM CT · Retention: 30d local + 90d S3</span>
              </div>
              <div className={styles.bhTable}>
                <div className={`${styles.bhRow} ${styles.bhRowHead}`}>
                  <div className={styles.bhCol1}>STARTED</div>
                  <div className={styles.bhCol2}>TYPE</div>
                  <div className={styles.bhCol3}>SIZE</div>
                  <div className={styles.bhCol4}>DURATION</div>
                  <div className={styles.bhCol5}>DEST</div>
                  <div className={styles.bhCol6}>STATUS</div>
                </div>
                {BACKUP_HISTORY.map((b, i) => {
                  const rowCls = i % 2 === 1
                    ? `${styles.bhRow} ${styles.bhRowAlt}`
                    : styles.bhRow;
                  const pillCls = pillClassFor(b.statTone);
                  return (
                    <div key={`${b.started}-${i}`} className={rowCls}>
                      <div className={`${styles.bhCol1} ${styles.bhMuted}`}>{b.started}</div>
                      <div className={`${styles.bhCol2} ${styles.bhStrong}`}>{b.type}</div>
                      <div className={styles.bhCol3}>{b.size}</div>
                      <div className={`${styles.bhCol4} ${styles.bhMuted}`}>{b.duration}</div>
                      <div className={`${styles.bhCol5} ${styles.bhMuted}`}>{b.location}</div>
                      <div className={styles.bhCol6}>
                        <span className={pillCls}>{b.statLabel}</span>
                      </div>
                    </div>
                  );
                })}
              </div>
            </div>

            <div className={styles.rightRail}>
              <div className={styles.sysInfo}>
                <div className={styles.panelLbl}>SYSTEM INFO</div>
                {SYS_INFO.map((r) => (
                  <div key={r.k} className={styles.sysRow}>
                    <span className={styles.sysK}>{r.k}</span>
                    <span className={styles.sysV}>{r.v}</span>
                  </div>
                ))}
              </div>

              <div className={styles.alerts}>
                <div className={styles.panelLbl}>OPEN ALERTS</div>
                {ALERTS.map((a, i) => {
                  const itemCls = a.tone === 'info'
                    ? `${styles.alertItem} ${styles.alertItemInfo}`
                    : `${styles.alertItem} ${styles.alertItemWarn}`;
                  const icCls = a.tone === 'info'
                    ? `${styles.alertIc} ${styles.alertIcInfo}`
                    : `${styles.alertIc} ${styles.alertIcWarn}`;
                  return (
                    <div key={`${a.title}-${i}`} className={itemCls}>
                      <span className={icCls} aria-hidden="true">
                        {a.tone === 'info' ? 'ℹ' : '⚠'}
                      </span>
                      <div className={styles.alertText}>
                        <div className={styles.alertTtl}>{a.title}</div>
                        <div className={styles.alertBody}>{a.body}</div>
                      </div>
                    </div>
                  );
                })}
              </div>
            </div>
          </section>

        </main>
      </div>
    </>
  );
}

type StatusCardProps = {
  readonly name: string;
  readonly tone: StatusTone;
  readonly pillLabel: string;
  readonly meta1: string;
  readonly meta2: string;
  readonly children: React.ReactNode;
};

function StatusCard(props: StatusCardProps): JSX.Element {
  const dotCls = `${styles.cardDot} ${dotClassFor(props.tone)}`;
  const pillCls = `${styles.cardPill} ${pillToneClassFor(props.tone)}`;
  return (
    <div className={styles.card}>
      <div className={styles.cardHead}>
        <span className={dotCls} />
        <span className={styles.cardName}>{props.name}</span>
        <span className={pillCls}>{props.pillLabel}</span>
      </div>
      <div className={styles.cardMeta1}>{props.meta1}</div>
      <div className={styles.cardMeta2}>{props.meta2}</div>
      {props.children}
    </div>
  );
}

type SparkProps = {
  readonly heights: readonly number[];
};

function Spark({ heights }: SparkProps): JSX.Element {
  return (
    <div className={styles.spark}>
      {heights.map((h, i) => (
        <span key={i} className={styles.sparkBar} style={{ height: `${h}px` }} />
      ))}
    </div>
  );
}

function dotClassFor(tone: StatusTone): string {
  switch (tone) {
    case 'good':   return styles['cardDotGood'] ?? '';
    case 'warn':   return styles['cardDotWarn'] ?? '';
    case 'danger': return styles['cardDotDanger'] ?? '';
  }
}

function pillToneClassFor(tone: StatusTone): string {
  switch (tone) {
    case 'good':   return styles['cardPillGood'] ?? '';
    case 'warn':   return styles['cardPillWarn'] ?? '';
    case 'danger': return styles['cardPillDanger'] ?? '';
  }
}

function pillClassFor(tone: BackupStatus): string {
  switch (tone) {
    case 'success': return `${styles.statusPill} ${styles['statusPillGood']   ?? ''}`;
    case 'warn':    return `${styles.statusPill} ${styles['statusPillWarn']   ?? ''}`;
    case 'danger':  return `${styles.statusPill} ${styles['statusPillDanger'] ?? ''}`;
  }
}
