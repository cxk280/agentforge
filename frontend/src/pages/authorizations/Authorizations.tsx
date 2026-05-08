// Authorizations — Figma "Screen 34 — Authorizations". Renders the AgentForge
// insurance prior-authorization queue: page header w/ summary meta + Export /
// New / Help buttons, 5-up KPI strip, filter strip (search + 5 selects), and a
// 13-row authorizations table.
//
// This is a 1:1 port of the PHP mock previously at
// /interface/patient_file/transaction/copilot_authorizations.php. All data is
// static demo data lifted directly from the Figma mock (node 79:2) — no DB
// queries, no CSV export wiring. The navy top-nav and demographics banner are
// rendered by the parent shell, so this component only renders the page body.
//
// Verified against Figma node 79:2 on 2026-05-07.
//
// Ported with strict TS (noUncheckedIndexedAccess + exactOptionalPropertyTypes
// on) — bracket access into option lists is coerced via `?? ''` and optional
// undefined props are typed accordingly.

import type { BootContext } from '../../shared/lib/bootContext';
import styles from './Authorizations.module.css';

type Tone = 'good' | 'warn' | 'danger' | 'neutral';

type AuthRow = {
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
};

type Kpi = {
  readonly label: string;
  readonly value: string;
  readonly tone?: 'orange' | 'green' | 'red' | undefined;
  readonly sub: string;
  readonly subTone?: 'down' | undefined;
  readonly unit?: string | undefined;
};

const KPIS: readonly Kpi[] = [
  { label: 'Awaiting payer',  value: '8',   tone: 'orange', sub: 'avg 4.2 days' },
  { label: 'Approved (30d)',  value: '42',  tone: 'green',  sub: '92% rate' },
  { label: 'Denied (30d)',    value: '3',   tone: 'red',    sub: 'all appealed' },
  { label: 'Submitted today', value: '5',   sub: '2 expedited' },
  { label: 'Avg turnaround',  value: '3.6', unit: 'd', sub: 'from 4.8 d', subTone: 'down' },
];

const ROWS: readonly AuthRow[] = [
  { auth: 'AU-2891', patient: 'Margaret Chen',  payer: 'Blue Cross PPO', service: 'Echocardiogram (cardiology)', cpt: '93306', requested: '04/28/2026', due: '05/12/2026', status: 'Approved',                tone: 'good',    provider: 'Dr. E. Rivera' },
  { auth: 'AU-2890', patient: 'David Kim',      payer: 'Aetna',          service: 'Colonoscopy + biopsy (GI)',   cpt: '45380', requested: '04/28/2026', due: '05/15/2026', status: 'Awaiting payer',           tone: 'warn',    provider: 'Dr. K. Chen' },
  { auth: 'AU-2889', patient: 'Allison Park',   payer: 'Cigna',          service: 'MRI Lumbar w/o contrast',     cpt: '72148', requested: '04/27/2026', due: '05/04/2026', status: 'Denied — appeal sent',     tone: 'danger',  provider: 'Dr. K. Chen' },
  { auth: 'AU-2888', patient: 'Carlos Mendez',  payer: 'Medicare',       service: 'Sleep study (polysomnogram)', cpt: '95810', requested: '04/27/2026', due: '05/10/2026', status: 'Awaiting payer',           tone: 'warn',    provider: 'Dr. R. Patel' },
  { auth: 'AU-2887', patient: 'Linda Martinez', payer: 'Blue Cross PPO', service: 'PT — 12 sessions (knee)',     cpt: '97110', requested: '04/26/2026', due: '05/08/2026', status: 'Approved',                tone: 'good',    provider: 'Dr. K. Chen' },
  { auth: 'AU-2886', patient: 'Emily Foster',   payer: 'Humana',         service: 'Mammogram (screening)',       cpt: '77067', requested: '04/26/2026', due: '05/06/2026', status: 'Approved',                tone: 'good',    provider: 'Dr. E. Rivera' },
  { auth: 'AU-2885', patient: 'James Brown',    payer: 'Aetna',          service: 'CT Chest w/ contrast',        cpt: '71260', requested: '04/25/2026', due: '05/02/2026', status: 'Awaiting payer',           tone: 'warn',    provider: 'Dr. R. Patel' },
  { auth: 'AU-2884', patient: 'Sarah Wilson',   payer: 'UnitedHealth',   service: 'Endoscopy (EGD)',             cpt: '43235', requested: '04/25/2026', due: '05/05/2026', status: 'Approved',                tone: 'good',    provider: 'Dr. K. Chen' },
  { auth: 'AU-2883', patient: 'Helen Garcia',   payer: 'Blue Cross PPO', service: 'Bone scan',                   cpt: '78306', requested: '04/24/2026', due: '05/01/2026', status: 'Denied — appeal pending',  tone: 'danger',  provider: 'Dr. R. Patel' },
  { auth: 'AU-2882', patient: 'Marcus Webb',    payer: 'Cigna',          service: 'MRI Brain w/ contrast',       cpt: '70553', requested: '04/24/2026', due: '04/30/2026', status: 'Approved',                tone: 'good',    provider: 'Dr. E. Rivera' },
  { auth: 'AU-2881', patient: 'Mike Tan',       payer: 'Medicare',       service: 'Stress test (nuclear)',       cpt: '78452', requested: '04/23/2026', due: '05/03/2026', status: 'Awaiting payer',           tone: 'warn',    provider: 'Dr. K. Chen' },
  { auth: 'AU-2880', patient: 'Robert Hayes',   payer: 'UnitedHealth',   service: 'PT — 8 sessions (shoulder)',  cpt: '97110', requested: '04/23/2026', due: '04/30/2026', status: 'Approved',                tone: 'good',    provider: 'NP Jones' },
  { auth: 'AU-2879', patient: 'Soo-Yeon Kim',   payer: 'Aetna',          service: 'Allergy testing panel',       cpt: '95004', requested: '04/22/2026', due: '04/29/2026', status: 'Withdrawn',                tone: 'neutral', provider: 'Dr. R. Patel' },
];

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
};

export function Authorizations(_props: AuthorizationsProps): JSX.Element {
  return (
    <>
      <header className={styles.pagehead}>
        <div className={styles.titleBlock}>
          <span className={styles.title}>Authorizations</span>
          <span className={styles.dot}>·</span>
          <span className={styles.meta}>34 active · 8 awaiting payer · 3 denied this week</span>
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
          {KPIS.map((kpi, i) => (
            <div key={kpi.label} className={styles.kpiCell}>
              <div className={styles.kpiLabel}>{kpi.label}</div>
              <div className={styles.kpiRow}>
                <span className={kpiValueClass(kpi.tone)}>
                  {kpi.value}
                  {kpi.unit !== undefined && <span className={styles.kpiUnit}>{` ${kpi.unit}`}</span>}
                </span>
                <span className={styles.kpiSub}>
                  {kpi.subTone === 'down' && <span className={styles.kpiDown}>↓ </span>}
                  {kpi.subTone === 'down' ? `from ${kpi.sub.replace(/^from\s*/, '')}` : kpi.sub}
                </span>
              </div>
              {i < KPIS.length - 1 && <div className={styles.kpiSeparator} />}
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
              {ROWS.map((r, idx) => (
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
              ))}
            </tbody>
          </table>
        </div>
      </main>
    </>
  );
}

function kpiValueClass(tone: Kpi['tone']): string {
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
