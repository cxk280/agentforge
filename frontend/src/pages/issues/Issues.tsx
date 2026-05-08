// Issues — Figma "Screen 17 — Issues" (file kj4MWNr8mpjZ2wVg1PbS0F, node 38:2).
//
// Patient-context Issues page. The PHP wrapper at copilot_issues.php queries
// the lists table (medical_problem + allergy rows for the current pid) and
// JSON-encodes the result onto data-issues; the entry index.tsx parses it
// and hands it here as the `issues` prop. Status pills (Active / Inactive /
// Resolved / All) and group rendering are local React state.
//
// The PHP outer shell at /interface/main/tabs/main.php still owns the navy
// top nav and patient header2 banner; this file renders inside the
// #maimain iframe and skips those chrome elements.

import { useMemo, useState } from 'react';
import type { BootContext } from '../../shared/lib/bootContext';
import styles from './Issues.module.css';

type StatusTab = 'active' | 'inactive' | 'resolved' | 'all';

type SeverityTone = 'warn' | 'good';

// Server-side issue row shape (mirrors the PHP query in copilot_issues.php).
export type IssueRow = {
  readonly icd: string;
  readonly title: string;
  readonly sub: string;
  readonly sev: string;
  readonly sevTone: SeverityTone;
};

// Server-side payload — two parallel arrays, one per group. The PHP wrapper
// emits this shape on data-issues; the React side groups them into cards.
export type IssuesPayload = {
  readonly problems: readonly IssueRow[];
  readonly allergies: readonly IssueRow[];
};

type IssueGroup = {
  readonly title: string;
  readonly count: number;
  readonly dotTone: 'warn' | 'danger' | 'good';
  readonly rows: readonly IssueRow[];
};

const STATUS_TABS: ReadonlyArray<{ readonly id: StatusTab; readonly label: string }> = [
  { id: 'active',   label: 'Active'   },
  { id: 'inactive', label: 'Inactive' },
  { id: 'resolved', label: 'Resolved' },
  { id: 'all',      label: 'All'      },
];

type IssuesProps = {
  readonly boot: BootContext;
  readonly issues: IssuesPayload;
};

export function Issues({ issues }: IssuesProps): JSX.Element {
  const [status, setStatus] = useState<StatusTab>('active');

  // Build the visible group list from the server payload. The .bak page only
  // surfaced active rows (enddate IS NULL), so under the 'active' / 'all'
  // tabs we render whatever the server sent. Inactive / Resolved aren't
  // populated server-side yet, so those tabs show empty groups — matching
  // the .bak fallback ("No active issues") when nothing is present.
  const groups = useMemo<readonly IssueGroup[]>(() => {
    const showActiveData = status === 'active' || status === 'all';
    const problems  = showActiveData ? issues.problems  : [];
    const allergies = showActiveData ? issues.allergies : [];
    const out: IssueGroup[] = [];
    if (problems.length > 0) {
      out.push({
        title: 'Active — Medical Problems',
        count: problems.length,
        dotTone: 'warn',
        rows: problems,
      });
    }
    if (allergies.length > 0) {
      out.push({
        title: 'Active — Allergies & Risk Factors',
        count: allergies.length,
        dotTone: 'danger',
        rows: allergies,
      });
    }
    if (out.length === 0) {
      out.push({
        title: 'No active issues',
        count: 0,
        dotTone: 'good',
        rows: [],
      });
    }
    return out;
  }, [issues, status]);

  const activeCount = issues.problems.length + issues.allergies.length;
  const summaryLabel = `Active ${activeCount} • Inactive 0 • Resolved 0`;

  return (
    <>
      <header className={styles.head}>
        <div className={styles.title}>Issues</div>
        <div className={styles.bullet}>•</div>
        <div className={styles.meta}>{summaryLabel}</div>
        <div className={styles.spacer} />
        <div className={styles.seg} role="tablist">
          {STATUS_TABS.map((t) => {
            const active = t.id === status;
            const cls = active ? `${styles.opt} ${styles.optActive}` : styles.opt;
            return (
              <button
                key={t.id}
                type="button"
                role="tab"
                aria-selected={active}
                className={cls}
                onClick={() => setStatus(t.id)}
              >
                {t.label}
              </button>
            );
          })}
        </div>
        <button type="button" className={styles.addBtn}>
          <span className={styles.plus}>+</span>
          <span>Add Issue</span>
        </button>
      </header>

      <main className={styles.body}>
        {groups.map((grp) => (
          <section key={grp.title} className={styles.grpCard}>
            <header className={styles.grpHead}>
              <span className={`${styles.dot} ${dotClass(grp.dotTone)}`} />
              <span className={styles.grpTitle}>{grp.title}</span>
              <span className={styles.grpCount}>{grp.count}</span>
            </header>
            {grp.rows.map((r, i) => {
              const isLast = i === grp.rows.length - 1;
              const rowCls = isLast ? `${styles.row} ${styles.rowLast}` : styles.row;
              return (
                <div key={`${r.icd}-${r.title}-${i}`} className={rowCls}>
                  <span className={styles.icd}>{r.icd}</span>
                  <div className={styles.rowInfo}>
                    <div className={styles.rowTitle}>{r.title}</div>
                    <div className={styles.rowSub}>{r.sub}</div>
                  </div>
                  <span className={styles.rowSpacer} />
                  <span className={`${styles.sevPill} ${sevClass(r.sevTone)}`}>{r.sev}</span>
                  <span className={styles.kebab} aria-hidden="true">⋯</span>
                </div>
              );
            })}
          </section>
        ))}
      </main>
    </>
  );
}

function dotClass(tone: 'warn' | 'danger' | 'good'): string {
  if (tone === 'warn') {
    return styles.dotWarn ?? '';
  }
  if (tone === 'danger') {
    return styles.dotDanger ?? '';
  }
  return styles.dotGood ?? '';
}

function sevClass(tone: SeverityTone): string {
  if (tone === 'warn') {
    return styles.sevWarn ?? '';
  }
  return styles.sevGood ?? '';
}
