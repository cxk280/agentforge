// Issues entry — mounts the Issues component on #cp-root inside the PHP
// wrapper at /interface/patient_file/issues/copilot_issues.php.
//
// The PHP wrapper queries the lists table (medical_problem + allergy rows
// for the current pid) and JSON-encodes the result as data-issues on
// #cp-root. We parse it here and hand it to the component, so the rendered
// rows match what's actually in the database for the active patient.

import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import { Issues } from './Issues';
import type { IssueRow, IssuesPayload } from './Issues';
import { readBootContext } from '../../shared/lib/bootContext';

const mountEl = document.getElementById('cp-root');
if (!mountEl) {
  throw new Error('Issues entry: #cp-root mount node not found');
}

const boot = readBootContext(mountEl);

const EMPTY_ISSUES: IssuesPayload = {
  problems: [],
  allergies: [],
};

let issues: IssuesPayload = EMPTY_ISSUES;
try {
  const raw = mountEl.dataset['issues'] ?? '';
  if (raw !== '') {
    const parsed = JSON.parse(raw) as unknown;
    if (parsed !== null && typeof parsed === 'object') {
      const obj = parsed as { problems?: unknown; allergies?: unknown };
      const problems  = Array.isArray(obj.problems)  ? (obj.problems  as IssueRow[]) : [];
      const allergies = Array.isArray(obj.allergies) ? (obj.allergies as IssueRow[]) : [];
      issues = { problems, allergies };
    }
  }
} catch (err) {
  console.error('Issues: failed to parse data-issues', err);
}

createRoot(mountEl).render(
  <StrictMode>
    <Issues boot={boot} issues={issues} />
  </StrictMode>,
);
