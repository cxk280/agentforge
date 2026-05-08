// Vitest setup — runs once before any test file imports.
//
// Pulls in @testing-library/jest-dom matchers (toBeInTheDocument,
// toHaveTextContent, etc.) so they are available without per-test imports.

import '@testing-library/jest-dom/vitest';
