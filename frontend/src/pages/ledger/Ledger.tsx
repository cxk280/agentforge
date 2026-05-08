// Ledger — Figma "Screen 18 — Ledger" (file kj4MWNr8mpjZ2wVg1PbS0F, node 39:2).
//
// Renders the patient billing ledger: dark navy summary banner with
// outstanding balance, patient aging buckets and stacked bar, plus
// Collect Payment / Generate Statement actions; below is the line-item
// ledger table (debit / credit / insurance / running balance).
//
// DB-backed: the PHP wrapper at /interface/patient_file/ledger/copilot_ledger.php
// composes charges (billing) and payments / adjustments (ar_activity +
// ar_session + insurance_companies) into a single LedgerPayload, JSON-encodes
// it onto data-ledger on #cp-root, and the entry index.tsx parses it and
// passes it here. With no active patient or no activity, the React tree
// falls back to the static demo dataset so the Figma frame stays browsable.

import type { BootContext } from '../../shared/lib/bootContext';
import styles from './Ledger.module.css';

export type AgingBucket = {
  readonly range: string;
  readonly amount: string;
  readonly value: number;
};

export type LedgerRow = {
  readonly date: string;
  readonly txn: string;
  readonly desc: string;
  readonly debit: string;
  readonly credit: string;
  readonly ins: string;
  readonly bal: string;
  readonly outstanding?: boolean | undefined;
};

export type LedgerPayload = {
  readonly outstandingBalance: string;
  readonly lastActivity: string;
  readonly aging: readonly AgingBucket[];
  readonly rows: readonly LedgerRow[];
};

// Static demo dataset, shown only when the PHP wrapper hands us no rows
// (typically: no active patient pid in the session, or a freshly seeded
// patient with no charges yet). Keeps the Figma mock rendering identically
// in those cases.
const DEMO_AGING: readonly AgingBucket[] = [
  { range: '0–30',  amount: '$0.00',     value: 0 },
  { range: '31–60', amount: '$0.00',     value: 0 },
  { range: '61–90', amount: '$98.00',    value: 98.00 },
  { range: '91+',   amount: '$1,205.60', value: 1205.60 },
];

const DEMO_ROWS: readonly LedgerRow[] = [
  { date: '04/12/2026', txn: 'TX-9402', desc: 'Office visit (99213)',           debit: '$152.00', credit: '—',       ins: 'BCBS PPO • Pending', bal: '$1,303.60' },
  { date: '04/12/2026', txn: 'TX-9403', desc: 'BCBS payment — claim BC-99281',  debit: '—',       credit: '$152.00', ins: 'BCBS PPO • Paid',    bal: '$1,303.60' },
  { date: '02/18/2026', txn: 'TX-9311', desc: 'Annual wellness visit (99396)',  debit: '$280.00', credit: '—',       ins: 'BCBS PPO • Paid',    bal: '$1,303.60' },
  { date: '02/18/2026', txn: 'TX-9312', desc: 'BCBS payment — claim BC-99020',  debit: '—',       credit: '$280.00', ins: '—',                  bal: '$1,303.60' },
  { date: '02/18/2026', txn: 'TX-9313', desc: 'Lab panel (80050)',              debit: '$184.00', credit: '—',       ins: 'BCBS PPO • Paid',    bal: '$1,303.60' },
  { date: '02/18/2026', txn: 'TX-9314', desc: 'BCBS payment — claim BC-99021',  debit: '—',       credit: '$139.00', ins: '—',                  bal: '$1,348.60' },
  { date: '02/18/2026', txn: 'TX-9315', desc: 'Patient copay collected — visa', debit: '—',       credit: '$45.00',  ins: '—',                  bal: '$1,303.60' },
  { date: '11/15/2025', txn: 'TX-9112', desc: 'Office visit (99212)',           debit: '$98.00',  credit: '—',       ins: 'BCBS PPO • Pending', bal: '$1,303.60', outstanding: true },
  { date: '08/22/2025', txn: 'TX-8821', desc: 'Telehealth visit (99214)',       debit: '$156.00', credit: '—',       ins: 'BCBS PPO • Paid',    bal: '$1,205.60' },
  { date: '08/22/2025', txn: 'TX-8822', desc: 'BCBS payment — claim BC-90422',  debit: '—',       credit: '$108.00', ins: '—',                  bal: '$1,205.60' },
  { date: '08/22/2025', txn: 'TX-8823', desc: 'Contractual write-off (BCBS)',   debit: '—',       credit: '$48.00',  ins: '—',                  bal: '$1,157.60' },
];

const DEMO_PAYLOAD: LedgerPayload = {
  outstandingBalance: '$1,303.60',
  lastActivity:       '04/12/2026',
  aging:              DEMO_AGING,
  rows:               DEMO_ROWS,
};

type LedgerProps = {
  readonly boot: BootContext;
  readonly ledger?: LedgerPayload | undefined;
};

export function Ledger({ ledger }: LedgerProps): JSX.Element {
  // Fall back to the demo dataset when the wrapper hands us no rows so the
  // Figma frame keeps rendering even on patients with no billing history.
  const payload: LedgerPayload =
    ledger && ledger.rows.length > 0 ? ledger : DEMO_PAYLOAD;

  const aging = payload.aging.length > 0 ? payload.aging : DEMO_AGING;
  const agingTotal = aging.reduce((sum, b) => sum + b.value, 0);

  return (
    <>
      <header className={styles.banner}>
        <div className={styles.balance}>
          <div className={styles.label}>OUTSTANDING BALANCE</div>
          <div className={styles.balanceVal}>
            {payload.outstandingBalance} <span className={styles.usd}>USD</span>
          </div>
          <div className={styles.sub}>
            {payload.lastActivity !== ''
              ? `Last activity ${payload.lastActivity} • Patient responsibility`
              : 'No recent activity • Patient responsibility'}
          </div>
        </div>

        <div className={styles.aging}>
          <div className={styles.label}>AGING (PATIENT)</div>
          <div className={styles.buckets}>
            {aging.map((b) => (
              <div key={b.range} className={styles.bucketCol}>
                <div className={styles.bucketTop}>{b.range}</div>
                <div className={styles.bucketAmt}>{b.amount}</div>
              </div>
            ))}
          </div>
          <div className={styles.bar}>
            {aging.map((b, i) => {
              if (b.value <= 0 || agingTotal <= 0) {
                return null;
              }
              const pct = (b.value / agingTotal) * 100;
              const segClass = i === 3 ? `${styles.seg} ${styles.seg4}` : styles.seg;
              return (
                <div
                  key={b.range}
                  className={segClass}
                  style={{ width: `${pct.toFixed(2)}%` }}
                />
              );
            })}
          </div>
        </div>

        <div className={styles.actions}>
          <button type="button" className={styles.priBtn}>Collect Payment</button>
          <button type="button" className={styles.secBtn}>Generate Statement</button>
        </div>
      </header>

      <section className={styles.tableWrap}>
        <table className={styles.table}>
          <thead>
            <tr>
              <th className={styles.colDate}>DATE</th>
              <th className={styles.colTx}>#</th>
              <th>DESCRIPTION</th>
              <th className={styles.colAmt}>DEBIT</th>
              <th className={styles.colAmt}>CREDIT</th>
              <th className={styles.colIns}>INSURANCE</th>
              <th className={styles.colBal}>BALANCE</th>
            </tr>
          </thead>
          <tbody>
            {payload.rows.map((r) => {
              const rowClass = r.outstanding ? styles.rowOut : '';
              const creditClass = r.credit !== '—'
                ? `${styles.colAmt} ${styles.creditAmt}`
                : styles.colAmt;
              return (
                <tr key={r.txn} className={rowClass}>
                  <td className={styles.colDate}>{r.date}</td>
                  <td className={styles.colTx}>{r.txn}</td>
                  <td>
                    {r.desc}
                    {r.outstanding === true && (
                      <span className={styles.outPill}>OUTSTANDING</span>
                    )}
                  </td>
                  <td className={styles.colAmt}>{r.debit}</td>
                  <td className={creditClass}>{r.credit}</td>
                  <td className={styles.colIns}>{r.ins}</td>
                  <td className={styles.colBal}>{r.bal}</td>
                </tr>
              );
            })}
          </tbody>
        </table>
      </section>
    </>
  );
}
