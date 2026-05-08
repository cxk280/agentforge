// Finder — Figma "Screen 10 — Patient Finder".
//
// 1:1 port of the PHP-rendered finder previously at
// /interface/main/finder/copilot_finder.php (preserved as .bak). DB-backed:
// the PHP wrapper queries patient_data + JOINs and JSON-encodes the result
// as data-roster on #cp-root; the entry index.tsx parses it and passes it
// here as the `roster` prop. Search, filter chips, and selection are held
// in React local state. Row click → window.parent.navigateTab to
// demographics.php?set_pid=N (matches the PHP <a href> behavior).

import { useMemo, useState } from 'react';
import type { BootContext } from '../../shared/lib/bootContext';
import styles from './Finder.module.css';

// Server-side roster shape (mirrors the PHP query in copilot_finder.php).
export type RosterPatient = {
  readonly pid: number;
  readonly mrn: string;
  readonly fname: string;
  readonly lname: string;
  readonly name: string;
  readonly dob: string;            // 'YYYY-MM-DD' or ''
  readonly age: number | null;
  readonly providerId: number;
  readonly providerName: string;
  readonly insurance: string;
  readonly lastVisit: string;      // 'Apr 12' or ''
  readonly isToday: boolean;
  readonly topAllergy: string;
  readonly allergyCount: number;
  readonly topCondition: string;
  readonly conditionCount: number;
  readonly isActive: boolean;
};

type FlagKind = 'warn' | 'cond' | 'rx';

type Flag = {
  readonly kind: FlagKind;
  readonly label: string;
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

// Avatar palette mirrors the PHP version; deterministic per-pid pick.
const AVATAR_PALETTE: readonly string[] = [
  '#5FD0D0', '#4885D9', '#FA8C33', '#8561C7', '#33A68C', '#D9668C',
];
function avatarColor(pid: number): string {
  return AVATAR_PALETTE[pid % AVATAR_PALETTE.length] ?? '#5FD0D0';
}

function initials(fname: string, lname: string): string {
  const a = fname.charAt(0).toUpperCase();
  const b = lname.charAt(0).toUpperCase();
  const i = (a + b).trim();
  return i !== '' ? i : '?';
}

function flagsFor(p: RosterPatient): readonly Flag[] {
  const out: Flag[] = [];
  if (p.allergyCount > 0 && p.topAllergy !== '') {
    const label = p.topAllergy.length > 14 ? p.topAllergy.slice(0, 13) + '…' : p.topAllergy;
    out.push({ kind: 'warn', label: `⚠ ${label}` });
  }
  if (p.conditionCount > 0 && p.topCondition !== '') {
    const label = p.topCondition.length > 14 ? p.topCondition.slice(0, 13) + '…' : p.topCondition;
    out.push({ kind: 'cond', label: `🩺 ${label}` });
  }
  return out;
}

function dobLabel(p: RosterPatient): string {
  if (p.dob === '') return '—';
  const dobFmt = p.dob.replace(/^(\d{4})-(\d{2})-(\d{2}).*/, '$2/$3/$1');
  return p.age !== null ? `${dobFmt} (${p.age} yrs)` : dobFmt;
}

function insuranceChipLabel(active: boolean): string {
  return active ? 'Insurance: Yes' : 'Insurance: Any';
}

// Reach into the helpers the parent shell defines (interface/main/tabs/js/
// tabs_view_model.js). Finder runs INSIDE the #maimain iframe, so the
// helpers live on `window.parent` (the shell), not on `window`.
type Win = Window & {
  navigateTab?: (url: string, name: string, afterLoad?: () => void) => void;
  activateTabByName?: (name: string, hideOthers?: boolean) => void;
  webroot_url?: string;
  patient_data_view_model?: new (
    pname: string,
    pid: number,
    pubpid: string,
    strDob: string,
    provider: string,
    insurance: string,
    allergies: unknown[],
  ) => unknown;
  app_view_model?: {
    application_data?: {
      patient?: (v?: unknown) => unknown;
    };
  };
};

// Pre-populate the parent shell's patient observable from data we already
// have on the row, so the banner renders content immediately instead of
// waiting 6-10 seconds for demographics.php to load + populate. Demographics
// will replace this stub with the full record once it lands.
function preheatPatient(p: RosterPatient, parent: Win, top: Win): void {
  const Ctor = parent.patient_data_view_model ?? top.patient_data_view_model;
  const set = parent.app_view_model?.application_data?.patient
    ?? top.app_view_model?.application_data?.patient;
  if (typeof Ctor !== 'function' || typeof set !== 'function') return;
  // Banner reads pname (Last, First) for the title, pubpid stripped of '#',
  // str_dob for the DOB line, provider for the bottom-row meta.
  const pubpid = p.mrn.replace(/^#/, '');
  const dob = p.dob || 'N/A';
  try {
    const stub = new Ctor(p.name, p.pid, pubpid, dob, p.providerName, p.insurance, []);
    set(stub);
  } catch (_) { /* observable unavailable yet — fall through */ }
}

function openDemographics(p: RosterPatient): void {
  const self = window as Win;
  const parent = (self.parent !== self ? self.parent : self) as Win;
  const top = (self.top !== null && self.top !== self ? self.top : self) as Win;

  preheatPatient(p, parent, top);

  const webroot = parent.webroot_url ?? top.webroot_url ?? self.webroot_url ?? '';
  const url = `${webroot}/interface/patient_file/summary/demographics.php?set_pid=${p.pid}`;

  const navigateTab = parent.navigateTab ?? top.navigateTab;
  const activateTabByName = parent.activateTabByName ?? top.activateTabByName;
  if (typeof navigateTab === 'function') {
    navigateTab(url, 'pat', () => activateTabByName?.('pat', true));
    return;
  }
  // Last resort: navigate just THIS iframe — never window.top.
  self.location.href = url;
}

type FinderProps = {
  readonly boot: BootContext;
  readonly roster: readonly RosterPatient[];
};

export function Finder({ roster }: FinderProps): JSX.Element {
  // Default state matches the PHP `?q=` empty + `?active=1` defaults.
  const [query, setQuery] = useState<string>('');
  const [filters, setFilters] = useState<ReadonlySet<FilterKey>>(
    new Set<FilterKey>(['active']),
  );
  const [selectedPid, setSelectedPid] = useState<number | null>(null);

  const insActive = filters.has('ins');
  const onlyActive = filters.has('active');
  const onlyMyPanel = filters.has('my_panel');
  const onlyRecent = filters.has('recent');
  const currentUserId = Number.parseInt(useBootUserId(), 10) || 0;

  const visibleRows = useMemo(() => {
    const q = query.trim().toLowerCase();
    return roster.filter((p) => {
      if (onlyActive && !p.isActive) return false;
      if (onlyMyPanel && p.providerId !== currentUserId) return false;
      if (insActive && p.insurance.trim() === '') return false;
      if (onlyRecent && p.lastVisit === '') return false;
      if (q !== '') {
        const hay = `${p.name} ${p.fname} ${p.lname} ${p.mrn}`.toLowerCase();
        if (!hay.includes(q)) return false;
      }
      return true;
    });
  }, [roster, query, onlyActive, onlyMyPanel, onlyRecent, insActive, currentUserId]);

  const onRowClick = (p: RosterPatient): void => {
    setSelectedPid(p.pid);
    openDemographics(p);
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

  const chips: readonly { key: FilterKey; label: string }[] = [
    { key: 'my_panel',      label: 'My panel' },
    { key: 'all_providers', label: 'All providers' },
    { key: 'active',        label: 'Active only' },
    { key: 'ins',           label: insuranceChipLabel(insActive) },
    { key: 'recent',        label: 'Last visit ≤ 12 mo' },
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
        <span className={styles.resultsCount}>{visibleRows.length} results</span>
      </div>

      <div className={styles.tableWrap}>
        <div className={styles.table}>
          <div className={styles.thead}>
            {COLUMNS.map((c) => (
              <div key={c} className={styles.th}>{c}</div>
            ))}
          </div>

          {visibleRows.length === 0 ? (
            <div className={styles.emptyRow}>
              No patients match the current filters.
            </div>
          ) : (
            visibleRows.map((p) => {
              const selected = p.pid === selectedPid;
              const rowCls = selected
                ? `${styles.row} ${styles.rowSelected}`
                : styles.row;
              const flags = flagsFor(p);
              return (
                <div
                  key={p.pid}
                  className={rowCls}
                  role="button"
                  tabIndex={0}
                  onClick={() => onRowClick(p)}
                  onKeyDown={(e) => {
                    if (e.key === 'Enter' || e.key === ' ') {
                      e.preventDefault();
                      onRowClick(p);
                    }
                  }}
                  title={`Open ${p.name}`}
                >
                  <div className={styles.cellName}>
                    <span
                      className={styles.avatar}
                      style={{ backgroundColor: avatarColor(p.pid) }}
                    >
                      {initials(p.fname, p.lname)}
                    </span>
                    <span className={styles.name}>{p.name}</span>
                  </div>
                  <div className={styles.mrn}>{p.mrn}</div>
                  <div className={styles.dob}>{dobLabel(p)}</div>
                  <div className={styles.prov}>{p.providerName || '—'}</div>
                  <div className={styles.ins}>{p.insurance || '—'}</div>
                  <div
                    className={
                      p.isToday ? `${styles.visit} ${styles.visitToday}` : styles.visit
                    }
                  >
                    {p.lastVisit || '—'}
                  </div>
                  <div className={styles.flags}>
                    {flags.map((fl, i) => (
                      <span
                        key={i}
                        className={`${styles.flag} ${flagClass(fl.kind)}`}
                      >
                        {fl.label}
                      </span>
                    ))}
                  </div>
                </div>
              );
            })
          )}
        </div>
      </div>
    </>
  );
}

function flagClass(kind: FlagKind): string {
  switch (kind) {
    case 'warn': return styles['flagWarn'] ?? '';
    case 'cond': return styles['flagCond'] ?? '';
    case 'rx':   return styles['flagRx'] ?? '';
  }
}

function useBootUserId(): string {
  const el = document.getElementById('cp-root');
  return el?.dataset['userId'] ?? '';
}
