// Office Notes — Figma "Screen 49". Cross-staff bulletin / sticky-note board
// for internal staff notes & announcements (not part of the patient chart).
//
// 1:1 React port of the PHP-rendered mock previously at
// /interface/main/onotes/copilot_office_notes.php. The PHP wrapper now queries
// the `onotes` table (with cp_pinned / cp_category / cp_parent_id extension
// columns) server-side and JSON-encodes the result as data-notes on #cp-root;
// the entry index.tsx parses it and passes it here as the `payload` prop.
//
// Filter pills are interactive client-side; archive / new / help / reply /
// sort are wired as inert buttons (no-op handlers) until the API contract is
// reintroduced.
//
// Original PHP preserved at copilot_office_notes.php.bak.

import { useMemo, useState } from 'react';
import type { BootContext } from '../../shared/lib/bootContext';
import styles from './OfficeNotes.module.css';

type Tone = 'yellow' | 'blue' | 'green' | 'pink' | 'violet';
type AvatarTone = 'teal' | 'blue' | 'orange' | 'green' | 'pink' | 'mint' | 'purple';

type FilterSlug =
  | 'all'
  | 'pinned'
  | 'pharmacy'
  | 'clinical'
  | 'front_desk'
  | 'billing'
  | 'maintenance';

// Server-side note shape — mirrors the JSON payload built in
// copilot_office_notes.php. `tone` and `avatar` come from the wrapper so we
// don't have to repeat the colour/shape mapping in two places.
export type Note = {
  readonly id: number;
  readonly category: string;       // display label, uppercased
  readonly catSlug: string;        // raw cp_category slug (may include 'general')
  readonly icon: string;
  readonly tone: Tone;
  readonly pinned: boolean;
  readonly body: string;
  readonly author: string;
  readonly initials: string;
  readonly avatar: AvatarTone;
  readonly when: string;
  readonly comments: number;
};

export type CountsPayload = {
  readonly all: number;
  readonly pinned: number;
  readonly pharmacy: number;
  readonly clinical: number;
  readonly front_desk: number;
  readonly billing: number;
  readonly maintenance: number;
};

export type OfficeNotesPayload = {
  readonly notes: readonly Note[];
  readonly counts: CountsPayload;
};

type FilterPill = {
  readonly slug: FilterSlug;
  readonly label: string;
};

const FILTER_PILLS: readonly FilterPill[] = [
  { slug: 'all',         label: 'All' },
  { slug: 'pinned',      label: 'Pinned' },
  { slug: 'pharmacy',    label: 'Pharmacy' },
  { slug: 'clinical',    label: 'Clinical' },
  { slug: 'front_desk',  label: 'Front desk' },
  { slug: 'billing',     label: 'Billing' },
  { slug: 'maintenance', label: 'Maintenance' },
];

// Defensive coerce: server payload may have an unrecognized tone/avatar
// (legacy data, future categories), so we fall back to safe defaults rather
// than letting CSS modules silently miss a class.
const VALID_TONES: ReadonlySet<Tone> = new Set<Tone>(['yellow', 'blue', 'green', 'pink', 'violet']);
const VALID_AVATARS: ReadonlySet<AvatarTone> = new Set<AvatarTone>([
  'teal', 'blue', 'orange', 'green', 'pink', 'mint', 'purple',
]);

function safeTone(t: string | undefined): Tone {
  return t !== undefined && VALID_TONES.has(t as Tone) ? (t as Tone) : 'yellow';
}
function safeAvatar(a: string | undefined): AvatarTone {
  return a !== undefined && VALID_AVATARS.has(a as AvatarTone) ? (a as AvatarTone) : 'teal';
}

type OfficeNotesProps = {
  readonly boot: BootContext;
  readonly payload: OfficeNotesPayload;
};

export function OfficeNotes({ payload }: OfficeNotesProps): JSX.Element {
  const [activeFilter, setActiveFilter] = useState<FilterSlug>('all');

  const counts = payload.counts;
  const notes = payload.notes;

  const visibleNotes = useMemo(() => notes.filter((n) => {
    if (activeFilter === 'all') return true;
    if (activeFilter === 'pinned') return n.pinned;
    return n.catSlug === activeFilter;
  }), [notes, activeFilter]);

  const totalNotes = counts.all;

  const pillCount = (slug: FilterSlug): number => {
    switch (slug) {
      case 'all':         return counts.all;
      case 'pinned':      return counts.pinned;
      case 'pharmacy':    return counts.pharmacy;
      case 'clinical':    return counts.clinical;
      case 'front_desk':  return counts.front_desk;
      case 'billing':     return counts.billing;
      case 'maintenance': return counts.maintenance;
    }
  };

  return (
    <>
      <header className={styles.head}>
        <span className={styles.title}>Office Notes</span>
        <span className={styles.bullet}>&bull;</span>
        <span className={styles.meta}>
          Internal staff notes &amp; announcements &middot; Riverside Family Medicine &middot; {totalNotes} total
        </span>
        <span className={styles.spacer} />
        <button type="button" className={styles.btnGhost}>
          <span aria-hidden="true">&#x232B;</span> Archive
        </button>
        <button type="button" className={styles.btnPrimary}>+ New office note</button>
        <button type="button" className={styles.helpPill} disabled title="Help is out of scope for this demo">
          ? Help
        </button>
      </header>

      <div className={styles.filter} role="tablist" aria-label="Filter office notes">
        {FILTER_PILLS.map((f) => {
          const active = activeFilter === f.slug;
          const cls = active ? `${styles.pill} ${styles.pillActive}` : styles.pill;
          const ctCls = active ? `${styles.ct} ${styles.ctActive}` : styles.ct;
          return (
            <button
              key={f.slug}
              type="button"
              role="tab"
              aria-selected={active}
              className={cls}
              onClick={() => setActiveFilter(f.slug)}
            >
              {f.label}
              <span className={ctCls}>{pillCount(f.slug)}</span>
            </button>
          );
        })}
        <span className={styles.sort}>Sort: Newest &#9662;</span>
      </div>

      <div className={styles.board}>
        {visibleNotes.length === 0 && (
          <div className={styles.empty}>No office notes match this filter.</div>
        )}
        {visibleNotes.map((n) => {
          const tone = safeTone(n.tone);
          const avatar = safeAvatar(n.avatar);
          const toneClass = styles[`tone_${tone}`] ?? '';
          const avClass = styles[`av_${avatar}`] ?? '';
          return (
            <article key={n.id} className={`${styles.note} ${toneClass}`}>
              <div className={styles.top}>
                <span className={styles.icon} aria-hidden="true">{n.icon}</span>
                <span className={styles.cat}>{n.category}</span>
                {n.pinned && <span className={styles.pinnedPill}>Pinned</span>}
              </div>
              <div className={styles.body}>{n.body}</div>
              <div className={styles.divider} />
              <div className={styles.authorRow}>
                <span className={`${styles.av} ${avClass}`}>{n.initials}</span>
                <span className={styles.who}>
                  <span className={styles.name}>{n.author}</span>
                  <span className={styles.when}>{n.when}</span>
                </span>
              </div>
              <div className={styles.foot}>
                <span className={styles.comments}>&#128172; {n.comments}</span>
                <button type="button" className={styles.replyToggle}>
                  <span aria-hidden="true">&#9112;</span> Reply
                </button>
              </div>
            </article>
          );
        })}
      </div>
    </>
  );
}
