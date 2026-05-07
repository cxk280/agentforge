// Patient Tracker / Flow Board — Figma "Screen 32 — Patient Tracker / Flow Board".
//
// Cross-patient kanban-style flow board for the day's exam-room states.
// Five columns (Waiting, Roomed, With Provider, Ready to Discharge,
// Checked Out). This is a 1:1 port of the PHP-rendered mock previously
// at /interface/main/copilot_patient_tracker.php. State is held in React
// but the data is fully static demo data sourced directly from the
// Figma source — no DB integration. View toggle defaults to "Today",
// provider filter defaults to "All providers".
//
// Verified against Figma node 76:2 (kj4MWNr8mpjZ2wVg1PbS0F) on 2026-05-07.

import { useState } from 'react';
import type { BootContext } from '../../shared/lib/bootContext';
import styles from './PatientTracker.module.css';

type RangeMode = 'today' | 'tomorrow' | 'week';
type PillTone = 'good' | 'info' | 'warn' | 'danger-soft' | 'violet' | 'neutral';

type FlowCard = {
  readonly name: string;
  readonly mrn: string;
  readonly visit: string;
  readonly provider: string;
  readonly time: string;
  readonly meta: string;
  readonly metaTone?: 'warn' | 'violet';
  readonly pillText?: string;
  readonly pillTone?: PillTone;
};

type FlowColumn = {
  readonly key: string;
  readonly label: string;
  readonly dot: string;
  readonly countBg: string;
  readonly countColor: string;
  readonly cards: readonly FlowCard[];
};

const PROVIDERS: readonly string[] = ['All providers', 'Dr. Rivera', 'Dr. Chen', 'Dr. Patel', 'NP Jones'];

const COLUMNS: readonly FlowColumn[] = [
  {
    key: 'waiting',
    label: 'Waiting',
    dot: '#8A91A1',
    countBg: '#FFF4EA',
    countColor: '#FA8C33',
    cards: [
      {
        name: 'Anita Patel',     mrn: '#005211', visit: 'Annual physical', provider: 'Dr. Rivera',
        time: '8:42 AM', meta: '· 18m', metaTone: 'warn',
        pillText: 'Behind 8m', pillTone: 'warn',
      },
      {
        name: 'Carlos Mendez',   mrn: '#004102', visit: 'Diabetes f/u',    provider: 'Dr. Chen',
        time: '9:00 AM', meta: '· 10m',
      },
      {
        name: 'Jennifer Liu',    mrn: '#005789', visit: 'Telehealth',      provider: 'Dr. Chen',
        time: '9:15 AM', meta: '· 5m',
        pillText: 'Telehealth', pillTone: 'info',
      },
      {
        name: 'Robert Hayes',    mrn: '#001821', visit: 'BP recheck',      provider: 'NP Jones',
        time: '9:30 AM', meta: '· 2m',
      },
      {
        name: 'Soo-Yeon Kim',    mrn: '#005903', visit: 'New patient',     provider: 'Dr. Patel',
        time: '9:45 AM', meta: '· —',
        pillText: 'New', pillTone: 'good',
      },
    ],
  },
  {
    key: 'roomed',
    label: 'Roomed',
    dot: '#4785D9',
    countBg: '#EAF1FC',
    countColor: '#4785D9',
    cards: [
      {
        name: 'Margaret Chen',   mrn: '#004821', visit: 'Diabetes f/u',    provider: 'Dr. Rivera',
        time: '8:30 AM', meta: '· Exam 3',
        pillText: 'Vitals done', pillTone: 'info',
      },
      {
        name: 'Ted Shaw',        mrn: '#000001', visit: 'Annual physical', provider: 'Dr. Rivera',
        time: '8:45 AM', meta: '· Exam 1',
        pillText: 'Vitals done', pillTone: 'info',
      },
      {
        name: 'Linda Martinez',  mrn: '#003918', visit: 'Acute — URI',     provider: 'Dr. Chen',
        time: '9:00 AM', meta: '· Exam 2',
      },
    ],
  },
  {
    key: 'with_prov',
    label: 'With Provider',
    dot: '#8561C7',
    countBg: '#F2EDFB',
    countColor: '#8561C7',
    cards: [
      {
        name: 'David Kim',       mrn: '#006102', visit: 'Annual physical', provider: 'Dr. Rivera',
        time: '8:15 AM', meta: '· Exam 4 · 12m', metaTone: 'violet',
        pillText: 'In exam', pillTone: 'violet',
      },
      {
        name: 'Allison Park',    mrn: '#002745', visit: 'Procedure — joint', provider: 'Dr. Chen',
        time: '8:30 AM', meta: '· Exam 5 · 22m', metaTone: 'violet',
        pillText: 'Long visit', pillTone: 'warn',
      },
      {
        name: 'Marcus Webb',     mrn: '#004411', visit: 'Telehealth',      provider: 'NP Jones',
        time: '8:45 AM', meta: '· Tele · 8m', metaTone: 'violet',
        pillText: 'Telehealth', pillTone: 'info',
      },
      {
        name: 'Helen Garcia',    mrn: '#003021', visit: 'Follow-up',       provider: 'Dr. Patel',
        time: '9:00 AM', meta: '· Exam 6 · 5m', metaTone: 'violet',
      },
    ],
  },
  {
    key: 'ready_dc',
    label: 'Ready to Discharge',
    dot: '#33A68C',
    countBg: '#E8F7F3',
    countColor: '#33A68C',
    cards: [
      {
        name: 'Emily Foster',    mrn: '#005544', visit: 'Annual physical', provider: 'Dr. Rivera',
        time: '8:00 AM', meta: '· Exam 1',
        pillText: 'Notes signed', pillTone: 'good',
      },
      {
        name: 'James Brown',     mrn: '#002188', visit: 'BP recheck',      provider: 'Dr. Chen',
        time: '8:15 AM', meta: '· Exam 2',
        pillText: 'Rx sent', pillTone: 'good',
      },
    ],
  },
  {
    key: 'checked_out',
    label: 'Checked Out',
    dot: '#33A666',
    countBg: '#E8F7ED',
    countColor: '#33A666',
    cards: [
      {
        name: 'Sarah Wilson',    mrn: '#005891', visit: 'Annual physical', provider: 'Dr. Rivera',
        time: '7:30 AM', meta: '· 45m total',
        pillText: 'Billed', pillTone: 'good',
      },
      {
        name: 'Mike Tan',        mrn: '#003456', visit: 'Follow-up',       provider: 'Dr. Chen',
        time: '7:45 AM', meta: '· 30m total',
        pillText: 'Billed', pillTone: 'good',
      },
      {
        name: 'Kelly Roberts',   mrn: '#004890', visit: 'Telehealth',      provider: 'NP Jones',
        time: '8:00 AM', meta: '· 25m total',
        pillText: 'Billed', pillTone: 'good',
      },
    ],
  },
];

// Header sub-meta + KPI strip values come from Figma directly.
const FACILITY_LABEL = 'Riverside Family Medicine · Today, May 2';
const KPI_AVG_WAIT = '8 min';
const KPI_IN_ROOMS = '6 / 8';
const KPI_BEHIND = '2';
const KPI_TODAY_VISITS = '24 visits';
// Checked-Out column's count badge in Figma reads "10" — that's a mock total
// across the whole day, not the 3 cards we render in the column.
const CHECKED_OUT_BADGE_COUNT = 10;

type PatientTrackerProps = {
  readonly boot: BootContext;
};

export function PatientTracker(_props: PatientTrackerProps): JSX.Element {
  const [range, setRange] = useState<RangeMode>('today');
  const [provider, setProvider] = useState<string>('All providers');

  return (
    <>
      <header className={styles.header}>
        <div className={styles.titleBlock}>
          <span className={styles.title}>Patient Flow</span>
          <span className={styles.bullet}>·</span>
          <span className={styles.subMeta}>{FACILITY_LABEL}</span>
        </div>
        <div className={styles.spacer} />
        <div className={styles.seg} role="tablist">
          <RangeBtn mode="today"    current={range} onSelect={setRange}>Today</RangeBtn>
          <RangeBtn mode="tomorrow" current={range} onSelect={setRange}>Tomorrow</RangeBtn>
          <RangeBtn mode="week"     current={range} onSelect={setRange}>Week</RangeBtn>
        </div>
        <button className={styles.refresh} type="button">
          <span aria-hidden="true">⟳</span>
          <span>Refresh</span>
        </button>
        <button className={styles.add} type="button">
          <span aria-hidden="true">+</span>
          <span>Walk-in patient</span>
        </button>
        <button className={styles.help} type="button">? Help</button>
      </header>

      <div className={styles.filterBar}>
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
          <Stat label="Avg wait"  value={KPI_AVG_WAIT}     valueColor="#33A666" />
          <Stat label="In rooms"  value={KPI_IN_ROOMS}     valueColor="#181D26" />
          <Stat label="Behind"    value={KPI_BEHIND}       valueColor="#FA8C33" />
          <Stat label="Today"     value={KPI_TODAY_VISITS} valueColor="#181D26" />
        </div>
      </div>

      <div className={styles.board}>
        {COLUMNS.map((col) => {
          const badgeCount = col.key === 'checked_out' ? CHECKED_OUT_BADGE_COUNT : col.cards.length;
          return (
            <section key={col.key} className={styles.col}>
              <div className={styles.colHead}>
                <span className={styles.colDot} style={{ backgroundColor: col.dot }} />
                <span className={styles.colLabel}>{col.label}</span>
                <span
                  className={styles.colCount}
                  style={{ backgroundColor: col.countBg, color: col.countColor }}
                >
                  {badgeCount}
                </span>
              </div>
              {col.cards.map((c) => (
                <Card key={c.mrn} card={c} />
              ))}
              <div className={styles.addCard}>+ Add patient</div>
            </section>
          );
        })}
      </div>
    </>
  );
}

type RangeBtnProps = {
  readonly mode: RangeMode;
  readonly current: RangeMode;
  readonly onSelect: (m: RangeMode) => void;
  readonly children: React.ReactNode;
};

function RangeBtn({ mode, current, onSelect, children }: RangeBtnProps): JSX.Element {
  const active = mode === current;
  const cls = active ? `${styles.segItem} ${styles.segItemActive}` : styles.segItem;
  return (
    <button
      className={cls}
      role="tab"
      type="button"
      aria-selected={active}
      onClick={() => onSelect(mode)}
    >
      {children}
    </button>
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
  readonly card: FlowCard;
};

function Card({ card }: CardProps): JSX.Element {
  const metaCls = card.metaTone === 'warn'
    ? `${styles.cardMeta} ${styles.cardMetaWarn}`
    : card.metaTone === 'violet'
      ? `${styles.cardMeta} ${styles.cardMetaViolet}`
      : styles.cardMeta;

  return (
    <div className={styles.card}>
      <span className={styles.av} aria-hidden="true" />
      <span className={styles.nm}>{card.name}</span>
      <span className={styles.mrn}>{card.mrn}</span>
      <span className={styles.visit}>{card.visit}</span>
      <span className={styles.prov}>{card.provider}</span>
      <div className={styles.foot}>
        <span className={styles.when}>{card.time}</span>
        <span className={metaCls}>{card.meta}</span>
        {card.pillText && card.pillTone && (
          <span className={`${styles.kbPill} ${styles[`kbPill_${card.pillTone.replace('-', '_')}`]}`}>
            {card.pillText}
          </span>
        )}
      </div>
    </div>
  );
}
