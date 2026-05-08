// RecordRequest — Figma "Screen 31 — Patient Record Request". Renders the
// AgentForge Records Release page: a patient-scoped pagehead, a left-column
// "New record request" form (recipient, fax/email, record-type checkboxes,
// date range, reason, signed-HIPAA pill + Send), and a right-column queue
// card with tabs (Outbound / Inbound / Templates / Audit log), 4 KPI count
// tiles, and a list of outbound/inbound queue rows.
//
// DB-backed: the PHP wrapper at copilot_record_request.php queries the
// `transactions` + `lbt_data` tables for the current patient's records-
// release queue, derives KPI counts, and pulls recipient names from the
// `pharmacies` table (or `procedure_providers` as a fallback). The result
// is JSON-encoded onto data-records on #cp-root and parsed by index.tsx,
// which passes the typed payload to this component as the `records` prop.
//
// Verified against Figma node 74:2 on 2026-05-07.

import { useMemo, useState } from 'react';
import type { BootContext } from '../../shared/lib/bootContext';
import styles from './RecordRequest.module.css';

type TabKey = 'outbound' | 'inbound' | 'templates' | 'audit';
type Tone = 'warn' | 'good' | 'danger' | 'info';
type Direction = 'out' | 'in';

type RecordCheck = {
  readonly label: string;
  readonly checked: boolean;
};

type CountTile = {
  readonly value: string;
  readonly label: string;
  readonly tone: Tone | 'dark';
};

// Server-side queue row shape (mirrors the PHP query in copilot_record_request.php).
export type RecordQueueRow = {
  readonly direction: Direction;
  readonly dest: string;
  readonly sub: string;
  readonly sentLabel: string;
  readonly sentDate: string;
  readonly status: string;
  readonly tone: Tone;
};

export type RecordRequestPayload = {
  readonly patientName: string;
  readonly recipients: readonly string[];
  readonly queue: readonly RecordQueueRow[];
  readonly kpis: {
    readonly active: number;
    readonly awaiting: number;
    readonly completed30: number;
    readonly failed: number;
  };
};

type TabDef = {
  readonly key: TabKey;
  readonly label: string;
};

const TABS: readonly TabDef[] = [
  { key: 'outbound',  label: 'Outbound' },
  { key: 'inbound',   label: 'Inbound' },
  { key: 'templates', label: 'Templates' },
  { key: 'audit',     label: 'Audit log' },
];

// Compliance taxonomy — labels are static; the checked-state is the visual
// default (matches the original PHP page).
const RECORDS: readonly RecordCheck[] = [
  { label: 'Office visit notes',     checked: true  },
  { label: 'Lab results',            checked: true  },
  { label: 'Imaging reports',        checked: true  },
  { label: 'Cardiology records',     checked: true  },
  { label: 'Medication history',     checked: false },
  { label: 'Immunization history',   checked: false },
  { label: 'Mental health notes',    checked: false },
  { label: 'Substance use treatment', checked: false },
];

type RecordRequestProps = {
  readonly boot: BootContext;
  readonly records: RecordRequestPayload;
};

export function RecordRequest({ records }: RecordRequestProps): JSX.Element {
  const [tab, setTab] = useState<TabKey>('outbound');
  const recipients = records.recipients;
  const [recipient, setRecipient] = useState<string>(recipients[0] ?? '');
  const [checks, setChecks] = useState<readonly boolean[]>(
    RECORDS.map((r) => r.checked),
  );

  const toggleCheck = (i: number): void => {
    setChecks((prev) => prev.map((v, idx) => (idx === i ? !v : v)));
  };

  // Tabs filter the queue: Outbound shows direction='out', Inbound shows
  // direction='in', and Templates/Audit are empty placeholders (no DB
  // backing for those views yet — same as the .bak which only honored
  // outbound / inbound on the server).
  const visibleQueue = useMemo<readonly RecordQueueRow[]>(() => {
    if (tab === 'outbound') return records.queue.filter((q) => q.direction === 'out');
    if (tab === 'inbound')  return records.queue.filter((q) => q.direction === 'in');
    return [];
  }, [tab, records.queue]);

  const counts: readonly CountTile[] = [
    { value: String(records.kpis.active),       label: 'Active',          tone: 'dark'   },
    { value: String(records.kpis.awaiting),     label: 'Awaiting reply',  tone: 'warn'   },
    { value: String(records.kpis.completed30),  label: 'Completed (30d)', tone: 'good'   },
    { value: String(records.kpis.failed),       label: 'Failed',          tone: 'danger' },
  ];

  const metaSuffix = records.kpis.active === 1 ? '1 active' : `${records.kpis.active} active`;
  const patientPrefix = records.patientName !== '' ? `${records.patientName} · ` : '';

  return (
    <>
      <header className={styles.pagehead}>
        <div className={styles.headInfo}>
          <span className={styles.title}>Records Release</span>
          <span className={styles.dot}>·</span>
          <span className={styles.metaLight}>{patientPrefix}Outbound chart requests · {metaSuffix}</span>
        </div>
        <button type="button" className={styles.helpPill}>? Help</button>
      </header>

      <div className={styles.body}>
        {/* Left column — New Record Request form */}
        <section className={styles.leftCol}>
          <div className={styles.labelTag}>NEW RECORD REQUEST</div>

          <div className={styles.field}>
            <label className={styles.fieldHead} htmlFor="rr-recipient">Recipient</label>
            <span className={styles.fieldSub}>Send to</span>
            <div className={styles.selectWrap}>
              <select
                id="rr-recipient"
                className={styles.input}
                value={recipient}
                onChange={(e) => setRecipient(e.target.value)}
              >
                {recipients.length === 0 ? (
                  <option value="">— No recipients available —</option>
                ) : (
                  recipients.map((r) => (
                    <option key={r} value={r}>{r}</option>
                  ))
                )}
              </select>
              <span className={styles.selectCaret} aria-hidden="true">▾</span>
            </div>
          </div>

          <div className={styles.fieldRow}>
            <div className={styles.field}>
              <label className={styles.fieldSub} htmlFor="rr-fax">Fax</label>
              <input id="rr-fax" className={styles.input} type="text" defaultValue="(512) 555-7741" />
            </div>
            <div className={styles.field}>
              <label className={styles.fieldSub} htmlFor="rr-email">Email (optional)</label>
              <input id="rr-email" className={styles.input} type="text" defaultValue="records@caa-tx.com" />
            </div>
          </div>

          <div className={styles.field}>
            <div className={styles.fieldHead}>Records to release</div>
            <div className={styles.checks}>
              {RECORDS.map((r, i) => {
                const on = checks[i] ?? false;
                const cls = on ? `${styles.chk}` : `${styles.chk} ${styles.chkUnchecked}`;
                const boxCls = on ? `${styles.chkBox} ${styles.chkBoxOn}` : styles.chkBox;
                return (
                  <label key={r.label} className={cls}>
                    <input
                      type="checkbox"
                      checked={on}
                      onChange={() => toggleCheck(i)}
                    />
                    <span className={boxCls} />
                    {r.label}
                  </label>
                );
              })}
            </div>
          </div>

          <div className={styles.field}>
            <div className={styles.fieldHead}>Date range</div>
            <div className={styles.fieldRow}>
              <div className={styles.field}>
                <label className={styles.fieldSub} htmlFor="rr-from">From</label>
                <input id="rr-from" className={styles.input} type="text" defaultValue="01/01/2024" />
              </div>
              <div className={styles.field}>
                <label className={styles.fieldSub} htmlFor="rr-to">To</label>
                <input id="rr-to" className={styles.input} type="text" defaultValue="05/02/2026" />
              </div>
            </div>
          </div>

          <div className={styles.field}>
            <label className={styles.fieldHead} htmlFor="rr-reason">Reason for request</label>
            <textarea
              id="rr-reason"
              className={styles.textarea}
              rows={3}
              defaultValue="Continuing care — pt referred to cardiology for elevated BP work-up and palpitations during last visit."
            />
          </div>

          <div className={styles.authLabel}>AUTHORIZATION</div>
          <div className={styles.authRow}>
            <div className={styles.authPill}>
              <span className={styles.authCheck} aria-hidden="true">✓</span>
              Signed HIPAA release on file (04/30/2026)
            </div>
            <button type="button" className={styles.sendBtn}>Send request</button>
          </div>
        </section>

        {/* Right column — outbound queue */}
        <section className={styles.rightCol}>
          <div className={styles.tabs} role="tablist">
            {TABS.map((t) => {
              const active = t.key === tab;
              const cls = active ? `${styles.tab} ${styles.tabActive}` : styles.tab;
              return (
                <button
                  key={t.key}
                  className={cls}
                  role="tab"
                  type="button"
                  aria-selected={active}
                  onClick={() => setTab(t.key)}
                >
                  {t.label}
                </button>
              );
            })}
          </div>

          <div className={styles.counts}>
            {counts.map((c) => {
              const valCls = c.tone === 'dark'
                ? styles.countV
                : `${styles.countV} ${styles[`countV_${c.tone}`] ?? ''}`;
              return (
                <div key={c.label} className={styles.count}>
                  <span className={valCls}>{c.value}</span>
                  <span className={styles.countL}>{c.label}</span>
                </div>
              );
            })}
          </div>

          <div className={styles.queue}>
            {visibleQueue.length === 0 ? (
              <div className={styles.qEmpty}>No record requests in this tab.</div>
            ) : (
              visibleQueue.map((q, idx) => {
                const pillCls = `${styles.pill} ${styles[`pill_${q.tone}`] ?? ''}`;
                return (
                  <div key={`${q.dest}-${idx}`} className={styles.qRow}>
                    <span className={styles.qIcon} aria-hidden="true">
                      {q.direction === 'in' ? '📥' : '📤'}
                    </span>
                    <div className={styles.qInfo}>
                      <div className={styles.qDest}>{q.dest}</div>
                      <div className={styles.qSub}>{q.sub}</div>
                      <div className={styles.qSent}>{q.sentLabel} {q.sentDate}</div>
                    </div>
                    <span className={pillCls}>{q.status}</span>
                    <a className={styles.qView} href="#">View →</a>
                  </div>
                );
              })
            )}
          </div>
        </section>
      </div>
    </>
  );
}
