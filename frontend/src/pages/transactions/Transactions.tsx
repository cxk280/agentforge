// Transactions — Figma "Screen 16 — Transactions" (file kj4MWNr8mpjZ2wVg1PbS0F,
// node 37:2). Patient-context page rendered inside the OpenEMR navtab iframe.
//
// 1:1 port of the static PHP mock that previously lived at
// /interface/patient_file/transaction/copilot_transactions.php — same KPI
// strip, filter chip row, and transactions table. Demo-only data; no
// network calls. The PHP wrapper still owns the navy top nav and the
// patient demographics banner (header2).
//
// A handful of values were re-pulled from Figma on 2026-05-07 and the PHP
// mock had drifted on a few of them — those drifts are corrected here:
//   - "Insurance Paid" KPI: #1F8C4D → #33A666 (Figma)
//   - "Total Charges" value color: #0D1B2A → #4F5763 (Figma)
//   - KPI card border-radius: 10px → 12px (Figma)
//   - Filter "All" chip: solid teal-tinted bg #E6F4F4 → translucent
//     rgba(0,140,140,0.1) (Figma)
//   - Type pills: PHP used grey "charge" + green "payment"; Figma uses
//     teal-tinted #008C8C for CHARGE, green #33A666 for PAYMENT, orange
//     #FA8C33 for ADJUSTMENT — adopted Figma values.
//   - Outstanding row balance text: black → orange #FA8C33 + bold (Figma)

import type { BootContext } from '../../shared/lib/bootContext';
import styles from './Transactions.module.css';

type KpiTone = 'neutral' | 'good' | 'info' | 'warn';

type Kpi = {
  readonly label: string;
  readonly value: string;
  readonly sub: string;
  readonly tone: KpiTone;
};

type FilterChip = {
  readonly label: string;
  readonly active: boolean;
};

type TxType = 'charge' | 'payment' | 'adjustment';

type TxRow = {
  readonly date: string;
  readonly type: TxType;
  readonly desc: string;
  readonly cpt: string;
  readonly prov: string;
  readonly ins: string;
  readonly pt: string;
  readonly bal: string;
  readonly outstanding: boolean;
  readonly insGreen?: boolean | undefined;
  readonly balRed?: boolean | undefined;
};

const KPIS: readonly Kpi[] = [
  { label: 'Total Charges',  value: '$8,420.00', sub: 'Last 12 months', tone: 'neutral' },
  { label: 'Insurance Paid', value: '$5,932.40', sub: '70% of charges', tone: 'good'    },
  { label: 'Patient Paid',   value: '$1,184.00', sub: '14% of charges', tone: 'info'    },
  { label: 'Outstanding',    value: '$1,303.60', sub: '16% — 60+ days', tone: 'warn' },
];

const FILTERS: readonly FilterChip[] = [
  { label: 'All',         active: true  },
  { label: 'Charges',     active: false },
  { label: 'Payments',    active: false },
  { label: 'Adjustments', active: false },
  { label: 'Refunds',     active: false },
];

const ROWS: readonly TxRow[] = [
  { date: '04/12/2026', type: 'charge',     desc: 'Office visit, established, level 3', cpt: '99213', prov: 'Dr. Rivera', ins: '$152.00', pt: '$25.00', bal: '$0.00',  outstanding: false },
  { date: '04/12/2026', type: 'payment',    desc: 'BCBS — claim #BC-99281',         cpt: '—', prov: '—',    ins: '$152.00', pt: '—', bal: '—', outstanding: false, insGreen: true },
  { date: '02/18/2026', type: 'charge',     desc: 'Annual wellness visit (preventive)', cpt: '99396', prov: 'Dr. Rivera', ins: '$280.00', pt: '$0.00',  bal: '$0.00',  outstanding: false },
  { date: '02/18/2026', type: 'payment',    desc: 'BCBS — claim #BC-99020',         cpt: '—', prov: '—',    ins: '$280.00', pt: '—', bal: '—', outstanding: false, insGreen: true },
  { date: '02/18/2026', type: 'charge',     desc: 'CMP + CBC + HbA1c lab panel',        cpt: '80050', prov: 'Dr. Rivera', ins: '$184.00', pt: '$45.00', bal: '$0.00',  outstanding: false },
  { date: '11/15/2025', type: 'charge',     desc: 'Office visit, established, level 2', cpt: '99212', prov: 'Dr. Rivera', ins: '$98.00',  pt: '$25.00', bal: '$98.00', outstanding: true },
  { date: '08/22/2025', type: 'charge',     desc: 'Telehealth lab review',              cpt: '99214', prov: 'Dr. Chen',   ins: '$156.00', pt: '$25.00', bal: '$0.00',  outstanding: false },
  { date: '08/22/2025', type: 'adjustment', desc: 'Contract write-off (BCBS)',          cpt: '—', prov: '—',    ins: '—', pt: '—', bal: '−$48.00', outstanding: false, balRed: true },
];

type TransactionsProps = {
  readonly boot: BootContext;
};

export function Transactions(_props: TransactionsProps): JSX.Element {
  return (
    <>
      <section className={styles.kpis}>
        {KPIS.map((k) => (
          <div key={k.label} className={`${styles.kpi} ${kpiToneClass(k.tone)}`}>
            <div className={styles.kpiLabel}>{k.label}</div>
            <div className={styles.kpiValue}>{k.value}</div>
            <div className={styles.kpiSub}>{k.sub}</div>
          </div>
        ))}
      </section>

      <section className={styles.filters}>
        <span className={styles.filterLabel}>Filter:</span>
        {FILTERS.map((f) => (
          <button
            key={f.label}
            type="button"
            className={f.active ? `${styles.chip} ${styles.chipActive}` : styles.chip}
          >
            {f.label}
          </button>
        ))}
        <div className={styles.spacer} />
        <button type="button" className={styles.pill}>
          <span aria-hidden="true">📅</span>
          <span>Last 12 months</span>
        </button>
        <button type="button" className={styles.pill}>
          <span aria-hidden="true">⬇</span>
          <span>Export CSV</span>
        </button>
      </section>

      <section className={styles.tableWrap}>
        <table className={styles.table}>
          <thead>
            <tr>
              <th className={styles.colDate}>DATE</th>
              <th className={styles.colType}>TYPE</th>
              <th>DESCRIPTION</th>
              <th className={styles.colCpt}>CPT</th>
              <th className={styles.colProv}>PROVIDER</th>
              <th className={styles.colNum}>INS PAID</th>
              <th className={styles.colNum}>PT PAID</th>
              <th className={styles.colNum}>BALANCE</th>
            </tr>
          </thead>
          <tbody>
            {ROWS.map((r, i) => (
              <tr key={i}>
                <td className={styles.colDate}>{r.date}</td>
                <td className={styles.colType}>
                  <span className={`${styles.typePill} ${typePillClass(r.type)}`}>
                    {r.type.toUpperCase()}
                  </span>
                </td>
                <td>
                  {r.desc}
                </td>
                <td className={styles.colCpt}>{r.cpt}</td>
                <td className={styles.colProv}>{r.prov}</td>
                <td className={`${styles.colNum} ${r.insGreen ? styles.amtGreen : ''}`}>{r.ins}</td>
                <td className={styles.colNum}>{r.pt}</td>
                <td className={`${styles.colNum} ${r.outstanding ? styles.amtOrange : ''} ${r.balRed ? styles.amtRed : ''}`}>
                  <span className={styles.balCell}>
                    <span>{r.bal}</span>
                    {r.outstanding && (
                      <span className={styles.outPill}>OUTSTANDING</span>
                    )}
                  </span>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </section>
    </>
  );
}

function kpiToneClass(tone: KpiTone): string {
  switch (tone) {
    case 'neutral': return styles.toneNeutral ?? '';
    case 'good':    return styles.toneGood ?? '';
    case 'info':    return styles.toneInfo ?? '';
    case 'warn':    return styles.toneWarn ?? '';
  }
}

function typePillClass(type: TxType): string {
  switch (type) {
    case 'charge':     return styles.typeCharge ?? '';
    case 'payment':    return styles.typePayment ?? '';
    case 'adjustment': return styles.typeAdjustment ?? '';
  }
}
