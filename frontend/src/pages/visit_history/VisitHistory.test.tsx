// VisitHistory unit tests. Uses Vitest + React Testing Library to verify
// that the component:
//   1. Renders rows from the typed payload (no hardcoded fallback).
//   2. Reflects payload.totalAll in the page header subtitle.
//   3. Filters the visible rows when the search input changes.
//   4. Shows the empty-state row when no rows survive filtering.
//
// The component renders inside the OpenEMR PHP iframe shell in production,
// but here we mount it bare on jsdom — its DOM contract is decoupled from
// the parent shell (no window.parent / navigateTab calls in the body).

import { describe, expect, it } from 'vitest';
import { fireEvent, render, screen } from '@testing-library/react';
import { VisitHistory } from './VisitHistory';
import type { VisitHistoryPayload, VisitRow } from './VisitHistory';
import type { BootContext } from '../../shared/lib/bootContext';

const boot: BootContext = {
  page: 'visit_history',
  csrf: 'test-csrf',
  userId: 1,
  patientId: 8,
  apiBase: '/apis',
};

function makeRow(overrides: Partial<VisitRow>): VisitRow {
  return {
    id: 3005,
    encounter: 3005,
    date: '10/08/2024',
    time: '11:00 AM',
    type: 'Office Visit',
    typeId: 5,
    provider: 'Site Administrator',
    providerId: 1,
    reason: 'Routine follow-up, fatigue, weight gain',
    duration: '15 min',
    status: 'in_progress',
    billed: false,
    ...overrides,
  };
}

const payload: VisitHistoryPayload = {
  rows: [
    makeRow({}),
    makeRow({
      id: 3004, encounter: 3004,
      date: '06/19/2024', time: '2:30 PM',
      reason: 'Bone density results review',
    }),
    makeRow({
      id: 3003, encounter: 3003,
      date: '02/14/2024', time: '10:00 AM',
      reason: 'Depression follow-up, TSH recheck',
    }),
  ],
  totalAll: 5,
  visitTypeOpts: [{ id: 5, name: 'Office Visit' }],
  providerOpts: [{ id: 1, name: 'Site Administrator' }],
};

describe('VisitHistory', () => {
  it('renders one table row per payload entry', () => {
    render(<VisitHistory boot={boot} payload={payload} />);

    expect(screen.getByText('10/08/2024')).toBeInTheDocument();
    expect(screen.getByText('Routine follow-up, fatigue, weight gain'))
      .toBeInTheDocument();
    expect(screen.getByText('Bone density results review'))
      .toBeInTheDocument();
    expect(screen.getByText('Depression follow-up, TSH recheck'))
      .toBeInTheDocument();
  });

  it('shows payload.totalAll in the header subtitle', () => {
    render(<VisitHistory boot={boot} payload={payload} />);
    // Header reads "5 encounters · All time" by default.
    expect(screen.getByText(/5 encounters/)).toBeInTheDocument();
  });

  it('renders an Open link with eid pointing at the encounter id', () => {
    render(<VisitHistory boot={boot} payload={payload} />);
    const openLinks = screen.getAllByText('Open', { exact: false });
    expect(openLinks.length).toBe(payload.rows.length);
    // First row's link should carry eid=3005 — Visit History → Encounter
    // Detail flows the encounter id explicitly.
    const firstLink = openLinks[0]?.closest('a');
    expect(firstLink?.getAttribute('href')).toContain('eid=3005');
  });

  it('filters rows by the search input (substring match on reason)', () => {
    render(<VisitHistory boot={boot} payload={payload} />);
    const search = screen.getByPlaceholderText(/Search by reason/i);

    fireEvent.change(search, { target: { value: 'depression' } });

    expect(screen.queryByText('Routine follow-up, fatigue, weight gain'))
      .not.toBeInTheDocument();
    expect(screen.queryByText('Bone density results review'))
      .not.toBeInTheDocument();
    expect(screen.getByText('Depression follow-up, TSH recheck'))
      .toBeInTheDocument();
  });

  it('shows the empty-state row when no rows match the search', () => {
    render(<VisitHistory boot={boot} payload={payload} />);
    const search = screen.getByPlaceholderText(/Search by reason/i);

    fireEvent.change(search, { target: { value: 'xyzzy-no-match' } });

    expect(screen.getByText(/No encounters match/i)).toBeInTheDocument();
  });

  it('renders empty-state with payload.rows = [] and no demo fallback', () => {
    const empty: VisitHistoryPayload = {
      rows: [], totalAll: 0, visitTypeOpts: [], providerOpts: [],
    };
    render(<VisitHistory boot={boot} payload={empty} />);

    // Critical: must NOT mask empty data with the prior hardcoded VISITS
    // demo (Margaret Chen / Dr. Rivera / Annual Physical etc.) — the
    // earlier batch fixed Ledger for this exact mistake. Confirm none of
    // those demo strings leak through.
    expect(screen.queryByText(/Margaret Chen/)).not.toBeInTheDocument();
    expect(screen.queryByText(/Annual Physical/)).not.toBeInTheDocument();
    // And the empty row should render.
    expect(screen.getByText(/No encounters match/i)).toBeInTheDocument();
  });
});
