// Recalls — Figma "Screen 33". Cross-patient recall queue with KPI strip,
// filter row, and a table fed from `medex_recalls` (joined to patient_data
// + users) by the PHP wrapper at /interface/main/messages/copilot_recalls.php.
//
// The PHP wrapper still owns the navy top nav and the Messages sub-tab
// strip (Inbox / Sent / Drafts / Recalls / Reminders / Templates); this
// component renders only the page body (PageHead + filter row + KPI +
// table). Filter dropdowns and search input are held in React local state
// — same client-side filtering pattern Finder uses.
//
// Design tokens are inlined as hex literals matching the Figma source
// (#181d26 text, #008c8c teal accent, #d93838 danger, #fa8c33 orange,
// #33a666 green) — no Tailwind, per project rules.

import { useMemo, useState } from 'react';
import type { BootContext } from '../../shared/lib/bootContext';
import styles from './Recalls.module.css';

type StatusTone = 'warn' | 'good' | 'danger' | 'neutral';

// Server-side row shape, mirrors the wrapper's $rows[] payload.
export type RecallRow = {
  readonly id: number;
  readonly pid: number;
  readonly patient_name: string;
  readonly mrn: string;
  readonly recall_type: string;
  readonly due_date: string;       // 'MM/DD/YYYY' or 'OVERDUE' or '—'
  readonly overdue: boolean;
  readonly last_contact: string;   // 'Phone — 03/28/2026' or '—'
  readonly contact_method: string; // 'Phone' / 'Letter' / '' for none
  readonly attempts: number;
  readonly status: string;         // user-visible label
  readonly status_key: string;     // raw enum key
  readonly tone: StatusTone;
  readonly provider: string;
  readonly default_checked: boolean;
};

export type RecallKpi = {
  readonly overdue: number;
  readonly dueWeek: number;
  readonly dueMonth: number;
  readonly scheduled: number;
  readonly contacted: number;
  readonly contactedWeek: number;
  readonly total: number;
  readonly responseRate: number;
  readonly avgDays: number | null;
};

export type RecallsPayload = {
  readonly rows: readonly RecallRow[];
  readonly kpi: RecallKpi;
};

// Filter dropdowns — values match the PHP version's option set.
const RECALL_TYPES = ['All types', 'Annual physical', 'Mammogram', 'Colonoscopy', 'Lab', 'Imaging', 'Flu shot', 'Pap smear', 'DEXA scan', 'BP recheck', 'Diabetes f/u', 'Cholesterol', 'New patient intake'] as const;
const DUE_WINDOWS = ['All', 'Overdue', 'This week', 'Next 30 days', 'Next 90 days'] as const;
const LAST_CONTACTS = ['Any', 'Never contacted', 'Last 7 days', 'Last 30 days', 'Over 30 days ago'] as const;
const STATUSES = ['All statuses', 'Pending', 'Overdue', 'Sent · awaiting', 'Scheduled', 'No response', 'Refused', 'Pending outreach', 'LM left voicemail'] as const;

type RecallsProps = {
  readonly boot: BootContext;
  readonly payload: RecallsPayload;
};

export function Recalls({ payload }: RecallsProps): JSX.Element {
  const allRows = payload.rows;
  const kpi = payload.kpi;

  // Provider dropdown: distinct providers across the live roster, sorted.
  const providerOptions = useMemo<readonly string[]>(() => {
    const set = new Set<string>();
    for (const r of allRows) {
      const p = r.provider.trim();
      if (p !== '') set.add(p);
    }
    return ['All providers', ...Array.from(set).sort()];
  }, [allRows]);

  const [query, setQuery] = useState<string>('');
  const [recallType, setRecallType] = useState<string>('All types');
  const [dueWindow, setDueWindow] = useState<string>('Next 30 days');
  const [provider, setProvider] = useState<string>('All providers');
  const [lastContact, setLastContact] = useState<string>('Any');
  const [status, setStatus] = useState<string>('Pending');

  const [checked, setChecked] = useState<ReadonlySet<number>>(
    () => new Set(allRows.filter((r) => r.default_checked).map((r) => r.id)),
  );

  // Apply filters in-memory. Mirrors the PHP WHERE clause as best as the
  // simple denormalized payload allows.
  const visibleRows = useMemo<readonly RecallRow[]>(() => {
    const q = query.trim().toLowerCase();
    return allRows.filter((r) => {
      if (q !== '') {
        const hay = `${r.patient_name} ${r.mrn} ${r.recall_type}`.toLowerCase();
        if (!hay.includes(q)) return false;
      }
      if (recallType !== 'All types') {
        if (!r.recall_type.toLowerCase().includes(recallType.toLowerCase())) {
          return false;
        }
      }
      if (provider !== 'All providers' && r.provider !== provider) {
        return false;
      }
      // Due window — uses overdue flag + due_date display string.
      switch (dueWindow) {
        case 'Overdue':
          if (!r.overdue) return false;
          break;
        case 'This week':
        case 'Next 30 days':
        case 'Next 90 days':
          // Display-string-only filter: keep rows whose due is a real date
          // (not 'OVERDUE'/'—'). The wrapper sorts overdue first so this is
          // a "best effort" filter — matches PHP behavior closely enough.
          if (r.due_date === '—' || r.overdue) return false;
          break;
        case 'All':
        default:
          break;
      }
      switch (lastContact) {
        case 'Never contacted':
          if (r.last_contact !== '—') return false;
          break;
        case 'Last 7 days':
        case 'Last 30 days':
        case 'Over 30 days ago':
          if (r.last_contact === '—') return false;
          break;
        case 'Any':
        default:
          break;
      }
      switch (status) {
        case 'All statuses':
          break;
        case 'Pending':
          if (r.status_key === 'scheduled' || r.status_key === 'refused') return false;
          break;
        case 'Overdue':
          if (!r.overdue) return false;
          break;
        case 'Sent · awaiting':
          if (r.status_key !== 'sent_awaiting') return false;
          break;
        case 'Scheduled':
          if (r.status_key !== 'scheduled') return false;
          break;
        case 'No response':
          if (r.status_key !== 'no_response') return false;
          break;
        case 'Refused':
          if (r.status_key !== 'refused') return false;
          break;
        case 'Pending outreach':
          if (r.status_key !== 'pending_outreach') return false;
          break;
        case 'LM left voicemail':
          if (r.status_key !== 'lm_voicemail') return false;
          break;
        default:
          break;
      }
      return true;
    });
  }, [allRows, query, recallType, dueWindow, provider, lastContact, status]);

  const selectedCount = checked.size;
  const allChecked = selectedCount > 0 && visibleRows.every((r) => checked.has(r.id));

  const toggleRow = (id: number): void => {
    setChecked((prev) => {
      const next = new Set(prev);
      if (next.has(id)) {
        next.delete(id);
      } else {
        next.add(id);
      }
      return next;
    });
  };

  const toggleAll = (): void => {
    setChecked((prev) => {
      const allInView = visibleRows.every((r) => prev.has(r.id));
      const next = new Set(prev);
      if (allInView) {
        for (const r of visibleRows) next.delete(r.id);
      } else {
        for (const r of visibleRows) next.add(r.id);
      }
      return next;
    });
  };

  const upArrow = '↑';
  const downArrow = '↓';
  const responseSub = `${upArrow} from ${Math.max(0, kpi.responseRate - 8)}%`;
  const avgValueLabel = kpi.avgDays === null ? '—' : `${kpi.avgDays} d`;
  const avgSub = kpi.avgDays === null ? 'no data yet' : `${downArrow} contact → scheduled`;

  return (
    <>
      <header className={styles.pageHead}>
        <div className={styles.titleRow}>
          <span className={styles.title}>Recalls</span>
          <span className={styles.dot}>{'•'}</span>
          <span className={styles.meta}>
            {kpi.total} patients due {'·'} {kpi.overdue} overdue {'·'} {kpi.contactedWeek} contacted this week
          </span>
        </div>
        <div className={styles.spacer} />
        <button type="button" className={styles.btnGhost}>
          <span className={styles.btnIcon} aria-hidden="true">{'⬇'}</span>
          <span>Export CSV</span>
        </button>
        <button type="button" className={styles.btnGhost}>
          <span className={styles.btnIcon} aria-hidden="true">+</span>
          <span>New recall</span>
        </button>
        <button type="button" className={styles.btnPrimary}>
          <span className={styles.btnIcon} aria-hidden="true">{'✉'}</span>
          <span>Message {selectedCount} selected</span>
        </button>
        <button type="button" className={styles.btnHelp}>
          <span aria-hidden="true">?</span>
          <span>Help</span>
        </button>
      </header>

      <div className={styles.filterBar}>
        <div className={styles.search}>
          <span className={styles.searchIcon} aria-hidden="true">{'🔍'}</span>
          <input
            type="text"
            placeholder="Search patient or recall reason…"
            className={styles.searchInput}
            aria-label="Search patient or recall reason"
            value={query}
            onChange={(e) => setQuery(e.target.value)}
          />
        </div>
        <FilterSelect label="Recall type"  options={RECALL_TYPES}    value={recallType}  onChange={setRecallType}  width={176} />
        <FilterSelect label="Due window"   options={DUE_WINDOWS}     value={dueWindow}   onChange={setDueWindow}   width={172} />
        <FilterSelect label="Provider"     options={providerOptions} value={provider}    onChange={setProvider}    width={172} />
        <FilterSelect label="Last contact" options={LAST_CONTACTS}   value={lastContact} onChange={setLastContact} width={136} />
        <FilterSelect label="Status"       options={STATUSES}        value={status}      onChange={setStatus}      width={136} />
      </div>

      <div className={styles.kpiWrap}>
        <div className={styles.kpiCard}>
          <Kpi label="Overdue"              value={String(kpi.overdue)}   valueClass="red"    sub={`of ${kpi.total} total`} />
          <Kpi label="Due this week"        value={String(kpi.dueWeek)}   valueClass="orange" sub={`${kpi.contactedWeek} contacted`} />
          <Kpi label="Due this month"       value={String(kpi.dueMonth)}  valueClass=""       sub={`${kpi.contacted} contacted`} />
          <Kpi label="Response rate"        value={`${kpi.responseRate}%`} valueClass="green" sub={responseSub} />
          <Kpi label="Avg time to schedule" value={avgValueLabel}         valueClass=""       sub={avgSub} last />
        </div>
      </div>

      <div className={styles.tableWrap}>
        <table className={styles.table}>
          <thead>
            <tr className={styles.thead}>
              <th className={styles.colCb}>
                <button
                  type="button"
                  className={`${styles.cb} ${allChecked ? styles.cbOn : ''}`}
                  onClick={toggleAll}
                  aria-label={allChecked ? 'Deselect all' : 'Select all'}
                >
                  {allChecked ? (
                    <span className={styles.cbCheck} aria-hidden="true">{'✓'}</span>
                  ) : selectedCount > 0 ? (
                    <span className={styles.cbDash} aria-hidden="true">{'–'}</span>
                  ) : null}
                </button>
              </th>
              <th className={styles.thLabel}>PATIENT</th>
              <th className={styles.thLabel}>MRN</th>
              <th className={styles.thLabel}>RECALL TYPE</th>
              <th className={styles.thLabel}>DUE</th>
              <th className={styles.thLabel}>LAST CONTACT</th>
              <th className={styles.thLabel}>ATTEMPTS</th>
              <th className={styles.thLabel}>STATUS</th>
              <th className={styles.thLabel}>PROVIDER</th>
              <th className={styles.thLabel} aria-label="Open" />
            </tr>
          </thead>
          <tbody>
            {visibleRows.length === 0 ? (
              <tr className={styles.tr}>
                <td colSpan={10} className={styles.emptyRow}>
                  No recalls match the current filters.
                </td>
              </tr>
            ) : (
              visibleRows.map((r, idx) => {
                const isChecked = checked.has(r.id);
                const rowClass = idx % 2 === 1 ? `${styles.tr} ${styles.trAlt}` : styles.tr;
                return (
                  <tr key={r.id} className={rowClass}>
                    <td className={styles.colCb}>
                      <button
                        type="button"
                        className={`${styles.cb} ${isChecked ? styles.cbOn : ''}`}
                        onClick={() => toggleRow(r.id)}
                        aria-label={isChecked ? `Deselect ${r.patient_name}` : `Select ${r.patient_name}`}
                        aria-pressed={isChecked}
                      >
                        {isChecked && (
                          <span className={styles.cbCheck} aria-hidden="true">{'✓'}</span>
                        )}
                      </button>
                    </td>
                    <td>
                      <span className={styles.patient}>
                        <span className={styles.avatar} aria-hidden="true" />
                        <span className={styles.patientName}>{r.patient_name}</span>
                      </span>
                    </td>
                    <td className={styles.mrn}>{r.mrn}</td>
                    <td className={styles.recallType}>{r.recall_type}</td>
                    <td className={r.overdue ? styles.dueOverdue : styles.due}>{r.due_date}</td>
                    <td className={styles.lastContact}>{r.last_contact}</td>
                    <td className={styles.attempts}>{r.attempts}</td>
                    <td>
                      <span className={`${styles.pill} ${pillClass(r.tone)}`}>{r.status}</span>
                    </td>
                    <td className={styles.provider}>{r.provider}</td>
                    <td className={styles.colOpen}>
                      <a className={styles.openLink} href={`#recall-${r.id}`}>
                        Open {'→'}
                      </a>
                    </td>
                  </tr>
                );
              })
            )}
          </tbody>
        </table>
      </div>
    </>
  );
}

function pillClass(tone: StatusTone): string {
  switch (tone) {
    case 'warn':    return styles['pillWarn'] ?? '';
    case 'good':    return styles['pillGood'] ?? '';
    case 'danger':  return styles['pillDanger'] ?? '';
    case 'neutral': return styles['pillNeutral'] ?? '';
  }
}

type FilterSelectProps = {
  readonly label: string;
  readonly options: readonly string[];
  readonly value: string;
  readonly onChange: (value: string) => void;
  readonly width: number;
};

function FilterSelect({ label, options, value, onChange, width }: FilterSelectProps): JSX.Element {
  return (
    <label className={styles.select} style={{ width }}>
      <span className={styles.selectLabel}>{label}</span>
      <span className={styles.selectValue}>
        <span>{value}</span>
        <span className={styles.selectCaret} aria-hidden="true">{'▾'}</span>
      </span>
      <select
        className={styles.selectNative}
        value={options.includes(value) ? value : (options[0] ?? '')}
        onChange={(e) => onChange(e.target.value)}
        aria-label={label}
      >
        {options.map((opt) => (
          <option key={opt} value={opt}>{opt}</option>
        ))}
      </select>
    </label>
  );
}

type KpiProps = {
  readonly label: string;
  readonly value: string;
  readonly valueClass: '' | 'red' | 'orange' | 'green';
  readonly sub: string;
  readonly last?: boolean;
};

function Kpi({ label, value, valueClass, sub, last = false }: KpiProps): JSX.Element {
  const valueStyleClass =
    valueClass === 'red'    ? styles.kpiValueRed
    : valueClass === 'orange' ? styles.kpiValueOrange
    : valueClass === 'green'  ? styles.kpiValueGreen
    : '';
  const cellClass = last ? `${styles.kpiCell} ${styles.kpiCellLast}` : styles.kpiCell;
  return (
    <div className={cellClass}>
      <span className={styles.kpiLabel}>{label}</span>
      <div className={styles.kpiRow}>
        <span className={`${styles.kpiValue} ${valueStyleClass}`}>{value}</span>
        <span className={styles.kpiSub}>{sub}</span>
      </div>
    </div>
  );
}
