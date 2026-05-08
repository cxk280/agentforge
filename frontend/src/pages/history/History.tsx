// History — Figma "Screen 12 — History".
//
// Static demo port of the patient Visit History page. Renders the local
// page header (Visit History title + encounter count + filter chips +
// New Visit button) and a year-grouped list of visit cards. The outer
// shell (navy top nav, patient-demographics banner, navtab strip) is
// owned by the surrounding PHP wrapper; this component only renders the
// page body that lives below those shells.
//
// Hardcoded from Figma node 33:2 — no DB, no API. Wiring to real
// /apis/copilot/history endpoints is a follow-up.

import { useState } from 'react';
import type { BootContext } from '../../shared/lib/bootContext';
import styles from './History.module.css';

type FilterKey = 'all' | 'office' | 'tele' | 'lab' | 'acute';

type Filter = {
  readonly key: FilterKey;
  readonly label: string;
};

const FILTERS: readonly Filter[] = [
  { key: 'all',    label: 'All' },
  { key: 'office', label: 'Office Visit' },
  { key: 'tele',   label: 'Telehealth' },
  { key: 'lab',    label: 'Lab Review' },
  { key: 'acute',  label: 'Acute' },
];

type RailKind = 'lab-review' | 'annual-physical' | 'follow-up';

type Tag = {
  readonly label: string;
  readonly kind?: 'default' | 'copilot' | undefined;
};

type Visit = {
  readonly date: string;
  readonly day: string;
  readonly rail: RailKind;
  readonly typeLabel: string;
  readonly provider: string;
  readonly modality?: string | undefined;
  readonly duration: string;
  readonly status: string;
  readonly title: string;
  readonly desc: string;
  readonly tags: readonly Tag[];
};

type YearGroup = {
  readonly year: string;
  readonly visits: readonly Visit[];
};

const TOTAL_LABEL = '32 encounters since 2018';

// Hardcoded demo data taken straight from the Figma frame.
const YEAR_GROUPS: readonly YearGroup[] = [
  {
    year: '2026',
    visits: [
      {
        date: 'Apr 12',
        day: 'Tuesday',
        rail: 'lab-review',
        typeLabel: 'Lab Review',
        provider: 'Dr. S. Chen',
        modality: 'Tele',
        duration: '15 min',
        status: 'Signed',
        title: 'A1C trending up — review medication options',
        desc: 'Discussed recent A1C of 7.9% (up from 7.2%). Reviewed dietary log. Recommended adding GLP-1 agonist if no improvement at next check.',
        tags: [
          { label: 'A1C ↑' },
          { label: 'GLP-1 considered' },
          { label: '✦ Co-Pilot insight', kind: 'copilot' },
        ],
      },
      {
        date: 'Feb 18',
        day: 'Wednesday',
        rail: 'annual-physical',
        typeLabel: 'Annual Physical',
        provider: 'Dr. E. Rivera',
        duration: '30 min',
        status: 'Signed',
        title: 'Annual exam — diabetes well-controlled, BP improved',
        desc: 'BP 130/82 (down from 145/90). All age-appropriate screenings ordered. No new concerns. Continue current regimen.',
        tags: [
          { label: 'BP improved' },
          { label: 'Routine' },
        ],
      },
      {
        date: 'Nov 15',
        day: 'Friday',
        rail: 'follow-up',
        typeLabel: 'Follow-up',
        provider: 'Dr. E. Rivera',
        duration: '20 min',
        status: 'Signed',
        title: 'Diabetes follow-up — Lisinopril increased',
        desc: 'BP elevated at 145/90. Increased Lisinopril from 5 mg to 10 mg daily. Recheck in 6 weeks.',
        tags: [
          { label: 'Rx adjusted' },
        ],
      },
    ],
  },
];

type HistoryProps = {
  readonly boot: BootContext;
};

export function History(_props: HistoryProps): JSX.Element {
  const [activeFilter, setActiveFilter] = useState<FilterKey>('all');

  return (
    <>
      <header className={styles.head}>
        <div className={styles.title}>Visit History</div>
        <div className={styles.metaDot} aria-hidden="true" />
        <div className={styles.meta}>{TOTAL_LABEL}</div>
        <div className={styles.spacer} />
        <div className={styles.filters}>
          {FILTERS.map((f) => {
            const active = f.key === activeFilter;
            const cls = active
              ? `${styles.chip} ${styles.chipActive}`
              : styles.chip;
            return (
              <button
                key={f.key}
                type="button"
                className={cls}
                onClick={() => setActiveFilter(f.key)}
              >
                {f.label}
              </button>
            );
          })}
          <button type="button" className={styles.newBtn}>
            <span className={styles.newPlus}>+</span>
            <span>New Visit</span>
          </button>
        </div>
      </header>

      <main className={styles.body}>
        {YEAR_GROUPS.map((group) => (
          <YearSection key={group.year} group={group} />
        ))}
      </main>
    </>
  );
}

type YearSectionProps = {
  readonly group: YearGroup;
};

function YearSection({ group }: YearSectionProps): JSX.Element {
  return (
    <>
      <div className={styles.yearRow}>
        <span className={styles.year}>{group.year}</span>
        <span className={styles.yearRule} />
      </div>
      {group.visits.map((v, i) => (
        <VisitCard key={`${group.year}-${i}`} visit={v} />
      ))}
    </>
  );
}

type VisitCardProps = {
  readonly visit: Visit;
};

function VisitCard({ visit }: VisitCardProps): JSX.Element {
  const railClass =
    visit.rail === 'lab-review'
      ? `${styles.rail} ${styles.railLab}`
      : visit.rail === 'annual-physical'
        ? `${styles.rail} ${styles.railAnnual}`
        : `${styles.rail} ${styles.railFollow}`;

  return (
    <article className={styles.card}>
      <div className={railClass}>
        <span className={styles.railDate}>{visit.date}</span>
        <span className={styles.railDay}>{visit.day}</span>
        <span className={styles.railSpacer} />
        <span className={styles.railPip} />
        <span className={styles.railTypePill}>{visit.typeLabel}</span>
      </div>
      <div className={styles.divider} />
      <div className={styles.cardBody}>
        <div className={styles.metaRow}>
          <span className={styles.provider}>{visit.provider}</span>
          <span className={styles.metaSep}>•</span>
          <span className={styles.metaDim}>
            {visit.modality !== undefined ? `${visit.modality} • ${visit.duration}` : visit.duration}
          </span>
          <span className={styles.metaSep}>•</span>
          <span className={styles.signed}>{visit.status}</span>
        </div>
        <div className={styles.vTitle}>{visit.title}</div>
        <p className={styles.vDesc}>{visit.desc}</p>
        <div className={styles.tagsRow}>
          <div className={styles.tags}>
            {visit.tags.map((t, i) => {
              const cls =
                t.kind === 'copilot'
                  ? `${styles.tag} ${styles.tagCopilot}`
                  : styles.tag;
              return (
                <span key={i} className={cls}>
                  {t.label}
                </span>
              );
            })}
          </div>
          <div className={styles.tagsSpacer} />
          <a className={styles.openLink} href="#">
            Open visit →
          </a>
        </div>
      </div>
    </article>
  );
}
