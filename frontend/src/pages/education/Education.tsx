// Education — Figma "Screen 48 — Patient Education".
//
// 1:1 port of the PHP-rendered mock previously at
// /interface/patient_file/education/copilot_education.php. The original page
// did real reads (patient_data, lists, procedure_result) and real writes
// (extended_log, onsite_messages); the React port is intentionally a static
// demo per the Batch-5 migration plan — same visual structure, same demo
// content, no DB. The navy top nav, demographics header2 banner and
// patient-tabs ribbon are still owned by the outer PHP shell and are not
// rendered here.
//
// State is held in React: category selection, search/level/format/source
// filters, and per-card "selected" checkboxes. The "Print" / "Portal" /
// "Preview" buttons are stubs (no-op) — wiring back to real endpoints is a
// follow-up captured in the migration plan.

import { useMemo, useState } from 'react';
import type { JSX } from 'react';
import type { BootContext } from '../../shared/lib/bootContext';
import styles from './Education.module.css';

// ── Types ─────────────────────────────────────────────────────────────

type CategoryKey =
  | 'all'  | 'diab' | 'card' | 'resp' | 'mind' | 'bone' | 'skin'
  | 'eye'  | 'kid'  | 'fem'  | 'med'  | 'nut'  | 'lab'  | 'proc';

type ArtKey =
  | 'book' | 'tube' | 'salad' | 'foot' | 'syringe'
  | 'warn' | 'phone' | 'eyeball';

type Format = 'Handout' | 'Video' | 'Web page';

type CatalogItem = {
  readonly id: string;
  readonly title: string;
  readonly desc: string;
  readonly source: string;
  readonly level: string;
  readonly format: Format;
  readonly pages: string;
  readonly langs: string;
  readonly art: ArtKey;
  readonly bg: string;
  readonly category: CategoryKey;
  readonly reviewed: string;        // YYYY-MM-DD
};

type CategoryDef = {
  readonly key: CategoryKey;
  readonly name: string;
  readonly icon: string;            // emoji-as-icon, matches the Figma rail
};

// ── Static catalog (matches the PHP version verbatim) ────────────────

const CATALOG: readonly CatalogItem[] = [
  { id: 'edu-diab-001', title: 'Living with type 2 diabetes',   desc: 'Diabetes basics — symptoms, monitoring, lifestyle', source: 'MedlinePlus', level: '6th grade', format: 'Handout', pages: '8 pgs',  langs: 'EN/ES', art: 'book',    bg: '#E8EEF5', category: 'diab', reviewed: '2025-09-12' },
  { id: 'edu-diab-002', title: 'Understanding your A1C',        desc: 'What it means, target ranges, action plan',         source: 'ADA',         level: '6th grade', format: 'Handout', pages: '4 pgs',  langs: 'EN/ES', art: 'tube',    bg: '#EAF2EE', category: 'diab', reviewed: '2025-11-04' },
  { id: 'edu-diab-003', title: 'Diabetes meal planning',        desc: 'Plate method, carb counting, sample meals',         source: 'ADA',         level: '5th grade', format: 'Handout', pages: '12 pgs', langs: 'EN/ES', art: 'salad',   bg: '#EFEAE0', category: 'diab', reviewed: '2025-08-20' },
  { id: 'edu-diab-004', title: 'Foot care for diabetics',       desc: 'Daily checks, when to call provider',               source: 'MedlinePlus', level: '5th grade', format: 'Handout', pages: '6 pgs',  langs: 'EN/ES', art: 'foot',    bg: '#F5EEEA', category: 'diab', reviewed: '2025-07-15' },
  { id: 'edu-diab-005', title: 'Insulin injection technique',   desc: 'Step-by-step with diagrams',                        source: 'CDC',         level: '6th grade', format: 'Handout', pages: '8 pgs',  langs: 'EN/ES', art: 'syringe', bg: '#EEEAF2', category: 'diab', reviewed: '2025-10-02' },
  { id: 'edu-diab-006', title: 'Hypoglycemia: low blood sugar', desc: 'Symptoms, treatment, prevention',                   source: 'ADA',         level: '5th grade', format: 'Handout', pages: '4 pgs',  langs: 'EN/ES', art: 'warn',    bg: '#F2EBE3', category: 'diab', reviewed: '2025-12-18' },
  { id: 'edu-diab-007', title: 'Continuous glucose monitors',   desc: 'How CGMs work, sensor setup',                       source: 'Dexcom',      level: '7th grade', format: 'Handout', pages: '10 pgs', langs: 'EN/ES', art: 'phone',   bg: '#E8EEF1', category: 'diab', reviewed: '2025-06-30' },
  { id: 'edu-diab-008', title: 'Diabetes and your eyes',        desc: 'Retinopathy screening, prevention',                 source: 'MedlinePlus', level: '5th grade', format: 'Handout', pages: '6 pgs',  langs: 'EN/ES', art: 'eyeball', bg: '#F0E9E1', category: 'diab', reviewed: '2025-09-01' },

  { id: 'edu-card-001', title: 'Managing high blood pressure',  desc: 'Lifestyle and medication basics',                   source: 'AHA',         level: '6th grade', format: 'Handout', pages: '6 pgs',  langs: 'EN/ES', art: 'book',    bg: '#E8EEF5', category: 'card', reviewed: '2025-10-15' },
  { id: 'edu-card-002', title: 'Heart-healthy eating',          desc: 'DASH diet basics and shopping tips',                source: 'AHA',         level: '5th grade', format: 'Handout', pages: '8 pgs',  langs: 'EN/ES', art: 'salad',   bg: '#EFEAE0', category: 'card', reviewed: '2025-09-22' },
  { id: 'edu-card-003', title: 'After your heart attack',       desc: 'Recovery, cardiac rehab, warning signs',            source: 'AHA',         level: '7th grade', format: 'Handout', pages: '12 pgs', langs: 'EN/ES', art: 'warn',    bg: '#F2EBE3', category: 'card', reviewed: '2025-08-05' },

  { id: 'edu-resp-001', title: 'Living with asthma',            desc: 'Triggers, action plans, inhaler technique',         source: 'CDC',         level: '5th grade', format: 'Handout', pages: '8 pgs',  langs: 'EN/ES', art: 'phone',   bg: '#E8EEF1', category: 'resp', reviewed: '2025-11-12' },
  { id: 'edu-resp-002', title: 'COPD basics',                   desc: 'Symptoms, breathing exercises, inhalers',           source: 'MedlinePlus', level: '6th grade', format: 'Handout', pages: '10 pgs', langs: 'EN/ES', art: 'tube',    bg: '#EAF2EE', category: 'resp', reviewed: '2025-07-08' },

  { id: 'edu-mind-001', title: 'Coping with depression',        desc: 'When to seek help, treatment options',              source: 'NIMH',        level: '7th grade', format: 'Handout', pages: '6 pgs',  langs: 'EN/ES', art: 'book',    bg: '#E8EEF5', category: 'mind', reviewed: '2025-10-28' },
  { id: 'edu-mind-002', title: 'Managing anxiety',              desc: 'Relaxation, breathing, when to call',               source: 'NIMH',        level: '6th grade', format: 'Handout', pages: '4 pgs',  langs: 'EN/ES', art: 'warn',    bg: '#F2EBE3', category: 'mind', reviewed: '2025-12-01' },

  { id: 'edu-bone-001', title: 'Low back pain',                 desc: 'Self-care, exercises, red flags',                   source: 'MedlinePlus', level: '5th grade', format: 'Handout', pages: '6 pgs',  langs: 'EN/ES', art: 'foot',    bg: '#F5EEEA', category: 'bone', reviewed: '2025-06-15' },
  { id: 'edu-skin-001', title: 'Wound care at home',            desc: 'Cleaning, dressing, infection signs',               source: 'MedlinePlus', level: '6th grade', format: 'Handout', pages: '4 pgs',  langs: 'EN/ES', art: 'syringe', bg: '#EEEAF2', category: 'skin', reviewed: '2025-09-30' },
  { id: 'edu-eye-001',  title: 'Cataracts: what to expect',     desc: 'Surgery, recovery, follow-up',                      source: 'MedlinePlus', level: '6th grade', format: 'Handout', pages: '6 pgs',  langs: 'EN/ES', art: 'eyeball', bg: '#F0E9E1', category: 'eye',  reviewed: '2025-08-12' },
  { id: 'edu-kid-001',  title: 'Childhood vaccine schedule',    desc: 'CDC recommended schedule birth–18',                 source: 'CDC',         level: '5th grade', format: 'Handout', pages: '4 pgs',  langs: 'EN/ES', art: 'syringe', bg: '#EEEAF2', category: 'kid',  reviewed: '2025-11-20' },
  { id: 'edu-fem-001',  title: 'Mammogram: what to expect',     desc: 'Preparation, the visit, results',                   source: 'CDC',         level: '6th grade', format: 'Handout', pages: '4 pgs',  langs: 'EN/ES', art: 'book',    bg: '#E8EEF5', category: 'fem',  reviewed: '2025-10-10' },
  { id: 'edu-med-001',  title: 'Metformin: medication guide',   desc: 'How to take it, side effects, missed dose',         source: 'FDA',         level: '6th grade', format: 'Handout', pages: '2 pgs',  langs: 'EN/ES', art: 'tube',    bg: '#EAF2EE', category: 'med',  reviewed: '2025-12-05' },
  { id: 'edu-med-002',  title: 'Lisinopril: medication guide',  desc: 'How to take it, side effects, missed dose',         source: 'FDA',         level: '6th grade', format: 'Handout', pages: '2 pgs',  langs: 'EN/ES', art: 'tube',    bg: '#EAF2EE', category: 'med',  reviewed: '2025-12-05' },
  { id: 'edu-nut-001',  title: 'Reading nutrition labels',      desc: 'Servings, calories, sodium, sugar',                 source: 'FDA',         level: '5th grade', format: 'Handout', pages: '4 pgs',  langs: 'EN/ES', art: 'salad',   bg: '#EFEAE0', category: 'nut',  reviewed: '2025-08-28' },
  { id: 'edu-lab-001',  title: 'Understanding your lipid panel',desc: 'LDL, HDL, triglycerides — what to watch',           source: 'AHA',         level: '6th grade', format: 'Handout', pages: '4 pgs',  langs: 'EN/ES', art: 'tube',    bg: '#EAF2EE', category: 'lab',  reviewed: '2025-09-18' },
  { id: 'edu-proc-001', title: 'Preparing for a colonoscopy',   desc: 'Diet, prep, day-of instructions',                   source: 'MedlinePlus', level: '6th grade', format: 'Handout', pages: '4 pgs',  langs: 'EN/ES', art: 'book',    bg: '#E8EEF5', category: 'proc', reviewed: '2025-07-02' },
];

const CATEGORIES: readonly CategoryDef[] = [
  { key: 'all',  name: 'All conditions',    icon: '☰'    },
  { key: 'diab', name: 'Diabetes',          icon: '\u{1F356}' },
  { key: 'card', name: 'Cardiovascular',    icon: '♥'    },
  { key: 'resp', name: 'Respiratory',       icon: '\u{1F389}' },
  { key: 'mind', name: 'Mental health',     icon: '☀'    },
  { key: 'bone', name: 'Musculoskeletal',   icon: '\u{1F3CB}' },
  { key: 'skin', name: 'Skin & wound',      icon: '\u{1F489}' },
  { key: 'eye',  name: 'Vision',            icon: '\u{1F441}' },
  { key: 'kid',  name: 'Pediatric',         icon: '\u{1F476}' },
  { key: 'fem',  name: 'Womens health',     icon: '\u{1F469}' },
  { key: 'med',  name: 'Medication guides', icon: '\u{1F48A}' },
  { key: 'nut',  name: 'Nutrition / diet',  icon: '\u{1F347}' },
  { key: 'lab',  name: 'Lab understanding', icon: '\u{1F52C}' },
  { key: 'proc', name: 'Procedure prep',    icon: '\u{1F4CB}' },
];

// Demo patient identity (replaces the PHP-side `patient_data` lookup) —
// matches the Figma's selected patient banner copy.
const PATIENT_NAME = 'Margaret Chen';
const SUGGESTION_DETAIL = 'E11.9 (T2DM) and recent A1C of 7.9%';

// ── Component ────────────────────────────────────────────────────────

type EducationProps = {
  readonly boot: BootContext;
};

export function Education(_props: EducationProps): JSX.Element {
  // The Figma capture of Screen 48 shows Diabetes selected, the search box
  // empty, level "5th grade", format "Handout", source "MedlinePlus", and
  // 3 of the visible cards checked. We mirror that initial state so the
  // first paint matches the design exactly.
  const [cat, setCat] = useState<CategoryKey>('diab');
  const [q, setQ] = useState<string>('');
  const [level, setLevel] = useState<string>('');
  const [format, setFormat] = useState<string>('');
  const [source, setSource] = useState<string>('');
  const [selected, setSelected] = useState<ReadonlySet<string>>(
    () => new Set<string>(['edu-diab-001', 'edu-diab-002', 'edu-diab-003']),
  );

  const allSources = useMemo<readonly string[]>(() => {
    const seen = new Set<string>();
    for (const item of CATALOG) {
      seen.add(item.source);
    }
    return Array.from(seen).sort();
  }, []);

  const counts = useMemo<ReadonlyMap<CategoryKey, number>>(() => {
    const m = new Map<CategoryKey, number>();
    m.set('all', CATALOG.length);
    for (const def of CATEGORIES) {
      if (def.key === 'all') continue;
      m.set(def.key, CATALOG.filter((r) => r.category === def.key).length);
    }
    return m;
  }, []);

  const filtered = useMemo<readonly CatalogItem[]>(() => {
    const needle = q.trim().toLowerCase();
    return CATALOG.filter((row) => {
      if (cat !== 'all' && row.category !== cat) return false;
      if (level !== '' && row.level !== level) return false;
      if (format !== '' && row.format !== format) return false;
      if (source !== '' && row.source !== source) return false;
      if (needle !== '') {
        const hay = `${row.title} ${row.desc} ${row.source} ${row.category}`.toLowerCase();
        if (!hay.includes(needle)) return false;
      }
      return true;
    });
  }, [cat, q, level, format, source]);

  const cards = filtered.slice(0, 8);
  const selectedCount = selected.size;

  const toggleSelected = (id: string): void => {
    setSelected((prev) => {
      const next = new Set(prev);
      if (next.has(id)) {
        next.delete(id);
      } else {
        next.add(id);
      }
      return next;
    });
  };

  return (
    <>
      <header className={styles.pagehead}>
        <div className={styles.headInfo}>
          <span className={styles.titleSm}>Patient Education</span>
          <span className={styles.star}>&#10022;</span>
          <span className={styles.metaL}>Find handouts to share via portal or print</span>
        </div>
        <div className={styles.headRight}>
          <span className={styles.langPill}>
            <span className={styles.globe}>&#127760;</span>
            English
            <span className={styles.caret}>&#9660;</span>
          </span>
          <button type="button" className={styles.shareBtn} disabled={selectedCount === 0}>
            {selectedCount > 0
              ? `Share ${selectedCount} selected via portal`
              : 'Share selected via portal'}
          </button>
          <button type="button" className={styles.helpLink}>? Help</button>
        </div>
      </header>

      <div className={styles.shell}>
        <aside className={styles.rail}>
          <div className={styles.railLabel}>CATEGORIES</div>
          {CATEGORIES.map((def) => {
            const active = def.key === cat;
            const ct = counts.get(def.key) ?? 0;
            const cls = active ? `${styles.catBtn} ${styles.catBtnActive}` : styles.catBtn;
            return (
              <button
                key={def.key}
                type="button"
                className={cls}
                onClick={() => setCat(def.key)}
              >
                <span className={styles.catIc}>{def.icon}</span>
                <span className={styles.catName}>{def.name}</span>
                <span className={styles.catCt}>{ct}</span>
              </button>
            );
          })}
        </aside>

        <main className={styles.main}>
          <div className={styles.filters}>
            <div className={styles.search}>
              <span className={styles.searchIc}>&#128269;</span>
              <input
                type="text"
                value={q}
                onChange={(e) => setQ(e.target.value)}
                placeholder="Search by topic, condition, or keyword..."
              />
            </div>
            <SelectField
              label="Reading level"
              value={level}
              options={['5th grade', '6th grade', '7th grade']}
              onChange={setLevel}
            />
            <SelectField
              label="Format"
              value={format}
              options={['Handout', 'Video', 'Web page']}
              onChange={setFormat}
            />
            <SelectField
              label="Source"
              value={source}
              options={allSources}
              onChange={setSource}
            />
          </div>

          <div className={styles.suggest}>
            <span className={styles.suggestStar}>&#10022;</span>
            <span className={styles.suggestTxt}>
              Suggested for <strong>{PATIENT_NAME}</strong>
              {' — based on '}
              {SUGGESTION_DETAIL}
            </span>
            <button type="button" className={styles.showAll} onClick={() => { setCat('diab'); setQ(''); }}>
              Show all &rarr;
            </button>
          </div>

          <div className={styles.grid}>
            {cards.length === 0 && (
              <div className={styles.empty}>No handouts match those filters.</div>
            )}
            {cards.map((card) => {
              const checked = selected.has(card.id);
              return (
                <article key={card.id} className={styles.card}>
                  <div className={styles.art} style={{ background: card.bg }}>
                    <ArtGlyph kind={card.art} />
                    <button
                      type="button"
                      className={checked ? `${styles.check} ${styles.checkOn}` : styles.check}
                      aria-label={checked ? 'Deselect handout' : 'Select handout'}
                      aria-pressed={checked}
                      onClick={() => toggleSelected(card.id)}
                    >
                      {checked ? '✓' : ''}
                    </button>
                  </div>
                  <div className={styles.body}>
                    <div className={styles.ttl}>{card.title}</div>
                    <div className={styles.desc}>{card.desc}</div>
                    <div className={styles.src}>{card.source}</div>
                    <div className={styles.meta}>
                      {card.level} &middot; {card.pages} &middot; {card.langs} &middot; reviewed {formatReviewed(card.reviewed)}
                    </div>
                    <div className={styles.actions}>
                      <button type="button" className={styles.actBtn}>&#128424; Print</button>
                      <button type="button" className={styles.actBtn}>&#10150; Portal</button>
                      <button type="button" className={`${styles.actBtn} ${styles.actBtnPreview}`}>Preview &rarr;</button>
                    </div>
                  </div>
                </article>
              );
            })}
          </div>
        </main>
      </div>
    </>
  );
}

// ── Subcomponents ────────────────────────────────────────────────────

type SelectFieldProps = {
  readonly label: string;
  readonly value: string;
  readonly options: readonly string[];
  readonly onChange: (value: string) => void;
};

function SelectField({ label, value, options, onChange }: SelectFieldProps): JSX.Element {
  return (
    <label className={styles.sel}>
      <span className={styles.selLabel}>{label}</span>
      <span className={styles.selVal}>
        {value !== '' ? value : 'Any'}
        <span className={styles.caret}>&#9660;</span>
      </span>
      <select value={value} onChange={(e) => onChange(e.target.value)}>
        <option value="">Any</option>
        {options.map((opt) => (
          <option key={opt} value={opt}>{opt}</option>
        ))}
      </select>
    </label>
  );
}

type ArtGlyphProps = {
  readonly kind: ArtKey;
};

function ArtGlyph({ kind }: ArtGlyphProps): JSX.Element {
  switch (kind) {
    case 'book':
      return (
        <svg width="68" height="58" viewBox="0 0 68 58" fill="none" aria-hidden="true">
          <path d="M6 8 Q6 4 10 4 L32 4 Q34 4 34 8 L34 50 Q34 54 30 54 L10 54 Q6 54 6 50 Z" fill="#5B7FA8" stroke="#3F5A7F" strokeWidth="1.5" />
          <path d="M34 8 Q34 4 38 4 L58 4 Q62 4 62 8 L62 50 Q62 54 58 54 L38 54 Q34 54 34 50 Z" fill="#7AA0CC" stroke="#3F5A7F" strokeWidth="1.5" />
          <line x1="14" y1="16" x2="28" y2="16" stroke="#FFFFFF" strokeWidth="1" />
          <line x1="14" y1="22" x2="28" y2="22" stroke="#FFFFFF" strokeWidth="1" />
          <line x1="14" y1="28" x2="26" y2="28" stroke="#FFFFFF" strokeWidth="1" />
          <line x1="40" y1="16" x2="56" y2="16" stroke="#FFFFFF" strokeWidth="1" />
          <line x1="40" y1="22" x2="56" y2="22" stroke="#FFFFFF" strokeWidth="1" />
          <line x1="40" y1="28" x2="54" y2="28" stroke="#FFFFFF" strokeWidth="1" />
        </svg>
      );
    case 'tube':
      return (
        <svg width="40" height="68" viewBox="0 0 40 68" fill="none" aria-hidden="true">
          <rect x="13" y="4" width="14" height="56" rx="7" fill="#FFFFFF" stroke="#3F8C5F" strokeWidth="2" />
          <rect x="13" y="34" width="14" height="26" rx="7" fill="#5FBF7F" />
          <line x1="9" y1="4" x2="31" y2="4" stroke="#3F8C5F" strokeWidth="3" strokeLinecap="round" />
        </svg>
      );
    case 'salad':
      return (
        <svg width="68" height="56" viewBox="0 0 68 56" fill="none" aria-hidden="true">
          <path d="M4 28 Q4 50 34 50 Q64 50 64 28 Z" fill="#D9D9D9" stroke="#9AA0AB" strokeWidth="1.5" />
          <circle cx="20" cy="32" r="7" fill="#D94545" />
          <circle cx="34" cy="26" r="8" fill="#5FBF55" />
          <circle cx="46" cy="32" r="6" fill="#D94545" />
          <circle cx="28" cy="36" r="5" fill="#FFB347" />
          <circle cx="42" cy="38" r="4" fill="#5FBF55" />
        </svg>
      );
    case 'foot':
      return (
        <svg width="46" height="68" viewBox="0 0 46 68" fill="none" aria-hidden="true">
          <path d="M14 44 Q10 50 12 58 Q14 64 22 64 Q32 64 34 56 Q36 48 32 42 Q28 36 30 28 Q32 18 24 14 Q14 12 12 22 Q10 32 14 44 Z" fill="#E8B89A" stroke="#A87655" strokeWidth="1.5" />
          <circle cx="14" cy="10" r="3" fill="#E8B89A" stroke="#A87655" strokeWidth="1" />
          <circle cx="20" cy="6"  r="3" fill="#E8B89A" stroke="#A87655" strokeWidth="1" />
          <circle cx="26" cy="6"  r="3" fill="#E8B89A" stroke="#A87655" strokeWidth="1" />
          <circle cx="32" cy="8"  r="3" fill="#E8B89A" stroke="#A87655" strokeWidth="1" />
          <circle cx="36" cy="14" r="3" fill="#E8B89A" stroke="#A87655" strokeWidth="1" />
        </svg>
      );
    case 'syringe':
      return (
        <svg width="80" height="50" viewBox="0 0 80 50" fill="none" aria-hidden="true">
          <line x1="2" y1="25" x2="14" y2="25" stroke="#9AA0AB" strokeWidth="2" />
          <rect x="14" y="18" width="6"  height="14" fill="#C7CBD2" stroke="#6F7785" strokeWidth="1" />
          <rect x="20" y="14" width="40" height="22" rx="2" fill="#FFFFFF" stroke="#6F7785" strokeWidth="1.5" />
          <rect x="20" y="14" width="20" height="22" fill="#A8C8E8" />
          <rect x="60" y="20" width="6"  height="10" fill="#C7CBD2" stroke="#6F7785" strokeWidth="1" />
          <rect x="66" y="14" width="4"  height="22" fill="#C7CBD2" stroke="#6F7785" strokeWidth="1" />
          <rect x="70" y="22" width="8"  height="6"  fill="#C7CBD2" stroke="#6F7785" strokeWidth="1" />
        </svg>
      );
    case 'warn':
      return (
        <svg width="68" height="58" viewBox="0 0 68 58" fill="none" aria-hidden="true">
          <path d="M34 4 L64 54 L4 54 Z" fill="#F0C674" stroke="#A87E33" strokeWidth="2" strokeLinejoin="round" />
          <rect x="32" y="20" width="4" height="18" fill="#5C4422" />
          <circle cx="34" cy="46" r="2.5" fill="#5C4422" />
        </svg>
      );
    case 'phone':
      return (
        <svg width="42" height="68" viewBox="0 0 42 68" fill="none" aria-hidden="true">
          <rect x="6" y="4" width="30" height="60" rx="5" fill="#1F2937" stroke="#0D1B2A" strokeWidth="1.5" />
          <rect x="9" y="9" width="24" height="44" rx="2" fill="#3A4756" />
          <circle cx="21" cy="58" r="2" fill="#6F7785" />
          <line x1="13" y1="20" x2="29" y2="20" stroke="#6F8FA8" strokeWidth="1" />
          <polyline points="13,32 17,28 21,34 25,24 29,30" fill="none" stroke="#5FBF7F" strokeWidth="1.5" />
          <line x1="13" y1="44" x2="29" y2="44" stroke="#6F8FA8" strokeWidth="1" />
        </svg>
      );
    case 'eyeball':
      return (
        <svg width="72" height="50" viewBox="0 0 72 50" fill="none" aria-hidden="true">
          <ellipse cx="36" cy="25" rx="32" ry="20" fill="#FFFFFF" stroke="#6F7785" strokeWidth="1.5" />
          <circle cx="36" cy="25" r="14" fill="#6B5436" />
          <circle cx="36" cy="25" r="6"  fill="#1F1A12" />
          <circle cx="32" cy="21" r="2"  fill="#FFFFFF" />
        </svg>
      );
  }
}

// "2025-09-12" -> "09/2025"  (matches the PHP date('m/Y', strtotime(...)))
function formatReviewed(iso: string): string {
  const parts = iso.split('-');
  const year = parts[0] ?? '';
  const month = parts[1] ?? '';
  return `${month}/${year}`;
}
