// History — Figma "Screen 12 — History".
//
// 1:1 port of the PHP-rendered patient Visit History page previously at
// /interface/patient_file/history/copilot_history.php (preserved as .bak).
// DB-backed: the PHP wrapper queries form_encounter LEFT JOIN users for the
// active patient and JSON-encodes the result as data-history on #cp-root;
// the entry index.tsx parses it and passes it here as the `history` prop,
// so the UI reflects the patient the user actually opened.
//
// The outer shell (navy top nav, patient-demographics banner, navtab strip)
// is owned by the surrounding PHP wrapper; this component only renders the
// page body that lives below those shells. Filter chips and search are
// purely client-side state.

import { useMemo, useState } from 'react';
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

// Server-side row shape (mirrors the PHP query in copilot_history.php).
export type Visit = {
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

export type YearGroup = {
  readonly year: string;
  readonly visits: readonly Visit[];
};

export type HistoryData = {
  readonly totalCount: number;
  readonly years: readonly YearGroup[];
};

// Map a typeLabel onto the FilterKey used by the chip strip. Anything we
// don't recognise falls into the 'all' bucket (only 'all' shows it).
function filterKeyForType(typeLabel: string): Exclude<FilterKey, 'all'> | null {
  switch (typeLabel) {
    case 'Office Visit':     return 'office';
    case 'Telehealth':       return 'tele';
    case 'Lab Review':       return 'lab';
    case 'Annual Physical':  return null;
    case 'Follow-up':        return null;
    default:                 return null;
  }
}

function totalLabel(years: readonly YearGroup[], totalCount: number): string {
  if (totalCount <= 0 || years.length === 0) {
    return `${totalCount} encounters`;
  }
  // Earliest year is the last group (server orders DESC, so the last entry
  // in `years` holds the oldest visits).
  const lastGroup = years[years.length - 1];
  const earliestYear = lastGroup?.year ?? '';
  return earliestYear !== ''
    ? `${totalCount} encounters since ${earliestYear}`
    : `${totalCount} encounters`;
}

type HistoryProps = {
  readonly boot: BootContext;
  readonly history: HistoryData;
};

export function History({ history }: HistoryProps): JSX.Element {
  const [activeFilter, setActiveFilter] = useState<FilterKey>('all');

  const visibleYears = useMemo<readonly YearGroup[]>(() => {
    if (activeFilter === 'all') return history.years;
    return history.years
      .map<YearGroup>((g) => ({
        year: g.year,
        visits: g.visits.filter((v) => filterKeyForType(v.typeLabel) === activeFilter),
      }))
      .filter((g) => g.visits.length > 0);
  }, [history.years, activeFilter]);

  const hasAny = history.years.some((g) => g.visits.length > 0);

  return (
    <>
      <header className={styles.head}>
        <div className={styles.title}>Visit History</div>
        <div className={styles.metaDot} aria-hidden="true" />
        <div className={styles.meta}>{totalLabel(history.years, history.totalCount)}</div>
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
        {!hasAny ? (
          <div className={styles.yearRow}>
            <span className={styles.year}>No visits on file for this patient.</span>
            <span className={styles.yearRule} />
          </div>
        ) : (
          visibleYears.map((group) => (
            <YearSection key={group.year} group={group} />
          ))
        )}
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

  const hasModality = visit.modality !== undefined && visit.modality !== '';

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
            {hasModality ? `${visit.modality} • ${visit.duration}` : visit.duration}
          </span>
          <span className={styles.metaSep}>•</span>
          <span className={styles.signed}>{visit.status}</span>
        </div>
        <div className={styles.vTitle}>{visit.title}</div>
        {visit.desc !== '' && <p className={styles.vDesc}>{visit.desc}</p>}
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
