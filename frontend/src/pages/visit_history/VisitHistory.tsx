// VisitHistory — Figma "Screen 30 — Visit History".
//
// Patient-scoped encounter list. Distinct from the Co-Pilot History
// navtab (Screen 12). The chrome (top nav, demographics banner, patient
// navtab strip) is rendered by the surrounding PHP wrapper; this
// component renders only the page body (page header, filter row, table,
// pager).
//
// Data is now live: the wrapper at copilot_visit_history.php joins
// form_encounter -> users -> openemr_postcalendar_categories for the
// active patient and JSON-encodes the payload onto data-history. Filter
// dropdowns and search input are React local state; they filter the
// already-loaded rows client-side. The pre-React PHP mock is preserved
// at copilot_visit_history.php.bak.

import { useMemo, useState } from 'react';
import type { BootContext } from '../../shared/lib/bootContext';
import styles from './VisitHistory.module.css';

type StatusKey = 'signed' | 'in_progress' | 'billed';
type DateRangeKey = '12mo' | '6mo' | '3mo' | 'all';
type StatusFilterKey = 'all' | 'signed' | 'in_progress' | 'billed';

const PAGE_SIZE = 11;

export type VisitRow = {
  readonly id: number;
  readonly encounter: number;
  readonly date: string;
  readonly time: string;
  readonly type: string;
  readonly typeId: number;
  readonly provider: string;
  readonly providerId: number;
  readonly reason: string;
  readonly duration: string;
  readonly status: StatusKey;
  readonly billed: boolean;
};

export type VisitTypeOpt = { readonly id: number; readonly name: string };
export type ProviderOpt = { readonly id: number; readonly name: string };

export type VisitHistoryPayload = {
  readonly rows: readonly VisitRow[];
  readonly totalAll: number;
  readonly visitTypeOpts: readonly VisitTypeOpt[];
  readonly providerOpts: readonly ProviderOpt[];
};

const DATE_RANGE_LABELS: Readonly<Record<DateRangeKey, string>> = {
  '12mo': 'Last 12 months',
  '6mo':  'Last 6 months',
  '3mo':  'Last 3 months',
  all:    'All time',
};

const STATUS_LABELS: Readonly<Record<StatusFilterKey, string>> = {
  all:         'All',
  signed:      'Signed',
  in_progress: 'In progress',
  billed:      'Billed',
};

type VisitHistoryProps = {
  readonly boot: BootContext;
  readonly payload: VisitHistoryPayload;
};

export function VisitHistory({ payload }: VisitHistoryProps): JSX.Element {
  const [dateRange,  setDateRange]  = useState<DateRangeKey>('all');
  const [visitTypeId,setVisitTypeId]= useState<string>('all');
  const [providerId, setProviderId] = useState<string>('all');
  const [status,     setStatus]     = useState<StatusFilterKey>('all');
  const [search,     setSearch]     = useState<string>('');
  const [page,       setPage]       = useState<number>(1);

  const clearFilters = (): void => {
    setDateRange('all');
    setVisitTypeId('all');
    setProviderId('all');
    setStatus('all');
    setSearch('');
    setPage(1);
  };

  const filteredRows = useMemo<readonly VisitRow[]>(() => {
    const q = search.trim().toLowerCase();
    const now = Date.now();
    const cutoff = (() => {
      if (dateRange === 'all') return null;
      const months = dateRange === '12mo' ? 12 : dateRange === '6mo' ? 6 : 3;
      return now - months * 30 * 24 * 60 * 60 * 1000;
    })();
    return payload.rows.filter((r) => {
      if (q !== '') {
        const hay = `${r.reason} ${r.provider} ${r.type}`.toLowerCase();
        if (!hay.includes(q)) return false;
      }
      if (visitTypeId !== 'all' && String(r.typeId) !== visitTypeId) return false;
      if (providerId !== 'all' && String(r.providerId) !== providerId) return false;
      if (status !== 'all' && r.status !== status) return false;
      if (cutoff !== null) {
        const ts = parseDate(r.date);
        if (ts !== null && ts < cutoff) return false;
      }
      return true;
    });
  }, [payload.rows, search, dateRange, visitTypeId, providerId, status]);

  const totalFiltered = filteredRows.length;
  const totalPages = Math.max(1, Math.ceil(totalFiltered / PAGE_SIZE));
  const safePage = Math.min(page, totalPages);
  const showFrom = totalFiltered === 0 ? 0 : ((safePage - 1) * PAGE_SIZE) + 1;
  const showTo = Math.min(safePage * PAGE_SIZE, totalFiltered);
  const visibleRows = filteredRows.slice((safePage - 1) * PAGE_SIZE, safePage * PAGE_SIZE);

  const headerSubtitle =
    `${payload.totalAll} ${payload.totalAll === 1 ? 'encounter' : 'encounters'} · ${DATE_RANGE_LABELS[dateRange]}`;

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
              onChange={(e) => { setSearch(e.target.value); setPage(1); }}
              placeholder="Search by reason, provider, or notes..."
            />
          </label>

          <FilterDropdown
            label="Date range"
            value={DATE_RANGE_LABELS[dateRange]}
            options={[
              { id: 'all',  name: 'All time' },
              { id: '12mo', name: 'Last 12 months' },
              { id: '6mo',  name: 'Last 6 months' },
              { id: '3mo',  name: 'Last 3 months' },
            ]}
            current={dateRange}
            onChange={(v) => { setDateRange(v as DateRangeKey); setPage(1); }}
          />
          <FilterDropdown
            label="Visit type"
            value={
              visitTypeId === 'all'
                ? 'All types'
                : payload.visitTypeOpts.find((o) => String(o.id) === visitTypeId)?.name ?? 'All types'
            }
            options={[
              { id: 'all', name: 'All types' },
              ...payload.visitTypeOpts.map((o) => ({ id: String(o.id), name: o.name })),
            ]}
            current={visitTypeId}
            onChange={(v) => { setVisitTypeId(v); setPage(1); }}
          />
          <FilterDropdown
            label="Provider"
            value={
              providerId === 'all'
                ? 'All providers'
                : payload.providerOpts.find((o) => String(o.id) === providerId)?.name ?? 'All providers'
            }
            options={[
              { id: 'all', name: 'All providers' },
              ...payload.providerOpts.map((o) => ({ id: String(o.id), name: o.name })),
            ]}
            current={providerId}
            onChange={(v) => { setProviderId(v); setPage(1); }}
          />
          <FilterDropdown
            label="Status"
            value={STATUS_LABELS[status]}
            options={[
              { id: 'all',         name: 'All' },
              { id: 'signed',      name: 'Signed' },
              { id: 'in_progress', name: 'In progress' },
              { id: 'billed',      name: 'Billed' },
            ]}
            current={status}
            onChange={(v) => { setStatus(v as StatusFilterKey); setPage(1); }}
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
              {visibleRows.length === 0 ? (
                <tr><td colSpan={9} className={styles.empty}>No encounters match the current filters.</td></tr>
              ) : visibleRows.map((v) => (
                <tr key={v.id}>
                  <td className={styles.cellDate}>{v.date}</td>
                  <td className={styles.cellTime}>{v.time}</td>
                  <td className={styles.cellType}>{v.type}</td>
                  <td className={styles.cellProv}>{v.provider}</td>
                  <td className={styles.cellReason}>{v.reason}</td>
                  <td className={styles.cellDur}>{v.duration}</td>
                  <td><StatusPill status={v.status} /></td>
                  <td>
                    {v.billed
                      ? <span className={styles.pillBilled}>Billed</span>
                      : <span className={styles.dash}>—</span>}
                  </td>
                  <td className={styles.cellOpen}>
                    <a className={styles.openLink}
                       href={`/interface/patient_file/encounter/copilot_encounter.php?eid=${v.id}`}>
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
            Showing {showFrom}-{showTo} of {totalFiltered}
          </div>
          <div className={styles.pager}>
            <button
              type="button"
              className={`${styles.pagerNav} ${safePage === 1 ? styles.pagerNavDisabled : ''}`}
              disabled={safePage === 1}
              onClick={() => setPage((p) => Math.max(1, p - 1))}
            >
              ‹ Prev
            </button>
            {Array.from({ length: totalPages }, (_, idx) => {
              const n = idx + 1;
              const active = n === safePage;
              const cls = active ? `${styles.pg} ${styles.pgActive}` : styles.pg;
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
              className={`${styles.pagerNav} ${safePage === totalPages ? styles.pagerNavDisabled : ''}`}
              disabled={safePage === totalPages}
              onClick={() => setPage((p) => Math.min(totalPages, p + 1))}
            >
              Next ›
            </button>
          </div>
        </div>
      </main>
    </>
  );
}

type StatusPillProps = { readonly status: StatusKey };

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

type DDOption = { readonly id: string; readonly name: string };
type FilterDropdownProps = {
  readonly label: string;
  readonly value: string;
  readonly options: readonly DDOption[];
  readonly current: string;
  readonly onChange: (next: string) => void;
};

function FilterDropdown({ label, value, options, current, onChange }: FilterDropdownProps): JSX.Element {
  return (
    <div className={styles.dd}>
      <span className={styles.ddLbl}>{label}</span>
      <span className={styles.ddVal}>{value}</span>
      <select
        className={styles.ddSelect}
        value={current}
        onChange={(e) => onChange(e.target.value)}
        aria-label={label}
      >
        {options.map((o) => <option key={o.id} value={o.id}>{o.name}</option>)}
      </select>
    </div>
  );
}

function parseDate(mmddyyyy: string): number | null {
  const m = /^(\d{2})\/(\d{2})\/(\d{4})$/.exec(mmddyyyy);
  if (!m) return null;
  const [, mm, dd, yyyy] = m;
  return new Date(`${yyyy}-${mm}-${dd}T00:00:00`).getTime();
}
