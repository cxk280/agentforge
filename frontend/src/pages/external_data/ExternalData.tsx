// ExternalData — Figma "Screen 19 — External Data" (fileKey kj4MWNr8mpjZ2wVg1PbS0F,
// nodeId 40:2). Renders the External Data Sources page header, a 4-up grid of
// source connection cards (HIE / LabCorp / Imaging / Surescripts), and a
// "Recent Imports" feed.
//
// Originally a 1:1 port of the static PHP mock at
// /interface/patient_file/external_data/copilot_external_data.php — that .bak
// also shipped hardcoded demo arrays (no DB-backed external_data_sources
// table exists in this build). The PHP wrapper now JSON-encodes those same
// arrays onto data-sources / data-imports / data-summary on #cp-root and the
// entry index.tsx parses them and passes them here as props, so the React
// page is consistent with the Finder data-attribute → prop pattern even
// while the underlying data is still stub. When real ingestion adapters
// land, only the PHP wrapper has to change.
//
// Reference: frontend/.fidelity-references/external_data-figma-2026-05-07.png

import type { BootContext } from '../../shared/lib/bootContext';
import styles from './ExternalData.module.css';

type Tone = 'good' | 'info' | 'violet' | 'warn';
type StatusTone = 'good' | 'warn';
type ActionVariant = 'primary' | 'secondary';

export type SourceCard = {
  readonly name: string;
  readonly icon: string;
  readonly tone: Tone;
  readonly status: string;
  readonly statusTone: StatusTone;
  readonly sub: string;
  readonly count: number;
};

export type ImportRow = {
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

export type ExternalDataSummary = {
  readonly connected: number;
  readonly pending: number;
};

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
  readonly sources: readonly SourceCard[];
  readonly imports: readonly ImportRow[];
  readonly summary: ExternalDataSummary;
};

export function ExternalData({ sources, imports, summary }: ExternalDataProps): JSX.Element {
  const metaLine = `${summary.connected} connected • ${summary.pending} pending review`;
  return (
    <>
      <header className={styles.head}>
        <div className={styles.title}>External Data Sources</div>
        <div className={styles.bullet}>•</div>
        <div className={styles.meta}>{metaLine}</div>
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
          {sources.map((s) => (
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
          {imports.map((im, i) => {
            const rowClass = i === imports.length - 1
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
