// QualityMeasures entry — mounts the QualityMeasures component on
// #cp-root inside the PHP wrapper at
// /interface/reports/copilot_quality_measures.php.

import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import { QualityMeasures } from './QualityMeasures';
import { readBootContext } from '../../shared/lib/bootContext';

const mountEl = document.getElementById('cp-root');
if (!mountEl) {
  throw new Error('QualityMeasures entry: #cp-root mount node not found');
}

const boot = readBootContext(mountEl);

createRoot(mountEl).render(
  <StrictMode>
    <QualityMeasures boot={boot} />
  </StrictMode>,
);
