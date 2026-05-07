// ProviderPortal — Figma "Screen 51 — Portal Dashboard" (node 100:2).
//
// Static demo from the Figma mock — Patient Portal Activity dashboard with KPI
// strip, recent activity feed (left), pending tasks + adoption (right). The
// navy top nav from the Figma is intentionally skipped per the migration spec
// — it is owned by the outer PHP shell. The original PHP-rendered version was
// at /portal/copilot_provider_portal.php; this React page replaces it via the
// same path with a manifest-loading wrapper.
//
// This is intentionally a no-DB hardcoded mock to match the Figma byte-for-
// byte (KPI numbers, adoption percentages, named patients, "All / Mine"
// toggle). State for the toggle is held locally — there is no API wiring.
//
// noUncheckedIndexedAccess + exactOptionalPropertyTypes: tuple/array indexing
// returns undefined, so we coerce with `?? ''` where strings are required.

import { useState } from 'react';
import type { BootContext } from '../../shared/lib/bootContext';
import styles from './ProviderPortal.module.css';

type Scope = 'all' | 'mine';

type Kpi = {
  readonly label: string;
  readonly value: string;
  readonly sub: string;
  readonly color: string;
};

type FeedItem = {
  readonly icon: string;
  readonly title: string;
  readonly desc: string;
  readonly when: string;
};

type Task = {
  readonly icon: string;
  readonly iconColor: string;
  readonly title: string;
  readonly desc: string;
};

type AdoptionRow = {
  readonly label: string;
  readonly pct: number;
  readonly color: string;
};

// ── KPI strip — values from Figma node 100:29 ─────────────────────────
const KPIS: readonly Kpi[] = [
  { label: 'Active accounts',   value: '2,484', sub: '71% of pts',     color: '#181D26' },
  { label: 'Logins today',      value: '328',   sub: '+12% vs avg',    color: '#33A666' },
  { label: 'Unread messages',   value: '24',    sub: 'avg 6h to reply', color: '#FA8C33' },
  { label: 'Form submissions',  value: '41',    sub: 'today',          color: '#4785D9' },
  { label: 'Pending approvals', value: '7',     sub: 'access requests', color: '#FA8C33' },
];

// ── Recent portal activity feed — 12 rows from Figma node 100:55 ──────
const FEED: readonly FeedItem[] = [
  { icon: '\u{1F4AC}', title: 'Margaret Chen sent a message',         desc: 'RE: question about new prescription', when: '2 min ago'  },
  { icon: '\u{1F4CB}', title: 'Ted Shaw completed pre-visit form',    desc: 'Diabetes intake — 14 questions answered', when: '12 min ago' },
  { icon: '\u{1F4C5}', title: 'Linda Martinez requested appointment', desc: 'Acute — knee pain, prefers next 3 days', when: '25 min ago' },
  { icon: '\u{1F4CB}', title: 'David Kim viewed lab results',          desc: 'HbA1c 6.4% (within range)', when: '38 min ago' },
  { icon: '\u{1F510}', title: 'Allison Park changed password',         desc: 'Routine update',           when: '1h ago'  },
  { icon: '\u{1F4B5}', title: 'Carlos Mendez paid $40 copay',          desc: 'Visit 04/29 · via Stripe', when: '1h ago'  },
  { icon: '\u{1F4AC}', title: 'Emily Foster sent a message',           desc: 'Refill request — Synthroid', when: '2h ago'  },
  { icon: '\u{1F4CB}', title: 'James Brown declined CGM consent form', desc: 'Will discuss at next visit', when: '3h ago'  },
  { icon: '\u{1F195}', title: 'Helen Garcia activated account',        desc: 'First login from email invitation', when: '4h ago'  },
  { icon: '\u{1F4C5}', title: 'Marcus Webb cancelled telehealth visit', desc: 'Was scheduled 05/02 14:00', when: '5h ago'  },
  { icon: '\u{1F4E4}', title: 'Mike Tan downloaded care plan',          desc: 'PDF · 4 pages',       when: '6h ago'  },
  { icon: '\u{1F4AC}', title: 'Robert Hayes sent a message',            desc: 'Question about side effect', when: '7h ago'  },
];

// ── Pending portal tasks — 4 rows from Figma node 100:148 ─────────────
const TASKS: readonly Task[] = [
  { icon: '\u{1F510}', iconColor: '#FA8C33', title: 'Approve 7 access requests',     desc: 'Patients linking proxy/spouse access' },
  { icon: '\u{1F4AC}', iconColor: '#4785D9', title: 'Reply to 24 unread messages',   desc: 'Avg response time 6h, target 4h'      },
  { icon: '\u{1F4CB}', iconColor: '#FA8C33', title: 'Review 3 declined consents',    desc: 'Patients said no to data-sharing'     },
  { icon: '\u{1F4E4}', iconColor: '#4785D9', title: 'Sign 4 portal-shared documents', desc: 'Margaret Chen, Ted Shaw, +2'         },
];

// ── Portal adoption rows — Figma node 100:174 ─────────────────────────
const ADOPTION: readonly AdoptionRow[] = [
  { label: 'Adult 18-39',      pct: 89, color: '#33A666' },
  { label: 'Adult 40-64',      pct: 78, color: '#33A666' },
  { label: 'Adult 65-74',      pct: 62, color: '#FA8C33' },
  { label: 'Adult 75+',        pct: 38, color: '#D93838' },
  { label: 'Pediatric (proxy)', pct: 94, color: '#33A666' },
];

const ADOPTION_HEADLINE = '71% of active pts have portal accounts';

type ProviderPortalProps = {
  readonly boot: BootContext;
};

export function ProviderPortal(_props: ProviderPortalProps): JSX.Element {
  const [scope, setScope] = useState<Scope>('all');

  return (
    <>
      <header className={styles.pageHead}>
        <div className={styles.titleRow}>
          <span className={styles.title}>Patient Portal Activity</span>
          <span className={styles.bullet}>&bull;</span>
          <span className={styles.meta}>Provider view &middot; all patient self-service actions</span>
        </div>
        <div className={styles.spacer} />
        <button type="button" className={styles.btn}>
          <span aria-hidden="true">&#9881;</span>
          <span>Portal settings</span>
        </button>
        <button type="button" className={`${styles.btn} ${styles.btnPrimary}`}>
          <span>+ Send portal invitation</span>
        </button>
        <button type="button" className={styles.helpPill} disabled>
          ? Help
        </button>
      </header>

      <main className={styles.body}>
        <div className={styles.kpi}>
          {KPIS.map((k) => (
            <div key={k.label} className={styles.kpiCell}>
              <span className={styles.kpiLabel}>{k.label}</span>
              <div className={styles.kpiRow}>
                <span className={styles.kpiVal} style={{ color: k.color }}>{k.value}</span>
                <span className={styles.kpiSub}>{k.sub}</span>
              </div>
            </div>
          ))}
        </div>

        <div className={styles.cols}>
          {/* LEFT: Recent portal activity feed */}
          <section className={styles.panel}>
            <div className={styles.panelHead}>
              <span className={styles.panelLabel}>RECENT PORTAL ACTIVITY</span>
              <div className={styles.toggle} role="tablist">
                <ScopeToggle current={scope} mode="all"  onSelect={setScope}>All</ScopeToggle>
                <ScopeToggle current={scope} mode="mine" onSelect={setScope}>Mine</ScopeToggle>
              </div>
            </div>
            <div className={styles.feed}>
              {FEED.map((row, i) => (
                <div key={i} className={styles.feedItem}>
                  <span className={styles.feedIcon}>{row.icon}</span>
                  <div className={styles.feedBody}>
                    <span className={styles.feedTitle}>{row.title}</span>
                    <span className={styles.feedDesc}>{row.desc}</span>
                  </div>
                  <div className={styles.feedRight}>
                    <span className={styles.feedWhen}>{row.when}</span>
                    <button type="button" className={styles.feedView}>View &rarr;</button>
                  </div>
                </div>
              ))}
            </div>
          </section>

          {/* RIGHT: stacked panels */}
          <div style={{ display: 'flex', flexDirection: 'column', gap: 16 }}>
            <section className={styles.panel}>
              <div className={styles.panelHead}>
                <span className={styles.panelLabel}>PENDING PORTAL TASKS</span>
              </div>
              <div className={styles.tasks}>
                {TASKS.map((t) => (
                  <div key={t.title} className={styles.task}>
                    <span className={styles.taskIcon} style={{ color: t.iconColor }}>{t.icon}</span>
                    <div className={styles.taskInfo}>
                      <span className={styles.taskTitle}>{t.title}</span>
                      <span className={styles.taskDesc}>{t.desc}</span>
                    </div>
                    <button type="button" className={styles.taskOpen}>Open &rarr;</button>
                  </div>
                ))}
              </div>
            </section>

            <section className={styles.panel}>
              <div className={`${styles.panelHead} ${styles.panelHeadStacked}`}>
                <span className={styles.panelLabel}>PORTAL ADOPTION</span>
                <span className={styles.panelSub}>{ADOPTION_HEADLINE}</span>
              </div>
              <div className={styles.adopt}>
                {ADOPTION.map((row) => (
                  <div key={row.label} className={styles.adoptRow}>
                    <div className={styles.adoptTop}>
                      <span className={styles.adoptLabel}>{row.label}</span>
                      <span className={styles.adoptPct} style={{ color: row.color }}>{row.pct}%</span>
                    </div>
                    <div className={styles.adoptBar}>
                      <div
                        className={styles.adoptFill}
                        style={{ width: `${row.pct}%`, background: row.color }}
                      />
                    </div>
                  </div>
                ))}
              </div>
            </section>
          </div>
        </div>
      </main>
    </>
  );
}

type ScopeToggleProps = {
  readonly mode: Scope;
  readonly current: Scope;
  readonly onSelect: (mode: Scope) => void;
  readonly children: React.ReactNode;
};

function ScopeToggle({ mode, current, onSelect, children }: ScopeToggleProps): JSX.Element {
  const active = mode === current;
  const cls = active ? `${styles.toggleItem} ${styles.toggleItemActive}` : styles.toggleItem;
  return (
    <button
      type="button"
      role="tab"
      aria-selected={active}
      className={cls}
      onClick={() => onSelect(mode)}
    >
      {children}
    </button>
  );
}
