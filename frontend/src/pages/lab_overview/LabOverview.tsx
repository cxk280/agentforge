// LabOverview — Figma "Screen 37 — Lab Overview".
//
// Patient-scoped lab trends dashboard. Renders four trended panels (HbA1c,
// LDL, Microalbumin, Creatinine) for the active patient with latest value,
// trend tag, reference range and an inline SVG line of historical results.
// A time-range pill toggle (6m / 1y / 2y / 5y / All) at the top filters the
// query window.
//
// This is a 1:1 port of the PHP-rendered mock previously at
// /interface/orders/copilot_lab_overview.php — a static demo with hardcoded
// series matching the Figma design (no DB queries). Wiring to a real
// /apis/copilot/labs endpoint is a follow-up.
//
// The chrome (top nav, demographics banner, navtab strip) is rendered by
// the parent shell — this page renders only the body.

import { useState } from 'react';
import type { BootContext } from '../../shared/lib/bootContext';
import styles from './LabOverview.module.css';

type RangeKey = '6m' | '1y' | '2y' | '5y' | 'all';
type Tone = 'good' | 'warn' | 'danger';

type Panel = {
  readonly name: string;
  readonly tag: string;
  readonly tone: Tone;
  readonly latest: string;
  readonly unit: string;
  readonly ref: string;
  readonly series: readonly number[];
};

const RANGE_OPTIONS: ReadonlyArray<{ readonly key: RangeKey; readonly label: string }> = [
  { key: '6m', label: '6m' },
  { key: '1y', label: '1y' },
  { key: '2y', label: '2y' },
  { key: '5y', label: '5y' },
  { key: 'all', label: 'All' },
];

const PATIENT_NAME = 'Margaret Chen';
const HELP_HREF = 'https://www.open-emr.org/wiki/index.php/Laboratory_Module';

// X-axis tick labels — match the Figma 9-tick layout (10/22 → 04/26).
const X_LABELS: readonly string[] = [
  '10/22', '01/23', '06/23', '01/24', '06/24', '11/24', '05/25', '11/25', '04/26',
];

// Static demo series — each panel has 9 points matching the Figma chart shape.
// HbA1c: rising (red); LDL: improving down (green); Microalbumin: trending up
// (orange); Creatinine: stable (green).
const PANELS: readonly Panel[] = [
  {
    name: 'HbA1c',
    tag: '↑ rising',
    tone: 'danger',
    latest: '7.9',
    unit: '%',
    ref: '<7.0',
    series: [6.4, 6.6, 6.8, 6.9, 7.0, 7.2, 7.4, 7.6, 7.9],
  },
  {
    name: 'LDL',
    tag: '↓ improving',
    tone: 'good',
    latest: '98',
    unit: 'mg/dL',
    ref: '<100',
    series: [142, 134, 125, 117, 112, 107, 103, 100, 98],
  },
  {
    name: 'Microalbumin',
    tag: '↑ trending up',
    tone: 'warn',
    latest: '32',
    unit: 'mg/g',
    ref: '<30',
    series: [12, 14, 16, 19, 22, 24, 26, 28, 32],
  },
  {
    name: 'Creatinine',
    tag: '→ stable',
    tone: 'good',
    latest: '1.04',
    unit: 'mg/dL',
    ref: '0.6–1.2',
    series: [0.95, 0.97, 0.99, 1.01, 1.02, 1.03, 1.04, 1.04, 1.04],
  },
];

const TONE_COLOR: Record<Tone, string> = {
  good: '#33A666',
  warn: '#FA8C33',
  danger: '#D93838',
};

type LabOverviewProps = {
  readonly boot: BootContext;
};

export function LabOverview(_props: LabOverviewProps): JSX.Element {
  const [activeRange, setActiveRange] = useState<RangeKey>('2y');

  return (
    <>
      <header className={styles.pageHead}>
        <div className={styles.info}>
          <span className={styles.titleLg}>Lab Trends</span>
          <span className={styles.dot}>&middot;</span>
          <span className={styles.subMeta}>Visualize key labs over time &middot; {PATIENT_NAME}</span>
        </div>
        <div className={styles.spacer} />
        <div className={styles.rangeToggle} role="tablist">
          {RANGE_OPTIONS.map((opt) => {
            const active = opt.key === activeRange;
            const cls = active
              ? `${styles.rangeBtn} ${styles.rangeBtnActive}`
              : styles.rangeBtn;
            return (
              <button
                key={opt.key}
                type="button"
                role="tab"
                aria-selected={active}
                className={cls}
                onClick={() => setActiveRange(opt.key)}
              >
                {opt.label}
              </button>
            );
          })}
        </div>
        <a className={styles.helpPill} href={HELP_HREF} target="_blank" rel="noopener noreferrer">
          ? Help
        </a>
      </header>

      <main className={styles.content}>
        <div className={styles.grid}>
          {PANELS.map((p) => (
            <TrendCard key={p.name} panel={p} />
          ))}
        </div>
      </main>
    </>
  );
}

type TrendCardProps = {
  readonly panel: Panel;
};

function TrendCard({ panel }: TrendCardProps): JSX.Element {
  const tagClass = `${styles.trendTag} ${styles[`tone_${panel.tone}`] ?? ''}`;
  const valClass = `${styles.statVal} ${styles[`tone_${panel.tone}`] ?? ''}`;

  return (
    <div className={styles.card}>
      <div className={styles.cardHead}>
        <div className={styles.headLeft}>
          <span className={styles.trendName}>{panel.name}</span>
          <span className={tagClass}>{panel.tag}</span>
        </div>
        <div className={styles.headRight}>
          <div className={styles.stat}>
            <span className={styles.statLbl}>Latest</span>
            <div className={styles.statRow}>
              <span className={valClass}>{panel.latest}</span>
              <span className={styles.statUnit}>{panel.unit}</span>
            </div>
          </div>
          <div className={styles.stat}>
            <span className={styles.statLbl}>Ref</span>
            <span className={styles.statRef}>{panel.ref}</span>
          </div>
        </div>
      </div>

      <div className={styles.chart}>
        <LabChart series={panel.series} tone={panel.tone} />
      </div>

      <div className={styles.xAxis}>
        {X_LABELS.map((lbl) => (
          <span key={lbl}>{lbl}</span>
        ))}
      </div>
    </div>
  );
}

type LabChartProps = {
  readonly series: readonly number[];
  readonly tone: Tone;
};

function LabChart({ series, tone }: LabChartProps): JSX.Element {
  const w = 700;
  const h = 220;
  const padL = 4;
  const padR = 16;
  const padT = 14;
  const padB = 14;
  const innerW = w - padL - padR;
  const innerH = h - padT - padB;
  const color = TONE_COLOR[tone];

  // 4 horizontal grid lines matching Figma (every 64px in original, here
  // distributed evenly across innerH).
  const grid: JSX.Element[] = [];
  for (let g = 1; g <= 3; g++) {
    const gy = padT + (g / 4) * innerH;
    grid.push(
      <line
        key={`g${g}`}
        x1={padL}
        y1={gy}
        x2={w - padR}
        y2={gy}
        stroke="#F0F2F5"
        strokeWidth={1}
      />,
    );
  }

  if (series.length < 1) {
    const cx = padL + innerW / 2;
    const cy = padT + innerH / 2;
    return (
      <svg
        viewBox={`0 0 ${w} ${h}`}
        xmlns="http://www.w3.org/2000/svg"
        preserveAspectRatio="none"
        style={{ width: '100%', height: '100%', display: 'block' }}
      >
        {grid}
        <text x={cx} y={cy} textAnchor="middle" fill="#8A91A1" fontSize={12} fontFamily="Inter">
          No results in selected range
        </text>
      </svg>
    );
  }

  const minRaw = Math.min(...series);
  const maxRaw = Math.max(...series);
  const n = series.length;
  let range = maxRaw - minRaw;
  let min = minRaw;
  let max = maxRaw;
  if (range === 0) {
    min -= 1;
    max += 1;
    range = 2;
  }
  // ~10% headroom so the line never touches edges.
  min -= range * 0.10;
  max += range * 0.10;
  range = max - min;

  const points: ReadonlyArray<readonly [number, number]> = series.map((v, i) => {
    const x = padL + (n > 1 ? (i / (n - 1)) * innerW : innerW / 2);
    const y = padT + (1 - (v - min) / range) * innerH;
    return [Math.round(x * 10) / 10, Math.round(y * 10) / 10] as const;
  });

  const first = points[0] ?? ([padL, padT + innerH / 2] as const);
  let d = `M${first[0]},${first[1]}`;
  for (let i = 1; i < points.length; i++) {
    const p = points[i] ?? first;
    d += ` L${p[0]},${p[1]}`;
  }

  return (
    <svg
      viewBox={`0 0 ${w} ${h}`}
      xmlns="http://www.w3.org/2000/svg"
      preserveAspectRatio="none"
      style={{ width: '100%', height: '100%', display: 'block' }}
    >
      {grid}
      <path
        fill="none"
        stroke={color}
        strokeWidth={2}
        strokeLinecap="round"
        strokeLinejoin="round"
        d={d}
      />
      {points.map((pt, i) => {
        const isLast = i === points.length - 1;
        const r = isLast ? 5 : 2.6;
        return <circle key={i} cx={pt[0]} cy={pt[1]} r={r} fill={color} />;
      })}
    </svg>
  );
}
