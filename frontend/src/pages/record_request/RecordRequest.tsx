// RecordRequest — Figma "Screen 31 — Patient Record Request". Renders the
// AgentForge Records Release page: a patient-scoped pagehead, a left-column
// "New record request" form (recipient, fax/email, record-type checkboxes,
// date range, reason, signed-HIPAA pill + Send), and a right-column queue
// card with tabs (Outbound / Inbound / Templates / Audit log), 4 KPI count
// tiles, and a list of outbound/inbound queue rows.
//
// This is a 1:1 port of the PHP-rendered page previously at
// /interface/patient_file/transaction/copilot_record_request.php. All data
// is static demo data taken directly from the Figma mock — no DB queries,
// no POST handler. The navy top-nav and patient demographics banner are
// rendered by the parent shell, so this component only renders the page
// body — pagehead + content area.
//
// Verified against Figma node 74:2 on 2026-05-07.

import { useState } from 'react';
import type { BootContext } from '../../shared/lib/bootContext';
import styles from './RecordRequest.module.css';

type TabKey = 'outbound' | 'inbound' | 'templates' | 'audit';
type Tone = 'warn' | 'good' | 'danger' | 'info';

type RecordCheck = {
  readonly label: string;
  readonly checked: boolean;
};

type CountTile = {
  readonly value: string;
  readonly label: string;
  readonly tone: Tone | 'dark';
};

type QueueRow = {
  readonly direction: 'out' | 'in';
  readonly dest: string;
  readonly sub: string;
  readonly sentLabel: string;
  readonly sentDate: string;
  readonly status: string;
  readonly tone: Tone;
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

const RECIPIENTS: readonly string[] = [
  'Cardiology Associates of Austin (Dr. M. Sandoval)',
  'Endocrine Specialists of TX (Dr. L. Park)',
  'Imaging Center — Riverside',
  "St. David's ED",
  'Mercy Home Health',
  'Patient (self)',
];

const COUNTS: readonly CountTile[] = [
  { value: '12', label: 'Active',          tone: 'dark'   },
  { value: '4',  label: 'Awaiting reply',  tone: 'warn'   },
  { value: '7',  label: 'Completed (30d)', tone: 'good'   },
  { value: '1',  label: 'Failed',          tone: 'danger' },
];

const QUEUE: readonly QueueRow[] = [
  {
    direction: 'out',
    dest:      'Cardiology Associates of Austin',
    sub:       'Dr. M. Sandoval • Fax',
    sentLabel: 'Sent',
    sentDate:  '04/30 14:22',
    status:    'Awaiting reply',
    tone:      'warn',
  },
  {
    direction: 'out',
    dest:      'Endocrine Specialists of TX',
    sub:       'Dr. L. Park • Fax',
    sentLabel: 'Sent',
    sentDate:  '04/29 09:14',
    status:    'Delivered',
    tone:      'good',
  },
  {
    direction: 'out',
    dest:      'Imaging Center — Riverside',
    sub:       'MRI Knee R, 03/15 study',
    sentLabel: 'Sent',
    sentDate:  '04/28 16:50',
    status:    'Delivered',
    tone:      'good',
  },
  {
    direction: 'in',
    dest:      "St. David's ED",
    sub:       'Visit summary 03/22',
    sentLabel: 'Received',
    sentDate:  '04/27 10:02',
    status:    'Imported',
    tone:      'good',
  },
  {
    direction: 'out',
    dest:      'Patient (self)',
    sub:       'Portal export — full chart',
    sentLabel: 'Sent',
    sentDate:  '04/26 11:30',
    status:    'Acknowledged',
    tone:      'good',
  },
  {
    direction: 'out',
    dest:      'Mercy Home Health',
    sub:       'Care plan + meds',
    sentLabel: 'Sent',
    sentDate:  '04/25 08:15',
    status:    'Awaiting reply',
    tone:      'warn',
  },
  {
    direction: 'out',
    dest:      'Dr. P. Watson (PCP transfer)',
    sub:       'Complete chart export',
    sentLabel: 'Sent',
    sentDate:  '04/22 13:00',
    status:    'Failed — invalid fax',
    tone:      'danger',
  },
];

type RecordRequestProps = {
  readonly boot: BootContext;
};

export function RecordRequest(_props: RecordRequestProps): JSX.Element {
  const [tab, setTab] = useState<TabKey>('outbound');
  const [recipient, setRecipient] = useState<string>(RECIPIENTS[0] ?? '');
  const [checks, setChecks] = useState<readonly boolean[]>(
    RECORDS.map((r) => r.checked),
  );

  const toggleCheck = (i: number): void => {
    setChecks((prev) => prev.map((v, idx) => (idx === i ? !v : v)));
  };

  return (
    <>
      <header className={styles.pagehead}>
        <div className={styles.headInfo}>
          <span className={styles.title}>Records Release</span>
          <span className={styles.dot}>·</span>
          <span className={styles.metaLight}>Outbound chart requests · 12 active</span>
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
                {RECIPIENTS.map((r) => (
                  <option key={r} value={r}>{r}</option>
                ))}
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
            {COUNTS.map((c) => {
              const valCls = c.tone === 'dark' ? styles.countV : `${styles.countV} ${styles[`countV_${c.tone}`] ?? ''}`;
              return (
                <div key={c.label} className={styles.count}>
                  <span className={valCls}>{c.value}</span>
                  <span className={styles.countL}>{c.label}</span>
                </div>
              );
            })}
          </div>

          <div className={styles.queue}>
            {QUEUE.map((q, idx) => {
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
            })}
          </div>
        </section>
      </div>
    </>
  );
}
