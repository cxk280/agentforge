// Chart Tracker — Figma "Screen 50 — Chart Tracker".
//
// Cross-patient kanban view of the encounter completion workflow:
// Open Encounter → Notes Drafting → Pending Sign → Signed → Coded →
// Billed. Each card shows the patient, encounter type, provider,
// age-of-state, and an optional state-specific badge (overdue
// indicator, attestation pending, E&M code, billed amount).
//
// This is a 1:1 port of the PHP-rendered mock previously at
// /custom/copilot_chart_tracker.php. State (provider filter) is held
// in React but the data is fully static demo data sourced directly
// from the Figma source — no DB integration. Provider filter defaults
// to "All providers".
//
// Verified against Figma node 99:2 (kj4MWNr8mpjZ2wVg1PbS0F) on 2026-05-07.

import { useState } from 'react';
import type { BootContext } from '../../shared/lib/bootContext';
import styles from './ChartTracker.module.css';

type BadgeKind = 'warn' | 'danger' | 'info' | 'good';

type ChartCard = {
  readonly name: string;
  readonly mrn: string;
  readonly enc: string;
  readonly provider: string;
  readonly ago: string;
  readonly badgeText?: string | undefined;
  readonly badgeKind?: BadgeKind | undefined;
};

type ChartColumn = {
  readonly key: string;
  readonly label: string;
  readonly dot: string;
  readonly countBg: string;
  readonly countColor: string;
  readonly count: number;
  readonly cards: readonly ChartCard[];
};

const PROVIDERS: readonly string[] = ['All providers', 'Dr. Rivera', 'Dr. Chen', 'Dr. Patel', 'NP Jones'];

// Six-column kanban — values match Figma node 99:2 directly. Column count
// pills show the (mock) total count for that lane, not the cards rendered.
const COLUMNS: readonly ChartColumn[] = [
  {
    key: 'open',
    label: 'Open Encounter',
    dot: '#4785D9',
    countBg: '#EAF1FC',
    countColor: '#4785D9',
    count: 8,
    cards: [
      {
        name: 'Margaret Chen', mrn: '#004821', enc: '05/02 · Diab f/u',
        provider: 'Dr. Rivera', ago: '12 min ago',
      },
    ],
  },
  {
    key: 'drafting',
    label: 'Notes Drafting',
    dot: '#FA8C33',
    countBg: '#FFF4EA',
    countColor: '#FA8C33',
    count: 12,
    cards: [
      {
        name: 'David Kim', mrn: '#006102', enc: '05/02 · Annual',
        provider: 'Dr. Chen', ago: '45 min ago',
        badgeText: 'Overdue 4h', badgeKind: 'warn',
      },
      {
        name: 'Allison Park', mrn: '#002745', enc: '05/02 · Procedure',
        provider: 'Dr. Chen', ago: '1h ago',
      },
      {
        name: 'Linda Martinez', mrn: '#003918', enc: '05/02 · Acute URI',
        provider: 'Dr. Chen', ago: '2h ago',
      },
    ],
  },
  {
    key: 'pending',
    label: 'Pending Sign',
    dot: '#8561C7',
    countBg: '#F2EDFB',
    countColor: '#8561C7',
    count: 9,
    cards: [
      {
        name: 'Carlos Mendez', mrn: '#004102', enc: '05/01 · Sleep f/u',
        provider: 'Dr. Patel', ago: '18h ago',
        badgeText: 'Awaiting attest', badgeKind: 'warn',
      },
      {
        name: 'Emily Foster', mrn: '#005544', enc: '05/01 · Annual',
        provider: 'Dr. Rivera', ago: '19h ago',
      },
      {
        name: 'James Brown', mrn: '#002188', enc: '05/01 · BP recheck',
        provider: 'Dr. Chen', ago: '20h ago',
      },
      {
        name: 'Helen Garcia', mrn: '#003021', enc: '04/30 · Pap',
        provider: 'Dr. Patel', ago: '2 days ago',
        badgeText: 'Overdue 48h', badgeKind: 'danger',
      },
    ],
  },
  {
    key: 'signed',
    label: 'Signed',
    dot: '#33A68C',
    countBg: '#E8F7F3',
    countColor: '#33A68C',
    count: 24,
    cards: [
      {
        name: 'Marcus Webb', mrn: '#004411', enc: '05/01 · Telehealth',
        provider: 'NP Jones', ago: '5h ago',
      },
      {
        name: 'Mike Tan', mrn: '#003456', enc: '05/01 · Diab f/u',
        provider: 'Dr. Chen', ago: '6h ago',
      },
      {
        name: 'Robert Hayes', mrn: '#001821', enc: '04/30 · Acute',
        provider: 'NP Jones', ago: '1 day ago',
      },
    ],
  },
  {
    key: 'coded',
    label: 'Coded',
    dot: '#008C8C',
    countBg: '#E6F5F5',
    countColor: '#008C8C',
    count: 18,
    cards: [
      {
        name: 'Sarah Wilson', mrn: '#005891', enc: '04/30 · Annual',
        provider: 'Dr. Rivera', ago: '1 day ago',
        badgeText: '99214', badgeKind: 'info',
      },
      {
        name: 'Soo-Yeon Kim', mrn: '#005903', enc: '04/30 · New pt',
        provider: 'Dr. Patel', ago: '1 day ago',
        badgeText: '99205', badgeKind: 'info',
      },
      {
        name: 'Kelly Roberts', mrn: '#004890', enc: '04/30 · Telehealth',
        provider: 'NP Jones', ago: '1 day ago',
        badgeText: '99213', badgeKind: 'info',
      },
    ],
  },
  {
    key: 'billed',
    label: 'Billed',
    dot: '#33A666',
    countBg: '#E8F7ED',
    countColor: '#33A666',
    count: 42,
    cards: [
      {
        name: 'Patricia Vance', mrn: '#005122', enc: '04/30 · Annual',
        provider: 'Dr. Chen', ago: '1 day ago',
        badgeText: '$280', badgeKind: 'good',
      },
      {
        name: 'Donald Reyes', mrn: '#005711', enc: '04/30 · Diab f/u',
        provider: 'Dr. Patel', ago: '1 day ago',
        badgeText: '$148', badgeKind: 'good',
      },
      {
        name: 'Frank Kim', mrn: '#003700', enc: '04/29 · Joint inj',
        provider: 'Dr. Chen', ago: '2 days ago',
        badgeText: '$320', badgeKind: 'good',
      },
    ],
  },
];

// Header sub-meta + KPI strip values come from Figma directly.
const IN_PROGRESS_LABEL = 'Encounter completion workflow · 38 in progress';
const KPI_AVG_SIGN = '8.4 hrs';
const KPI_OVERDUE = '5';
const KPI_LOCKED_TODAY = '24';
const KPI_REOPENED = '2';

type ChartTrackerProps = {
  readonly boot: BootContext;
};

export function ChartTracker(_props: ChartTrackerProps): JSX.Element {
  const [provider, setProvider] = useState<string>('All providers');

  return (
    <>
      <header className={styles.header}>
        <div className={styles.titleBlock}>
          <span className={styles.title}>Chart Tracker</span>
          <span className={styles.bullet}>·</span>
          <span className={styles.subMeta}>{IN_PROGRESS_LABEL}</span>
        </div>
        <div className={styles.spacer} />
        <button className={styles.filter} type="button">
          <span aria-hidden="true">⊟</span>
          <span>Filter</span>
        </button>
        <button className={styles.export} type="button">
          <span aria-hidden="true">⬇</span>
          <span>Export tracker</span>
        </button>
        <button className={styles.help} type="button">? Help</button>
      </header>

      <div className={styles.statsBar}>
        <div className={styles.pills}>
          {PROVIDERS.map((p) => (
            <ProviderPill
              key={p}
              label={p}
              active={p === provider}
              onSelect={setProvider}
            />
          ))}
        </div>
        <div className={styles.stats}>
          <Stat label="Avg time to sign" value={KPI_AVG_SIGN}     valueColor="#181D26" />
          <Stat label="Overdue (>48h)"   value={KPI_OVERDUE}      valueColor="#D93838" />
          <Stat label="Locked today"     value={KPI_LOCKED_TODAY} valueColor="#33A666" />
          <Stat label="Re-opened"        value={KPI_REOPENED}     valueColor="#FA8C33" />
        </div>
      </div>

      <div className={styles.board}>
        {COLUMNS.map((col) => (
          <section key={col.key} className={styles.col}>
            <div className={styles.colHead}>
              <span className={styles.colDot} style={{ backgroundColor: col.dot }} />
              <span className={styles.colLabel}>{col.label}</span>
              <span
                className={styles.colCount}
                style={{ backgroundColor: col.countBg, color: col.countColor }}
              >
                {col.count}
              </span>
            </div>
            {col.cards.map((c) => (
              <Card key={c.mrn} card={c} />
            ))}
          </section>
        ))}
      </div>
    </>
  );
}

type ProviderPillProps = {
  readonly label: string;
  readonly active: boolean;
  readonly onSelect: (label: string) => void;
};

function ProviderPill({ label, active, onSelect }: ProviderPillProps): JSX.Element {
  const cls = active ? `${styles.pill} ${styles.pillActive}` : styles.pill;
  return (
    <button className={cls} type="button" onClick={() => onSelect(label)}>
      {label}
    </button>
  );
}

type StatProps = {
  readonly label: string;
  readonly value: string;
  readonly valueColor: string;
};

function Stat({ label, value, valueColor }: StatProps): JSX.Element {
  return (
    <div className={styles.stat}>
      <span className={styles.statLbl}>{label}</span>
      <span className={styles.statVal} style={{ color: valueColor }}>{value}</span>
    </div>
  );
}

type CardProps = {
  readonly card: ChartCard;
};

function Card({ card }: CardProps): JSX.Element {
  const badgeCls = card.badgeKind
    ? `${styles.badge} ${styles[`badge_${card.badgeKind}`] ?? ''}`
    : styles.badge;

  return (
    <div className={styles.card}>
      <span className={styles.av} aria-hidden="true" />
      <span className={styles.nm}>{card.name}</span>
      <span className={styles.mrn}>{card.mrn}</span>
      <span className={styles.enc}>{card.enc}</span>
      <span className={styles.prov}>{card.provider}</span>
      <div className={styles.foot}>
        <span className={styles.ago}>{card.ago}</span>
        {card.badgeText && card.badgeKind && (
          <span className={badgeCls}>{card.badgeText}</span>
        )}
      </div>
    </div>
  );
}
