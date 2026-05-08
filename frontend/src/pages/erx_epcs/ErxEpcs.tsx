// ErxEpcs — Figma "Screen 42 — e-Rx EPCS".
//
// EPCS (Electronic Prescriptions for Controlled Substances) signing queue.
// Renders the background queue (prescriptions awaiting EPCS sign-off) with a
// two-factor authentication modal layered on top — enter password + 6-digit
// authenticator code, attest 21 CFR 1311 compliance, then "Sign & send".
//
// This is a React port of the DB-backed PHP page previously at
// /interface/eRx/copilot_erx_epcs.php (preserved at .php.bak). Behaviors
// preserved from the PHP original:
//
//   - Clicking a queue row sets that rx as "selected", which resolves to a
//     patient context; the modal then shows ALL awaiting-sign Rx for that
//     patient (cross-rx, same-patient grouping). Rows belonging to the
//     selected patient render with a teal check; only that one row is
//     visually highlighted as `is-selected`.
//   - "Cancel" / "×" closes the modal (clears selection — same as the PHP
//     ?  href that returns to the queue with no `selected` query param).
//   - Form posts the selected rx_ids[] + password + otp + attest. Validation
//     mirrors PHP `cp_epcs_valid_otp` (4-6 digits) + non-empty password +
//     attest checkbox. On success, a flash banner appears ("N prescription(s)
//     signed and queued for transmission"); on validation failure, the
//     specific reasons are listed inline.
//   - The Help button on the pagehead is permanently disabled (matches PHP).
//
// The DB-backed dataset is replaced with a hardcoded representative payload
// matching the Figma (Allison Park × 2, Marcus Webb × 2, total 4 Rx /
// 2 patients). Allison Park's Tramadol (Schedule IV) + Oxycodone (Schedule
// II) are the modal's default focus — same as `selected = first row`
// resolving to first-patient grouping in PHP.

import { useState } from 'react';
import type { BootContext } from '../../shared/lib/bootContext';
import styles from './ErxEpcs.module.css';

type DeaSchedule = 'II' | 'III' | 'IV' | 'V';

type RxRow = {
  readonly id: number;
  readonly patientId: number;
  readonly patientName: string;
  readonly mrn: string;
  readonly dob: string;
  readonly sex: string;
  readonly drug: string;
  readonly schedule: DeaSchedule;
  readonly sigLine: string;
  readonly pharmacyLine: string;
};

type FlashTone = 'good' | 'danger';

type Flash = {
  readonly tone: FlashTone;
  readonly text: string;
};

// Hardcoded queue — matches Figma screen 42 (Allison Park × 2, Marcus Webb × 2).
// Shape mirrors what the PHP queue SELECT produced: id, patient_id, patient
// name, MRN, DOB, sex, drug, cp_dea_schedule, sig line, pharmacy/note line.
const QUEUE: readonly RxRow[] = [
  {
    id: 101,
    patientId: 27,
    patientName: 'Allison Park',
    mrn: '#002745',
    dob: '09/30/1973',
    sex: 'Female',
    drug: 'Tramadol 50 mg',
    schedule: 'IV',
    sigLine: 'Cap, q6h PRN pain . Dispense 20 . no refills',
    pharmacyLine: 'CVS Pharmacy #4291 . Austin, TX',
  },
  {
    id: 102,
    patientId: 27,
    patientName: 'Allison Park',
    mrn: '#002745',
    dob: '09/30/1973',
    sex: 'Female',
    drug: 'Oxycodone 5 mg',
    schedule: 'II',
    sigLine: 'Tab, q4-6h PRN severe pain . Dispense 15 . no refills',
    pharmacyLine: 'CVS Pharmacy #4291 . Austin, TX',
  },
  {
    id: 103,
    patientId: 41,
    patientName: 'Marcus Webb',
    mrn: '#004411',
    dob: '02/14/1986',
    sex: 'Male',
    drug: 'Lorazepam 1 mg',
    schedule: 'IV',
    sigLine: 'Tab, qHS PRN anxiety . Dispense 30 . no refills',
    pharmacyLine: 'Walgreens #2018 . Austin, TX',
  },
  {
    id: 104,
    patientId: 41,
    patientName: 'Marcus Webb',
    mrn: '#004411',
    dob: '02/14/1986',
    sex: 'Male',
    drug: 'Adderall XR 20 mg',
    schedule: 'II',
    sigLine: 'Cap, daily AM . Dispense 30 . no refills',
    pharmacyLine: 'Walgreens #2018 . Austin, TX',
  },
];

// Distinct patient count for the queue header badge.
const DISTINCT_PATIENTS: number = (() => {
  const set = new Set<number>();
  for (const r of QUEUE) {
    set.add(r.patientId);
  }
  return set.size;
})();

// Validate OTP: 4-6 digits (PHP cp_epcs_valid_otp).
function isValidOtp(otp: string): boolean {
  return /^[0-9]{4,6}$/.test(otp);
}

// Schedule label for the modal Rx pill.
function scheduleLabel(s: DeaSchedule): string {
  return `Schedule ${s}`;
}

// Map schedule to pill tone class (Schedule II = danger/red, III/IV/V = warn/amber).
function scheduleToneClass(s: DeaSchedule): string {
  return s === 'II' ? styles['pillSchedII'] ?? '' : styles['pillSchedIv'] ?? '';
}

// Plural helper — matches `N prescription` / `N prescriptions` PHP behavior.
function pluralize(n: number, singular: string, plural: string): string {
  return `${n} ${n === 1 ? singular : plural}`;
}

type ErxEpcsProps = {
  readonly boot: BootContext;
};

export function ErxEpcs(_props: ErxEpcsProps): JSX.Element {
  // Initial selection — first row in the queue (mirrors PHP default behavior
  // when no ?selected= query param is set: focus the first awaiting-sign rx).
  const initialSelectedId: number | null = QUEUE.length > 0 ? (QUEUE[0]?.id ?? null) : null;

  const [selectedRxId, setSelectedRxId] = useState<number | null>(initialSelectedId);
  const [password, setPassword] = useState<string>('');
  const [otp, setOtp] = useState<string>('');
  const [attest, setAttest] = useState<boolean>(false);
  const [flash, setFlash] = useState<Flash | null>(null);

  // Resolve the focus rx + its patient grouping (modalRxs = all awaiting-sign
  // Rx for the same patient_id). Mirrors PHP block at lines 240-273.
  const focusRx: RxRow | null =
    selectedRxId !== null
      ? (QUEUE.find((r) => r.id === selectedRxId) ?? null)
      : null;
  const modalPatient: RxRow | null = focusRx;
  const modalRxs: readonly RxRow[] =
    focusRx !== null ? QUEUE.filter((r) => r.patientId === focusRx.patientId) : [];
  const showModal = modalPatient !== null && modalRxs.length > 0;

  const onRowClick = (rxId: number): void => {
    setSelectedRxId(rxId);
    setFlash(null);
  };

  const closeModal = (): void => {
    setSelectedRxId(null);
    setPassword('');
    setOtp('');
    setAttest(false);
  };

  const onSubmit = (e: React.FormEvent<HTMLFormElement>): void => {
    e.preventDefault();
    const errors: string[] = [];
    if (modalRxs.length === 0) {
      errors.push('No prescriptions selected');
    }
    if (password === '') {
      errors.push('Password required');
    }
    if (!isValidOtp(otp)) {
      errors.push('Authenticator code must be 4-6 digits');
    }
    if (!attest) {
      errors.push('Compliance attestation must be checked');
    }
    if (errors.length > 0) {
      setFlash({ tone: 'danger', text: 'Cannot sign: ' + errors.join(' . ') });
      return;
    }
    const n = modalRxs.length;
    setFlash({
      tone: 'good',
      text: pluralize(n, 'prescription', 'prescriptions') + ' signed and queued for transmission',
    });
    closeModal();
  };

  const queueCountLabel: string =
    pluralize(QUEUE.length, 'prescription', 'prescriptions') +
    ' . ' +
    pluralize(DISTINCT_PATIENTS, 'patient', 'patients');

  return (
    <>
      <header className={styles.pagehead}>
        <div className={styles.info}>
          <div className={styles.titleRow}>
            <span className={styles.title}>EPCS Signing Queue</span>
            <span className={styles.bullet}>&bull;</span>
            <span className={styles.meta}>DEA-controlled prescriptions &middot; 2FA required</span>
          </div>
        </div>
        <button
          type="button"
          className={`${styles.btn} ${styles.btnGhost}`}
          disabled
          title="Help is out of scope for this demo"
        >
          ? Help
        </button>
      </header>

      {flash !== null && (
        <div
          className={
            flash.tone === 'good'
              ? `${styles.flash} ${styles.flashGood}`
              : `${styles.flash} ${styles.flashDanger}`
          }
        >
          {flash.text}
        </div>
      )}

      <div className={styles.wrap}>
        <section className={styles.queue}>
          <div className={styles.queueHead}>
            <span className={styles.queueLabel}>Prescriptions awaiting EPCS sign-off</span>
            <span className={styles.queueBadge}>{queueCountLabel}</span>
          </div>

          {QUEUE.length === 0 ? (
            <div className={styles.queueEmpty}>
              No prescriptions awaiting EPCS sign-off.
            </div>
          ) : (
            QUEUE.map((r) => {
              const isSel = r.id === selectedRxId;
              const isPatientSel =
                modalPatient !== null && r.patientId === modalPatient.patientId;
              const rowCls = isSel
                ? `${styles.row} ${styles.rowSelected}`
                : styles.row;
              return (
                <button
                  key={r.id}
                  type="button"
                  className={rowCls}
                  onClick={() => onRowClick(r.id)}
                  aria-pressed={isSel}
                >
                  <span
                    className={
                      isPatientSel ? `${styles.chk} ${styles.chkOn}` : styles.chk
                    }
                    aria-hidden="true"
                  >
                    {isPatientSel ? '✓' : ''}
                  </span>
                  <span className={styles.avatar} aria-hidden="true" />
                  <div className={styles.who}>
                    <div className={styles.whoName}>{r.patientName}</div>
                    <div className={styles.whoMrn}>{r.mrn}</div>
                  </div>
                  <div className={styles.rx}>
                    <span className={styles.rxPill} aria-hidden="true" />
                    <div>
                      <div className={styles.rxName}>{r.drug}</div>
                      <div className={styles.rxSig}>{r.sigLine}</div>
                    </div>
                  </div>
                  <span className={styles.viewLink}>View details &rarr;</span>
                </button>
              );
            })
          )}
        </section>

        {showModal && modalPatient !== null && (
          <div
            className={styles.modalOverlay}
            onClick={(e) => {
              if (e.target === e.currentTarget) {
                closeModal();
              }
            }}
          >
            <form
              className={styles.modal}
              role="dialog"
              aria-modal="true"
              aria-labelledby="epcs-modal-title"
              onSubmit={onSubmit}
              autoComplete="off"
            >
              <div className={styles.modalHead}>
                <span className={styles.modalLock} aria-hidden="true">
                  &#128274;
                </span>
                <div className={styles.modalTi}>
                  <span className={styles.modalT} id="epcs-modal-title">
                    EPCS Two-Factor Authentication
                  </span>
                  <span className={styles.modalS}>
                    {`Sign ${modalRxs.length} controlled Rx for ${modalPatient.patientName}`}
                  </span>
                </div>
                <button
                  type="button"
                  className={styles.modalX}
                  aria-label="Close"
                  onClick={closeModal}
                >
                  &times;
                </button>
              </div>

              <div>
                <div className={styles.secLbl}>PATIENT</div>
                <div className={styles.ptRow}>
                  <span className={styles.ptAvatar} aria-hidden="true" />
                  <div>
                    <div className={styles.ptName}>{modalPatient.patientName}</div>
                    <div className={styles.ptMeta}>
                      MRN {modalPatient.mrn} &middot; DOB {modalPatient.dob}
                      {modalPatient.sex !== '' && (
                        <>
                          {' '}&middot; {modalPatient.sex}
                        </>
                      )}
                    </div>
                  </div>
                </div>
              </div>

              <div>
                <div className={styles.secLbl}>PRESCRIPTIONS</div>
                <div className={styles.rxList}>
                  {modalRxs.map((rx, i) => (
                    <div key={rx.id} className={styles.rxItem}>
                      <div className={styles.rxItemTop}>
                        <span className={styles.rxItemNum}>{i + 1}.</span>
                        <span className={styles.rxItemName}>{rx.drug}</span>
                        <span className={`${styles.pill} ${scheduleToneClass(rx.schedule)}`}>
                          {scheduleLabel(rx.schedule)}
                        </span>
                      </div>
                      <div className={styles.rxItemSig}>{rx.sigLine}</div>
                      <div className={styles.rxItemSig}>{rx.pharmacyLine}</div>
                    </div>
                  ))}
                </div>
              </div>

              <div className={styles.fields}>
                <div className={styles.fld}>
                  <label htmlFor="epcs-pwd">PASSWORD</label>
                  <input
                    id="epcs-pwd"
                    type="password"
                    name="password"
                    autoComplete="off"
                    required
                    value={password}
                    onChange={(e) => setPassword(e.target.value)}
                  />
                </div>
                <div className={styles.fld}>
                  <label htmlFor="epcs-otp">AUTHENTICATOR CODE</label>
                  <div className={styles.inputWrap}>
                    <input
                      id="epcs-otp"
                      className={`${styles.authInput} ${styles.focused}`}
                      type="text"
                      name="otp"
                      placeholder="4729"
                      maxLength={6}
                      inputMode="numeric"
                      pattern="[0-9]{4,6}"
                      autoComplete="one-time-code"
                      required
                      value={otp}
                      onChange={(e) => setOtp(e.target.value)}
                    />
                    <span className={styles.hintR}>Yubikey &middot; 6-digit</span>
                  </div>
                </div>
              </div>

              <label className={styles.attest}>
                <input
                  type="checkbox"
                  name="attest"
                  value="1"
                  required
                  checked={attest}
                  onChange={(e) => setAttest(e.target.checked)}
                />
                <span className={styles.attestTxt}>
                  I certify these prescriptions comply with 21 CFR 1311 and DEA EPCS
                  requirements.
                </span>
              </label>

              <div className={styles.modalFoot}>
                <button
                  type="button"
                  className={`${styles.btn} ${styles.btnGhost}`}
                  onClick={closeModal}
                >
                  Cancel
                </button>
                <button type="submit" className={`${styles.btn} ${styles.btnPrimary}`}>
                  Sign &amp; send
                </button>
              </div>
            </form>
          </div>
        )}
      </div>
    </>
  );
}
