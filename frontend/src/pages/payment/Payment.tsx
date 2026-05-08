// Payment — Figma "Screen 58 — Payment Intake" (node 107:2).
//
// 1:1 React port of /interface/billing/copilot_payment.php. The original
// page hits live `billing` / `ar_activity` / `form_encounter` rows for the
// active patient; the React port keeps the same UX shape with a hardcoded
// representative dataset matching the Figma exactly. Every interaction the
// PHP exposed is wired:
//   - Tender selection (radio: card / cash / check / wire / hsa).
//   - Per-charge allocation inputs (parsed loosely, capped at the line balance).
//   - Per-row checkbox state (rows with $0 applied get the unchecked styling).
//   - Note input.
//   - "Or use new card" fields (card number, expiry, CVC, ZIP).
//   - Email-receipt checkbox + email field.
//   - Cancel link → /interface/main/copilot_mock_index.php.
//   - Charge button → POST to copilot_payment.php (TODO: real endpoint).
//
// On submit we POST a multipart/form-encoded payload to copilot_payment.php
// to preserve the original PHP contract (action=charge_payment, method, note,
// allocations[<billing_id>] = "$N.NN", plus the new-card fields). The PHP
// backend is untouched today and still writes ar_session + ar_activity rows.
//
// TODO: replace copilot_payment.php with a typed JSON endpoint
//       (e.g. /apis/copilot/billing/payments) once a vault integration is in.

import { useMemo, useState } from 'react';
import type { BootContext } from '../../shared/lib/bootContext';
import styles from './Payment.module.css';

type TenderKey = 'card' | 'cash' | 'check' | 'wire' | 'hsa';

type Charge = {
  readonly billingId: number;
  readonly encounterId: number;
  readonly dos: string;
  readonly chargeLabel: string;
  readonly encLine: string;
  readonly cpt: string;
  readonly total: number;
  readonly paid: number;
  readonly balance: number;
  readonly tag: 'Insurance' | null;
};

// Hardcoded representative dataset — matches the Figma node 107:2 exactly,
// and matches what the original PHP query returned for the demo patient
// (Margaret Chen) on 2026-04-30 / 2026-02-18.
const CHARGES: readonly Charge[] = [
  {
    billingId: 1001,
    encounterId: 2891,
    dos: '04/30/2026',
    chargeLabel: '99214 Office visit, level 4',
    encLine: 'Encounter #2891 · Pt resp',
    cpt: '99214',
    total: 140.0,
    paid: 0.0,
    balance: 140.0,
    tag: null,
  },
  {
    billingId: 1002,
    encounterId: 2891,
    dos: '04/30/2026',
    chargeLabel: 'Lab — HbA1c (84443)',
    encLine: 'Encounter #2891 · Pt resp',
    cpt: 'Lab',
    total: 48.0,
    paid: 0.0,
    balance: 48.0,
    tag: null,
  },
  {
    billingId: 1003,
    encounterId: 2701,
    dos: '02/18/2026',
    chargeLabel: '99396 Annual physical',
    encLine: 'Encounter #2701 · Pt copay',
    cpt: '99396',
    total: 280.0,
    paid: 240.0,
    balance: 40.0,
    tag: null,
  },
  {
    billingId: 1004,
    encounterId: 2701,
    dos: '02/18/2026',
    chargeLabel: 'Mammogram screening',
    encLine: 'Encounter #2701 · Awaiting payer',
    cpt: 'Mammogram',
    total: 612.5,
    paid: 0.0,
    balance: 612.5,
    tag: 'Insurance',
  },
];

const TENDERS: readonly { readonly key: TenderKey; readonly icon: string; readonly label: string }[] = [
  { key: 'card',  icon: '💳', label: 'Card' },
  { key: 'cash',  icon: '💵', label: 'Cash' },
  { key: 'check', icon: '📝', label: 'Check' },
  { key: 'wire',  icon: '🏦', label: 'Wire' },
  { key: 'hsa',   icon: '🏥', label: 'HSA' },
];

const PATIENT_NAME = 'Margaret Chen';
const PATIENT_EMAIL = 'm.chen@example.com';
const CARD_LABEL = 'Visa ending in •• 4291';
const CARD_SUB = `Exp 08/2027 · ${PATIENT_NAME}`;
const CANCEL_HREF = '/interface/main/copilot_mock_index.php';
// PHP endpoint preserved for the React port. TODO: replace with
// /apis/copilot/billing/payments once a typed JSON contract exists.
const POST_ACTION = '/interface/billing/copilot_payment.php';

function formatMoney(n: number): string {
  return n.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

// Loose currency parser that mirrors the PHP's `preg_replace('/[^0-9.]/', ...)`.
// "" / "$" / non-numeric input → 0.
function parseAmount(raw: string): number {
  const cleaned = raw.replace(/[^0-9.]/g, '');
  if (cleaned === '' || cleaned === '.') {
    return 0;
  }
  const n = Number.parseFloat(cleaned);
  return Number.isFinite(n) && n > 0 ? n : 0;
}

type PaymentProps = {
  readonly boot: BootContext;
};

export function Payment(_props: PaymentProps): JSX.Element {
  // Allocation state — billing_id (string) → entered text. Seeded the same
  // way PHP did: pre-apply against the first three non-Insurance charges,
  // capped at 3, leaving Insurance-tagged rows at $0.
  const initialAllocations: Record<string, string> = {};
  let seeded = 0;
  for (const c of CHARGES) {
    const k = String(c.billingId);
    if (seeded >= 3 || c.tag === 'Insurance') {
      initialAllocations[k] = '$0.00';
    } else {
      initialAllocations[k] = `$${formatMoney(c.balance)}`;
      seeded += 1;
    }
  }

  const [tender, setTender] = useState<TenderKey>('card');
  const [allocations, setAllocations] = useState<Record<string, string>>(initialAllocations);
  const [note, setNote] = useState<string>('Visit copay + lab self-pay portion');
  const [cardNumber, setCardNumber] = useState<string>('');
  const [cardExp, setCardExp] = useState<string>('');
  const [cardCvc, setCardCvc] = useState<string>('');
  const [cardZip, setCardZip] = useState<string>('');
  const [emailReceipt, setEmailReceipt] = useState<boolean>(true);
  const [receiptEmail, setReceiptEmail] = useState<string>(PATIENT_EMAIL);
  const [flash, setFlash] = useState<string | null>(null);

  // Cap each line at its own balance (mirrors PHP `min($amt, $cap)`).
  const cappedByLine = useMemo<Record<string, number>>(() => {
    const out: Record<string, number> = {};
    for (const c of CHARGES) {
      const k = String(c.billingId);
      const raw = parseAmount(allocations[k] ?? '');
      out[k] = Math.min(raw, c.balance);
    }
    return out;
  }, [allocations]);

  const totalApply = useMemo<number>(() => {
    let t = 0;
    for (const c of CHARGES) {
      t += cappedByLine[String(c.billingId)] ?? 0;
    }
    return Math.round(t * 100) / 100;
  }, [cappedByLine]);

  const totalBalance = CHARGES.reduce((s, c) => s + c.balance, 0);

  const handleSubmit = async (e: React.FormEvent<HTMLFormElement>): Promise<void> => {
    e.preventDefault();
    if (totalApply <= 0) {
      setFlash('Nothing to charge — allocate at least one amount above $0.');
      return;
    }
    const fd = new FormData();
    fd.set('action', 'charge_payment');
    fd.set('method', tender);
    fd.set('note', note);
    for (const c of CHARGES) {
      const k = String(c.billingId);
      const v = allocations[k] ?? '';
      fd.set(`allocations[${k}]`, v);
    }
    fd.set('card_number', cardNumber);
    fd.set('card_exp', cardExp);
    fd.set('card_cvc', cardCvc);
    fd.set('card_zip', cardZip);
    if (emailReceipt) {
      fd.set('receipt_email', receiptEmail);
    }
    try {
      // TODO: swap to a typed JSON endpoint once the vault integration ships.
      await fetch(POST_ACTION, { method: 'POST', body: fd, credentials: 'same-origin' });
      setFlash(`Payment posted: $${formatMoney(totalApply)}`);
    } catch {
      setFlash('Could not reach the payment endpoint.');
    }
  };

  return (
    <>
      <header className={styles.pagehead}>
        <div className={styles.pageheadInfo}>
          <span className={styles.pageheadTitle}>Payment Intake</span>
          <span className={styles.pageheadBullet}>•</span>
          <span className={styles.pageheadMeta}>Post a payment for {PATIENT_NAME}</span>
        </div>
        <button type="button" className={styles.helpBtn}>? Help</button>
      </header>

      {flash !== null && <div className={styles.flash}>{flash}</div>}

      <form method="post" action={POST_ACTION} className={styles.shell} onSubmit={handleSubmit}>
        <input type="hidden" name="action" value="charge_payment" />

        {/* LEFT COLUMN ────────────────────────────────────────────── */}
        <div className={styles.col}>
          <section className={styles.section}>
            <div className={styles.hd}>
              <div className={styles.lbl}>OPEN BALANCES</div>
              <div className={styles.sub}>
                ${formatMoney(totalBalance)} total · {CHARGES.length} charges
              </div>
            </div>
            <table className={styles.tbl}>
              <thead>
                <tr>
                  <th style={{ width: 32 }}></th>
                  <th style={{ width: 90 }}>DOS</th>
                  <th>CHARGE</th>
                  <th style={{ width: 90 }}>CPT</th>
                  <th style={{ width: 80 }} className={styles.r}>TOTAL</th>
                  <th style={{ width: 90 }} className={styles.r}>PAYMENTS</th>
                  <th style={{ width: 90 }} className={styles.r}>BALANCE</th>
                </tr>
              </thead>
              <tbody>
                {CHARGES.map((c) => {
                  const k = String(c.billingId);
                  const isApplied = (cappedByLine[k] ?? 0) > 0;
                  const rowCls = isApplied ? '' : styles.unchecked;
                  const checkCls = isApplied
                    ? `${styles.check} ${styles.checkOn}`
                    : styles.check;
                  return (
                    <tr key={c.billingId} className={rowCls}>
                      <td><span className={checkCls} aria-hidden="true" /></td>
                      <td className={`${styles.muted} ${styles.nowrap}`}>{c.dos}</td>
                      <td>
                        <div className={styles.charge}>{c.chargeLabel}</div>
                        <div className={styles.enc}>{c.encLine}</div>
                      </td>
                      <td className={styles.muted}>{c.cpt}</td>
                      <td className={`${styles.r} ${styles.muted}`}>${formatMoney(c.total)}</td>
                      <td className={`${styles.r} ${styles.muted}`}>${formatMoney(c.paid)}</td>
                      <td className={`${styles.r} ${styles.bold}`}>
                        ${formatMoney(c.balance)}
                        {c.tag !== null && (
                          <div style={{ marginTop: 6 }}>
                            <span className={styles.tag}>{c.tag}</span>
                          </div>
                        )}
                      </td>
                    </tr>
                  );
                })}
              </tbody>
            </table>
          </section>

          <section className={styles.section}>
            <div className={styles.hd}>
              <div className={styles.lbl}>PAYMENT ALLOCATION</div>
            </div>
            <table className={styles.tbl}>
              <thead>
                <tr>
                  <th>CHARGE</th>
                  <th style={{ width: 120 }} className={styles.r}>BALANCE</th>
                  <th style={{ width: 130 }} className={styles.r}>APPLIED</th>
                  <th style={{ width: 130 }} className={styles.r}>REMAINING</th>
                </tr>
              </thead>
              <tbody>
                {CHARGES.map((c) => {
                  const k = String(c.billingId);
                  const applied = cappedByLine[k] ?? 0;
                  const remaining = Math.round((c.balance - applied) * 100) / 100;
                  const value = allocations[k] ?? '';
                  return (
                    <tr key={c.billingId}>
                      <td>{c.chargeLabel}</td>
                      <td className={`${styles.r} ${styles.muted}`}>${formatMoney(c.balance)}</td>
                      <td className={styles.r}>
                        <input
                          type="text"
                          name={`allocations[${k}]`}
                          className={styles.allocInput}
                          value={value}
                          onChange={(e) => setAllocations((prev) => ({ ...prev, [k]: e.target.value }))}
                          style={{ textAlign: 'left' }}
                        />
                      </td>
                      <td className={styles.r}>
                        <span className={styles.rem}>${formatMoney(remaining)}</span>
                      </td>
                    </tr>
                  );
                })}
                <tr className={styles.totalRow}>
                  <td>Total to apply</td>
                  <td className={`${styles.r} ${styles.muted}`}></td>
                  <td className={styles.r} style={{ paddingRight: 24 }}>${formatMoney(totalApply)}</td>
                  <td className={`${styles.r} ${styles.muted}`}>$0.00</td>
                </tr>
              </tbody>
            </table>
            <div className={styles.note}>
              <label htmlFor="pi-note">Note:</label>
              <input
                id="pi-note"
                type="text"
                name="note"
                value={note}
                onChange={(e) => setNote(e.target.value)}
              />
            </div>
          </section>
        </div>

        {/* RIGHT COLUMN ───────────────────────────────────────────── */}
        <aside className={styles.right}>
          <div>
            <div className={styles.grpLbl}>PAYMENT TENDER</div>
            <div className={styles.tender}>
              {TENDERS.map((t) => {
                const active = t.key === tender;
                const cls = active ? `${styles.tenderItem} ${styles.tenderItemActive}` : styles.tenderItem;
                return (
                  <label key={t.key} className={cls}>
                    <input
                      type="radio"
                      name="method"
                      value={t.key}
                      checked={active}
                      onChange={() => setTender(t.key)}
                    />
                    <span className={styles.tenderIco}>{t.icon}</span> {t.label}
                  </label>
                );
              })}
            </div>
          </div>

          <div>
            <div className={styles.grpLbl}>CARD ON FILE</div>
            <div className={styles.cardOnFile}>
              <div className={styles.cardThumb} aria-hidden="true" />
              <div className={styles.cardInfo}>
                <div className={styles.cardL1}>{CARD_LABEL}</div>
                <div className={styles.cardL2}>{CARD_SUB}</div>
              </div>
              <span className={styles.cardPill}>On file</span>
            </div>
          </div>

          <div>
            <div className={styles.grpLbl}>OR USE NEW CARD</div>
            <div className={styles.newCardBox}>
              <div className={styles.fld}>
                <label htmlFor="card-num">Card number</label>
                <input
                  id="card-num"
                  type="text"
                  name="card_number"
                  placeholder="4242 4242 4242 4242"
                  autoComplete="off"
                  value={cardNumber}
                  onChange={(e) => setCardNumber(e.target.value)}
                />
              </div>
              <div className={styles.fldRow}>
                <div className={styles.fld}>
                  <label htmlFor="card-exp">Expiry</label>
                  <input
                    id="card-exp"
                    type="text"
                    name="card_exp"
                    placeholder="MM / YY"
                    autoComplete="off"
                    value={cardExp}
                    onChange={(e) => setCardExp(e.target.value)}
                  />
                </div>
                <div className={styles.fld}>
                  <label htmlFor="card-cvc">CVC</label>
                  <input
                    id="card-cvc"
                    type="text"
                    name="card_cvc"
                    placeholder="CVC"
                    autoComplete="off"
                    value={cardCvc}
                    onChange={(e) => setCardCvc(e.target.value)}
                  />
                </div>
                <div className={styles.fld}>
                  <label htmlFor="card-zip">ZIP</label>
                  <input
                    id="card-zip"
                    type="text"
                    name="card_zip"
                    placeholder="ZIP"
                    autoComplete="off"
                    value={cardZip}
                    onChange={(e) => setCardZip(e.target.value)}
                  />
                </div>
              </div>
            </div>
          </div>

          <div className={styles.receipt}>
            <button
              type="button"
              role="checkbox"
              aria-checked={emailReceipt}
              className={emailReceipt ? `${styles.check} ${styles.checkOn} ${styles.checkSm}` : `${styles.check} ${styles.checkSm}`}
              onClick={() => setEmailReceipt((v) => !v)}
            />
            <span>Email receipt to</span>
            <input
              type="email"
              name="receipt_email"
              value={receiptEmail}
              onChange={(e) => setReceiptEmail(e.target.value)}
              placeholder="email@example.com"
            />
          </div>

          <div className={styles.amount}>
            <div>
              <div className={styles.amountLbl}>AMOUNT</div>
              <div className={styles.amountVal}>${formatMoney(totalApply)}</div>
            </div>
            <div className={styles.amountRight}>
              <div className={styles.amountL1}>{CARD_LABEL}</div>
              <div className={styles.amountL2}>Stripe processor · ~2 sec</div>
            </div>
          </div>

          <div className={styles.actions}>
            <a className={styles.cancel} href={CANCEL_HREF}>Cancel</a>
            <button type="submit" className={styles.chargeBtn}>
              <span aria-hidden="true">💳</span> Charge ${formatMoney(totalApply)}
            </button>
          </div>
        </aside>
      </form>
    </>
  );
}
