// Authorizations — Figma "Screen 34 — Authorizations". Renders the AgentForge
// insurance prior-authorization queue: page header w/ summary meta + Export /
// New / Help buttons, 5-up KPI strip, filter strip (search + 5 selects), and
// a 13-row authorizations table.
//
// DB-backed: the PHP wrapper at
// /interface/patient_file/transaction/copilot_authorizations.php composes
// rows + KPIs + meta from the `cp_authorizations` table (joined to
// `patient_data` and `users`) into a single AuthPayload, JSON-encodes it
// onto data-auth on #cp-root, and the entry index.tsx parses it and passes
// it here. With no rows, the component renders the empty state — no demo
// fallback (Ledger had a fallback bug; we don't reintroduce it here).
//
// Verified against Figma node 79:2 on 2026-05-07.
//
// Ported with strict TS (noUncheckedIndexedAccess + exactOptionalPropertyTypes
// on) — bracket access into option lists is coerced via `?? ''` and optional
// undefined props are typed accordingly.

import type { BootContext } from '../../shared/lib/bootContext';
import styles from './Authorizations.module.css';

type Tone = 'good' | 'warn' | 'danger' | 'neutral';

export type AuthRow = {
  readonly auth: string;
  readonly patient: string;
  readonly payer: string;
  readonly service: string;
  readonly cpt: string;
  readonly requested: string;
  readonly due: string;
  readonly status: string;
  readonly tone: Tone;
  readonly provider: string;
  readonly expedited?: boolean | undefined;
};

export type AuthKpi = {
  readonly label: string;
  readonly value: string;
  readonly tone?: 'orange' | 'green' | 'red' | undefined;
  readonly sub: string;
  readonly subTone?: 'down' | undefined;
  readonly unit?: string | undefined;
};

export type AuthMeta = {
  readonly active: number;
  readonly awaitingPayer: number;
  readonly deniedThisWeek: number;
};

export type AuthPayload = {
  readonly meta: AuthMeta;
  readonly kpis: readonly AuthKpi[];
  readonly rows: readonly AuthRow[];
};

const EMPTY_PAYLOAD: AuthPayload = {
  meta: { active: 0, awaitingPayer: 0, deniedThisWeek: 0 },
  kpis: [
    { label: 'Awaiting payer',  value: '0',  tone: 'orange', sub: 'no open requests' },
    { label: 'Approved (30d)',  value: '0',  tone: 'green',  sub: 'no decisions yet' },
    { label: 'Denied (30d)',    value: '0',  tone: 'red',    sub: 'none' },
    { label: 'Submitted today', value: '0',                  sub: '0 expedited' },
    { label: 'Avg turnaround',  value: '—',  unit: '',       sub: 'last 90 days' },
  ],
  rows: [],
};

type FilterDef = {
  readonly key: string;
  readonly label: string;
  readonly value: string;
  readonly width: number;
};

const FILTERS: readonly FilterDef[] = [
  { key: 'status',   label: 'Status',       value: 'All',           width: 136 },
  { key: 'payer',    label: 'Payer',        value: 'All payers',    width: 152 },
  { key: 'service',  label: 'Service type', value: 'All',           width: 136 },
  { key: 'provider', label: 'Provider',     value: 'All providers', width: 172 },
  { key: 'range',    label: 'Date range',   value: 'Last 30 days',  width: 172 },
];

type AuthorizationsProps = {
  readonly boot: BootContext;
  readonly auth?: AuthPayload | undefined;
};

export function Authorizations({ auth }: AuthorizationsProps): JSX.Element {
  const payload: AuthPayload = auth ?? EMPTY_PAYLOAD;
  const meta = payload.meta;
  const kpis = payload.kpis.length > 0 ? payload.kpis : EMPTY_PAYLOAD.kpis;
  const rows = payload.rows;

  return (
    <>
      <header className={styles.pagehead}>
        <div className={styles.titleBlock}>
          <span className={styles.title}>Authorizations</span>
          <span className={styles.dot}>·</span>
          <span className={styles.meta}>
            {meta.active} active · {meta.awaitingPayer} awaiting payer · {meta.deniedThisWeek} denied this week
          </span>
        </div>
        <div className={styles.spacer} />
        <button type="button" className={styles.btnGhost}>
          <span className={styles.btnIcon}>⬇</span>
          <span>Export</span>
        </button>
        <button type="button" className={styles.btnPrimary}>
          + New authorization
        </button>
        <button type="button" className={styles.helpPill}>? Help</button>
      </header>

      <div className={styles.divider} />

      <main className={styles.content}>
        <div className={styles.kpiCard}>
          {kpis.map((kpi, i) => (
            <div key={kpi.label} className={styles.kpiCell}>
              <div className={styles.kpiLabel}>{kpi.label}</div>
              <div className={styles.kpiRow}>
                <span className={kpiValueClass(kpi.tone)}>
                  {kpi.value}
                  {kpi.unit !== undefined && kpi.unit !== '' && (
                    <span className={styles.kpiUnit}>{` ${kpi.unit}`}</span>
                  )}
                </span>
                <span className={styles.kpiSub}>
                  {kpi.subTone === 'down' && <span className={styles.kpiDown}>↓ </span>}
                  {kpi.subTone === 'down' ? `from ${kpi.sub.replace(/^from\s*/, '')}` : kpi.sub}
                </span>
              </div>
              {i < kpis.length - 1 && <div className={styles.kpiSeparator} />}
            </div>
          ))}
        </div>

        <div className={styles.filterBar}>
          <label className={styles.search}>
            <span className={styles.searchIcon}>🔍</span>
            <input
              type="text"
              placeholder="Search patient, payer, or auth #…"
              defaultValue=""
            />
          </label>
          {FILTERS.map((f) => (
            <div
              key={f.key}
              className={styles.sel}
              style={{ width: `${f.width}px` }}
            >
              <span className={styles.selLbl}>{f.label}</span>
              <span className={styles.selVal}>{f.value}</span>
              <span className={styles.selCaret}>▾</span>
            </div>
          ))}
        </div>

        <div className={styles.divider} />

        <div className={styles.tableCard}>
          <table className={styles.table}>
            <thead>
              <tr>
                <th className={styles.cb}><span className={styles.cb16} /></th>
                <th>AUTH #</th>
                <th>PATIENT</th>
                <th>PAYER</th>
                <th>SERVICE</th>
                <th>CPT</th>
                <th>REQUESTED</th>
                <th>DUE</th>
                <th>STATUS</th>
                <th>PROVIDER</th>
                <th className={styles.act}></th>
              </tr>
            </thead>
            <tbody>
              {rows.length === 0 ? (
                <tr>
                  <td colSpan={11} style={{ padding: '28px', textAlign: 'center', color: '#8A91A1' }}>
                    No authorizations match the current filters.
                  </td>
                </tr>
              ) : (
                rows.map((r, idx) => (
                  <tr key={r.auth} className={idx % 2 === 1 ? styles.rowAlt : undefined}>
                    <td className={styles.cb}><span className={styles.cb16} /></td>
                    <td className={styles.authnum}>{r.auth}</td>
                    <td className={styles.patient}>{r.patient}</td>
                    <td className={styles.payer}>{r.payer}</td>
                    <td className={styles.service}>{r.service}</td>
                    <td className={styles.cpt}>{r.cpt}</td>
                    <td className={styles.muted}>{r.requested}</td>
                    <td className={styles.due}>{r.due}</td>
                    <td>
                      <span className={pillClass(r.tone)}>{r.status}</span>
                    </td>
                    <td className={styles.muted}>{r.provider}</td>
                    <td className={styles.act}><span className={styles.kebab}>⋯</span></td>
                  </tr>
                ))
              )}
            </tbody>
          </table>
        </div>
      </main>
    </>
  );
}

function kpiValueClass(tone: AuthKpi['tone']): string {
  if (tone === 'orange') return `${styles.kpiVal} ${styles.kpiOrange}`;
  if (tone === 'green')  return `${styles.kpiVal} ${styles.kpiGreen}`;
  if (tone === 'red')    return `${styles.kpiVal} ${styles.kpiRed}`;
  return styles.kpiVal ?? '';
}

function pillClass(tone: Tone): string {
  const map: Record<Tone, string> = {
    good:    `${styles.pill} ${styles.pillGood}`,
    warn:    `${styles.pill} ${styles.pillWarn}`,
    danger:  `${styles.pill} ${styles.pillDanger}`,
    neutral: `${styles.pill} ${styles.pillNeutral}`,
  };
  return map[tone] ?? '';
}
