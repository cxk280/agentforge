// Education entry — mounts the Education component on #cp-root inside the
// PHP wrapper at /interface/patient_file/education/copilot_education.php.
//
// The PHP wrapper queries patient_data + lists + procedure_result for the
// active pid (mirroring the pre-React .bak) and JSON-encodes the result as
// data-education on #cp-root. We parse it here and hand it to the
// component, so the suggestion banner reflects the actual chart.

import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import { Education } from './Education';
import type { EducationPayload, EducationProblem } from './Education';
import { readBootContext } from '../../shared/lib/bootContext';

const mountEl = document.getElementById('cp-root');
if (!mountEl) {
  throw new Error('Education entry: #cp-root mount node not found');
}

const boot = readBootContext(mountEl);

const EMPTY_EDUCATION: EducationPayload = {
  patientName: '',
  problems: [],
  a1c: null,
};

let education: EducationPayload = EMPTY_EDUCATION;
try {
  const raw = mountEl.dataset['education'] ?? '';
  if (raw !== '') {
    const parsed = JSON.parse(raw) as unknown;
    if (parsed !== null && typeof parsed === 'object') {
      const obj = parsed as {
        patientName?: unknown;
        problems?: unknown;
        a1c?: unknown;
      };
      const patientName = typeof obj.patientName === 'string' ? obj.patientName : '';
      const problems: EducationProblem[] = Array.isArray(obj.problems)
        ? (obj.problems as unknown[]).flatMap((p) => {
            if (p === null || typeof p !== 'object') return [];
            const row = p as { title?: unknown; diagnosis?: unknown };
            return [{
              title: typeof row.title === 'string' ? row.title : '',
              diagnosis: typeof row.diagnosis === 'string' ? row.diagnosis : '',
            }];
          })
        : [];
      let a1c: EducationPayload['a1c'] = null;
      if (obj.a1c !== null && typeof obj.a1c === 'object') {
        const ar = obj.a1c as { value?: unknown; date?: unknown };
        if (typeof ar.value === 'number' && Number.isFinite(ar.value)) {
          a1c = {
            value: ar.value,
            date: typeof ar.date === 'string' ? ar.date : '',
          };
        }
      }
      education = { patientName, problems, a1c };
    }
  }
} catch (err) {
  console.error('Education: failed to parse data-education', err);
}

createRoot(mountEl).render(
  <StrictMode>
    <Education boot={boot} education={education} />
  </StrictMode>,
);
