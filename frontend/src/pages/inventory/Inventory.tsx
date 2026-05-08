// Inventory — Figma "Screen 60". Practice-wide drug inventory with tabbed
// views (Active inventory / Expiring soon / Below reorder / Destruction log /
// Receiving log), filter row, and a 12-row demo table that mirrors the
// Figma canvas exactly. 1:1 port of the DB-backed PHP mock previously at
// /interface/billing/copilot_inventory.php — replaced with hardcoded static
// demo data so the component renders identically without a database.
//
// The PHP wrapper still owns the navy top nav (rendered by the parent shell);
// this component renders only the page body — page head, tab bar, filter
// bar, and the inventory table.
//
// State held in React: selected tab, filter dropdown values, and the search
// box value. Filters are inert (no real data to re-filter); they update the
// chrome only — matches the PHP behavior where filters reissued the GET.
//
// Design tokens are inlined as hex literals matching the Figma source
// (#181d26 text, #008c8c teal accent, #d93838 danger, #fa8c33 warn,
// #fceaea controlled-substance pill bg) — no Tailwind, per project rules.

import { useState } from 'react';
import type { BootContext } from '../../shared/lib/bootContext';
import styles from './Inventory.module.css';

type ExpTone = 'plain' | 'warn' | 'past';
type StockTone = 'plain' | 'warn';

type InventoryRow = {
  readonly id: number;
  readonly drug: string;
  readonly form: string;
  readonly ndc: string;
  readonly schedule: string;       // '' or 'C-IV', etc.
  readonly lot: string;
  readonly exp: string;
  readonly expTone: ExpTone;
  readonly onHand: string;
  readonly onHandTone: StockTone;
  readonly reorder: string;
  readonly location: string;
  readonly lastDispensed: string;
};

export type InventoryPayload = {
  readonly rows: readonly InventoryRow[];
};

// Verbatim demo data from Figma Screen 60 (node 109:2). Twelve rows.
const ROWS: readonly InventoryRow[] = [
  { id: 1,  drug: 'Influenza vaccine 2025-26', form: 'Quadrivalent IM', ndc: '60702-1234-1',  schedule: '',     lot: 'SF26-491',  exp: '03/15/2027', expTone: 'plain', onHand: '248',   onHandTone: 'plain', reorder: '100',   location: 'Vaccine fridge B',  lastDispensed: '04/30/2026' },
  { id: 2,  drug: 'Shingrix',                  form: 'Subunit, IM',     ndc: '58160-823-11',  schedule: '',     lot: 'RX-44291',  exp: '08/22/2027', expTone: 'plain', onHand: '64',    onHandTone: 'plain', reorder: '40',    location: 'Vaccine fridge A',  lastDispensed: '04/29/2026' },
  { id: 3,  drug: 'Tdap (Adacel)',             form: 'IM',              ndc: '49281-400-15',  schedule: '',     lot: 'TD-29010',  exp: '11/12/2026', expTone: 'plain', onHand: '32',    onHandTone: 'plain', reorder: '24',    location: 'Vaccine fridge A',  lastDispensed: '04/28/2026' },
  { id: 4,  drug: 'PCV13 (Prevnar 13)',        form: 'IM',              ndc: '00005-1971-02', schedule: '',     lot: 'PV-22918',  exp: '06/10/2026', expTone: 'warn',  onHand: '12',    onHandTone: 'warn',  reorder: '12',    location: 'Vaccine fridge A',  lastDispensed: '04/27/2026' },
  { id: 5,  drug: 'Lidocaine 1% w/epi',        form: '30mL vial',       ndc: '00409-4276-02', schedule: '',     lot: 'LC-91220',  exp: '12/02/2026', expTone: 'plain', onHand: '24',    onHandTone: 'plain', reorder: '20',    location: 'Procedure cabinet', lastDispensed: '04/29/2026' },
  { id: 6,  drug: 'Ceftriaxone 1g',            form: 'IM/IV',           ndc: '00781-3236-92', schedule: '',     lot: 'CT-44210',  exp: '08/14/2026', expTone: 'plain', onHand: '18',    onHandTone: 'plain', reorder: '15',    location: 'Med room A',        lastDispensed: '04/30/2026' },
  { id: 7,  drug: 'Naloxone 4mg nasal',        form: 'Nasal spray',     ndc: '69547-353-02',  schedule: '',     lot: 'NX-22918',  exp: '02/28/2027', expTone: 'plain', onHand: '12',    onHandTone: 'plain', reorder: '8',     location: 'Crash cart',        lastDispensed: '04/14/2026' },
  { id: 8,  drug: 'Tramadol 50mg',             form: 'Tab #100',        ndc: '00591-5713-01', schedule: 'C-IV', lot: 'TR-19292',  exp: '11/22/2026', expTone: 'warn',  onHand: '3 btl', onHandTone: 'warn',  reorder: '5 btl', location: 'Locked CS cabinet', lastDispensed: '04/30/2026' },
  { id: 9,  drug: 'Lorazepam 0.5mg',           form: 'Tab #100',        ndc: '00591-0240-05', schedule: 'C-IV', lot: 'LZ-22118',  exp: '09/14/2026', expTone: 'plain', onHand: '5 btl', onHandTone: 'warn',  reorder: '5 btl', location: 'Locked CS cabinet', lastDispensed: '04/29/2026' },
  { id: 10, drug: 'Phenobarbital 30mg',        form: 'Tab #100',        ndc: '00781-1080-13', schedule: 'C-IV', lot: 'PB-44291',  exp: '07/04/2026', expTone: 'warn',  onHand: '2 btl', onHandTone: 'warn',  reorder: '3 btl', location: 'Locked CS cabinet', lastDispensed: '04/22/2026' },
  { id: 11, drug: 'Promethazine 25mg',         form: 'IM',              ndc: '00781-3014-30', schedule: '',     lot: 'PM-29101',  exp: '05/22/2026', expTone: 'past',  onHand: '8',     onHandTone: 'warn',  reorder: '10',    location: 'Med room B',        lastDispensed: '04/22/2026' },
  { id: 12, drug: 'Methylprednisolone 4mg',    form: 'IM',              ndc: '00009-0035-04', schedule: '',     lot: 'MP-19119',  exp: '06/01/2026', expTone: 'warn',  onHand: '24',    onHandTone: 'plain', reorder: '20',    location: 'Med room A',        lastDispensed: '04/30/2026' },
];

type TabKey = 'active' | 'expiring' | 'below_reorder' | 'destruction_log' | 'receiving_log';

type TabDef = {
  readonly key: TabKey;
  readonly label: string;
  readonly count: number | null;
};

const TABS: readonly TabDef[] = [
  { key: 'active',          label: 'Active inventory', count: 124 },
  { key: 'expiring',        label: 'Expiring soon',    count: 3   },
  { key: 'below_reorder',   label: 'Below reorder',    count: 2   },
  { key: 'destruction_log', label: 'Destruction log',  count: 38  },
  { key: 'receiving_log',   label: 'Receiving log',    count: null },
];

// Filter dropdowns — values match the PHP version's option set, defaults
// match the Figma canvas (Schedule=All, Type=All, Location=All facilities,
// Status=In stock).
const SCHEDULE_OPTS = ['All', 'Rx (non-controlled)', 'Controlled (C-II–V)'] as const;
const TYPE_OPTS     = ['All', 'Tablet / Capsule', 'Injection / IV', 'Spray', 'Vial'] as const;
const LOCATION_OPTS = ['All facilities', 'Vaccine fridge A', 'Vaccine fridge B', 'Procedure cabinet', 'Med room A', 'Med room B', 'Crash cart', 'Locked CS cabinet'] as const;
const STATUS_OPTS   = ['In stock', 'All stock', 'Out of stock', 'Low'] as const;

type InventoryProps = {
  readonly boot: BootContext;
  readonly payload: InventoryPayload;
};

export function Inventory({ payload }: InventoryProps): JSX.Element {
  const [tab, setTab] = useState<TabKey>('active');
  const allRows: readonly InventoryRow[] = payload.rows.length > 0 ? payload.rows : ROWS;

  return (
    <>
      <header className={styles.pageHead}>
        <div className={styles.titleRow}>
          <span className={styles.title}>Inventory</span>
          <span className={styles.dot}>{'•'}</span>
          <span className={styles.meta}>
            Drug inventory {'·'} 124 SKUs {'·'} 3 expiring in 30d {'·'} 2 below reorder
          </span>
        </div>
        <div className={styles.spacer} />
        <button type="button" className={styles.btnGhost}>
          <span className={styles.btnIcon} aria-hidden="true">{'⬇'}</span>
          <span>Export</span>
        </button>
        <button type="button" className={styles.btnGhost}>
          <span className={styles.btnIcon} aria-hidden="true">+</span>
          <span>Receive shipment</span>
        </button>
        <button type="button" className={styles.btnDanger}>
          <span className={styles.btnIcon} aria-hidden="true">{'☓'}</span>
          <span>Record destruction (DEA)</span>
        </button>
        <button type="button" className={styles.btnHelp}>
          <span aria-hidden="true">?</span>
          <span>Help</span>
        </button>
      </header>

      <nav className={styles.tabBar} role="tablist" aria-label="Inventory views">
        {TABS.map((t) => {
          const active = tab === t.key;
          const cls = active ? `${styles.tab} ${styles.tabActive}` : styles.tab;
          const labelText = t.count !== null ? `${t.label} (${t.count})` : t.label;
          return (
            <button
              key={t.key}
              type="button"
              role="tab"
              aria-selected={active}
              className={cls}
              onClick={() => setTab(t.key)}
            >
              {labelText}
            </button>
          );
        })}
      </nav>

      <div className={styles.filterBar}>
        <div className={styles.search}>
          <span className={styles.searchIcon} aria-hidden="true">{'🔍'}</span>
          <input
            type="text"
            placeholder="Search by drug, NDC, or lot #"
            className={styles.searchInput}
            aria-label="Search by drug, NDC, or lot number"
          />
        </div>
        <FilterSelect options={SCHEDULE_OPTS} defaultValue="All"           width={116} />
        <FilterSelect options={TYPE_OPTS}     defaultValue="All"           width={104} />
        <FilterSelect options={LOCATION_OPTS} defaultValue="All facilities" width={152} />
        <FilterSelect options={STATUS_OPTS}   defaultValue="In stock"      width={124} />
      </div>

      <div className={styles.tableWrap}>
        <table className={styles.table}>
          <thead>
            <tr className={styles.thead}>
              <th className={styles.thLabel}>DRUG / FORM</th>
              <th className={styles.thLabel}>NDC</th>
              <th className={styles.thLabel}>SCHEDULE</th>
              <th className={styles.thLabel}>LOT #</th>
              <th className={styles.thLabel}>EXP DATE</th>
              <th className={styles.thLabel}>ON HAND</th>
              <th className={styles.thLabel}>REORDER</th>
              <th className={styles.thLabel}>LOCATION</th>
              <th className={styles.thLabel}>LAST DISPENSED</th>
              <th className={styles.thLabel} aria-label="Actions" />
            </tr>
          </thead>
          <tbody>
            {allRows.map((r, idx) => {
              const rowClass = idx % 2 === 1 ? `${styles.tr} ${styles.trAlt}` : styles.tr;
              return (
                <tr key={r.id} className={rowClass}>
                  <td className={styles.drugCell}>
                    <div className={styles.drugName}>{r.drug}</div>
                    <div className={styles.drugForm}>{r.form}</div>
                  </td>
                  <td className={styles.muted}>{r.ndc}</td>
                  <td>
                    {r.schedule !== '' && (
                      <span className={styles.schedPill}>{r.schedule}</span>
                    )}
                  </td>
                  <td className={styles.muted}>{r.lot}</td>
                  <td className={expClass(r.expTone)}>{r.exp}</td>
                  <td className={onHandClass(r.onHandTone)}>{r.onHand}</td>
                  <td className={styles.muted}>{r.reorder}</td>
                  <td className={styles.muted}>{r.location}</td>
                  <td className={styles.muted}>{r.lastDispensed}</td>
                  <td className={styles.actCell}>
                    <span className={styles.kebab} aria-hidden="true">{'⋯'}</span>
                  </td>
                </tr>
              );
            })}
          </tbody>
        </table>
      </div>
    </>
  );
}

function expClass(tone: ExpTone): string {
  switch (tone) {
    case 'past':  return styles['expPast'] ?? '';
    case 'warn':  return styles['expWarn'] ?? '';
    case 'plain': return styles['expPlain'] ?? '';
  }
}

function onHandClass(tone: StockTone): string {
  switch (tone) {
    case 'warn':  return styles['ohWarn'] ?? '';
    case 'plain': return styles['ohPlain'] ?? '';
  }
}

type FilterSelectProps = {
  readonly options: readonly string[];
  readonly defaultValue: string;
  readonly width: number;
};

function FilterSelect({ options, defaultValue, width }: FilterSelectProps): JSX.Element {
  const [value, setValue] = useState<string>(defaultValue);
  return (
    <label className={styles.select} style={{ width }}>
      <span className={styles.selectValue}>{value}</span>
      <span className={styles.selectCaret} aria-hidden="true">{'▾'}</span>
      <select
        className={styles.selectNative}
        value={value}
        onChange={(e) => setValue(e.target.value)}
        aria-label="Filter"
      >
        {options.map((opt) => (
          <option key={opt} value={opt}>{opt}</option>
        ))}
      </select>
    </label>
  );
}
