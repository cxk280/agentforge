// PendingReview — Figma "Screen 35 — Pending Review" (file kj4MWNr8mpjZ2wVg1PbS0F,
// node 81:2). Provider's cross-patient queue of orders, results, documents and
// messages awaiting review and sign-off. Two-pane layout: list of pending items
// on the left, detail / co-pilot suggestion / provider note for the selected
// item on the right.
//
// Static demo: the previous PHP at /interface/orders/copilot_pending_review.php
// did real DB queries (procedure_result/report/order, pnotes, users); this React
// port hardcodes the Figma's 11 visible queue rows + Margaret Chen detail pane.
// All buttons (Reassign / Sign N selected / Forward / Skip / Sign & next) are
// inert — POST handlers were stripped along with the SQL.
//
// Skips the navy top nav (parent shell owns it). Renders only the page body:
// page-head + filter bar + two-pane body.

import { useState } from 'react';
import type { BootContext } from '../../shared/lib/bootContext';
import styles from './PendingReview.module.css';

type Bucket = 'lab' | 'imaging' | 'doc' | 'msg';
type StatusTone = 'danger' | 'warn' | 'info' | 'plain';
type FilterKey = 'all' | 'lab' | 'imaging' | 'doc' | 'msg' | 'critical';

type QueueItem = {
  readonly id: string;
  readonly bucket: Bucket;
  readonly icon: string;
  readonly title: string;
  readonly sub: string;
  readonly statusLabel: string;
  readonly statusTone: StatusTone;
  readonly when: string;
  readonly preChecked: boolean;
  readonly critical: boolean;
};

const HEADER_SUMMARY = 'Dr. Rivera · 18 results, 4 documents, 2 messages awaiting sign-off';

const FILTERS: ReadonlyArray<{ readonly key: FilterKey; readonly label: string; readonly count: number }> = [
  { key: 'all',      label: 'All',         count: 24 },
  { key: 'lab',      label: 'Lab results', count: 18 },
  { key: 'imaging',  label: 'Imaging',     count: 5 },
  { key: 'doc',      label: 'Documents',   count: 4 },
  { key: 'msg',      label: 'Messages',    count: 2 },
  { key: 'critical', label: 'Critical',    count: 3 },
];

const QUEUE: readonly QueueItem[] = [
  { id: 'chen',     bucket: 'lab',     icon: '\u{1F9EA}', title: 'Margaret Chen · HbA1c',       sub: '7.9% (↑)',          statusLabel: 'Critical', statusTone: 'danger', when: '2h ago', preChecked: true,  critical: true  },
  { id: 'kim',      bucket: 'lab',     icon: '\u{1F9EA}', title: 'David Kim · CBC + CMP',       sub: 'Normal',                 statusLabel: 'Routine',  statusTone: 'info',   when: '3h ago', preChecked: true,  critical: false },
  { id: 'park',     bucket: 'imaging', icon: '\u{1FA7B}', title: 'Allison Park · MRI Lumbar',   sub: 'Mild bulge L4-L5',       statusLabel: 'Routine',  statusTone: 'info',   when: '4h ago', preChecked: true,  critical: false },
  { id: 'martinez', bucket: 'lab',     icon: '\u{1F9EA}', title: 'Linda Martinez · Lipid panel', sub: 'LDL 142 (↑)',      statusLabel: 'Abnormal', statusTone: 'warn',   when: '5h ago', preChecked: true,  critical: false },
  { id: 'mendez',   bucket: 'doc',     icon: '\u{1F4C4}', title: 'Carlos Mendez · Sleep study', sub: 'Mild OSA, AHI 12',       statusLabel: 'Routine',  statusTone: 'info',   when: '6h ago', preChecked: true,  critical: false },
  { id: 'foster',   bucket: 'lab',     icon: '\u{1F9EA}', title: 'Emily Foster · TSH',          sub: '12.4 (↑↑)',    statusLabel: 'Critical', statusTone: 'danger', when: '7h ago', preChecked: true,  critical: true  },
  { id: 'brown',    bucket: 'msg',     icon: '\u{1F4E8}', title: 'James Brown · Patient msg',   sub: 'RE: refill request',     statusLabel: '—',   statusTone: 'plain',  when: '8h ago', preChecked: false, critical: false },
  { id: 'garcia',   bucket: 'lab',     icon: '\u{1F9EA}', title: 'Helen Garcia · UA',           sub: '+ leuk esterase',        statusLabel: 'Abnormal', statusTone: 'warn',   when: '1d ago', preChecked: false, critical: false },
  { id: 'webb',     bucket: 'imaging', icon: '\u{1FA7B}', title: 'Marcus Webb · CT Chest',      sub: 'No acute findings',      statusLabel: 'Routine',  statusTone: 'info',   when: '1d ago', preChecked: false, critical: false },
  { id: 'tan',      bucket: 'doc',     icon: '\u{1F4C4}', title: 'Mike Tan · ED summary',       sub: 'URI, discharged',        statusLabel: 'Routine',  statusTone: 'info',   when: '1d ago', preChecked: false, critical: false },
  { id: 'hayes',    bucket: 'lab',     icon: '\u{1F9EA}', title: 'Robert Hayes · Glucose',      sub: '108 (high-normal)',      statusLabel: 'Routine',  statusTone: 'info',   when: '2d ago', preChecked: false, critical: false },
];

const SUGGESTIONS: readonly string[] = [
  'Notify patient via portal (template: A1C critical)',
  'Increase Metformin to 1000 mg BID OR consider GLP-1',
  'Schedule diabetes education referral',
  'Recheck A1C in 8-12 weeks',
];

const NOTE_BODY =
  'Discussed results — patient agrees to GLP-1 trial. Will start semaglutide 0.25 mg weekly. F/u in 8 weeks for A1C recheck and weight check. Diabetes ed referral placed.';

type PendingReviewProps = {
  readonly boot: BootContext;
};

export function PendingReview(_props: PendingReviewProps): JSX.Element {
  const [activeFilter, setActiveFilter] = useState<FilterKey>('all');
  const [selectedId, setSelectedId] = useState<string>('chen');

  const preCheckedCount = QUEUE.filter((q) => q.preChecked).length;

  return (
    <>
      <header className={styles.pageHead}>
        <div className={styles.pageTitleBlock}>
          <span className={styles.pageTitle}>Pending Review</span>
          <span className={styles.dot}>&middot;</span>
          <span className={styles.metaLight}>{HEADER_SUMMARY}</span>
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
            return (
              <button
                key={f.key}
                type="button"
                className={pillClass}
                onClick={() => setActiveFilter(f.key)}
              >
                <span>{f.label}</span>
                <span className={ctClass}>{f.count}</span>
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
            <span className={styles.count}>{QUEUE.length} results</span>
          </div>
          <div className={styles.list}>
            {QUEUE.map((q) => {
              const isSel = q.id === selectedId;
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
          <div className={styles.detailHead}>
            <span className={styles.detailIcon}>{'\u{1F9EA}'}</span>
            <div className={styles.detailInfo}>
              <span className={styles.detailTitle}>Margaret Chen — HbA1c</span>
              <span className={styles.detailSub}>MRN #004821 &middot; Drawn 04/30/2026 09:14 &middot; Quest Diagnostics</span>
              <span className={styles.critPill}>CRITICAL VALUE</span>
            </div>
            <button type="button" className={styles.btnGhost}>Open chart &rarr;</button>
          </div>

          <div className={styles.detailBody}>
            <div>
              <div className={styles.secLbl}>RESULT</div>
              <div className={`${styles.resBox} ${styles.resBoxCritical}`}>
                <div className={styles.resName}>HbA1c</div>
                <div className={styles.resBig}>
                  <span className={styles.resVal}>7.9 %</span>
                  <span className={styles.resDelta}>&uarr; from 7.2% (11/15/2025)</span>
                </div>
                <div className={styles.resLast}>Last 4: 7.2 &rarr; 7.4 &rarr; 7.6 &rarr; 7.9</div>
                <div className={styles.resRefBox}>
                  <div className={styles.resRefLbl}>Reference range</div>
                  <div className={styles.resRefVal}>&lt; 7.0 %</div>
                </div>
                <div className={styles.resTrend}>Trend: rising 0.7% over 12 mo</div>
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
              <textarea className={styles.note} defaultValue={NOTE_BODY} />
              <button type="button" className={styles.tplBtn}>Use template &#9662;</button>
            </div>
          </div>

          <div className={styles.foot}>
            <button type="button" className={styles.btnGhost}>Forward</button>
            <button type="button" className={styles.btnGhost}>Skip</button>
            <span className={styles.spacer} />
            <button type="button" className={styles.btnPrimary}>Sign &amp; next &rarr;</button>
          </div>
        </section>
      </div>
    </>
  );
}
