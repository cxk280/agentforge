// LabOverview — Figma "Screen 37 — Lab Overview".
//
// Patient-scoped lab trends dashboard. Renders one trended panel per known
// LOINC concept (HbA1c, LDL, Microalbumin, Creatinine) for the active
// patient with latest value, trend tag, reference range, and an inline SVG
// line of historical results. A time-range pill toggle at the top filters
// the query window client-side using each panel's per-point dates.
//
// The PHP wrapper at /interface/orders/copilot_lab_overview.php queries
// procedure_result -> procedure_report -> procedure_order for the active
// patient and JSON-encodes the typed payload onto data-overview. The
// component renders panels straight from that payload (no hardcoded
// series). When a patient has no readings for a panel, that panel renders
// in an empty state with a flat axis and a neutral "single reading"/"—"
// pill — present for visual consistency, not synthetic data.
//
// The chrome (top nav, demographics banner, navtab strip) is rendered by
// the parent shell — this page renders only the body.

import { useMemo, useState } from 'react';
import type { BootContext } from '../../shared/lib/bootContext';
import styles from './LabOverview.module.css';

type RangeKey = '6m' | '1y' | '2y' | '5y' | 'all';
type Tone = 'good' | 'warn' | 'danger';

export type Panel = {
  readonly name: string;
  readonly tag: string;
  readonly tone: Tone;
  readonly latest: string;
  readonly unit: string;
  readonly ref: string;
  readonly series: readonly number[];
  readonly dates: readonly string[];
};

export type OverviewPayload = {
  readonly patientName: string;
  readonly panels: readonly Panel[];
  readonly xLabels: readonly string[];
};

const RANGE_OPTIONS: ReadonlyArray<{ readonly key: RangeKey; readonly label: string }> = [
  { key: '6m', label: '6m' },
  { key: '1y', label: '1y' },
  { key: '2y', label: '2y' },
  { key: '5y', label: '5y' },
  { key: 'all', label: 'All' },
];

const HELP_HREF = 'https://www.open-emr.org/wiki/index.php/Laboratory_Module';

const RANGE_MS: Record<RangeKey, number | null> = {
  '6m': 1000 * 60 * 60 * 24 * 183,
  '1y': 1000 * 60 * 60 * 24 * 365,
  '2y': 1000 * 60 * 60 * 24 * 365 * 2,
  '5y': 1000 * 60 * 60 * 24 * 365 * 5,
  all: null,
};

const TONE_COLOR: Record<Tone, string> = {
  good: '#33A666',
  warn: '#FA8C33',
  danger: '#D93838',
};

// Filter a panel's series + dates by a range cutoff. Returns the same
// panel shape with the truncated arrays so downstream rendering is
// uniform whether or not we filtered.
function filterByRange(panel: Panel, cutoffMs: number | null): Panel {
  if (cutoffMs === null || panel.series.length === 0) return panel;
  const now = Date.now();
  const limit = now - cutoffMs;
  const keptSeries: number[] = [];
  const keptDates: string[] = [];
  for (let i = 0; i < panel.series.length; i++) {
    const dRaw = panel.dates[i] ?? '';
    const t = Date.parse(dRaw);
    if (Number.isNaN(t) || t >= limit) {
      keptSeries.push(panel.series[i] as number);
      keptDates.push(dRaw);
    }
  }
  return { ...panel, series: keptSeries, dates: keptDates };
}

type LabOverviewProps = {
  readonly boot: BootContext;
  readonly payload: OverviewPayload;
};

export function LabOverview({ payload }: LabOverviewProps): JSX.Element {
  const [activeRange, setActiveRange] = useState<RangeKey>('2y');

  const cutoffMs = RANGE_MS[activeRange];
  const filteredPanels = useMemo(
    () => payload.panels.map((p) => filterByRange(p, cutoffMs)),
    [payload.panels, cutoffMs],
  );

  const subMeta = payload.patientName !== ''
    ? `Visualize key labs over time · ${payload.patientName}`
    : 'Visualize key labs over time';

  return (
    <>
      <header className={styles.pageHead}>
        <div className={styles.info}>
          <span className={styles.titleLg}>Lab Trends</span>
          <span className={styles.dot}>&middot;</span>
          <span className={styles.subMeta}>{subMeta}</span>
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
        {filteredPanels.length === 0 ? (
          <div className={styles.empty}>
            No lab results on file for this patient yet.
          </div>
        ) : (
          <div className={styles.grid}>
            {filteredPanels.map((p) => (
              <TrendCard key={p.name} panel={p} xLabels={payload.xLabels} />
            ))}
          </div>
        )}
      </main>
    </>
  );
}

type TrendCardProps = {
  readonly panel: Panel;
  readonly xLabels: readonly string[];
};

function TrendCard({ panel, xLabels }: TrendCardProps): JSX.Element {
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
        {xLabels.map((lbl, i) => (
          <span key={`${i}-${lbl}`}>{lbl}</span>
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
