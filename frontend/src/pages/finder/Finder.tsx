// Finder — Figma "Screen 10 — Patient Finder". Renders the page header
// (title + spacer + search pill + New Patient button), filter chip row, and
// results table.
//
// This is a 1:1 port of the visual layer of the PHP-rendered finder
// previously at /interface/main/finder/copilot_finder.php. The original PHP
// was DB-backed (queries patient_data + joins for provider/insurance/visits);
// this React port uses hardcoded demo data matching the Figma mock so the
// shell can be migrated independently of the data layer. Wiring this back
// to /apis/copilot/patients (or an equivalent endpoint) is a follow-up.
//
// Search state, the active-filter set, and the highlighted row are held in
// React local state and behave identically to the static Figma frame:
// search box pre-populated with "Chen", "My panel" + "Active only" chips
// active, the first row (Margaret Chen) selected, "23 results" count.

import { useState } from 'react';
import type { BootContext } from '../../shared/lib/bootContext';
import styles from './Finder.module.css';

type FlagKind = 'warn' | 'cond' | 'rx';

type Flag = {
  readonly kind: FlagKind;
  readonly label: string;
};

type PatientRow = {
  readonly pid: number;
  readonly initials: string;
  readonly avatarColor: string;
  readonly name: string;
  readonly mrn: string;
  readonly dob: string;
  readonly provider: string;
  readonly insurance: string;
  readonly lastVisit: string;
  readonly today: boolean;
  readonly flags: readonly Flag[];
};

type FilterKey = 'my_panel' | 'all_providers' | 'active' | 'ins' | 'recent';

const COLUMNS: readonly string[] = [
  'NAME',
  'MRN',
  'DOB / AGE',
  'PROVIDER',
  'INSURANCE',
  'LAST VISIT',
  'FLAGS',
];

// Demo data lifted directly from the Figma frame (Screen 10 — Patient Finder).
const ROWS: readonly PatientRow[] = [
  {
    pid: 1,
    initials: 'MC',
    avatarColor: '#5FD0D0',
    name: 'Chen, Margaret',
    mrn: '#004821',
    dob: '03/14/1958 (68 yrs)',
    provider: 'Dr. E. Rivera',
    insurance: 'Blue Cross PPO',
    lastVisit: 'Today',
    today: true,
    flags: [
      { kind: 'warn', label: '⚠ Penicillin' },
      { kind: 'cond', label: '🩺 T2DM' },
    ],
  },
  {
    pid: 2,
    initials: 'RC',
    avatarColor: '#4785D9',
    name: 'Chen, Robert',
    mrn: '#005713',
    dob: '11/02/1971 (54 yrs)',
    provider: 'Dr. E. Rivera',
    insurance: 'Aetna PPO',
    lastVisit: 'Oct 18',
    today: false,
    flags: [
      { kind: 'cond', label: '🩺 HTN' },
    ],
  },
  {
    pid: 3,
    initials: 'LC',
    avatarColor: '#FA8C33',
    name: 'Chen, Lily',
    mrn: '#006904',
    dob: '08/22/1985 (40 yrs)',
    provider: 'Dr. A. Patel',
    insurance: 'United HMO',
    lastVisit: 'Sep 03',
    today: false,
    flags: [],
  },
  {
    pid: 4,
    initials: 'WC',
    avatarColor: '#8561C7',
    name: 'Chen, Wei',
    mrn: '#004112',
    dob: '01/30/1949 (76 yrs)',
    provider: 'Dr. E. Rivera',
    insurance: 'Medicare A+B',
    lastVisit: 'Aug 28',
    today: false,
    flags: [
      { kind: 'warn', label: '⚠ Sulfa' },
      { kind: 'cond', label: '🩺 CHF' },
    ],
  },
  {
    pid: 5,
    initials: 'JC',
    avatarColor: '#33A68C',
    name: 'Chen-Wong, Jasmine',
    mrn: '#007231',
    dob: '05/12/1992 (33 yrs)',
    provider: 'Dr. S. Chen',
    insurance: 'Cigna PPO',
    lastVisit: 'Jul 15',
    today: false,
    flags: [],
  },
  {
    pid: 6,
    initials: 'DC',
    avatarColor: '#D9668C',
    name: 'Chen, Daniel',
    mrn: '#005002',
    dob: '12/04/2003 (22 yrs)',
    provider: 'Dr. A. Patel',
    insurance: 'Self Pay',
    lastVisit: 'May 22',
    today: false,
    flags: [
      { kind: 'rx', label: '💊 Refill due' },
    ],
  },
];

// Display label for the "Insurance" chip — switches between "Any" and "Yes"
// depending on whether the chip is active, mirroring the PHP version.
function insuranceChipLabel(active: boolean): string {
  return active ? 'Insurance: Yes' : 'Insurance: Any';
}

// Default state matches the original PHP page (copilot_finder.php.bak):
// no prepopulated search, no preselected row, only "Active only" filter on.
// The Figma frame shows "Chen" + Margaret selected as a visual demo of the
// filtered state — that's design decoration, not initial state.
const RESULTS_COUNT = ROWS.length;
const DEFAULT_SEARCH = '';
const DEFAULT_SELECTED_PID: number | null = null;
const DEFAULT_FILTERS: ReadonlySet<FilterKey> = new Set<FilterKey>([
  'active',
]);

// Reach into the helpers the parent shell defines (interface/main/tabs/js/
// tabs_view_model.js). Same pattern the React Header uses to drive tab nav.
declare global {
  interface Window {
    navigateTab?: (url: string, name: string, afterLoad?: () => void) => void;
    activateTabByName?: (name: string, hideOthers?: boolean) => void;
    webroot_url?: string;
  }
}

function openDemographics(pid: number): void {
  const webroot = window.webroot_url ?? '';
  const url = `${webroot}/interface/patient_file/summary/demographics.php?set_pid=${pid}`;
  if (typeof window.navigateTab === 'function') {
    window.navigateTab(url, 'pat', () => {
      window.activateTabByName?.('pat', true);
    });
  } else {
    // Fallback: hard-navigate. Should not happen in the live shell.
    window.top !== null && window.top !== window
      ? (window.top.location.href = url)
      : (window.location.href = url);
  }
}

type FinderProps = {
  readonly boot: BootContext;
};

export function Finder(_props: FinderProps): JSX.Element {
  const [query, setQuery] = useState<string>(DEFAULT_SEARCH);
  const [filters, setFilters] = useState<ReadonlySet<FilterKey>>(DEFAULT_FILTERS);
  const [selectedPid, setSelectedPid] = useState<number | null>(DEFAULT_SELECTED_PID);

  const onRowClick = (pid: number): void => {
    setSelectedPid(pid);
    openDemographics(pid);
  };

  const toggleFilter = (key: FilterKey): void => {
    const next = new Set(filters);
    if (next.has(key)) {
      next.delete(key);
    } else {
      next.add(key);
    }
    setFilters(next);
  };

  const insActive = filters.has('ins');
  const chips: readonly { key: FilterKey; label: string }[] = [
    { key: 'my_panel',       label: 'My panel' },
    { key: 'all_providers',  label: 'All providers' },
    { key: 'active',         label: 'Active only' },
    { key: 'ins',            label: insuranceChipLabel(insActive) },
    { key: 'recent',         label: 'Last visit ≤ 12 mo' },
  ];

  return (
    <>
      <header className={styles.header}>
        <div className={styles.title}>Patient Finder</div>
        <div className={styles.spacer} />
        <label className={styles.search}>
          <span className={styles.searchIcon} aria-hidden="true">{'🔍'}</span>
          <input
            className={styles.searchInput}
            type="text"
            value={query}
            onChange={(e) => setQuery(e.target.value)}
            placeholder="Search patients"
            autoComplete="off"
            aria-label="Search patients"
          />
          <span className={styles.searchCaret} aria-hidden="true" />
        </label>
        <button className={styles.newBtn} type="button">
          <span className={styles.newPlus}>+</span>
          <span>New Patient</span>
        </button>
      </header>

      <div className={styles.filters}>
        <span className={styles.filterLabel}>Filters:</span>
        {chips.map((c) => {
          const active = filters.has(c.key);
          const cls = active
            ? `${styles.chip} ${styles.chipActive}`
            : styles.chip;
          return (
            <button
              key={c.key}
              type="button"
              className={cls}
              onClick={() => toggleFilter(c.key)}
              aria-pressed={active}
            >
              <span>{c.label}</span>
              {active && <span className={styles.chipX} aria-hidden="true">{'×'}</span>}
            </button>
          );
        })}
        <span className={styles.resultsCount}>{RESULTS_COUNT} results</span>
      </div>

      <div className={styles.tableWrap}>
        <div className={styles.table}>
          <div className={styles.thead}>
            {COLUMNS.map((c) => (
              <div key={c} className={styles.th}>{c}</div>
            ))}
          </div>

          {ROWS.map((r) => {
            const selected = r.pid === selectedPid;
            const rowCls = selected
              ? `${styles.row} ${styles.rowSelected}`
              : styles.row;
            return (
              <div
                key={r.pid}
                className={rowCls}
                role="button"
                tabIndex={0}
                onClick={() => onRowClick(r.pid)}
                onKeyDown={(e) => {
                  if (e.key === 'Enter' || e.key === ' ') {
                    e.preventDefault();
                    onRowClick(r.pid);
                  }
                }}
                title={`Open ${r.name}`}
              >
                <div className={styles.cellName}>
                  <span
                    className={styles.avatar}
                    style={{ backgroundColor: r.avatarColor }}
                  >
                    {r.initials}
                  </span>
                  <span className={styles.name}>{r.name}</span>
                </div>
                <div className={styles.mrn}>{r.mrn}</div>
                <div className={styles.dob}>{r.dob}</div>
                <div className={styles.prov}>{r.provider}</div>
                <div className={styles.ins}>{r.insurance}</div>
                <div
                  className={
                    r.today ? `${styles.visit} ${styles.visitToday}` : styles.visit
                  }
                >
                  {r.lastVisit}
                </div>
                <div className={styles.flags}>
                  {r.flags.map((fl, i) => (
                    <span
                      key={i}
                      className={`${styles.flag} ${flagClass(fl.kind, styles)}`}
                    >
                      {fl.label}
                    </span>
                  ))}
                </div>
              </div>
            );
          })}
        </div>
      </div>
    </>
  );
}

function flagClass(kind: FlagKind, s: typeof styles): string {
  switch (kind) {
    case 'warn': return s['flagWarn'] ?? '';
    case 'cond': return s['flagCond'] ?? '';
    case 'rx':   return s['flagRx'] ?? '';
  }
}
