<?php

/**
 * Prescription Report — Screen 44.
 *
 * Reports → Clinical → Prescriptions sub-page. Left rail of report
 * categories, KPI strip, a "Top 12 Medications" bar list, and a
 * "Recent Prescriptions" table with filter pills.
 *
 * Cross-patient. Every visible value comes from the `prescriptions`
 * table joined to `patient_data` and `users`. The date range
 * (?range=q1_2026|q4_2025|all) gates KPI counts and Top 12. The tab
 * (?tab=all|controlled|brand|recent) filters the Recent Prescriptions
 * panel. POST action=export_csv streams the (filtered) recent rows.
 *
 * CSRF skipped — internal mock page.
 *
 * @package OpenEMR
 * @author  AgentForge / Claude Code
 * @license https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

require_once(__DIR__ . "/../globals.php");
require_once(__DIR__ . "/../main/copilot_helpers.php");

// -------------------------------------------------------------------------
// Date-range resolution.
// -------------------------------------------------------------------------
$validRanges = ['q1_2026', 'q4_2025', 'all'];
$range = (string)($_GET['range'] ?? 'all');
if (!in_array($range, $validRanges, true)) {
    $range = 'all';
}

$rangeBounds = [
    'q1_2026' => ['2026-01-01 00:00:00', '2026-04-01 00:00:00'],
    'q4_2025' => ['2025-10-01 00:00:00', '2026-01-01 00:00:00'],
    'all'     => null,
];
$rangeLabels = [
    'q1_2026' => 'Q1 2026',
    'q4_2025' => 'Q4 2025',
    'all'     => 'All time',
];

/**
 * Build a "WHERE date_added >= ? AND date_added < ?" fragment + params
 * for the chosen range. Empty for 'all'.
 *
 * @return array{0: string, 1: list<string>}
 */
$rangeWhere = static function (string $range) use ($rangeBounds): array {
    $b = $rangeBounds[$range] ?? null;
    if ($b === null) {
        return ['', []];
    }
    return [' AND p.date_added >= ? AND p.date_added < ? ', [$b[0], $b[1]]];
};

// -------------------------------------------------------------------------
// Controlled / brand classification.
//
// The seed schema has no dea_schedule column on prescriptions or drugs,
// so we identify controlled substances and brand-name drugs by name
// pattern. These lists cover the seeded fixture data plus standard
// Schedule II–IV agents and common brand suffixes.
// -------------------------------------------------------------------------
$controlledRegex = '/\b(adderall|alprazolam|xanax|ativan|lorazepam|clonazepam'
                 . '|klonopin|diazepam|valium|oxycodone|oxycontin|percocet'
                 . '|hydrocodone|vicodin|norco|tramadol|ultram|codeine'
                 . '|fentanyl|morphine|methadone|methylphenidate|ritalin'
                 . '|concerta|vyvanse|amphetamine|dextroamphetamine'
                 . '|zolpidem|ambien|temazepam|phentermine|testosterone'
                 . '|buprenorphine|suboxone)\b/i';

// Brand: name contains a parenthesized brand annotation like
// "Empagliflozin (Jardiance)" OR is a known brand-only name.
$knownBrands = ['synthroid', 'crestor', 'lipitor', 'jardiance', 'advair',
                'singulair', 'nexium', 'prevacid', 'protonix', 'zoloft',
                'lexapro', 'prozac', 'celexa', 'effexor', 'cymbalta',
                'wellbutrin', 'adderall', 'oxycontin', 'percocet',
                'vicodin', 'norco', 'klonopin', 'xanax', 'ativan',
                'valium', 'ambien', 'concerta', 'vyvanse', 'ritalin',
                'suboxone'];

$isControlled = static function (string $drug) use ($controlledRegex): bool {
    return preg_match($controlledRegex, $drug) === 1;
};
$isBrand = static function (string $drug) use ($knownBrands): bool {
    if (preg_match('/\([A-Z][A-Za-z0-9 \-]+\)/', $drug) === 1) {
        return true; // "Empagliflozin (Jardiance)" style
    }
    $lc = strtolower($drug);
    foreach ($knownBrands as $b) {
        if (strpos($lc, $b) !== false) {
            return true;
        }
    }
    return false;
};

// -------------------------------------------------------------------------
// POST: action=export_csv → stream a CSV of the (filtered) recent rows.
// CSRF skipped — internal mock page.
// -------------------------------------------------------------------------
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST'
    && (($_POST['action'] ?? '') === 'export_csv')) {
    $tabPost = (string)($_POST['tab'] ?? 'all');
    $rangePost = (string)($_POST['range'] ?? 'all');
    if (!in_array($rangePost, $validRanges, true)) {
        $rangePost = 'all';
    }
    [$rangeFrag, $rangeParams] = $rangeWhere($rangePost);

    $sql = "SELECT p.id, p.drug, p.dosage, p.note, p.indication, p.date_added,
                   pd.fname AS pat_fname, pd.lname AS pat_lname, pd.pubpid,
                   u.fname AS prov_fname, u.lname AS prov_lname,
                   u.title AS prov_title, u.username AS prov_username
              FROM prescriptions p
         LEFT JOIN patient_data pd ON pd.pid = p.patient_id
         LEFT JOIN users u         ON u.id  = p.provider_id
             WHERE 1=1 $rangeFrag
          ORDER BY p.date_added DESC
             LIMIT 500";
    $rs = sqlStatement($sql, $rangeParams);

    $stamp = date('Ymd_His');
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="prescriptions_' . $stamp . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, [
        'Date', 'Patient', 'MRN', 'Drug', 'Dosage', 'Note',
        'Indication', 'Provider', 'Tag',
    ]);
    while ($r = sqlFetchArray($rs)) {
        $drug = (string)($r['drug'] ?? '');
        $tag = '';
        if ($isControlled($drug)) {
            $tag = 'Controlled';
        } elseif ($isBrand($drug)) {
            $tag = 'Brand';
        }
        // Honor the tab filter on export.
        if ($tabPost === 'controlled' && $tag !== 'Controlled') { continue; }
        if ($tabPost === 'brand'      && $tag !== 'Brand')      { continue; }

        $provDisplay = cp_format_provider_name([
            'fname'    => $r['prov_fname'] ?? '',
            'lname'    => $r['prov_lname'] ?? '',
            'title'    => $r['prov_title'] ?? '',
            'username' => $r['prov_username'] ?? '',
        ]);
        $patName = trim((string)($r['pat_fname'] ?? '') . ' '
                       . (string)($r['pat_lname'] ?? ''));
        fputcsv($out, [
            (string)($r['date_added'] ?? ''),
            $patName,
            (string)($r['pubpid'] ?? ''),
            $drug,
            (string)($r['dosage'] ?? ''),
            (string)($r['note'] ?? ''),
            (string)($r['indication'] ?? ''),
            $provDisplay,
            $tag,
        ]);
    }
    fclose($out);
    exit;
}

// -------------------------------------------------------------------------
// Tab filter for the Recent Prescriptions panel.
// -------------------------------------------------------------------------
$validTabs = ['all', 'controlled', 'brand', 'recent'];
$tab = (string)($_GET['tab'] ?? 'all');
if (!in_array($tab, $validTabs, true)) {
    $tab = 'all';
}

// -------------------------------------------------------------------------
// KPI queries — all scoped to the chosen range.
// -------------------------------------------------------------------------
[$rangeFrag, $rangeParams] = $rangeWhere($range);

$kpiTotal = (int)((sqlQuery(
    "SELECT COUNT(*) AS c FROM prescriptions p WHERE 1=1 $rangeFrag",
    $rangeParams
) ?: ['c' => 0])['c'] ?? 0);

// Encounters in the same range, for "avg per visit".
$encParams = [];
$encWhere = '1=1';
$b = $rangeBounds[$range] ?? null;
if ($b !== null) {
    $encWhere .= ' AND fe.date >= ? AND fe.date < ?';
    $encParams = [$b[0], $b[1]];
}
$kpiEnc = (int)((sqlQuery(
    "SELECT COUNT(*) AS c FROM form_encounter fe WHERE $encWhere",
    $encParams
) ?: ['c' => 0])['c'] ?? 0);

$kpiAvgPerVisit = $kpiEnc > 0
    ? round($kpiTotal / $kpiEnc, 1)
    : 0.0;

// Pull every drug name in range (cheap; small fixture) so we can run
// classifier counts in PHP for KPI tiles + Top 12 + tagging.
$allInRange = [];
$rs = sqlStatement(
    "SELECT p.drug FROM prescriptions p WHERE 1=1 $rangeFrag",
    $rangeParams
);
while ($r = sqlFetchArray($rs)) {
    $allInRange[] = (string)($r['drug'] ?? '');
}

$ctlCount = 0;
$brandCount = 0;
$genericCount = 0;
foreach ($allInRange as $d) {
    if ($d === '') { continue; }
    if ($isControlled($d)) {
        $ctlCount++;
        // Controlled drugs are typically also branded; we still count
        // them under Controlled (red) and exclude from the generic/brand
        // mix to keep the tiles non-overlapping.
        continue;
    }
    if ($isBrand($d)) {
        $brandCount++;
    } else {
        $genericCount++;
    }
}
$nonControlled = $brandCount + $genericCount;
$kpiGenericPct = $nonControlled > 0
    ? (int)round($genericCount / $nonControlled * 100)
    : 0;
$kpiBrandPct = $nonControlled > 0
    ? (int)round($brandCount / $nonControlled * 100)
    : 0;

// -------------------------------------------------------------------------
// Top 12 Medications — by Rx volume, scoped to range.
// Bar width = c / max(c) * 100.
// -------------------------------------------------------------------------
$topMeds = [];
$rs = sqlStatement(
    "SELECT p.drug, COUNT(*) AS c
       FROM prescriptions p
      WHERE 1=1 $rangeFrag AND p.drug IS NOT NULL AND p.drug <> ''
   GROUP BY p.drug
   ORDER BY c DESC, p.drug ASC
      LIMIT 12",
    $rangeParams
);
while ($r = sqlFetchArray($rs)) {
    $topMeds[] = [
        'drug' => (string)$r['drug'],
        'c'    => (int)$r['c'],
    ];
}
$topMax = $topMeds !== [] ? max(array_column($topMeds, 'c')) : 1;

// "Indication" hint: pick the most common indication observed for this
// drug in the database (small fixture, cheap). Falls back to '—'.
$indicationFor = static function (string $drug): string {
    $row = sqlQuery(
        "SELECT indication FROM prescriptions
          WHERE drug = ? AND indication IS NOT NULL AND indication <> ''
          ORDER BY date_added DESC LIMIT 1",
        [$drug]
    );
    $ind = (string)($row['indication'] ?? '');
    if ($ind === '') {
        return '';
    }
    // Trim to first phrase before " - " for compact display.
    $parts = preg_split('/\s+-\s+/', $ind, 2);
    return trim((string)($parts[0] ?? $ind));
};

// -------------------------------------------------------------------------
// Recent Prescriptions — 12 rows, filtered by tab.
// -------------------------------------------------------------------------
$recent = [];
$rs = sqlStatement(
    "SELECT p.id, p.drug, p.dosage, p.note, p.indication, p.date_added,
            pd.fname AS pat_fname, pd.lname AS pat_lname,
            u.fname  AS prov_fname, u.lname  AS prov_lname,
            u.title  AS prov_title, u.username AS prov_username
       FROM prescriptions p
  LEFT JOIN patient_data pd ON pd.pid = p.patient_id
  LEFT JOIN users u         ON u.id  = p.provider_id
      WHERE 1=1 $rangeFrag
   ORDER BY p.date_added DESC
      LIMIT 200",
    $rangeParams
);
while ($r = sqlFetchArray($rs)) {
    $drug = (string)($r['drug'] ?? '');
    $tagText = '';
    $tagTone = '';
    if ($isControlled($drug)) {
        $tagText = 'Ctl';
        $tagTone = 'danger';
    } elseif ($isBrand($drug)) {
        $tagText = 'Brand';
        $tagTone = 'warn';
    }
    if ($tab === 'controlled' && $tagText !== 'Ctl')   { continue; }
    if ($tab === 'brand'      && $tagText !== 'Brand') { continue; }
    // "recent" = last 30 days from now.
    if ($tab === 'recent') {
        $when = strtotime((string)($r['date_added'] ?? '')) ?: 0;
        if ($when < (time() - 30 * 86400)) { continue; }
    }
    $recent[] = $r + [
        '_tagText' => $tagText,
        '_tagTone' => $tagTone,
    ];
    if (count($recent) >= 12) { break; }
}

// -------------------------------------------------------------------------
// Reports left sidebar.
// -------------------------------------------------------------------------
$sidebar = [
    'CLINICAL' => [
        ['Patient List',          false],
        ['Prescriptions',         true],
        ['Lab Trends',            false],
        ['Quality Measures',      false],
        ['Immunizations',         false],
        ['Encounters',            false],
    ],
    'FINANCIAL' => [
        ['Daily Cash',            false],
        ['Aging',                 false],
        ['Payer Mix',             false],
        ['Collections',           false],
    ],
    'OPERATIONS' => [
        ['Visit Volume',          false],
        ['Provider Productivity', false],
        ['No-shows',              false],
    ],
    'ELECTRONIC' => [
        ['Submissions',           false],
        ['CCDA Exports',          false],
        ['HIE Sync',              false],
        ['Public Health',         false],
    ],
];

// -------------------------------------------------------------------------
// Helpers for rendering the tab links / range links while preserving
// the other GET params.
// -------------------------------------------------------------------------
$selfPath = $_SERVER['PHP_SELF'] ?? '/interface/reports/copilot_prescription_report.php';

$buildUrl = static function (array $overrides) use ($selfPath, $range, $tab): string {
    $params = ['range' => $range, 'tab' => $tab];
    foreach ($overrides as $k => $v) {
        $params[$k] = $v;
    }
    return $selfPath . '?' . http_build_query($params);
};

?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo xlt('Prescription Report'); ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/public/copilot-archetype.css">
<style>
  .cp-pagehead .dot { color: #8A91A1; font-size: 14px; padding: 0 4px; }
  .cp-pagehead .meta-light { color: #8A91A1; font-size: 12px; line-height: 1; }
  .cp-pagehead .range-form { display: inline-flex; gap: 6px; align-items: center; }
  .cp-pagehead .range-form select {
    font: 500 12px Inter, sans-serif;
    color: #4F5763;
    background: #FFFFFF;
    border: 1px solid #E4E5E8;
    border-radius: 6px;
    padding: 3px 8px;
  }

  /* Reports left sidebar */
  .cp-rep-side {
    flex: 0 0 200px;
    background: #FFFFFF;
    border-right: 1px solid #E4E5E8;
    padding: 18px 0 24px;
    overflow-y: auto;
  }
  .cp-rep-side .header {
    font-size: 11px; font-weight: 700;
    color: #8A91A1; letter-spacing: 0.7px;
    padding: 0 20px 12px;
  }
  .cp-rep-side .grp { margin-bottom: 14px; }
  .cp-rep-side .grp .lbl {
    font-size: 11px; font-weight: 500;
    color: #8A91A1;
    padding: 6px 20px 4px;
  }
  .cp-rep-side .item {
    display: block;
    padding: 7px 20px;
    font-size: 12px; font-weight: 500;
    color: #4F5763;
    text-decoration: none;
    line-height: 1.3;
    position: relative;
  }
  .cp-rep-side .item:hover { background: #F5F6F7; color: #0D1B2A; }
  .cp-rep-side .item.active {
    color: #008C8C; font-weight: 600;
    background: rgba(0,140,140,0.08);
  }
  .cp-rep-side .item.active::before {
    content: ''; position: absolute;
    left: 0; top: 0; bottom: 0; width: 3px; background: #008C8C;
  }

  /* 5-col KPI */
  .cp-kpi-5 { grid-template-columns: repeat(5, 1fr); }
  .cp-kpi .val.red    { color: #D93838; }
  .cp-kpi .val.green  { color: #1F8C4D; }
  .cp-kpi .val.orange { color: #FA8C33; }

  /* 2-col main grid */
  .pr-grid {
    display: grid;
    grid-template-columns: 380px 1fr;
    gap: 16px;
  }

  /* Top medications panel */
  .pr-meds {
    background: #FFFFFF;
    border: 1px solid #E4E5E8;
    border-radius: 12px;
    padding: 18px 20px 20px;
  }
  .pr-meds .head {
    font-size: 11px; font-weight: 600;
    color: #8A91A1; letter-spacing: 0.6px;
    line-height: 1.2;
  }
  .pr-meds .sub {
    font-size: 11px; color: #8A91A1;
    margin-top: 4px; margin-bottom: 14px;
    line-height: 1.2;
  }
  .pr-meds ol {
    list-style: none;
    margin: 0; padding: 0;
    display: flex; flex-direction: column; gap: 10px;
  }
  .pr-meds li {
    display: grid;
    grid-template-columns: 18px 1fr auto;
    column-gap: 10px;
    align-items: start;
  }
  .pr-meds .num {
    font-size: 12px; color: #8A91A1;
    font-weight: 500;
    line-height: 1.4;
    text-align: right;
  }
  .pr-meds .body {
    display: flex; flex-direction: column; gap: 4px;
    min-width: 0;
  }
  .pr-meds .name {
    font-size: 13px; font-weight: 600; color: #0D1B2A;
    line-height: 1.2;
  }
  .pr-meds .ind {
    font-size: 11px; color: #8A91A1;
    line-height: 1.2;
  }
  .pr-meds .bar {
    margin-top: 4px;
    height: 3px;
    background: #EEF1F3;
    border-radius: 2px;
    overflow: hidden;
  }
  .pr-meds .bar > i {
    display: block;
    height: 100%;
    background: #008C8C;
    border-radius: 2px;
  }
  .pr-meds .ct {
    font-size: 14px; font-weight: 700; color: #0D1B2A;
    line-height: 1.2;
    align-self: start;
  }
  .pr-meds .empty {
    font-size: 12px; color: #8A91A1; font-style: italic;
  }

  /* Recent prescriptions panel */
  .pr-recent {
    background: #FFFFFF;
    border: 1px solid #E4E5E8;
    border-radius: 12px;
    padding: 18px 20px 8px;
  }
  .pr-recent .head {
    font-size: 12px; font-weight: 600;
    color: #4F5763; letter-spacing: 0.4px;
    margin-bottom: 14px;
  }
  .pr-recent .pills {
    display: flex; gap: 18px;
    border-bottom: 1px solid #E4E5E8;
    padding-bottom: 10px;
    margin-bottom: 6px;
  }
  .pr-recent .pills a {
    background: transparent; border: 0;
    font-size: 12px; font-weight: 500;
    color: #8A91A1;
    padding: 0 0 6px;
    line-height: 1;
    position: relative;
    text-decoration: none;
  }
  .pr-recent .pills a.active {
    color: #008C8C; font-weight: 600;
  }
  .pr-recent .pills a.active::after {
    content: '';
    position: absolute;
    left: 0; right: 0; bottom: -11px;
    height: 2px; background: #008C8C;
  }
  .pr-recent table {
    width: 100%;
    border-collapse: collapse;
    font-size: 12px;
  }
  .pr-recent td {
    padding: 11px 0;
    border-top: 1px solid #F0F1F3;
    vertical-align: middle;
    line-height: 1.3;
  }
  .pr-recent tr:first-child td { border-top: 0; }
  .pr-recent .c-time { color: #8A91A1; font-weight: 500; width: 80px; }
  .pr-recent .c-pat  { color: #0D1B2A; font-weight: 600; width: 120px; padding-right: 12px; }
  .pr-recent .c-drug { color: #0D1B2A; font-weight: 500; width: 160px; padding-right: 12px; }
  .pr-recent .c-note { color: #8A91A1; padding-right: 12px; }
  .pr-recent .c-prov { color: #4F5763; width: 110px; padding-right: 12px; }
  .pr-recent .c-tag  { width: 50px; text-align: right; }
  .pr-recent .tag {
    display: inline-block;
    border-radius: 999px;
    padding: 2px 8px;
    font-size: 10px; font-weight: 600;
    line-height: 1.4;
  }
  .pr-recent .tag.danger { background: #FCE7E7; color: #D93838; }
  .pr-recent .tag.warn   { background: #FFF8EC; color: #FA8C33; }
  .pr-recent .empty {
    font-size: 12px; color: #8A91A1; font-style: italic;
    padding: 18px 0;
  }

  /* Inline form so the Run/Export buttons can submit a real form */
  .cp-pagehead form.hdr-form { display: contents; }
</style>
</head>
<body class="cp-arch">

<div class="cp-shell">
  <aside class="cp-rep-side">
    <div class="header"><?php echo xlt('REPORTS'); ?></div>
    <?php foreach ($sidebar as $cat => $items): ?>
      <div class="grp">
        <div class="lbl"><?php echo text(ucfirst(strtolower((string)$cat))); ?></div>
        <?php foreach ($items as [$nm, $act]): ?>
          <a href="#" class="item<?php echo $act ? ' active' : ''; ?>"><?php echo text($nm); ?></a>
        <?php endforeach; ?>
      </div>
    <?php endforeach; ?>
  </aside>

  <div style="flex:1 1 auto; display:flex; flex-direction:column; min-width:0;">
    <header class="cp-pagehead">
      <div class="info">
        <div style="display:flex; align-items:center; gap:8px;">
          <span class="title"><?php echo xlt('Prescription Report'); ?></span>
          <span class="meta-light">
            <?php
              echo text($rangeLabels[$range])
                 . ' · ' . text(number_format($kpiTotal)) . ' Rx written'
                 . ' · ' . text((string)$ctlCount) . ' controlled substances';
            ?>
          </span>
        </div>
        <form method="get" action="<?php echo attr($selfPath); ?>" class="range-form">
          <label for="range" class="meta-light"><?php echo xlt('Date range'); ?>:</label>
          <select id="range" name="range" onchange="this.form.submit()">
            <?php foreach ($validRanges as $rk): ?>
              <option value="<?php echo attr($rk); ?>"
                <?php echo $rk === $range ? 'selected' : ''; ?>>
                <?php echo text($rangeLabels[$rk]); ?>
              </option>
            <?php endforeach; ?>
          </select>
          <input type="hidden" name="tab" value="<?php echo attr($tab); ?>">
        </form>
      </div>

      <form method="post" action="<?php echo attr($selfPath); ?>"
            style="display:inline;">
        <input type="hidden" name="action" value="export_csv">
        <input type="hidden" name="range" value="<?php echo attr($range); ?>">
        <input type="hidden" name="tab"   value="<?php echo attr($tab); ?>">
        <button type="submit" class="cp-btn ghost">
          ⤓ <?php echo xlt('Export CSV'); ?>
        </button>
      </form>

      <form method="get" action="<?php echo attr($selfPath); ?>"
            style="display:inline;">
        <input type="hidden" name="range" value="<?php echo attr($range); ?>">
        <input type="hidden" name="tab"   value="<?php echo attr($tab); ?>">
        <button type="submit" class="cp-btn primary">
          <?php echo xlt('Run'); ?>
        </button>
      </form>
    </header>

    <main class="cp-content tight">

      <div class="cp-kpi-grid cp-kpi-5">
        <div class="cp-kpi">
          <span class="lbl"><?php echo xlt('Total Rx'); ?></span>
          <span class="val"><?php echo text(number_format($kpiTotal)); ?></span>
        </div>
        <div class="cp-kpi">
          <span class="lbl"><?php echo xlt('Avg per visit'); ?></span>
          <span class="val">
            <?php echo text($kpiEnc > 0 ? (string)$kpiAvgPerVisit : '—'); ?>
          </span>
        </div>
        <div class="cp-kpi">
          <span class="lbl"><?php echo xlt('Controlled'); ?></span>
          <span class="val red"><?php echo text((string)$ctlCount); ?></span>
        </div>
        <div class="cp-kpi">
          <span class="lbl"><?php echo xlt('Generic %'); ?></span>
          <span class="val green">
            <?php echo text($nonControlled > 0 ? $kpiGenericPct . '%' : '—'); ?>
          </span>
        </div>
        <div class="cp-kpi">
          <span class="lbl"><?php echo xlt('Brand-name %'); ?></span>
          <span class="val orange">
            <?php echo text($nonControlled > 0 ? $kpiBrandPct . '%' : '—'); ?>
          </span>
        </div>
      </div>

      <div class="pr-grid">
        <section class="pr-meds">
          <div class="head"><?php echo xlt('TOP 12 MEDICATIONS'); ?></div>
          <div class="sub">
            <?php echo xlt('By Rx volume') . ', ' . text($rangeLabels[$range]); ?>
          </div>
          <?php if ($topMeds === []): ?>
            <div class="empty"><?php echo xlt('No prescriptions in range.'); ?></div>
          <?php else: ?>
          <ol>
            <?php foreach ($topMeds as $i => $m): ?>
              <?php
                $w = $topMax > 0 ? (int)round($m['c'] / $topMax * 100) : 0;
                $ind = $indicationFor($m['drug']);
              ?>
              <li>
                <span class="num"><?php echo text(($i + 1) . '.'); ?></span>
                <div class="body">
                  <span class="name"><?php echo text($m['drug']); ?></span>
                  <?php if ($ind !== ''): ?>
                    <span class="ind"><?php echo text($ind); ?></span>
                  <?php endif; ?>
                  <span class="bar"><i style="width: <?php echo (int)$w; ?>%;"></i></span>
                </div>
                <span class="ct"><?php echo text(number_format($m['c'])); ?></span>
              </li>
            <?php endforeach; ?>
          </ol>
          <?php endif; ?>
        </section>

        <section class="pr-recent">
          <div class="head"><?php echo xlt('RECENT PRESCRIPTIONS'); ?></div>
          <div class="pills">
            <?php
              $tabs = [
                  'all'        => xl('All'),
                  'controlled' => xl('Controlled'),
                  'brand'      => xl('Brand'),
                  'recent'     => xl('Recent'),
              ];
              foreach ($tabs as $tk => $tlabel):
            ?>
              <a href="<?php echo attr($buildUrl(['tab' => $tk])); ?>"
                 class="<?php echo $tab === $tk ? 'active' : ''; ?>">
                <?php echo text($tlabel); ?>
              </a>
            <?php endforeach; ?>
          </div>
          <?php if ($recent === []): ?>
            <div class="empty"><?php echo xlt('No prescriptions match this filter.'); ?></div>
          <?php else: ?>
          <table>
            <tbody>
              <?php foreach ($recent as $r): ?>
                <?php
                  $patName = trim((string)($r['pat_fname'] ?? '') . ' '
                                . (string)($r['pat_lname'] ?? ''));
                  if ($patName === '') { $patName = '—'; }
                  $when = strtotime((string)($r['date_added'] ?? '')) ?: 0;
                  $tStr = $when > 0 ? date('m/d H:i', $when) : '—';
                  $provDisplay = cp_format_provider_name([
                      'fname'    => $r['prov_fname'] ?? '',
                      'lname'    => $r['prov_lname'] ?? '',
                      'title'    => $r['prov_title'] ?? '',
                      'username' => $r['prov_username'] ?? '',
                  ]);
                  $note = (string)($r['note'] ?? '');
                  if ($note === '') {
                      $note = (string)($r['indication'] ?? '');
                  }
                  if (mb_strlen($note) > 60) {
                      $note = mb_substr($note, 0, 59) . '…';
                  }
                ?>
                <tr>
                  <td class="c-time"><?php echo text($tStr); ?></td>
                  <td class="c-pat"><?php echo text($patName); ?></td>
                  <td class="c-drug"><?php echo text((string)($r['drug'] ?? '')); ?></td>
                  <td class="c-note"><?php echo text($note); ?></td>
                  <td class="c-prov"><?php echo text($provDisplay); ?></td>
                  <td class="c-tag">
                    <?php if (($r['_tagText'] ?? '') !== ''): ?>
                      <span class="tag <?php echo attr((string)$r['_tagTone']); ?>">
                        <?php echo text((string)$r['_tagText']); ?>
                      </span>
                    <?php endif; ?>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
          <?php endif; ?>
        </section>
      </div>

    </main>
  </div>
</div>

</body>
</html>
