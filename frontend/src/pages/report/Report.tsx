// Report — Figma "Screen 14 — Report". Renders the AgentForge patient
// "Report" page: toolbar with report-type segmented control, date-range
// pill, Print and Download PDF actions, and a centered "paper" document
// with letterhead, patient block, allergies, active problems, and current
// medications.
//
// Live DB-backed: the PHP wrapper at copilot_report.php queries
// patient_data + insurance_data + lists + prescriptions + form_encounter +
// immunizations and JSON-encodes the result as data-report on #cp-root.
// The entry index.tsx parses it and passes it here as the `report` prop.
// The toolbar's report-type, date-range, and Print/PDF actions remain
// client-only state — only the paper-document body is wired to the DB.

import { useState } from 'react';
import type { BootContext } from '../../shared/lib/bootContext';
import styles from './Report.module.css';

type ReportType = 'comprehensive' | 'demographics' | 'visit' | 'custom';

type ReportTypeOption = {
  readonly key: ReportType;
  readonly label: string;
};

// Server-side payload shape (mirrors the PHP query in copilot_report.php).

export type ReportPatient = {
  readonly fname: string;
  readonly lname: string;
  readonly name: string;
  readonly sex: string;
  readonly age: number | null;
  readonly dob: string;          // 'MM/DD/YYYY' or ''
  readonly mrn: string;          // '#004821' or ''
  readonly memberSince: string;  // 'YYYY' or ''
  readonly insurance: string;
  readonly insGroup: string;
  readonly insMember: string;
  readonly providerName: string;
};

export type ReportAllergy = {
  readonly name: string;
  readonly severity: string;
  readonly reaction: string;
  readonly comments: string;
  readonly reviewed: string;     // 'MM/DD/YYYY' or ''
};

export type ReportProblem = {
  readonly icd: string;
  readonly name: string;
  readonly meta: string;
};

export type ReportMedication = {
  readonly name: string;
  readonly dose: string;
  readonly freq: string;
  readonly refill: string;
};

export type ReportVisit = {
  readonly date: string;
  readonly reason: string;
  readonly provider: string;
  readonly closed: boolean;
};

export type ReportImmunization = {
  readonly name: string;
  readonly cvx: string;
  readonly admined: string;
};

export type ReportData = {
  readonly generatedOn: string;
  readonly patient: ReportPatient;
  readonly allergies: readonly ReportAllergy[];
  readonly problems: readonly ReportProblem[];
  readonly medications: readonly ReportMedication[];
  readonly visits: readonly ReportVisit[];
  readonly immunizations: readonly ReportImmunization[];
};

const REPORT_TYPES: readonly ReportTypeOption[] = [
  { key: 'comprehensive', label: 'Comprehensive' },
  { key: 'demographics',  label: 'Demographics only' },
  { key: 'visit',         label: 'Visit summary' },
  { key: 'custom',        label: 'Custom…' },
];

const EMPTY_PATIENT: ReportPatient = {
  fname: '',
  lname: '',
  name: '',
  sex: '',
  age: null,
  dob: '',
  mrn: '',
  memberSince: '',
  insurance: '',
  insGroup: '',
  insMember: '',
  providerName: '',
};

export const EMPTY_REPORT: ReportData = {
  generatedOn: '',
  patient: EMPTY_PATIENT,
  allergies: [],
  problems: [],
  medications: [],
  visits: [],
  immunizations: [],
};

type ReportProps = {
  readonly boot: BootContext;
  readonly report: ReportData;
};

function patientLine(p: ReportPatient): string {
  const parts: string[] = [];
  if (p.sex !== '') parts.push(p.sex);
  if (p.age !== null) parts.push(`${p.age} years`);
  if (p.dob !== '') parts.push(`DOB ${p.dob}`);
  return parts.join(' • ');
}

function memberLine(p: ReportPatient): string {
  const segs: string[] = [];
  if (p.mrn !== '') segs.push(`MRN ${p.mrn}`);
  if (p.memberSince !== '') segs.push(`Member since ${p.memberSince}`);
  return segs.join(' • ');
}

function allergyLine(a: ReportAllergy): string {
  const tone = a.severity !== ''
    ? `${a.severity} reaction`
    : 'Reaction noted';
  const detail = a.reaction !== ''
    ? a.reaction
    : (a.comments !== '' ? a.comments : '');
  const head = detail !== ''
    ? `${tone} (${detail})`
    : tone;
  const reviewed = a.reviewed !== '' ? ` Reviewed ${a.reviewed}.` : '';
  return `${a.name} — ${head}.${reviewed}`;
}

export function Report({ report }: ReportProps): JSX.Element {
  const [selected, setSelected] = useState<ReportType>('comprehensive');

  const p = report.patient;
  const generatedLine = report.generatedOn !== ''
    ? `Generated ${report.generatedOn}${p.providerName !== '' ? ' by ' + p.providerName : ''}`
    : '';

  return (
    <>
      <header className={styles.toolbar}>
        <div className={styles.title}>Patient Report</div>
        <div className={styles.seg} role="tablist">
          {REPORT_TYPES.map((opt) => {
            const active = opt.key === selected;
            const cls = active ? `${styles.segOpt} ${styles.segOptActive}` : styles.segOpt;
            return (
              <button
                key={opt.key}
                type="button"
                role="tab"
                aria-selected={active}
                className={cls}
                onClick={() => setSelected(opt.key)}
              >
                {opt.label}
              </button>
            );
          })}
        </div>
        <div className={styles.spacer} />
        <span className={styles.pill}>
          <span>{'📅'}</span>
          <span className={styles.pillLabel}>Last 12 months</span>
          <span className={styles.pillCaret}>{'▾'}</span>
        </span>
        <button type="button" className={`${styles.pill} ${styles.print}`}>
          {'⎙'} Print
        </button>
        <button type="button" className={styles.pdfBtn}>Download PDF</button>
      </header>

      <main className={styles.stage}>
        <article className={styles.paper}>
          <div className={styles.letterhead}>
            <div className={styles.letterheadTop}>
              <div className={styles.logo} />
              <div>
                <div className={styles.name}>Riverside Family Medicine</div>
                <div className={styles.addr}>
                  847 Main Street, Suite 200 {'•'} Austin, TX 78701 {'•'} (512) 555-0142
                </div>
              </div>
            </div>
            <div className={styles.doctitle}>PATIENT REPORT</div>
            {generatedLine !== '' && (
              <div className={styles.gen}>{generatedLine}</div>
            )}
            <div className={styles.rule} />
          </div>

          <section className={styles.section}>
            <div className={styles.head}>PATIENT</div>
            <div className={styles.patBox}>
              <div className={styles.col}>
                <div className={styles.nm}>{p.name !== '' ? p.name : '—'}</div>
                {patientLine(p) !== '' && (
                  <div className={styles.sub}>{patientLine(p)}</div>
                )}
                {memberLine(p) !== '' && (
                  <div className={styles.sub}>{memberLine(p)}</div>
                )}
              </div>
              <div className={styles.col}>
                {p.insurance !== '' ? (
                  <div className={styles.ins}>{`Insurance: ${p.insurance}`}</div>
                ) : (
                  <div className={styles.ins}>Insurance: —</div>
                )}
                {(p.insGroup !== '' || p.insMember !== '') && (
                  <div className={styles.sub}>
                    {p.insGroup !== '' ? `Group #${p.insGroup}` : ''}
                    {p.insGroup !== '' && p.insMember !== '' ? ' • ' : ''}
                    {p.insMember !== '' ? `Member ID ${p.insMember}` : ''}
                  </div>
                )}
                {p.providerName !== '' && (
                  <div className={styles.sub}>{`Primary Provider: ${p.providerName}`}</div>
                )}
              </div>
            </div>
          </section>

          <section className={styles.section}>
            <div className={styles.head}>ALLERGIES &amp; REACTIONS</div>
            <div className={styles.allergyBox}>
              {report.allergies.length === 0 ? (
                <div>No known drug allergies on file.</div>
              ) : (
                report.allergies.map((a, i) => (
                  <div key={`${a.name}-${i}`}>{allergyLine(a)}</div>
                ))
              )}
            </div>
          </section>

          <section className={styles.section}>
            <div className={styles.head}>ACTIVE PROBLEMS</div>
            <div className={styles.table}>
              {report.problems.length === 0 ? (
                <div className={styles.row}>
                  <span className={styles.icdName}>No active problems on file.</span>
                </div>
              ) : (
                report.problems.map((pr, i) => (
                  <div key={`${pr.icd}-${pr.name}-${i}`} className={styles.row}>
                    {pr.icd !== '' && (
                      <span className={styles.icd}>{pr.icd}</span>
                    )}
                    <span className={styles.icdName}>{pr.name !== '' ? pr.name : '—'}</span>
                    <span className={styles.flex} />
                    <span className={styles.meta}>{pr.meta}</span>
                  </div>
                ))
              )}
            </div>
          </section>

          <section className={styles.section}>
            <div className={styles.head}>{`CURRENT MEDICATIONS (${report.medications.length})`}</div>
            <div className={styles.table}>
              {report.medications.length === 0 ? (
                <div className={`${styles.row} ${styles.med}`}>
                  <span className={styles.medName}>No active medications on file.</span>
                </div>
              ) : (
                report.medications.map((m, i) => (
                  <div key={`${m.name}-${i}`} className={`${styles.row} ${styles.med}`}>
                    <span className={styles.medName}>{m.name !== '' ? m.name : '—'}</span>
                    {m.dose !== '' && (
                      <span className={styles.medDose}>{m.dose}</span>
                    )}
                    {m.freq !== '' && (
                      <>
                        <span className={styles.medSep}>{'•'}</span>
                        <span className={styles.medFreq}>{m.freq}</span>
                      </>
                    )}
                    <span className={styles.flex} />
                    {m.refill !== '' && (
                      <span className={styles.medRefill}>{m.refill}</span>
                    )}
                  </div>
                ))
              )}
            </div>
          </section>

          {report.visits.length > 0 && (
            <section className={styles.section}>
              <div className={styles.head}>{`RECENT VISITS (${report.visits.length})`}</div>
              <div className={styles.table}>
                {report.visits.map((v, i) => (
                  <div key={`${v.date}-${i}`} className={styles.row}>
                    {v.date !== '' && (
                      <span className={styles.icd}>{v.date}</span>
                    )}
                    <span className={styles.icdName}>{v.reason}</span>
                    <span className={styles.flex} />
                    <span className={styles.meta}>
                      {v.provider !== '' ? v.provider : '—'}
                      {v.closed ? ' • Signed' : ''}
                    </span>
                  </div>
                ))}
              </div>
            </section>
          )}

          {report.immunizations.length > 0 && (
            <section className={styles.section}>
              <div className={styles.head}>{`IMMUNIZATIONS (${report.immunizations.length})`}</div>
              <div className={styles.table}>
                {report.immunizations.map((im, i) => (
                  <div key={`${im.name}-${i}`} className={styles.row}>
                    {im.cvx !== '' && (
                      <span className={styles.icd}>{im.cvx}</span>
                    )}
                    <span className={styles.icdName}>{im.name !== '' ? im.name : '—'}</span>
                    <span className={styles.flex} />
                    {im.admined !== '' && (
                      <span className={styles.meta}>{`Administered ${im.admined}`}</span>
                    )}
                  </div>
                ))}
              </div>
            </section>
          )}
        </article>
      </main>
    </>
  );
}
