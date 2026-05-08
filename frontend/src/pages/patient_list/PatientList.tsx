// PatientList — Figma "Screen 43 — Patient List Report".
//
// 1:1 port of the PHP-rendered mock previously at
// /interface/reports/copilot_patient_list.php. Cohort table is static demo
// data matched to the Figma design; the original PHP version's database
// integration (cohort SQL, save_view / export_csv POST handlers, real
// dropdown options) is intentionally not preserved — this is a UI mock.
//
// The Figma sub-nav on the left is included here (it lives inside the
// Reports area body); the navy top nav is owned by the React Header at
// /frontend/src/pages/header/.

import type { BootContext } from '../../shared/lib/bootContext';
import styles from './PatientList.module.css';

type Hba1cTone = 'normal' | 'warn' | 'danger';

type PatientRow = {
  readonly name: string;
  readonly mrn: string;
  readonly dob: string;
  readonly age: number;
  readonly sex: 'M' | 'F';
  readonly lastVisit: string;
  readonly provider: string;
  readonly insurance: string;
  readonly dx: string;
  readonly hba1c: number;
  readonly tone: Hba1cTone;
};

export type PatientListPayload = {
  readonly rows: readonly PatientRow[];
  readonly total: number;
};

type SidebarGroup = {
  readonly label: string;
  readonly items: readonly { readonly name: string; readonly active: boolean }[];
};

// Verified against Figma node 91:2 (Screen 43 — Patient List Report) on
// 2026-05-07. Order, labels and "active" flag come straight from the design.
const SIDEBAR: readonly SidebarGroup[] = [
  {
    label: 'Clinical',
    items: [
      { name: 'Patient List',     active: true  },
      { name: 'Prescriptions',    active: false },
      { name: 'Lab Trends',       active: false },
      { name: 'Quality Measures', active: false },
      { name: 'Immunizations',    active: false },
      { name: 'Encounters',       active: false },
    ],
  },
  {
    label: 'Financial',
    items: [
      { name: 'Daily Cash',  active: false },
      { name: 'Aging',       active: false },
      { name: 'Payer Mix',   active: false },
      { name: 'Collections', active: false },
    ],
  },
  {
    label: 'Operations',
    items: [
      { name: 'Visit Volume',          active: false },
      { name: 'Provider Productivity', active: false },
      { name: 'No-shows',              active: false },
    ],
  },
  {
    label: 'Electronic',
    items: [
      { name: 'Submissions',   active: false },
      { name: 'CCDA Exports',  active: false },
      { name: 'HIE Sync',      active: false },
      { name: 'Public Health', active: false },
    ],
  },
];

type FilterChip = {
  readonly label: string;
  readonly value: string;
  readonly width: number; // Figma-fixed widths so the bar matches the design.
};

const FILTERS: readonly FilterChip[] = [
  { label: 'Provider',     value: 'Dr. E. Rivera, Dr. K. Chen', width: 220 },
  { label: 'Age range',    value: '60+ years',                  width: 136 },
  { label: 'Sex',          value: 'All',                        width: 96  },
  { label: 'Visit window', value: 'Last 12 months',             width: 152 },
  { label: 'Diagnosis',    value: 'Diabetes (E11)',             width: 172 },
  { label: 'Insurance',    value: 'All payers',                 width: 144 },
  { label: 'Payor status', value: 'Active',                     width: 124 },
];

const ROWS: readonly PatientRow[] = [
  { name: 'Margaret Chen',  mrn: '#004821', dob: '03/14/1958', age: 68, sex: 'F', lastVisit: '02/18/2026', provider: 'Dr. Rivera', insurance: 'Blue Cross PPO', dx: 'E11.9',           hba1c: 7.9, tone: 'danger' },
  { name: 'Ted Shaw',       mrn: '#000001', dob: '03/12/1965', age: 61, sex: 'M', lastVisit: '01/22/2026', provider: 'Dr. Rivera', insurance: 'Medicare',       dx: 'E11.9, I10',      hba1c: 6.8, tone: 'normal' },
  { name: 'Linda Martinez', mrn: '#003918', dob: '11/02/1947', age: 78, sex: 'F', lastVisit: '03/05/2026', provider: 'Dr. Rivera', insurance: 'Medicare',       dx: 'E11.9, I10, I25', hba1c: 7.2, tone: 'warn'   },
  { name: 'David Kim',      mrn: '#006102', dob: '06/18/1981', age: 44, sex: 'M', lastVisit: '11/15/2025', provider: 'Dr. Chen',   insurance: 'Aetna',          dx: 'E11.9, K57',      hba1c: 6.4, tone: 'normal' },
  { name: 'Allison Park',   mrn: '#002745', dob: '09/30/1973', age: 52, sex: 'F', lastVisit: '12/08/2025', provider: 'Dr. Chen',   insurance: 'Cigna',          dx: 'E11.9, M17',      hba1c: 7.0, tone: 'normal' },
  { name: 'Carlos Mendez',  mrn: '#004102', dob: '08/22/1955', age: 70, sex: 'M', lastVisit: '02/02/2026', provider: 'Dr. Chen',   insurance: 'Medicare',       dx: 'E11.9, I10, J45', hba1c: 6.6, tone: 'normal' },
  { name: 'Emily Foster',   mrn: '#005544', dob: '05/10/1959', age: 67, sex: 'F', lastVisit: '03/22/2026', provider: 'Dr. Rivera', insurance: 'Humana',         dx: 'E11.9, E03.9',    hba1c: 5.6, tone: 'normal' },
  { name: 'James Brown',    mrn: '#002188', dob: '01/14/1961', age: 65, sex: 'M', lastVisit: '01/28/2026', provider: 'Dr. Chen',   insurance: 'Medicare',       dx: 'E11.9, I10',      hba1c: 7.4, tone: 'warn'   },
  { name: 'Helen Garcia',   mrn: '#003021', dob: '11/22/1954', age: 71, sex: 'F', lastVisit: '02/15/2026', provider: 'Dr. Patel',  insurance: 'Blue Cross PPO', dx: 'E11.9, N18.3',    hba1c: 8.1, tone: 'danger' },
  { name: 'Marcus Webb',    mrn: '#004411', dob: '04/04/1968', age: 58, sex: 'M', lastVisit: '01/10/2026', provider: 'Dr. Rivera', insurance: 'Cigna',          dx: 'E11.9, F32.9',    hba1c: 6.9, tone: 'normal' },
  { name: 'Mike Tan',       mrn: '#003456', dob: '07/30/1962', age: 63, sex: 'M', lastVisit: '12/22/2025', provider: 'Dr. Chen',   insurance: 'Medicare',       dx: 'E11.9, J44',      hba1c: 6.5, tone: 'normal' },
  { name: 'Robert Hayes',   mrn: '#001821', dob: '03/19/1957', age: 69, sex: 'M', lastVisit: '02/28/2026', provider: 'NP Jones',   insurance: 'UnitedHealth',   dx: 'E11.9, I10, M54', hba1c: 7.1, tone: 'warn'   },
  { name: 'Soo-Yeon Kim',   mrn: '#005903', dob: '12/05/1960', age: 65, sex: 'F', lastVisit: '03/30/2026', provider: 'Dr. Patel',  insurance: 'Aetna',          dx: 'E11.9, M81',      hba1c: 6.7, tone: 'normal' },
  { name: 'Sarah Wilson',   mrn: '#005891', dob: '08/14/1965', age: 60, sex: 'F', lastVisit: '01/18/2026', provider: 'Dr. Rivera', insurance: 'UnitedHealth',   dx: 'E11.9',           hba1c: 5.6, tone: 'normal' },
];

const TOTAL_LABEL = '1,847 patients matching filters · last refreshed 2 min ago';

type PatientListProps = {
  readonly boot: BootContext;
  readonly payload: PatientListPayload;
};

export function PatientList({ payload }: PatientListProps): JSX.Element {
  const liveRows = payload.rows;
  const rowsToRender: readonly PatientRow[] = liveRows.length > 0 ? liveRows : ROWS;
  const totalLabel = liveRows.length > 0
    ? `${payload.total.toLocaleString()} ${payload.total === 1 ? 'patient' : 'patients'} matching filters · live`
    : TOTAL_LABEL;
  return (
    <div className={styles.root}>
      <aside className={styles.side}>
        <div className={styles.sideHeader}>REPORTS</div>
        {SIDEBAR.map((group) => (
          <div key={group.label} className={styles.sideGroup}>
            <div className={styles.sideGroupLabel}>{group.label}</div>
            {group.items.map((item) => {
              const cls = item.active
                ? `${styles.sideItem} ${styles.sideItemActive}`
                : styles.sideItem;
              return (
                <a key={item.name} className={cls} href="#" onClick={(e) => e.preventDefault()}>
                  {item.name}
                </a>
              );
            })}
          </div>
        ))}
      </aside>

      <div className={styles.divider} />

      <main className={styles.main}>
        <header className={styles.pageHead}>
          <div className={styles.titleBlock}>
            <span className={styles.title}>Patient List</span>
            <span className={styles.dot}>·</span>
            <span className={styles.subtitle}>{totalLabel}</span>
          </div>
          <div className={styles.headSpacer} />
          <button type="button" className={styles.btnGhost}>
            <span aria-hidden="true">⊞</span>
            <span>Save view</span>
          </button>
          <button type="button" className={styles.btnGhost}>
            <span aria-hidden="true">⬇</span>
            <span>Export CSV</span>
          </button>
          <button type="button" className={styles.btnPrimary}>Run</button>
        </header>

        <div className={styles.headBorder} />

        <section className={styles.filterBar}>
          <div className={styles.filterTitle}>FILTERS</div>
          <div className={styles.filterRow}>
            {FILTERS.map((f) => (
              <div
                key={f.label}
                className={styles.filterChip}
                style={{ width: f.width }}
              >
                <span className={styles.chipLabel}>{f.label}</span>
                <span className={styles.chipValue}>{f.value}</span>
                <span className={styles.chipCaret} aria-hidden="true">▾</span>
              </div>
            ))}
          </div>
        </section>

        <div className={styles.headBorder} />

        <div className={styles.tableWrap}>
          <table className={styles.table}>
            <thead>
              <tr>
                <th className={styles.thChk}>
                  <span className={styles.checkbox} aria-hidden="true" />
                </th>
                <th>NAME</th>
                <th>MRN</th>
                <th>DOB</th>
                <th>AGE</th>
                <th>SEX</th>
                <th>LAST VISIT</th>
                <th>PROVIDER</th>
                <th>INSURANCE</th>
                <th>DX</th>
                <th className={styles.thHba1c}>HBA1C</th>
                <th className={styles.thStatus}>STATUS</th>
              </tr>
            </thead>
            <tbody>
              {rowsToRender.map((r, i) => (
                <tr key={r.mrn} className={i % 2 === 1 ? styles.rowAlt : undefined}>
                  <td className={styles.tdChk}>
                    <span className={styles.checkbox} aria-hidden="true" />
                  </td>
                  <td className={styles.tdName}>{r.name}</td>
                  <td className={styles.tdMuted}>{r.mrn}</td>
                  <td className={styles.tdMuted}>{r.dob}</td>
                  <td className={styles.tdStrong}>{r.age}</td>
                  <td className={styles.tdMuted}>{r.sex}</td>
                  <td className={styles.tdStrong}>{r.lastVisit}</td>
                  <td className={styles.tdMuted}>{r.provider}</td>
                  <td className={styles.tdMuted}>{r.insurance}</td>
                  <td className={styles.tdMuted}>{r.dx}</td>
                  <td className={hba1cClass(r.tone)}>
                    {r.hba1c.toFixed(1)}
                    {r.tone !== 'normal' && <span className={styles.hba1cArrow}> ↑</span>}
                  </td>
                  <td className={styles.tdStatus}>⋯</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </main>
    </div>
  );
}

function hba1cClass(tone: Hba1cTone): string {
  if (tone === 'danger') { return `${styles.tdHba1c} ${styles.hba1cDanger}`; }
  if (tone === 'warn')   { return `${styles.tdHba1c} ${styles.hba1cWarn}`; }
  return `${styles.tdHba1c} ${styles.hba1cNormal}`;
}
