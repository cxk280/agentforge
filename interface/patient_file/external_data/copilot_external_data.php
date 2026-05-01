<?php

/**
 * External Data — implements Screen 19 of the AgentForge mockups.
 *
 * Renders the "External Data" navtab content: source connection cards
 * (HIE, LabCorp, Imaging, Surescripts) and a "Recent Imports" feed of
 * incoming records with reconciliation status.
 *
 * @package OpenEMR
 * @author  AgentForge / Claude Code
 * @license https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

require_once(__DIR__ . "/../../globals.php");

$sources = [
    [
        'name'   => 'Texas HIE — CommonWell',
        'icon'   => '🌐',
        'tone'   => 'good',
        'status' => 'Connected',
        'status_tone' => 'good',
        'sub'    => 'Today 08:14 AM',
        'count'  => 24,
    ],
    [
        'name'   => 'LabCorp Direct Connect',
        'icon'   => '🧪',
        'tone'   => 'info',
        'status' => 'Connected',
        'status_tone' => 'good',
        'sub'    => 'Today 08:14 AM',
        'count'  => 18,
    ],
    [
        'name'   => 'Riverside Imaging API',
        'icon'   => '🩻',
        'tone'   => 'violet',
        'status' => 'Connected',
        'status_tone' => 'good',
        'sub'    => 'Yesterday',
        'count'  => 5,
    ],
    [
        'name'   => 'Surescripts Rx History',
        'icon'   => '💊',
        'tone'   => 'warn',
        'status' => 'Needs review',
        'status_tone' => 'warn',
        'sub'    => '1 record awaiting reconcile',
        'count'  => 1,
    ],
];

// imports: each row has icon, icon_tone, title, type, source, date, fields, status, action
$imports = [
    ['icon'=>'🌐','tone'=>'good',  'title'=>'ED Visit — Riverside General Hospital',         'type'=>'Encounter Summary',  'src'=>'Texas HIE',     'date'=>'Apr 9, 2026',  'fields'=>12,'status'=>'Reconciled','status_tone'=>'good','action'=>'View'],
    ['icon'=>'🧪','tone'=>'info',  'title'=>'Comprehensive Metabolic Panel + CBC',          'type'=>'Lab Result',         'src'=>'LabCorp Direct','date'=>'Apr 12, 2026', 'fields'=>18,'status'=>'Reconciled','status_tone'=>'good','action'=>'View'],
    ['icon'=>'💊','tone'=>'warn',  'title'=>'External Rx: Atorvastatin 20mg → 40mg (Walgreens)','type'=>'Medication History','src'=>'Surescripts','date'=>'Apr 8, 2026',  'fields'=>1, 'status'=>'Needs review','status_tone'=>'warn','action'=>'Reconcile'],
    ['icon'=>'🌐','tone'=>'good',  'title'=>'DEXA scan — South Austin Imaging',             'type'=>'Imaging Report',     'src'=>'Texas HIE',     'date'=>'Mar 22, 2026', 'fields'=>4, 'status'=>'Reconciled','status_tone'=>'good','action'=>'View'],
    ['icon'=>'🩻','tone'=>'violet','title'=>'Bilateral knee X-Ray report',                  'type'=>'Radiology',          'src'=>'Riverside Imaging','date'=>'Feb 18, 2026', 'fields'=>6, 'status'=>'Reconciled','status_tone'=>'good','action'=>'View'],
    ['icon'=>'🌐','tone'=>'good',  'title'=>'Influenza vaccine — Riverside Pharmacy',       'type'=>'Immunization',       'src'=>'Texas HIE',     'date'=>'Oct 15, 2025', 'fields'=>3, 'status'=>'Reconciled','status_tone'=>'good','action'=>'View'],
];
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo xlt('External Data'); ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
  *, *::before, *::after { box-sizing: border-box; }
  html, body { margin: 0; padding: 0; }
  body {
    font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', system-ui, sans-serif;
    background: #F5F6F7;
    color: #0D1B2A;
    -webkit-font-smoothing: antialiased;
    -moz-osx-font-smoothing: grayscale;
  }
  button { font-family: inherit; cursor: pointer; }

  /* ── Header ──────────────────────────────────────────────────────────── */
  .cp-ext-head {
    background: #FFFFFF;
    border: 1px solid #E4E5E8;
    height: 60px;
    padding: 0 24px;
    display: flex; align-items: center; gap: 12px;
  }
  .cp-ext-title { font-size: 16px; font-weight: 700; color: #0D1B2A; line-height: 1; }
  .cp-ext-bullet { color: #8A91A1; font-size: 14px; line-height: 1; }
  .cp-ext-meta { color: #8A91A1; font-size: 12px; line-height: 1; }
  .cp-ext-spacer { flex: 1; }

  .cp-pill {
    background: #FFFFFF;
    border: 1px solid #E4E5E8;
    border-radius: 999px;
    padding: 6px 12px;
    font-size: 12px;
    color: #4F5763;
    line-height: 1;
    display: inline-flex; align-items: center; gap: 6px;
    font-weight: 500;
  }
  .cp-pill:hover { background: #F5F6F7; }

  .cp-ext-pri {
    background: #008C8C;
    color: #FFFFFF;
    border: none;
    border-radius: 999px;
    padding: 7px 16px;
    font-size: 12px; font-weight: 600;
    line-height: 1;
    display: inline-flex; align-items: center; gap: 6px;
  }
  .cp-ext-pri:hover { background: #00787A; }

  /* ── Body ────────────────────────────────────────────────────────────── */
  .cp-ext-body { padding: 20px 24px 32px; display: flex; flex-direction: column; gap: 16px; }
  .cp-src-grid {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 16px;
  }
  .cp-src-card {
    background: #FFFFFF;
    border: 1px solid #E4E5E8;
    border-radius: 12px;
    padding: 16px;
    display: flex; flex-direction: column; gap: 8px;
  }
  .cp-src-top { display: flex; align-items: center; gap: 10px; }
  .cp-src-ico {
    width: 36px; height: 36px;
    border-radius: 8px;
    display: inline-flex; align-items: center; justify-content: center;
    font-size: 16px;
    flex: 0 0 auto;
  }
  .cp-src-ico.good   { background: rgba(51, 166, 102, 0.14); }
  .cp-src-ico.info   { background: rgba(71, 133, 217, 0.14); }
  .cp-src-ico.violet { background: rgba(133, 97, 199, 0.14); }
  .cp-src-ico.warn   { background: rgba(250, 140, 51, 0.14); }
  .cp-src-name { font-size: 13px; font-weight: 600; color: #0D1B2A; line-height: 1.2; }
  .cp-src-status {
    display: inline-flex; align-items: center; gap: 4px;
    font-size: 11px; font-weight: 500;
    line-height: 1;
    margin-top: 1px;
  }
  .cp-src-status.good { color: #33A666; }
  .cp-src-status.warn { color: #FA8C33; }
  .cp-src-status .dot {
    width: 6px; height: 6px;
    border-radius: 50%;
    display: inline-block;
  }
  .cp-src-status.good .dot { background: #33A666; }
  .cp-src-status.warn .dot { background: #FA8C33; }
  .cp-src-sub { font-size: 11px; color: #8A91A1; line-height: 1; }
  .cp-src-stats { display: flex; align-items: baseline; gap: 4px; }
  .cp-src-stats .num { font-size: 18px; font-weight: 700; color: #0D1B2A; line-height: 1; }
  .cp-src-stats .lbl { font-size: 12px; color: #8A91A1; }

  /* Recent imports card */
  .cp-imp-card {
    background: #FFFFFF;
    border: 1px solid #E4E5E8;
    border-radius: 12px;
    overflow: hidden;
  }
  .cp-imp-head {
    height: 48px;
    padding: 0 22px;
    border-bottom: 1px solid #E4E5E8;
    display: flex; align-items: center; gap: 12px;
  }
  .cp-imp-title { font-size: 13px; font-weight: 600; color: #0D1B2A; line-height: 1; }
  .cp-imp-spacer { flex: 1; }
  .cp-imp-link {
    font-size: 11px; font-weight: 500;
    color: #008C8C;
    line-height: 1;
    cursor: pointer;
  }
  .cp-imp-row {
    height: 72px;
    padding: 0 22px;
    border-bottom: 0.5px solid #E4E5E8;
    display: flex; align-items: center; gap: 16px;
  }
  .cp-imp-row:last-child { border-bottom: none; }
  .cp-imp-ico {
    width: 40px; height: 40px;
    border-radius: 8px;
    display: inline-flex; align-items: center; justify-content: center;
    font-size: 18px;
    flex: 0 0 auto;
  }
  .cp-imp-ico.good   { background: rgba(51, 166, 102, 0.14); }
  .cp-imp-ico.info   { background: rgba(71, 133, 217, 0.14); }
  .cp-imp-ico.violet { background: rgba(133, 97, 199, 0.14); }
  .cp-imp-ico.warn   { background: rgba(250, 140, 51, 0.14); }
  .cp-imp-info { display: flex; flex-direction: column; gap: 3px; min-width: 0; }
  .cp-imp-titlerow { display: inline-flex; align-items: center; gap: 8px; }
  .cp-imp-row-title { font-size: 13px; font-weight: 600; color: #0D1B2A; line-height: 1.2; }
  .cp-type-pill {
    background: #F5F6F7;
    border-radius: 4px;
    padding: 1px 6px;
    font-size: 10px; font-weight: 500;
    color: #4F5763;
    line-height: 1.4;
  }
  .cp-imp-sub { font-size: 11px; color: #8A91A1; line-height: 1.2; }
  .cp-imp-spacer-row { flex: 1; }
  .cp-status-pill {
    border-radius: 999px;
    padding: 4px 10px;
    font-size: 11px; font-weight: 600;
    line-height: 1;
    display: inline-flex; align-items: center;
  }
  .cp-status-pill.good {
    background: rgba(51, 166, 102, 0.14);
    color: #33A666;
  }
  .cp-status-pill.warn {
    background: rgba(250, 140, 51, 0.14);
    color: #FA8C33;
  }
  .cp-action {
    border-radius: 999px;
    padding: 6px 14px;
    font-size: 12px; font-weight: 500;
    line-height: 1;
    border: 1px solid transparent;
  }
  .cp-action.secondary {
    background: #FFFFFF;
    color: #0D1B2A;
    border-color: #E4E5E8;
  }
  .cp-action.secondary:hover { background: #F5F6F7; }
  .cp-action.primary {
    background: #008C8C;
    color: #FFFFFF;
    font-weight: 600;
  }
  .cp-action.primary:hover { background: #00787A; }
</style>
</head>
<body>

<header class="cp-ext-head">
  <div class="cp-ext-title"><?php echo xlt('External Data Sources'); ?></div>
  <div class="cp-ext-bullet">•</div>
  <div class="cp-ext-meta">3 connected • 1 pending review</div>
  <div class="cp-ext-spacer"></div>
  <button type="button" class="cp-pill"><span>⟳</span><span><?php echo xlt('Sync sources'); ?></span></button>
  <button type="button" class="cp-ext-pri"><span>⬆</span><span><?php echo xlt('Import CCDA'); ?></span></button>
</header>

<main class="cp-ext-body">
  <section class="cp-src-grid">
    <?php foreach ($sources as $s): ?>
      <article class="cp-src-card">
        <div class="cp-src-top">
          <span class="cp-src-ico <?php echo attr($s['tone']); ?>"><?php echo text($s['icon']); ?></span>
          <div>
            <div class="cp-src-name"><?php echo text($s['name']); ?></div>
            <div class="cp-src-status <?php echo attr($s['status_tone']); ?>">
              <span class="dot"></span>
              <span><?php echo text($s['status']); ?></span>
            </div>
          </div>
        </div>
        <div class="cp-src-sub"><?php echo text($s['sub']); ?></div>
        <div class="cp-src-stats">
          <span class="num"><?php echo text((string)$s['count']); ?></span>
          <span class="lbl"><?php echo xlt('records'); ?></span>
        </div>
      </article>
    <?php endforeach; ?>
  </section>

  <section class="cp-imp-card">
    <header class="cp-imp-head">
      <div class="cp-imp-title"><?php echo xlt('Recent Imports'); ?></div>
      <div class="cp-imp-spacer"></div>
      <span class="cp-imp-link"><?php echo xlt('View all'); ?> →</span>
    </header>
    <?php foreach ($imports as $im): ?>
      <div class="cp-imp-row">
        <span class="cp-imp-ico <?php echo attr($im['tone']); ?>"><?php echo text($im['icon']); ?></span>
        <div class="cp-imp-info">
          <div class="cp-imp-titlerow">
            <span class="cp-imp-row-title"><?php echo text($im['title']); ?></span>
            <span class="cp-type-pill"><?php echo text($im['type']); ?></span>
          </div>
          <div class="cp-imp-sub">
            <?php echo text($im['src']); ?> • <?php echo text($im['date']); ?> • <?php echo text($im['fields']); ?> fields
          </div>
        </div>
        <div class="cp-imp-spacer-row"></div>
        <span class="cp-status-pill <?php echo attr($im['status_tone']); ?>">
          <?php echo $im['status_tone'] === 'good' ? '✓ ' : ''; ?><?php echo text($im['status']); ?>
        </span>
        <button type="button" class="cp-action <?php echo $im['status_tone'] === 'warn' ? 'primary' : 'secondary'; ?>">
          <?php echo text($im['action']); ?><?php echo $im['action'] === 'Reconcile' ? ' →' : ''; ?>
        </button>
      </div>
    <?php endforeach; ?>
  </section>
</main>

</body>
</html>
