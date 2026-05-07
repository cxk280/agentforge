// Messages — Figma "Screen 7". Two-pane inbox + message detail, port of the
// PHP-rendered mock at /interface/main/messages/copilot_messages.php.
//
// Selection state: PHP version used ?selected=N URL params; the React port
// keeps it in component state, which avoids a full iframe reload on each
// row click and matches the Figma's instant-feeling interaction.
//
// Demo data is preserved verbatim from the PHP version so the existing
// review/screenshot workflow stays valid.

import { useState } from 'react';
import type { BootContext } from '../../shared/lib/bootContext';
import styles from './Messages.module.css';

type Filter = 'All' | 'Inbox' | 'Sent' | 'Recalls';

type Finding = readonly [emoji: string, title: string, subtitle: string];

type Message = {
  readonly sender: string;
  readonly senderFull: string;
  readonly time: string;
  readonly dateLabel: string;
  readonly to: string;
  readonly subject: string;
  readonly preview: string;
  readonly unread: boolean;
  readonly urgent: boolean;
  readonly lead: string;
  readonly findings: readonly Finding[];
  readonly closer: string;
};

const MESSAGES: readonly Message[] = [
  {
    sender: 'Dr. Sarah Chen',
    senderFull: 'Dr. Sarah Chen — Endocrinology',
    time: '9:14 AM',
    dateLabel: 'Today 9:14 AM',
    to: 'Dr. E. Rivera',
    subject: 'Re: Margaret Chen lab results',
    preview: "I've reviewed the A1C trend and would like to discuss…",
    unread: true,
    urgent: false,
    lead: "Hi Eduardo,\n\nI've reviewed Margaret Chen's recent lab results from 04/12/2026 and the trend over the past 18 months. A few observations:",
    findings: [
      ['📈', 'A1C climbed from 7.2% to 7.9%',  'above her 7.0% target'],
      ['💊', 'Metformin still 1000 mg BID',    'may benefit from adding a GLP-1'],
      ['⚠',  'Microalbumin slightly elevated', 'monitor renal function'],
    ],
    closer: "I'm happy to discuss further or join the next visit if helpful. Let me know what works best.\n\n— Sarah",
  },
  {
    sender: 'Lab — LabCorp',
    senderFull: 'LabCorp Houston Lab',
    time: '8:42 AM',
    dateLabel: 'Today 8:42 AM',
    to: 'Dr. E. Rivera',
    subject: 'Lab results available',
    preview: 'Comprehensive metabolic panel and CBC for patient #004821…',
    unread: true,
    urgent: false,
    lead: "Dr. Rivera,\n\nLab results are available for the following order set on patient #004821 (Margaret Chen). Drawn 04/12/2026, processed at our Houston facility.",
    findings: [
      ['🧪', 'CMP — within range',       'glucose 142 mg/dL flagged high (fasting)'],
      ['🩸', 'CBC — within range',       'Hgb 13.4 g/dL, WBC 7.2'],
      ['📎', 'Microalbumin — elevated',  '32 mg/g (ref < 30 mg/g) — repeat in 90 days'],
    ],
    closer: "Full report attached to the patient chart under Documents → Lab Reports.\n\n— LabCorp Reporting",
  },
  {
    sender: 'Pharmacy — CVS',
    senderFull: 'CVS Pharmacy #4521',
    time: 'Yesterday',
    dateLabel: 'Yesterday 4:12 PM',
    to: 'Dr. E. Rivera',
    subject: 'Refill request: Ted Shaw',
    preview: 'Patient is requesting refill of Lisinopril 20mg, last filled…',
    unread: true,
    urgent: true,
    lead: "Refill request from Ted Shaw (DOB 1947-03-11). Patient is on the following Rx and is requesting one 90-day refill.",
    findings: [
      ['💊', 'Lisinopril 20 mg tablet, 1 daily',  'last filled 02/14/2026, 0 refills remaining'],
      ['📞', 'Patient called pharmacy 3:47 PM',     'reports BP at home running 132/84'],
      ['🪪', 'Insurance copay $4 (BCBS)',           'no PA required for refill'],
    ],
    closer: "Please approve via e-Rx or reply with a written script. We can hold for pickup once authorized.\n\n— CVS #4521 (512-555-0142)",
  },
  {
    sender: 'Patient Portal',
    senderFull: 'Patient Portal — Margaret Chen',
    time: 'Yesterday',
    dateLabel: 'Yesterday 11:08 AM',
    to: 'Dr. E. Rivera',
    subject: 'Question about medication',
    preview: 'Hi Dr. Rivera, I noticed my new prescription bottle says…',
    unread: false,
    urgent: false,
    lead: "Hi Dr. Rivera,\n\nI noticed my new prescription bottle says 1000 mg, but I thought we discussed lowering my dose. Can you confirm what I should be taking and whether the new strength is right?",
    findings: [
      ['💊', 'Patient: Margaret Chen',           'MRN #004821 • DOB 03/14/1958'],
      ['📋', 'Active Rx: Metformin 1000 mg BID', 'last refill 04/01/2026 (90 days)'],
      ['📅', 'Last visit: 02/18/2026',           'titration plan documented'],
    ],
    closer: "I want to make sure I'm not making a mistake — should I keep taking the new bottle or wait?\n\nThank you,\nMargaret",
  },
  {
    sender: 'Front Desk',
    senderFull: 'Maria Gonzalez — Front Desk',
    time: 'Mon',
    dateLabel: 'Mon 2:34 PM',
    to: 'Dr. E. Rivera',
    subject: 'Schedule update — Wed afternoon',
    preview: 'Two slots opened up Wednesday afternoon, would you like…',
    unread: false,
    urgent: false,
    lead: "Hi Dr. Rivera,\n\nTwo slots opened up Wednesday afternoon (1:30 PM and 2:00 PM). Would you like me to fill them from the wait list or hold them for same-day add-ons?",
    findings: [
      ['📅', 'Wed 1:30 PM open',          '30 min slot'],
      ['📅', 'Wed 2:00 PM open',          '30 min slot'],
      ['📋', '4 patients on the wait list', 'top: Helen Garcia, follow-up'],
    ],
    closer: "Let me know how you'd like to handle these.\n\n— Maria",
  },
  {
    sender: 'Dr. Patel',
    senderFull: 'Dr. Rajesh Patel — Orthopedics',
    time: 'Mon',
    dateLabel: 'Mon 10:21 AM',
    to: 'Dr. E. Rivera',
    subject: 'Referral feedback for J. Wong',
    preview: 'Saw your referral; orthopedic consult complete with…',
    unread: false,
    urgent: false,
    lead: "Eduardo,\n\nSaw your referral for James Wong (R knee pain). Consult complete; full report posted to the chart. Quick summary below.",
    findings: [
      ['🦴', 'MRI: small medial meniscus tear',  'no surgical indication at this time'],
      ['💊', 'Recommend NSAID + PT',              '6-week trial before re-evaluation'],
      ['📅', 'Follow-up scheduled',                '06/14/2026 in our clinic'],
    ],
    closer: "Happy to discuss anytime if you want to talk through the imaging.\n\n— Raj",
  },
];

const FILTERS: readonly Filter[] = ['All', 'Inbox', 'Sent', 'Recalls'];

type MessagesProps = {
  readonly boot: BootContext;
};

export function Messages(_props: MessagesProps): JSX.Element {
  const [selectedIdx, setSelectedIdx] = useState<number>(0);
  const [filter, setFilter] = useState<Filter>('All');

  const selected = MESSAGES[selectedIdx] ?? MESSAGES[0]!;

  return (
    <>
      <header className={styles.header}>
        <div className={styles.titleBlock}>
          <div className={styles.title}>Messages</div>
          <span className={styles.newBadge}>12 new</span>
        </div>
        <div className={styles.spacer} />
        <div className={styles.filters} role="tablist">
          {FILTERS.map((f) => (
            <button
              key={f}
              type="button"
              role="tab"
              aria-selected={filter === f}
              onClick={() => setFilter(f)}
              className={
                filter === f
                  ? `${styles.filterItem} ${styles.filterItemActive}`
                  : styles.filterItem
              }
            >
              {f}
            </button>
          ))}
        </div>
        <button type="button" className={styles.compose}>
          <span className={styles.composeIcon}>✎</span>
          <span>Compose</span>
        </button>
      </header>

      <div className={styles.body}>
        <nav className={styles.inbox} aria-label="Inbox">
          {MESSAGES.map((m, i) => {
            const isSelected = i === selectedIdx;
            const itemClass = isSelected
              ? `${styles.item} ${styles.itemSelected}`
              : styles.item;
            return (
              <button
                key={`${m.sender}-${m.subject}`}
                type="button"
                className={itemClass}
                onClick={() => setSelectedIdx(i)}
                aria-current={isSelected ? 'true' : undefined}
              >
                <div className={styles.itemRow}>
                  {m.unread
                    ? <span className={styles.itemDot} aria-label="Unread" />
                    : <span className={styles.itemDotPlaceholder} aria-hidden="true" />
                  }
                  <span
                    className={
                      m.unread
                        ? `${styles.itemSender} ${styles.itemSenderUnread}`
                        : styles.itemSender
                    }
                  >
                    {m.sender}
                  </span>
                  <span className={styles.itemTime}>{m.time}</span>
                </div>
                <div
                  className={
                    m.unread
                      ? `${styles.itemSubject} ${styles.itemSubjectUnread}`
                      : styles.itemSubject
                  }
                >
                  {m.subject}
                </div>
                <div className={styles.itemPreviewRow}>
                  {m.urgent && <span className={styles.urgentBadge}>URGENT</span>}
                  <span className={styles.itemPreview}>{m.preview}</span>
                </div>
              </button>
            );
          })}
        </nav>

        <section className={styles.detail} aria-label="Message detail">
          <div className={styles.detailHeader}>
            <h2 className={styles.detailSubject}>{selected.subject}</h2>
            <div className={styles.detailFromRow}>
              <div className={styles.detailAvatar} aria-hidden="true" />
              <div className={styles.detailFromText}>
                <div className={styles.detailFromName}>{selected.senderFull}</div>
                <div className={styles.detailFromMeta}>
                  To: {selected.to} • {selected.dateLabel}
                </div>
              </div>
            </div>
          </div>

          <div className={styles.detailBody}>
            <p className={styles.detailPara}>{selected.lead}</p>
            {selected.findings.map((f, i) => (
              <div key={i} className={styles.findingCard}>
                <span className={styles.findingEmoji}>{f[0]}</span>
                <div className={styles.findingText}>
                  <span className={styles.findingTitle}>{f[1]}</span>
                  <span className={styles.findingSubtitle}>{f[2]}</span>
                </div>
              </div>
            ))}
            <p className={styles.detailPara}>{selected.closer}</p>
          </div>

          <div className={styles.actions}>
            <button type="button" className={styles.actionPrimary}>↩ Reply</button>
            <button type="button" className={styles.actionGhost}>↪ Forward</button>
            <button type="button" className={`${styles.actionGhost} ${styles.actionGhostMuted}`}>
              Archive
            </button>
            <button type="button" className={styles.actionLink}>
              Open in patient chart →
            </button>
          </div>
        </section>
      </div>
    </>
  );
}

