// Assessments entry — mounts the Assessments component on #cp-root inside the
// PHP wrapper at /interface/patient_file/assessments/copilot_assessments.php.
//
// The PHP wrapper JSON-encodes the categories sidebar, the assessment cards,
// and the header summary onto data-categories / data-assessments /
// data-summary on #cp-root (currently hardcoded stubs — see TODO(real-data)
// in the wrapper). We parse those here and hand them to the component as
// props, mirroring the Finder + ExternalData pattern so the React side is
// consistent across pages even while the underlying data is still mock.

import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import { Assessments } from './Assessments';
import type {
  Assessment,
  AssessmentsSummary,
  Category,
} from './Assessments';
import { readBootContext } from '../../shared/lib/bootContext';

const mountEl = document.getElementById('cp-root');
if (!mountEl) {
  throw new Error('Assessments entry: #cp-root mount node not found');
}

const boot = readBootContext(mountEl);

function parseJsonArray<T>(raw: string | undefined): readonly T[] {
  if (raw === undefined || raw === '') return [];
  try {
    const parsed: unknown = JSON.parse(raw);
    return Array.isArray(parsed) ? (parsed as T[]) : [];
  } catch (err) {
    console.error('Assessments: failed to parse data attribute', err);
    return [];
  }
}

function parseSummary(raw: string | undefined): AssessmentsSummary {
  if (raw === undefined || raw === '') return { due: 0, completed: 0 };
  try {
    const parsed = JSON.parse(raw) as { due?: unknown; completed?: unknown };
    const due = typeof parsed.due === 'number' ? parsed.due : 0;
    const completed = typeof parsed.completed === 'number' ? parsed.completed : 0;
    return { due, completed };
  } catch (err) {
    console.error('Assessments: failed to parse data-summary', err);
    return { due: 0, completed: 0 };
  }
}

const categories = parseJsonArray<Category>(mountEl.dataset['categories']);
const assessments = parseJsonArray<Assessment>(mountEl.dataset['assessments']);
const summary = parseSummary(mountEl.dataset['summary']);

createRoot(mountEl).render(
  <StrictMode>
    <Assessments
      boot={boot}
      categories={categories}
      assessments={assessments}
      summary={summary}
    />
  </StrictMode>,
);
