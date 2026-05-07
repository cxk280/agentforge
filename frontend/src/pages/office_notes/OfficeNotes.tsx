// Office Notes — Figma "Screen 49". Cross-staff bulletin / sticky-note board
// for internal staff notes & announcements (not part of the patient chart).
//
// 1:1 React port of the PHP-rendered mock previously at
// /interface/main/onotes/copilot_office_notes.php. The PHP version had a
// real `onotes` DB integration with cp_* extension columns, pin/archive/reply
// POST handlers, GET filters, etc. — that wiring is intentionally dropped
// for the React port and replaced with hardcoded demo data matching the
// Figma exactly. Filter pills are interactive client-side; archive / new /
// help / reply / sort are wired as inert buttons (no-op handlers) until the
// API contract is reintroduced.
//
// Demo data, palette, copy, and counts all come from the Figma node
// (kj4MWNr8mpjZ2wVg1PbS0F #98:2). The 10 visible cards on the board match
// the design exactly; the filter-pill totals (24/6/5/8/4/3/2) are also from
// the design and represent a "full bulletin" aggregate, not the count of
// rendered cards.
//
// Original PHP preserved at copilot_office_notes.php.bak.

import { useState } from 'react';
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

type Note = {
  readonly id: number;
  readonly category: string;       // display label, uppercased
  readonly catSlug: Exclude<FilterSlug, 'all' | 'pinned'>;
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

type FilterPill = {
  readonly slug: FilterSlug;
  readonly label: string;
  readonly count: number;
};

const FILTERS: readonly FilterPill[] = [
  { slug: 'all',         label: 'All',         count: 24 },
  { slug: 'pinned',      label: 'Pinned',      count: 6 },
  { slug: 'pharmacy',    label: 'Pharmacy',    count: 5 },
  { slug: 'clinical',    label: 'Clinical',    count: 8 },
  { slug: 'front_desk',  label: 'Front desk',  count: 4 },
  { slug: 'billing',     label: 'Billing',     count: 3 },
  { slug: 'maintenance', label: 'Maintenance', count: 2 },
];

// Same 10 demo notes the Figma board shows, in the same grid order (5 across,
// 2 rows). Authors, timestamps, icons, tones — verbatim from the design.
const NOTES: readonly Note[] = [
  {
    id: 1,
    category: 'PHARMACY',
    catSlug: 'pharmacy',
    icon: '\u{1F4CC}', // pushpin (pinned card)
    tone: 'yellow',
    pinned: true,
    body: 'Walgreens Tarrytown is closed for renovation through 5/15. Reroute Rx to Walgreens 38th St until further notice.',
    author: 'Sandra (front desk)',
    initials: 'SC',
    avatar: 'teal',
    when: '04/30 09:14',
    comments: 0,
  },
  {
    id: 2,
    category: 'CLINICAL',
    catSlug: 'clinical',
    icon: '\u{1FA7A}', // stethoscope
    tone: 'blue',
    pinned: true,
    body: 'Dr. Patel out 5/3-5/7 for conference. NP Jones covering acute slots, Dr. Chen covering established patients.',
    author: 'Dr. Rivera',
    initials: 'ER',
    avatar: 'blue',
    when: '04/29 16:30',
    comments: 0,
  },
  {
    id: 3,
    category: 'PHARMACY',
    catSlug: 'pharmacy',
    icon: '\u{1F489}', // syringe
    tone: 'green',
    pinned: false,
    body: 'New shipment of Shingrix arrived \u{2014} 80 doses. Stocked in vaccine fridge B. PIN: 4 (cold chain log updated).',
    author: 'Maria (RN)',
    initials: 'MN',
    avatar: 'mint',
    when: '04/29 14:00',
    comments: 0,
  },
  {
    id: 4,
    category: 'BILLING',
    catSlug: 'billing',
    icon: '\u{1F4B5}', // dollar
    tone: 'pink',
    pinned: false,
    body: 'Aetna Claims edit 2026-Q2: HCPCS G0438 needs Z-code modifier through end of quarter. Will revert in Q3.',
    author: 'Linda (billing)',
    initials: 'LB',
    avatar: 'pink',
    when: '04/28 10:00',
    comments: 0,
  },
  {
    id: 5,
    category: 'FRONT DESK',
    catSlug: 'front_desk',
    icon: '\u{1F6AA}', // door
    tone: 'violet',
    pinned: false,
    body: 'Lobby coffee machine making weird grinding noise. Maintenance ticketed (#WO-4429). Avoid until repaired.',
    author: 'Sandra',
    initials: 'SC',
    avatar: 'purple',
    when: '04/28 08:30',
    comments: 0,
  },
  {
    id: 6,
    category: 'PHARMACY',
    catSlug: 'pharmacy',
    icon: '\u{1F4DE}', // telephone
    tone: 'yellow',
    pinned: false,
    body: 'Sun Pharma announced shortage of generic levothyroxine 50/75/100 mcg \u{2014} 6 week ETA. Use alternate manufacturers.',
    author: 'Dr. Chen',
    initials: 'SC',
    avatar: 'orange',
    when: '04/27 11:14',
    comments: 0,
  },
  {
    id: 7,
    category: 'CLINICAL',
    catSlug: 'clinical',
    icon: '\u{1FA7A}', // stethoscope
    tone: 'blue',
    pinned: false,
    body: 'Reminder: Q1 2026 CQM submission deadline is 3/31/2027. Marcus pulling preliminary scores end of week.',
    author: 'Dr. Rivera',
    initials: 'ER',
    avatar: 'blue',
    when: '04/27 09:45',
    comments: 0,
  },
  {
    id: 8,
    category: 'MAINTENANCE',
    catSlug: 'maintenance',
    icon: '\u{1F6E0}', // hammer & wrench
    tone: 'violet',
    pinned: false,
    body: 'Exam 5 BP cuff replaced (old cuff readings ran 8-10 mmHg low). New cuff calibrated 4/26.',
    author: 'Brad (BMET)',
    initials: 'BH',
    avatar: 'green',
    when: '04/26 15:30',
    comments: 0,
  },
  {
    id: 9,
    category: 'BILLING',
    catSlug: 'billing',
    icon: '\u{1F4B5}', // dollar
    tone: 'pink',
    pinned: false,
    body: 'Reminder to use updated Z-code list for SDOH screening (Z55-Z65). Reimbursement increased 4/1.',
    author: 'Linda',
    initials: 'LB',
    avatar: 'pink',
    when: '04/26 10:00',
    comments: 0,
  },
  {
    id: 10,
    category: 'CLINICAL',
    catSlug: 'clinical',
    icon: '\u{1FA7A}', // stethoscope
    tone: 'blue',
    pinned: false,
    body: 'Pt Margaret Chen has new Penicillin allergy entered 4/26 \u{2014} please verify chart and update e-Rx allergy list.',
    author: 'Dr. Rivera',
    initials: 'ER',
    avatar: 'blue',
    when: '04/26 09:00',
    comments: 0,
  },
];

const TOTAL_NOTES = FILTERS[0]?.count ?? NOTES.length;

type OfficeNotesProps = {
  readonly boot: BootContext;
};

export function OfficeNotes(_props: OfficeNotesProps): JSX.Element {
  const [activeFilter, setActiveFilter] = useState<FilterSlug>('all');

  const visibleNotes = NOTES.filter((n) => {
    if (activeFilter === 'all') return true;
    if (activeFilter === 'pinned') return n.pinned;
    return n.catSlug === activeFilter;
  });

  return (
    <>
      <header className={styles.head}>
        <span className={styles.title}>Office Notes</span>
        <span className={styles.bullet}>&bull;</span>
        <span className={styles.meta}>
          Internal staff notes &amp; announcements &middot; Riverside Family Medicine &middot; {TOTAL_NOTES} total
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
        {FILTERS.map((f) => {
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
              <span className={ctCls}>{f.count}</span>
            </button>
          );
        })}
        <span className={styles.sort}>Sort: Newest &#9662;</span>
      </div>

      <div className={styles.board}>
        {visibleNotes.length === 0 && (
          <div className={styles.empty}>No office notes match this filter.</div>
        )}
        {visibleNotes.map((n) => (
          <article key={n.id} className={`${styles.note} ${styles[`tone_${n.tone}`]}`}>
            <div className={styles.top}>
              <span className={styles.icon} aria-hidden="true">{n.icon}</span>
              <span className={styles.cat}>{n.category}</span>
              {n.pinned && <span className={styles.pinnedPill}>Pinned</span>}
            </div>
            <div className={styles.body}>{n.body}</div>
            <div className={styles.divider} />
            <div className={styles.authorRow}>
              <span className={`${styles.av} ${styles[`av_${n.avatar}`]}`}>{n.initials}</span>
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
        ))}
      </div>
    </>
  );
}
