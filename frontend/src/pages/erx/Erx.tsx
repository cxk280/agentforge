// e-Rx — Figma "Screen 25 — e-Rx" (node 54:2, fileKey kj4MWNr8mpjZ2wVg1PbS0F).
//
// 1:1 port of the static-PHP mock previously at /interface/eRx/copilot_erx.php.
// The navy top nav and patient demographics banner are owned by the outer
// shell at /interface/main/tabs/main.php — this file renders only the body
// (page header + two-column layout).
//
// Layout: page-head row (title + EPCS pill + Save draft + Send to pharmacy),
// then a two-column body. LEFT (560px): Drug Search panel + Current
// Medications panel. RIGHT (flex 1): selected-drug strip, prescription form,
// safety-check card, no-allergy-conflicts card.
//
// Demo data is hardcoded to match the Figma reference exactly.
//
// State: only `selectedDrug` is interactive — clicking a drug-search row
// updates the highlighted "Selected" pill and the form's drug strip. All
// other inputs are static, like the previous PHP mock.
//
// Verified against frontend/.fidelity-references/erx-figma-2026-05-07.png
// on 2026-05-07.

import { useState } from 'react';
import type { BootContext } from '../../shared/lib/bootContext';
import styles from './Erx.module.css';

type DrugOption = {
  readonly id: string;
  readonly name: string;
};

const DRUG_OPTIONS: readonly DrugOption[] = [
  { id: 'lisinopril-10', name: 'Lisinopril 10 mg tablet' },
  { id: 'lisinopril-20', name: 'Lisinopril 20 mg tablet' },
  { id: 'lisinopril-hctz', name: 'Lisinopril–HCTZ 10/12.5 mg' },
  { id: 'lisinopril-5', name: 'Lisinopril 5 mg tablet' },
];

type CurrentMed = {
  readonly name: string;
  readonly dose: string;
  readonly note: string;
};

const CURRENT_MEDS: readonly CurrentMed[] = [
  { name: 'Metformin',     dose: '1000 mg', note: 'BID with meals' },
  { name: 'Lisinopril',    dose: '10 mg',   note: 'Daily — increased 04/01' },
  { name: 'Levothyroxine', dose: '50 mcg',  note: 'Daily, AM' },
  { name: 'Atorvastatin',  dose: '40 mg',   note: 'Nightly' },
];

type ErxProps = {
  readonly boot: BootContext;
};

export function Erx(_props: ErxProps): JSX.Element {
  const [selectedDrugId, setSelectedDrugId] = useState<string>('lisinopril-10');
  const selectedDrug =
    DRUG_OPTIONS.find((d) => d.id === selectedDrugId) ?? DRUG_OPTIONS[0];
  const selectedName = selectedDrug?.name ?? '';

  return (
    <>
      <header className={styles.head}>
        <div className={styles.title}>e-Rx</div>
        <div className={styles.bullet}>•</div>
        <div className={styles.meta}>New prescription</div>
        <div className={styles.spacer} />
        <span className={styles.epcs}>
          <span className={styles.epcsIc} aria-hidden="true">&#128274;</span>
          <span>EPCS active</span>
        </span>
        <div className={styles.headDivider} />
        <button className={styles.btnGhost} type="button">Save draft</button>
        <button className={styles.btnPrimary} type="button">
          <span>Send to pharmacy</span>
          <span aria-hidden="true">&rarr;</span>
        </button>
      </header>

      <main className={styles.body}>
        <div className={styles.left}>
          <section className={styles.panel}>
            <div className={styles.panelLbl}>DRUG SEARCH</div>
            <div className={styles.search}>
              <span className={styles.searchIc} aria-hidden="true">&#128269;</span>
              <span className={styles.searchText}>lisinop</span>
              <span className={styles.searchCaret} aria-hidden="true">|</span>
            </div>
            <div className={styles.drugList}>
              {DRUG_OPTIONS.map((d) => {
                const sel = d.id === selectedDrugId;
                const cls = sel
                  ? `${styles.drugRow} ${styles.drugRowSel}`
                  : styles.drugRow;
                return (
                  <button
                    key={d.id}
                    type="button"
                    className={cls}
                    onClick={() => setSelectedDrugId(d.id)}
                  >
                    <span className={styles.pillIc} aria-hidden="true">&#128138;</span>
                    <span className={styles.drugName}>{d.name}</span>
                    {sel && (
                      <span className={styles.drugSelectedPill}>Selected</span>
                    )}
                  </button>
                );
              })}
            </div>
          </section>

          <section className={`${styles.panel} ${styles.medsPanel}`}>
            <div className={styles.medsHead}>
              <h3 className={styles.medsTitle}>Current Medications</h3>
              <span className={styles.medsCount}>{CURRENT_MEDS.length}</span>
              <div className={styles.spacer} />
              <button type="button" className={styles.medsHistory}>
                <span aria-hidden="true">&#8853;</span>
                <span>History</span>
              </button>
            </div>
            <div className={styles.medsList}>
              {CURRENT_MEDS.map((m) => (
                <div key={m.name} className={styles.medsRow}>
                  <span className={styles.pillIc} aria-hidden="true">&#128138;</span>
                  <div className={styles.medsInfo}>
                    <div className={styles.medsTop}>
                      <span className={styles.medsName}>{m.name}</span>
                      <span className={styles.medsDose}>{m.dose}</span>
                    </div>
                    <div className={styles.medsSub}>{m.note}</div>
                  </div>
                  <span className={styles.statusActive}>
                    <span aria-hidden="true">&#9679;</span> Active
                  </span>
                </div>
              ))}
            </div>
          </section>
        </div>

        <div className={styles.right}>
          <section className={styles.formPanel}>
            <div className={styles.selRow}>
              <div className={styles.selPill}>
                <span className={styles.selIc} aria-hidden="true">&#128138;</span>
                <div className={styles.selInfo}>
                  <div className={styles.selName}>{selectedName}</div>
                  <div className={styles.selBrand}>
                    Brand: Prinivil/Zestril • Generic OK
                  </div>
                </div>
              </div>
              <div className={styles.spacer} />
              <button type="button" className={styles.changeDrug}>
                Change drug
              </button>
            </div>

            <div className={styles.row2}>
              <Field label="Strength">
                <Select value="10 mg" />
              </Field>
              <Field label="Dosage form">
                <Select value="Tablet" />
              </Field>
            </div>

            <Field label="Sig (instructions to patient)">
              <Input value="Take 1 tablet by mouth once daily for blood pressure" />
            </Field>

            <div className={styles.row3}>
              <Field label="Quantity">
                <Input value="90" />
              </Field>
              <Field label="Days supply">
                <Input value="90" />
              </Field>
              <Field label="Refills">
                <Select value="3" />
              </Field>
            </div>

            <div className={styles.row2}>
              <Field label="Pharmacy">
                <Select value="CVS — 4500 Burnet Rd, Austin TX" />
              </Field>
              <Field label="Delivery">
                <Select value="Pickup" />
              </Field>
            </div>

            <div className={styles.row2}>
              <Field label="DAW">
                <Select value="No (allow generic)" />
              </Field>
              <Field label="Effective date">
                <Input value="04/29/2026" />
              </Field>
            </div>

            <Field label="Internal note">
              <Input value="" placeholder="" />
            </Field>
          </section>

          <section className={`${styles.safetyCard} ${styles.safetyWarn}`}>
            <div className={styles.safetyHead}>
              <span aria-hidden="true">&#9888;</span>
              <span>1 SAFETY CHECK</span>
            </div>
            <div className={styles.safetyBody}>
              Dose increased from 5 mg &rarr; 10 mg on 04/01/2026 &mdash;
              confirm titration plan and 6-week recheck order.
            </div>
          </section>

          <section className={`${styles.safetyCard} ${styles.safetyOk}`}>
            <div className={styles.safetyHead}>
              <span aria-hidden="true">&#10003;</span>
              <span>NO ALLERGIES OR INTERACTIONS DETECTED</span>
            </div>
            <div className={styles.safetyBody}>
              Lisinopril checked against Penicillin allergy and current
              medication list. No conflicts.
            </div>
          </section>
        </div>
      </main>
    </>
  );
}

type FieldProps = {
  readonly label: string;
  readonly children: React.ReactNode;
};

function Field({ label, children }: FieldProps): JSX.Element {
  return (
    <div className={styles.field}>
      <label className={styles.fieldLabel}>{label}</label>
      {children}
    </div>
  );
}

type InputProps = {
  readonly value: string;
  readonly placeholder?: string | undefined;
};

function Input({ value, placeholder }: InputProps): JSX.Element {
  return (
    <div className={styles.input}>
      {value !== '' ? (
        <span className={styles.inputText}>{value}</span>
      ) : (
        <span className={styles.inputPlaceholder}>{placeholder ?? ''}</span>
      )}
    </div>
  );
}

type SelectProps = {
  readonly value: string;
};

function Select({ value }: SelectProps): JSX.Element {
  return (
    <div className={styles.input}>
      <span className={styles.inputText}>{value}</span>
      <span className={styles.caret} aria-hidden="true">&#9662;</span>
    </div>
  );
}
