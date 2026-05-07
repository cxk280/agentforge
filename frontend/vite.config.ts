import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';
import { resolve } from 'node:path';

// AgentForge React build.
//
// Outputs to ../public/build/ which the PHP wrappers in /interface/.../*.php
// read via manifest.json to resolve hashed asset filenames. /public/build is
// NOT in the openemr/openemr:flex base image's VOLUME list (only public/themes
// and public/assets are), so COPY'd build artifacts survive at runtime — see
// the comment block at the top of /Dockerfile for the volume rules.
//
// Each page entry below produces a separate bundle; React/react-dom + shared
// modules are auto-extracted into a shared chunk that HTTP-caches across
// iframe navigations between Reactified pages.

export default defineConfig({
  plugins: [react()],
  // Apache's DocumentRoot in the OpenEMR runtime is the repo root, so the
  // /public dir is part of the URL path. Built assets live at
  // /var/www/localhost/htdocs/openemr/public/build/ and are reached via
  // https://<host>/public/build/... — the Vite base must match.
  base: '/public/build/',
  build: {
    outDir: resolve(__dirname, '../public/build'),
    emptyOutDir: true,
    manifest: true,
    sourcemap: true,
    rollupOptions: {
      input: {
        calendar:      resolve(__dirname, 'src/pages/calendar/index.tsx'),
        messages:      resolve(__dirname, 'src/pages/messages/index.tsx'),
        header:        resolve(__dirname, 'src/pages/header/index.tsx'),
        reports:       resolve(__dirname, 'src/pages/reports/index.tsx'),
        admin:         resolve(__dirname, 'src/pages/admin/index.tsx'),
        finder:        resolve(__dirname, 'src/pages/finder/index.tsx'),
        office_notes:  resolve(__dirname, 'src/pages/office_notes/index.tsx'),
        print_preview: resolve(__dirname, 'src/pages/print_preview/index.tsx'),
        patient_list:        resolve(__dirname, 'src/pages/patient_list/index.tsx'),
        prescription_report: resolve(__dirname, 'src/pages/prescription_report/index.tsx'),
        lab_trends:          resolve(__dirname, 'src/pages/lab_trends/index.tsx'),
        quality_measures:    resolve(__dirname, 'src/pages/quality_measures/index.tsx'),
        electronic_reports:  resolve(__dirname, 'src/pages/electronic_reports/index.tsx'),
      },
    },
  },
  server: {
    port: 5173,
    strictPort: true,
  },
});
