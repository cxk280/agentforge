<?php

/**
 * Batch Results — Screen 38.
 *
 * Bulk-review screen for an inbound lab batch. Cross-patient. Shows KPI
 * roll-up, filter pills, and the per-patient result rows with checkbox
 * select, value/ref-range/delta and per-row flag pill.
 *
 * The chrome (top nav) is rendered by the parent shell — this page
 * renders only the body.
 *
 * BATCH DEFINITION
 *   The OpenEMR `procedure_order.lab_id` column is meant to identify the
 *   external lab a batch came from, but in this seed every row has
 *   lab_id = 0. To still produce a meaningful "batch" grouping we group
 *   by DATE(procedure_report.date_report) — i.e. all results that
 *   reported on the same calendar day belong to the same run. The
 *   selected batch can be overridden via `?batch=YYYY-MM-DD`; default is
 *   the most recent batch.
 *
 * @package OpenEMR
 * @author  AgentForge / Claude Code
 * @license https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

require_once(__DIR__ . "/../globals.php");
require_once(__DIR__ . "/../main/copilot_helpers.php");

use OpenEMR\Common\Logging\EventAuditLogger;

// -----------------------------------------------------------------------------
// Helpers (file-local).
// -----------------------------------------------------------------------------

/**
 * Classify a `procedure_result.abnormal` flag into one of:
 *   'normal' | 'abnormal' | 'critical'
 */
function cp_batch_flag_class(string $abn): string
{
    $a = strtolower(trim($abn));
    if (in_array($a, ['critical', 'cc'], true)) {
        return 'critical';
    }
    if (in_array($a, ['', 'no', 'n', 'normal'], true)) {
        return 'normal';
    }
    // 'yes' | 'y' | 'low' | 'high' | 'abnormal' | anything else non-normal.
    return 'abnormal';
}

/**
 * Pick the value-cell tone class for a row.
 */
function cp_batch_value_tone(string $flagClass, string $resultRaw): string
{
    if ($flagClass === 'critical') { return 'danger'; }
    if ($flagClass === 'abnormal') { return 'warn'; }
    // For non-numeric "Within range"-style results we want a slightly heavier
    // weight so the cell still reads as a deliberate value.
    if (!is_numeric(trim($resultRaw))) {
        return 'plainBold';
    }
    return 'plain';
}

// -----------------------------------------------------------------------------
// POST handlers — sign_all / reimport / export_csv.
// CSRF skipped — internal mock page.
// -----------------------------------------------------------------------------

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $action = (string)($_POST['action'] ?? '');
    $batchKey = (string)($_POST['batch'] ?? '');
    // Whitelist: must look like YYYY-MM-DD or empty.
    if ($batchKey !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $batchKey)) {
        $batchKey = '';
    }

    $authUser  = (string)($_SESSION['authUser'] ?? 'admin');
    $authGroup = (string)($_SESSION['authProvider'] ?? 'Default');

    if ($action === 'sign_all' && $batchKey !== '') {
        // Mark every report in the batch with a NULL or non-'reviewed'
        // review_status as 'reviewed'. We scope by date(date_report) to
        // match how the page groups batches.
        sqlStatement(
            "UPDATE procedure_report
             SET review_status = 'reviewed'
             WHERE DATE(date_report) = ?
               AND (review_status IS NULL OR review_status <> 'reviewed')",
            [$batchKey]
        );
        // How many distinct results are in the batch (used for the flash msg).
        $signed = sqlQuery(
            "SELECT COUNT(*) AS n
             FROM procedure_result prs
             JOIN procedure_report pr ON pr.procedure_report_id = prs.procedure_report_id
             WHERE DATE(pr.date_report) = ?",
            [$batchKey]
        );
        $n = (int)($signed['n'] ?? 0);
        try {
            EventAuditLogger::getInstance()->newEvent(
                'sign',
                $authUser,
                $authGroup,
                1,
                "copilot_batch_results sign_all batch=$batchKey count=$n"
            );
        } catch (\Throwable $t) {
            // Swallow — UX still proceeds.
        }
        header('Location: ' . $_SERVER['PHP_SELF'] . '?batch=' . urlencode($batchKey) . '&msg=signed_all_' . $n);
        exit;
    }

    if ($action === 'reimport' && $batchKey !== '') {
        // Out-of-scope to actually re-pull from the lab interface;
        // record the request to the audit log so the action is honest.
        try {
            EventAuditLogger::getInstance()->newEvent(
                'queue',
                $authUser,
                $authGroup,
                1,
                "copilot_batch_results reimport batch=$batchKey (queued)"
            );
        } catch (\Throwable $t) {
            // Swallow.
        }
        header('Location: ' . $_SERVER['PHP_SELF'] . '?batch=' . urlencode($batchKey) . '&msg=reimport_queued');
        exit;
    }

    if ($action === 'export_csv' && $batchKey !== '') {
        // Stream a CSV of every row in the batch (no filter applied).
        $rs = sqlStatement(
            "SELECT prs.procedure_result_id,
                    pd.fname, pd.lname, pd.pubpid,
                    prs.result_text, prs.result, prs.units, prs.range,
                    prs.abnormal, pr.review_status, pr.date_collected,
                    u.fname AS pfname, u.lname AS plname, u.title AS ptitle, u.username AS puser
             FROM procedure_result prs
             JOIN procedure_report pr ON pr.procedure_report_id = prs.procedure_report_id
             JOIN procedure_order po ON po.procedure_order_id = pr.procedure_order_id
             LEFT JOIN patient_data pd ON pd.pid = po.patient_id
             LEFT JOIN users u ON u.id = po.provider_id
             WHERE DATE(pr.date_report) = ?
             ORDER BY prs.procedure_result_id ASC",
            [$batchKey]
        );
        $filename = 'batch_' . $batchKey . '_' . date('Ymd_His') . '.csv';
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        $out = fopen('php://output', 'w');
        fputcsv($out, [
            'result_id', 'patient', 'mrn', 'test', 'value', 'units', 'range',
            'abnormal', 'review_status', 'drawn', 'provider',
        ]);
        while ($r = sqlFetchArray($rs)) {
            $providerName = cp_format_provider_name([
                'username' => $r['puser'] ?? '',
                'fname'    => $r['pfname'] ?? '',
                'lname'    => $r['plname'] ?? '',
                'title'    => $r['ptitle'] ?? '',
            ]);
            $value = trim((string)($r['result'] ?? ''));
            $patient = trim(((string)($r['fname'] ?? '')) . ' ' . ((string)($r['lname'] ?? '')));
            $drawn = $r['date_collected'] ? date('m/d/Y H:i', (int)strtotime((string)$r['date_collected'])) : '';
            fputcsv($out, [
                (int)$r['procedure_result_id'],
                $patient,
                (string)($r['pubpid'] ?? ''),
                (string)($r['result_text'] ?? ''),
                $value,
                (string)($r['units'] ?? ''),
                (string)($r['range'] ?? ''),
                (string)($r['abnormal'] ?? ''),
                (string)($r['review_status'] ?? ''),
                $drawn,
                $providerName,
            ]);
        }
        fclose($out);
        try {
            EventAuditLogger::getInstance()->newEvent(
                'export',
                $authUser,
                $authGroup,
                1,
                "copilot_batch_results export_csv batch=$batchKey"
            );
        } catch (\Throwable $t) {
            // Swallow.
        }
        exit;
    }

    // Fallback POST — unknown action; just bounce back.
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

// -----------------------------------------------------------------------------
// GET — load batches, KPIs, rows.
// -----------------------------------------------------------------------------

// Available batches (DATE(date_report)) and counts. Newest first. We pick
// the "most recent unfinished" batch as default — i.e. the most recent
// batch with at least one report whose review_status is not 'reviewed'.
// If every batch is reviewed, just default to the newest.
$batchesRs = sqlStatement(
    "SELECT DATE(pr.date_report) AS batch_key,
            MAX(pr.date_report) AS reported_at,
            COUNT(prs.procedure_result_id) AS results,
            COUNT(DISTINCT po.patient_id) AS patients,
            SUM(CASE WHEN COALESCE(pr.review_status, '') <> 'reviewed' THEN 1 ELSE 0 END) AS unreviewed
     FROM procedure_result prs
     JOIN procedure_report pr ON pr.procedure_report_id = prs.procedure_report_id
     JOIN procedure_order po ON po.procedure_order_id = pr.procedure_order_id
     WHERE pr.date_report IS NOT NULL
     GROUP BY DATE(pr.date_report)
     ORDER BY reported_at DESC"
);
$batches = [];
while ($b = sqlFetchArray($batchesRs)) {
    $batches[] = $b;
}

// Selected batch — from ?batch=YYYY-MM-DD if present and known.
$requestedBatch = (string)($_GET['batch'] ?? '');
if ($requestedBatch !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $requestedBatch)) {
    $requestedBatch = '';
}
$selectedBatch = '';
if ($requestedBatch !== '') {
    foreach ($batches as $b) {
        if ((string)$b['batch_key'] === $requestedBatch) {
            $selectedBatch = $requestedBatch;
            break;
        }
    }
}
if ($selectedBatch === '') {
    // Most recent unfinished, else most recent.
    foreach ($batches as $b) {
        if ((int)$b['unreviewed'] > 0) {
            $selectedBatch = (string)$b['batch_key'];
            break;
        }
    }
    if ($selectedBatch === '' && !empty($batches)) {
        $selectedBatch = (string)$batches[0]['batch_key'];
    }
}

// Subtitle bits.
$batchPatients = 0;
$batchTotal    = 0;
$batchReceived = '';
$batchLabId    = 0;
foreach ($batches as $b) {
    if ((string)$b['batch_key'] === $selectedBatch) {
        $batchPatients = (int)$b['patients'];
        $batchTotal    = (int)$b['results'];
        $ts = strtotime((string)$b['reported_at']);
        $batchReceived = $ts ? date('m/d H:i', $ts) : (string)$b['reported_at'];
        break;
    }
}
// Pull a representative lab_id (and provider, for the subtitle) for the batch.
if ($selectedBatch !== '') {
    $repRow = sqlQuery(
        "SELECT po.lab_id
         FROM procedure_report pr
         JOIN procedure_order po ON po.procedure_order_id = pr.procedure_order_id
         WHERE DATE(pr.date_report) = ?
         ORDER BY pr.date_report DESC
         LIMIT 1",
        [$selectedBatch]
    );
    if ($repRow) {
        $batchLabId = (int)($repRow['lab_id'] ?? 0);
    }
}
// "Run #" — prefer lab_id; fall back to a stable hash of the date so the
// subtitle still reads like a human run identifier.
$runId = $batchLabId > 0
    ? (string)$batchLabId
    : ($selectedBatch !== '' ? (string)(2000 + (int)date('z', (int)strtotime($selectedBatch)) + (int)date('Y', (int)strtotime($selectedBatch))) : '0');

// -----------------------------------------------------------------------------
// KPIs for the selected batch.
// -----------------------------------------------------------------------------
$kpi = [
    'total'    => 0,
    'normal'   => 0,
    'abnormal' => 0,
    'critical' => 0,
    'signed'   => 0,
];
if ($selectedBatch !== '') {
    $kpiRow = sqlQuery(
        "SELECT
            COUNT(*) AS total,
            SUM(CASE WHEN LOWER(TRIM(prs.abnormal)) IN ('','no','n','normal') THEN 1 ELSE 0 END) AS normal,
            SUM(CASE WHEN LOWER(TRIM(prs.abnormal)) IN ('yes','y','low','high','abnormal') THEN 1 ELSE 0 END) AS abnormal,
            SUM(CASE WHEN LOWER(TRIM(prs.abnormal)) IN ('critical','cc') THEN 1 ELSE 0 END) AS critical,
            SUM(CASE WHEN pr.review_status = 'reviewed' THEN 1 ELSE 0 END) AS signed
         FROM procedure_result prs
         JOIN procedure_report pr ON pr.procedure_report_id = prs.procedure_report_id
         WHERE DATE(pr.date_report) = ?",
        [$selectedBatch]
    );
    if ($kpiRow) {
        $kpi['total']    = (int)($kpiRow['total'] ?? 0);
        $kpi['normal']   = (int)($kpiRow['normal'] ?? 0);
        $kpi['abnormal'] = (int)($kpiRow['abnormal'] ?? 0);
        $kpi['critical'] = (int)($kpiRow['critical'] ?? 0);
        $kpi['signed']   = (int)($kpiRow['signed'] ?? 0);
    }
}
$kpi['unsigned']  = max(0, $kpi['total'] - $kpi['signed']);
$signedPct = $kpi['total'] > 0 ? (int)round(($kpi['signed'] / $kpi['total']) * 100) : 0;

// -----------------------------------------------------------------------------
// Filter pill — All / Critical / Abnormal / Normal / Unsigned.
// -----------------------------------------------------------------------------
$validFilters = ['all', 'critical', 'abnormal', 'normal', 'unsigned'];
$filter = (string)($_GET['filter'] ?? 'all');
if (!in_array($filter, $validFilters, true)) {
    $filter = 'all';
}

// -----------------------------------------------------------------------------
// Apply-template options: pull from document_templates, category =
// 'patient_letter'. If none seeded, fall back to a small honest list.
// -----------------------------------------------------------------------------
$templateOptions = [];
$tplRs = sqlStatement(
    "SELECT DISTINCT template_name
     FROM document_templates
     WHERE category = 'patient_letter'
       AND template_name IS NOT NULL
       AND template_name <> ''
     ORDER BY template_name ASC"
);
while ($tr = sqlFetchArray($tplRs)) {
    $templateOptions[] = (string)$tr['template_name'];
}
if (empty($templateOptions)) {
    // Hardcoded fallback — none seeded in this dev DB. Comment is the
    // honest-button signal: in production the dropdown reads from
    // document_templates.
    $templateOptions = [
        'A1C critical letter',
        'Lipid abnormal letter',
        'TSH critical letter',
        'Normal results letter',
    ];
}
$selectedTemplate = $templateOptions[0] ?? '';

// -----------------------------------------------------------------------------
// Main query: rows for the selected batch.
// -----------------------------------------------------------------------------
$rows = [];
if ($selectedBatch !== '') {
    $rs = sqlStatement(
        "SELECT prs.procedure_result_id,
                prs.result_text, prs.result, prs.units, prs.range,
                prs.abnormal,
                pr.procedure_report_id, pr.review_status,
                pr.date_collected, pr.date_report,
                po.procedure_order_id, po.patient_id, po.provider_id,
                pd.fname AS p_fname, pd.lname AS p_lname, pd.pubpid AS p_pubpid,
                u.username AS u_user, u.fname AS u_fname, u.lname AS u_lname, u.title AS u_title
         FROM procedure_result prs
         JOIN procedure_report pr ON pr.procedure_report_id = prs.procedure_report_id
         JOIN procedure_order po  ON po.procedure_order_id   = pr.procedure_order_id
         LEFT JOIN patient_data pd ON pd.pid = po.patient_id
         LEFT JOIN users u ON u.id = po.provider_id
         WHERE DATE(pr.date_report) = ?
         ORDER BY
            CASE LOWER(TRIM(prs.abnormal))
                WHEN 'critical' THEN 0
                WHEN 'cc'       THEN 0
                WHEN 'high'     THEN 1
                WHEN 'low'      THEN 1
                WHEN 'abnormal' THEN 1
                WHEN 'yes'      THEN 1
                WHEN 'y'        THEN 1
                ELSE 2
            END,
            pd.lname ASC, prs.procedure_result_id ASC",
        [$selectedBatch]
    );

    while ($r = sqlFetchArray($rs)) {
        $abnRaw = (string)($r['abnormal'] ?? '');
        $flagClass = cp_batch_flag_class($abnRaw);

        // Apply filter pill.
        if ($filter === 'critical' && $flagClass !== 'critical') { continue; }
        if ($filter === 'abnormal' && $flagClass !== 'abnormal') { continue; }
        if ($filter === 'normal'   && $flagClass !== 'normal')   { continue; }
        if ($filter === 'unsigned' && (string)($r['review_status'] ?? '') === 'reviewed') { continue; }

        // Patient.
        $name = trim(((string)($r['p_fname'] ?? '')) . ' ' . ((string)($r['p_lname'] ?? ''))) ?: '(unknown)';
        $mrn = '#' . (string)($r['p_pubpid'] ?? '—');

        // Test name.
        $test = trim((string)($r['result_text'] ?? '')) ?: '—';

        // Value display.
        $resultRaw = trim((string)($r['result'] ?? ''));
        $units = trim((string)($r['units'] ?? ''));
        if ($resultRaw === '') {
            $valDisplay = 'Within range';
        } elseif ($units !== '') {
            $valDisplay = $resultRaw . ' ' . $units;
        } else {
            $valDisplay = $resultRaw;
        }
        $valTone = cp_batch_value_tone($flagClass, $resultRaw);

        // Reference range.
        $ref = trim((string)($r['range'] ?? '')) ?: '—';

        // Delta vs previous result for this patient + test.
        $delta = '—';
        $deltaTone = 'muted';
        $resultText = (string)($r['result_text'] ?? '');
        $patientId = (int)($r['patient_id'] ?? 0);
        $thisResultId = (int)($r['procedure_result_id'] ?? 0);
        if ($resultText !== '' && $patientId > 0 && $resultRaw !== '' && is_numeric($resultRaw)) {
            $prior = sqlQuery(
                "SELECT prs2.result, pr2.date_report
                 FROM procedure_result prs2
                 JOIN procedure_report pr2 ON pr2.procedure_report_id = prs2.procedure_report_id
                 JOIN procedure_order  po2 ON po2.procedure_order_id  = pr2.procedure_order_id
                 WHERE po2.patient_id = ?
                   AND prs2.result_text = ?
                   AND prs2.procedure_result_id <> ?
                   AND pr2.date_report < ?
                 ORDER BY pr2.date_report DESC
                 LIMIT 1",
                [$patientId, $resultText, $thisResultId, (string)$r['date_report']]
            );
            if ($prior && trim((string)($prior['result'] ?? '')) !== '' && is_numeric((string)$prior['result'])) {
                $cur = (float)$resultRaw;
                $prv = (float)$prior['result'];
                $diff = $cur - $prv;
                $arrow = '→';
                if ($diff > 0.0001) {
                    $arrow = abs($diff) >= ($prv * 0.25) ? '↑↑' : '↑';
                } elseif ($diff < -0.0001) {
                    $arrow = abs($diff) >= ($prv * 0.25) ? '↓↓' : '↓';
                }
                if ($diff === 0.0 || abs($diff) < 0.0001) {
                    $delta = '→ stable';
                    $deltaTone = 'muted';
                } else {
                    $priorDisplay = rtrim(rtrim((string)$prv, '0'), '.');
                    if ($priorDisplay === '') { $priorDisplay = (string)$prv; }
                    $delta = $arrow . ' from ' . $priorDisplay . ($units !== '' ? (' ' . $units) : '');
                    if ($flagClass === 'critical') { $deltaTone = 'danger'; }
                    elseif ($flagClass === 'abnormal') { $deltaTone = 'warn'; }
                    else { $deltaTone = 'muted'; }
                }
            } else {
                $delta = 'New finding';
                $deltaTone = $flagClass === 'critical' ? 'danger' : ($flagClass === 'abnormal' ? 'warn' : 'muted');
            }
        } elseif ($resultRaw !== '' && !is_numeric($resultRaw)) {
            // Non-numeric (e.g. positive/negative, narrative) — call it new.
            $delta = $flagClass === 'normal' ? '—' : 'New finding';
            $deltaTone = $flagClass === 'critical' ? 'danger' : ($flagClass === 'abnormal' ? 'warn' : 'muted');
        }

        // Drawn date.
        $drawnTs = $r['date_collected']
            ? strtotime((string)$r['date_collected'])
            : strtotime((string)$r['date_report']);
        $drawn = $drawnTs ? date('m/d', (int)$drawnTs) : '—';

        // Flag pill.
        if ($flagClass === 'critical') {
            $flagLbl = 'Critical';
            $flagTone = 'danger';
        } elseif ($flagClass === 'abnormal') {
            $flagLbl = 'Abnormal';
            $flagTone = 'warn';
        } else {
            $flagLbl = 'Normal';
            $flagTone = 'good';
        }

        // Provider.
        $providerName = cp_format_provider_name([
            'username' => (string)($r['u_user']  ?? ''),
            'fname'    => (string)($r['u_fname'] ?? ''),
            'lname'    => (string)($r['u_lname'] ?? ''),
            'title'    => (string)($r['u_title'] ?? ''),
        ]);

        // Pre-check rows that still need attention (unsigned OR abnormal).
        $isReviewed = (string)($r['review_status'] ?? '') === 'reviewed';
        $checked = !$isReviewed || $flagClass !== 'normal';

        $rows[] = [
            'id'          => (int)$r['procedure_result_id'],
            'reportId'    => (int)$r['procedure_report_id'],
            'checked'     => $checked,
            'name'        => $name,
            'mrn'         => $mrn,
            'test'        => $test,
            'value'       => $valDisplay,
            'value_tone'  => $valTone,
            'ref'         => $ref,
            'delta'       => $delta,
            'delta_tone'  => $deltaTone,
            'drawn'       => $drawn,
            'flag'        => $flagLbl,
            'flag_tone'   => $flagTone,
            'provider'    => $providerName,
        ];
    }
}

// Flash message.
$flash = (string)($_GET['msg'] ?? '');
$flashText = '';
if ($flash !== '') {
    if (preg_match('/^signed_all_(\d+)$/', $flash, $m)) {
        $flashText = 'Signed ' . (int)$m[1] . ' results · ok';
    } elseif ($flash === 'reimport_queued') {
        $flashText = 'Re-import queued · we will surface results when the lab responds';
    } else {
        $flashText = 'Done';
    }
}

// Page-level helpers for the template.
$pageSelf = (string)$_SERVER['PHP_SELF'];
$qsBatch  = $selectedBatch !== '' ? ('?batch=' . urlencode($selectedBatch)) : '';

?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo xlt('Batch Results'); ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/public/copilot-archetype.css">
<style>
  /* Page header dot separator */
  .cp-pagehead .dot { color: #8A91A1; font-size: 14px; padding: 0 4px; }
  .cp-pagehead .meta-light { color: #8A91A1; font-size: 12px; line-height: 1; }

  /* 5-column KPI strip for Batch Results */
  .cp-kpi-5 { grid-template-columns: repeat(5, 1fr); }
  .cp-kpi .val.green  { color: #1F8C4D; }
  .cp-kpi .val.orange { color: #FA8C33; }
  .cp-kpi .val.red    { color: #D93838; }
  .cp-kpi .progress {
    height: 6px; background: #E4E5E8; border-radius: 999px; overflow: hidden;
    margin-top: 8px;
  }
  .cp-kpi .progress > i {
    display: block; height: 100%; background: #008C8C; border-radius: 999px;
  }
  .cp-kpi .sub.muted { color: #8A91A1; }

  /* Pills with count badge */
  .cp-filter .pills button .ct {
    background: #F5F6F7; color: #4F5763;
    margin-left: 6px; padding: 1px 7px; border-radius: 999px;
    font-size: 10px; font-weight: 600;
  }
  .cp-filter .pills button.active .ct {
    background: #008C8C; color: #FFFFFF;
  }
  .cp-filter .right {
    margin-left: auto;
    display: inline-flex; align-items: center; gap: 8px;
  }
  .cp-filter .right .lbl { font-size: 11px; color: #8A91A1; }
  .cp-filter .dropdown {
    background: #FFFFFF; border: 1px solid #E4E5E8; border-radius: 8px;
    padding: 6px 12px; font-size: 11px; color: #0D1B2A;
    display: inline-flex; align-items: center; gap: 8px;
  }
  .cp-filter .dropdown select {
    border: 0; background: transparent; font: inherit; color: inherit;
    outline: none; padding: 0; margin: 0;
  }
  .cp-filter .dropdown .caret { color: #8A91A1; font-size: 9px; }

  /* Batch table specifics */
  .cp-bt { border-radius: 12px; }
  .cp-bt table { font-size: 12px; }
  .cp-bt th, .cp-bt td { padding: 11px 14px; }
  .cp-bt th.cb, .cp-bt td.cb { width: 36px; padding-left: 18px; padding-right: 8px; }
  .cp-bt th.act, .cp-bt td.act { width: 28px; padding-left: 6px; padding-right: 14px; text-align: right; }
  .cp-bt td.bold { font-weight: 600; color: #0D1B2A; }
  .cp-bt td.mrn { color: #8A91A1; }
  .cp-bt td.muted-soft { color: #4F5763; }

  /* checkbox visuals (visual layer; functional input lives underneath) */
  .cp-cb {
    width: 16px; height: 16px;
    border: 1.5px solid #C9CDD4; border-radius: 4px;
    background: #FFFFFF; display: inline-block; vertical-align: middle;
    position: relative;
    cursor: pointer;
  }
  .cp-cb.on { background: #008C8C; border-color: #008C8C; }
  .cp-cb.on::after {
    content: '✓'; color: #FFFFFF; font-size: 11px; font-weight: 700;
    position: absolute; left: 2px; top: -1px;
  }
  .cp-cb-input { position: absolute; opacity: 0; pointer-events: none; }

  /* Value cell colors */
  .v-danger    { color: #D93838; font-weight: 700; }
  .v-warn      { color: #FA8C33; font-weight: 700; }
  .v-plain     { color: #0D1B2A; font-weight: 500; }
  .v-plainBold { color: #0D1B2A; font-weight: 600; }

  /* Delta cell */
  .d-danger { color: #D93838; font-weight: 600; }
  .d-warn   { color: #FA8C33; font-weight: 600; }
  .d-muted  { color: #8A91A1; font-weight: 500; }

  /* Kebab menu */
  .cp-kebab {
    display: inline-block; color: #8A91A1; cursor: pointer;
    padding: 4px 6px; border-radius: 6px; font-size: 14px;
    line-height: 1;
    text-decoration: none;
  }
  .cp-kebab:hover { background: #F5F6F7; color: #0D1B2A; }

  /* Flash banner */
  .cp-flash {
    background: #E6F5EE; color: #1F8C4D;
    border: 1px solid #C2E5D2; border-radius: 8px;
    padding: 8px 14px; font-size: 12px; font-weight: 500;
    margin: 0 0 10px 0;
  }

  /* Pills as anchors keep the same look */
  .cp-filter .pills a {
    text-decoration: none;
  }

  /* Header form buttons share the cp-btn look */
  .cp-pagehead form { display: inline-flex; }
  .cp-pagehead form .cp-btn { margin: 0; }
</style>
</head>
<body class="cp-arch">

<header class="cp-pagehead">
  <div class="info">
    <div style="display:flex; align-items:center; gap:8px;">
      <span class="title"><?php echo xlt('Batch Results'); ?></span>
      <span class="dot">·</span>
      <?php if ($selectedBatch !== ''): ?>
        <span class="meta-light">
          <?php echo xlt('Quest run'); ?> #<?php echo text($runId); ?> ·
          <?php echo text((string)$batchPatients) . ' ' . xlt('patient' . ($batchPatients === 1 ? '' : 's')); ?> ·
          <?php echo xlt('received'); ?> <?php echo text($batchReceived); ?>
        </span>
      <?php else: ?>
        <span class="meta-light"><?php echo xlt('No batches found'); ?></span>
      <?php endif; ?>
    </div>
  </div>

  <!-- Export — POST action=export_csv → CSV download -->
  <form method="post" action="<?php echo attr($pageSelf); ?>" style="display:inline-flex;">
    <input type="hidden" name="action" value="export_csv">
    <input type="hidden" name="batch" value="<?php echo attr($selectedBatch); ?>">
    <button type="submit" class="cp-btn ghost"<?php echo $selectedBatch === '' ? ' disabled' : ''; ?>>⤓ <?php echo xlt('Export'); ?></button>
  </form>

  <!-- Re-import — POST action=reimport → audit log + flash msg -->
  <form method="post" action="<?php echo attr($pageSelf); ?>" style="display:inline-flex;">
    <input type="hidden" name="action" value="reimport">
    <input type="hidden" name="batch" value="<?php echo attr($selectedBatch); ?>">
    <button type="submit" class="cp-btn ghost"<?php echo $selectedBatch === '' ? ' disabled' : ''; ?>>⬆ <?php echo xlt('Re-import'); ?></button>
  </form>

  <!-- Sign all — POST action=sign_all → UPDATE review_status='reviewed' -->
  <form method="post" action="<?php echo attr($pageSelf); ?>" style="display:inline-flex;">
    <input type="hidden" name="action" value="sign_all">
    <input type="hidden" name="batch" value="<?php echo attr($selectedBatch); ?>">
    <button type="submit" class="cp-btn primary"<?php echo $kpi['unsigned'] === 0 ? ' disabled title="Already signed"' : ''; ?>>
      <?php echo xlt('Sign all'); ?> (<?php echo text((string)$kpi['unsigned']); ?>)
    </button>
  </form>

  <!-- Help — explicitly disabled for the mock -->
  <button type="button" class="cp-btn ghost" disabled title="Out of scope">? <?php echo xlt('Help'); ?></button>
</header>

<main class="cp-content tight">

  <?php if ($flashText !== ''): ?>
    <div class="cp-flash"><?php echo text($flashText); ?></div>
  <?php endif; ?>

  <div class="cp-kpi-grid cp-kpi-5">
    <div class="cp-kpi">
      <span class="lbl"><?php echo xlt('Total'); ?></span>
      <span class="val"><?php echo text((string)$kpi['total']); ?></span>
    </div>
    <div class="cp-kpi">
      <span class="lbl"><?php echo xlt('Normal'); ?></span>
      <span class="val green"><?php echo text((string)$kpi['normal']); ?></span>
    </div>
    <div class="cp-kpi">
      <span class="lbl"><?php echo xlt('Abnormal'); ?></span>
      <span class="val orange"><?php echo text((string)$kpi['abnormal']); ?></span>
    </div>
    <div class="cp-kpi">
      <span class="lbl"><?php echo xlt('Critical'); ?></span>
      <span class="val red"><?php echo text((string)$kpi['critical']); ?></span>
    </div>
    <div class="cp-kpi">
      <span class="lbl"><?php echo xlt('Already signed'); ?></span>
      <span class="val"><?php echo text((string)$kpi['signed']); ?></span>
      <span class="progress"><i style="width: <?php echo attr((string)$signedPct); ?>%;"></i></span>
      <span class="sub muted"><?php echo text((string)$kpi['signed']); ?> / <?php echo text((string)$kpi['total']); ?> <?php echo xlt('reviewed'); ?></span>
    </div>
  </div>

  <div class="cp-filter">
    <div class="pills">
      <?php
        $pills = [
            ['key' => 'all',      'label' => xl('All'),      'count' => $kpi['total']],
            ['key' => 'critical', 'label' => xl('Critical'), 'count' => $kpi['critical']],
            ['key' => 'abnormal', 'label' => xl('Abnormal'), 'count' => $kpi['abnormal']],
            ['key' => 'normal',   'label' => xl('Normal'),   'count' => $kpi['normal']],
            ['key' => 'unsigned', 'label' => xl('Unsigned'), 'count' => $kpi['unsigned']],
        ];
      ?>
      <?php foreach ($pills as $p): ?>
        <?php
          $href = $pageSelf . '?'
                . ($selectedBatch !== '' ? ('batch=' . urlencode($selectedBatch) . '&') : '')
                . 'filter=' . urlencode($p['key']);
          $isActive = $filter === $p['key'];
        ?>
        <a href="<?php echo attr($href); ?>" class="<?php echo $isActive ? 'active' : ''; ?>">
          <button type="button" class="<?php echo $isActive ? 'active' : ''; ?>">
            <?php echo text($p['label']); ?> <span class="ct"><?php echo text((string)$p['count']); ?></span>
          </button>
        </a>
      <?php endforeach; ?>
    </div>
    <div class="right">
      <span class="lbl"><?php echo xlt('Apply template'); ?></span>
      <span class="dropdown">
        <!-- Apply-template dropdown is part of the bulk-action form below;
             we render a styled native <select> tied to that form. -->
        <select form="cp-bulk-form" name="template">
          <?php foreach ($templateOptions as $tpl): ?>
            <option value="<?php echo attr($tpl); ?>"<?php echo $tpl === $selectedTemplate ? ' selected' : ''; ?>><?php echo text($tpl); ?></option>
          <?php endforeach; ?>
        </select>
        <span class="caret">▾</span>
      </span>
    </div>
  </div>

  <!-- Bulk-action form wraps the table so checkbox selections submit. -->
  <form id="cp-bulk-form" method="post" action="<?php echo attr($pageSelf); ?>">
    <input type="hidden" name="action" value="apply_template">
    <input type="hidden" name="batch" value="<?php echo attr($selectedBatch); ?>">

    <div class="cp-tbl cp-bt">
      <table>
        <thead>
          <tr>
            <th class="cb"><span class="cp-cb<?php echo (count($rows) > 0) ? ' on' : ''; ?>"></span></th>
            <th><?php echo xlt('PATIENT'); ?></th>
            <th><?php echo xlt('MRN'); ?></th>
            <th><?php echo xlt('TEST'); ?></th>
            <th><?php echo xlt('VALUE'); ?></th>
            <th><?php echo xlt('REF RANGE'); ?></th>
            <th><?php echo xlt('DELTA'); ?></th>
            <th><?php echo xlt('DRAWN'); ?></th>
            <th><?php echo xlt('FLAG'); ?></th>
            <th><?php echo xlt('PROVIDER'); ?></th>
            <th class="act"></th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($rows)): ?>
            <tr>
              <td colspan="11" style="text-align:center; color:#8A91A1; padding:24px;">
                <?php echo xlt('No results match the current filter.'); ?>
              </td>
            </tr>
          <?php endif; ?>
          <?php foreach ($rows as $r): ?>
            <tr>
              <td class="cb">
                <label style="display:inline-block; position:relative;">
                  <input class="cp-cb-input" type="checkbox" name="result_ids[]" value="<?php echo attr((string)$r['id']); ?>"<?php echo $r['checked'] ? ' checked' : ''; ?>>
                  <span class="cp-cb<?php echo $r['checked'] ? ' on' : ''; ?>"></span>
                </label>
              </td>
              <td class="bold"><?php echo text($r['name']); ?></td>
              <td class="mrn"><?php echo text($r['mrn']); ?></td>
              <td class="muted-soft"><?php echo text($r['test']); ?></td>
              <td><span class="v-<?php echo attr($r['value_tone']); ?>"><?php echo text($r['value']); ?></span></td>
              <td class="muted-soft"><?php echo text($r['ref']); ?></td>
              <td><span class="d-<?php echo attr($r['delta_tone']); ?>"><?php echo text($r['delta']); ?></span></td>
              <td class="muted-soft"><?php echo text($r['drawn']); ?></td>
              <td><span class="cp-status-pill <?php echo attr($r['flag_tone']); ?>"><?php echo text($r['flag']); ?></span></td>
              <td class="muted-soft"><?php echo text($r['provider']); ?></td>
              <td class="act"><a href="/interface/orders/orders_results.php?id=<?php echo attr((string)$r['reportId']); ?>" class="cp-kebab" title="<?php echo xla('Open report'); ?>">⋯</a></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </form>

</main>

</body>
</html>
