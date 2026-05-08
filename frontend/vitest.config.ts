// Vitest configuration for the AgentForge React unit-test suite.
//
// Co-located with the existing Vite build config (vite.config.ts) so we
// inherit the same plugin set + path aliases. Tests live in
// src/**/*.test.{ts,tsx}; jsdom provides the DOM globals expected by
// React Testing Library.

import { defineConfig } from 'vitest/config';
import react from '@vitejs/plugin-react';

export default defineConfig({
  plugins: [react()],
  test: {
    environment: 'jsdom',
    globals: true,
    setupFiles: ['./src/test/setup.ts'],
    css: true,
    include: ['src/**/*.{test,spec}.{ts,tsx}'],
    exclude: ['node_modules', 'dist', '.fidelity-references'],
  },
});
