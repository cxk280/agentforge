// ImmunizationRegistry — Figma "Screen 47 — Immunization Registry".
// Reports → Clinical → Immunizations sub-page. Population-level coverage
// report: KPI tiles per vaccine antigen, "patients due" outreach queue,
// TX-DSHS registry sync status, and a 12-month administrations bar chart.
//
// Originally a 1:1 visual port of the static mock at
// /interface/patient_file/history/copilot_immunization_registry.php (.bak),
// this component is now DB-backed: the PHP wrapper computes coverage tiles,
// the patients-due queue, monthly admins counts, and sync card metrics from
// the live `immunizations` + `patient_data` + `extended_log` tables and
// JSON-encodes the result onto data-imm. The component renders whatever
// the wrapper sends. Visual layout is unchanged from the original mock.
//
// Tabs are client-side filters over the same prebuilt due-list (per-tab
// counts come from the PHP wrapper). The "Force re-sync" button stays
// static for now — same posture as the other migrated Reports pages.

import { useMemo, useState } from 'react';
import type { BootContext } from '../../shared/lib/bootContext';
import styles from './ImmunizationRegistry.module.css';

type Tone = 'good' | 'warn' | 'info';
type AvColor = 'orange' | 'blue' | 'purple' | 'teal' | 'pink' | 'green' | 'mint' | 'violet';
type PillTone = 'danger' | 'warn' | 'neutral';
type BarTone = 'teal' | 'orange';
type TabKey = 'all' | 'flu' | 'covid' | 'tdap' | 'shingrix';

export type CoverageTile = {
  readonly name: string;
  readonly sub: string;
  readonly goalLabel: string;
  readonly pct: number;
  readonly tone: Tone;
  readonly denom: string;
};

export type DueRow = {
  readonly av: AvColor;
  readonly initials: string;
  readonly name: string;
  readonly mrn: string;
  readonly dem: string;
  readonly vac: string;
  readonly pill: string;
  readonly pillTone: PillTone;
  readonly pid?: number | undefined;
};

export type MonthBar = {
  readonly label: string;
  readonly count: number;
  readonly showNum: boolean;
  readonly tone: BarTone;
};

export type SyncInfo = {
  readonly lastSyncLabel: string;
  readonly pushedQuarter: number;
  readonly pending: number;
  readonly errors: number;
};

export type ImmunizationRegistryPayload = {
  readonly coverage: readonly CoverageTile[];
  readonly dueRows: readonly DueRow[];
  readonly tabCounts: Readonly<Record<TabKey, number>>;
  readonly months: readonly MonthBar[];
  readonly sync: SyncInfo;
  readonly peakTotal: number;
  readonly totalAdmins: number;
};

type SideItem = { readonly label: string; readonly active?: boolean | undefined };
type SideGroup = { readonly heading: string; readonly items: readonly SideItem[] };

const SIDE_GROUPS: readonly SideGroup[] = [
  {
    heading: 'Clinical',
    items: [
      { label: 'Patient List' },
      { label: 'Prescriptions' },
      { label: 'Lab Trends' },
      { label: 'Quality Measures' },
      { label: 'Immunizations', active: true },
      { label: 'Encounters' },
    ],
  },
  {
    heading: 'Financial',
    items: [
      { label: 'Daily Cash' },
      { label: 'Aging' },
      { label: 'Payer Mix' },
      { label: 'Collections' },
    ],
  },
  {
    heading: 'Operations',
    items: [
      { label: 'Visit Volume' },
      { label: 'Provider Productivity' },
      { label: 'No-shows' },
    ],
  },
  {
    heading: 'Electronic',
    items: [
      { label: 'Submissions' },
      { label: 'CCDA Exports' },
      { label: 'HIE Sync' },
      { label: 'Public Health' },
    ],
  },
];

const TABS: readonly { readonly key: TabKey; readonly label: string }[] = [
  { key: 'all',      label: 'All' },
  { key: 'flu',      label: 'Flu' },
  { key: 'covid',    label: 'COVID' },
  { key: 'tdap',     label: 'Tdap' },
  { key: 'shingrix', label: 'Shingrix' },
];

const BAR_MAX_PX = 220;

// Map a tab key onto the substring(s) we expect to find inside the
// "vaccines due" cell so client-side tab switching reuses the prebuilt
// list rather than re-fetching from PHP. Mirrors $tabVaccines in the
// PHP wrapper. The 'all' tab matches every row.
const TAB_MATCHERS: Readonly<Record<Exclude<TabKey, 'all'>, readonly string[]>> = {
  flu:      ['flu', 'influenza'],
  covid:    ['covid'],
  tdap:     ['tdap'],
  shingrix: ['shingrix'],
};

type ImmunizationRegistryProps = {
  readonly boot: BootContext;
  readonly payload: ImmunizationRegistryPayload;
};

export function ImmunizationRegistry({ payload }: ImmunizationRegistryProps): JSX.Element {
  const [activeTab, setActiveTab] = useState<TabKey>('all');

  const visibleDueRows = useMemo(() => {
    if (activeTab === 'all') {
      return payload.dueRows;
    }
    const needles = TAB_MATCHERS[activeTab];
    return payload.dueRows.filter((row) => {
      const hay = row.vac.toLowerCase();
      return needles.some((n) => hay.includes(n));
    });
  }, [payload.dueRows, activeTab]);

  const barMax = useMemo(() => {
    let max = 0;
    for (const m of payload.months) {
      if (m.count > max) {
        max = m.count;
      }
    }
    return max < 1 ? 1 : max;
  }, [payload.months]);

  return (
    <div className={styles.shell}>
      <aside className={styles.side}>
        <div className={styles.sideHeader}>REPORTS</div>
        {SIDE_GROUPS.map((group) => (
          <div key={group.heading} className={styles.sideGroup}>
            <div className={styles.sideGroupLabel}>{group.heading}</div>
            {group.items.map((item) => {
              const cls = item.active
                ? `${styles.sideItem} ${styles.sideItemActive}`
                : styles.sideItem;
              return (
                <a key={item.label} className={cls} href="#" onClick={(e) => e.preventDefault()}>
                  {item.label}
                </a>
              );
            })}
          </div>
        ))}
      </aside>

      <div className={styles.main}>
        <header className={styles.pagehead}>
          <div className={styles.titleRow}>
            <span className={styles.title}>Immunization Registry</span>
            <span className={styles.dot}>·</span>
            <span className={styles.metaLight}>
              Coverage rates and outreach queue · {payload.totalAdmins} admins / 12 mo
            </span>
          </div>
          <div className={styles.headSpacer} />
          <button type="button" className={styles.btnGhost}>
            <span aria-hidden="true">⟳</span> Sync to TX-DSHS
          </button>
          <button type="button" className={styles.btnPrimary}>
            + Record vaccination
          </button>
        </header>

        <main className={styles.content}>
          <section className={styles.covGrid}>
            {payload.coverage.map((c) => {
              const pillCls = `${styles.gpill} ${toneClass(styles, c.tone, 'gpill')}`;
              const pctCls  = `${styles.pct}   ${toneClass(styles, c.tone, 'pct')}`;
              const fillCls = `${styles.barFill} ${toneClass(styles, c.tone, 'fill')}`;
              return (
                <div key={c.name} className={styles.covCard}>
                  <div className={styles.covName}>{c.name}</div>
                  <div className={styles.covSub}>{c.sub}</div>
                  <span className={pillCls}>{c.goalLabel}</span>
                  <div className={pctCls}>{c.pct}%</div>
                  <div className={styles.covDenom}>{c.denom}</div>
                  <div className={styles.barBg} />
                  <div className={fillCls} style={{ width: `calc((100% - 30px) * ${c.pct / 100})` }} />
                </div>
              );
            })}
          </section>

          <div className={styles.cols}>
            <section className={styles.duePanel}>
              <div className={styles.dueLbl}>PATIENTS DUE FOR VACCINATION</div>
              <div className={styles.dueTabs} role="tablist">
                {TABS.map((t) => {
                  const active = t.key === activeTab;
                  const cls = active ? `${styles.tab} ${styles.tabActive}` : styles.tab;
                  const ct = payload.tabCounts[t.key] ?? 0;
                  return (
                    <button
                      key={t.key}
                      role="tab"
                      type="button"
                      aria-selected={active}
                      className={cls}
                      onClick={() => setActiveTab(t.key)}
                    >
                      {t.label}
                      <span className={styles.tabCt}>{ct}</span>
                    </button>
                  );
                })}
              </div>
              <div>
                {visibleDueRows.length === 0 ? (
                  <div className={styles.dueEmpty}>
                    No patients currently due for this tab.
                  </div>
                ) : (
                  visibleDueRows.map((row, i) => {
                    const rowCls = i % 2 === 1 ? `${styles.dueRow} ${styles.dueRowAlt}` : styles.dueRow;
                    const avCls  = `${styles.av} ${avClass(styles, row.av)}`;
                    const pillCls = `${styles.pill} ${pillToneClass(styles, row.pillTone)}`;
                    const key = `${row.mrn}-${row.name}-${i}`;
                    return (
                      <div key={key} className={rowCls}>
                        <span className={avCls}>{row.initials}</span>
                        <span className={styles.dueName}>{row.name}</span>
                        <span className={styles.dueMrn}>{row.mrn}</span>
                        <span className={styles.dueDem}>{row.dem}</span>
                        <span className={styles.dueVac}>{row.vac}</span>
                        <span className={pillCls}>{row.pill}</span>
                        <a
                          href="#"
                          className={styles.sched}
                          onClick={(e) => e.preventDefault()}
                        >
                          Schedule →
                        </a>
                      </div>
                    );
                  })
                )}
              </div>
            </section>

            <div className={styles.right}>
              <div className={`${styles.panelCard} ${styles.syncCard}`}>
                <div className={styles.plbl}>TX-DSHS REGISTRY SYNC</div>
                <div className={styles.syncRow}>
                  <span className={styles.syncDot} />
                  <span>Connected · last sync {payload.sync.lastSyncLabel}</span>
                </div>
                <div className={styles.syncMeta}>
                  {payload.sync.pushedQuarter.toLocaleString()} records pushed this quarter
                  <br />
                  {payload.sync.pending} pending · {payload.sync.errors} errors
                </div>
                <button type="button" className={styles.forceBtn}>Force re-sync now</button>
              </div>

              <div className={`${styles.panelCard} ${styles.monthCard}`}>
                <div className={styles.plbl}>MONTHLY ADMINISTRATIONS</div>
                <div className={styles.sub2}>Last 12 months</div>
                <div className={styles.chart}>
                  <div className={styles.bars}>
                    {payload.months.map((m, idx) => {
                      const h = m.count > 0 ? Math.max(6, Math.round((m.count / barMax) * BAR_MAX_PX)) : 0;
                      const barCls = `${styles.bar} ${m.tone === 'orange' ? styles.barOrange : styles.barTeal}`;
                      return (
                        <div key={idx} className={styles.col}>
                          <span className={styles.num}>{m.showNum ? String(m.count) : ''}</span>
                          <div className={barCls} style={{ height: `${h}px` }} />
                        </div>
                      );
                    })}
                  </div>
                  <div className={styles.axis}>
                    {payload.months.map((m, idx) => (
                      <span key={idx} className={styles.axisLab}>{m.label}</span>
                    ))}
                  </div>
                  <div className={styles.caption}>
                    Flu season peak Oct–Dec ({payload.peakTotal} admins)
                  </div>
                </div>
              </div>
            </div>
          </div>
        </main>
      </div>
    </div>
  );
}

// CSS-Module class lookup helpers — pulling these out so the JSX above
// doesn't grow a forest of ternaries. With noUncheckedIndexedAccess on,
// bracket access into the styles map is `string | undefined`; coerce
// with `?? ''` so we never concatenate "undefined" into a className.

function toneClass(s: Readonly<Record<string, string | undefined>>, tone: Tone, kind: 'gpill' | 'pct' | 'fill'): string {
  const key = kind + capitalize(tone);
  return s[key] ?? '';
}

function avClass(s: Readonly<Record<string, string | undefined>>, color: AvColor): string {
  return s['av' + capitalize(color)] ?? '';
}

function pillToneClass(s: Readonly<Record<string, string | undefined>>, tone: PillTone): string {
  return s['pill' + capitalize(tone)] ?? '';
}

function capitalize(s: string): string {
  return s.charAt(0).toUpperCase() + s.slice(1);
}
