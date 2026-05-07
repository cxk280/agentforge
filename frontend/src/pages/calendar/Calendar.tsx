// Calendar — Figma "Screen 6". Renders the AgentForge month-grid view with a
// page header (current month label, prev/next nav, Today, Day/Week/Month
// toggle, New Appointment).
//
// This is a 1:1 port of the PHP-rendered mock previously at
// /interface/main/calendar/copilot_calendar.php. State is held in React but
// behaves identically to the static PHP version for now: today = Nov 12, 2026,
// month label fixed, view toggle defaults to "Month", events are static demo
// data. Wiring to a real /apis/copilot/calendar/events endpoint is a follow-up
// task captured in the plan file.

import { useState } from 'react';
import type { BootContext } from '../../shared/lib/bootContext';
import styles from './Calendar.module.css';

type ViewMode = 'day' | 'week' | 'month';

type CalendarEvent = {
  readonly day: number;
  readonly color: string;
  readonly label: string;
};

const DAY_LABELS: readonly string[] = ['SUN', 'MON', 'TUE', 'WED', 'THU', 'FRI', 'SAT'];

// Same demo data the PHP mock shipped — November 2026, today is the 12th.
const TODAY_DATE = 12;
const DAYS_IN_MONTH = 30;
const START_COL = 0; // Nov 1, 2026 falls on a Sunday
const TOTAL_CELLS = 35;
const MONTH_LABEL = 'November 2026';

const EVENTS: readonly CalendarEvent[] = [
  { day: 4,  color: '#5FD0D0', label: '9:00 Smith' },
  { day: 10, color: '#5FD0D0', label: '9:00 Chen' },
  { day: 11, color: '#D93838', label: '9:00 Annual' },
  { day: 13, color: '#5FD0D0', label: '9:00 Lopez' },
  { day: 14, color: '#008C8C', label: '9:00 Patel' },
  { day: 17, color: '#008C8C', label: '9:00 Brown' },
  { day: 18, color: '#D93838', label: '9:00 Urgent' },
  { day: 20, color: '#5FD0D0', label: '9:00 Davis' },
  { day: 22, color: '#008C8C', label: '9:00 Wilson' },
  { day: 24, color: '#FA8C33', label: '9:00 Reviews' },
  { day: 26, color: '#5FD0D0', label: '9:00 Miller' },
  { day: 28, color: '#008C8C', label: '9:00 Garcia' },
  { day: 30, color: '#5FD0D0', label: '9:00 Wong' },
];

const EVENTS_BY_DAY: ReadonlyMap<number, CalendarEvent> = new Map(
  EVENTS.map((e) => [e.day, e]),
);

type CalendarProps = {
  readonly boot: BootContext;
};

export function Calendar(_props: CalendarProps): JSX.Element {
  const [viewMode, setViewMode] = useState<ViewMode>('month');

  return (
    <>
      <header className={styles.header}>
        <div className={styles.titleBlock}>
          <div className={styles.title}>{MONTH_LABEL}</div>
          <div className={styles.nav}>
            <button className={styles.navBtn} type="button" aria-label="Previous month">‹</button>
            <button className={styles.navBtn} type="button" aria-label="Next month">›</button>
          </div>
          <button className={styles.today} type="button">Today</button>
        </div>
        <div className={styles.spacer} />
        <div className={styles.view} role="tablist">
          <ViewToggle mode="day"   current={viewMode} onSelect={setViewMode}>Day</ViewToggle>
          <ViewToggle mode="week"  current={viewMode} onSelect={setViewMode}>Week</ViewToggle>
          <ViewToggle mode="month" current={viewMode} onSelect={setViewMode}>Month</ViewToggle>
        </div>
        <button className={styles.new} type="button">
          <span className={styles.newPlus}>+</span>
          <span>New Appointment</span>
        </button>
      </header>

      <div className={styles.wrap}>
        <div className={styles.grid}>
          {DAY_LABELS.map((d) => (
            <div key={d} className={styles.dayLabel}>{d}</div>
          ))}
          {Array.from({ length: TOTAL_CELLS }, (_, i) => {
            const date = i - START_COL + 1;
            const inMonth = date >= 1 && date <= DAYS_IN_MONTH;
            const isToday = inMonth && date === TODAY_DATE;
            const event = inMonth ? EVENTS_BY_DAY.get(date) : undefined;

            const cellClass = isToday
              ? `${styles.cell} ${styles.cellToday}`
              : styles.cell;

            return (
              <div key={i} className={cellClass}>
                {inMonth && (
                  <>
                    <span className={styles.cellDate}>{date}</span>
                    {event && (
                      <div className={styles.event} style={{ backgroundColor: event.color }}>
                        {event.label}
                      </div>
                    )}
                  </>
                )}
              </div>
            );
          })}
        </div>
      </div>
    </>
  );
}

type ViewToggleProps = {
  readonly mode: ViewMode;
  readonly current: ViewMode;
  readonly onSelect: (mode: ViewMode) => void;
  readonly children: React.ReactNode;
};

function ViewToggle({ mode, current, onSelect, children }: ViewToggleProps): JSX.Element {
  const active = mode === current;
  const cls = active
    ? `${styles.viewItem} ${styles.viewItemActive}`
    : styles.viewItem;
  return (
    <button
      className={cls}
      role="tab"
      type="button"
      aria-selected={active}
      onClick={() => onSelect(mode)}
    >
      {children}
    </button>
  );
}
