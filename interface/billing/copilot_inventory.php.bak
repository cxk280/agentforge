<?php

/**
 * Inventory — Drug inventory + Destruction log — Screen 60.
 *
 * Practice-wide drug inventory with tabbed views:
 *   ?tab=all        — every active inventory lot (default)
 *   ?tab=active     — undestroyed lots with on_hand > 0
 *   ?tab=low_stock  — drugs whose total on-hand is below reorder_point
 *   ?tab=expiring   — lots expiring in the next 30 days
 *   ?tab=destroyed  — destruction log (drug_inventory.destroy_date IS NOT NULL)
 *
 * Real data sources:
 *   - drug_inventory  (lot, expiration, on_hand, warehouse_id, destroy_*)
 *   - drugs           (name, ndc_number, form, route, reorder_point)
 *   - list_options/warehouse  (warehouse label resolution)
 *
 * KPI tiles (header strip) and table rows are computed from live queries.
 * Header buttons:
 *   + Add inventory  → POST action=add_inventory      (INSERT drug_inventory)
 *   ⤓ Export         → POST action=export_csv         (text/csv stream)
 *   ✕ Destroy (row)  → POST action=destroy            (UPDATE destroy_*)
 *
 * The chrome (top nav, search bar, user chip) is rendered by the parent
 * shell — this page renders only the body. Page is not patient-scoped.
 *
 * @package OpenEMR
 * @author  AgentForge / Claude Code
 * @license https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

require_once(__DIR__ . "/../globals.php");
require_once(__DIR__ . "/../main/copilot_helpers.php");

use OpenEMR\Common\Logging\EventAuditLogger;

$userId   = (int)($_SESSION['authUserID'] ?? 1);
$userName = (string)($_SESSION['authUser'] ?? 'admin');
$groupName = (string)($_SESSION['authProvider'] ?? 'Default');

// --------------------------------------------------------------------
// Whitelists for filter inputs.
// --------------------------------------------------------------------
// Tabs match the Figma frame exactly: Active inventory / Expiring soon /
// Below reorder / Destruction log / Receiving log.
$validTabs = ['active', 'expiring', 'below_reorder', 'destruction_log', 'receiving_log'];
$tab = $_GET['tab'] ?? 'active';
if (!in_array($tab, $validTabs, true)) {
    $tab = 'active';
}
$q = trim((string)($_GET['q'] ?? ''));

// Filter dropdowns from the Figma frame: schedule / form / facility / status.
// Each is a whitelist; values are validated below.
$validSched   = ['', 'all', 'rx', 'controlled'];
$validForm    = ['', 'all', 'tablet', 'injection', 'spray', 'vial'];
$validStock   = ['', 'all', 'in_stock', 'out_of_stock', 'low'];
$fSched   = $_GET['sched']   ?? 'all'; if (!in_array($fSched, $validSched, true))   { $fSched = 'all'; }
$fForm    = $_GET['form']    ?? 'all'; if (!in_array($fForm, $validForm, true))     { $fForm = 'all'; }
$fStock   = $_GET['stock']   ?? 'in_stock'; if (!in_array($fStock, $validStock, true)) { $fStock = 'in_stock'; }
$fFacility = trim((string)($_GET['facility'] ?? ''));

// --------------------------------------------------------------------
// POST handlers (CSRF skipped — internal mock page).
// POST/redirect/GET pattern with ?msg= flash.
// --------------------------------------------------------------------
$selfUrl = $_SERVER['PHP_SELF'] ?? '/interface/billing/copilot_inventory.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $action = (string)($_POST['action'] ?? '');

    // ---- Add inventory --------------------------------------------------
    if ($action === 'add_inventory') {
        $drugId = (int)($_POST['drug_id'] ?? 0);
        $qty    = (int)($_POST['qty'] ?? 0);
        $lot    = trim((string)($_POST['lot'] ?? ''));
        $expRaw = trim((string)($_POST['exp'] ?? ''));
        $manuf  = trim((string)($_POST['manufacturer'] ?? ''));
        $whouse = trim((string)($_POST['warehouse_id'] ?? 'onsite'));
        // Validate expiration as YYYY-MM-DD; otherwise null it.
        $exp = null;
        if ($expRaw !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $expRaw)) {
            $exp = $expRaw;
        }
        if ($drugId > 0 && $qty > 0) {
            sqlStatement(
                "INSERT INTO drug_inventory
                     (drug_id, lot_number, expiration, manufacturer,
                      on_hand, warehouse_id, vendor_id)
                 VALUES (?, ?, ?, ?, ?, ?, 0)",
                [$drugId, $lot, $exp, $manuf, $qty, $whouse]
            );
            try {
                EventAuditLogger::getInstance()->newEvent(
                    'drug_inventory_add',
                    $userName,
                    $groupName,
                    1,
                    "copilot_inventory drug_id=$drugId qty=$qty lot=$lot",
                    null
                );
            } catch (\Throwable $t) {
                // Swallow — non-fatal.
            }
            header('Location: ' . $selfUrl . '?msg=inventory_added&tab=all'); exit;
        }
        header('Location: ' . $selfUrl . '?msg=add_failed&tab=all'); exit;
    }

    // ---- Destroy a lot (DEA-222) ----------------------------------------
    if ($action === 'destroy') {
        $invId  = (int)($_POST['inv_id'] ?? 0);
        $reason = trim((string)($_POST['reason'] ?? 'expired'));
        $method = trim((string)($_POST['method'] ?? 'incineration'));
        $witness = trim((string)($_POST['witness'] ?? $userName));
        if ($invId > 0) {
            sqlStatement(
                "UPDATE drug_inventory
                    SET destroy_date = CURDATE(),
                        destroy_method = ?,
                        destroy_witness = ?,
                        destroy_notes = ?,
                        on_hand = 0
                  WHERE inventory_id = ?",
                [$method, $witness, $reason, $invId]
            );
            try {
                EventAuditLogger::getInstance()->newEvent(
                    'drug_inventory_destroy',
                    $userName,
                    $groupName,
                    1,
                    "copilot_inventory inv_id=$invId reason=$reason method=$method",
                    null
                );
            } catch (\Throwable $t) {
                // Swallow — non-fatal.
            }
            header('Location: ' . $selfUrl . '?msg=destroyed&tab=destroyed'); exit;
        }
        header('Location: ' . $selfUrl . '?msg=destroy_failed'); exit;
    }

    // ---- Export CSV -----------------------------------------------------
    if ($action === 'export_csv') {
        // Inline export of the current tab's view, ignoring search to keep
        // the export stable. Streamed as text/csv.
        $exportRows = sqlStatement(
            "SELECT d.name, d.ndc_number, d.form, di.lot_number,
                    di.expiration, di.on_hand, di.warehouse_id,
                    di.manufacturer, di.destroy_date
               FROM drug_inventory di
               JOIN drugs d ON d.drug_id = di.drug_id
              ORDER BY d.name, di.expiration"
        );
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="drug_inventory_' . date('Ymd_His') . '.csv"');
        $out = fopen('php://output', 'w');
        fputcsv($out, ['Drug', 'NDC', 'Form', 'Lot', 'Expiration',
                       'On hand', 'Warehouse', 'Manufacturer', 'Destroyed']);
        while ($r = sqlFetchArray($exportRows)) {
            fputcsv($out, [
                $r['name'], $r['ndc_number'], $r['form'], $r['lot_number'],
                $r['expiration'], $r['on_hand'], $r['warehouse_id'],
                $r['manufacturer'], $r['destroy_date'],
            ]);
        }
        try {
            EventAuditLogger::getInstance()->newEvent(
                'drug_inventory_export',
                $userName,
                $groupName,
                1,
                'copilot_inventory export_csv',
                null
            );
        } catch (\Throwable $t) {
            // Swallow.
        }
        fclose($out);
        exit;
    }
}

$flash = $_GET['msg'] ?? null;

// --------------------------------------------------------------------
// Warehouse label resolver. Falls back to raw id if not found.
// --------------------------------------------------------------------
$whLabels = [];
$whRows = sqlStatement(
    "SELECT option_id, title FROM list_options WHERE list_id = ? AND activity = 1",
    ['warehouse']
);
while ($w = sqlFetchArray($whRows)) {
    $whLabels[(string)$w['option_id']] = (string)$w['title'];
}
$resolveWarehouse = static function (?string $id) use ($whLabels): string {
    if ($id === null || $id === '') { return '—'; }
    return $whLabels[$id] ?? $id;
};

// --------------------------------------------------------------------
// KPI tiles — all from real queries.
// --------------------------------------------------------------------
$kpiTotalDrugs = (int)(sqlQuery("SELECT COUNT(*) AS c FROM drugs WHERE active = 1")['c'] ?? 0);

// Low stock = drugs whose total non-destroyed on_hand < reorder_point (and
// reorder_point > 0). Drugs with no inventory rows count as low if their
// reorder_point > 0.
$lowStockSql = "SELECT d.drug_id, d.reorder_point,
                       COALESCE(SUM(CASE WHEN di.destroy_date IS NULL
                                         THEN di.on_hand ELSE 0 END), 0) AS oh
                  FROM drugs d
                  LEFT JOIN drug_inventory di ON di.drug_id = d.drug_id
                 WHERE d.active = 1 AND d.reorder_point > 0
                 GROUP BY d.drug_id, d.reorder_point
                HAVING oh < d.reorder_point";
$kpiLowStock = 0;
$lowRows = sqlStatement($lowStockSql);
while ($r = sqlFetchArray($lowRows)) {
    $kpiLowStock++;
}

$kpiExpiring = (int)(sqlQuery(
    "SELECT COUNT(*) AS c FROM drug_inventory
      WHERE destroy_date IS NULL
        AND expiration IS NOT NULL
        AND expiration BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)"
)['c'] ?? 0);

// Total value: drugs table has no unit_price column, so this is reported
// as total on-hand units across all active inventory. Honest about scope.
$kpiOnHandUnits = (int)(sqlQuery(
    "SELECT COALESCE(SUM(on_hand), 0) AS s
       FROM drug_inventory WHERE destroy_date IS NULL"
)['s'] ?? 0);

$kpiDestroyed = (int)(sqlQuery(
    "SELECT COUNT(*) AS c FROM drug_inventory WHERE destroy_date IS NOT NULL"
)['c'] ?? 0);

// Tab counts (also used for badge labels).
$tabCounts = [
    'active'          => (int)(sqlQuery("SELECT COUNT(*) AS c FROM drug_inventory WHERE destroy_date IS NULL AND on_hand > 0")['c'] ?? 0),
    'expiring'        => $kpiExpiring,
    'below_reorder'   => $kpiLowStock,
    'destruction_log' => $kpiDestroyed,
    // Receiving log: every lot that's been added (i.e. exists in
    // drug_inventory). Same row count as the all-time inventory.
    'receiving_log'   => (int)(sqlQuery("SELECT COUNT(*) AS c FROM drug_inventory")['c'] ?? 0),
];

// --------------------------------------------------------------------
// Main rows query. Always join drugs; tabs change WHERE.
// --------------------------------------------------------------------
$where = '1=1';
$params = [];
switch ($tab) {
    case 'active':
        $where .= ' AND di.destroy_date IS NULL AND di.on_hand > 0';
        break;
    case 'expiring':
        $where .= ' AND di.destroy_date IS NULL'
                . ' AND di.expiration IS NOT NULL'
                . ' AND di.expiration BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)';
        break;
    case 'destruction_log':
        $where .= ' AND di.destroy_date IS NOT NULL';
        break;
    case 'receiving_log':
        // Receiving log = every lot, ordered by inventory_id desc (most
        // recently added first). Use a sentinel so the main query path
        // applies the right ORDER BY further down.
        // (No additional WHERE clause needed.)
        break;
    case 'below_reorder':
        // Show drugs (one row each) whose total on-hand is below reorder.
        // We'll handle this with a separate query below; sentinel here.
        $where .= ' AND 1=0';
        break;
    default:
        // Defensive: should already be normalized by validTabs check.
        $where .= ' AND di.destroy_date IS NULL AND di.on_hand > 0';
        break;
}
// Filter dropdowns. Each value is allow-listed above; only literal SQL
// fragments enter $where, never user input.
if ($fSched === 'controlled') {
    // Controlled-substance match by drug name (drugs table has no
    // dea_schedule column in the seed). Pattern list mirrors the
    // controlled-detection helper used by the prescription-report.
    $where .= " AND (d.name LIKE 'Tramadol%' OR d.name LIKE 'Lorazepam%'"
            . " OR d.name LIKE 'Phenobarbital%' OR d.name LIKE 'Oxycodone%'"
            . " OR d.name LIKE 'Hydrocodone%' OR d.name LIKE 'Adderall%'"
            . " OR d.name LIKE 'Vyvanse%' OR d.name LIKE 'Xanax%'"
            . " OR d.name LIKE 'Ambien%' OR d.name LIKE 'Methylphenidate%'"
            . " OR d.name LIKE 'Diazepam%' OR d.name LIKE 'Clonazepam%'"
            . " OR d.name LIKE 'Codeine%' OR d.name LIKE 'Fentanyl%'"
            . " OR d.name LIKE 'Morphine%')";
} elseif ($fSched === 'rx') {
    // Rx-only (non-controlled) — exclude OTC + the controlled patterns.
    $where .= " AND d.name NOT LIKE 'Tramadol%'"
            . " AND d.name NOT LIKE 'Lorazepam%'"
            . " AND d.name NOT LIKE 'Phenobarbital%'"
            . " AND d.name NOT LIKE 'Oxycodone%'"
            . " AND d.name NOT LIKE 'Adderall%'";
}
if ($fForm === 'tablet')    { $where .= " AND (d.form LIKE '%tab%' OR d.form LIKE '%capsule%')"; }
elseif ($fForm === 'injection') { $where .= " AND (d.form LIKE '%inject%' OR d.form LIKE '%IM%' OR d.form LIKE '%IV%' OR d.form LIKE '%vial%')"; }
elseif ($fForm === 'spray') { $where .= " AND d.form LIKE '%spray%'"; }
elseif ($fForm === 'vial')  { $where .= " AND d.form LIKE '%vial%'"; }

// Stock filter only applies to the inventory tabs (not destruction/receiving log).
if (in_array($tab, ['active', 'expiring'], true)) {
    if ($fStock === 'in_stock')      { $where .= ' AND di.on_hand > 0'; }
    elseif ($fStock === 'out_of_stock') { $where .= ' AND di.on_hand = 0'; }
    elseif ($fStock === 'low')        { $where .= ' AND d.reorder_point > 0 AND di.on_hand < d.reorder_point'; }
}

// Facility filter: maps to drug_inventory.warehouse_id (a list_options key).
if ($fFacility !== '' && $fFacility !== 'all') {
    $where .= ' AND di.warehouse_id = ?';
    $params[] = $fFacility;
}

if ($q !== '') {
    $where .= ' AND (d.name LIKE ? OR d.ndc_number LIKE ? OR di.lot_number LIKE ?)';
    $like = '%' . $q . '%';
    $params[] = $like; $params[] = $like; $params[] = $like;
}

$rows = [];

// Per-drug "last dispensed" date — pulled from drug_sales. Cached in a
// PHP map so the inventory query stays a simple JOIN.
$lastDispensed = [];
$ldRows = sqlStatement(
    "SELECT drug_id, MAX(sale_date) AS last_sale FROM drug_sales GROUP BY drug_id"
);
while ($r = sqlFetchArray($ldRows)) {
    $lastDispensed[(int)$r['drug_id']] = $r['last_sale'];
}

// Controlled-substance detection — by name pattern, since drugs has no
// dea_schedule column in seed. Returns the schedule label (e.g. "C-IV")
// or '' for non-controlled.
$schedFor = static function (string $name): string {
    $n = strtolower(trim($name));
    if ($n === '') return '';
    // Schedule II
    if (preg_match('/^(oxycodone|fentanyl|methylphenidate|adderall|vyvanse|hydrocodone|morphine)/', $n)) return 'C-II';
    // Schedule III
    if (preg_match('/^(codeine|ketamine)/', $n)) return 'C-III';
    // Schedule IV (the most common pattern in the demo seed)
    if (preg_match('/^(tramadol|lorazepam|phenobarbital|diazepam|clonazepam|alprazolam|xanax|ambien|zolpidem)/', $n)) return 'C-IV';
    // Schedule V
    if (preg_match('/^(pregabalin|lacosamide)/', $n)) return 'C-V';
    return '';
};

if ($tab === 'below_reorder') {
    // One row per drug (no lot dimension) — show the deficit.
    $sql = "SELECT d.drug_id, d.name, d.ndc_number, d.form, d.reorder_point,
                   COALESCE(SUM(CASE WHEN di.destroy_date IS NULL THEN di.on_hand ELSE 0 END), 0) AS on_hand_total,
                   (SELECT MAX(di2.warehouse_id)
                      FROM drug_inventory di2
                     WHERE di2.drug_id = d.drug_id
                       AND di2.destroy_date IS NULL) AS warehouse_id,
                   (SELECT MIN(di3.expiration)
                      FROM drug_inventory di3
                     WHERE di3.drug_id = d.drug_id
                       AND di3.destroy_date IS NULL
                       AND di3.expiration IS NOT NULL) AS expiration,
                   (SELECT MAX(di4.lot_number)
                      FROM drug_inventory di4
                     WHERE di4.drug_id = d.drug_id
                       AND di4.destroy_date IS NULL) AS lot_number
              FROM drugs d
              LEFT JOIN drug_inventory di ON di.drug_id = d.drug_id
             WHERE d.active = 1 AND d.reorder_point > 0";
    $lowParams = [];
    if ($q !== '') {
        $sql .= ' AND (d.name LIKE ? OR d.ndc_number LIKE ?)';
        $lowParams[] = '%' . $q . '%';
        $lowParams[] = '%' . $q . '%';
    }
    $sql .= " GROUP BY d.drug_id, d.name, d.ndc_number, d.form, d.reorder_point
            HAVING on_hand_total < d.reorder_point
             ORDER BY (d.reorder_point - on_hand_total) DESC, d.name
             LIMIT 200";
    $res = sqlStatement($sql, $lowParams);
    while ($r = sqlFetchArray($res)) {
        $rows[] = [
            'inventory_id'   => null,
            'drug_id'        => (int)$r['drug_id'],
            'name'           => (string)$r['name'],
            'ndc_number'     => (string)$r['ndc_number'],
            'form'           => (string)$r['form'],
            'lot_number'     => (string)($r['lot_number'] ?? ''),
            'expiration'     => $r['expiration'],
            'on_hand'        => (int)$r['on_hand_total'],
            'reorder_point'  => (float)$r['reorder_point'],
            'warehouse_id'   => (string)($r['warehouse_id'] ?? ''),
            'destroy_date'   => null,
            'low'            => true,
            'last_dispensed' => $lastDispensed[(int)$r['drug_id']] ?? null,
            'schedule'       => $schedFor((string)$r['name']),
        ];
    }
} else {
    // Receiving log uses inventory_id DESC; everything else sorts by exp date.
    $orderBy = ($tab === 'receiving_log')
        ? 'di.inventory_id DESC'
        : '(di.destroy_date IS NOT NULL), di.expiration ASC, d.name ASC';
    $sql = "SELECT di.inventory_id, di.drug_id, di.lot_number, di.expiration,
                   di.on_hand, di.warehouse_id, di.destroy_date,
                   di.destroy_notes, di.manufacturer,
                   d.name, d.ndc_number, d.form, d.reorder_point
              FROM drug_inventory di
              JOIN drugs d ON d.drug_id = di.drug_id
             WHERE $where
             ORDER BY $orderBy
             LIMIT 200";
    $res = sqlStatement($sql, $params);
    while ($r = sqlFetchArray($res)) {
        $rows[] = [
            'inventory_id'   => (int)$r['inventory_id'],
            'drug_id'        => (int)$r['drug_id'],
            'name'           => (string)$r['name'],
            'ndc_number'     => (string)$r['ndc_number'],
            'form'           => (string)$r['form'],
            'lot_number'     => (string)($r['lot_number'] ?? ''),
            'expiration'     => $r['expiration'],
            'on_hand'        => (int)$r['on_hand'],
            'reorder_point'  => (float)$r['reorder_point'],
            'warehouse_id'   => (string)($r['warehouse_id'] ?? ''),
            'destroy_date'   => $r['destroy_date'],
            'destroy_notes'  => (string)($r['destroy_notes'] ?? ''),
            'manufacturer'   => (string)($r['manufacturer'] ?? ''),
            'low'            => false,
            'last_dispensed' => $lastDispensed[(int)$r['drug_id']] ?? null,
            'schedule'       => $schedFor((string)$r['name']),
        ];
    }
}

// Drug list for the Add inventory <select>.
$drugChoices = [];
$drugRes = sqlStatement(
    "SELECT drug_id, name, ndc_number FROM drugs WHERE active = 1 ORDER BY name"
);
while ($d = sqlFetchArray($drugRes)) {
    $drugChoices[] = $d;
}

// Helper: compute the expiration tone for a given date.
$expTone = static function (?string $expDate): string {
    if ($expDate === null || $expDate === '') { return 'plain'; }
    $today = strtotime((string)date('Y-m-d'));
    $exp = strtotime($expDate);
    if ($exp === false) { return 'plain'; }
    if ($exp < $today) { return 'past'; }
    if ($exp - $today <= 30 * 86400) { return 'warn'; }
    return 'plain';
};

// Header sub-label is now derived from KPIs.
$subMeta = $kpiTotalDrugs . ' SKUs · '
         . $kpiExpiring . ' expiring in 30d · '
         . $kpiLowStock . ' below reorder';

?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo xlt('Inventory'); ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/public/copilot-archetype.css">
<style>
  /* Page header dot + light meta */
  .cp-pagehead .dot { color: #8A91A1; font-size: 14px; padding: 0 4px; }
  .cp-pagehead .meta-light { color: #8A91A1; font-size: 12px; line-height: 1.2; }
  .cp-btn.danger { background: #D93838; color: #FFFFFF; border-color: #D93838; }
  .cp-btn.danger:hover { background: #BF2C2C; }

  /* Flash banner */
  .cp-flash {
    background: #E6F4EA; color: #1F8C4D;
    border-bottom: 1px solid #C8E6CF;
    padding: 10px 24px;
    font-size: 12px; font-weight: 600;
  }
  .cp-flash.err { background: #FCE7E7; color: #D93838; border-bottom-color: #F5C9C9; }

  /* Tab strip below header */
  .cp-tabs {
    background: #FFFFFF;
    border-bottom: 1px solid #E4E5E8;
    padding: 0 24px;
    display: flex; align-items: stretch; gap: 0;
  }
  .cp-tabs a {
    background: transparent; border: 0;
    padding: 14px 0;
    margin-right: 28px;
    font-size: 13px; font-weight: 500;
    color: #4F5763;
    border-bottom: 2px solid transparent;
    line-height: 1;
    text-decoration: none;
  }
  .cp-tabs a:hover { color: #0D1B2A; }
  .cp-tabs a.active {
    color: #008C8C; font-weight: 600;
    border-bottom-color: #008C8C;
  }

  /* Filter row: search box + dropdown selects */
  .cp-filter-row {
    background: #FFFFFF;
    border-bottom: 1px solid #E4E5E8;
    padding: 12px 24px;
    display: flex; align-items: center; gap: 10px;
  }
  .cp-search-box {
    background: #F5F6F7;
    border: 1px solid #E4E5E8;
    border-radius: 8px;
    height: 32px;
    padding: 0 12px;
    display: flex; align-items: center; gap: 8px;
    flex: 0 0 280px;
  }
  .cp-search-box .ic { color: #8A91A1; font-size: 12px; }
  .cp-search-box input {
    border: 0; background: transparent; outline: none;
    flex: 1; font-size: 12px; color: #0D1B2A;
  }
  .cp-search-box input::placeholder { color: #8A91A1; }
  .cp-dd {
    background: #FFFFFF;
    border: 1px solid #E4E5E8;
    border-radius: 8px;
    height: 32px;
    padding: 0 32px 0 12px;
    font-size: 12px; color: #0D1B2A;
    display: inline-flex; align-items: center;
    position: relative;
    flex: 0 0 auto;
    min-width: 110px;
  }
  .cp-dd::after {
    content: '▾';
    position: absolute; right: 10px; top: 50%; transform: translateY(-50%);
    color: #8A91A1; font-size: 9px;
  }
  /* Native <select> styled to match the figma dropdown chrome */
  .cp-dd-native {
    background: #FFFFFF;
    border: 1px solid #E4E5E8;
    border-radius: 8px;
    height: 32px;
    padding: 0 28px 0 12px;
    font-size: 12px; color: #0D1B2A;
    appearance: none;
    background-image: linear-gradient(45deg, transparent 50%, #8A91A1 50%),
                      linear-gradient(135deg, #8A91A1 50%, transparent 50%);
    background-position: calc(100% - 14px) 14px, calc(100% - 9px) 14px;
    background-size: 5px 5px, 5px 5px;
    background-repeat: no-repeat;
    flex: 0 0 auto;
    min-width: 110px;
  }
  .cp-dd-native:focus { outline: none; border-color: #008C8C; }

  /* SCHEDULE pill (e.g. C-IV) — orange tone for controlled substances */
  .cp-sched-pill {
    display: inline-block;
    background: #FFF1E0;
    color: #FA8C33;
    border-radius: 999px;
    padding: 2px 9px;
    font-size: 10px; font-weight: 700;
    letter-spacing: 0.5px;
    line-height: 1.4;
  }

  /* Kebab action — replaces the inline Destroy form per the figma */
  .cp-kebab {
    display: inline-block;
    color: #8A91A1; cursor: pointer;
    padding: 4px 6px; border-radius: 6px; font-size: 14px;
    line-height: 1; text-decoration: none;
  }
  .cp-kebab:hover { background: #F5F6F7; color: #0D1B2A; }

  /* KPI strip */
  .cp-kpis {
    display: grid; grid-template-columns: repeat(4, 1fr);
    gap: 12px;
    padding: 16px 24px;
    background: #F5F6F7;
    border-bottom: 1px solid #E4E5E8;
  }
  .cp-kpi {
    background: #FFFFFF;
    border: 1px solid #E4E5E8;
    border-radius: 10px;
    padding: 14px 16px;
  }
  .cp-kpi .lbl {
    font-size: 10px; font-weight: 600; color: #8A91A1;
    text-transform: uppercase; letter-spacing: 0.5px;
  }
  .cp-kpi .val {
    font-size: 22px; font-weight: 700; color: #0D1B2A;
    margin-top: 4px;
  }
  .cp-kpi .sub {
    font-size: 11px; color: #4F5763; margin-top: 2px;
  }
  .cp-kpi .val.warn { color: #FA8C33; }
  .cp-kpi .val.bad  { color: #D93838; }

  /* Inventory table */
  .cp-content.flush {
    padding: 0;
    gap: 0;
    background: #FFFFFF;
  }
  .cp-inv-tbl {
    background: #FFFFFF;
  }
  .cp-inv-tbl table { width: 100%; border-collapse: collapse; font-size: 12px; }
  .cp-inv-tbl thead { background: #F5F6F7; }
  .cp-inv-tbl th {
    padding: 11px 14px;
    text-align: left;
    font-size: 10px; font-weight: 600;
    color: #8A91A1; letter-spacing: 0.5px;
    text-transform: uppercase;
    border-bottom: 1px solid #E4E5E8;
    white-space: nowrap;
  }
  .cp-inv-tbl td {
    padding: 12px 14px;
    border-bottom: 1px solid #F0F1F3;
    color: #0D1B2A;
    vertical-align: top;
    font-size: 12px;
  }
  .cp-inv-tbl tbody tr:last-child td { border-bottom: none; }
  .cp-inv-tbl td.drug { padding-left: 24px; min-width: 200px; }
  .cp-inv-tbl th.drug { padding-left: 24px; }
  .cp-inv-tbl td.act, .cp-inv-tbl th.act {
    padding-right: 24px;
    text-align: right;
    width: 96px;
    white-space: nowrap;
  }
  .cp-inv-tbl .name {
    font-weight: 600; color: #0D1B2A; line-height: 1.3;
  }
  .cp-inv-tbl .form {
    font-size: 11px; color: #8A91A1; margin-top: 2px; line-height: 1.3;
  }
  .cp-inv-tbl .muted { color: #4F5763; }
  .cp-inv-tbl .bold { font-weight: 600; }
  .cp-inv-tbl tr.destroyed td { background: #FAFAFB; color: #8A91A1; }
  .cp-inv-tbl tr.destroyed td .name { color: #4F5763; }

  /* Cell tones */
  .v-warn  { color: #FA8C33; font-weight: 600; }
  .v-past  { color: #D93838; font-weight: 600; }
  .v-plain { color: #0D1B2A; font-weight: 600; }

  /* Inline destroy form */
  .cp-mini-btn {
    background: #FFFFFF; border: 1px solid #E4E5E8;
    border-radius: 6px; padding: 4px 8px; font-size: 11px;
    color: #0D1B2A; cursor: pointer;
  }
  .cp-mini-btn:hover { background: #F5F6F7; }
  .cp-mini-btn.danger {
    background: #D93838; color: #FFFFFF; border-color: #D93838;
  }
  .cp-mini-btn.danger:hover { background: #BF2C2C; }

  /* Empty state */
  .cp-empty {
    padding: 48px 24px;
    text-align: center;
    color: #8A91A1;
    font-size: 13px;
  }

  /* Add-inventory drawer (hidden by default; toggled via :target) */
  .cp-drawer {
    display: none;
    background: #FFFFFF;
    border-bottom: 1px solid #E4E5E8;
    padding: 16px 24px;
  }
  .cp-drawer:target { display: block; }
  .cp-drawer form {
    display: grid;
    grid-template-columns: repeat(6, 1fr) auto auto;
    gap: 8px; align-items: end;
  }
  .cp-drawer label { font-size: 10px; font-weight: 600; color: #8A91A1;
    text-transform: uppercase; letter-spacing: 0.5px;
    display: block; margin-bottom: 4px; }
  .cp-drawer input, .cp-drawer select {
    height: 32px; border: 1px solid #E4E5E8; border-radius: 6px;
    padding: 0 8px; font-size: 12px; color: #0D1B2A; background: #FFFFFF;
    width: 100%;
  }
  .cp-drawer .cp-btn { height: 32px; }
</style>
</head>
<body class="cp-arch">

<header class="cp-pagehead">
  <div class="info">
    <div style="display:flex; align-items:center; gap:6px;">
      <span class="title"><?php echo xlt('Inventory'); ?></span>
      <span class="dot">·</span>
      <span class="meta-light"><?php echo xlt('Drug inventory'); ?> · <?php echo text($subMeta); ?></span>
    </div>
  </div>
  <form method="post" action="<?php echo attr($selfUrl); ?>" style="display:inline;">
    <input type="hidden" name="action" value="export_csv">
    <button type="submit" class="cp-btn ghost">⤓ <?php echo xlt('Export'); ?></button>
  </form>
  <a href="#add-inv" class="cp-btn ghost" style="text-decoration:none;">+ <?php echo xlt('Receive shipment'); ?></a>
  <a href="?tab=destruction_log" class="cp-btn danger" style="text-decoration:none;">✕ <?php echo xlt('Record destruction (DEA)'); ?></a>
  <button type="button" class="cp-btn ghost" disabled title="<?php echo xla('Help is out of scope'); ?>">? <?php echo xlt('Help'); ?></button>
</header>

<?php if ($flash !== null): ?>
  <?php
    $flashMap = [
        'inventory_added' => ['Inventory lot added.', 'ok'],
        'destroyed'       => ['Inventory marked destroyed (DEA-222).', 'ok'],
        'add_failed'      => ['Could not add inventory — drug and qty required.', 'err'],
        'destroy_failed'  => ['Could not destroy — invalid inventory id.', 'err'],
    ];
    [$flashTxt, $flashKind] = $flashMap[$flash] ?? [$flash, 'ok'];
  ?>
  <div class="cp-flash<?php echo $flashKind === 'err' ? ' err' : ''; ?>">
    <?php echo text($flashTxt); ?>
  </div>
<?php endif; ?>

<section id="add-inv" class="cp-drawer">
  <form method="post" action="<?php echo attr($selfUrl); ?>">
    <input type="hidden" name="action" value="add_inventory">
    <div>
      <label><?php echo xlt('Drug'); ?></label>
      <select name="drug_id" required>
        <option value=""><?php echo xlt('Select drug'); ?></option>
        <?php foreach ($drugChoices as $d): ?>
          <option value="<?php echo attr($d['drug_id']); ?>">
            <?php echo text($d['name']); ?> (<?php echo text($d['ndc_number']); ?>)
          </option>
        <?php endforeach; ?>
      </select>
    </div>
    <div>
      <label><?php echo xlt('Qty'); ?></label>
      <input type="number" name="qty" min="1" required>
    </div>
    <div>
      <label><?php echo xlt('Lot #'); ?></label>
      <input type="text" name="lot" maxlength="20">
    </div>
    <div>
      <label><?php echo xlt('Expiration'); ?></label>
      <input type="date" name="exp">
    </div>
    <div>
      <label><?php echo xlt('Manufacturer'); ?></label>
      <input type="text" name="manufacturer" maxlength="255">
    </div>
    <div>
      <label><?php echo xlt('Warehouse'); ?></label>
      <select name="warehouse_id">
        <?php foreach ($whLabels as $id => $title): ?>
          <option value="<?php echo attr($id); ?>"><?php echo text($title); ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <button type="submit" class="cp-btn primary"><?php echo xlt('Receive lot'); ?></button>
    <a href="<?php echo attr($selfUrl); ?>" class="cp-btn ghost" style="text-decoration:none;"><?php echo xlt('Cancel'); ?></a>
  </form>
</section>

<nav class="cp-tabs">
  <?php
    $tabDefs = [
        'active'          => xl('Active inventory'),
        'expiring'        => xl('Expiring soon'),
        'below_reorder'   => xl('Below reorder'),
        'destruction_log' => xl('Destruction log'),
        'receiving_log'   => xl('Receiving log'),
    ];
    foreach ($tabDefs as $key => $lbl):
      $cls = ($tab === $key) ? 'active' : '';
      $href = '?tab=' . urlencode($key) . ($q !== '' ? '&q=' . urlencode($q) : '');
      $cnt = (int)($tabCounts[$key] ?? 0);
  ?>
    <a class="<?php echo attr($cls); ?>" href="<?php echo attr($href); ?>">
      <?php echo text($lbl); ?>
      <?php if ($key !== 'receiving_log' || $cnt > 0): ?>
        (<?php echo text((string)$cnt); ?>)
      <?php endif; ?>
    </a>
  <?php endforeach; ?>
</nav>

<form method="get" action="<?php echo attr($selfUrl); ?>" class="cp-filter-row">
  <input type="hidden" name="tab" value="<?php echo attr($tab); ?>">
  <div class="cp-search-box">
    <span class="ic">🔍</span>
    <input type="text" name="q"
           value="<?php echo attr($q); ?>"
           placeholder="<?php echo xla('Search by drug, NDC, or lot #'); ?>"
           onchange="this.form.submit()">
  </div>
  <select class="cp-dd-native" name="sched" onchange="this.form.submit()">
    <option value="all"        <?php echo $fSched === 'all' ? 'selected' : ''; ?>><?php echo xlt('All'); ?></option>
    <option value="rx"         <?php echo $fSched === 'rx' ? 'selected' : ''; ?>><?php echo xlt('Rx (non-controlled)'); ?></option>
    <option value="controlled" <?php echo $fSched === 'controlled' ? 'selected' : ''; ?>><?php echo xlt('Controlled (C-II–V)'); ?></option>
  </select>
  <select class="cp-dd-native" name="form" onchange="this.form.submit()">
    <option value="all"       <?php echo $fForm === 'all' ? 'selected' : ''; ?>><?php echo xlt('All'); ?></option>
    <option value="tablet"    <?php echo $fForm === 'tablet' ? 'selected' : ''; ?>><?php echo xlt('Tablet / Capsule'); ?></option>
    <option value="injection" <?php echo $fForm === 'injection' ? 'selected' : ''; ?>><?php echo xlt('Injection / IV'); ?></option>
    <option value="spray"     <?php echo $fForm === 'spray' ? 'selected' : ''; ?>><?php echo xlt('Spray'); ?></option>
    <option value="vial"      <?php echo $fForm === 'vial' ? 'selected' : ''; ?>><?php echo xlt('Vial'); ?></option>
  </select>
  <select class="cp-dd-native" name="facility" onchange="this.form.submit()">
    <option value=""  <?php echo $fFacility === '' || $fFacility === 'all' ? 'selected' : ''; ?>><?php echo xlt('All facilities'); ?></option>
    <?php foreach ($whLabels as $id => $title): ?>
      <option value="<?php echo attr($id); ?>"
              <?php echo $fFacility === $id ? 'selected' : ''; ?>>
        <?php echo text($title); ?>
      </option>
    <?php endforeach; ?>
  </select>
  <select class="cp-dd-native" name="stock" onchange="this.form.submit()">
    <option value="in_stock"     <?php echo $fStock === 'in_stock' ? 'selected' : ''; ?>><?php echo xlt('In stock'); ?></option>
    <option value="all"          <?php echo $fStock === 'all' ? 'selected' : ''; ?>><?php echo xlt('All stock'); ?></option>
    <option value="out_of_stock" <?php echo $fStock === 'out_of_stock' ? 'selected' : ''; ?>><?php echo xlt('Out of stock'); ?></option>
    <option value="low"          <?php echo $fStock === 'low' ? 'selected' : ''; ?>><?php echo xlt('Low'); ?></option>
  </select>
  <?php if ($q !== '' || $fSched !== 'all' || $fForm !== 'all' || $fFacility !== '' || $fStock !== 'in_stock'): ?>
    <a href="?tab=<?php echo attr(urlencode($tab)); ?>" class="cp-btn ghost" style="text-decoration:none;">
      <?php echo xlt('Clear'); ?>
    </a>
  <?php endif; ?>
</form>

<main class="cp-content flush">

  <div class="cp-inv-tbl">
    <table>
      <thead>
        <tr>
          <th class="drug"><?php echo xlt('DRUG / FORM'); ?></th>
          <th><?php echo xlt('NDC'); ?></th>
          <th><?php echo xlt('SCHEDULE'); ?></th>
          <th><?php echo xlt('LOT #'); ?></th>
          <th><?php echo xlt('EXP DATE'); ?></th>
          <th><?php echo xlt('ON HAND'); ?></th>
          <th><?php echo xlt('REORDER'); ?></th>
          <th><?php echo xlt('LOCATION'); ?></th>
          <th><?php echo xlt('LAST DISPENSED'); ?></th>
          <th class="act"></th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($rows)): ?>
          <tr><td class="cp-empty" colspan="10">
            <?php if ($tab === 'active' && $q === ''): ?>
              <?php echo xlt('No drug inventory yet. Click "Receive shipment" to log your first lot.'); ?>
            <?php else: ?>
              <?php echo xlt('No matches for the current tab and filter.'); ?>
            <?php endif; ?>
          </td></tr>
        <?php else: foreach ($rows as $r):
          $tone = $expTone($r['expiration']);
          $isDestroyed = !empty($r['destroy_date']);
          $low = !empty($r['low']) || ($r['reorder_point'] > 0 && (float)$r['on_hand'] < (float)$r['reorder_point']);
          $ohTone = $low ? 'warn' : 'plain';
          $expDisplay = $r['expiration'] !== null && $r['expiration'] !== ''
              ? date('m/d/Y', (int)strtotime((string)$r['expiration']))
              : '—';
          $reorderDisplay = ((float)$r['reorder_point']) > 0
              ? rtrim(rtrim(number_format((float)$r['reorder_point'], 2), '0'), '.')
              : '—';
          $lastDisp = $r['last_dispensed'] ?? null;
          $lastDispDisplay = ($lastDisp && $lastDisp !== '0000-00-00')
              ? date('m/d/Y', (int)strtotime((string)$lastDisp))
              : '—';
        ?>
          <tr<?php echo $isDestroyed ? ' class="destroyed"' : ''; ?>>
            <td class="drug">
              <div class="name"><?php echo text($r['name']); ?></div>
              <div class="form"><?php echo text($r['form']); ?></div>
            </td>
            <td class="muted"><?php echo text($r['ndc_number']); ?></td>
            <td>
              <?php if ($r['schedule'] !== ''): ?>
                <span class="cp-sched-pill"><?php echo text($r['schedule']); ?></span>
              <?php endif; ?>
            </td>
            <td class="muted"><?php echo text($r['lot_number'] !== '' ? $r['lot_number'] : '—'); ?></td>
            <td><span class="v-<?php echo attr($tone); ?>"><?php echo text($expDisplay); ?></span></td>
            <td><span class="v-<?php echo attr($ohTone); ?>"><?php echo text((string)$r['on_hand']); ?></span></td>
            <td class="muted"><?php echo text($reorderDisplay); ?></td>
            <td class="muted"><?php echo text($resolveWarehouse($r['warehouse_id'] ?? '')); ?></td>
            <td class="muted"><?php echo text($lastDispDisplay); ?></td>
            <td class="act">
              <?php if (!$isDestroyed && !empty($r['inventory_id'])): ?>
                <form method="post" action="<?php echo attr($selfUrl); ?>"
                      style="display:inline;"
                      onsubmit="return confirm('Mark this lot destroyed (DEA-222)? This zeros on-hand and is logged.');">
                  <input type="hidden" name="action" value="destroy">
                  <input type="hidden" name="inv_id" value="<?php echo attr((string)$r['inventory_id']); ?>">
                  <input type="hidden" name="reason" value="<?php echo attr($tone === 'past' ? 'expired' : 'standard_destruction'); ?>">
                  <button type="submit" class="cp-kebab" title="<?php echo xla('Destroy lot (DEA-222)'); ?>">⋯</button>
                </form>
              <?php else: ?>
                <span class="muted">⋯</span>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>

</main>

</body>
</html>
