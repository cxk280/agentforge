// ModuleInstaller — Figma "Screen 57 — Module Installer". Renders the
// Admin → Modules sub-page: a left admin rail (Users & Access / Practice /
// Clinical / System with "Modules" highlighted under System), a page head
// with "Modules · Manage installed modules and integrations · 18 enabled,
// 4 updates available" plus Upload + Apply updates buttons, a tab bar
// (Installed / Updates / Marketplace / Custom uploads), a filter bar
// (search + 6 category pills), and a 3-column grid of module cards.
//
// This is a 1:1 port of the PHP-rendered mock previously at
// /interface/super/copilot_module_installer.php. Data is hardcoded demo
// content matching the Figma — 12 modules across Clinical / Billing /
// Integration, three with "Update available" pills, two disabled.
// Future work would source it from the /apis/copilot/admin/modules
// endpoint and wire toggle / apply-updates flows.
//
// The navy top nav is intentionally NOT rendered here; the PHP outer
// shell at /interface/main/tabs/main.php still owns it via the #maimain
// iframe.

import { useState } from 'react';
import type { BootContext } from '../../shared/lib/bootContext';
import styles from './ModuleInstaller.module.css';

type SidebarItem = {
  readonly label: string;
  readonly active: boolean;
};

type SidebarGroup = {
  readonly label: string;
  readonly items: readonly SidebarItem[];
};

type TabKey = 'installed' | 'updates' | 'marketplace' | 'custom';

type Tab = {
  readonly key: TabKey;
  readonly label: string;
  readonly count: number | null;
};

type CategoryKey = 'all' | 'clinical' | 'billing' | 'integrations' | 'ui' | 'custom';

type Category = {
  readonly key: CategoryKey;
  readonly label: string;
  readonly count: number;
};

type Module = {
  readonly id: string;
  readonly icon: string;
  readonly iconBg: string;
  readonly name: string;
  readonly version: string;
  readonly category: string;
  readonly description: string;
  readonly active: boolean;
  readonly hasUpdate: boolean;
};

export type ModuleInstallerPayload = {
  readonly modules: readonly Module[];
  readonly installedCount: number;
};

const ADMIN_NAV: readonly SidebarGroup[] = [
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
      { label: 'Backup',    active: false },
      { label: 'Globals',   active: false },
      { label: 'Database',  active: false },
      { label: 'Modules',   active: true  },
    ],
  },
];

const TABS: readonly Tab[] = [
  { key: 'installed',   label: 'Installed',      count: 18   },
  { key: 'updates',     label: 'Updates',        count: 4    },
  { key: 'marketplace', label: 'Marketplace',    count: null },
  { key: 'custom',      label: 'Custom uploads', count: 3    },
];

const CATEGORIES: readonly Category[] = [
  { key: 'all',          label: 'All',          count: 18 },
  { key: 'clinical',     label: 'Clinical',     count: 6  },
  { key: 'billing',      label: 'Billing',      count: 3  },
  { key: 'integrations', label: 'Integrations', count: 5  },
  { key: 'ui',           label: 'UI',           count: 2  },
  { key: 'custom',       label: 'Custom',       count: 2  },
];

// 12 modules from the Figma grid (3 cols x 4 rows).
const MODULES: readonly Module[] = [
  {
    id: 'surescripts-erx',
    icon: '✨', // sparkle
    iconBg: '#E6F5F5',
    name: 'Surescripts e-Rx Bridge',
    version: 'v2.1.4',
    category: 'Integration',
    description: 'Connects e-Rx workflow to Surescripts hub. Required for EPCS.',
    active: true,
    hasUpdate: true,
  },
  {
    id: 'quest-hl7',
    icon: '\u{1F9EA}', // test tube
    iconBg: '#E6F5F5',
    name: 'Quest Diagnostics HL7',
    version: 'v1.8.2',
    category: 'Integration',
    description: 'Receives lab results via HL7 v2.5 ORU messages.',
    active: true,
    hasUpdate: false,
  },
  {
    id: 'commonwell-hie',
    icon: '\u{1F310}', // globe
    iconBg: '#E6F5F5',
    name: 'CommonWell HIE',
    version: 'v3.0.1',
    category: 'Integration',
    description: 'Federated record exchange across CommonWell members.',
    active: true,
    hasUpdate: false,
  },
  {
    id: 'cms-qpp',
    icon: '\u{1F4CA}', // bar chart
    iconBg: '#E6F5F5',
    name: 'CMS QPP / MIPS',
    version: 'v4.2.0',
    category: 'Clinical',
    description: 'Calculates and submits eCQM measures.',
    active: true,
    hasUpdate: true,
  },
  {
    id: 'patient-portal',
    icon: '\u{1F465}', // people
    iconBg: '#E6F5F5',
    name: 'Patient Portal',
    version: 'v5.4.0',
    category: 'Clinical',
    description: 'Patient-facing portal with messaging, results, payments.',
    active: true,
    hasUpdate: false,
  },
  {
    id: 'stripe',
    icon: '\u{1F4B3}', // credit card
    iconBg: '#E6F5F5',
    name: 'Stripe Payments',
    version: 'v2.0.3',
    category: 'Billing',
    description: 'Accept patient credit-card payments at checkout.',
    active: true,
    hasUpdate: true,
  },
  {
    id: 'clearinghouse-837',
    icon: '\u{1F4B5}', // dollar bill
    iconBg: '#E6F5F5',
    name: 'Clearinghouse 837',
    version: 'v3.5.1',
    category: 'Billing',
    description: 'Submits claims to clearinghouse via 837P/837I.',
    active: true,
    hasUpdate: false,
  },
  {
    id: 'dea-epcs',
    icon: '\u{1F510}', // lock
    iconBg: '#E6F5F5',
    name: 'DEA EPCS Module',
    version: 'v1.4.2',
    category: 'Clinical',
    description: 'Two-factor signing for controlled substances.',
    active: true,
    hasUpdate: false,
  },
  {
    id: 'tx-dshs',
    icon: '\u{1F489}', // syringe
    iconBg: '#E6F5F5',
    name: 'Texas DSHS Registry',
    version: 'v1.2.0',
    category: 'Integration',
    description: 'State immunization registry sync.',
    active: true,
    hasUpdate: true,
  },
  {
    id: 'sdoh',
    icon: '\u{1F3E0}', // house
    iconBg: '#E6F5F5',
    name: 'SDOH Screener (Z-codes)',
    version: 'v1.1.0',
    category: 'Clinical',
    description: 'Social determinants screening templates.',
    active: true,
    hasUpdate: false,
  },
  {
    id: 'zoom-telehealth',
    icon: '\u{1F4BB}', // laptop
    iconBg: '#E6F5F5',
    name: 'Telehealth (Zoom)',
    version: 'v2.3.0',
    category: 'Clinical',
    description: 'Video visits via Zoom Healthcare. Requires BAA.',
    active: false,
    hasUpdate: false,
  },
  {
    id: 'twilio-sms',
    icon: '\u{1F4F1}', // phone
    iconBg: '#E6F5F5',
    name: 'Twilio SMS Reminders',
    version: 'v1.0.5',
    category: 'Integration',
    description: 'Send appointment reminders via SMS.',
    active: false,
    hasUpdate: false,
  },
];

type ModuleInstallerProps = {
  readonly boot: BootContext;
  readonly payload: ModuleInstallerPayload;
};

export function ModuleInstaller({ payload }: ModuleInstallerProps): JSX.Element {
  // Live modules from the wrapper, demo otherwise. Only the Installed tab is
  // backed by real data — Updates / Marketplace / Custom uploads stay demo.
  const allModules: readonly Module[] = payload.modules.length > 0 ? payload.modules : MODULES;

  const [activeTab, setActiveTab] = useState<TabKey>('installed');
  const [activeCat, setActiveCat] = useState<CategoryKey>('all');
  const [search, setSearch] = useState<string>('');
  const [toggleState, setToggleState] = useState<ReadonlyMap<string, boolean>>(
    () => new Map(allModules.map((m) => [m.id, m.active])),
  );

  const toggle = (id: string): void => {
    setToggleState((prev) => {
      const next = new Map(prev);
      next.set(id, !(prev.get(id) ?? false));
      return next;
    });
  };

  return (
    <>
      <div className={styles.shell}>
        <aside className={styles.sidebar}>
          <div className={styles.sidebarHeader}>ADMIN</div>
          {ADMIN_NAV.map((group) => (
            <div key={group.label} className={styles.group}>
              <div className={styles.groupLabel}>{group.label}</div>
              {group.items.map((item) => {
                const cls = item.active
                  ? `${styles.navItem} ${styles.navItemActive}`
                  : styles.navItem;
                return (
                  <a key={item.label} className={cls} href="#" target="_self">
                    {item.label}
                  </a>
                );
              })}
            </div>
          ))}
        </aside>

        <div className={styles.main}>
          <header className={styles.pagehead}>
            <div className={styles.pageheadTitle}>Modules</div>
            <span className={styles.pageheadDot}>•</span>
            <div className={styles.pageheadMeta}>
              Manage installed modules and integrations · 18 enabled, 4 updates available
            </div>
            <span className={styles.pageheadSpacer} />
            <button type="button" className={styles.btnGhost}>
              <span className={styles.btnArrow}>⬆</span> Upload .zip module
            </button>
            <button type="button" className={styles.btnPrimary}>
              <span className={styles.btnArrow}>⬆</span> Apply 4 updates
            </button>
          </header>

          <nav className={styles.tabbar} role="tablist">
            {TABS.map((tab) => {
              const isActive = tab.key === activeTab;
              const cls = isActive
                ? `${styles.tab} ${styles.tabActive}`
                : styles.tab;
              const label = tab.count !== null
                ? `${tab.label} (${String(tab.count)})`
                : tab.label;
              return (
                <button
                  key={tab.key}
                  type="button"
                  role="tab"
                  aria-selected={isActive}
                  className={cls}
                  onClick={() => setActiveTab(tab.key)}
                >
                  {label}
                </button>
              );
            })}
          </nav>

          <div className={styles.filterbar}>
            <div className={styles.search}>
              <span className={styles.searchIcon}>{'\u{1F50D}'}</span>
              <input
                type="text"
                className={styles.searchInput}
                placeholder="Search modules"
                value={search}
                onChange={(e) => setSearch(e.target.value)}
              />
            </div>
            <div className={styles.pills}>
              {CATEGORIES.map((cat) => {
                const isActive = cat.key === activeCat;
                const pillCls = isActive
                  ? `${styles.pill} ${styles.pillActive}`
                  : styles.pill;
                const ctCls = isActive
                  ? `${styles.pillCt} ${styles.pillCtActive}`
                  : styles.pillCt;
                return (
                  <button
                    key={cat.key}
                    type="button"
                    className={pillCls}
                    onClick={() => setActiveCat(cat.key)}
                  >
                    <span>{cat.label}</span>
                    <span className={ctCls}>{cat.count}</span>
                  </button>
                );
              })}
            </div>
          </div>

          <div className={styles.body}>
            <div className={styles.grid}>
              {allModules.map((m) => {
                const isOn = toggleState.get(m.id) ?? m.active;
                const toggleCls = isOn
                  ? styles.toggle
                  : `${styles.toggle} ${styles.toggleOff}`;
                return (
                  <div key={m.id} className={styles.card}>
                    <div className={styles.cardHead}>
                      <span
                        className={styles.cardIcon}
                        style={{ backgroundColor: m.iconBg }}
                      >
                        {m.icon}
                      </span>
                      <div className={styles.cardMeta}>
                        <div className={styles.cardName}>{m.name}</div>
                        <div className={styles.cardSub}>
                          {m.version} · {m.category}
                        </div>
                      </div>
                    </div>
                    <div className={styles.cardDesc}>{m.description}</div>
                    <div className={styles.cardFoot}>
                      <div className={styles.statusPills}>
                        {isOn ? (
                          <span className={`${styles.statusPill} ${styles.statusActive}`}>
                            ✓ Active
                          </span>
                        ) : (
                          <span className={`${styles.statusPill} ${styles.statusDisabled}`}>
                            Disabled
                          </span>
                        )}
                        {m.hasUpdate && (
                          <span className={`${styles.statusPill} ${styles.statusUpdate}`}>
                            Update available
                          </span>
                        )}
                      </div>
                      <span className={styles.cardSpacer} />
                      <button type="button" className={styles.settingsBtn}>
                        Settings
                      </button>
                      <button
                        type="button"
                        className={toggleCls}
                        aria-pressed={isOn}
                        aria-label={`Toggle ${m.name}`}
                        onClick={() => toggle(m.id)}
                      />
                    </div>
                  </div>
                );
              })}
            </div>
          </div>
        </div>
      </div>
    </>
  );
}
