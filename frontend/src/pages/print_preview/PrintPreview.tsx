// PrintPreview — Figma "Screen 61 — Print Preview".
//
// 1:1 port of the body of the PHP-rendered mock at
// /interface/main/copilot_print_preview.php. The PHP version supported a
// ?type= query param across 12 archetypes and read live patient/facility data
// from MySQL; for the React port we render with hardcoded static demo data
// that matches the Figma mock exactly (Margaret Chen / Riverside Family
// Medicine), and the type-picker drives client-side React state. Wiring to a
// real /apis/copilot/print endpoint is a follow-up.
//
// Layout: 3-column body — output type sidebar | print settings | preview
// stage with a paper card. The page header (title, printer pill, Save PDF,
// Print, Help) renders above the body. The PHP outer shell still owns the
// navy top nav and patient header2 banner — this component is rendered into
// the #maimain iframe at /interface/main/copilot_print_preview.php.
//
// Confidentiality affects which sections render on the demographics paper:
//   - standard           → all sections shown
//   - restricted         → insurance hidden
//   - highly_restricted  → insurance, problems, meds, allergies hidden

import { useState } from 'react';
import type { BootContext } from '../../shared/lib/bootContext';
import styles from './PrintPreview.module.css';

type OutputType =
  | 'demographics'
  | 'superbill'
  | 'referral'
  | 'generic_letter'
  | 'address_labels'
  | 'visit_summary'
  | 'imaging_order'
  | 'lab_requisition'
  | 'prescription'
  | 'wristband'
  | 'appointment'
  | 'statement';

type PaperSize = 'letter' | 'legal' | 'a4';
type Orientation = 'portrait' | 'landscape';
type Confidentiality = 'standard' | 'restricted' | 'highly_restricted';
type IncludeKey =
  | 'practice_header'
  | 'patient_demos'
  | 'insurance_info'
  | 'active_meds'
  | 'active_problems'
  | 'allergies'
  | 'recent_labs'
  | 'care_team'
  | 'signoff';

type OutputTypeDef = {
  readonly slug: OutputType;
  readonly icon: string;
  readonly label: string;
};

type IncludeDef = {
  readonly key: IncludeKey;
  readonly label: string;
};

const OUTPUT_TYPES: readonly OutputTypeDef[] = [
  { slug: 'demographics',     icon: '📋', label: 'Demographics summary' },
  { slug: 'superbill',        icon: '💵', label: 'Superbill' },
  { slug: 'referral',         icon: '📤', label: 'Referral letter' },
  { slug: 'generic_letter',   icon: '✉',  label: 'Generic letter' },
  { slug: 'address_labels',   icon: '🏷', label: 'Address labels' },
  { slug: 'visit_summary',    icon: '📃', label: 'Visit summary' },
  { slug: 'imaging_order',    icon: '🩻', label: 'Imaging order' },
  { slug: 'lab_requisition',  icon: '🧪', label: 'Lab requisition' },
  { slug: 'prescription',     icon: '💊', label: 'Prescription' },
  { slug: 'wristband',        icon: '🆔', label: 'Wristband' },
  { slug: 'appointment',      icon: '📅', label: 'Appointment confirmation' },
  { slug: 'statement',        icon: '📨', label: 'Statement' },
];

const INCLUDES: readonly IncludeDef[] = [
  { key: 'practice_header',  label: 'Practice header / logo' },
  { key: 'patient_demos',    label: 'Patient demographics' },
  { key: 'insurance_info',   label: 'Insurance info' },
  { key: 'active_meds',      label: 'Active medications' },
  { key: 'active_problems',  label: 'Active problems' },
  { key: 'allergies',        label: 'Allergies' },
  { key: 'recent_labs',      label: 'Recent labs (last 90d)' },
  { key: 'care_team',        label: 'Care team / referrals' },
  { key: 'signoff',          label: 'Sign-off line' },
];

const DEFAULT_INCLUDES: ReadonlySet<IncludeKey> = new Set<IncludeKey>([
  'practice_header',
  'patient_demos',
  'insurance_info',
  'active_meds',
  'active_problems',
  'allergies',
  'signoff',
]);

const PRINTERS: readonly string[] = [
  'HP-LJ-Front Desk',
  'HP-LJ-Nursing',
  'Brother-MFC-Back Office',
];

// ── Hardcoded demo data (matches the Figma mock 1:1) ─────────────────────────

const FACILITY = {
  name: 'OpenEMR',
  brand: 'RIVERSIDE FAMILY MEDICINE',
  meta1: '4521 Riverside Drive · Austin, TX 78704 · (512) 555-0100',
  meta2: 'NPI 1234567890 · Tax ID 74-3219876 · Generated 05/02/2026 10:14 CT',
} as const;

const PATIENT = {
  name: 'Margaret Chen',
  mrn: '004821',
  dob: '03/14/1958 (68 years)',
  sex: 'Female',
  language: 'English',
  marital: 'Married',
  address: '847 Main Street, Suite 200',
  cityStateZip: 'Austin, TX 78701',
  phone: '(512) 555-0142',
  email: 'm.chen@example.com',
  emergency: 'Robert Chen (spouse) (512) 555-0143',
} as const;

const INSURANCE = {
  plan: 'Blue Cross Blue Shield PPO',
  memberId: '4QF23-991',
  group: 'BCBS-7281',
  effective: '01/01/2026 – 12/31/2026',
} as const;

const PROBLEMS: readonly string[] = [
  'Type 2 diabetes mellitus (E11.9) — since 2019',
  'Essential hypertension (I10) — since 2017',
  'Hypothyroidism (E03.9) — since 2021',
  'Osteoarthritis, knees (M17.0) — since 2022',
];

const MEDS: readonly string[] = [
  'Metformin 1000 mg — BID with meals',
  'Lisinopril 10 mg — daily (increased 04/01)',
  'Levothyroxine 50 mcg — daily AM',
  'Atorvastatin 40 mg — nightly',
];

const ALLERGIES: readonly string[] = [
  'Penicillin — mild rash, itching',
  'Sulfa drugs — mild skin reaction',
];

// ── Helpers ──────────────────────────────────────────────────────────────────

function paperSizeLabel(size: PaperSize): string {
  switch (size) {
    case 'letter': return 'US Letter (8.5 × 11 in)';
    case 'legal':  return 'US Legal (8.5 × 14 in)';
    case 'a4':     return 'A4 (210 × 297 mm)';
  }
}

function paperSizeShort(size: PaperSize): string {
  switch (size) {
    case 'letter': return '8.5 × 11 in';
    case 'legal':  return '8.5 × 14 in';
    case 'a4':     return '210 × 297 mm';
  }
}

function orientationLabel(o: Orientation): string {
  return o === 'portrait' ? 'Portrait' : 'Landscape';
}

// ── Component ────────────────────────────────────────────────────────────────

type PrintPreviewProps = {
  readonly boot: BootContext;
};

export function PrintPreview(_props: PrintPreviewProps): JSX.Element {
  const [selectedType, setSelectedType] = useState<OutputType>('demographics');
  const [paperSize, setPaperSize] = useState<PaperSize>('letter');
  const [orientation, setOrientation] = useState<Orientation>('portrait');
  const [copies, setCopies] = useState<number>(1);
  const [confidentiality, setConfidentiality] = useState<Confidentiality>('standard');
  const [printer, setPrinter] = useState<string>(PRINTERS[0] ?? 'HP-LJ-Front Desk');
  const [includes, setIncludes] = useState<ReadonlySet<IncludeKey>>(DEFAULT_INCLUDES);

  const selectedTypeDef = OUTPUT_TYPES.find((t) => t.slug === selectedType) ?? OUTPUT_TYPES[0];
  const pageCount = 1;
  const subtitle = `${selectedTypeDef!.label} · ${pageCount} page${pageCount === 1 ? '' : 's'}`;

  const showInsurance = confidentiality === 'standard';
  const showMeds      = confidentiality !== 'highly_restricted';
  const showAllergies = confidentiality !== 'highly_restricted';
  const showProblems  = confidentiality !== 'highly_restricted';

  const toggleInclude = (key: IncludeKey): void => {
    setIncludes((prev) => {
      const next = new Set(prev);
      if (next.has(key)) {
        next.delete(key);
      } else {
        next.add(key);
      }
      return next;
    });
  };

  const handleCopiesChange = (raw: string): void => {
    const n = Number.parseInt(raw, 10);
    if (!Number.isFinite(n)) {
      setCopies(1);
      return;
    }
    setCopies(Math.max(1, Math.min(99, n)));
  };

  return (
    <>
      <header className={styles.head}>
        <div className={styles.headInfo}>
          <span className={styles.headTitle}>Print Preview</span>
          <span className={styles.headBullet}>•</span>
          <span className={styles.headMeta}>{subtitle}</span>
        </div>
        <div className={styles.headSpacer} />

        <div className={styles.printerSel}>
          <span className={styles.printerSelIcon}>🖨</span>
          <select
            className={styles.printerSelInput}
            value={printer}
            onChange={(e) => setPrinter(e.target.value)}
            aria-label="Printer"
          >
            {PRINTERS.map((p) => (
              <option key={p} value={p}>{p}</option>
            ))}
          </select>
          <span className={styles.printerSelCar}>▾</span>
        </div>

        <button type="button" className={`${styles.btn} ${styles.btnGhost}`}>
          <span aria-hidden>⬇</span>
          <span>Save PDF</span>
        </button>

        <button type="button" className={`${styles.btn} ${styles.btnPrimary}`}>
          <span aria-hidden>🖨</span>
          <span>{`Print ${copies} ${copies === 1 ? 'page' : 'pages'}`}</span>
        </button>

        <button
          type="button"
          className={styles.helpPill}
          disabled
          title="Out of scope for demo"
        >
          ? Help
        </button>
      </header>

      <div className={styles.body}>
        <aside className={styles.sidebar}>
          <div className={styles.sidebarLabel}>OUTPUT TYPE</div>
          {OUTPUT_TYPES.map((t) => {
            const active = t.slug === selectedType;
            const cls = active
              ? `${styles.cat} ${styles.catActive}`
              : styles.cat;
            return (
              <button
                key={t.slug}
                type="button"
                className={cls}
                onClick={() => setSelectedType(t.slug)}
              >
                <span className={styles.catIcon}>{t.icon}</span>
                <span>{t.label}</span>
              </button>
            );
          })}
        </aside>

        <div className={styles.settingsWrap}>
          <div className={styles.settings}>
            <div className={styles.settingsLabel}>PRINT SETTINGS</div>

            <div className={styles.fieldLabel}>Paper size</div>
            <div className={styles.select}>
              <select
                value={paperSize}
                onChange={(e) => setPaperSize(e.target.value as PaperSize)}
                aria-label="Paper size"
              >
                <option value="letter">{paperSizeLabel('letter')}</option>
                <option value="legal">{paperSizeLabel('legal')}</option>
                <option value="a4">{paperSizeLabel('a4')}</option>
              </select>
              <span className={styles.selectCar}>▾</span>
            </div>

            <div className={`${styles.fieldLabel} ${styles.fieldLabelSpacer}`}>Orientation</div>
            <div className={styles.orient} role="tablist">
              <OrientButton mode="portrait"  current={orientation} onSelect={setOrientation}>Portrait</OrientButton>
              <OrientButton mode="landscape" current={orientation} onSelect={setOrientation}>Landscape</OrientButton>
            </div>

            <div className={`${styles.fieldLabel} ${styles.fieldLabelSpacer}`}>Copies</div>
            <div className={styles.input}>
              <input
                type="number"
                min={1}
                max={99}
                value={copies}
                onChange={(e) => handleCopiesChange(e.target.value)}
                aria-label="Copies"
              />
              <span className={styles.inputStep}>⊟</span>
            </div>

            <div className={`${styles.fieldLabel} ${styles.includeLabel}`}>INCLUDE</div>
            <div className={styles.includeList}>
              {INCLUDES.map((inc) => {
                const on = includes.has(inc.key);
                const cls = on
                  ? `${styles.check} ${styles.checkOn}`
                  : styles.check;
                return (
                  <label key={inc.key} className={cls}>
                    <input
                      type="checkbox"
                      checked={on}
                      onChange={() => toggleInclude(inc.key)}
                    />
                    <span className={styles.checkBox} aria-hidden>{on ? '✓' : ''}</span>
                    <span className={styles.checkLabel}>{inc.label}</span>
                  </label>
                );
              })}
            </div>

            <div className={`${styles.fieldLabel} ${styles.fieldLabelSpacer}`}>Confidentiality</div>
            <div className={styles.select}>
              <select
                value={confidentiality}
                onChange={(e) => setConfidentiality(e.target.value as Confidentiality)}
                aria-label="Confidentiality"
              >
                <option value="standard">Standard</option>
                <option value="restricted">Restricted</option>
                <option value="highly_restricted">Highly restricted</option>
              </select>
              <span className={styles.selectCar}>▾</span>
            </div>
          </div>
        </div>

        <div className={styles.stage}>
          <div className={styles.scaleLabel}>
            {`${paperSizeShort(paperSize)} · 100% scale · ${orientationLabel(orientation)}`}
          </div>
          <div className={styles.paperWrap}>
            <div
              className={
                orientation === 'landscape'
                  ? `${styles.paper} ${styles.paperLandscape}`
                  : styles.paper
              }
            >
              {selectedType === 'demographics' ? (
                <DemographicsPaper
                  showInsurance={showInsurance && includes.has('insurance_info')}
                  showProblems={showProblems && includes.has('active_problems')}
                  showMeds={showMeds && includes.has('active_meds')}
                  showAllergies={showAllergies && includes.has('allergies')}
                  showHeader={includes.has('practice_header')}
                  showDemos={includes.has('patient_demos')}
                  showSignoff={includes.has('signoff')}
                  confidentiality={confidentiality}
                />
              ) : (
                <ComingSoon label={selectedTypeDef!.label} onBack={() => setSelectedType('demographics')} />
              )}
            </div>
          </div>
        </div>
      </div>
    </>
  );
}

// ── Subcomponents ────────────────────────────────────────────────────────────

type OrientButtonProps = {
  readonly mode: Orientation;
  readonly current: Orientation;
  readonly onSelect: (o: Orientation) => void;
  readonly children: React.ReactNode;
};

function OrientButton({ mode, current, onSelect, children }: OrientButtonProps): JSX.Element {
  const active = mode === current;
  const cls = active
    ? `${styles.orientBtn} ${styles.orientBtnActive}`
    : styles.orientBtn;
  return (
    <button
      type="button"
      role="tab"
      aria-selected={active}
      className={cls}
      onClick={() => onSelect(mode)}
    >
      {children}
    </button>
  );
}

type DemographicsPaperProps = {
  readonly showHeader: boolean;
  readonly showDemos: boolean;
  readonly showInsurance: boolean;
  readonly showProblems: boolean;
  readonly showMeds: boolean;
  readonly showAllergies: boolean;
  readonly showSignoff: boolean;
  readonly confidentiality: Confidentiality;
};

function DemographicsPaper(props: DemographicsPaperProps): JSX.Element {
  const confLabel = props.confidentiality === 'highly_restricted'
    ? 'Highly Restricted'
    : props.confidentiality === 'restricted'
      ? 'Restricted'
      : 'Standard';

  return (
    <>
      {props.showHeader && (
        <div className={styles.paperHead}>
          <div className={styles.paperLogo} />
          <div className={styles.paperBrand}>
            <div className={styles.paperBrandTitle}>{FACILITY.name}</div>
            <div className={styles.paperBrandClinic}>{FACILITY.brand}</div>
          </div>
          <div className={styles.paperHeadMeta}>
            <div className={styles.paperHeadMetaL1}>{FACILITY.meta1}</div>
            <div className={styles.paperHeadMetaL2}>{FACILITY.meta2}</div>
          </div>
        </div>
      )}

      <div className={styles.paperTitle}>PATIENT DEMOGRAPHICS SUMMARY</div>

      <div className={styles.paperGrid}>
        <div className={styles.paperLcol}>
          {props.showDemos && (
            <>
              <div className={styles.paperSection}>IDENTITY</div>
              <KV k="Patient name"       v={PATIENT.name} />
              <KV k="Date of birth"      v={PATIENT.dob} />
              <KV k="Preferred language" v={PATIENT.language} />

              <div className={`${styles.paperSection} ${styles.paperSectionSpacer}`}>CONTACT</div>
              <KV k="Address"            v={PATIENT.address} />
              <KV k="City / State / ZIP" v={PATIENT.cityStateZip} />
              <KV k="Phone"              v={PATIENT.phone} />
              <KV k="Email"              v={PATIENT.email} />
              <KV k="Emergency contact"  v={PATIENT.emergency} />
            </>
          )}

          {props.showInsurance && (
            <>
              <div className={`${styles.paperSection} ${styles.paperSectionSpacer}`}>INSURANCE</div>
              <KV k="Primary plan" v={INSURANCE.plan} />
              <KV k="Member ID"    v={INSURANCE.memberId} />
              <KV k="Group #"      v={INSURANCE.group} />
              <KV k="Effective"    v={INSURANCE.effective} />
            </>
          )}
        </div>

        <div className={styles.paperRcol}>
          {props.showDemos && (
            <>
              <KV k="MRN"            v={PATIENT.mrn} />
              <KV k="Sex"            v={PATIENT.sex} />
              <KV k="Marital status" v={PATIENT.marital} />
            </>
          )}

          {props.showProblems && (
            <>
              <div className={`${styles.paperSection} ${styles.paperSectionSpacer}`}>ACTIVE PROBLEMS</div>
              <ul className={styles.bullets}>
                {PROBLEMS.map((p) => (
                  <li key={p}>• {p}</li>
                ))}
              </ul>
            </>
          )}

          {props.showMeds && (
            <>
              <div className={`${styles.paperSection} ${styles.paperSectionSpacer}`}>CURRENT MEDICATIONS</div>
              <ul className={styles.bullets}>
                {MEDS.map((m) => (
                  <li key={m}>• {m}</li>
                ))}
              </ul>
            </>
          )}

          {props.showAllergies && (
            <>
              <div className={`${styles.paperSection} ${styles.paperSectionSpacer}`}>ALLERGIES</div>
              <ul className={`${styles.bullets} ${styles.bulletsAllergies}`}>
                {ALLERGIES.map((a) => (
                  <li key={a}>• {a}</li>
                ))}
              </ul>
            </>
          )}
        </div>
      </div>

      {props.showSignoff && (
        <div className={styles.paperFooter}>
          <div className={styles.paperSignoff}>
            <span className={styles.paperSignoffLbl}>Reviewed by:</span>
            <span className={styles.paperSignoffLine}>&nbsp;</span>
            <span className={`${styles.paperSignoffLbl} ${styles.paperSignoffLblRight}`}>Date:</span>
            <span className={styles.paperSignoffLine}>&nbsp;</span>
          </div>
          <div className={styles.paperPagefooter}>
            {`Page 1 of 1 · Confidentiality: ${confLabel} · Confidential — protected health information`}
          </div>
        </div>
      )}
    </>
  );
}

type KVProps = {
  readonly k: string;
  readonly v: string;
};

function KV({ k, v }: KVProps): JSX.Element {
  return (
    <div className={styles.kv}>
      <span className={styles.kvK}>{k}</span>
      <span className={styles.kvV}>{v}</span>
    </div>
  );
}

type ComingSoonProps = {
  readonly label: string;
  readonly onBack: () => void;
};

function ComingSoon({ label, onBack }: ComingSoonProps): JSX.Element {
  return (
    <div className={styles.comingSoon}>
      <h2>{label}</h2>
      <p>This output type is coming soon.</p>
      <button type="button" className={styles.comingSoonBack} onClick={onBack}>
        Back to demographics summary
      </button>
    </div>
  );
}
