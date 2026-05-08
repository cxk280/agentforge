// ExternalData — Figma "Screen 19 — External Data" (fileKey kj4MWNr8mpjZ2wVg1PbS0F,
// nodeId 40:2). Renders the External Data Sources page header, a 4-up grid of
// source connection cards (HIE / LabCorp / Imaging / Surescripts), and a
// "Recent Imports" feed.
//
// 1:1 port of the static PHP mock previously at
// /interface/patient_file/external_data/copilot_external_data.php — same demo
// data, same visual treatment. Patient-context page; the navy nav and
// patient demographics banner are owned by the outer OpenEMR shell, not this
// component.
//
// Reference: frontend/.fidelity-references/external_data-figma-2026-05-07.png

import type { BootContext } from '../../shared/lib/bootContext';
import styles from './ExternalData.module.css';

type Tone = 'good' | 'info' | 'violet' | 'warn';
type StatusTone = 'good' | 'warn';
type ActionVariant = 'primary' | 'secondary';

type SourceCard = {
  readonly name: string;
  readonly icon: string;
  readonly tone: Tone;
  readonly status: string;
  readonly statusTone: StatusTone;
  readonly sub: string;
  readonly count: number;
};

type ImportRow = {
  readonly icon: string;
  readonly tone: Tone;
  readonly title: string;
  readonly type: string;
  readonly src: string;
  readonly date: string;
  readonly fields: number;
  readonly status: string;
  readonly statusTone: StatusTone;
  readonly action: string;
  readonly actionVariant: ActionVariant;
};

const SOURCES: readonly SourceCard[] = [
  { name: 'Texas HIE — CommonWell',   icon: '🌐', tone: 'good',   status: 'Connected',    statusTone: 'good', sub: 'Today 08:14 AM',              count: 24 },
  { name: 'LabCorp Direct Connect',   icon: '🧪', tone: 'info',   status: 'Connected',    statusTone: 'good', sub: 'Today 08:14 AM',              count: 18 },
  { name: 'Riverside Imaging API',    icon: '🩻', tone: 'violet', status: 'Connected',    statusTone: 'good', sub: 'Yesterday',                   count: 5  },
  { name: 'Surescripts Rx History',   icon: '💊', tone: 'warn',   status: 'Needs review', statusTone: 'warn', sub: '1 record awaiting reconcile', count: 1  },
];

const IMPORTS: readonly ImportRow[] = [
  { icon: '🌐', tone: 'good',   title: 'ED Visit — Riverside General Hospital',              type: 'Encounter Summary',   src: 'Texas HIE',          date: 'Apr 9, 2026',  fields: 12, status: 'Reconciled',   statusTone: 'good', action: 'View',      actionVariant: 'secondary' },
  { icon: '🧪', tone: 'info',   title: 'Comprehensive Metabolic Panel + CBC',                type: 'Lab Result',          src: 'LabCorp Direct',     date: 'Apr 12, 2026', fields: 18, status: 'Reconciled',   statusTone: 'good', action: 'View',      actionVariant: 'secondary' },
  { icon: '💊', tone: 'warn',   title: 'External Rx: Atorvastatin 20mg → 40mg (Walgreens)',  type: 'Medication History',  src: 'Surescripts',        date: 'Apr 8, 2026',  fields: 1,  status: 'Needs review', statusTone: 'warn', action: 'Reconcile', actionVariant: 'primary'   },
  { icon: '🌐', tone: 'good',   title: 'DEXA scan — South Austin Imaging',                   type: 'Imaging Report',      src: 'Texas HIE',          date: 'Mar 22, 2026', fields: 4,  status: 'Reconciled',   statusTone: 'good', action: 'View',      actionVariant: 'secondary' },
  { icon: '🩻', tone: 'violet', title: 'Bilateral knee X-Ray report',                        type: 'Radiology',           src: 'Riverside Imaging',  date: 'Feb 18, 2026', fields: 6,  status: 'Reconciled',   statusTone: 'good', action: 'View',      actionVariant: 'secondary' },
  { icon: '🌐', tone: 'good',   title: 'Influenza vaccine — Riverside Pharmacy',             type: 'Immunization',        src: 'Texas HIE',          date: 'Oct 15, 2025', fields: 3,  status: 'Reconciled',   statusTone: 'good', action: 'View',      actionVariant: 'secondary' },
];

const ICON_TONE_CLASS: Readonly<Record<Tone, string>> = {
  good:   styles.iconGood   ?? '',
  info:   styles.iconInfo   ?? '',
  violet: styles.iconViolet ?? '',
  warn:   styles.iconWarn   ?? '',
};

const STATUS_TONE_CLASS: Readonly<Record<StatusTone, string>> = {
  good: styles.statusGood ?? '',
  warn: styles.statusWarn ?? '',
};

const PILL_TONE_CLASS: Readonly<Record<StatusTone, string>> = {
  good: styles.pillGood ?? '',
  warn: styles.pillWarn ?? '',
};

const ACTION_VARIANT_CLASS: Readonly<Record<ActionVariant, string>> = {
  primary:   styles.actionPrimary   ?? '',
  secondary: styles.actionSecondary ?? '',
};

type ExternalDataProps = {
  readonly boot: BootContext;
};

export function ExternalData(_props: ExternalDataProps): JSX.Element {
  return (
    <>
      <header className={styles.head}>
        <div className={styles.title}>External Data Sources</div>
        <div className={styles.bullet}>•</div>
        <div className={styles.meta}>3 connected • 1 pending review</div>
        <div className={styles.spacer} />
        <button type="button" className={styles.pill}>
          <span>⟳</span>
          <span>Sync sources</span>
        </button>
        <button type="button" className={styles.primary}>
          <span>⬆</span>
          <span>Import CCDA</span>
        </button>
      </header>

      <main className={styles.body}>
        <section className={styles.sourceGrid}>
          {SOURCES.map((s) => (
            <article key={s.name} className={styles.sourceCard}>
              <div className={styles.sourceTop}>
                <span className={`${styles.sourceIcon} ${ICON_TONE_CLASS[s.tone]}`}>
                  {s.icon}
                </span>
                <div>
                  <div className={styles.sourceName}>{s.name}</div>
                  <div className={`${styles.sourceStatus} ${STATUS_TONE_CLASS[s.statusTone]}`}>
                    <span className={styles.dot} />
                    <span>{s.status}</span>
                  </div>
                </div>
              </div>
              <div className={styles.sourceSub}>{s.sub}</div>
              <div className={styles.sourceStats}>
                <span className={styles.sourceNum}>{s.count}</span>
                <span className={styles.sourceLbl}>records</span>
              </div>
            </article>
          ))}
        </section>

        <section className={styles.impCard}>
          <header className={styles.impHead}>
            <div className={styles.impTitle}>Recent Imports</div>
            <div className={styles.spacer} />
            <span className={styles.impLink}>View all →</span>
          </header>
          {IMPORTS.map((im, i) => {
            const rowClass = i === IMPORTS.length - 1
              ? `${styles.impRow} ${styles.impRowLast}`
              : styles.impRow;
            return (
              <div key={`${im.title}-${i}`} className={rowClass}>
                <span className={`${styles.impIcon} ${ICON_TONE_CLASS[im.tone]}`}>
                  {im.icon}
                </span>
                <div className={styles.impInfo}>
                  <div className={styles.impTitleRow}>
                    <span className={styles.impRowTitle}>{im.title}</span>
                    <span className={styles.typePill}>{im.type}</span>
                  </div>
                  <div className={styles.impSub}>
                    {im.src} • {im.date} • {im.fields} fields
                  </div>
                </div>
                <div className={styles.spacer} />
                <span className={`${styles.statusPill} ${PILL_TONE_CLASS[im.statusTone]}`}>
                  {im.statusTone === 'good' ? '✓ ' : ''}{im.status}
                </span>
                <button
                  type="button"
                  className={`${styles.action} ${ACTION_VARIANT_CLASS[im.actionVariant]}`}
                >
                  {im.action}{im.action === 'Reconcile' ? ' →' : ''}
                </button>
              </div>
            );
          })}
        </section>
      </main>
    </>
  );
}
