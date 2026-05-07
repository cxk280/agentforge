// Recalls — Figma "Screen 33". Cross-patient recall queue with KPI strip,
// filter row, and a 12-row demo table. 1:1 port of the DB-backed PHP mock
// previously at /interface/main/messages/copilot_recalls.php — replaced
// with hardcoded static demo data matching the Figma design exactly.
//
// The PHP wrapper still owns the navy top nav and the Messages sub-tab
// strip (Inbox / Sent / Drafts / Recalls / Reminders / Templates); this
// component renders only the page body (PageHead + filter row + KPI +
// table). Header counts and table rows mirror the Figma copy verbatim
// so the existing screenshot-diff workflow stays valid.
//
// State held in React: which rows are checked. Selecting rows arms the
// "Message N selected" header CTA; clicking it is a no-op for the demo.
// Filter dropdowns and search input are present but inert (matches PHP
// behavior where filters re-issued the GET — there is no live data here).
//
// Design tokens are inlined as hex literals matching the Figma source
// (#181d26 text, #008c8c teal accent, #d93838 danger, #fa8c33 orange,
// #33a666 green) — no Tailwind, per project rules.

import { useState } from 'react';
import type { BootContext } from '../../shared/lib/bootContext';
import styles from './Recalls.module.css';

type StatusTone = 'warn' | 'good' | 'danger' | 'neutral';

type Recall = {
  readonly id: number;
  readonly name: string;
  readonly mrn: string;
  readonly type: string;
  readonly due: string;
  readonly overdue: boolean;
  readonly last: string;
  readonly attempts: number;
  readonly status: string;
  readonly tone: StatusTone;
  readonly provider: string;
  readonly defaultChecked: boolean;
};

// Verbatim demo data from Figma Screen 33 (node 78:2). Twelve rows; the
// `defaultChecked` flags reproduce the 8 pre-selected rows shown in the
// mock so the header CTA reads "Message 8 selected" by default.
const RECALLS: readonly Recall[] = [
  { id: 1,  name: 'Margaret Chen',  mrn: '#004821', type: 'Mammogram',          due: '05/15/2026', overdue: false, last: 'Letter — 04/02/2026', attempts: 2, status: 'Sent · awaiting',   tone: 'warn',    provider: 'Dr. E. Rivera', defaultChecked: true  },
  { id: 2,  name: 'Ted Shaw',       mrn: '#000001', type: 'Annual physical',    due: '05/20/2026', overdue: false, last: 'Portal — 04/14/2026', attempts: 1, status: 'Scheduled 05/19',         tone: 'good',    provider: 'Dr. E. Rivera', defaultChecked: false },
  { id: 3,  name: 'Linda Martinez', mrn: '#003918', type: 'HbA1c lab',          due: 'OVERDUE',    overdue: true,  last: 'Phone — 03/28/2026',  attempts: 3, status: 'No response',             tone: 'danger',  provider: 'Dr. E. Rivera', defaultChecked: true  },
  { id: 4,  name: 'David Kim',      mrn: '#006102', type: 'Colonoscopy',        due: '06/02/2026', overdue: false, last: '—',                   attempts: 0, status: 'Pending outreach',        tone: 'neutral', provider: 'Dr. K. Chen',   defaultChecked: true  },
  { id: 5,  name: 'Allison Park',   mrn: '#002745', type: 'DEXA scan',          due: 'OVERDUE',    overdue: true,  last: 'Letter — 02/15/2026', attempts: 4, status: 'Refused — declined', tone: 'danger',  provider: 'Dr. E. Rivera', defaultChecked: false },
  { id: 6,  name: 'Carlos Mendez',  mrn: '#004102', type: 'Annual physical',    due: '05/08/2026', overdue: false, last: 'Phone — 04/22/2026',  attempts: 1, status: 'LM left voicemail',       tone: 'warn',    provider: 'Dr. K. Chen',   defaultChecked: true  },
  { id: 7,  name: 'Emily Foster',   mrn: '#005544', type: 'Flu shot',           due: 'OVERDUE',    overdue: true,  last: 'Email — 10/15/2025',  attempts: 2, status: 'No response',             tone: 'danger',  provider: 'NP Jones',      defaultChecked: true  },
  { id: 8,  name: 'James Brown',    mrn: '#002188', type: 'BP recheck',         due: '05/10/2026', overdue: false, last: 'Portal — 04/28/2026', attempts: 1, status: 'Scheduled 05/09',         tone: 'good',    provider: 'Dr. K. Chen',   defaultChecked: false },
  { id: 9,  name: 'Helen Garcia',   mrn: '#003021', type: 'Pap smear',          due: '05/30/2026', overdue: false, last: '—',                   attempts: 0, status: 'Pending outreach',        tone: 'neutral', provider: 'Dr. R. Patel',  defaultChecked: true  },
  { id: 10, name: 'Mike Tan',       mrn: '#003456', type: 'Diabetes f/u',       due: '05/18/2026', overdue: false, last: 'Letter — 04/10/2026', attempts: 2, status: 'Sent · awaiting',   tone: 'warn',    provider: 'Dr. K. Chen',   defaultChecked: true  },
  { id: 11, name: 'Robert Hayes',   mrn: '#001821', type: 'Annual physical',    due: '06/12/2026', overdue: false, last: '—',                   attempts: 0, status: 'Pending outreach',        tone: 'neutral', provider: 'NP Jones',      defaultChecked: false },
  { id: 12, name: 'Soo-Yeon Kim',   mrn: '#005903', type: 'New patient intake', due: '05/05/2026', overdue: false, last: 'Email — 04/30/2026',  attempts: 1, status: 'Sent · awaiting',   tone: 'warn',    provider: 'Dr. R. Patel',  defaultChecked: true  },
];

// Filter dropdowns — values match the PHP version's option set.
const RECALL_TYPES = ['All types', 'Annual physical', 'Mammogram', 'Colonoscopy', 'Lab', 'Imaging', 'Flu shot', 'Pap smear', 'DEXA scan', 'BP recheck', 'Diabetes f/u', 'Cholesterol', 'New patient intake'] as const;
const DUE_WINDOWS = ['All', 'Overdue', 'This week', 'Next 30 days', 'Next 90 days'] as const;
const PROVIDERS = ['All providers', 'Dr. E. Rivera', 'Dr. K. Chen', 'Dr. R. Patel', 'NP Jones'] as const;
const LAST_CONTACTS = ['Any', 'Never contacted', 'Last 7 days', 'Last 30 days', 'Over 30 days ago'] as const;
const STATUSES = ['All statuses', 'Pending', 'Overdue', 'Sent · awaiting', 'Scheduled', 'No response', 'Refused', 'Pending outreach', 'LM left voicemail'] as const;

type RecallsProps = {
  readonly boot: BootContext;
};

export function Recalls(_props: RecallsProps): JSX.Element {
  const [checked, setChecked] = useState<ReadonlySet<number>>(
    () => new Set(RECALLS.filter((r) => r.defaultChecked).map((r) => r.id)),
  );

  const selectedCount = checked.size;
  const allChecked = selectedCount === RECALLS.length;

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
      if (prev.size === RECALLS.length) {
        return new Set();
      }
      return new Set(RECALLS.map((r) => r.id));
    });
  };

  return (
    <>
      <header className={styles.pageHead}>
        <div className={styles.titleRow}>
          <span className={styles.title}>Recalls</span>
          <span className={styles.dot}>{'•'}</span>
          <span className={styles.meta}>
            127 patients due {'·'} 23 overdue {'·'} 18 contacted this week
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
          />
        </div>
        <FilterSelect label="Recall type"  options={RECALL_TYPES}   defaultValue="All types"     width={176} />
        <FilterSelect label="Due window"   options={DUE_WINDOWS}    defaultValue="Next 30 days"  width={172} />
        <FilterSelect label="Provider"     options={PROVIDERS}      defaultValue="All providers" width={172} />
        <FilterSelect label="Last contact" options={LAST_CONTACTS}  defaultValue="Any"           width={136} />
        <FilterSelect label="Status"       options={STATUSES}       defaultValue="Pending"       width={136} />
      </div>

      <div className={styles.kpiWrap}>
        <div className={styles.kpiCard}>
          <Kpi label="Overdue"              value="23"     valueClass="red"    sub="+5 vs last week" />
          <Kpi label="Due this week"        value="41"     valueClass="orange" sub="12 contacted" />
          <Kpi label="Due this month"       value="127"    valueClass=""       sub="68 contacted" />
          <Kpi label="Response rate"        value="62%"    valueClass="green"  sub={`${'↑'} from 54%`} />
          <Kpi label="Avg time to schedule" value="4.2 d"  valueClass=""       sub={`${'↓'} from 5.8 d`} last />
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
            {RECALLS.map((r, idx) => {
              const isChecked = checked.has(r.id);
              const rowClass = idx % 2 === 1 ? `${styles.tr} ${styles.trAlt}` : styles.tr;
              return (
                <tr key={r.id} className={rowClass}>
                  <td className={styles.colCb}>
                    <button
                      type="button"
                      className={`${styles.cb} ${isChecked ? styles.cbOn : ''}`}
                      onClick={() => toggleRow(r.id)}
                      aria-label={isChecked ? `Deselect ${r.name}` : `Select ${r.name}`}
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
                      <span className={styles.patientName}>{r.name}</span>
                    </span>
                  </td>
                  <td className={styles.mrn}>{r.mrn}</td>
                  <td className={styles.recallType}>{r.type}</td>
                  <td className={r.overdue ? styles.dueOverdue : styles.due}>{r.due}</td>
                  <td className={styles.lastContact}>{r.last}</td>
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
            })}
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
  readonly defaultValue: string;
  readonly width: number;
};

function FilterSelect({ label, options, defaultValue, width }: FilterSelectProps): JSX.Element {
  const [value, setValue] = useState<string>(defaultValue);
  return (
    <label className={styles.select} style={{ width }}>
      <span className={styles.selectLabel}>{label}</span>
      <span className={styles.selectValue}>
        <span>{value}</span>
        <span className={styles.selectCaret} aria-hidden="true">{'▾'}</span>
      </span>
      <select
        className={styles.selectNative}
        value={value}
        onChange={(e) => setValue(e.target.value)}
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
