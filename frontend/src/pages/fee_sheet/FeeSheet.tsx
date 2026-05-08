// FeeSheet — Figma "Screen 24 — Fee Sheet" (file kj4MWNr8mpjZ2wVg1PbS0F,
// node 53:2). Two-column body: LEFT a code picker (search, category pills,
// popular CPT list with Add/Added states); RIGHT a "Selected for this
// visit" panel with CPT/ICD line items and a totals box.
//
// 1:1 port of the static PHP mock previously at
// /interface/forms/fee_sheet/copilot_fee_sheet.php (preserved at .bak for a
// side-by-side diff). Per task scope, the navy nav and demographics banner
// are intentionally skipped — this iframe page only renders the page body.
//
// State is hardcoded demo data — no DB, no POST. Wiring to a real
// /apis/copilot/fee_sheet endpoint is a follow-up.

import type { BootContext } from '../../shared/lib/bootContext';
import styles from './FeeSheet.module.css';

type AddState = 'add' | 'added';

type CategoryPill = {
  readonly label: string;
  readonly active: boolean;
};

type PopularCode = {
  readonly code: string;
  readonly label: string;
  readonly price: string;
  readonly state: AddState;
};

type SelectedRow = {
  readonly type: 'CPT' | 'ICD';
  readonly code: string;
  readonly label: string;
  readonly qty?: string | undefined;
  readonly price?: string | undefined;
};

const CATEGORY_PILLS: readonly CategoryPill[] = [
  { label: 'Office Visits',  active: true  },
  { label: 'Labs',           active: false },
  { label: 'Procedures',     active: false },
  { label: 'Diagnostics',    active: false },
  { label: 'Vaccinations',   active: false },
  { label: 'Codes (ICD-10)', active: false },
];

const POPULAR_CODES: readonly PopularCode[] = [
  { code: '99211', label: 'Established patient, minimal',     price: '$45.00',  state: 'add'   },
  { code: '99212', label: 'Established patient, level 2',     price: '$98.00',  state: 'add'   },
  { code: '99213', label: 'Established patient, level 3',     price: '$152.00', state: 'added' },
  { code: '99214', label: 'Established patient, level 4',     price: '$215.00', state: 'add'   },
  { code: '99215', label: 'Established patient, level 5',     price: '$310.00', state: 'add'   },
  { code: '99396', label: 'Annual wellness, age 40-64',       price: '$280.00', state: 'add'   },
  { code: '99397', label: 'Annual wellness, age 65+',         price: '$305.00', state: 'add'   },
  { code: '80050', label: 'Comprehensive metabolic panel',    price: '$184.00', state: 'add'   },
];

const SELECTED_ROWS: readonly SelectedRow[] = [
  { type: 'CPT', code: '99213', label: 'Office visit, level 3', qty: 'Qty: 1', price: '$152.00' },
  { type: 'CPT', code: '36415', label: 'Venipuncture',          qty: 'Qty: 1', price: '$15.00'  },
  { type: 'ICD', code: 'E11.9', label: 'Type 2 Diabetes Mellitus' },
  { type: 'ICD', code: 'I10',   label: 'Essential hypertension' },
];

type FeeSheetProps = {
  readonly boot: BootContext;
};

export function FeeSheet(_props: FeeSheetProps): JSX.Element {
  return (
    <>
      <header className={styles.head}>
        <div className={styles.title}>Fee Sheet</div>
        <div className={styles.bullet}>•</div>
        <div className={styles.meta}>Today's visit • Charges and CPT/ICD selection</div>
        <div className={styles.spacer} />
        <div className={styles.subtotal}>
          <span className={styles.subtotalLbl}>Subtotal</span>
          <span className={styles.subtotalVal}>$152.00</span>
        </div>
        <button type="button" className={`${styles.btn} ${styles.btnGhost}`}>Save draft</button>
        <button type="button" className={`${styles.btn} ${styles.btnPrimary}`}>Save &amp; close →</button>
      </header>

      <main className={styles.body}>
        <section className={styles.panel}>
          <div className={styles.panelLbl}>CODE PICKER</div>

          <div className={styles.searchWrap}>
            <span className={styles.searchIc}>🔍</span>
            <input type="text" placeholder="Search by code, name, or CPT..." />
          </div>

          <div className={styles.cats}>
            {CATEGORY_PILLS.map((pill) => (
              <button
                key={pill.label}
                type="button"
                className={pill.active ? `${styles.catPill} ${styles.catPillActive}` : styles.catPill}
              >
                {pill.label}
              </button>
            ))}
          </div>

          <div className={styles.catSub}>POPULAR FOR PRIMARY CARE</div>

          <div className={styles.codeList}>
            {POPULAR_CODES.map((row) => {
              const rowCls = row.state === 'added'
                ? `${styles.codeRow} ${styles.codeRowSelected}`
                : styles.codeRow;
              const btnCls = row.state === 'added'
                ? `${styles.addBtn} ${styles.addBtnAdded}`
                : styles.addBtn;
              return (
                <div key={row.code} className={rowCls}>
                  <span className={styles.codeCode}>{row.code}</span>
                  <span className={styles.codeLabel}>{row.label}</span>
                  <span className={styles.codePrice}>{row.price}</span>
                  <button type="button" className={btnCls}>
                    {row.state === 'added' ? '✓ Added' : '+ Add'}
                  </button>
                </div>
              );
            })}
          </div>
        </section>

        <section className={styles.panel}>
          <div className={styles.panelHead}>
            <h3>Selected for this visit</h3>
            <span className={styles.cnt}>4</span>
          </div>

          <div className={styles.selList}>
            {SELECTED_ROWS.map((row) => {
              const tagCls = row.type === 'CPT'
                ? `${styles.selTag} ${styles.selTagCpt}`
                : `${styles.selTag} ${styles.selTagIcd}`;
              return (
                <div key={`${row.type}-${row.code}`} className={styles.selRow}>
                  <span className={tagCls}>{row.type}</span>
                  <span className={styles.selCode}>{row.code}</span>
                  <span className={styles.selLabel}>{row.label}</span>
                  {row.qty !== undefined && row.qty !== '' && (
                    <span className={styles.selQty}>{row.qty}</span>
                  )}
                  {row.price !== undefined && row.price !== '' && (
                    <span className={styles.selPrice}>{row.price}</span>
                  )}
                  <button type="button" className={styles.selX} aria-label="Remove">✕</button>
                </div>
              );
            })}
          </div>

          <div className={styles.totals}>
            <div className={styles.totalsRow}>
              <span>Subtotal</span>
              <span className={styles.totalsVal}>$167.00</span>
            </div>
            <div className={`${styles.totalsRow} ${styles.totalsRowNeg}`}>
              <span>Insurance estimate</span>
              <span className={styles.totalsVal}>−$142.00</span>
            </div>
            <div className={styles.totalsRow}>
              <span>Patient responsibility</span>
              <span className={styles.totalsVal}>$25.00</span>
            </div>
            <div className={`${styles.totalsRow} ${styles.totalsRowTotal}`}>
              <span>Total billed</span>
              <span className={styles.totalsVal}>$167.00</span>
            </div>
          </div>
        </section>
      </main>
    </>
  );
}
