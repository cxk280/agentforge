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

type DocumentsProps = {
  readonly boot: BootContext;
};

export function Documents(_props: DocumentsProps): JSX.Element {
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
        <button type="button" className={styles.upload}>
          <span className={styles.uploadIcon}>⬆</span>
          <span>Upload</span>
        </button>
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
