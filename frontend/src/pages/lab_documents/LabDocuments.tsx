// LabDocuments — Figma "Screen 40 — Lab Documents" (file kj4MWNr8mpjZ2wVg1PbS0F,
// node 87:2). Static demo port of the PHP-rendered mock previously at
// /interface/patient_file/documents/copilot_lab_documents.php.
//
// Three-pane PDF inbox: filter chip row + sort selector at the top, a
// document list on the left, a PDF preview pane in the middle, and a
// "Match to patient" rail on the right with AI-scored suggestions and
// triage actions (Match & route, Forward to provider, Mark as duplicate,
// Reject — wrong patient).
//
// Cross-patient page (lab inbox is org-wide). The PHP outer shell at
// /interface/main/tabs/main.php still owns the navy top nav and left
// sidebar; this React tree only renders inside the #cp-root mount node
// and starts at the page header.
//
// Behavior preserved from the PHP version:
//  - Filter pills (All / Unmatched / Lab / Imaging / Discharge / Other)
//    with counts; clicking a pill switches the visible list.
//  - Sort selector (Newest / Oldest / Filename) re-orders the list.
//  - Row click selects the document and renders its preview + match rail.
//  - "Open original" link navigates to the existing PHP doc viewer
//    (copilot_doc_viewer.php?docref=<id>) via window.navigateTab.
//  - Match & route, Forward to provider, Mark as duplicate, and Reject
//    actions confirm/prompt and (in the static port) flash a confirmation.
//    The actual POST handlers in the PHP version are not in scope for
//    this Sunday-final UI migration; sibling action handlers in PHP
//    (copilot_documents_upload/delete/serve, copilot_doc_viewer) stay
//    untouched.
//
// The demo dataset matches the Figma frame (10 rows, 3 unmatched, the
// 4th row "LabCorp_CBC_unknown_001.pdf" pre-selected). Wiring this back
// to the real `documents` table + `cp_doc_routing` is a follow-up.

import { useMemo, useState } from 'react';
import type { BootContext } from '../../shared/lib/bootContext';
import styles from './LabDocuments.module.css';

type FilterKey = 'all' | 'unmatched' | 'lab' | 'imaging' | 'discharge' | 'other';
type SortKey = 'newest' | 'oldest' | 'filename';

type CategoryKind = 'Lab' | 'Imaging' | 'Discharge' | 'Other';

type DocRow = {
  readonly id: number;
  readonly filename: string;
  readonly icon: string;            // 📄 / 🩻
  readonly patientName?: string | undefined;
  readonly mrn?: string | undefined;
  readonly dob?: string | undefined; // mm/dd/yyyy
  readonly category: CategoryKind;
  readonly receivedShort: string;   // mm/dd HH:MM
  readonly receivedLong: string;    // for preview header
  readonly size: string;            // e.g. "1.2 MB"
  readonly source: string;          // e.g. "LabCorp fax 512-555-0142"
  readonly unmatched: boolean;
  readonly unmatchedSubLabel?: string | undefined; // "UNMATCHED — 3 candidates" etc
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
// Demo data — mirrors the Figma frame's visible rows. The pre-selected
// "LabCorp_CBC_unknown_001.pdf" is the unmatched row in the screenshot
// that drives the right-rail suggestions.
// ─────────────────────────────────────────────────────────────────────────

const ROWS: readonly DocRow[] = [
  {
    id: 101,
    filename: 'Quest_HbA1c_Chen_M.pdf',
    icon: '📄',
    patientName: 'Margaret Chen',
    category: 'Lab',
    receivedShort: '04/30 09:14',
    receivedLong: '04/30 09:14 · Quest Diagnostics',
    size: '188 KB',
    source: 'Quest Diagnostics',
    unmatched: false,
  },
  {
    id: 102,
    filename: 'Quest_TSH_Foster_E.pdf',
    icon: '📄',
    patientName: 'Emily Foster',
    category: 'Lab',
    receivedShort: '04/30 09:14',
    receivedLong: '04/30 09:14 · Quest Diagnostics',
    size: '156 KB',
    source: 'Quest Diagnostics',
    unmatched: false,
  },
  {
    id: 103,
    filename: 'Quest_Lipid_Martinez_L.pdf',
    icon: '📄',
    patientName: 'Linda Martinez',
    category: 'Lab',
    receivedShort: '04/30 09:14',
    receivedLong: '04/30 09:14 · Quest Diagnostics',
    size: '210 KB',
    source: 'Quest Diagnostics',
    unmatched: false,
  },
  {
    id: 104,
    filename: 'LabCorp_CBC_unknown_001.pdf',
    icon: '📄',
    category: 'Lab',
    receivedShort: '04/30 08:00',
    receivedLong: '04/30 08:00 · LabCorp fax 512-555-0142',
    size: '1.2 MB',
    source: 'LabCorp fax 512-555-0142',
    unmatched: true,
    unmatchedSubLabel: 'UNMATCHED — 3 candidates',
  },
  {
    id: 105,
    filename: 'RFM_MRI_Park_A.pdf',
    icon: '🩻',
    patientName: 'Allison Park',
    category: 'Imaging',
    receivedShort: '04/29 16:30',
    receivedLong: '04/29 16:30 · Riverside Imaging (RFM)',
    size: '3.4 MB',
    source: 'Riverside Imaging (RFM)',
    unmatched: false,
  },
  {
    id: 106,
    filename: 'StDavids_Discharge_Hayes_R.pdf',
    icon: '📄',
    patientName: 'Robert Hayes',
    category: 'Discharge',
    receivedShort: '04/29 14:00',
    receivedLong: "04/29 14:00 · St. David's Hospital",
    size: '420 KB',
    source: "St. David's Hospital",
    unmatched: false,
  },
  {
    id: 107,
    filename: 'Fax_unknown_002.pdf',
    icon: '📄',
    category: 'Other',
    receivedShort: '04/29 11:14',
    receivedLong: '04/29 11:14 · Inbound fax',
    size: '92 KB',
    source: 'Inbound fax',
    unmatched: true,
    unmatchedSubLabel: 'UNMATCHED — fax 5550142',
  },
  {
    id: 108,
    filename: 'Quest_BMP_Brown_J.pdf',
    icon: '📄',
    patientName: 'James Brown',
    category: 'Lab',
    receivedShort: '04/29 09:00',
    receivedLong: '04/29 09:00 · Quest Diagnostics',
    size: '174 KB',
    source: 'Quest Diagnostics',
    unmatched: false,
  },
  {
    id: 109,
    filename: 'Imaging_CT_Webb_M.pdf',
    icon: '🩻',
    patientName: 'Marcus Webb',
    category: 'Imaging',
    receivedShort: '04/29 08:00',
    receivedLong: '04/29 08:00 · Imaging center',
    size: '4.1 MB',
    source: 'Imaging center',
    unmatched: false,
  },
  {
    id: 110,
    filename: 'LabCorp_HbA1c_unknown_003.pdf',
    icon: '📄',
    category: 'Lab',
    receivedShort: '04/28 16:30',
    receivedLong: '04/28 16:30 · LabCorp',
    size: '198 KB',
    source: 'LabCorp',
    unmatched: true,
    unmatchedSubLabel: 'UNMATCHED — partial DOB',
  },
  {
    id: 111,
    filename: 'Quest_TSH_Tan_M.pdf',
    icon: '📄',
    patientName: 'Mike Tan',
    category: 'Lab',
    receivedShort: '04/28 14:00',
    receivedLong: '04/28 14:00 · Quest Diagnostics',
    size: '162 KB',
    source: 'Quest Diagnostics',
    unmatched: false,
  },
];

// Suggestions shown for the pre-selected unmatched doc (LabCorp_CBC_unknown_001).
// The PHP version derives these via SOUNDEX on a name token from the filename;
// the static port hard-codes them to match the Figma frame.
const SUGGESTIONS_BY_DOC: ReadonlyMap<number, readonly MatchSuggestion[]> = new Map([
  [104, [
    { pid: 1,  name: 'Margaret Chen',  mrn: '#004821', dob: '03/14/1958', confidencePct: 94 },
    { pid: 21, name: 'Margaret Cheng', mrn: '#007212', dob: '03/14/1962', confidencePct: 71 },
    { pid: 22, name: 'Mary Cherie',    mrn: '#003319', dob: '03/04/1958', confidencePct: 62 },
  ]],
  [107, [
    { pid: 31, name: 'John Doe',       mrn: '#005002', dob: '12/04/1980', confidencePct: 58 },
    { pid: 32, name: 'James Brown',    mrn: '#003317', dob: '04/12/1971', confidencePct: 51 },
    { pid: 33, name: 'Jane Bishop',    mrn: '#006115', dob: '07/22/1965', confidencePct: 47 },
  ]],
  [110, [
    { pid: 41, name: 'Daniel Tran',    mrn: '#005544', dob: '02/14/1975', confidencePct: 64 },
    { pid: 42, name: 'David Tomlin',   mrn: '#004409', dob: '02/14/1972', confidencePct: 55 },
    { pid: 43, name: 'Diana Tate',     mrn: '#005920', dob: '02/04/1978', confidencePct: 48 },
  ]],
]);

// Static parsed-PDF view shown in the preview body for the pre-selected
// LabCorp CBC unknown 001 document. Matches the Figma frame.
type CbcRow = { readonly test: string; readonly result: string; readonly ref: string; readonly flag: string };
const CBC_ROWS: readonly CbcRow[] = [
  { test: 'WBC',     result: '7.2',  ref: '4.0–11.0',  flag: '—' },
  { test: 'RBC',     result: '4.6',  ref: '3.8–5.2',   flag: '—' },
  { test: 'HGB',     result: '13.4', ref: '12–16',     flag: '—' },
  { test: 'HCT',     result: '40.2', ref: '36–46',     flag: '—' },
  { test: 'MCV',     result: '87',   ref: '80–96',     flag: '—' },
  { test: 'MCH',     result: '29.1', ref: '27–33',     flag: '—' },
  { test: 'PLT',     result: '248',  ref: '150–400',   flag: '—' },
  { test: 'NEUT %',  result: '58',   ref: '40–70',     flag: '—' },
  { test: 'LYMPH %', result: '32',   ref: '20–45',     flag: '—' },
];

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

// Counts shown in the filter pills. Independent of the active filter so
// the user always sees the full distribution (matches the PHP version).
function buildCounts(rows: readonly DocRow[]): Record<FilterKey, number> {
  const counts: Record<FilterKey, number> = {
    all: rows.length,
    unmatched: 0,
    lab: 0,
    imaging: 0,
    discharge: 0,
    other: 0,
  };
  for (const r of rows) {
    if (r.unmatched) counts.unmatched++;
    if (r.category === 'Lab')       counts.lab++;
    if (r.category === 'Imaging')   counts.imaging++;
    if (r.category === 'Discharge') counts.discharge++;
    if (r.category === 'Other')     counts.other++;
  }
  return counts;
}

// ─────────────────────────────────────────────────────────────────────────
// Component
// ─────────────────────────────────────────────────────────────────────────

type LabDocumentsProps = {
  readonly boot: BootContext;
};

export function LabDocuments(_props: LabDocumentsProps): JSX.Element {
  const [filter, setFilter] = useState<FilterKey>('all');
  const [sort, setSort] = useState<SortKey>('newest');
  // Pre-select the unmatched LabCorp CBC doc so the right-rail suggestions
  // are visible on first paint, mirroring the Figma frame.
  const [selectedId, setSelectedId] = useState<number>(104);
  const [flash, setFlash] = useState<string | null>(null);

  const counts = useMemo(() => buildCounts(ROWS), []);
  const visibleRows = useMemo(() => {
    return ROWS
      .filter((r) => rowMatchesFilter(r, filter))
      .slice()
      .sort((a, b) => compareRows(a, b, sort));
  }, [filter, sort]);

  // If the active filter hides the selected doc, pick the first visible.
  const selected: DocRow | undefined = useMemo(() => {
    const fromVisible = visibleRows.find((r) => r.id === selectedId);
    if (fromVisible) return fromVisible;
    return visibleRows[0];
  }, [visibleRows, selectedId]);

  const suggestions: readonly MatchSuggestion[] = selected
    ? (SUGGESTIONS_BY_DOC.get(selected.id) ?? [])
    : [];

  const totalDocs = ROWS.length;
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
                        ? 'COMPLETE BLOOD COUNT'
                        : selected.category === 'Imaging'
                          ? 'IMAGING REPORT'
                          : selected.category === 'Discharge'
                            ? 'DISCHARGE SUMMARY'
                            : 'DOCUMENT'}
                    </div>
                    <div className={styles.pdfRule} />

                    {selected.unmatched ? (
                      <>
                        <div className={styles.pdfLine}>
                          Patient: ████████████ (redacted partial)
                        </div>
                        <div className={styles.pdfLine}>
                          DOB: 03/14/19██  Sex: F  MRN: not provided
                        </div>
                        <div className={styles.pdfLine}>
                          Specimen #: LC-289-44192  Drawn: 04/29 14:30
                        </div>
                      </>
                    ) : (
                      <>
                        <div className={styles.pdfLine}>
                          Patient: {selected.patientName ?? '—'}
                        </div>
                        <div className={styles.pdfLine}>
                          DOB: {selected.dob ?? '—'}  MRN: {selected.mrn ?? 'not provided'}
                        </div>
                        <div className={styles.pdfLine}>
                          Source: {selected.source}
                        </div>
                      </>
                    )}

                    <div className={styles.pdfTblHead}>
                      <span className={styles.pdfCol1}>TEST</span>
                      <span className={styles.pdfCol2}>RESULT</span>
                      <span className={styles.pdfCol3}>REF</span>
                      <span className={styles.pdfCol4}>FLAG</span>
                    </div>
                    {selected.category === 'Lab' && CBC_ROWS.map((r) => (
                      <div key={r.test} className={styles.pdfTblRow}>
                        <span className={styles.pdfCol1}>{r.test}</span>
                        <span className={styles.pdfCol2}>{r.result}</span>
                        <span className={styles.pdfCol3}>{r.ref}</span>
                        <span className={styles.pdfCol4}>{r.flag}</span>
                      </div>
                    ))}
                    {selected.category !== 'Lab' && (
                      <div className={styles.pdfNote}>
                        Preview unavailable for this MIME type.{' '}
                        <button
                          type="button"
                          className={styles.pdfOpenLink}
                          onClick={() => openOriginalDoc(selected.id)}
                        >
                          Open original →
                        </button>
                      </div>
                    )}
                    <div className={styles.pdfSigned}>
                      Signed: Dr. Patel, MD · LabCorp Houston Lab · 04/29 22:15
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
