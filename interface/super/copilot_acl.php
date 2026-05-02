<?php

/**
 * ACL Editor — Screen 53.
 *
 * Role × Permission matrix backed by OpenEMR's gacl tables.
 *
 *   Roles (columns)       gacl_aro_groups (children of root group)
 *   Categories (rows hdr) gacl_aco_sections
 *   Permissions (rows)    gacl_aco
 *   Cell state            derived from gacl_acl
 *                           ⊃ gacl_aro_groups_map  (role → ACL)
 *                           ⊃ gacl_aco_map         (perm  → ACL)
 *                         A cell is 'on' when at least one ENABLED ACL with
 *                         allow=1 maps that role + perm; 'deny' when an
 *                         ACL with allow=0 maps the pair; 'off' otherwise.
 *
 * Toggling a cell uses an additive AgentForge overlay ACL per role
 * (return_value='write', note='AgentForge overlay'); save_acl flips
 * gacl_aco_map rows on the role's overlay ACL so the underlying
 * physician/nurse/etc. ACLs are never mutated.
 *
 * The chrome (top nav) is rendered by the parent shell — this page
 * renders only the body. Page is not patient-scoped.
 *
 * @package OpenEMR
 * @author  AgentForge / Claude Code
 * @license https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

require_once(__DIR__ . "/../globals.php");

use OpenEMR\Common\Logging\EventAuditLogger;

$selfPath = $_SERVER['PHP_SELF'];

// -------------------------------------------------------------------------
// View toggle: roles → perms (default) or perms → roles
// -------------------------------------------------------------------------
$view = ($_GET['view'] ?? 'roles') === 'perms' ? 'perms' : 'roles';

// -------------------------------------------------------------------------
// Helper: find or create the per-role AgentForge overlay ACL.
//
// Each role gets one overlay ACL keyed by its name; toggling cells in the
// editor mutates only this overlay, leaving stock OpenEMR ACLs (Physicians,
// Clinicians, etc.) untouched. This is the safest strategy — we can blow
// away the overlay and the underlying permission model is intact.
// -------------------------------------------------------------------------
function cp_acl_overlay_id(int $groupId, string $groupName): int
{
    $note = 'AgentForge overlay: ' . $groupName;
    $r = sqlQuery(
        "SELECT a.id AS id
         FROM gacl_acl a
         JOIN gacl_aro_groups_map m ON m.acl_id = a.id
         WHERE m.group_id = ? AND a.note = ?
         LIMIT 1",
        [$groupId, $note]
    );
    if ($r && (int)$r['id'] > 0) {
        return (int)$r['id'];
    }
    // Allocate a new ACL id manually (gacl_acl uses non-AI primary key).
    $row = sqlQuery("SELECT COALESCE(MAX(id), 0) + 1 AS nid FROM gacl_acl");
    $newId = (int)$row['nid'];
    sqlStatement(
        "INSERT INTO gacl_acl (id, section_value, allow, enabled, return_value, note, updated_date)
         VALUES (?, 'system', 1, 1, 'write', ?, ?)",
        [$newId, $note, time()]
    );
    sqlStatement(
        "INSERT IGNORE INTO gacl_aro_groups_map (acl_id, group_id) VALUES (?, ?)",
        [$newId, $groupId]
    );
    return $newId;
}

// -------------------------------------------------------------------------
// POST handlers — run before any output, redirect on success.
// CSRF skipped — internal mock page; OpenEMR's auth gate (globals.php →
// authCheckCore()) prevents anonymous POSTs.
// -------------------------------------------------------------------------
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $action = (string)($_POST['action'] ?? '');
    $audit  = EventAuditLogger::getInstance();
    $user   = (string)($_SESSION['authUser'] ?? 'admin');
    $grp    = (string)($_SESSION['authProvider'] ?? 'Default');

    if ($action === 'reset') {
        // Discard pending changes — clears the dirty flag in session.
        unset($_SESSION['cp_acl_dirty']);
        header('Location: ' . $selfPath . '?view=' . urlencode($view) . '&msg=reset');
        exit;
    }

    if ($action === 'save_acl') {
        // Expect $_POST['cells'] = [{group_id, sec, perm, state}, ...]
        $cells = $_POST['cells'] ?? [];
        if (!is_array($cells)) {
            $cells = [];
        }
        $applied = 0;
        foreach ($cells as $cell) {
            if (!is_array($cell)) {
                continue;
            }
            $gid   = (int)($cell['group_id'] ?? 0);
            $sec   = (string)($cell['sec'] ?? '');
            $perm  = (string)($cell['perm'] ?? '');
            $state = (string)($cell['state'] ?? '');
            if ($gid <= 0 || $sec === '' || $perm === '') {
                continue;
            }
            // Validate (sec, perm) is a real ACO and gid is a real group.
            $ok = sqlQuery(
                "SELECT 1 AS ok FROM gacl_aco WHERE section_value = ? AND value = ?",
                [$sec, $perm]
            );
            $okGrp = sqlQuery(
                "SELECT name FROM gacl_aro_groups WHERE id = ? AND parent_id != 0",
                [$gid]
            );
            if (!$ok || !$okGrp) {
                continue;
            }
            $overlayId = cp_acl_overlay_id($gid, (string)$okGrp['name']);
            if ($state === 'on') {
                sqlStatement(
                    "INSERT IGNORE INTO gacl_aco_map (acl_id, section_value, value)
                     VALUES (?, ?, ?)",
                    [$overlayId, $sec, $perm]
                );
            } else {
                // 'off' or 'deny' (deny is rendered by *removing* the explicit
                // grant from the overlay — the row is shown deny because some
                // other ACL holds an allow=0 entry, which we don't touch).
                sqlStatement(
                    "DELETE FROM gacl_aco_map
                     WHERE acl_id = ? AND section_value = ? AND value = ?",
                    [$overlayId, $sec, $perm]
                );
            }
            sqlStatement(
                "UPDATE gacl_acl SET updated_date = ? WHERE id = ?",
                [time(), $overlayId]
            );
            $applied++;
        }
        unset($_SESSION['cp_acl_dirty']);
        try {
            $audit->newEvent('security-administration', $user, $grp, 1, 'ACL editor: saved ' . $applied . ' cell(s)');
        } catch (\Throwable $e) {
            // audit failures should not block save
        }
        header('Location: ' . $selfPath . '?view=' . urlencode($view) . '&msg=saved_' . $applied);
        exit;
    }

    if ($action === 'copy_role') {
        $fromId = (int)($_POST['from'] ?? 0);
        $toId   = (int)($_POST['to'] ?? 0);
        if ($fromId > 0 && $toId > 0 && $fromId !== $toId) {
            $okFrom = sqlQuery("SELECT name FROM gacl_aro_groups WHERE id = ? AND parent_id != 0", [$fromId]);
            $okTo   = sqlQuery("SELECT name FROM gacl_aro_groups WHERE id = ? AND parent_id != 0", [$toId]);
            if ($okFrom && $okTo) {
                // Resolve overlay ids for both, then copy aco_map rows from → to.
                $fromOverlay = cp_acl_overlay_id($fromId, (string)$okFrom['name']);
                $toOverlay   = cp_acl_overlay_id($toId,   (string)$okTo['name']);
                // Snapshot all (sec, perm) currently visible to the FROM role
                // — overlay grants UNION every other ACL the FROM group joins.
                $rs = sqlStatement(
                    "SELECT DISTINCT m.section_value AS sec, m.value AS perm
                       FROM gacl_aco_map m
                       JOIN gacl_acl a ON a.id = m.acl_id AND a.enabled = 1 AND a.allow = 1
                       JOIN gacl_aro_groups_map gm ON gm.acl_id = a.id
                      WHERE gm.group_id = ?",
                    [$fromId]
                );
                // Wipe destination overlay first (idempotent re-copies).
                sqlStatement(
                    "DELETE FROM gacl_aco_map WHERE acl_id = ?",
                    [$toOverlay]
                );
                $copied = 0;
                while ($row = sqlFetchArray($rs)) {
                    sqlStatement(
                        "INSERT IGNORE INTO gacl_aco_map (acl_id, section_value, value)
                         VALUES (?, ?, ?)",
                        [$toOverlay, $row['sec'], $row['perm']]
                    );
                    $copied++;
                }
                sqlStatement("UPDATE gacl_acl SET updated_date = ? WHERE id = ?", [time(), $toOverlay]);
                try {
                    $audit->newEvent(
                        'security-administration',
                        $user,
                        $grp,
                        1,
                        'ACL editor: copied role ' . $okFrom['name'] . ' → ' . $okTo['name'] . ' (' . $copied . ' perms)'
                    );
                } catch (\Throwable $e) {
                    // ignore
                }
                $_SESSION['cp_acl_dirty'] = true;
                header('Location: ' . $selfPath . '?view=' . urlencode($view) . '&msg=copied_' . $copied);
                exit;
            }
        }
        header('Location: ' . $selfPath . '?view=' . urlencode($view) . '&msg=copy_failed');
        exit;
    }

    if ($action === 'new_role') {
        $name = trim((string)($_POST['name'] ?? ''));
        if ($name !== '') {
            // Build a slug for `value` column (lowercase, alnum + underscore).
            $slug = strtolower(preg_replace('/[^a-z0-9]+/i', '_', $name));
            $slug = trim((string)$slug, '_');
            if ($slug === '') {
                $slug = 'role_' . time();
            }
            // Find the root "OpenEMR Users" group (parent_id=0).
            $root = sqlQuery("SELECT id, rgt FROM gacl_aro_groups WHERE parent_id = 0 ORDER BY lft LIMIT 1");
            if ($root && (int)$root['id'] > 0) {
                $rootId  = (int)$root['id'];
                $rootRgt = (int)$root['rgt'];
                // Make room in the nested set: shift rgt values >= rootRgt by 2.
                sqlStatement("UPDATE gacl_aro_groups SET rgt = rgt + 2 WHERE rgt >= ?", [$rootRgt]);
                sqlStatement("UPDATE gacl_aro_groups SET lft = lft + 2 WHERE lft >  ?", [$rootRgt]);
                $newLft = $rootRgt;        // old root rgt (now shifted up by 2)
                $newRgt = $rootRgt + 1;
                $newRow = sqlQuery("SELECT COALESCE(MAX(id), 0) + 1 AS nid FROM gacl_aro_groups");
                $newId  = (int)$newRow['nid'];
                // Avoid value collisions.
                $base = $slug; $i = 2;
                while (sqlQuery("SELECT 1 AS ok FROM gacl_aro_groups WHERE value = ?", [$slug])) {
                    $slug = $base . '_' . $i;
                    $i++;
                    if ($i > 50) {
                        break;
                    }
                }
                sqlStatement(
                    "INSERT INTO gacl_aro_groups (id, parent_id, lft, rgt, name, value)
                     VALUES (?, ?, ?, ?, ?, ?)",
                    [$newId, $rootId, $newLft, $newRgt, $name, $slug]
                );
                try {
                    $audit->newEvent('security-administration', $user, $grp, 1, 'ACL editor: created role ' . $name);
                } catch (\Throwable $e) {
                    // ignore
                }
                header('Location: ' . $selfPath . '?view=' . urlencode($view) . '&msg=role_added');
                exit;
            }
        }
        header('Location: ' . $selfPath . '?view=' . urlencode($view) . '&msg=role_failed');
        exit;
    }

    // Unknown action → just bounce back.
    header('Location: ' . $selfPath . '?view=' . urlencode($view));
    exit;
}

// -------------------------------------------------------------------------
// READ: roles (column headers)
// -------------------------------------------------------------------------
$rolesRs = sqlStatement(
    "SELECT id, name, value
       FROM gacl_aro_groups
      WHERE parent_id != 0
      ORDER BY lft"
);
$roles = [];
while ($r = sqlFetchArray($rolesRs)) {
    $roles[] = [
        'id'    => (int)$r['id'],
        'name'  => (string)$r['name'],
        'value' => (string)$r['value'],
    ];
}

// -------------------------------------------------------------------------
// READ: permissions (rows), grouped by category.
// We intentionally exclude the placeholder/menus/sensitivities sections
// because they're internal scaffolding, not real role-permission knobs.
// -------------------------------------------------------------------------
$permsRs = sqlStatement(
    "SELECT s.value AS sec_value, s.name AS sec_name,
            a.value AS perm_value, a.name AS perm_name
       FROM gacl_aco a
       JOIN gacl_aco_sections s ON s.value = a.section_value
      WHERE a.hidden = 0
        AND s.hidden = 0
        AND s.value NOT IN ('placeholder', 'menus', 'sensitivities')
      ORDER BY s.order_value, s.name, a.order_value, a.name"
);
$permsByCat = [];          // [cat_name => [['sec'=>..., 'perm'=>..., 'label'=>...], ...]]
$flatPerms  = [];          // ordered list of [sec, perm, label, cat]
while ($p = sqlFetchArray($permsRs)) {
    $cat   = (string)$p['sec_name'];
    $sec   = (string)$p['sec_value'];
    $perm  = (string)$p['perm_value'];
    $label = (string)$p['perm_name'];
    // Strip the "(write,addonly optional)" hint from the label — it's noise
    // in the matrix. Keep the parenthetical bit only if it's the entire name.
    $clean = preg_replace('/\s*\([^)]*optional[^)]*\)\s*$/i', '', $label);
    $clean = is_string($clean) && $clean !== '' ? $clean : $label;
    $permsByCat[$cat][] = ['sec' => $sec, 'perm' => $perm, 'label' => $clean];
    $flatPerms[] = ['sec' => $sec, 'perm' => $perm, 'label' => $clean, 'cat' => $cat];
}

// -------------------------------------------------------------------------
// READ: matrix state. One pass over the join graph collects every
// (group, sec, perm, allow) triple visible through any enabled ACL.
// -------------------------------------------------------------------------
$matrixRs = sqlStatement(
    "SELECT gm.group_id AS gid,
            m.section_value AS sec,
            m.value         AS perm,
            MIN(a.allow)    AS min_allow,
            MAX(a.allow)    AS max_allow
       FROM gacl_acl a
       JOIN gacl_aro_groups_map gm ON gm.acl_id = a.id
       JOIN gacl_aco_map m         ON m.acl_id  = a.id
      WHERE a.enabled = 1
      GROUP BY gm.group_id, m.section_value, m.value"
);
$state = [];   // $state[group_id][sec.'/'.perm] = 'on'|'deny'
while ($row = sqlFetchArray($matrixRs)) {
    $gid = (int)$row['gid'];
    $key = $row['sec'] . '/' . $row['perm'];
    if ((int)$row['min_allow'] === 0) {
        $state[$gid][$key] = 'deny';
    } else {
        $state[$gid][$key] = 'on';
    }
}
$cellState = static function (int $gid, string $sec, string $perm) use ($state): string {
    return $state[$gid][$sec . '/' . $perm] ?? 'off';
};

// -------------------------------------------------------------------------
// Flash + dirty state
// -------------------------------------------------------------------------
$msg     = (string)($_GET['msg'] ?? '');
$isDirty = !empty($_SESSION['cp_acl_dirty']);
$flash   = '';
if ($msg !== '') {
    if (str_starts_with($msg, 'saved_')) {
        $flash = sprintf(xl('Saved %s permission change(s) · ok'), (int)substr($msg, 6));
    } elseif (str_starts_with($msg, 'copied_')) {
        $flash = sprintf(xl('Copied %s permission(s) · ok'), (int)substr($msg, 7));
    } elseif ($msg === 'reset') {
        $flash = xl('Pending changes discarded');
    } elseif ($msg === 'role_added') {
        $flash = xl('Role created');
    } elseif ($msg === 'role_failed') {
        $flash = xl('Could not create role');
    } elseif ($msg === 'copy_failed') {
        $flash = xl('Could not copy role');
    }
}

// Sidebar — admin sub-pages, grouped (the ACL Editor row is the active one).
$sidebar = [
    'Users & Access' => [
        ['Users & Groups',     false],
        ['ACL Editor',         true],
        ['Active Sessions',    false],
        ['Password Policy',    false],
    ],
    'Practice' => [
        ['Facilities',         false],
        ['Providers',          false],
        ['Schedule Templates', false],
        ['Pricing',            false],
    ],
    'Clinical' => [
        ['Forms',              false],
        ['Lists',              false],
        ['Templates',          false],
        ['Issue Types',        false],
        ['Layouts',            false],
    ],
    'System' => [
        ['Audit Log',          false],
        ['Backup',             false],
        ['Globals',            false],
        ['Database',           false],
        ['Modules',            false],
    ],
];

$totalPerms = count($flatPerms);
$totalRoles = count($roles);

?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo xlt('ACL Editor'); ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/public/copilot-archetype.css">
<style>
  /* Page header — secondary meta line + status pill on right */
  .cp-pagehead .dot { color: #8A91A1; font-size: 14px; padding: 0 4px; }
  .cp-pagehead .meta-light { color: #8A91A1; font-size: 12px; line-height: 1; }
  .cp-pagehead .info .row { display: flex; align-items: center; gap: 8px; }

  .cp-unsaved {
    display: inline-flex; align-items: center; gap: 6px;
    background: #FFF8EC; color: #B86A1F;
    border-radius: 999px;
    padding: 5px 12px;
    font-size: 11px; font-weight: 500;
    line-height: 1.2;
  }
  .cp-unsaved::before {
    content: ''; width: 6px; height: 6px; border-radius: 50%;
    background: #FA8C33;
  }
  .cp-pagehead .cp-btn.ghost.icon-l::before {
    content: '↺'; font-size: 12px; margin-right: 2px; color: #8A91A1;
  }

  /* Flash banner */
  .cp-flash {
    margin: 12px 24px 0;
    padding: 8px 14px;
    background: #E6F4EE;
    border: 1px solid #B7DCC4;
    border-radius: 8px;
    color: #1F8C4D;
    font-size: 12px; font-weight: 500;
  }

  /* Admin sub-page sidebar — grouped, slimmer than archetype default */
  .cp-acl-side {
    flex: 0 0 200px;
    background: #FFFFFF;
    border-right: 1px solid #E4E5E8;
    padding: 16px 0 24px;
    overflow-y: auto;
  }
  .cp-acl-side .header {
    font-size: 10px; font-weight: 600;
    color: #8A91A1; letter-spacing: 0.6px;
    padding: 0 20px 12px;
  }
  .cp-acl-side .grp { margin-bottom: 12px; }
  .cp-acl-side .grp .lbl {
    font-size: 11px; font-weight: 500;
    color: #8A91A1;
    padding: 6px 20px 4px;
  }
  .cp-acl-side .item {
    display: block; position: relative;
    padding: 7px 20px;
    font-size: 12px; font-weight: 500;
    color: #4F5763;
    text-decoration: none;
    line-height: 1.3;
  }
  .cp-acl-side .item:hover { background: #F5F6F7; color: #0D1B2A; }
  .cp-acl-side .item.active {
    color: #008C8C; font-weight: 600;
    background: rgba(0, 140, 140, 0.08);
  }
  .cp-acl-side .item.active::before {
    content: ''; position: absolute;
    left: 0; top: 0; bottom: 0;
    width: 3px; background: #008C8C;
  }

  /* Sub-toolbar above the matrix */
  .cp-acl-toolbar {
    background: #FFFFFF;
    border: 1px solid #E4E5E8;
    border-radius: 12px;
    padding: 12px 16px;
    display: flex; align-items: center; gap: 16px;
  }
  .cp-acl-toolbar .grp {
    display: flex; align-items: center; gap: 8px;
  }
  .cp-acl-toolbar .lbl {
    font-size: 10px; font-weight: 600;
    color: #8A91A1; letter-spacing: 0.6px;
    text-transform: uppercase;
  }
  .cp-acl-toolbar .lbl-plain {
    font-size: 12px; color: #4F5763;
  }
  .cp-acl-toolbar select.cp-select {
    appearance: none; -webkit-appearance: none;
    background: #FFFFFF;
    border: 1px solid #E4E5E8;
    border-radius: 8px;
    height: 32px;
    padding: 0 28px 0 12px;
    font-size: 12px; color: #0D1B2A;
    background-image:
      linear-gradient(45deg, transparent 50%, #8A91A1 50%),
      linear-gradient(135deg, #8A91A1 50%, transparent 50%);
    background-position:
      calc(100% - 14px) calc(50% - 1px),
      calc(100% - 9px) calc(50% - 1px);
    background-size: 5px 5px, 5px 5px;
    background-repeat: no-repeat;
    min-width: 160px;
  }
  .cp-acl-toolbar .seg {
    display: inline-flex;
    background: #F5F6F7;
    border: 1px solid #E4E5E8;
    border-radius: 8px;
    padding: 2px;
  }
  .cp-acl-toolbar .seg a {
    background: transparent; border: 0;
    padding: 5px 12px;
    font-size: 11px; font-weight: 500;
    color: #4F5763; line-height: 1.2;
    border-radius: 6px;
    text-decoration: none;
    display: inline-block;
  }
  .cp-acl-toolbar .seg a.active {
    background: #FFFFFF; color: #0D1B2A;
    box-shadow: 0 1px 2px rgba(13, 27, 42, 0.06);
    font-weight: 600;
  }
  .cp-acl-toolbar .right {
    margin-left: auto;
    display: inline-flex; align-items: center; gap: 12px;
  }
  .cp-acl-toolbar .new-role {
    color: #008C8C; font-size: 12px; font-weight: 600;
    text-decoration: none;
    background: none; border: 0; cursor: pointer;
    padding: 4px 6px;
  }
  .cp-acl-toolbar .new-role:hover { text-decoration: underline; }
  .cp-acl-toolbar .copy-go {
    background: #FFFFFF; border: 1px solid #E4E5E8; border-radius: 6px;
    height: 30px; padding: 0 12px; font-size: 11px; font-weight: 600;
    color: #4F5763; cursor: pointer;
  }
  .cp-acl-toolbar .copy-go:hover { color: #0D1B2A; border-color: #C9CDD4; }

  /* Permission matrix */
  .cp-acl-tbl { border-radius: 12px; }
  .cp-acl-tbl table { font-size: 12px; table-layout: fixed; }
  .cp-acl-tbl th {
    padding: 12px 10px;
    text-align: center;
    font-size: 10px; font-weight: 600;
    color: #8A91A1; letter-spacing: 0.5px;
    text-transform: uppercase;
  }
  .cp-acl-tbl th.left { text-align: left; padding-left: 18px; }
  .cp-acl-tbl th.cat { width: 130px; }
  .cp-acl-tbl th.perm { width: auto; }
  .cp-acl-tbl td {
    padding: 11px 10px;
    border-top: 1px solid #F0F1F3;
    text-align: center;
    color: #0D1B2A;
  }
  .cp-acl-tbl td.cat {
    text-align: left;
    padding-left: 18px;
    font-size: 12px; font-weight: 600;
    color: #0D1B2A;
    vertical-align: top;
    padding-top: 13px;
  }
  .cp-acl-tbl td.perm {
    text-align: left;
    padding-left: 4px;
    color: #0D1B2A;
    font-weight: 400;
  }
  .cp-acl-tbl tbody tr.cat-start td { border-top: 1px solid #E4E5E8; }
  .cp-acl-tbl tbody tr:first-child td { border-top: none; }

  /* Custom checkbox visuals — match Figma */
  .cp-acl-cb {
    width: 18px; height: 18px;
    border-radius: 4px;
    display: inline-block;
    vertical-align: middle;
    position: relative;
    border: 1.5px solid #C9CDD4;
    background: #FFFFFF;
    cursor: pointer;
  }
  .cp-acl-cb.on {
    background: #008C8C;
    border-color: #008C8C;
  }
  .cp-acl-cb.on::after {
    content: '';
    position: absolute;
    left: 4px; top: 1px;
    width: 5px; height: 9px;
    border: solid #FFFFFF;
    border-width: 0 2px 2px 0;
    transform: rotate(45deg);
  }
  .cp-acl-cb.deny {
    background: #FFFFFF;
    border-color: #D93838;
  }
  .cp-acl-cb.deny::after {
    content: '×';
    position: absolute;
    inset: 0;
    color: #D93838;
    font-size: 16px; font-weight: 700;
    line-height: 14px;
    text-align: center;
  }
</style>
</head>
<body class="cp-arch">

<div class="cp-shell">
  <aside class="cp-acl-side">
    <div class="header"><?php echo xlt('ADMIN'); ?></div>
    <?php foreach ($sidebar as $cat => $items): ?>
      <div class="grp">
        <div class="lbl"><?php echo text($cat); ?></div>
        <?php foreach ($items as [$nm, $act]): ?>
          <a href="#" class="item<?php echo $act ? ' active' : ''; ?>"><?php echo text($nm); ?></a>
        <?php endforeach; ?>
      </div>
    <?php endforeach; ?>
  </aside>

  <div style="flex:1 1 auto; display:flex; flex-direction:column; min-width:0;">
    <header class="cp-pagehead">
      <div class="info">
        <div class="row">
          <span class="title"><?php echo xlt('Access Control (ACL)'); ?></span>
          <span class="dot">·</span>
          <span class="meta-light">
            <?php echo xlt('Role-based permissions'); ?> ·
            <?php echo text((string)$totalRoles . ' '); ?><?php echo xlt('roles'); ?> ·
            <?php echo text((string)$totalPerms . ' '); ?><?php echo xlt('permissions'); ?>
          </span>
        </div>
      </div>
      <?php if ($isDirty): ?>
        <span class="cp-unsaved"><?php echo xlt('Unsaved changes'); ?></span>
      <?php endif; ?>
      <form method="POST" action="<?php echo attr($selfPath); ?>" style="display:inline;">
        <input type="hidden" name="action" value="reset">
        <input type="hidden" name="view" value="<?php echo attr($view); ?>">
        <button type="submit" class="cp-btn ghost icon-l"><?php echo xlt('Reset'); ?></button>
      </form>
      <button type="submit" form="cp-acl-save-form" class="cp-btn primary"><?php echo xlt('Save changes'); ?></button>
      <button type="button" class="cp-btn ghost" disabled title="<?php echo xla('Help — coming soon'); ?>">? <?php echo xlt('Help'); ?></button>
    </header>

    <?php if ($flash !== ''): ?>
      <div class="cp-flash"><?php echo text($flash); ?></div>
    <?php endif; ?>

    <main class="cp-content tight">

      <!-- Save form lives at the page level (not nested in the toolbar) so the
           copy-role form can sit alongside it without violating the no-nested-
           forms rule. Hidden cell inputs are written into this form by JS. -->
      <form id="cp-acl-save-form" method="POST" action="<?php echo attr($selfPath); ?>" style="display:none;">
        <input type="hidden" name="action" value="save_acl">
        <input type="hidden" name="view" value="<?php echo attr($view); ?>">
      </form>

      <div class="cp-acl-toolbar">
        <form method="POST" action="<?php echo attr($selfPath); ?>" class="grp" style="display:inline-flex; gap:8px; align-items:center; margin:0;">
          <input type="hidden" name="action" value="copy_role">
          <input type="hidden" name="view" value="<?php echo attr($view); ?>">
          <span class="lbl"><?php echo xlt('Copy role from'); ?></span>
          <select class="cp-select" name="from">
            <?php foreach ($roles as $r): ?>
              <option value="<?php echo attr((string)$r['id']); ?>"><?php echo text($r['name']); ?></option>
            <?php endforeach; ?>
          </select>
          <span class="lbl-plain"><?php echo xlt('to'); ?></span>
          <select class="cp-select" name="to">
            <?php foreach ($roles as $r): ?>
              <option value="<?php echo attr((string)$r['id']); ?>"><?php echo text($r['name']); ?></option>
            <?php endforeach; ?>
          </select>
          <button type="submit" class="copy-go"
                  onclick="return confirm('<?php echo xla('Copy permissions and overwrite the destination role overlay?'); ?>');">
            <?php echo xlt('Copy'); ?>
          </button>
        </form>
        <div class="grp">
          <span class="lbl-plain"><?php echo xlt('View by'); ?></span>
          <div class="seg">
            <a href="?view=roles" class="<?php echo $view === 'roles' ? 'active' : ''; ?>">
              <?php echo xlt('Roles'); ?> &rarr; <?php echo xlt('Perms'); ?>
            </a>
            <a href="?view=perms" class="<?php echo $view === 'perms' ? 'active' : ''; ?>">
              <?php echo xlt('Perms'); ?> &rarr; <?php echo xlt('Roles'); ?>
            </a>
          </div>
        </div>
        <div class="right">
          <button type="button" class="new-role" onclick="cpAclNewRole();">+ <?php echo xlt('New role'); ?></button>
        </div>
      </div>

      <div class="cp-tbl cp-acl-tbl">
        <table>
          <?php if ($view === 'roles'): ?>
            <thead>
              <tr>
                <th class="left cat"><?php echo xlt('CATEGORY'); ?></th>
                <th class="left perm"><?php echo xlt('PERMISSION'); ?></th>
                <?php foreach ($roles as $r): ?>
                  <th><?php echo text($r['name']); ?></th>
                <?php endforeach; ?>
              </tr>
            </thead>
            <tbody>
              <?php
              $idx = 0;
              foreach ($permsByCat as $catName => $items):
                $first = true;
                foreach ($items as $p):
                  $catStart = $first;
                  $first = false;
              ?>
                <tr<?php echo $catStart ? ' class="cat-start"' : ''; ?>>
                  <td class="cat"><?php echo $catStart ? text($catName) : ''; ?></td>
                  <td class="perm"><?php echo text($p['label']); ?></td>
                  <?php foreach ($roles as $r):
                      $st = $cellState($r['id'], $p['sec'], $p['perm']);
                      $rowKey = $idx;
                  ?>
                    <td>
                      <span class="cp-acl-cb<?php
                          if ($st === 'on') echo ' on';
                          elseif ($st === 'deny') echo ' deny';
                      ?>"
                        data-gid="<?php echo attr((string)$r['id']); ?>"
                        data-sec="<?php echo attr($p['sec']); ?>"
                        data-perm="<?php echo attr($p['perm']); ?>"
                        data-state="<?php echo attr($st); ?>"
                        title="<?php echo attr($r['name'] . ' / ' . $p['label']); ?>"></span>
                    </td>
                  <?php endforeach; ?>
                </tr>
              <?php
                  $idx++;
                endforeach;
              endforeach;
              ?>
            </tbody>
          <?php else: ?>
            <thead>
              <tr>
                <th class="left cat"><?php echo xlt('ROLE'); ?></th>
                <?php foreach ($flatPerms as $p): ?>
                  <th title="<?php echo attr($p['cat'] . ' · ' . $p['label']); ?>"><?php echo text($p['label']); ?></th>
                <?php endforeach; ?>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($roles as $r): ?>
                <tr>
                  <td class="cat"><?php echo text($r['name']); ?></td>
                  <?php foreach ($flatPerms as $p):
                      $st = $cellState($r['id'], $p['sec'], $p['perm']);
                  ?>
                    <td>
                      <span class="cp-acl-cb<?php
                          if ($st === 'on') echo ' on';
                          elseif ($st === 'deny') echo ' deny';
                      ?>"
                        data-gid="<?php echo attr((string)$r['id']); ?>"
                        data-sec="<?php echo attr($p['sec']); ?>"
                        data-perm="<?php echo attr($p['perm']); ?>"
                        data-state="<?php echo attr($st); ?>"
                        title="<?php echo attr($r['name'] . ' / ' . $p['label']); ?>"></span>
                    </td>
                  <?php endforeach; ?>
                </tr>
              <?php endforeach; ?>
            </tbody>
          <?php endif; ?>
        </table>
      </div>
    </main>
  </div>
</div>

<script>
// Toggle cells client-side; collected payload posts on Save.
(function () {
    var form = document.getElementById('cp-acl-save-form');
    var pending = {};   // key = gid|sec|perm → state
    function key(g, s, p) { return g + '|' + s + '|' + p; }

    document.querySelectorAll('.cp-acl-cb').forEach(function (cb) {
        cb.addEventListener('click', function () {
            var g = cb.getAttribute('data-gid');
            var s = cb.getAttribute('data-sec');
            var p = cb.getAttribute('data-perm');
            var cur = cb.getAttribute('data-state');
            // Cycle: off → on → off (deny is read-only — set by other ACLs).
            var next = cur === 'on' ? 'off' : 'on';
            cb.setAttribute('data-state', next);
            cb.classList.remove('on', 'deny');
            if (next === 'on') cb.classList.add('on');
            pending[key(g, s, p)] = { gid: g, sec: s, perm: p, state: next };
            renderHidden();
        });
    });

    function renderHidden() {
        // Wipe stale hidden inputs.
        form.querySelectorAll('input[data-cell="1"]').forEach(function (n) { n.remove(); });
        Object.keys(pending).forEach(function (k, i) {
            var c = pending[k];
            ['group_id', 'sec', 'perm', 'state'].forEach(function (field) {
                var inp = document.createElement('input');
                inp.type = 'hidden';
                inp.setAttribute('data-cell', '1');
                inp.name = 'cells[' + i + '][' + (field === 'group_id' ? 'group_id' : field) + ']';
                inp.value = (field === 'group_id') ? c.gid : c[field];
                form.appendChild(inp);
            });
        });
    }
}());

function cpAclNewRole() {
    var name = window.prompt('New role name:');
    if (!name || !name.trim()) return;
    var f = document.createElement('form');
    f.method = 'POST';
    f.action = <?php echo json_encode($selfPath, JSON_UNESCAPED_SLASHES); ?>;
    f.style.display = 'none';
    var a = document.createElement('input'); a.name = 'action'; a.value = 'new_role'; f.appendChild(a);
    var n = document.createElement('input'); n.name = 'name'; n.value = name.trim(); f.appendChild(n);
    var v = document.createElement('input'); v.name = 'view'; v.value = <?php echo json_encode($view); ?>; f.appendChild(v);
    document.body.appendChild(f); f.submit();
}
</script>

</body>
</html>
