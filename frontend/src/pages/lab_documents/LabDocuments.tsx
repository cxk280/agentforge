// LabDocuments — Figma "Screen 40 — Lab Documents" (file kj4MWNr8mpjZ2wVg1PbS0F,
// node 87:2). Cross-patient PDF inbox driven by live SQL on the `documents`
// table (joined to categories + patient_data) in the PHP wrapper.
//
// Three-pane layout: filter chip row + sort selector at the top, a
// document list on the left, a PDF preview pane in the middle, and a
// "Match to patient" rail on the right showing the linked patient when
// matched and a search affordance when unmatched.
//
// The PHP outer shell at /interface/main/tabs/main.php still owns the
// navy top nav and left sidebar; this React tree renders only the page
// body starting at the header.
//
// Behavior preserved from the PHP version:
//  - Filter pills (All / Unmatched / Lab / Imaging / Discharge / Other)
//    with counts coming from the wrapper-side SQL.
//  - Sort selector (Newest / Oldest / Filename) re-orders the list
//    client-side using receivedShort + filename.
//  - Row click selects the document and renders its preview + match rail.
//  - "Open original" navigates to the existing PHP doc viewer
//    (copilot_doc_viewer.php?docref=<id>) via window.navigateTab.
//
// Inert in this final UI pass (sibling PHP action handlers
// copilot_documents_upload/delete/serve handle the real flows):
//  - Match & route, Forward to provider, Mark as duplicate, Reject —
//    flash a confirmation only.
//  - Suggestion ranking — the original SOUNDEX-based fuzzy matcher is
//    out of scope; matched docs show their linked patient, unmatched
//    docs show the search affordance instead of suggestions.

import { useMemo, useState } from 'react';
import type { BootContext } from '../../shared/lib/bootContext';
import styles from './LabDocuments.module.css';

type FilterKey = 'all' | 'unmatched' | 'lab' | 'imaging' | 'discharge' | 'other';
type SortKey = 'newest' | 'oldest' | 'filename';

type CategoryKind = 'Lab' | 'Imaging' | 'Discharge' | 'Other';

export type DocRow = {
  readonly id: number;
  readonly filename: string;
  readonly icon: string;            // 📄 / 🩻
  readonly patientName?: string | undefined | null;
  readonly mrn?: string | undefined | null;
  readonly dob?: string | undefined | null; // mm/dd/yyyy
  readonly category: CategoryKind;
  readonly receivedShort: string;   // mm/dd HH:MM
  readonly receivedLong: string;    // for preview header
  readonly size: string;            // e.g. "1.2 MB"
  readonly source: string;          // e.g. "LabCorp fax 512-555-0142"
  readonly unmatched: boolean;
  readonly unmatchedSubLabel?: string | undefined | null;
};

export type DocsCounts = {
  readonly all: number;
  readonly unmatched: number;
  readonly lab: number;
  readonly imaging: number;
  readonly discharge: number;
  readonly other: number;
};

export type DocsPayload = {
  readonly docs: readonly DocRow[];
  readonly counts: DocsCounts;
};

type MatchSuggestion = {
  readonly pid: number;
  readonly name: string;
  readonly mrn: string;
  readonly dob: string;
  readonly confidencePct: number;
};

const COLOR_CONFIDENCE_GOOD = 90;

// ─────────────────────────────────────────────────────────────────────────
// Window globals — same pattern Finder uses for tab navigation.
// ─────────────────────────────────────────────────────────────────────────

// LabDocuments runs INSIDE the #maimain iframe; the shell's navigateTab
// helper lives on `window.parent`, not on the iframe's own `window`.
type Win = Window & {
  navigateTab?: (url: string, name: string, afterLoad?: () => void) => void;
  activateTabByName?: (name: string, hideOthers?: boolean) => void;
  webroot_url?: string;
};

function openOriginalDoc(docId: number): void {
  const self = window as Win;
  const parent = (self.parent !== self ? self.parent : self) as Win;
  const top = (self.top !== null && self.top !== self ? self.top : self) as Win;

  const webroot = parent.webroot_url ?? top.webroot_url ?? self.webroot_url ?? '';
  const url = `${webroot}/interface/patient_file/documents/copilot_doc_viewer.php?docref=${docId}`;

  const navigateTab = parent.navigateTab ?? top.navigateTab;
  const activateTabByName = parent.activateTabByName ?? top.activateTabByName;
  if (typeof navigateTab === 'function') {
    navigateTab(url, 'pat', () => {
      activateTabByName?.('pat', true);
    });
    return;
  }

  // Fallback: navigate just THIS iframe (preserves the parent shell + nav).
  // Never replace window.top — that would destroy the navy header.
  self.location.href = url;
}

// ─────────────────────────────────────────────────────────────────────────
// Filter helpers
// ─────────────────────────────────────────────────────────────────────────

function rowMatchesFilter(row: DocRow, filter: FilterKey): boolean {
  switch (filter) {
    case 'all':       return true;
    case 'unmatched': return row.unmatched;
    case 'lab':       return row.category === 'Lab';
    case 'imaging':   return row.category === 'Imaging';
    case 'discharge': return row.category === 'Discharge';
    case 'other':     return row.category === 'Other';
  }
}

function compareRows(a: DocRow, b: DocRow, sort: SortKey): number {
  switch (sort) {
    case 'newest':   return b.receivedShort.localeCompare(a.receivedShort) || (b.id - a.id);
    case 'oldest':   return a.receivedShort.localeCompare(b.receivedShort) || (a.id - b.id);
    case 'filename': return a.filename.localeCompare(b.filename);
  }
}

// ─────────────────────────────────────────────────────────────────────────
// Component
// ─────────────────────────────────────────────────────────────────────────

type LabDocumentsProps = {
  readonly boot: BootContext;
  readonly payload: DocsPayload;
};

export function LabDocuments({ payload }: LabDocumentsProps): JSX.Element {
  const [filter, setFilter] = useState<FilterKey>('all');
  const [sort, setSort] = useState<SortKey>('newest');
  // Default selection: the first unmatched doc if any (so the routing rail
  // is visible on first paint), else the first row, else nothing.
  const initialSelected: number =
    payload.docs.find((d) => d.unmatched)?.id ?? payload.docs[0]?.id ?? 0;
  const [selectedId, setSelectedId] = useState<number>(initialSelected);
  const [flash, setFlash] = useState<string | null>(null);

  const counts = payload.counts;
  const visibleRows = useMemo(() => {
    return payload.docs
      .filter((r) => rowMatchesFilter(r, filter))
      .slice()
      .sort((a, b) => compareRows(a, b, sort));
  }, [payload.docs, filter, sort]);

  // If the active filter hides the selected doc, pick the first visible.
  const selected: DocRow | undefined = useMemo(() => {
    const fromVisible = visibleRows.find((r) => r.id === selectedId);
    if (fromVisible) return fromVisible;
    return visibleRows[0];
  }, [visibleRows, selectedId]);

  // The original PHP version surfaced SOUNDEX-based fuzzy candidates for
  // unmatched docs. The static React port hard-coded them; the current
  // wiring drops the suggestion list entirely until a real /apis/copilot
  // endpoint exists. Unmatched docs show a search affordance instead.
  const suggestions: readonly MatchSuggestion[] = [];

  const totalDocs = counts.all;
  const unmatchedCount = counts.unmatched;
  const routeBtnLabel = unmatchedCount > 0 ? `Route ${unmatchedCount}` : 'Route';

  const onRowClick = (id: number): void => {
    setSelectedId(id);
  };

  const onMatchRoute = (sug: MatchSuggestion): void => {
    if (!selected) return;
    setFlash(`Matched ${selected.filename} to ${sug.name} & routed · ok`);
  };

  const onForwardProvider = (): void => {
    if (!selected) return;
    const raw = window.prompt('Forward to provider — enter provider user ID (numeric):');
    if (raw === null) return;
    const id = Number.parseInt(raw, 10);
    if (!Number.isFinite(id) || id <= 0) {
      window.alert('Invalid provider ID.');
      return;
    }
    setFlash(`Forwarded ${selected.filename} to provider #${id} · ok`);
  };

  const onMarkDuplicate = (): void => {
    if (!selected) return;
    if (!window.confirm('Mark this document as a duplicate? It will be hidden from the inbox.')) return;
    setFlash(`Marked duplicate · ok`);
  };

  const onReject = (): void => {
    if (!selected) return;
    if (!window.confirm('Reject this document as wrong patient?')) return;
    setFlash(`Rejected · ok`);
  };

  const onBulkRoute = (): void => {
    if (unmatchedCount <= 0) return;
    setFlash(`Routed ${unmatchedCount} · ok`);
  };

  return (
    <>
      <header className={styles.head}>
        <div className={styles.title}>Lab Documents</div>
        <div className={styles.bullet}>•</div>
        <div className={styles.meta}>
          PDF inbox · {totalDocs} documents · {unmatchedCount} unmatched to patient
        </div>
        <div className={styles.spacer} />
        <button type="button" className={styles.upload}>
          <span className={styles.uploadIcon}>⬆</span>
          <span>Upload</span>
        </button>
        <button
          type="button"
          className={styles.route}
          onClick={onBulkRoute}
          disabled={unmatchedCount === 0}
          title={unmatchedCount > 0 ? 'Bulk route all unmatched docs to lab queue' : 'Nothing to route'}
        >
          {routeBtnLabel}
        </button>
        <button type="button" className={styles.help} disabled title="Help — out of scope">
          ? Help
        </button>
      </header>

      <div className={styles.filterBar}>
        <FilterPill key1="all"       label="All"       count={counts.all}       active={filter === 'all'}       onClick={() => setFilter('all')} />
        <FilterPill key1="unmatched" label="Unmatched" count={counts.unmatched} active={filter === 'unmatched'} onClick={() => setFilter('unmatched')} />
        <FilterPill key1="lab"       label="Lab"       count={counts.lab}       active={filter === 'lab'}       onClick={() => setFilter('lab')} />
        <FilterPill key1="imaging"   label="Imaging"   count={counts.imaging}   active={filter === 'imaging'}   onClick={() => setFilter('imaging')} />
        <FilterPill key1="discharge" label="Discharge" count={counts.discharge} active={filter === 'discharge'} onClick={() => setFilter('discharge')} />
        <FilterPill key1="other"     label="Other"     count={counts.other}     active={filter === 'other'}     onClick={() => setFilter('other')} />
        <div className={styles.spacer} />
        <label className={styles.sortLabel}>
          <span>Sort:</span>
          <select
            className={styles.sortSelect}
            value={sort}
            onChange={(e) => setSort(e.target.value as SortKey)}
            aria-label="Sort documents"
          >
            <option value="newest">Newest</option>
            <option value="oldest">Oldest</option>
            <option value="filename">Filename</option>
          </select>
          <span className={styles.sortCaret} aria-hidden="true">▾</span>
        </label>
      </div>

      {flash !== null && (
        <div className={styles.flash} role="status">{flash}</div>
      )}

      <div className={styles.body}>
        {/* Document list */}
        <aside className={styles.list}>
          {visibleRows.length === 0 && (
            <div className={styles.emptyList}>No documents in this view.</div>
          )}
          {visibleRows.map((r, i) => {
            const isSel = selected !== undefined && r.id === selected.id;
            const rowCls = isSel ? `${styles.row} ${styles.rowSel}` : styles.row;
            const isLast = i === visibleRows.length - 1;
            const subLabelCls = r.unmatched ? styles.subUnmatched : styles.subMatched;
            const subLabel = r.unmatched
              ? (r.unmatchedSubLabel ?? 'UNMATCHED — needs routing')
              : `${r.patientName ?? '—'} · matched`;

            return (
              <div
                key={r.id}
                role="button"
                tabIndex={0}
                aria-pressed={isSel}
                className={`${rowCls}${isLast ? ` ${styles.rowLast}` : ''}`}
                onClick={() => onRowClick(r.id)}
                onKeyDown={(e) => {
                  if (e.key === 'Enter' || e.key === ' ') {
                    e.preventDefault();
                    onRowClick(r.id);
                  }
                }}
                title={`Open preview: ${r.filename}`}
              >
                <span
                  className={
                    r.unmatched && !isSel
                      ? styles.cb
                      : `${styles.cb} ${styles.cbOn}`
                  }
                  aria-hidden="true"
                >
                  {!(r.unmatched && !isSel) && '✓'}
                </span>
                <span className={styles.docIcon} aria-hidden="true">{r.icon}</span>
                <div className={styles.rowMeta}>
                  <div className={styles.rowName}>{r.filename}</div>
                  <div className={subLabelCls}>{subLabel}</div>
                  <div className={styles.rowCat}>{r.category}</div>
                </div>
                <div className={styles.rowRight}>
                  <span className={styles.rowDate}>{r.receivedShort}</span>
                  {r.unmatched && (
                    <span className={`${styles.pill} ${styles.pillWarn}`}>Match needed</span>
                  )}
                </div>
              </div>
            );
          })}
        </aside>

        {/* Preview pane + match rail */}
        <section className={styles.preview}>
          {selected ? (
            <>
              <header className={styles.prevHead}>
                <span className={styles.prevIcon} aria-hidden="true">{selected.icon}</span>
                <div className={styles.prevHeadInfo}>
                  <div className={styles.prevName}>{selected.filename}</div>
                  <div className={styles.prevSub}>
                    {selected.size} · received {selected.receivedLong}
                  </div>
                </div>
                <span
                  className={
                    selected.unmatched
                      ? `${styles.pill} ${styles.pillWarn}`
                      : `${styles.pill} ${styles.pillNeutral}`
                  }
                >
                  {selected.unmatched ? 'UNMATCHED — needs routing' : 'Matched'}
                </span>
              </header>

              <div className={styles.prevSplit}>
                <div className={styles.pdfCol}>
                  <div className={styles.pdfPage}>
                    <div className={styles.pdfBrand}>
                      {selected.source.toUpperCase().split(' ')[0] ?? 'DOCUMENT'}
                    </div>
                    <div className={styles.pdfTestName}>
                      {selected.category === 'Lab'
                        ? 'LABORATORY DOCUMENT'
                        : selected.category === 'Imaging'
                          ? 'IMAGING REPORT'
                          : selected.category === 'Discharge'
                            ? 'DISCHARGE SUMMARY'
                            : 'DOCUMENT'}
                    </div>
                    <div className={styles.pdfRule} />
                    {selected.unmatched ? (
                      <div className={styles.pdfLine}>
                        Patient: not assigned · awaiting routing
                      </div>
                    ) : (
                      <>
                        <div className={styles.pdfLine}>
                          Patient: {selected.patientName ?? '—'}
                        </div>
                        <div className={styles.pdfLine}>
                          DOB: {selected.dob ?? '—'}  MRN: {selected.mrn ?? 'not provided'}
                        </div>
                      </>
                    )}
                    <div className={styles.pdfLine}>Source: {selected.source}</div>
                    <div className={styles.pdfNote}>
                      Inline preview not rendered — the documents table
                      stores binary blobs.{' '}
                      <button
                        type="button"
                        className={styles.pdfOpenLink}
                        onClick={() => openOriginalDoc(selected.id)}
                      >
                        Open original →
                      </button>
                    </div>
                  </div>
                </div>

                <aside className={styles.matchRail}>
                  <div className={styles.railLbl}>MATCH TO PATIENT</div>
                  <div className={styles.railSearch} aria-hidden="true">
                    <span className={styles.railSearchIcon}>🔍</span>
                    <span>Search by name, MRN, DOB</span>
                  </div>
                  <div className={styles.railLbl}>
                    {selected.unmatched ? 'Suggested matches' : 'Document is matched'}
                  </div>
                  {selected.unmatched && suggestions.length === 0 && (
                    <div className={styles.railEmpty}>
                      No suggestions found. Use the search above.
                    </div>
                  )}
                  {selected.unmatched && suggestions.map((s, i) => {
                    const isPrimary = i === 0;
                    const cardCls = isPrimary
                      ? `${styles.cand} ${styles.candPrimary}`
                      : styles.cand;
                    const pillCls =
                      s.confidencePct >= COLOR_CONFIDENCE_GOOD
                        ? `${styles.pill} ${styles.pillConfHigh}`
                        : `${styles.pill} ${styles.pillConfLow}`;
                    return (
                      <button
                        key={s.pid}
                        type="button"
                        className={cardCls}
                        onClick={() => onMatchRoute(s)}
                      >
                        <span className={styles.candAva} />
                        <div className={styles.candInfo}>
                          <div className={styles.candName}>{s.name}</div>
                          <div className={styles.candDet}>
                            MRN {s.mrn} · DOB {s.dob}
                          </div>
                          <span className={pillCls}>
                            {s.confidencePct}% confidence
                          </span>
                        </div>
                      </button>
                    );
                  })}
                  {!selected.unmatched && (
                    <div className={`${styles.cand} ${styles.candPrimary}`}>
                      <span className={styles.candAva} />
                      <div className={styles.candInfo}>
                        <div className={styles.candName}>{selected.patientName ?? '—'}</div>
                        <div className={styles.candDet}>
                          MRN {selected.mrn ?? '—'} · DOB {selected.dob ?? '—'}
                        </div>
                        <span className={`${styles.pill} ${styles.pillConfHigh}`}>Linked</span>
                      </div>
                    </div>
                  )}

                  <div className={styles.railLbl}>ACTIONS</div>
                  <button
                    type="button"
                    className={styles.actPrimary}
                    disabled={!selected.unmatched || suggestions.length === 0}
                    onClick={() => {
                      const first = suggestions[0];
                      if (first) onMatchRoute(first);
                    }}
                    title={
                      selected.unmatched && suggestions.length > 0
                        ? 'Match top suggestion & route to lab queue'
                        : 'Already matched or no candidates available.'
                    }
                  >
                    Match &amp; route to lab queue
                  </button>
                  <button type="button" className={styles.actGhost} onClick={onForwardProvider}>
                    Forward to provider…
                  </button>
                  <button type="button" className={styles.actGhost} onClick={onMarkDuplicate}>
                    Mark as duplicate
                  </button>
                  <button type="button" className={styles.actGhost} onClick={onReject}>
                    Reject — wrong patient
                  </button>
                </aside>
              </div>
            </>
          ) : (
            <div className={styles.emptyPreview}>No document selected.</div>
          )}
        </section>
      </div>
    </>
  );
}

type FilterPillProps = {
  readonly key1: FilterKey;
  readonly label: string;
  readonly count: number;
  readonly active: boolean;
  readonly onClick: () => void;
};

function FilterPill(props: FilterPillProps): JSX.Element {
  const cls = props.active ? `${styles.pillBtn} ${styles.pillBtnActive}` : styles.pillBtn;
  const ctCls = props.active ? `${styles.pillCt} ${styles.pillCtActive}` : styles.pillCt;
  return (
    <button type="button" className={cls} onClick={props.onClick} aria-pressed={props.active}>
      <span>{props.label}</span>
      <span className={ctCls}>{props.count}</span>
    </button>
  );
}
