// Reports — Figma "Screen 8 — Reports". Renders the AgentForge categorised
// report card grid (Clinical / Operations / Financial) replacing the legacy
// Reports dropdown.
//
// This is a 1:1 port of the PHP-rendered mock previously at
// /interface/reports/copilot_reports.php. Cards are static demo data; each
// card links to the corresponding existing OpenEMR report URL so the
// underlying functionality is preserved. Search-reports button is a no-op
// placeholder (matches the Figma + the PHP mock).

import type { BootContext } from '../../shared/lib/bootContext';
import styles from './Reports.module.css';

type ReportCard = {
  readonly icon: string;
  readonly title: string;
  readonly description: string;
  readonly href: string;
};

type ReportSection = {
  readonly label: string;
  readonly color: string;
  readonly iconBg: string;
  readonly cards: readonly ReportCard[];
};

// Verified against Figma node 29:2 (Screen 8 — Reports) on 2026-05-07. Section
// colors come straight from the Figma dot fills; iconBg is the same color at
// 12% alpha (rgba(c, 0.12)) per the card icon backgrounds in the design.
const SECTIONS: readonly ReportSection[] = [
  {
    label: 'CLINICAL',
    color: '#008C8C',
    iconBg: 'rgba(0, 140, 140, 0.12)',
    cards: [
      { icon: '📋', title: 'Patient List',           description: 'Filterable cohort export with demographics + active conditions', href: '/interface/reports/copilot_patient_list.php' },
      { icon: '💊', title: 'Prescription Report',    description: 'All Rx in date range, by provider or drug class',                href: '/interface/reports/copilot_prescription_report.php' },
      { icon: '🧪', title: 'Lab Trends',             description: 'A1C, lipids, BP across patient panel',                           href: '/interface/reports/copilot_lab_trends.php' },
      { icon: '📊', title: 'Quality Measures (CQM)', description: 'Standard + automated measures for MIPS reporting',               href: '/interface/reports/copilot_quality_measures.php' },
      { icon: '💉', title: 'Immunization Registry',  description: 'Due / overdue immunizations by age band',                        href: '/interface/patient_file/history/copilot_immunization_registry.php' },
    ],
  },
  {
    label: 'OPERATIONS',
    color: '#4785D9',
    iconBg: 'rgba(71, 133, 217, 0.12)',
    cards: [
      { icon: '📅', title: 'Daily Summary',      description: "Today's appointments, encounters, no-shows", href: '/interface/reports/daily_summary_report.php' },
      { icon: '✅', title: 'Encounters',         description: 'Encounter counts by provider and visit type', href: '/interface/patient_file/encounter/copilot_visit_history.php' },
      { icon: '🏥', title: 'Patient Flow Board', description: 'Live status across exam rooms',               href: '/interface/main/copilot_patient_tracker.php' },
      { icon: '📈', title: 'Chart Activity',     description: 'Open vs locked notes, signing turnaround',    href: '/custom/copilot_chart_tracker.php' },
    ],
  },
  {
    label: 'FINANCIAL',
    color: '#FA8C33',
    iconBg: 'rgba(250, 140, 51, 0.12)',
    cards: [
      { icon: '💰', title: 'Daily Cash Reconciliation', description: 'Receipts by method, balanced against deposits', href: '/interface/billing/copilot_aging.php' },
      { icon: '📉', title: 'Aging & Collections',       description: '30/60/90/120 buckets per insurer',              href: '/interface/billing/copilot_aging.php' },
      { icon: '🧾', title: 'Patient Ledger',            description: 'All charges and payments for a single patient', href: '/interface/billing/copilot_payment.php' },
      { icon: '📋', title: 'Insurance Distribution',    description: 'Volume and revenue by payer',                   href: '/interface/billing/copilot_aging.php' },
    ],
  },
];

type ReportsProps = {
  readonly boot: BootContext;
};

export function Reports(_props: ReportsProps): JSX.Element {
  return (
    <>
      <header className={styles.header}>
        <div className={styles.title}>Reports</div>
        <div className={styles.spacer} />
        <button className={styles.search} type="button">
          <span aria-hidden="true">🔍</span>
          <span>Search reports</span>
        </button>
      </header>

      <main className={styles.content}>
        {SECTIONS.map((section) => (
          <section key={section.label} className={styles.section}>
            <div className={styles.secHead}>
              <span className={styles.secDot} style={{ backgroundColor: section.color }} />
              <span className={styles.secLabel}>{section.label}</span>
            </div>
            <div className={styles.grid}>
              {section.cards.map((card) => (
                <a key={card.title} className={styles.card} href={card.href} target="_self">
                  <span className={styles.icon} style={{ backgroundColor: section.iconBg }} aria-hidden="true">
                    {card.icon}
                  </span>
                  <div className={styles.iconSpacer} />
                  <div className={styles.cardTitle}>{card.title}</div>
                  <p className={styles.cardDesc}>{card.description}</p>
                </a>
              ))}
            </div>
          </section>
        ))}
      </main>
    </>
  );
}
