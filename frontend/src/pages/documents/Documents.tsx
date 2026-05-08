// Documents — Figma "Screen 15 — Documents" (file kj4MWNr8mpjZ2wVg1PbS0F,
// node 36:2). Static demo port of the PHP-rendered mock previously at
// /interface/patient_file/documents/copilot_documents.php.
//
// Patient-context page. The PHP outer shell at
// /interface/main/tabs/main.php still owns the navy top nav, left sidebar,
// and patient header2 banner; this React tree only renders inside the
// #cp-root mount node and starts at the page header.
//
// Live W2 upload UI, the bbox-viewer link rows, the upload/extract/delete
// handlers, and any DB I/O have been intentionally dropped from this
// migration — per the migration brief, only the static documents.php
// landing page is in scope. The sibling action handlers
// (copilot_documents_upload.php, _delete.php, _serve.php) and the
// child viewer (copilot_doc_viewer.php) are untouched.

import { useRef, useState } from 'react';
import type { BootContext } from '../../shared/lib/bootContext';
import styles from './Documents.module.css';

type Category = {
  readonly icon: string;
  readonly label: string;
  readonly count: number;
  readonly active: boolean;
};

type RecentCard = {
  readonly cat: string;
  readonly catKind: 'lab' | 'clinical' | 'imaging' | 'referral';
  readonly title: string;
  readonly meta: string;
};

type EarlierRow = {
  readonly icon: string;
  readonly iconKind: 'lab' | 'imaging' | 'clinical' | 'insurance' | 'rx';
  readonly title: string;
  readonly cat: string;
  readonly src: string;
  readonly date: string;
  readonly size: string;
};

const CATEGORIES: readonly Category[] = [
  { icon: '📁', label: 'All Documents',  count: 47, active: true  },
  { icon: '🩺', label: 'Clinical Notes', count: 18, active: false },
  { icon: '🧪', label: 'Lab Reports',    count: 12, active: false },
  { icon: '📋', label: 'Imaging',        count: 5,  active: false },
  { icon: '💊', label: 'Prescriptions',  count: 4,  active: false },
  { icon: '🏥', label: 'Referrals',      count: 3,  active: false },
  { icon: '📄', label: 'Insurance / ID', count: 3,  active: false },
  { icon: '✏',       label: 'Patient Forms',  count: 2,  active: false },
];

const RECENT: readonly RecentCard[] = [
  { cat: 'Lab Report',    catKind: 'lab',      title: 'CMP + CBC Results',      meta: 'Apr 12, 2026 • 248 KB • LabCorp' },
  { cat: 'Clinical Note', catKind: 'clinical', title: 'Annual Physical Note',   meta: 'Feb 18, 2026 • 32 KB • Dr. Rivera' },
  { cat: 'Imaging',       catKind: 'imaging',  title: 'Echocardiogram Report',  meta: 'Feb 18, 2026 • 1.2 MB • Riverside Imaging' },
  { cat: 'Referral',      catKind: 'referral', title: 'Endocrinology Referral', meta: 'Nov 20, 2025 • 18 KB • Dr. Rivera' },
];

const EARLIER: readonly EarlierRow[] = [
  { icon: '🧪', iconKind: 'lab',       title: 'A1C + Lipid Panel',            cat: 'Lab Report',     src: 'LabCorp',           date: 'Nov 5, 2025',  size: '184 KB' },
  { icon: '📋', iconKind: 'imaging',   title: 'Knee X-Ray (Bilateral)',       cat: 'Imaging',        src: 'Riverside Imaging', date: 'Oct 22, 2025', size: '2.4 MB' },
  { icon: '🩺', iconKind: 'clinical',  title: 'Telehealth Note — Lab Review', cat: 'Clinical Note',  src: 'Dr. S. Chen',       date: 'Aug 22, 2025', size: '24 KB' },
  { icon: '📄', iconKind: 'insurance', title: 'Insurance Card (front)',       cat: 'Insurance / ID', src: 'Patient upload',    date: 'Jul 15, 2025', size: '1.1 MB' },
  { icon: '💊', iconKind: 'rx',        title: 'Rx — Lisinopril 5mg → 10mg', cat: 'Prescription',   src: 'Dr. Rivera',         date: 'Apr 1, 2025',  size: '18 KB' },
];

// Server-side row shape from copilot_documents.php's liveDocs query.
export type LiveDoc = {
  readonly id: number;
  readonly name: string;
  readonly mime: string;
  readonly date: string;
  readonly isPdf: boolean;
  readonly docType: string;
  readonly hasExtracted: boolean;
};

type DocumentsProps = {
  readonly boot: BootContext;
  readonly liveDocs: readonly LiveDoc[];
  readonly copilotBackend: string;
};

type UploadState =
  | { kind: 'idle' }
  | { kind: 'uploading' }
  | { kind: 'done'; docId: number }
  | { kind: 'error'; message: string };

type ExtractState = 'idle' | 'extracting' | 'done' | 'error';

export function Documents({ boot, liveDocs, copilotBackend }: DocumentsProps): JSX.Element {
  const fileRef = useRef<HTMLInputElement>(null);
  const [upload, setUpload] = useState<UploadState>({ kind: 'idle' });
  // Per-row extract status, keyed by docId.
  const [extract, setExtract] = useState<Record<number, ExtractState>>({});
  // Locally-deleted doc ids, hidden until next reload.
  const [deleted, setDeleted] = useState<ReadonlySet<number>>(new Set());

  const onUploadClick = (): void => {
    if (!boot.patientId) {
      // Mirror the PHP "open a patient first" behavior. The upload endpoint
      // files documents against $_SESSION['pid'].
      window.alert('Open a patient chart first — uploads are filed against an active patient.');
      return;
    }
    fileRef.current?.click();
  };

  const onFileChange = async (e: React.ChangeEvent<HTMLInputElement>): Promise<void> => {
    const file = e.target.files?.[0];
    if (!file) return;
    setUpload({ kind: 'uploading' });
    const fd = new FormData();
    fd.append('file', file);
    // OpenEMR CsrfUtils::verifyCsrfToken reads the field as csrf_token_form
    // (see interface/patient_file/documents/copilot_documents_upload.php).
    if (boot.csrf) fd.append('csrf_token_form', boot.csrf);
    // Upload handler files docs against an explicit patient_id POST field
    // (line 61 of copilot_documents_upload.php). Without this, it 400s
    // with "Missing patient_id" even though $_SESSION['pid'] is set —
    // the handler uses POST, not session, for the file destination.
    if (boot.patientId) fd.append('patient_id', String(boot.patientId));
    try {
      const resp = await fetch('./copilot_documents_upload.php', {
        method: 'POST',
        body: fd,
      });
      const data = (await resp.json().catch(() => ({}))) as {
        ok?: boolean;
        doc_id?: number;
        error?: string;
      };
      if (!resp.ok || data.ok === false) {
        throw new Error(data.error ?? `HTTP ${resp.status}`);
      }
      setUpload({ kind: 'done', docId: Number(data.doc_id ?? 0) });
      // Reload the iframe so the wrapper re-runs the liveDocs query and
      // the just-uploaded row appears with its Extract button. Match the
      // pre-React PHP behavior (window.location.reload after upload).
      setTimeout(() => { window.location.reload(); }, 600);
    } catch (err) {
      const message = err instanceof Error ? err.message : String(err);
      setUpload({ kind: 'error', message });
      window.alert(`Upload failed: ${message}`);
    } finally {
      // Reset the file input so the same file can be re-selected if needed.
      if (e.target) e.target.value = '';
    }
  };

  const uploadLabel =
    upload.kind === 'uploading' ? 'Uploading…'
    : upload.kind === 'done'    ? `✓ Uploaded #${upload.docId}`
    : 'Upload';

  const onExtract = async (doc: LiveDoc): Promise<void> => {
    if (!boot.patientId || !copilotBackend) return;
    setExtract((s) => ({ ...s, [doc.id]: 'extracting' }));
    try {
      const resp = await fetch(`${copilotBackend}/extract`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          patient_id: boot.patientId,
          doc_type: doc.docType,
          document_id: doc.id,
        }),
      });
      const data = (await resp.json().catch(() => ({}))) as { detail?: string; fact_count?: number };
      if (!resp.ok) throw new Error(data.detail ?? `HTTP ${resp.status}`);
      setExtract((s) => ({ ...s, [doc.id]: 'done' }));
      setTimeout(() => { window.location.reload(); }, 700);
    } catch (err) {
      const message = err instanceof Error ? err.message : String(err);
      setExtract((s) => ({ ...s, [doc.id]: 'error' }));
      window.alert(`Extraction failed: ${message}`);
    }
  };

  const onDelete = async (doc: LiveDoc): Promise<void> => {
    const ok = window.confirm(
      `Delete "${doc.name}" and any extracted facts derived from it?\n\nThis cannot be undone.`,
    );
    if (!ok) return;
    try {
      const fd = new FormData();
      fd.append('docref', String(doc.id));
      if (boot.csrf) fd.append('csrf_token_form', boot.csrf);
      const resp = await fetch('./copilot_documents_delete.php', {
        method: 'POST',
        body: fd,
        credentials: 'same-origin',
      });
      const data = (await resp.json().catch(() => ({}))) as { ok?: boolean; error?: string };
      if (!resp.ok || data.ok === false) {
        throw new Error(data.error ?? `HTTP ${resp.status}`);
      }
      setDeleted((s) => {
        const next = new Set(s);
        next.add(doc.id);
        return next;
      });
      // If we just deleted the last live row, reload to refresh the
      // empty-state messaging from the server.
      const remaining = liveDocs.filter((d) => !deleted.has(d.id) && d.id !== doc.id);
      if (remaining.length === 0) {
        setTimeout(() => { window.location.reload(); }, 300);
      }
    } catch (err) {
      const message = err instanceof Error ? err.message : String(err);
      window.alert(`Delete failed: ${message}`);
    }
  };

  const visibleLive = liveDocs.filter((d) => !deleted.has(d.id));

  return (
    <>
      <header className={styles.head}>
        <div className={styles.title}>Documents</div>
        <div className={styles.bullet}>•</div>
        <div className={styles.meta}>47 files in 6 categories</div>
        <div className={styles.spacer} />
        <button type="button" className={styles.pill}>
          <span className={styles.pillIcon}>🔍</span>
          <span>Search documents</span>
        </button>
        <button type="button" className={styles.pill}>
          <span className={styles.pillIcon}>⇅</span>
          <span>Recent first</span>
        </button>
        <button
          type="button"
          className={styles.upload}
          onClick={onUploadClick}
          disabled={upload.kind === 'uploading'}
        >
          <span className={styles.uploadIcon}>
            {upload.kind === 'done' ? '' : '⬆'}
          </span>
          <span>{uploadLabel}</span>
        </button>
        <input
          ref={fileRef}
          type="file"
          accept=".pdf,application/pdf,image/*"
          onChange={onFileChange}
          style={{ display: 'none' }}
          aria-hidden="true"
          tabIndex={-1}
        />
      </header>

      <div className={styles.body}>
        <aside className={styles.side}>
          <div className={styles.sideHead}>CATEGORIES</div>
          {CATEGORIES.map((c) => {
            const cls = c.active ? `${styles.cat} ${styles.catActive}` : styles.cat;
            return (
              <div key={c.label} className={cls}>
                <span className={styles.catIcon}>{c.icon}</span>
                <span className={styles.catLabel}>{c.label}</span>
                <span className={styles.catCount}>{c.count}</span>
              </div>
            );
          })}
        </aside>

        <main className={styles.main}>
          {visibleLive.length > 0 && (
            <>
              <SectionLabel>LIVE — uploaded on this chart</SectionLabel>
              <div className={styles.earlierCard} style={{ marginBottom: 16 }}>
                {visibleLive.map((d) => {
                  const state = extract[d.id] ?? 'idle';
                  const extractDone = d.hasExtracted || state === 'done';
                  const extractBtnLabel =
                    state === 'extracting' ? 'Extracting…'
                    : extractDone           ? '✓ Extraction complete'
                    : 'Extract';
                  return (
                    <div key={d.id} className={styles.earlierRow} style={{ display: 'flex', alignItems: 'center', gap: 12 }}>
                      <div className={styles.earlierIcon}>{d.isPdf ? '📄' : '📎'}</div>
                      <div className={styles.earlierInfo}>
                        <div className={styles.earlierTitle}>{d.name}</div>
                        <div className={styles.earlierSub}>
                          <span className={styles.catPill}>{d.mime}</span>
                          <span style={{ marginLeft: 8 }}>doc #{d.id}</span>
                        </div>
                      </div>
                      <div style={{ flex: 1 }} />
                      <div className={styles.earlierDate}>{d.date}</div>
                      {d.isPdf && (
                        <button
                          type="button"
                          disabled={state === 'extracting' || extractDone}
                          onClick={() => onExtract(d)}
                          style={{
                            background: extractDone ? '#2d7a4f' : '#008C8C',
                            color: '#FFFFFF',
                            border: 'none',
                            borderRadius: 999,
                            padding: '6px 12px',
                            fontSize: 11,
                            fontWeight: 600,
                            cursor: extractDone ? 'default' : 'pointer',
                            opacity: extractDone ? 0.95 : 1,
                          }}
                          title={extractDone ? 'Extraction already complete for this document.' : undefined}
                        >
                          {extractBtnLabel}
                        </button>
                      )}
                      <button
                        type="button"
                        onClick={() => onDelete(d)}
                        style={{
                          background: '#FFFFFF',
                          color: '#a01d1d',
                          border: '1px solid #d6a3a3',
                          borderRadius: 999,
                          padding: '6px 12px',
                          fontSize: 11,
                          fontWeight: 600,
                          cursor: 'pointer',
                        }}
                        title="Delete this document and any extracted facts derived from it. Cannot be undone."
                      >
                        Delete
                      </button>
                    </div>
                  );
                })}
              </div>
            </>
          )}
          <SectionLabel>RECENT</SectionLabel>
          <div className={styles.recentGrid}>
            {RECENT.map((r) => (
              <article key={r.title} className={styles.recentCard}>
                <div className={styles.thumb}>
                  <div className={styles.thumbDoc}>
                    <span className={styles.thumbLineHard} />
                    <span className={styles.thumbLine} style={{ width: '86%' }} />
                    <span className={styles.thumbLine} style={{ width: '78%' }} />
                    <span className={styles.thumbLine} style={{ width: '70%' }} />
                    <span className={styles.thumbLine} style={{ width: '92%' }} />
                  </div>
                </div>
                <div className={styles.recentBody}>
                  <span className={`${styles.catPill} ${styles[`catPill_${r.catKind}`] ?? ''}`}>
                    {r.cat}
                  </span>
                  <div className={styles.recentTitle}>{r.title}</div>
                  <div className={styles.recentMeta}>{r.meta}</div>
                </div>
              </article>
            ))}
          </div>

          <SectionLabel>EARLIER</SectionLabel>
          <div className={styles.earlierCard}>
            {EARLIER.map((e, i) => {
              const rowClass = i === EARLIER.length - 1
                ? `${styles.earlRow} ${styles.earlRowLast}`
                : styles.earlRow;
              const iconClass = `${styles.earlIcon} ${styles[`earlIcon_${e.iconKind}`] ?? ''}`;
              return (
                <div key={e.title} className={rowClass}>
                  <div className={iconClass}>{e.icon}</div>
                  <div className={styles.earlInfo}>
                    <div className={styles.earlTitle}>{e.title}</div>
                    <div className={styles.earlSub}>
                      <span className={styles.earlCatPill}>{e.cat}</span>
                      <span>{e.src}</span>
                    </div>
                  </div>
                  <div className={styles.earlSpacer} />
                  <div className={styles.earlDate}>{e.date}</div>
                  <div className={styles.earlSize}>{e.size}</div>
                  <div className={styles.earlKebab}>⋯</div>
                </div>
              );
            })}
          </div>
        </main>
      </div>
    </>
  );
}

type SectionLabelProps = { readonly children: React.ReactNode };

function SectionLabel({ children }: SectionLabelProps): JSX.Element {
  return (
    <div className={styles.sectionRow}>
      <span className={styles.sectionLabel}>{children}</span>
      <span className={styles.sectionRule} />
    </div>
  );
}
