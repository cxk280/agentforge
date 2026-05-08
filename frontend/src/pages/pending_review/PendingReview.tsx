// PendingReview — Figma "Screen 35 — Pending Review".
//
// Provider's cross-patient queue of procedure reports awaiting sign-off,
// driven by live SQL in the PHP wrapper:
//   procedure_result → procedure_report → procedure_order, joined to
//   patient_data + users, filtered to rows where review_status is unset
//   or not 'reviewed'. The wrapper also pre-computes a Last-3 trend per
//   row (ordering by date, smallest result-id when tied) so the detail
//   pane can render without an extra round trip.
//
// Behavior preserved from the original PHP version:
//   - Filter pills (All / Lab / Imaging / Documents / Messages / Critical)
//     with counts derived from the visible queue.
//   - Click a row to populate the detail pane on the right.
//   - Pre-checked checkboxes drive the header's "Sign N selected" count.
// Inert from the original PHP version (out of scope for this final UI
// pass): Reassign / Sign / Forward / Skip / Sign & next POSTs and
// per-template note insertion.
//
// The Co-Pilot suggestion card is intentionally static — the live agent
// is the chat surface in the right rail; this inline card is for visual
// fidelity only.

import { useMemo, useState } from 'react';
import type { BootContext } from '../../shared/lib/bootContext';
import styles from './PendingReview.module.css';

type Bucket = 'lab' | 'imaging' | 'doc' | 'msg';
type StatusTone = 'danger' | 'warn' | 'info' | 'plain';
type FilterKey = 'all' | 'lab' | 'imaging' | 'doc' | 'msg' | 'critical';

export type QueueItem = {
  readonly id: string;
  readonly reportId: number;
  readonly resultId: number;
  readonly orderId: number;
  readonly patientId: number;
  readonly providerId: number;
  readonly bucket: Bucket;
  readonly icon: string;
  readonly title: string;
  readonly sub: string;
  readonly statusLabel: string;
  readonly statusTone: StatusTone;
  readonly when: string;
  readonly preChecked: boolean;
  readonly critical: boolean;
  readonly patientName: string;
  readonly pubpid: string;
  readonly testName: string;
  readonly resultCode: string;
  readonly resultValue: string;
  readonly units: string;
  readonly range: string;
  readonly abnormal: string;
  readonly reportDate: string;
  readonly providerName: string;
  readonly trend: readonly string[];
  readonly priorValue: string | null;
  readonly priorDate: string | null;
};

export type Counts = {
  readonly all: number;
  readonly lab: number;
  readonly imaging: number;
  readonly doc: number;
  readonly msg: number;
  readonly critical: number;
};

export type PendingPayload = {
  readonly queue: readonly QueueItem[];
  readonly counts: Counts;
  readonly headerSummary: string;
};

const FILTERS: ReadonlyArray<{ readonly key: FilterKey; readonly label: string }> = [
  { key: 'all',      label: 'All'         },
  { key: 'lab',      label: 'Lab results' },
  { key: 'imaging',  label: 'Imaging'     },
  { key: 'doc',      label: 'Documents'   },
  { key: 'msg',      label: 'Messages'    },
  { key: 'critical', label: 'Critical'    },
];

const SUGGESTIONS: readonly string[] = [
  'Notify patient via portal (template: A1C critical)',
  'Increase Metformin to 1000 mg BID OR consider GLP-1',
  'Schedule diabetes education referral',
  'Recheck A1C in 8-12 weeks',
];

function rowMatchesFilter(q: QueueItem, filter: FilterKey): boolean {
  switch (filter) {
    case 'all':       return true;
    case 'lab':       return q.bucket === 'lab';
    case 'imaging':   return q.bucket === 'imaging';
    case 'doc':       return q.bucket === 'doc';
    case 'msg':       return q.bucket === 'msg';
    case 'critical':  return q.critical;
  }
}

function formatDateTime(iso: string): string {
  if (iso === '') return '';
  const t = Date.parse(iso);
  if (Number.isNaN(t)) return iso;
  const d = new Date(t);
  const mm = String(d.getMonth() + 1).padStart(2, '0');
  const dd = String(d.getDate()).padStart(2, '0');
  const yy = d.getFullYear();
  const hh = String(d.getHours()).padStart(2, '0');
  const mi = String(d.getMinutes()).padStart(2, '0');
  return `${mm}/${dd}/${yy} ${hh}:${mi}`;
}

function formatShortDate(iso: string): string {
  if (iso === '') return '';
  const t = Date.parse(iso);
  if (Number.isNaN(t)) return iso;
  const d = new Date(t);
  const mm = String(d.getMonth() + 1).padStart(2, '0');
  const dd = String(d.getDate()).padStart(2, '0');
  const yy = String(d.getFullYear()).slice(2);
  return `${mm}/${dd}/${yy}`;
}

type PendingReviewProps = {
  readonly boot: BootContext;
  readonly payload: PendingPayload;
};

export function PendingReview({ payload }: PendingReviewProps): JSX.Element {
  const [activeFilter, setActiveFilter] = useState<FilterKey>('all');
  const [selectedId, setSelectedId] = useState<string>(
    payload.queue[0]?.id ?? '',
  );

  const visibleQueue = useMemo(
    () => payload.queue.filter((q) => rowMatchesFilter(q, activeFilter)),
    [payload.queue, activeFilter],
  );

  const selected: QueueItem | undefined =
    payload.queue.find((q) => q.id === selectedId)
    ?? visibleQueue[0]
    ?? payload.queue[0];

  const preCheckedCount = payload.queue.filter((q) => q.preChecked).length;

  return (
    <>
      <header className={styles.pageHead}>
        <div className={styles.pageTitleBlock}>
          <span className={styles.pageTitle}>Pending Review</span>
          <span className={styles.dot}>&middot;</span>
          <span className={styles.metaLight}>{payload.headerSummary}</span>
        </div>
        <div className={styles.spacer} />
        <button type="button" className={styles.btnGhost}>&#8646; Reassign</button>
        <button type="button" className={styles.btnPrimary}>&#9998; Sign {preCheckedCount} selected</button>
        <button type="button" className={styles.helpPill}>? Help</button>
      </header>

      <div className={styles.filterBar}>
        <div className={styles.pills}>
          {FILTERS.map((f) => {
            const active = f.key === activeFilter;
            const pillClass = active ? `${styles.pill} ${styles.pillActive}` : styles.pill;
            const ctClass = active ? `${styles.pillCt} ${styles.pillCtActive}` : styles.pillCt;
            const count =
              f.key === 'all'      ? payload.counts.all
              : f.key === 'lab'      ? payload.counts.lab
              : f.key === 'imaging'  ? payload.counts.imaging
              : f.key === 'doc'      ? payload.counts.doc
              : f.key === 'msg'      ? payload.counts.msg
              : payload.counts.critical;
            return (
              <button
                key={f.key}
                type="button"
                className={pillClass}
                onClick={() => setActiveFilter(f.key)}
              >
                <span>{f.label}</span>
                <span className={ctClass}>{count}</span>
              </button>
            );
          })}
        </div>
      </div>

      <div className={styles.body}>
        <section className={`${styles.pane} ${styles.paneLeft}`}>
          <div className={styles.listHead}>
            <span className={`${styles.cb} ${styles.cbIndeterminate}`} aria-hidden="true" />
            <span className={styles.sort}>Sort: Newest <span className={styles.caret}>&#9662;</span></span>
            <span className={styles.count}>{visibleQueue.length} results</span>
          </div>
          <div className={styles.list}>
            {visibleQueue.length === 0 && (
              <div className={styles.emptyList}>Queue empty — all caught up.</div>
            )}
            {visibleQueue.map((q) => {
              const isSel = selected !== undefined && q.id === selected.id;
              const rowClass = isSel ? `${styles.row} ${styles.rowActive}` : styles.row;
              const cbClass = q.preChecked ? `${styles.cb} ${styles.cbOn}` : styles.cb;
              const pillClass = `${styles.statusPill} ${styles[`tone_${q.statusTone}`] ?? ''}`;
              return (
                <button
                  key={q.id}
                  type="button"
                  className={rowClass}
                  onClick={() => setSelectedId(q.id)}
                >
                  <span className={cbClass} aria-hidden="true" />
                  <span className={styles.rowIcon}>{q.icon}</span>
                  <div className={styles.core}>
                    <div className={styles.rowTitle}>{q.title}</div>
                    <div className={styles.rowSub}>{q.sub}</div>
                  </div>
                  <div className={styles.meta}>
                    <span className={pillClass}>{q.statusLabel}</span>
                    <span className={styles.when}>{q.when}</span>
                  </div>
                  <span className={styles.kebab}>&#8943;</span>
                </button>
              );
            })}
          </div>
        </section>

        <section className={`${styles.pane} ${styles.paneRight}`}>
          {selected ? (
            <DetailPane item={selected} />
          ) : (
            <div className={styles.emptyDetail}>No items pending review.</div>
          )}
        </section>
      </div>
    </>
  );
}

type DetailPaneProps = {
  readonly item: QueueItem;
};

function DetailPane({ item }: DetailPaneProps): JSX.Element {
  const isCrit = item.critical;
  const drawn = formatDateTime(item.reportDate);
  const detailSubParts = [
    item.pubpid !== '' ? 'MRN ' + item.pubpid : null,
    drawn !== '' ? 'Drawn ' + drawn : null,
    item.providerName !== '' && item.providerName !== '—' ? item.providerName : null,
  ].filter((s): s is string => s !== null);

  const valueDisplay = item.units !== ''
    ? `${item.resultValue} ${item.units}`
    : item.resultValue;
  const refDisplay = item.range !== '' ? item.range : '—';
  const priorLine = item.priorValue !== null && item.priorDate !== null
    ? `${item.abnormal.toLowerCase().startsWith('h') || item.critical ? '↑' : item.abnormal.toLowerCase().startsWith('l') ? '↓' : ''} from ${item.priorValue}${item.units !== '' ? ' ' + item.units : ''} (${formatShortDate(item.priorDate)})`
    : '—';
  const trendArrow = ' → ';
  const lastNLine = item.trend.length > 0 ? item.trend.join(trendArrow) : '—';

  return (
    <>
      <div className={styles.detailHead}>
        <span className={styles.detailIcon}>{item.icon}</span>
        <div className={styles.detailInfo}>
          <span className={styles.detailTitle}>
            {item.patientName} — {item.testName}
          </span>
          <span className={styles.detailSub}>{detailSubParts.join(' · ')}</span>
          {isCrit && (
            <span className={styles.critPill}>CRITICAL VALUE</span>
          )}
        </div>
        <button type="button" className={styles.btnGhost}>Open chart &rarr;</button>
      </div>

      <div className={styles.detailBody}>
        <div>
          <div className={styles.secLbl}>RESULT</div>
          <div
            className={
              isCrit
                ? `${styles.resBox} ${styles.resBoxCritical}`
                : styles.resBox
            }
          >
            <div className={styles.resName}>{item.testName}</div>
            <div className={styles.resBig}>
              <span className={styles.resVal}>{valueDisplay}</span>
              <span className={styles.resDelta}>{priorLine}</span>
            </div>
            <div className={styles.resLast}>
              {item.trend.length > 1 ? `Last ${item.trend.length}: ${lastNLine}` : 'Single reading on file'}
            </div>
            <div className={styles.resRefBox}>
              <div className={styles.resRefLbl}>Reference range</div>
              <div className={styles.resRefVal}>{refDisplay}</div>
            </div>
            <div className={styles.resTrend}>
              {item.trend.length > 1
                ? `${item.trend.length} readings on file for ${item.resultCode || item.testName}`
                : 'Trend unavailable — needs prior result for comparison'}
            </div>
          </div>
        </div>

        <div>
          <div className={`${styles.secLbl} ${styles.secLblCop}`}>CO-PILOT SUGGESTION</div>
          <div className={styles.cop}>
            <div className={styles.copHead}><span className={styles.spark}>&#10022;</span> Suggested actions</div>
            <ul className={styles.copList}>
              {SUGGESTIONS.map((s) => (
                <li key={s}>{s}</li>
              ))}
            </ul>
            <div className={styles.copActions}>
              <button type="button" className={styles.btnPrimary}>Accept all &amp; sign</button>
              <button type="button" className={styles.btnCopGhost}>Open in Co-Pilot &rarr;</button>
            </div>
          </div>
        </div>

        <div>
          <div className={styles.secLbl}>PROVIDER NOTE</div>
          <textarea className={styles.note} defaultValue="" placeholder="Type a note for this report — saved with the sign-off action." />
          <button type="button" className={styles.tplBtn}>Use template &#9662;</button>
        </div>
      </div>

      <div className={styles.foot}>
        <button type="button" className={styles.btnGhost}>Forward</button>
        <button type="button" className={styles.btnGhost}>Skip</button>
        <span className={styles.spacer} />
        <button type="button" className={styles.btnPrimary}>Sign &amp; next &rarr;</button>
      </div>
    </>
  );
}
