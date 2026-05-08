// ImmunizationRegistry — Figma "Screen 47 — Immunization Registry".
// Reports → Clinical → Immunizations sub-page. Population-level coverage
// report: KPI tiles per vaccine antigen, "patients due" outreach queue,
// TX-DSHS registry sync status, and a 12-month administrations bar chart.
//
// 1:1 port of the static mock formerly at
// /interface/patient_file/history/copilot_immunization_registry.php — all
// values are hardcoded demo data matching Figma exactly so the visual
// output is frozen against the reference screenshot at
// frontend/.fidelity-references/immunization_registry-figma-2026-05-07.png.
//
// The navy top nav and patient demographics banner are intentionally not
// rendered (per migration brief — this is a practice-wide registry view,
// not a patient-scoped one).
//
// Tabs and the "Force re-sync" button are static — no router/handlers,
// matching the demo posture of the other migrated Reports pages.

import { useState } from 'react';
import type { BootContext } from '../../shared/lib/bootContext';
import styles from './ImmunizationRegistry.module.css';

type Tone = 'good' | 'warn' | 'info';
type AvColor = 'orange' | 'blue' | 'purple' | 'teal' | 'pink' | 'green' | 'mint' | 'violet';
type PillTone = 'danger' | 'warn' | 'neutral';
type BarTone = 'teal' | 'orange';
type TabKey = 'all' | 'flu' | 'covid' | 'tdap' | 'shingrix';

type CoverageTile = {
  readonly name: string;
  readonly sub: string;
  readonly goalLabel: string;
  readonly pct: number;
  readonly tone: Tone;
  readonly denom: string;
};

type DueRow = {
  readonly av: AvColor;
  readonly initials: string;
  readonly name: string;
  readonly mrn: string;
  readonly dem: string;
  readonly vac: string;
  readonly pill: string;
  readonly pillTone: PillTone;
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

// Coverage tile values verified against Figma node 96:2 on 2026-05-07.
// Order is row-major (3×2): Influenza, COVID-19 booster, Pneumococcal /
// Tdap, Shingrix, HPV.
const COVERAGE: readonly CoverageTile[] = [
  { name: 'Influenza',        sub: '2025-26',        goalLabel: 'Goal ≥65%', pct: 62, tone: 'warn', denom: '1147 of 1847 · Season ends 04/30' },
  { name: 'COVID-19 booster', sub: '2025-26',        goalLabel: 'Tracked',   pct: 41, tone: 'info', denom: '757 of 1847 · Updated formula' },
  { name: 'Pneumococcal',     sub: 'PPSV23 + PCV',   goalLabel: 'Goal ≥75%', pct: 78, tone: 'good', denom: '512 of 656 · Adults 65+' },
  { name: 'Tdap',             sub: '10-yr booster',  goalLabel: 'Goal ≥80%', pct: 83, tone: 'good', denom: '1532 of 1647 · All adults' },
  { name: 'Shingrix',         sub: 'Adults 50+',     goalLabel: 'Goal ≥60%', pct: 58, tone: 'warn', denom: '412 of 710 · 2-dose series' },
  { name: 'HPV',              sub: 'Series complete', goalLabel: 'Goal ≥70%', pct: 71, tone: 'good', denom: '62 of 116 · Adolescents' },
];

// Patients-due rows verified against Figma node 96:2 on 2026-05-07.
const DUE_ROWS: readonly DueRow[] = [
  { av: 'orange', initials: 'MC', name: 'Margaret Chen', mrn: '#004821', dem: '68F', vac: 'Flu (2025-26)',     pill: 'OVERDUE 6 mo', pillTone: 'danger' },
  { av: 'blue',   initials: 'TS', name: 'Ted Shaw',      mrn: '#000001', dem: '61M', vac: 'Flu, Shingrix',     pill: 'Flu OVERDUE',  pillTone: 'danger' },
  { av: 'purple', initials: 'LM', name: 'Linda Martinez', mrn: '#003918', dem: '78F', vac: 'PPSV23 booster',    pill: 'Due 05/14',    pillTone: 'warn' },
  { av: 'teal',   initials: 'DK', name: 'David Kim',     mrn: '#006102', dem: '44M', vac: 'Tdap (10-yr)',      pill: 'Due 06/22',    pillTone: 'warn' },
  { av: 'pink',   initials: 'AP', name: 'Allison Park',  mrn: '#002745', dem: '52F', vac: 'Shingrix dose 2',   pill: 'Due 05/03',    pillTone: 'warn' },
  { av: 'green',  initials: 'CM', name: 'Carlos Mendez', mrn: '#004102', dem: '70M', vac: 'Flu, COVID booster', pill: 'Both due',     pillTone: 'neutral' },
  { av: 'mint',   initials: 'EF', name: 'Emily Foster',  mrn: '#005544', dem: '67F', vac: 'PPSV23, Shingrix',  pill: 'Both due',     pillTone: 'neutral' },
  { av: 'violet', initials: 'JB', name: 'James Brown',   mrn: '#002188', dem: '65M', vac: 'PCV13',             pill: 'Due 05/22',    pillTone: 'warn' },
  { av: 'orange', initials: 'HG', name: 'Helen Garcia',  mrn: '#003021', dem: '71F', vac: 'PCV13, Shingrix d2', pill: 'Both due',     pillTone: 'neutral' },
];

// Tab counts mirror the static demo (Figma shows All=9 implicitly via
// the row count; per-vaccine counts are illustrative and match what the
// PHP precursor's intersection logic would yield against this demo data).
const TAB_COUNTS: Readonly<Record<TabKey, number>> = {
  all: 9,
  flu: 3,
  covid: 1,
  tdap: 1,
  shingrix: 4,
};

const TABS: readonly { readonly key: TabKey; readonly label: string }[] = [
  { key: 'all',      label: 'All' },
  { key: 'flu',      label: 'Flu' },
  { key: 'covid',    label: 'COVID' },
  { key: 'tdap',     label: 'Tdap' },
  { key: 'shingrix', label: 'Shingrix' },
];

// Monthly administrations chart — last 12 months ending April. Counts and
// colors verified against Figma node 96:2 on 2026-05-07. The five middle
// labels (Sep–Jan) show numbers above the bars; the orange columns mark
// the Oct–Dec flu peak (428 admins).
type MonthBar = {
  readonly label: string;
  readonly count: number;
  readonly showNum: boolean;
  readonly tone: BarTone;
};
const MONTHS: readonly MonthBar[] = [
  { label: 'M', count: 12,  showNum: false, tone: 'teal' },
  { label: 'J', count: 14,  showNum: false, tone: 'teal' },
  { label: 'J', count: 18,  showNum: false, tone: 'teal' },
  { label: 'A', count: 26,  showNum: false, tone: 'teal' },
  { label: 'S', count: 52,  showNum: true,  tone: 'teal' },
  { label: 'O', count: 142, showNum: true,  tone: 'orange' },
  { label: 'N', count: 188, showNum: true,  tone: 'orange' },
  { label: 'D', count: 98,  showNum: true,  tone: 'orange' },
  { label: 'J', count: 56,  showNum: true,  tone: 'teal' },
  { label: 'F', count: 22,  showNum: false, tone: 'teal' },
  { label: 'M', count: 16,  showNum: false, tone: 'teal' },
  { label: 'A', count: 14,  showNum: false, tone: 'teal' },
];
const BAR_MAX = 188;
const BAR_MAX_PX = 220;

type ImmunizationRegistryProps = {
  readonly boot: BootContext;
};

export function ImmunizationRegistry(_props: ImmunizationRegistryProps): JSX.Element {
  const [activeTab, setActiveTab] = useState<TabKey>('all');

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
            <span className={styles.metaLight}>Coverage rates and outreach queue · Q1 2026</span>
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
            {COVERAGE.map((c) => {
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
                      <span className={styles.tabCt}>{TAB_COUNTS[t.key]}</span>
                    </button>
                  );
                })}
              </div>
              <div>
                {DUE_ROWS.map((row, i) => {
                  const rowCls = i % 2 === 1 ? `${styles.dueRow} ${styles.dueRowAlt}` : styles.dueRow;
                  const avCls  = `${styles.av} ${avClass(styles, row.av)}`;
                  const pillCls = `${styles.pill} ${pillToneClass(styles, row.pillTone)}`;
                  return (
                    <div key={row.mrn} className={rowCls}>
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
                })}
              </div>
            </section>

            <div className={styles.right}>
              <div className={`${styles.panelCard} ${styles.syncCard}`}>
                <div className={styles.plbl}>TX-DSHS REGISTRY SYNC</div>
                <div className={styles.syncRow}>
                  <span className={styles.syncDot} />
                  <span>Connected · last sync 14 min ago</span>
                </div>
                <div className={styles.syncMeta}>
                  1,124 records pushed this quarter
                  <br />
                  2 pending · 0 errors
                </div>
                <button type="button" className={styles.forceBtn}>Force re-sync now</button>
              </div>

              <div className={`${styles.panelCard} ${styles.monthCard}`}>
                <div className={styles.plbl}>MONTHLY ADMINISTRATIONS</div>
                <div className={styles.sub2}>Last 12 months</div>
                <div className={styles.chart}>
                  <div className={styles.bars}>
                    {MONTHS.map((m, idx) => {
                      const h = m.count > 0 ? Math.max(6, Math.round((m.count / BAR_MAX) * BAR_MAX_PX)) : 0;
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
                    {MONTHS.map((m, idx) => (
                      <span key={idx} className={styles.axisLab}>{m.label}</span>
                    ))}
                  </div>
                  <div className={styles.caption}>
                    Flu season peak Oct–Dec (428 admins)
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
