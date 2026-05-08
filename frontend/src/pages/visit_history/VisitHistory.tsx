// VisitHistory — Figma "Screen 30 — Visit History".
//
// Patient-scoped encounter list. Distinct from the Co-Pilot History
// navtab (Screen 12). The chrome (top nav, demographics banner, patient
// navtab strip) is rendered by the surrounding PHP wrapper; this
// component renders only the page body (page header, filter row, table,
// pager).
//
// 1:1 static port of the PHP-rendered mock previously at
// /interface/patient_file/encounter/copilot_visit_history.php (preserved
// at copilot_visit_history.php.bak). Data is hardcoded from Figma node
// 73:2 — no DB, no API. Filter selects, search input, Open/kebab buttons,
// pager links and the Export action are all visual-only stubs. Wiring to
// real /apis/copilot/encounters endpoints is a follow-up.
//
// Today's date in this mock is 02/18/2026 — Margaret Chen, MRN #004821,
// 42 total encounters across her chart.
//
// File loosely groups: types, demo data, page component, sub-components.
// Status-pill rendering lives in <StatusPill>; the dropdown trigger that
// shows "label" + "value" stacked lives in <FilterDropdown>.

import { useState } from 'react';
import type { BootContext } from '../../shared/lib/bootContext';
import styles from './VisitHistory.module.css';

type StatusKey = 'signed' | 'in_progress' | 'billed';

type Visit = {
  readonly date: string;
  readonly time: string;
  readonly type: string;
  readonly provider: string;
  readonly reason: string;
  readonly duration: string;
  readonly status: StatusKey;
  readonly billed: boolean;
};

type DateRangeKey = '12mo' | '6mo' | '3mo' | 'all';
type VisitTypeKey = 'all' | 'office' | 'annual' | 'tele' | 'acute' | 'diabetes' | 'procedure';
type ProviderKey = 'all' | 'rivera' | 'chen' | 'patel';
type StatusFilterKey = 'all' | 'signed' | 'in_progress' | 'billed';

const DATE_RANGE_LABELS: Readonly<Record<DateRangeKey, string>> = {
  '12mo': 'Last 12 months',
  '6mo':  'Last 6 months',
  '3mo':  'Last 3 months',
  all:    'All time',
};

const VISIT_TYPE_LABELS: Readonly<Record<VisitTypeKey, string>> = {
  all:       'All types',
  office:    'Office Visit',
  annual:    'Annual Physical',
  tele:      'Telehealth',
  acute:     'Acute / Same-day',
  diabetes:  'Diabetes Follow-up',
  procedure: 'Procedure',
};

const PROVIDER_LABELS: Readonly<Record<ProviderKey, string>> = {
  all:    'All providers',
  rivera: 'Dr. E. Rivera',
  chen:   'Dr. K. Chen',
  patel:  'Dr. R. Patel',
};

const STATUS_LABELS: Readonly<Record<StatusFilterKey, string>> = {
  all:         'All',
  signed:      'Signed',
  in_progress: 'In progress',
  billed:      'Billed',
};

// Hardcoded demo data lifted directly from the Figma frame (Screen 30).
const VISITS: readonly Visit[] = [
  { date: '02/18/2026', time: '9:00 AM',  type: 'Annual Physical',     provider: 'Dr. E. Rivera', reason: 'Annual physical, DM2 review',  duration: '30 min', status: 'signed', billed: true },
  { date: '11/15/2025', time: '10:30 AM', type: 'Diabetes Follow-up',  provider: 'Dr. E. Rivera', reason: '3-mo A1C check, med titration', duration: '20 min', status: 'signed', billed: true },
  { date: '08/22/2025', time: '2:15 PM',  type: 'Telehealth',          provider: 'Dr. K. Chen',   reason: 'Lab review, no-show f/u',       duration: '15 min', status: 'signed', billed: true },
  { date: '05/03/2025', time: '11:00 AM', type: 'Acute / Same-day',    provider: 'Dr. R. Patel',  reason: 'URI symptoms, fever 100.2',     duration: '15 min', status: 'signed', billed: true },
  { date: '02/12/2025', time: '9:00 AM',  type: 'Annual Physical',     provider: 'Dr. E. Rivera', reason: 'Annual exam, screening labs',   duration: '30 min', status: 'signed', billed: true },
  { date: '10/04/2024', time: '3:30 PM',  type: 'Acute / Same-day',    provider: 'Dr. R. Patel',  reason: 'URI, prescribed Augmentin',     duration: '15 min', status: 'signed', billed: true },
  { date: '07/16/2024', time: '10:00 AM', type: 'Procedure',           provider: 'Dr. K. Chen',   reason: 'Knee joint injection, R',       duration: '25 min', status: 'signed', billed: true },
  { date: '04/02/2024', time: '11:30 AM', type: 'Diabetes Follow-up',  provider: 'Dr. E. Rivera', reason: '3-mo follow-up, A1C 7.2',       duration: '20 min', status: 'signed', billed: true },
  { date: '01/18/2024', time: '9:30 AM',  type: 'Office Visit',        provider: 'Dr. E. Rivera', reason: 'BP recheck, med adjustment',    duration: '15 min', status: 'signed', billed: true },
  { date: '11/02/2023', time: '2:00 PM',  type: 'Telehealth',          provider: 'Dr. K. Chen',   reason: 'Refill request, brief check-in', duration: '10 min', status: 'signed', billed: true },
  { date: '08/14/2023', time: '10:30 AM', type: 'Annual Physical',     provider: 'Dr. E. Rivera', reason: 'Annual exam',                   duration: '30 min', status: 'signed', billed: true },
];

const TOTAL_ENCOUNTERS = 42;
const SHOW_FROM = 1;
const SHOW_TO = 11;
const TOTAL_PAGES = 4;

type VisitHistoryProps = {
  readonly boot: BootContext;
};

export function VisitHistory(_props: VisitHistoryProps): JSX.Element {
  const [dateRange,  setDateRange]  = useState<DateRangeKey>('12mo');
  const [visitType,  setVisitType]  = useState<VisitTypeKey>('all');
  const [provider,   setProvider]   = useState<ProviderKey>('all');
  const [status,     setStatus]     = useState<StatusFilterKey>('all');
  const [search,     setSearch]     = useState<string>('');
  const [page,       setPage]       = useState<number>(1);

  const clearFilters = (): void => {
    setDateRange('12mo');
    setVisitType('all');
    setProvider('all');
    setStatus('all');
    setSearch('');
  };

  const headerSubtitle = `${TOTAL_ENCOUNTERS} encounters · all-time`;

  return (
    <>
      <header className={styles.pageHead}>
        <div className={styles.titleRow}>
          <span className={styles.title}>Visit History</span>
          <span className={styles.dot}>·</span>
          <span className={styles.metaLight}>{headerSubtitle}</span>
        </div>
        <div className={styles.spacer} />
        <button type="button" className={`${styles.btn} ${styles.btnGhost}`}>
          <span className={styles.btnIc}>⤓</span>
          <span>Export</span>
        </button>
        <button type="button" className={`${styles.btn} ${styles.btnPrimary}`}>
          <span className={styles.btnPlus}>+</span>
          <span>New visit</span>
        </button>
      </header>

      <main className={styles.content}>
        <div className={styles.filter}>
          <label className={styles.search} htmlFor="vhSearch">
            <span className={styles.searchIc}>🔍</span>
            <input
              id="vhSearch"
              type="text"
              value={search}
              onChange={(e) => setSearch(e.target.value)}
              placeholder="Search by reason, provider, or notes..."
            />
          </label>

          <FilterDropdown
            label="Date range"
            value={DATE_RANGE_LABELS[dateRange]}
            options={DATE_RANGE_LABELS}
            current={dateRange}
            onChange={setDateRange}
          />
          <FilterDropdown
            label="Visit type"
            value={VISIT_TYPE_LABELS[visitType]}
            options={VISIT_TYPE_LABELS}
            current={visitType}
            onChange={setVisitType}
          />
          <FilterDropdown
            label="Provider"
            value={PROVIDER_LABELS[provider]}
            options={PROVIDER_LABELS}
            current={provider}
            onChange={setProvider}
          />
          <FilterDropdown
            label="Status"
            value={STATUS_LABELS[status]}
            options={STATUS_LABELS}
            current={status}
            onChange={setStatus}
          />

          <button type="button" className={styles.clear} onClick={clearFilters}>
            Clear filters
          </button>
        </div>

        <div className={styles.tbl}>
          <table>
            <thead>
              <tr>
                <th>DATE</th>
                <th>TIME</th>
                <th>VISIT TYPE</th>
                <th>PROVIDER</th>
                <th>REASON</th>
                <th>DURATION</th>
                <th>STATUS</th>
                <th>BILLING</th>
                <th aria-label="Actions" />
              </tr>
            </thead>
            <tbody>
              {VISITS.map((v, i) => (
                <tr key={i}>
                  <td className={styles.cellDate}>{v.date}</td>
                  <td className={styles.cellTime}>{v.time}</td>
                  <td className={styles.cellType}>{v.type}</td>
                  <td className={styles.cellProv}>{v.provider}</td>
                  <td className={styles.cellReason}>{v.reason}</td>
                  <td className={styles.cellDur}>{v.duration}</td>
                  <td>
                    <StatusPill status={v.status} />
                  </td>
                  <td>
                    {v.billed ? (
                      <span className={styles.pillBilled}>Billed</span>
                    ) : (
                      <span className={styles.dash}>—</span>
                    )}
                  </td>
                  <td className={styles.cellOpen}>
                    <a className={styles.openLink} href="#" onClick={(e) => e.preventDefault()}>
                      Open <span aria-hidden>→</span>
                    </a>
                    <button
                      type="button"
                      className={styles.kebab}
                      disabled
                      title="Coming soon"
                      aria-label="More actions"
                    >
                      ⋯
                    </button>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>

        <div className={styles.footer}>
          <div className={styles.footerLeft}>
            Showing {SHOW_FROM}-{SHOW_TO} of {TOTAL_ENCOUNTERS}
          </div>
          <div className={styles.pager}>
            <button
              type="button"
              className={`${styles.pagerNav} ${page === 1 ? styles.pagerNavDisabled : ''}`}
              disabled={page === 1}
              onClick={() => setPage((p) => Math.max(1, p - 1))}
            >
              ‹ Prev
            </button>
            {Array.from({ length: TOTAL_PAGES }, (_, idx) => {
              const n = idx + 1;
              const active = n === page;
              const cls = active
                ? `${styles.pg} ${styles.pgActive}`
                : styles.pg;
              return (
                <button
                  key={n}
                  type="button"
                  className={cls}
                  aria-current={active ? 'page' : undefined}
                  onClick={() => setPage(n)}
                >
                  {n}
                </button>
              );
            })}
            <button
              type="button"
              className={`${styles.pagerNav} ${page === TOTAL_PAGES ? styles.pagerNavDisabled : ''}`}
              disabled={page === TOTAL_PAGES}
              onClick={() => setPage((p) => Math.min(TOTAL_PAGES, p + 1))}
            >
              Next ›
            </button>
          </div>
        </div>
      </main>
    </>
  );
}

type StatusPillProps = {
  readonly status: StatusKey;
};

function StatusPill({ status }: StatusPillProps): JSX.Element {
  if (status === 'signed') {
    return (
      <span className={styles.pillSigned}>
        <span className={styles.pillSignedDot} aria-hidden>✓</span>
        Signed
      </span>
    );
  }
  if (status === 'billed') {
    return <span className={styles.pillInfo}>Billed</span>;
  }
  return <span className={styles.pillWarn}>In progress</span>;
}

type FilterDropdownProps<K extends string> = {
  readonly label: string;
  readonly value: string;
  readonly options: Readonly<Record<K, string>>;
  readonly current: K;
  readonly onChange: (next: K) => void;
};

function FilterDropdown<K extends string>({
  label,
  value,
  options,
  current,
  onChange,
}: FilterDropdownProps<K>): JSX.Element {
  return (
    <div className={styles.dd}>
      <span className={styles.ddLbl}>{label}</span>
      <span className={styles.ddVal}>{value}</span>
      <select
        className={styles.ddSelect}
        value={current}
        onChange={(e) => onChange(e.target.value as K)}
        aria-label={label}
      >
        {(Object.entries(options) as Array<[K, string]>).map(([key, lbl]) => (
          <option key={key} value={key}>{lbl}</option>
        ))}
      </select>
    </div>
  );
}
