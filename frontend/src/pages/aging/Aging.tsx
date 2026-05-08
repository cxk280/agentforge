// Aging — Figma "Screen 59 — Daily Cash / Aging". Renders the AgentForge
// Reports → Financial → Aging page: 5-up KPI strip, A/R-by-aging-bucket
// stacked bar with legend tiles, and a two-column row with an aging-by-payer
// table on the left and a largest-outstanding-accounts list on the right.
//
// This is a 1:1 port of the PHP-rendered page previously at
// /interface/billing/copilot_aging.php. All data is static demo data taken
// directly from the Figma mock — no DB queries, no CSV export wiring (yet).
// The navy top-nav and left sub-nav are rendered by the parent shell, so
// this component only renders the page body — pagehead + content area.
//
// Verified against Figma node 108:2 on 2026-05-07.

import type { BootContext } from '../../shared/lib/bootContext';
import styles from './Aging.module.css';

type BucketKey = '0-30' | '31-60' | '61-90' | '91-120' | '>120';
type Tone = 'green' | 'orange' | 'red' | 'dark';

type BucketTile = {
  readonly key: BucketKey;
  readonly label: string;
  readonly value: string;
  readonly pctLabel: string;
  readonly tone: Tone;
};

type PayerRow = {
  readonly payer: string;
  readonly b0_30: string;
  readonly b31_60: string;
  readonly b61_90: string;
  readonly b91_120: string;
  readonly b120: string;
  readonly total: string;
};

type AccountRow = {
  readonly name: string;
  readonly mrn: string;
  readonly amount: string;
  readonly amountTone: Tone;
  readonly bucket: string;
  readonly note: string;
};

export type AgingPayload = {
  readonly kpis: readonly Kpi[];
  readonly bucketTiles: readonly BucketTile[];
  readonly bucketBarPcts: readonly { readonly key: BucketKey; readonly pct: number; readonly fill: string }[];
  readonly accounts: readonly AccountRow[];
  readonly totalAR: number;
};

// KPI strip — 5 tiles, exactly as drawn in Figma.
type Kpi = {
  readonly label: string;
  readonly value: string;
  readonly tone: Tone;
};

const KPIS: readonly Kpi[] = [
  { label: 'Total A/R',       value: '$248,910', tone: 'dark'   },
  { label: 'Days in A/R',     value: '31 d',     tone: 'green'  },
  { label: '% > 90 days',     value: '12.4%',    tone: 'orange' },
  { label: 'Collected today', value: '$8,412',   tone: 'green'  },
  { label: 'Adjustments',     value: '$1,108',   tone: 'dark'   },
];

// A/R by aging bucket — values from Figma.
// Bar widths from Figma (px over a 1154-wide region) → percentages.
//   0-30   : 702.72 / 1146 = 61.3%
//   31-60  : 218.88 / 1146 = 19.1%
//   61-90  : 80.64  / 1146 = 7.0%
//   91-120 : 57.60  / 1146 = 5.0%
//   >120   : 86.40  / 1146 = 7.5%
const BUCKET_TILES: readonly BucketTile[] = [
  { key: '0-30',   label: 'Current (0-30)', value: '$152,402', pctLabel: '61% of total',   tone: 'green'  },
  { key: '31-60',  label: '31-60',          value: '$48,219',  pctLabel: '19% of total',   tone: 'green'  },
  { key: '61-90',  label: '61-90',          value: '$17,488',  pctLabel: '7% of total',    tone: 'orange' },
  { key: '91-120', label: '91-120',         value: '$12,108',  pctLabel: '5% of total',    tone: 'orange' },
  { key: '>120',   label: '> 120',          value: '$18,693',  pctLabel: '7.5% of total',  tone: 'red'    },
];

const BUCKET_BAR_PCTS: ReadonlyArray<{ readonly key: BucketKey; readonly pct: number; readonly fill: string }> = [
  { key: '0-30',   pct: 61.3, fill: '#33A666' },
  { key: '31-60',  pct: 19.1, fill: '#33A666' },
  { key: '61-90',  pct: 7.0,  fill: '#FA8C33' },
  { key: '91-120', pct: 5.0,  fill: '#FA8C33' },
  { key: '>120',   pct: 7.5,  fill: '#D93838' },
];

const PAYER_ROWS: readonly PayerRow[] = [
  { payer: 'Blue Cross PPO', b0_30: '$54,212', b31_60: '$18,440', b61_90: '$5,128', b91_120: '$2,108', b120: '$1,440', total: '$81,328' },
  { payer: 'Medicare',       b0_30: '$38,219', b31_60: '$12,408', b61_90: '$4,488', b91_120: '$3,128', b120: '$2,488', total: '$60,731' },
  { payer: 'UnitedHealth',   b0_30: '$28,108', b31_60: '$8,219',  b61_90: '$3,108', b91_120: '$2,108', b120: '$1,108', total: '$42,651' },
  { payer: 'Aetna',          b0_30: '$18,442', b31_60: '$6,108',  b61_90: '$2,108', b91_120: '$1,488', b120: '$3,108', total: '$31,254' },
  { payer: 'Cigna',          b0_30: '$8,128',  b31_60: '$2,108',  b61_90: '$1,108', b91_120: '$888',   b120: '$1,488', total: '$13,720' },
  { payer: 'Self-pay',       b0_30: '$5,293',  b31_60: '$936',    b61_90: '$1,548', b91_120: '$2,388', b120: '$9,061', total: '$19,226' },
];

const ACCOUNTS: readonly AccountRow[] = [
  { name: 'Frank Kim',       mrn: '#003700', amount: '$4,128', amountTone: 'red',    bucket: '> 120 days', note: 'Self-pay'      },
  { name: 'Patricia Vance',  mrn: '#005122', amount: '$3,488', amountTone: 'orange', bucket: '91-120',     note: 'Self-pay'      },
  { name: 'Donald Reyes',    mrn: '#005711', amount: '$2,488', amountTone: 'orange', bucket: '61-90',      note: 'Insurance gap' },
  { name: 'Linda Martinez',  mrn: '#003918', amount: '$1,948', amountTone: 'orange', bucket: '91-120',     note: 'Aetna deny'    },
  { name: 'Helen Garcia',    mrn: '#003021', amount: '$1,488', amountTone: 'red',    bucket: '> 120 days', note: 'Self-pay'      },
  { name: 'Robert Hayes',    mrn: '#001821', amount: '$1,108', amountTone: 'orange', bucket: '61-90',      note: 'Co-pay'        },
  { name: 'Mike Tan',        mrn: '#003456', amount: '$988',   amountTone: 'dark',   bucket: '31-60',      note: 'BC PPO'        },
  { name: 'Soo-Yeon Kim',    mrn: '#005903', amount: '$748',   amountTone: 'dark',   bucket: '0-30',       note: 'Aetna'         },
];

type AgingProps = {
  readonly boot: BootContext;
  readonly payload: AgingPayload;
};

export function Aging({ payload }: AgingProps): JSX.Element {
  const isLive = payload.totalAR > 0;
  const kpis = isLive ? payload.kpis : KPIS;
  const bucketTiles = isLive ? payload.bucketTiles : BUCKET_TILES;
  const bucketBarPcts = isLive ? payload.bucketBarPcts : BUCKET_BAR_PCTS;
  const accounts = isLive ? payload.accounts : ACCOUNTS;
  return (
    <>
      <header className={styles.pagehead}>
        <div className={styles.titleRow}>
          <span className={styles.title}>Aging Report</span>
          <span className={styles.dot}>•</span>
          <span className={styles.subtitle}>Outstanding A/R by aging bucket · as of 05/02/2026</span>
        </div>
        <div className={styles.spacer} />
        <button className={styles.exportBtn} type="button">⬇ Export CSV</button>
      </header>

      <main className={styles.content}>
        {/* 5-up KPI strip */}
        <section className={styles.kpiCard}>
          {kpis.map((k, i) => (
            <span key={k.label} className={styles.kpiSlot}>
              {i > 0 && <span className={styles.kpiDivider} aria-hidden="true" />}
              <span className={styles.kpi}>
                <span className={styles.kpiLabel}>{k.label}</span>
                <span className={`${styles.kpiVal} ${toneClass(k.tone)}`}>{k.value}</span>
              </span>
            </span>
          ))}
        </section>

        {/* A/R by aging bucket */}
        <section className={styles.bucketCard}>
          <div className={styles.cardHead}>A/R BY AGING BUCKET</div>
          <div className={styles.bucketBar} aria-hidden="true">
            {bucketBarPcts.map((seg) => (
              <span
                key={seg.key}
                className={styles.bucketBarSeg}
                style={{ width: `${seg.pct}%`, background: seg.fill }}
              />
            ))}
          </div>
          <div className={styles.bucketLegend}>
            {bucketTiles.map((t) => (
              <div key={t.key} className={styles.bucketTile}>
                <div className={styles.bucketTileLabelRow}>
                  <span className={`${styles.bucketDot} ${dotClass(t.tone)}`} aria-hidden="true" />
                  <span className={styles.bucketTileLabel}>{t.label}</span>
                </div>
                <div className={`${styles.bucketTileVal} ${toneClass(t.tone)}`}>{t.value}</div>
                <div className={styles.bucketTileSub}>{t.pctLabel}</div>
              </div>
            ))}
          </div>
        </section>

        {/* Two-column lower layout */}
        <div className={styles.cols}>
          <section className={styles.payerCard}>
            <div className={styles.cardHead}>AGING BY PAYER</div>
            <div className={styles.payerHead}>
              <span className={styles.payerHeadCol}>PAYER</span>
              <span className={styles.payerHeadCol}>0-30</span>
              <span className={styles.payerHeadCol}>31-60</span>
              <span className={styles.payerHeadCol}>61-90</span>
              <span className={styles.payerHeadCol}>91-120</span>
              <span className={styles.payerHeadCol}>{'> 120'}</span>
              <span className={styles.payerHeadCol}>TOTAL</span>
            </div>
            <div className={styles.payerList}>
              {PAYER_ROWS.map((p, i) => {
                const rowClass = i % 2 === 1
                  ? `${styles.payerRow} ${styles.payerRowAlt}`
                  : styles.payerRow;
                return (
                  <div key={p.payer} className={rowClass}>
                    <span className={styles.payerName}>{p.payer}</span>
                    <span className={styles.payerCell}>{p.b0_30}</span>
                    <span className={styles.payerCell}>{p.b31_60}</span>
                    <span className={styles.payerCell}>{p.b61_90}</span>
                    <span className={`${styles.payerCell} ${styles.payerWarn}`}>{p.b91_120}</span>
                    <span className={`${styles.payerCell} ${styles.payerWarn}`}>{p.b120}</span>
                    <span className={`${styles.payerCell} ${styles.payerDanger}`}>{p.total}</span>
                  </div>
                );
              })}
            </div>
          </section>

          <section className={styles.acctCard}>
            <div className={styles.cardHead}>LARGEST OUTSTANDING ACCOUNTS</div>
            <div className={styles.acctList}>
              {accounts.map((a, i) => {
                const rowClass = i % 2 === 1
                  ? `${styles.acctRow} ${styles.acctRowAlt}`
                  : styles.acctRow;
                return (
                  <div key={a.mrn} className={rowClass}>
                    <span className={styles.acctAvatar} aria-hidden="true" />
                    <div className={styles.acctWho}>
                      <span className={styles.acctName}>{a.name}</span>
                      <span className={styles.acctMrn}>{a.mrn}</span>
                    </div>
                    <span className={`${styles.acctAmt} ${toneClass(a.amountTone)}`}>{a.amount}</span>
                    <span className={styles.acctBucket}>{a.bucket}</span>
                    <span className={styles.acctNote}>{a.note}</span>
                    <a className={styles.acctStmt} href="#" onClick={(e) => e.preventDefault()}>
                      {'Statement →'}
                    </a>
                  </div>
                );
              })}
            </div>
          </section>
        </div>
      </main>
    </>
  );
}

function toneClass(t: Tone): string {
  switch (t) {
    case 'green':  return styles.toneGreen ?? '';
    case 'orange': return styles.toneOrange ?? '';
    case 'red':    return styles.toneRed ?? '';
    case 'dark':   return styles.toneDark ?? '';
  }
}

function dotClass(t: Tone): string {
  switch (t) {
    case 'green':  return styles.dotGreen ?? '';
    case 'orange': return styles.dotOrange ?? '';
    case 'red':    return styles.dotRed ?? '';
    case 'dark':   return styles.dotDark ?? '';
  }
}
