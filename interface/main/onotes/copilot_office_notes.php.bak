<?php

/**
 * Office Notes - Screen 49.
 *
 * Cross-staff bulletin / sticky-note board for internal staff notes &
 * announcements (not part of the patient chart).
 *
 * Source of truth: `onotes` table. The base schema
 * (`id, date, body, user, groupname, activity`) doesn't model pinning,
 * categories, or threaded replies, so we extend it with three Co-Pilot
 * columns:
 *
 *   - `cp_pinned`    TINYINT NOT NULL DEFAULT 0  - sort-to-top flag
 *   - `cp_category`  VARCHAR(40) NOT NULL DEFAULT 'general'
 *   - `cp_parent_id` BIGINT NULL DEFAULT NULL    - parent note id (for replies)
 *
 * Schema migrations and demo seed run idempotently on first page hit,
 * gated by a `globals.copilot_onotes_seed_v1` marker.
 *
 * The chrome (top nav, etc.) is rendered by the parent shell - this page
 * renders only the body. Page is not patient-scoped.
 *
 * @package OpenEMR
 * @author  AgentForge / Claude Code
 * @license https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

require_once(__DIR__ . "/../../globals.php");
require_once(__DIR__ . "/../copilot_helpers.php");

use OpenEMR\Common\Logging\EventAuditLogger;

// -------------------------------------------------------------------------
// Category taxonomy (slug => [Display label, sticky tone, emoji icon])
// -------------------------------------------------------------------------
$catMeta = [
    'pharmacy'   => ['Pharmacy',    'yellow', "\u{1F48A}"],   // pill
    'clinical'   => ['Clinical',    'blue',   "\u{1FA7A}"],   // stethoscope
    'front_desk' => ['Front desk',  'violet', "\u{1F6AA}"],   // door
    'billing'    => ['Billing',     'pink',   "\u{1F4B5}"],   // dollar
    'maintenance' => ['Maintenance','violet', "\u{1F6E0}"],   // hammer & wrench
    'general'    => ['General',     'yellow', "\u{1F4CC}"],   // pushpin
];

// Avatar tone palette - cycle by author's user id so the same person
// always gets the same coloured chip across the board.
$avatarTones = ['teal', 'blue', 'orange', 'green', 'pink', 'mint', 'purple'];

// -------------------------------------------------------------------------
// Idempotent schema migration + demo seed.
// Guarded by globals.copilot_onotes_seed_v1 marker; safe to run on every
// page load. Adding a column twice is a no-op (information_schema check).
// -------------------------------------------------------------------------
$dbName = (string)(sqlQuery("SELECT DATABASE() AS d")['d'] ?? '');

$columnsToAdd = [
    'cp_pinned'    => "ALTER TABLE onotes ADD COLUMN cp_pinned TINYINT NOT NULL DEFAULT 0",
    'cp_category'  => "ALTER TABLE onotes ADD COLUMN cp_category VARCHAR(40) NOT NULL DEFAULT 'general'",
    'cp_parent_id' => "ALTER TABLE onotes ADD COLUMN cp_parent_id BIGINT NULL DEFAULT NULL",
];
foreach ($columnsToAdd as $col => $ddl) {
    $exists = sqlQuery(
        "SELECT 1 AS x FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'onotes' AND COLUMN_NAME = ?",
        [$dbName, $col]
    );
    if (empty($exists['x'])) {
        try {
            sqlStatement($ddl);
        } catch (\Throwable $t) {
            // Swallow - other concurrent request may have raced us.
        }
    }
}

$seedMarker = sqlQuery("SELECT gl_value FROM globals WHERE gl_name = 'copilot_onotes_seed_v1'");
if (empty($seedMarker['gl_value'])) {
    // 10 demo notes spread across categories. (username, category, pinned, days_ago, body)
    // Usernames map to seeded users in this dev DB:
    //   admin (id=1), erivera (Dr. Rivera, MD, id=5), apark (Dr. Park, DO, id=6),
    //   jpatel (Dr. Patel, MD, id=7), llee (Dr. Lee, MD, id=8), kkim (Dr. Kim, MD, id=9),
    //   mnunez (Maria Nunez, id=10), schoi (Sandra Choi, RN, id=11),
    //   bhudson (Brian Hudson, id=12).
    $demoNotes = [
        ['schoi',   'pharmacy',    1, 2,  'Walgreens Tarrytown is closed for renovation through 5/15. Reroute Rx to Walgreens 38th St until further notice.'],
        ['erivera', 'clinical',    1, 3,  'Dr. Patel out 5/3-5/7 for conference. NP Jones covering acute slots, Dr. Chen covering established patients.'],
        ['mnunez',  'pharmacy',    0, 3,  'New shipment of Shingrix arrived - 80 doses. Stocked in vaccine fridge B. PIN: 4 (cold chain log updated).'],
        ['admin',   'billing',     0, 4,  'Aetna Claims edit 2026-Q2: HCPCS G0438 needs Z-code modifier through end of quarter. Will revert in Q3.'],
        ['schoi',   'front_desk',  0, 4,  'Lobby coffee machine making weird grinding noise. Maintenance ticketed (#WO-4429). Avoid until repaired.'],
        ['llee',    'pharmacy',    0, 5,  'Sun Pharma announced shortage of generic levothyroxine 50/75/100 mcg - 6 week ETA. Use alternate manufacturers.'],
        ['erivera', 'clinical',    0, 5,  'Reminder: Q1 2026 CQM submission deadline is 3/31/2027. Marcus pulling preliminary scores end of week.'],
        ['bhudson', 'maintenance', 0, 6,  'Exam 5 BP cuff replaced (old cuff readings ran 8-10 mmHg low). New cuff calibrated 4/26.'],
        ['admin',   'billing',     0, 6,  'Reminder to use updated Z-code list for SDOH screening (Z55-Z65). Reimbursement increased 4/1.'],
        ['erivera', 'clinical',    0, 6,  'Pt Margaret Chen has new Penicillin allergy entered 4/26 - please verify chart and update e-Rx allergy list.'],
    ];
    foreach ($demoNotes as $n) {
        [$user, $cat, $pinned, $daysAgo, $body] = $n;
        $when = date('Y-m-d H:i:s', strtotime("-$daysAgo days") + (8 * 3600) + (mt_rand(0, 480) * 60));
        sqlStatement(
            "INSERT INTO onotes
                (date, body, user, groupname, activity, cp_pinned, cp_category, cp_parent_id)
             VALUES (?, ?, ?, 'Default', 1, ?, ?, NULL)",
            [$when, $body, $user, $pinned, $cat]
        );
    }
    sqlStatement(
        "INSERT INTO globals (gl_name, gl_index, gl_value) VALUES ('copilot_onotes_seed_v1', 0, ?)",
        [date('c')]
    );
}

// -------------------------------------------------------------------------
// POST handlers (run before any output, redirect on success).
// CSRF skipped - internal mock page; the surrounding OpenEMR auth gate
// (`globals.php` -> `authCheckCore()`) prevents anonymous POSTs.
// -------------------------------------------------------------------------
$selfPath = $_SERVER['PHP_SELF'];
$validCategories = array_keys($catMeta);

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $action = (string)($_POST['action'] ?? '');
    $authUser = (string)($_SESSION['authUser'] ?? 'admin');
    $authGroup = (string)($_SESSION['authProvider'] ?? 'Default');

    if ($action === 'new_note') {
        $body = trim((string)($_POST['body'] ?? ''));
        $cat  = (string)($_POST['category'] ?? 'general');
        if (!in_array($cat, $validCategories, true)) {
            $cat = 'general';
        }
        if ($body !== '') {
            sqlStatement(
                "INSERT INTO onotes
                    (date, body, user, groupname, activity, cp_pinned, cp_category, cp_parent_id)
                 VALUES (NOW(), ?, ?, ?, 1, 0, ?, NULL)",
                [$body, $authUser, $authGroup, $cat]
            );
            try {
                EventAuditLogger::getInstance()->newEvent(
                    'office_note_create',
                    $authUser,
                    $authGroup,
                    1,
                    'copilot_office_notes new_note category=' . $cat
                );
            } catch (\Throwable $t) {
                // Audit failure should not block the user-facing flow.
            }
            header('Location: ' . $selfPath . '?msg=created');
            exit;
        }
        header('Location: ' . $selfPath . '?msg=create_failed');
        exit;
    }

    if ($action === 'archive') {
        $rawIds = $_POST['ids'] ?? [];
        if (!is_array($rawIds)) {
            $rawIds = [$rawIds];
        }
        $ids = [];
        foreach ($rawIds as $i) {
            $n = (int)$i;
            if ($n > 0) {
                $ids[] = $n;
            }
        }
        $count = 0;
        if ($ids !== []) {
            $place = implode(',', array_fill(0, count($ids), '?'));
            sqlStatement(
                "UPDATE onotes SET activity = 0 WHERE id IN ($place)",
                $ids
            );
            $count = count($ids);
            try {
                EventAuditLogger::getInstance()->newEvent(
                    'office_note_archive',
                    $authUser,
                    $authGroup,
                    1,
                    'copilot_office_notes archive ids=' . implode(',', $ids)
                );
            } catch (\Throwable $t) {
                // Same: log failures should not block the redirect.
            }
        }
        header('Location: ' . $selfPath . '?msg=archived_' . $count);
        exit;
    }

    if ($action === 'reply') {
        $noteId = (int)($_POST['note_id'] ?? 0);
        $body   = trim((string)($_POST['body'] ?? ''));
        if ($noteId > 0 && $body !== '') {
            // Inherit category from the parent so reply stays in the same
            // filter bucket as the parent note.
            $parent = sqlQuery("SELECT cp_category FROM onotes WHERE id = ?", [$noteId]);
            $cat = (string)($parent['cp_category'] ?? 'general');
            if (!in_array($cat, $validCategories, true)) {
                $cat = 'general';
            }
            sqlStatement(
                "INSERT INTO onotes
                    (date, body, user, groupname, activity, cp_pinned, cp_category, cp_parent_id)
                 VALUES (NOW(), ?, ?, ?, 1, 0, ?, ?)",
                [$body, $authUser, $authGroup, $cat, $noteId]
            );
            header('Location: ' . $selfPath . '?msg=replied_' . $noteId);
            exit;
        }
        header('Location: ' . $selfPath . '?msg=reply_failed');
        exit;
    }

    if ($action === 'pin') {
        $noteId = (int)($_POST['note_id'] ?? 0);
        $value  = (int)($_POST['value'] ?? 0) === 1 ? 1 : 0;
        if ($noteId > 0) {
            sqlStatement("UPDATE onotes SET cp_pinned = ? WHERE id = ?", [$value, $noteId]);
            header('Location: ' . $selfPath . '?msg=pinned_' . $noteId);
            exit;
        }
        header('Location: ' . $selfPath . '?msg=pin_failed');
        exit;
    }
}

// -------------------------------------------------------------------------
// GET filters: category pill + sort.
// -------------------------------------------------------------------------
$cat = strtolower((string)($_GET['cat'] ?? 'all'));
$validCatFilters = array_merge(['all', 'pinned'], $validCategories);
if (!in_array($cat, $validCatFilters, true)) {
    $cat = 'all';
}

$sort = (string)($_GET['sort'] ?? 'newest');
if (!in_array($sort, ['newest', 'oldest', 'pinned'], true)) {
    $sort = 'newest';
}

$where = 'o.activity = 1 AND o.cp_parent_id IS NULL';
$params = [];
if ($cat === 'pinned') {
    $where .= ' AND o.cp_pinned = 1';
} elseif ($cat !== 'all') {
    $where .= ' AND o.cp_category = ?';
    $params[] = $cat;
}

$orderBy = match ($sort) {
    'oldest' => 'o.date ASC',
    'pinned' => 'o.cp_pinned DESC, o.date DESC',
    default  => 'o.cp_pinned DESC, o.date DESC',
};

// -------------------------------------------------------------------------
// Counts for pill badges (always reflect the full dataset, not the active
// filter - so the user can see how many would be in each bucket).
// -------------------------------------------------------------------------
$counts = ['all' => 0, 'pinned' => 0];
foreach ($validCategories as $slug) {
    $counts[$slug] = 0;
}
$rsCount = sqlStatement(
    "SELECT cp_category, cp_pinned, COUNT(*) AS c
     FROM onotes
     WHERE activity = 1 AND cp_parent_id IS NULL
     GROUP BY cp_category, cp_pinned"
);
while ($cr = sqlFetchArray($rsCount)) {
    $cSlug = (string)($cr['cp_category'] ?? 'general');
    $cN    = (int)($cr['c'] ?? 0);
    $counts['all'] += $cN;
    if ((int)$cr['cp_pinned'] === 1) {
        $counts['pinned'] += $cN;
    }
    if (isset($counts[$cSlug])) {
        $counts[$cSlug] += $cN;
    }
}

// -------------------------------------------------------------------------
// Fetch top-level notes joined to their author (users.username) and a
// reply-count subquery.
// -------------------------------------------------------------------------
$sql = "SELECT o.id, o.date, o.body, o.user AS author_username,
               o.cp_pinned, o.cp_category,
               u.id AS user_id, u.fname, u.lname, u.title, u.username,
               (SELECT COUNT(*) FROM onotes c
                 WHERE c.cp_parent_id = o.id AND c.activity = 1) AS reply_count
        FROM onotes o
        LEFT JOIN users u ON u.username = o.user
        WHERE $where
        ORDER BY $orderBy
        LIMIT 10";
$rs = sqlStatement($sql, $params);

$notes = [];
while ($r = sqlFetchArray($rs)) {
    $catKey = (string)($r['cp_category'] ?? 'general');
    [$catLabel, $catTone, $catIcon] = $catMeta[$catKey] ?? $catMeta['general'];

    $authorRow = [
        'fname'    => (string)($r['fname'] ?? ''),
        'lname'    => (string)($r['lname'] ?? ''),
        'title'    => (string)($r['title'] ?? ''),
        'username' => (string)($r['username'] ?? $r['author_username'] ?? ''),
    ];
    // If users join missed (e.g. legacy free-text user), fall back to the raw value.
    $authorName = $authorRow['username'] !== ''
        ? cp_format_provider_name($authorRow)
        : ((string)$r['author_username'] !== '' ? (string)$r['author_username'] : 'Unknown');

    $initials = cp_initials($authorRow);
    $userId = (int)($r['user_id'] ?? 0);
    $tone = $userId > 0
        ? $avatarTones[$userId % count($avatarTones)]
        : $avatarTones[0];

    $when = (string)($r['date'] ?? '');
    $whenDisplay = $when !== '' ? date('m/d H:i', strtotime($when)) : '';

    $notes[] = [
        'id'         => (int)$r['id'],
        'category'   => strtoupper($catLabel),
        'cat_slug'   => $catKey,
        'icon'       => $catIcon,
        'tone'       => $catTone,
        'pinned'     => (int)$r['cp_pinned'] === 1,
        'body'       => (string)$r['body'],
        'author'     => $authorName,
        'avatar'     => $tone,
        'initials'   => $initials,
        'when'       => $whenDisplay,
        'comments'   => (int)($r['reply_count'] ?? 0),
    ];
}

// Filter pill metadata, in display order.
$filters = [
    ['slug' => 'all',         'label' => 'All',         'count' => $counts['all']],
    ['slug' => 'pinned',      'label' => 'Pinned',      'count' => $counts['pinned']],
    ['slug' => 'pharmacy',    'label' => 'Pharmacy',    'count' => $counts['pharmacy']],
    ['slug' => 'clinical',    'label' => 'Clinical',    'count' => $counts['clinical']],
    ['slug' => 'front_desk',  'label' => 'Front desk',  'count' => $counts['front_desk']],
    ['slug' => 'billing',     'label' => 'Billing',     'count' => $counts['billing']],
    ['slug' => 'maintenance', 'label' => 'Maintenance', 'count' => $counts['maintenance']],
];

$flash = (string)($_GET['msg'] ?? '');
$showNew = (string)($_GET['new'] ?? '') === '1';
$totalNotes = $counts['all'];

?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo xlt('Office Notes'); ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/public/copilot-archetype.css">
<style>
  /* Page header layout overrides for Office Notes */
  .cp-on-head {
    display: flex; align-items: center;
    gap: 10px;
    padding: 14px 24px;
    background: #FFFFFF;
    border-bottom: 1px solid #E4E5E8;
  }
  .cp-on-head .title {
    font-size: 18px; font-weight: 700;
    color: #181D26; line-height: 1;
  }
  .cp-on-head .bullet { color: #8A91A1; font-size: 16px; line-height: 1; }
  .cp-on-head .meta {
    font-size: 13px; color: #4F5763; line-height: 1;
  }
  .cp-on-head .spacer { flex: 1; }
  .cp-on-head .cp-btn { padding: 7px 16px; font-size: 13px; }
  .cp-on-head .help-pill {
    background: #F5F6F7; color: #4F5763;
    border-radius: 12px;
    padding: 5px 12px;
    font-size: 12px; font-weight: 500;
    line-height: 1;
    border: none;
  }
  .cp-on-head .help-pill[disabled] { cursor: not-allowed; opacity: 0.65; }

  /* Flash banner */
  .cp-flash {
    margin: 0 24px;
    margin-top: 10px;
    padding: 8px 12px;
    border-radius: 8px;
    font-size: 12px;
    background: #E6F5F5;
    color: #006B6B;
    border: 1px solid #B5DDDD;
  }

  /* Filter bar */
  .cp-on-filter {
    display: flex; align-items: center;
    gap: 8px;
    padding: 10px 24px;
    background: #FFFFFF;
    border-bottom: 1px solid #E4E5E8;
  }
  .cp-on-filter .pill {
    background: #FFFFFF;
    border: 1px solid #E4E5E8;
    border-radius: 14px;
    height: 28px;
    padding: 0 11px;
    display: inline-flex; align-items: center; gap: 8px;
    font-size: 12px; font-weight: 500;
    color: #4F5763;
    line-height: 1;
    text-decoration: none;
  }
  .cp-on-filter .pill.active {
    background: #E6F5F5;
    border-color: #008C8C;
    color: #008C8C;
  }
  .cp-on-filter .pill .ct {
    background: #F5F6F7;
    color: #4F5763;
    border-radius: 9px;
    height: 18px; min-width: 22px;
    padding: 0 6px;
    display: inline-flex; align-items: center; justify-content: center;
    font-size: 10px; font-weight: 600;
    line-height: 1;
  }
  .cp-on-filter .pill.active .ct {
    background: #008C8C;
    color: #FFFFFF;
  }
  .cp-on-filter .sort {
    margin-left: auto;
    font-size: 11px; font-weight: 500;
    color: #4F5763;
  }
  .cp-on-filter .sort select {
    border: 1px solid #E4E5E8; background: #FFFFFF;
    border-radius: 8px; padding: 4px 6px;
    font-size: 11px; color: #181D26;
    margin-left: 4px;
  }

  /* Inline new-note form (revealed on +New office note) */
  .cp-newnote {
    margin: 12px 24px 0;
    background: #FFFFFF;
    border: 1px solid #E4E5E8;
    border-radius: 12px;
    padding: 14px 16px;
    display: flex; flex-direction: column; gap: 10px;
  }
  .cp-newnote label { font-size: 11px; font-weight: 600; color: #4F5763; }
  .cp-newnote textarea {
    border: 1px solid #E4E5E8;
    border-radius: 8px;
    font-family: inherit;
    font-size: 13px;
    padding: 8px 10px;
    min-height: 64px;
    resize: vertical;
  }
  .cp-newnote .row {
    display: flex; gap: 12px; align-items: center;
  }
  .cp-newnote select {
    border: 1px solid #E4E5E8;
    border-radius: 8px;
    font-size: 12px; padding: 5px 8px;
  }
  .cp-newnote .actions { margin-left: auto; display: flex; gap: 8px; }

  /* Board grid */
  .cp-on-board {
    padding: 16px 24px 32px;
    display: grid;
    grid-template-columns: repeat(5, 1fr);
    gap: 18px;
  }
  .cp-on-empty {
    grid-column: 1 / -1;
    text-align: center;
    color: #8A91A1;
    font-size: 13px;
    padding: 40px 0;
  }

  /* Sticky-note card */
  .cp-on-note {
    border-radius: 12px;
    padding: 16px;
    min-height: 336px;
    display: flex; flex-direction: column;
    position: relative;
  }
  .cp-on-note.tone-yellow { background: #FFF3BB; }
  .cp-on-note.tone-blue   { background: #DEEDFB; }
  .cp-on-note.tone-green  { background: #DFF6EA; }
  .cp-on-note.tone-pink   { background: #FFE1ED; }
  .cp-on-note.tone-violet { background: #EAE4FB; }

  .cp-on-note .top {
    display: flex; align-items: flex-start; gap: 10px;
    margin-bottom: 16px;
  }
  .cp-on-note .icon {
    font-size: 18px; line-height: 1;
    flex: 0 0 auto;
  }
  .cp-on-note .cat {
    font-size: 10px; font-weight: 700;
    color: #4F5763;
    letter-spacing: 0.4px;
    line-height: 1;
    margin-top: 4px;
  }
  .cp-on-note .pinned-pill {
    margin-left: auto;
    background: #FFF4EA;
    color: #FA8C33;
    border-radius: 9px;
    padding: 3px 10px;
    font-size: 10px; font-weight: 600;
    line-height: 1.2;
    border: none;
    cursor: pointer;
  }
  .cp-on-note .pin-form {
    margin-left: auto;
  }
  .cp-on-note .pin-form .pin-toggle {
    background: rgba(255, 255, 255, 0.6);
    color: #8A91A1;
    border-radius: 9px;
    padding: 3px 8px;
    font-size: 10px; font-weight: 600;
    line-height: 1.2;
    border: none;
    cursor: pointer;
  }
  .cp-on-note .body {
    font-size: 12px;
    color: #181D26;
    line-height: 1.45;
    flex: 1;
    white-space: pre-wrap;
  }
  .cp-on-note .divider {
    height: 1px;
    background: rgba(138, 145, 161, 0.5);
    margin: 12px 0 12px;
  }
  .cp-on-note .author-row {
    display: flex; align-items: center; gap: 10px;
    margin-bottom: 12px;
  }
  .cp-on-note .author-row .av {
    width: 20px; height: 20px;
    border-radius: 50%;
    flex: 0 0 auto;
    display: inline-flex; align-items: center; justify-content: center;
    color: #FFFFFF;
    font-size: 9px; font-weight: 700;
  }
  .cp-on-note .author-row .av.teal   { background: #008C8C; }
  .cp-on-note .author-row .av.blue   { background: #4785D9; }
  .cp-on-note .author-row .av.purple { background: #8561C7; }
  .cp-on-note .author-row .av.orange { background: #FA8C33; }
  .cp-on-note .author-row .av.green  { background: #33A666; }
  .cp-on-note .author-row .av.pink   { background: #D9668C; }
  .cp-on-note .author-row .av.mint   { background: #33A68C; }
  .cp-on-note .author-row .who { display: flex; flex-direction: column; gap: 2px; }
  .cp-on-note .author-row .name { font-size: 11px; font-weight: 600; color: #181D26; line-height: 1; }
  .cp-on-note .author-row .when { font-size: 10px; color: #4F5763; line-height: 1; }
  .cp-on-note .foot {
    display: flex; align-items: center;
    gap: 12px;
  }
  .cp-on-note .foot label.sel {
    font-size: 10px; color: #4F5763;
    display: inline-flex; align-items: center; gap: 4px;
  }
  .cp-on-note .foot .comments {
    font-size: 11px; color: #4F5763;
  }
  .cp-on-note .foot .reply-toggle {
    margin-left: auto;
    font-size: 11px; font-weight: 600;
    color: #008C8C;
    text-decoration: none;
    background: none; border: none; cursor: pointer; padding: 0;
  }
  .cp-on-note .reply-form {
    margin-top: 10px;
    display: flex; flex-direction: column; gap: 6px;
  }
  .cp-on-note .reply-form textarea {
    width: 100%;
    border: 1px solid rgba(138,145,161,0.5);
    border-radius: 6px;
    background: rgba(255,255,255,0.6);
    font-family: inherit; font-size: 11px;
    padding: 6px 8px; resize: vertical;
    min-height: 44px;
  }
  .cp-on-note .reply-form .reply-actions { display: flex; gap: 6px; justify-content: flex-end; }
  .cp-on-note .reply-form button {
    background: #008C8C; color: #FFFFFF;
    border: none; border-radius: 6px;
    font-size: 10px; font-weight: 600;
    padding: 4px 10px; cursor: pointer;
  }
</style>
<script>
  function cpToggleNew() {
    var el = document.getElementById('cp-newnote');
    if (el) { el.style.display = (el.style.display === 'none' || !el.style.display) ? 'flex' : 'none'; }
  }
  function cpToggleReply(id) {
    var el = document.getElementById('reply-' + id);
    if (el) { el.style.display = (el.style.display === 'none' || !el.style.display) ? 'flex' : 'none'; }
  }
</script>
</head>
<body class="cp-arch">

<form method="post" action="<?php echo attr($selfPath); ?>" id="cp-archive-form">
<input type="hidden" name="action" value="archive">

<header class="cp-on-head">
  <span class="title"><?php echo xlt('Office Notes'); ?></span>
  <span class="bullet">&bull;</span>
  <span class="meta"><?php echo xlt('Internal staff notes & announcements'); ?> &middot; <?php echo xlt('Riverside Family Medicine'); ?> &middot; <?php echo text((string) $totalNotes); ?> <?php echo xlt('total'); ?></span>
  <span class="spacer"></span>
  <button type="submit" class="cp-btn ghost" title="<?php echo xla('Archive selected notes'); ?>"><span aria-hidden="true">&#x232B;</span> <?php echo xlt('Archive'); ?></button>
  <button type="button" class="cp-btn primary" onclick="cpToggleNew()">+ <?php echo xlt('New office note'); ?></button>
  <button type="button" class="help-pill" disabled title="<?php echo xla('Help is out of scope for this demo'); ?>">? <?php echo xlt('Help'); ?></button>
</header>

<?php if ($flash !== ''): ?>
  <div class="cp-flash">
    <?php
    $flashMap = [
        'created'        => xl('Office note posted.'),
        'create_failed'  => xl('Could not post note - body was empty.'),
        'reply_failed'   => xl('Reply failed - body was empty.'),
        'pin_failed'     => xl('Pin update failed.'),
    ];
    if (isset($flashMap[$flash])) {
        echo text($flashMap[$flash]);
    } elseif (preg_match('/^archived_(\d+)$/', $flash, $mm)) {
        echo text(sprintf(xl('Archived %d note(s).'), (int)$mm[1]));
    } elseif (preg_match('/^replied_(\d+)$/', $flash, $mm)) {
        echo text(sprintf(xl('Reply posted to note #%d.'), (int)$mm[1]));
    } elseif (preg_match('/^pinned_(\d+)$/', $flash, $mm)) {
        echo text(sprintf(xl('Pin updated for note #%d.'), (int)$mm[1]));
    } else {
        echo text($flash);
    }
    ?>
  </div>
<?php endif; ?>

<div class="cp-on-filter">
  <?php foreach ($filters as $f): ?>
    <?php $isActive = ($cat === $f['slug']) || ($cat === 'all' && $f['slug'] === 'all'); ?>
    <a class="pill<?php echo $isActive ? ' active' : ''; ?>"
       href="?cat=<?php echo attr($f['slug']); ?>&sort=<?php echo attr($sort); ?>">
      <?php echo text($f['label']); ?>
      <span class="ct"><?php echo text((string) $f['count']); ?></span>
    </a>
  <?php endforeach; ?>
  <span class="sort">
    <?php echo xlt('Sort'); ?>:
    <select onchange="window.location.href='?cat=<?php echo attr($cat); ?>&sort=' + this.value">
      <option value="newest" <?php echo $sort === 'newest' ? 'selected' : ''; ?>><?php echo xlt('Newest'); ?></option>
      <option value="oldest" <?php echo $sort === 'oldest' ? 'selected' : ''; ?>><?php echo xlt('Oldest'); ?></option>
      <option value="pinned" <?php echo $sort === 'pinned' ? 'selected' : ''; ?>><?php echo xlt('Pinned first'); ?></option>
    </select>
  </span>
</div>

</form>

<div id="cp-newnote" class="cp-newnote" style="display: <?php echo $showNew ? 'flex' : 'none'; ?>;">
  <form method="post" action="<?php echo attr($selfPath); ?>">
    <input type="hidden" name="action" value="new_note">
    <label for="newnote-body"><?php echo xlt('New office note'); ?></label>
    <textarea id="newnote-body" name="body" placeholder="<?php echo xla('What does the team need to know?'); ?>" required></textarea>
    <div class="row">
      <label for="newnote-cat"><?php echo xlt('Category'); ?>:</label>
      <select id="newnote-cat" name="category">
        <?php foreach ($validCategories as $slug): ?>
          <?php $label = $catMeta[$slug][0]; ?>
          <option value="<?php echo attr($slug); ?>"><?php echo text($label); ?></option>
        <?php endforeach; ?>
      </select>
      <div class="actions">
        <button type="button" class="cp-btn ghost" onclick="cpToggleNew()"><?php echo xlt('Cancel'); ?></button>
        <button type="submit" class="cp-btn primary"><?php echo xlt('Post note'); ?></button>
      </div>
    </div>
  </form>
</div>

<div class="cp-on-board">
  <?php if ($notes === []): ?>
    <div class="cp-on-empty"><?php echo xlt('No office notes match this filter.'); ?></div>
  <?php endif; ?>
  <?php foreach ($notes as $n): ?>
    <div class="cp-on-note tone-<?php echo attr($n['tone']); ?>">
      <div class="top">
        <span class="icon"><?php echo text($n['icon']); ?></span>
        <span class="cat"><?php echo text($n['category']); ?></span>
        <?php if ($n['pinned']): ?>
          <form method="post" action="<?php echo attr($selfPath); ?>" class="pin-form" title="<?php echo xla('Click to unpin'); ?>">
            <input type="hidden" name="action" value="pin">
            <input type="hidden" name="note_id" value="<?php echo attr((string)$n['id']); ?>">
            <input type="hidden" name="value" value="0">
            <button type="submit" class="pinned-pill"><?php echo xlt('Pinned'); ?></button>
          </form>
        <?php else: ?>
          <form method="post" action="<?php echo attr($selfPath); ?>" class="pin-form" title="<?php echo xla('Click to pin'); ?>">
            <input type="hidden" name="action" value="pin">
            <input type="hidden" name="note_id" value="<?php echo attr((string)$n['id']); ?>">
            <input type="hidden" name="value" value="1">
            <button type="submit" class="pin-toggle"><?php echo xlt('Pin'); ?></button>
          </form>
        <?php endif; ?>
      </div>
      <div class="body"><?php echo text($n['body']); ?></div>
      <div class="divider"></div>
      <div class="author-row">
        <span class="av <?php echo attr($n['avatar']); ?>"><?php echo text($n['initials']); ?></span>
        <span class="who">
          <span class="name"><?php echo text($n['author']); ?></span>
          <span class="when"><?php echo text($n['when']); ?></span>
        </span>
      </div>
      <div class="foot">
        <label class="sel" title="<?php echo xla('Select for bulk archive'); ?>">
          <input form="cp-archive-form" type="checkbox" name="ids[]" value="<?php echo attr((string)$n['id']); ?>">
          <?php echo xlt('Select'); ?>
        </label>
        <span class="comments">&#128172; <?php echo text((string) $n['comments']); ?></span>
        <button type="button" class="reply-toggle" onclick="cpToggleReply(<?php echo (int)$n['id']; ?>)"><span aria-hidden="true">&#9112;</span> <?php echo xlt('Reply'); ?></button>
      </div>
      <form id="reply-<?php echo (int)$n['id']; ?>" class="reply-form" method="post" action="<?php echo attr($selfPath); ?>" style="display:none;">
        <input type="hidden" name="action" value="reply">
        <input type="hidden" name="note_id" value="<?php echo attr((string)$n['id']); ?>">
        <textarea name="body" placeholder="<?php echo xla('Type your reply...'); ?>" required></textarea>
        <div class="reply-actions">
          <button type="submit"><?php echo xlt('Send reply'); ?></button>
        </div>
      </form>
    </div>
  <?php endforeach; ?>
</div>

</body>
</html>
