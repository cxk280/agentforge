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
        patient_tracker:     resolve(__dirname, 'src/pages/patient_tracker/index.tsx'),
        recalls:             resolve(__dirname, 'src/pages/recalls/index.tsx'),
        acl:                 resolve(__dirname, 'src/pages/acl/index.tsx'),
        practice_settings:   resolve(__dirname, 'src/pages/practice_settings/index.tsx'),
        facilities:          resolve(__dirname, 'src/pages/facilities/index.tsx'),
        audit:               resolve(__dirname, 'src/pages/audit/index.tsx'),
        system_status:       resolve(__dirname, 'src/pages/system_status/index.tsx'),
        module_installer:    resolve(__dirname, 'src/pages/module_installer/index.tsx'),
        users:               resolve(__dirname, 'src/pages/users/index.tsx'),
        provider_portal:     resolve(__dirname, 'src/pages/provider_portal/index.tsx'),
        billing:             resolve(__dirname, 'src/pages/billing/index.tsx'),
        aging:               resolve(__dirname, 'src/pages/aging/index.tsx'),
        inventory:           resolve(__dirname, 'src/pages/inventory/index.tsx'),
        chart_tracker:       resolve(__dirname, 'src/pages/chart_tracker/index.tsx'),
        new_patient:         resolve(__dirname, 'src/pages/new_patient/index.tsx'),
        dashboard:           resolve(__dirname, 'src/pages/dashboard/index.tsx'),
        history:             resolve(__dirname, 'src/pages/history/index.tsx'),
        assessments:         resolve(__dirname, 'src/pages/assessments/index.tsx'),
        record_request:      resolve(__dirname, 'src/pages/record_request/index.tsx'),
        education:           resolve(__dirname, 'src/pages/education/index.tsx'),
        report:              resolve(__dirname, 'src/pages/report/index.tsx'),
        documents:           resolve(__dirname, 'src/pages/documents/index.tsx'),
        transactions:        resolve(__dirname, 'src/pages/transactions/index.tsx'),
        issues:              resolve(__dirname, 'src/pages/issues/index.tsx'),
        ledger:              resolve(__dirname, 'src/pages/ledger/index.tsx'),
        external_data:         resolve(__dirname, 'src/pages/external_data/index.tsx'),
        patient_modules:       resolve(__dirname, 'src/pages/patient_modules/index.tsx'),
        visit_history:         resolve(__dirname, 'src/pages/visit_history/index.tsx'),
        authorizations:        resolve(__dirname, 'src/pages/authorizations/index.tsx'),
        immunization_registry: resolve(__dirname, 'src/pages/immunization_registry/index.tsx'),
        encounter:           resolve(__dirname, 'src/pages/encounter/index.tsx'),
        create_visit:        resolve(__dirname, 'src/pages/create_visit/index.tsx'),
        fee_sheet:           resolve(__dirname, 'src/pages/fee_sheet/index.tsx'),
        pending_review:      resolve(__dirname, 'src/pages/pending_review/index.tsx'),
        patient_results:     resolve(__dirname, 'src/pages/patient_results/index.tsx'),
        lab_overview:        resolve(__dirname, 'src/pages/lab_overview/index.tsx'),
        batch_results:       resolve(__dirname, 'src/pages/batch_results/index.tsx'),
        erx:                 resolve(__dirname, 'src/pages/erx/index.tsx'),
        erx_renewal:         resolve(__dirname, 'src/pages/erx_renewal/index.tsx'),
        coding_lists:        resolve(__dirname, 'src/pages/coding_lists/index.tsx'),
        forms_layouts:       resolve(__dirname, 'src/pages/forms_layouts/index.tsx'),
        templates:           resolve(__dirname, 'src/pages/templates/index.tsx'),
        erx_epcs:            resolve(__dirname, 'src/pages/erx_epcs/index.tsx'),
        payment:             resolve(__dirname, 'src/pages/payment/index.tsx'),
        pro:                 resolve(__dirname, 'src/pages/pro/index.tsx'),
        login:               resolve(__dirname, 'src/pages/login/index.tsx'),
        lab_documents:       resolve(__dirname, 'src/pages/lab_documents/index.tsx'),
        posting_payments:    resolve(__dirname, 'src/pages/posting_payments/index.tsx'),
        edi_history:         resolve(__dirname, 'src/pages/edi_history/index.tsx'),
        claim_file_tracker:  resolve(__dirname, 'src/pages/claim_file_tracker/index.tsx'),
      },
    },
  },
  server: {
    port: 5173,
    strictPort: true,
  },
});
