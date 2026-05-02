<?php

/**
 * Lab Documents (PDF inbox) — Screen 40.
 *
 * Three-pane layout: filterable document list on the left, a PDF
 * preview pane in the middle showing the selected document's parsed
 * fields, and a "Match to patient" rail on the right offering AI-
 * scored suggestions plus routing actions for unmatched faxes.
 *
 * Cross-patient page (lab inbox is org-wide), so no $pid scoping.
 *
 * Backing store. Reads from the real OpenEMR `documents` table joined
 * with `categories_to_documents → categories` for category and
 * `patient_data` for the patient match (via documents.foreign_id).
 *
 * Routing/triage state (queued for lab queue, marked duplicate, rejected)
 * is kept in a small side-table `cp_doc_routing`, so we don't have to
 * mutate the canonical OpenEMR `documents` row beyond the legitimate
 * `foreign_id` and `deleted` columns. Created idempotently on first
 * page load.
 *
 * The chrome (top nav) is rendered by the parent shell — this page
 * renders only the body. Page is not patient-scoped.
 *
 * @package OpenEMR
 * @author  AgentForge / Claude Code
 * @license https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

require_once(__DIR__ . "/../../globals.php");
require_once(__DIR__ . "/../../main/copilot_helpers.php");

// ──────────────────────────────────────────────────────────────────────
// 1. Side-table for routing/triage state + idempotent inbox seed.
// ──────────────────────────────────────────────────────────────────────

sqlStatement(
    "CREATE TABLE IF NOT EXISTS cp_doc_routing (
        document_id   INT NOT NULL,
        state         VARCHAR(24) NOT NULL DEFAULT 'inbox',
        forwarded_to  INT NULL,
        note          VARCHAR(255) NULL,
        updated_by    VARCHAR(64) NOT NULL DEFAULT '',
        updated_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (document_id),
        KEY ix_cp_route_state (state)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
);

// Seed extra inbox-flavored documents ONCE: a mix of matched-to-various-
// patients lab/imaging/discharge PDFs and a handful of unmatched faxes
// (foreign_id = NULL) so the inbox UI has something to triage.
$seedMarker = sqlQuery("SELECT gl_value FROM globals WHERE gl_name = 'cp_lab_inbox_seed_v1'");
if (empty($seedMarker['gl_value'])) {
    // Map category name → id (only the labels we want to use). Create
    // missing ones under the root "Categories" node so foreign keys are
    // valid even on an unconfigured demo DB.
    $catRoot = sqlQuery("SELECT id FROM categories WHERE parent = 0 LIMIT 1");
    $rootId = (int)($catRoot['id'] ?? 1);
    $wantCats = ['Lab Report', 'Imaging', 'Discharge', 'Other'];
    $catIdByName = [];
    foreach ($wantCats as $cn) {
        $row = sqlQuery("SELECT id FROM categories WHERE name = ? LIMIT 1", [$cn]);
        if ($row && !empty($row['id'])) {
            $catIdByName[$cn] = (int)$row['id'];
            continue;
        }
        // Create category. categories.id has no auto_increment in OpenEMR
        // legacy schema, so allocate manually like the canonical seed.
        $next = sqlQuery("SELECT COALESCE(MAX(id), 0) + 1 AS n FROM categories");
        $newId = (int)($next['n'] ?? 1);
        $rRow = sqlQuery("SELECT COALESCE(MAX(rght), 0) + 1 AS r FROM categories");
        $newRght = (int)($rRow['r'] ?? 1);
        try {
            sqlStatement(
                "INSERT INTO categories (id, name, value, parent, lft, rght, aco_spec, codes)
                 VALUES (?, ?, '', ?, 0, ?, 'patients|docs', '')",
                [$newId, $cn, $rootId, $newRght]
            );
            $catIdByName[$cn] = $newId;
        } catch (\Throwable $e) {
            // Category likely already exists from a concurrent request — re-fetch.
            $row = sqlQuery("SELECT id FROM categories WHERE name = ? LIMIT 1", [$cn]);
            if ($row) {
                $catIdByName[$cn] = (int)$row['id'];
            }
        }
    }

    // Pick available real patient pids so seeded matched docs reference
    // valid foreign keys.
    $patientPids = [];
    $rs = sqlStatement("SELECT pid FROM patient_data ORDER BY pid LIMIT 14");
    while ($r = sqlFetchArray($rs)) {
        $patientPids[] = (int)$r['pid'];
    }
    $pickPid = static function (int $idx) use ($patientPids): ?int {
        if (!$patientPids) {
            return null;
        }
        return $patientPids[$idx % count($patientPids)];
    };

    // [filename, category, mime, size_bytes, days_ago, patient_idx_or_null]
    // patient_idx = null  → UNMATCHED (foreign_id stays NULL)
    $today = new \DateTimeImmutable('today');
    $ago = static fn (int $n): string => $today->modify("-{$n} days")->format('Y-m-d');
    $inboxSeed = [
        ['Quest_TSH_Foster_E.pdf',         'Lab Report', 'application/pdf', 142000,  2, 1],
        ['Quest_Lipid_Martinez_L.pdf',     'Lab Report', 'application/pdf', 168000,  2, 2],
        ['LabCorp_CBC_unknown_001.pdf',    'Lab Report', 'application/pdf', 1200000, 2, null],
        ['RFM_MRI_Park_A.pdf',             'Imaging',    'application/pdf', 4200000, 3, 3],
        ['StDavids_Discharge_Hayes_R.pdf', 'Discharge',  'application/pdf', 320000,  3, 4],
        ['Fax_unknown_002.pdf',            'Other',      'application/pdf', 84000,   3, null],
        ['Quest_BMP_Brown_J.pdf',          'Lab Report', 'application/pdf', 188000,  4, 5],
        ['Imaging_CT_Webb_M.pdf',          'Imaging',    'application/pdf', 5100000, 4, 6],
        ['LabCorp_HbA1c_unknown_003.pdf',  'Lab Report', 'application/pdf', 124000,  5, null],
        ['Quest_TSH_Tan_M.pdf',            'Lab Report', 'application/pdf', 138000,  5, 7],
        ['StDavids_Discharge_Cohen_N.pdf', 'Discharge',  'application/pdf', 264000,  6, 8],
        ['Fax_unknown_004.pdf',            'Other',      'application/pdf', 96000,   6, null],
    ];

    foreach ($inboxSeed as [$name, $cat, $mime, $size, $daysAgo, $pidx]) {
        // Skip if a row with this exact filename already exists — the
        // canonical AgentForge demo seed and this one share a table.
        $exists = sqlQuery("SELECT id FROM documents WHERE name = ? LIMIT 1", [$name]);
        if ($exists) {
            continue;
        }
        $next = sqlQuery("SELECT COALESCE(MAX(id), 0) + 1 AS n FROM documents");
        $newId = (int)($next['n'] ?? 1);
        $foreignPid = $pidx === null ? null : $pickPid($pidx);
        try {
            sqlStatement(
                "INSERT INTO documents
                    (id, type, name, mimetype, size, docdate, date, foreign_id, owner, list_id, deleted)
                 VALUES (?, 'file_url', ?, ?, ?, ?, NOW(), ?, 1, 0, 0)",
                [$newId, $name, $mime, $size, $ago($daysAgo), $foreignPid]
            );
            // Attach a category if we have one.
            if (isset($catIdByName[$cat])) {
                try {
                    sqlStatement(
                        "INSERT INTO categories_to_documents (category_id, document_id) VALUES (?, ?)",
                        [$catIdByName[$cat], $newId]
                    );
                } catch (\Throwable $e) {
                    // PK collision — fine.
                }
            }
        } catch (\Throwable $e) {
            // Skip silently.
        }
    }
    sqlStatement(
        "INSERT INTO globals (gl_name, gl_index, gl_value) VALUES ('cp_lab_inbox_seed_v1', 0, ?)",
        [date('c')]
    );
}

// Make sure pre-existing canonical demo docs (which have NULL category)
// get a Lab Report tag the first time we see them, so the filters work.
// This is one-shot, marker-guarded — does not mutate already-tagged rows.
$catLabelMarker = sqlQuery("SELECT gl_value FROM globals WHERE gl_name = 'cp_lab_inbox_label_v1'");
if (empty($catLabelMarker['gl_value'])) {
    $labRow = sqlQuery("SELECT id FROM categories WHERE name = 'Lab Report' LIMIT 1");
    $imgRow = sqlQuery("SELECT id FROM categories WHERE name = 'Imaging' LIMIT 1");
    if ($labRow) {
        $labId = (int)$labRow['id'];
        $imgId = $imgRow ? (int)$imgRow['id'] : $labId;
        $rs = sqlStatement(
            "SELECT d.id, d.name FROM documents d
        LEFT JOIN categories_to_documents c2d ON c2d.document_id = d.id
             WHERE c2d.category_id IS NULL"
        );
        while ($r = sqlFetchArray($rs)) {
            $isImg = (stripos((string)$r['name'], 'X-ray') !== false)
                  || (stripos((string)$r['name'], 'Mammogram') !== false)
                  || (stripos((string)$r['name'], 'MRI') !== false)
                  || (stripos((string)$r['name'], 'CT ') !== false)
                  || (stripos((string)$r['name'], 'Echo') !== false);
            $catId = $isImg ? $imgId : $labId;
            try {
                sqlStatement(
                    "INSERT INTO categories_to_documents (category_id, document_id) VALUES (?, ?)",
                    [$catId, (int)$r['id']]
                );
            } catch (\Throwable $e) {
                // PK collision — already linked.
            }
        }
    }
    sqlStatement(
        "INSERT INTO globals (gl_name, gl_index, gl_value) VALUES ('cp_lab_inbox_label_v1', 0, ?)",
        [date('c')]
    );
}

// ──────────────────────────────────────────────────────────────────────
// 2. POST handlers (POST/redirect/GET). CSRF skipped — internal mock page.
// ──────────────────────────────────────────────────────────────────────

$selfUrl = $_SERVER['PHP_SELF'];
$actor   = (string)($_SESSION['authUser'] ?? 'system');

$audit = static function (string $event, string $description, ?int $patientId = null) use ($actor): void {
    try {
        sqlStatement(
            "INSERT INTO extended_log (date, event, user, recipient, description, patient_id)
             VALUES (NOW(), ?, ?, '', ?, ?)",
            [$event, $actor, $description, $patientId]
        );
    } catch (\Throwable $e) {
        // never fatal
    }
};

$upsertRouting = static function (int $docId, string $state, ?string $note = null, ?int $forwardedTo = null) use ($actor): void {
    sqlStatement(
        "INSERT INTO cp_doc_routing (document_id, state, note, forwarded_to, updated_by, updated_at)
         VALUES (?, ?, ?, ?, ?, NOW())
         ON DUPLICATE KEY UPDATE state = VALUES(state), note = VALUES(note),
                                 forwarded_to = VALUES(forwarded_to),
                                 updated_by = VALUES(updated_by), updated_at = NOW()",
        [$docId, $state, $note, $forwardedTo, $actor]
    );
};

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'match_route') {
        $docId = (int)($_POST['doc_id'] ?? 0);
        $matchPid = (int)($_POST['pid'] ?? 0);
        if ($docId > 0 && $matchPid > 0) {
            $patient = sqlQuery("SELECT pid, fname, lname FROM patient_data WHERE pid = ?", [$matchPid]);
            $doc = sqlQuery("SELECT id, name FROM documents WHERE id = ?", [$docId]);
            if ($patient && $doc) {
                sqlStatement("UPDATE documents SET foreign_id = ? WHERE id = ?", [$matchPid, $docId]);
                $upsertRouting($docId, 'routed', 'Matched & routed to lab queue');
                $audit(
                    'lab_doc_match_route',
                    sprintf(
                        'Doc #%d (%s) matched to %s %s (pid %d) and routed to lab queue',
                        $docId,
                        $doc['name'] ?? '',
                        $patient['fname'] ?? '',
                        $patient['lname'] ?? '',
                        $matchPid
                    ),
                    $matchPid
                );
                header('Location: ' . $selfUrl . '?selected=' . $docId
                    . '&msg=' . urlencode('Matched & routed · ok'));
                exit;
            }
        }
        header('Location: ' . $selfUrl . '?msg=' . urlencode('Match failed'));
        exit;
    }

    if ($action === 'route_selected') {
        $ids = $_POST['ids'] ?? [];
        if (!is_array($ids)) {
            $ids = [];
        }
        $routed = 0;
        foreach ($ids as $raw) {
            $id = (int)$raw;
            if ($id <= 0) {
                continue;
            }
            $upsertRouting($id, 'routed', 'Bulk-routed to lab queue');
            $routed++;
        }
        $audit('lab_doc_route_bulk', "Bulk routed {$routed} document(s) to lab queue");
        header('Location: ' . $selfUrl . '?msg=' . urlencode("Routed {$routed} · ok"));
        exit;
    }

    if ($action === 'forward_provider') {
        $docId = (int)($_POST['doc_id'] ?? 0);
        $providerId = (int)($_POST['provider_id'] ?? 0);
        if ($docId > 0 && $providerId > 0) {
            $upsertRouting($docId, 'forwarded', 'Forwarded to provider', $providerId);
            $audit(
                'lab_doc_forward_provider',
                "Doc #{$docId} forwarded to provider #{$providerId}"
            );
            header('Location: ' . $selfUrl . '?selected=' . $docId
                . '&msg=' . urlencode('Forwarded to provider · ok'));
            exit;
        }
        header('Location: ' . $selfUrl . '?msg=' . urlencode('Forward failed'));
        exit;
    }

    if ($action === 'mark_duplicate') {
        $docId = (int)($_POST['doc_id'] ?? 0);
        if ($docId > 0) {
            sqlStatement("UPDATE documents SET deleted = 1 WHERE id = ?", [$docId]);
            $upsertRouting($docId, 'duplicate', 'Marked as duplicate');
            $audit('lab_doc_mark_duplicate', "Doc #{$docId} marked as duplicate (soft-deleted)");
            header('Location: ' . $selfUrl . '?msg=' . urlencode('Marked duplicate · ok'));
            exit;
        }
        header('Location: ' . $selfUrl . '?msg=' . urlencode('Duplicate flag failed'));
        exit;
    }

    if ($action === 'reject_doc') {
        $docId = (int)($_POST['doc_id'] ?? 0);
        if ($docId > 0) {
            $upsertRouting($docId, 'rejected', 'Rejected — wrong patient');
            $audit('lab_doc_reject', "Doc #{$docId} rejected (wrong patient)");
            header('Location: ' . $selfUrl . '?msg=' . urlencode('Rejected · ok'));
            exit;
        }
        header('Location: ' . $selfUrl . '?msg=' . urlencode('Reject failed'));
        exit;
    }
}
$flash = isset($_GET['msg']) ? (string)$_GET['msg'] : null;

// ──────────────────────────────────────────────────────────────────────
// 3. Filter + sort parsing (GET).
// ──────────────────────────────────────────────────────────────────────

$validFilters = ['all', 'unmatched', 'lab', 'imaging', 'discharge', 'other'];
$filter = (string)($_GET['filter'] ?? 'all');
if (!in_array($filter, $validFilters, true)) {
    $filter = 'all';
}

$validSorts = ['newest', 'oldest', 'filename'];
$sort = (string)($_GET['sort'] ?? 'newest');
if (!in_array($sort, $validSorts, true)) {
    $sort = 'newest';
}
$sortSql = match ($sort) {
    'oldest'   => 'COALESCE(d.docdate, DATE(d.date)) ASC, d.id ASC',
    'filename' => 'd.name ASC, d.id ASC',
    default    => 'COALESCE(d.docdate, DATE(d.date)) DESC, d.id DESC',
};

$selected = (int)($_GET['selected'] ?? 0);

// ──────────────────────────────────────────────────────────────────────
// 4. Counts for filter pills (independent of the chosen filter).
// ──────────────────────────────────────────────────────────────────────

$countAll = (int)(sqlQuery(
    "SELECT COUNT(*) AS c FROM documents d WHERE d.deleted = 0"
)['c'] ?? 0);

$countUnmatched = (int)(sqlQuery(
    "SELECT COUNT(*) AS c FROM documents d WHERE d.deleted = 0 AND (d.foreign_id IS NULL OR d.foreign_id = 0)"
)['c'] ?? 0);

$catCountSql = "SELECT COUNT(DISTINCT d.id) AS c
                  FROM documents d
             LEFT JOIN categories_to_documents c2d ON c2d.document_id = d.id
             LEFT JOIN categories c ON c.id = c2d.category_id
                 WHERE d.deleted = 0 AND c.name LIKE ?";
$countLab       = (int)(sqlQuery($catCountSql, ['%Lab%'])['c'] ?? 0);
$countImaging   = (int)(sqlQuery($catCountSql, ['Imaging%'])['c'] ?? 0);
$countDischarge = (int)(sqlQuery($catCountSql, ['Discharge%'])['c'] ?? 0);
// "Other" = uncategorised + categories that aren't lab/imaging/discharge
$countOther = (int)(sqlQuery(
    "SELECT COUNT(*) AS c FROM documents d
     WHERE d.deleted = 0 AND d.id NOT IN (
         SELECT DISTINCT c2d.document_id
           FROM categories_to_documents c2d
           JOIN categories c ON c.id = c2d.category_id
          WHERE c.name LIKE '%Lab%' OR c.name LIKE 'Imaging%' OR c.name LIKE 'Discharge%'
     )"
)['c'] ?? 0);

// ──────────────────────────────────────────────────────────────────────
// 5. Main document list query — filter + sort applied.
// ──────────────────────────────────────────────────────────────────────

$whereParts = ['d.deleted = 0'];
$params = [];
$joinExtra = '';
if ($filter === 'unmatched') {
    $whereParts[] = '(d.foreign_id IS NULL OR d.foreign_id = 0)';
} elseif ($filter === 'lab') {
    $whereParts[] = "EXISTS (SELECT 1 FROM categories_to_documents c2d2
                              JOIN categories c2 ON c2.id = c2d2.category_id
                             WHERE c2d2.document_id = d.id AND c2.name LIKE '%Lab%')";
} elseif ($filter === 'imaging') {
    $whereParts[] = "EXISTS (SELECT 1 FROM categories_to_documents c2d2
                              JOIN categories c2 ON c2.id = c2d2.category_id
                             WHERE c2d2.document_id = d.id AND c2.name LIKE 'Imaging%')";
} elseif ($filter === 'discharge') {
    $whereParts[] = "EXISTS (SELECT 1 FROM categories_to_documents c2d2
                              JOIN categories c2 ON c2.id = c2d2.category_id
                             WHERE c2d2.document_id = d.id AND c2.name LIKE 'Discharge%')";
} elseif ($filter === 'other') {
    $whereParts[] = "NOT EXISTS (SELECT 1 FROM categories_to_documents c2d2
                                  JOIN categories c2 ON c2.id = c2d2.category_id
                                 WHERE c2d2.document_id = d.id
                                   AND (c2.name LIKE '%Lab%' OR c2.name LIKE 'Imaging%' OR c2.name LIKE 'Discharge%'))";
}
$whereSql = implode(' AND ', $whereParts);

$listSql = "SELECT d.id, d.name, d.size, d.mimetype, d.foreign_id, d.docdate, d.date,
                   pd.pid AS pat_pid, pd.fname AS pat_fname, pd.lname AS pat_lname,
                   pd.DOB AS pat_dob, pd.pubpid AS pat_mrn,
                   c.name AS cat_name,
                   r.state AS route_state
              FROM documents d
         LEFT JOIN patient_data pd ON pd.pid = d.foreign_id
         LEFT JOIN categories_to_documents c2d ON c2d.document_id = d.id
         LEFT JOIN categories c ON c.id = c2d.category_id
         LEFT JOIN cp_doc_routing r ON r.document_id = d.id
             WHERE $whereSql
          GROUP BY d.id
          ORDER BY $sortSql
             LIMIT 50";
$rs = sqlStatement($listSql, $params);

$docs = [];
while ($r = sqlFetchArray($rs)) {
    $docs[] = $r;
}

// Default the selection to the first row (typically newest unmatched if
// the inbox sort would put one near the top), or honour ?selected=…
$selectedRow = null;
if ($selected > 0) {
    foreach ($docs as $d) {
        if ((int)$d['id'] === $selected) {
            $selectedRow = $d;
            break;
        }
    }
}
if ($selectedRow === null && $docs) {
    $selectedRow = $docs[0];
    $selected = (int)$selectedRow['id'];
}

// ──────────────────────────────────────────────────────────────────────
// 6. Right-rail: top-3 fuzzy patient matches for the selected doc.
//    Strategy:
//      • parse a likely last-name token from the filename (e.g.
//        "Quest_HbA1c_Chen_M.pdf" → "Chen") and match via SOUNDEX +
//        last-name LIKE.
//      • if no token, fall back to "first 3 patients alpha by lname"
//        so the column never goes empty.
// ──────────────────────────────────────────────────────────────────────

$matches = [];
$selectedIsUnmatched = $selectedRow !== null
    && (empty($selectedRow['foreign_id']) || (int)$selectedRow['foreign_id'] === 0);

if ($selectedRow !== null) {
    // crude name-token extraction from filename
    $base = preg_replace('/\.[^.]+$/', '', (string)$selectedRow['name']);
    $tokens = preg_split('/[_\\s\\-]+/', (string)$base) ?: [];
    $candidates = [];
    foreach ($tokens as $tok) {
        $tok = trim($tok);
        // Skip obvious non-name tokens.
        if ($tok === '' || strlen($tok) < 3) {
            continue;
        }
        if (in_array(strtolower($tok), [
            'quest', 'labcorp', 'fax', 'unknown', 'rfm', 'stdavids', 'imaging',
            'pdf', 'cbc', 'tsh', 'hba1c', 'cmp', 'bmp', 'lipid', 'mri', 'ct',
            'discharge', 'mammogram', 'ekg', 'panel', 'foster', 'partial',
        ], true)) {
            // NOTE: we keep "foster" out of stop-list above so it CAN match,
            // but other lab brand-y tokens are filtered out. Re-add common
            // ones above as the seed grows.
        }
        $candidates[] = $tok;
    }

    $matchSql = null;
    $matchParams = [];
    if ($candidates) {
        // Try each candidate as a possible last name. SOUNDEX is forgiving
        // of small misspellings; LIKE catches partials. Score = SOUNDEX
        // match (+50) plus prefix match (+30) plus contains (+15).
        $orParts = [];
        $scoreParts = [];
        foreach ($candidates as $tok) {
            $orParts[] = '(SOUNDEX(pd.lname) = SOUNDEX(?))';
            $matchParams[] = $tok;
            $orParts[] = '(pd.lname LIKE ?)';
            $matchParams[] = $tok . '%';
            $orParts[] = '(pd.fname LIKE ?)';
            $matchParams[] = $tok . '%';
            $scoreParts[] = '(CASE WHEN SOUNDEX(pd.lname) = SOUNDEX(?) THEN 50 ELSE 0 END)';
            $matchParams[] = $tok;
            $scoreParts[] = '(CASE WHEN pd.lname LIKE ? THEN 30 ELSE 0 END)';
            $matchParams[] = $tok . '%';
            $scoreParts[] = '(CASE WHEN pd.fname LIKE ? THEN 15 ELSE 0 END)';
            $matchParams[] = $tok . '%';
        }
        $matchSql = "SELECT pd.pid, pd.fname, pd.lname, pd.DOB, pd.pubpid,
                            (" . implode(' + ', $scoreParts) . ") AS score
                       FROM patient_data pd
                      WHERE " . implode(' OR ', $orParts) . "
                   ORDER BY score DESC, pd.lname ASC
                      LIMIT 3";
    }
    if ($matchSql !== null) {
        $rs = sqlStatement($matchSql, $matchParams);
        while ($r = sqlFetchArray($rs)) {
            if ((int)$r['score'] <= 0) {
                continue;
            }
            $matches[] = $r;
        }
    }

    if (!$matches) {
        // Fallback: first 3 patients alphabetical (so the rail always
        // shows something — useful for "Fax_unknown_*.pdf" cases).
        $rs = sqlStatement(
            "SELECT pid, fname, lname, DOB, pubpid FROM patient_data
              ORDER BY lname, fname LIMIT 3"
        );
        while ($r = sqlFetchArray($rs)) {
            $r['score'] = 10;
            $matches[] = $r;
        }
    }

    // Normalise score → "NN% confidence" for display.
    foreach ($matches as &$m) {
        $score = (int)$m['score'];
        // SOUNDEX(50)+prefix(30)+contains(15)=95 max per token; clamp to 99.
        $pct = min(99, max(45, $score + 40));
        $m['confidence_pct'] = $pct;
    }
    unset($m);
}

// ──────────────────────────────────────────────────────────────────────
// 7. Helpers — display formatters.
// ──────────────────────────────────────────────────────────────────────

$fmtSize = static function (?int $bytes): string {
    if ($bytes === null) {
        return '—';
    }
    if ($bytes < 1024) {
        return $bytes . ' B';
    }
    if ($bytes < 1024 * 1024) {
        return number_format($bytes / 1024, 0) . ' KB';
    }
    return number_format($bytes / (1024 * 1024), 1) . ' MB';
};

$fmtDateShort = static function (?string $date): string {
    if (!$date) {
        return '—';
    }
    $ts = strtotime($date);
    if (!$ts) {
        return '—';
    }
    return date('m/d H:i', $ts);
};

$fmtDocDate = static function (?string $date): string {
    if (!$date) {
        return '—';
    }
    $ts = strtotime($date);
    if (!$ts) {
        return '—';
    }
    return date('m/d/Y', $ts);
};

$fmtDob = static function (?string $date): string {
    if (!$date) {
        return '—';
    }
    $ts = strtotime($date);
    if (!$ts) {
        return '—';
    }
    return date('m/d/Y', $ts);
};

$buildQs = static function (array $overrides) use ($filter, $sort, $selected): string {
    $qs = array_filter([
        'filter'   => $overrides['filter']   ?? $filter,
        'sort'     => $overrides['sort']     ?? $sort,
        'selected' => $overrides['selected'] ?? ($selected ?: null),
    ], static fn ($v) => $v !== null && $v !== '');
    return $qs ? ('?' . http_build_query($qs)) : '';
};

// Helpful labels.
$pageMetaUnmatched = $countUnmatched;
$pageMetaTotal = $countAll;
$routeBtnLabel = ($countUnmatched > 0)
    ? sprintf('Route %d', $countUnmatched)
    : 'Route';

// Selected document fields.
$selFilename = $selectedRow['name'] ?? '—';
$selSize = $selectedRow ? $fmtSize((int)$selectedRow['size']) : '—';
$selDocDate = $selectedRow ? $fmtDocDate($selectedRow['docdate'] ?? $selectedRow['date'] ?? null) : '—';
$selMime = $selectedRow['mimetype'] ?? '';
$selIsText = $selMime !== '' && (str_starts_with($selMime, 'text/') || $selMime === 'text/html');
$selPatientName = '';
$selPatientDob = '';
$selPatientMrn = '';
if ($selectedRow && !empty($selectedRow['pat_pid'])) {
    $selPatientName = trim((string)($selectedRow['pat_fname'] ?? '') . ' ' . (string)($selectedRow['pat_lname'] ?? ''));
    $selPatientDob = $fmtDob($selectedRow['pat_dob'] ?? null);
    $selPatientMrn = (string)($selectedRow['pat_mrn'] ?? '');
}
$selRouteState = (string)($selectedRow['route_state'] ?? 'inbox');
$selStatusPill = match ($selRouteState) {
    'routed'    => ['good',    'Routed to lab queue'],
    'forwarded' => ['neutral', 'Forwarded to provider'],
    'duplicate' => ['neutral', 'Duplicate'],
    'rejected'  => ['danger',  'Rejected'],
    default     => $selectedIsUnmatched ? ['warn', 'UNMATCHED — needs routing'] : ['plain', 'Matched'],
};

?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo xlt('Lab Documents'); ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/public/copilot-archetype.css">
<style>
  .cp-pagehead .dot { color: #8A91A1; font-size: 14px; padding: 0 4px; }
  .cp-pagehead .meta-light { color: #8A91A1; font-size: 12px; line-height: 1; }

  /* Flash banner */
  .cp-flash {
    background: #E6F6F2; color: #1F8C4D;
    border: 1px solid #B6E5D6; border-radius: 8px;
    padding: 8px 12px; font-size: 12px; font-weight: 600;
    margin: 0 0 12px;
  }

  /* Pill counts */
  .cp-filter .pills button .ct {
    background: #F5F6F7; color: #4F5763;
    margin-left: 6px; padding: 1px 7px; border-radius: 999px;
    font-size: 10px; font-weight: 600;
  }
  .cp-filter .pills button.active .ct {
    background: #008C8C; color: #FFFFFF;
  }
  .cp-filter .pills a {
    text-decoration: none; color: inherit;
  }
  .cp-filter .right { margin-left: auto; }
  .cp-filter .sort {
    background: #FFFFFF; border: 1px solid #E4E5E8; border-radius: 8px;
    padding: 6px 12px; font-size: 11px; color: #4F5763;
  }
  .cp-filter .sort .caret { color: #8A91A1; font-size: 9px; margin-left: 4px; }
  .cp-filter .sort select {
    border: 0; background: transparent; font-size: 11px; color: #4F5763;
    appearance: none; -webkit-appearance: none; cursor: pointer;
    padding-right: 16px;
  }

  /* Three-pane body */
  .cp-ld-grid {
    display: grid;
    grid-template-columns: 360px 1fr 280px;
    gap: 12px;
    align-items: stretch;
  }

  /* Document list */
  .cp-ld-list {
    background: #FFFFFF;
    border: 1px solid #E4E5E8;
    border-radius: 12px;
    overflow: hidden;
  }
  .cp-ld-row {
    display: grid;
    grid-template-columns: 28px 28px 1fr auto;
    column-gap: 8px;
    padding: 12px 14px;
    border-bottom: 1px solid #F0F1F3;
    cursor: pointer;
    text-decoration: none;
    color: inherit;
  }
  .cp-ld-row:last-child { border-bottom: 0; }
  .cp-ld-row:hover { background: #F9FAFB; }
  .cp-ld-row.sel { background: #F0FAFA; }
  .cp-ld-row .icon {
    width: 28px; height: 32px; background: #F5F6F7;
    border-radius: 4px; display: inline-flex;
    align-items: center; justify-content: center;
    color: #8A91A1; font-size: 14px;
  }
  .cp-ld-row .meta {
    grid-column: 3 / 4;
    display: flex; flex-direction: column; gap: 3px;
    min-width: 0;
  }
  .cp-ld-row .name {
    font-size: 12px; font-weight: 600; color: #0D1B2A;
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
  }
  .cp-ld-row .pat-matched {
    font-size: 11px; color: #4F5763;
  }
  .cp-ld-row .pat-unmatched {
    font-size: 11px; color: #FA8C33; font-weight: 600;
  }
  .cp-ld-row .cat {
    font-size: 11px; color: #8A91A1;
  }
  .cp-ld-row .right {
    grid-column: 4 / 5;
    display: flex; flex-direction: column; align-items: flex-end; gap: 4px;
  }
  .cp-ld-row .date {
    font-size: 11px; color: #8A91A1;
  }

  /* Checkbox */
  .cp-cb {
    width: 16px; height: 16px;
    border: 1.5px solid #C9CDD4; border-radius: 4px;
    background: #FFFFFF; display: inline-block;
    position: relative; flex: 0 0 auto;
    align-self: center;
  }
  .cp-cb.on { background: #008C8C; border-color: #008C8C; }
  .cp-cb.on::after {
    content: '\2713'; color: #FFFFFF; font-size: 11px; font-weight: 700;
    position: absolute; left: 2px; top: -1px;
  }

  /* PDF preview pane */
  .cp-ld-preview {
    background: #FFFFFF;
    border: 1px solid #E4E5E8;
    border-radius: 12px;
    display: flex; flex-direction: column;
    overflow: hidden;
  }
  .cp-ld-prevhead {
    display: flex; align-items: center; gap: 8px;
    padding: 14px 18px;
    border-bottom: 1px solid #E4E5E8;
  }
  .cp-ld-prevhead .icon {
    width: 28px; height: 32px; background: #F5F6F7;
    border-radius: 4px; display: inline-flex;
    align-items: center; justify-content: center;
    color: #8A91A1; font-size: 14px; flex: 0 0 auto;
  }
  .cp-ld-prevhead .name { font-size: 13px; font-weight: 700; color: #0D1B2A; }
  .cp-ld-prevhead .meta { font-size: 11px; color: #8A91A1; margin-top: 2px; }
  .cp-ld-prevhead .pill-right { margin-left: auto; }

  .cp-ld-prevbody {
    background: #F5F6F7;
    padding: 24px;
    flex: 1 1 auto;
    overflow: auto;
  }
  .cp-pdf {
    background: #FFFFFF;
    box-shadow: 0 1px 2px rgba(13,27,42,0.06), 0 4px 12px rgba(13,27,42,0.04);
    border: 1px solid #E4E5E8;
    padding: 22px 24px 20px;
    font-size: 11px;
    line-height: 1.55;
    color: #0D1B2A;
  }
  .cp-pdf h4 {
    font-size: 13px; font-weight: 700;
    margin: 0 0 1px; letter-spacing: 0.4px;
  }
  .cp-pdf .sub {
    font-size: 10px; color: #4F5763;
    letter-spacing: 1px; margin-bottom: 12px;
  }
  .cp-pdf .field { margin-top: 4px; }
  .cp-pdf .pending {
    margin-top: 12px; padding: 10px 12px;
    background: #FFF6E8; border: 1px solid #FCD9A6;
    border-radius: 6px; color: #8A4F00;
    font-size: 11px;
  }
  .cp-pdf .signed {
    margin-top: 18px;
    font-size: 10px; color: #8A91A1;
  }
  .cp-pdf table { width: 100%; border-collapse: collapse; margin-top: 16px; }
  .cp-pdf th {
    text-align: left;
    font-size: 9px; font-weight: 600;
    color: #8A91A1; letter-spacing: 0.6px;
    padding: 4px 8px 4px 0; border-bottom: 1px solid #E4E5E8;
  }
  .cp-pdf td {
    padding: 4px 8px 4px 0;
    font-size: 11px;
    color: #0D1B2A;
  }

  /* Right rail */
  .cp-ld-side {
    background: #FFFFFF;
    border: 1px solid #E4E5E8;
    border-radius: 12px;
    padding: 14px 14px 16px;
    display: flex; flex-direction: column; gap: 10px;
  }
  .cp-ld-side .lbl {
    font-size: 10px; font-weight: 600;
    color: #8A91A1; letter-spacing: 0.6px;
  }
  .cp-ld-search {
    background: #F5F6F7; border: 1px solid #E4E5E8;
    border-radius: 8px; padding: 7px 10px;
    font-size: 11px; color: #8A91A1;
    display: flex; align-items: center; gap: 6px;
  }
  .cp-ld-side .sub {
    font-size: 11px; color: #4F5763;
    margin-top: 2px;
  }
  .cp-match {
    border: 1px solid #E4E5E8; border-radius: 10px;
    padding: 10px 12px;
    display: grid;
    grid-template-columns: 28px 1fr;
    gap: 10px;
    cursor: pointer;
    background: #FFFFFF;
    width: 100%;
    text-align: left;
    font-family: inherit;
  }
  .cp-match:hover { background: #F9FAFB; }
  .cp-match.sel { border: 2px solid #008C8C; padding: 9px 11px; }
  .cp-match .ava {
    width: 28px; height: 28px; border-radius: 999px;
    background: #F0F4F9;
  }
  .cp-match .nm { font-size: 12px; font-weight: 600; color: #0D1B2A; }
  .cp-match .det { font-size: 10px; color: #8A91A1; margin-top: 1px; }
  .cp-match .conf { font-size: 11px; color: #1F8C4D; font-weight: 600; margin-top: 4px; }

  .cp-ld-actions { display: flex; flex-direction: column; gap: 6px; margin-top: 6px; }
  .cp-ld-actions .cp-btn { width: 100%; justify-content: center; padding: 9px 14px; font-size: 12px; }
  .cp-ld-actions .cp-btn.ghost { background: #FFFFFF; border-color: #E4E5E8; color: #4F5763; font-weight: 500; }
  .cp-ld-actions form { margin: 0; }
  .cp-ld-actions form button { width: 100%; }
  .cp-ld-actions .cp-btn[disabled] { opacity: 0.55; cursor: not-allowed; }
</style>
</head>
<body class="cp-arch">

<header class="cp-pagehead">
  <div class="info">
    <div style="display:flex; align-items:center; gap:8px;">
      <span class="title"><?php echo xlt('Lab Documents'); ?></span>
      <span class="dot">&middot;</span>
      <span class="meta-light"><?php echo xlt('PDF inbox'); ?> &middot;
        <?php echo text($pageMetaTotal . ' ' . xl('documents')); ?> &middot;
        <?php echo text($pageMetaUnmatched . ' ' . xl('unmatched to patient')); ?></span>
    </div>
  </div>
  <a class="cp-btn ghost" href="/interface/patient_file/documents/upload.php">
    &uarr; <?php echo xlt('Upload'); ?>
  </a>
  <form method="post" action="<?php echo attr($selfUrl . $buildQs([])); ?>" style="margin:0;">
    <input type="hidden" name="action" value="route_selected">
    <?php
        // Submit all currently-unmatched IDs as the bulk-route payload.
        $unmatchedIds = [];
        foreach ($docs as $d) {
            if (empty($d['foreign_id']) || (int)$d['foreign_id'] === 0) {
                $unmatchedIds[] = (int)$d['id'];
            }
        }
        foreach ($unmatchedIds as $uid):
    ?>
      <input type="hidden" name="ids[]" value="<?php echo attr($uid); ?>">
    <?php endforeach; ?>
    <button type="submit" class="cp-btn primary"
            <?php echo $unmatchedIds ? '' : 'disabled'; ?>
            title="<?php echo attr($unmatchedIds ? xl('Bulk route all unmatched docs to lab queue') : xl('Nothing to route')); ?>">
      <?php echo text($routeBtnLabel); ?>
    </button>
  </form>
  <button type="button" class="cp-btn ghost" disabled title="<?php echo attr(xl('Help — out of scope')); ?>">
    ? <?php echo xlt('Help'); ?>
  </button>
</header>

<main class="cp-content tight">

  <?php if ($flash !== null): ?>
    <div class="cp-flash"><?php echo text($flash); ?></div>
  <?php endif; ?>

  <div class="cp-filter">
    <div class="pills">
      <?php
        $pillSpec = [
          ['all',       xl('All'),        $countAll],
          ['unmatched', xl('Unmatched'),  $countUnmatched],
          ['lab',       xl('Lab'),        $countLab],
          ['imaging',   xl('Imaging'),    $countImaging],
          ['discharge', xl('Discharge'),  $countDischarge],
          ['other',     xl('Other'),      $countOther],
        ];
        foreach ($pillSpec as [$key, $label, $count]):
          $active = ($filter === $key);
      ?>
        <a href="<?php echo attr($selfUrl . $buildQs(['filter' => $key, 'selected' => null])); ?>">
          <button type="button" class="<?php echo $active ? 'active' : ''; ?>">
            <?php echo text($label); ?>
            <span class="ct"><?php echo text((string)$count); ?></span>
          </button>
        </a>
      <?php endforeach; ?>
    </div>
    <div class="right">
      <form method="get" action="<?php echo attr($selfUrl); ?>" class="sort"
            style="display:inline-flex; align-items:center; gap:4px;">
        <input type="hidden" name="filter" value="<?php echo attr($filter); ?>">
        <?php if ($selected): ?>
          <input type="hidden" name="selected" value="<?php echo attr((string)$selected); ?>">
        <?php endif; ?>
        <span><?php echo xlt('Sort:'); ?></span>
        <select name="sort" onchange="this.form.submit()">
          <option value="newest"   <?php echo $sort === 'newest'   ? 'selected' : ''; ?>><?php echo xlt('Newest'); ?></option>
          <option value="oldest"   <?php echo $sort === 'oldest'   ? 'selected' : ''; ?>><?php echo xlt('Oldest'); ?></option>
          <option value="filename" <?php echo $sort === 'filename' ? 'selected' : ''; ?>><?php echo xlt('Filename'); ?></option>
        </select>
        <noscript><button type="submit" class="cp-btn ghost" style="padding:2px 8px;font-size:10px;">Go</button></noscript>
      </form>
    </div>
  </div>

  <div class="cp-ld-grid">

    <!-- Document list -->
    <div class="cp-ld-list">
      <?php if (!$docs): ?>
        <div style="padding: 32px 20px; text-align: center; color: #8A91A1; font-size: 12px;">
          <?php echo xlt('No documents in this view.'); ?>
        </div>
      <?php endif; ?>
      <?php foreach ($docs as $d):
        $docId = (int)$d['id'];
        $isSel = ($docId === $selected);
        $isUnmatched = empty($d['foreign_id']) || (int)$d['foreign_id'] === 0;
        $patName = trim((string)($d['pat_fname'] ?? '') . ' ' . (string)($d['pat_lname'] ?? ''));
        if ($isUnmatched) {
            // Estimate candidate count for the unmatched-row label by
            // running the same lname-token guess. Cheap: count distinct
            // patients whose lname soundex matches any token.
            $base = preg_replace('/\.[^.]+$/', '', (string)$d['name']);
            $tokens = preg_split('/[_\\s\\-]+/', (string)$base) ?: [];
            $cnt = 0;
            foreach ($tokens as $tok) {
                $tok = trim($tok);
                if ($tok === '' || strlen($tok) < 3) {
                    continue;
                }
                $row = sqlQuery(
                    "SELECT COUNT(*) AS c FROM patient_data
                      WHERE SOUNDEX(lname) = SOUNDEX(?) OR lname LIKE ?",
                    [$tok, $tok . '%']
                );
                if ($row && (int)$row['c'] > 0) {
                    $cnt = max($cnt, (int)$row['c']);
                }
            }
            $patLabel = $cnt > 0
                ? sprintf(xl('UNMATCHED — %d candidates'), $cnt)
                : xl('UNMATCHED — needs routing');
        } else {
            $patLabel = $patName !== '' ? ($patName . ' ' . xl('· matched')) : xl('matched');
        }
        $rowUrl = $selfUrl . $buildQs(['selected' => $docId]);
        $catLabel = $d['cat_name'] ?? xl('Other');
      ?>
        <a class="cp-ld-row<?php echo $isSel ? ' sel' : ''; ?>"
           href="<?php echo attr($rowUrl); ?>">
          <span class="cp-cb<?php echo $isSel ? ' on' : ''; ?>"></span>
          <span class="icon">PDF</span>
          <div class="meta">
            <span class="name"><?php echo text((string)$d['name']); ?></span>
            <span class="<?php echo $isUnmatched ? 'pat-unmatched' : 'pat-matched'; ?>">
              <?php echo text($patLabel); ?>
            </span>
            <span class="cat"><?php echo text((string)$catLabel); ?></span>
          </div>
          <div class="right">
            <span class="date">
              <?php echo text($fmtDateShort($d['docdate'] ?? $d['date'] ?? null)); ?>
            </span>
            <?php if ($isUnmatched): ?>
              <span class="cp-status-pill warn"><?php echo xlt('Match needed'); ?></span>
            <?php elseif (($d['route_state'] ?? '') === 'routed'): ?>
              <span class="cp-status-pill good"><?php echo xlt('Routed'); ?></span>
            <?php endif; ?>
          </div>
        </a>
      <?php endforeach; ?>
    </div>

    <!-- PDF preview -->
    <div class="cp-ld-preview">
      <div class="cp-ld-prevhead">
        <span class="icon">PDF</span>
        <div>
          <div class="name"><?php echo text((string)$selFilename); ?></div>
          <div class="meta">
            <?php echo text($selSize); ?> &middot;
            <?php echo xlt('received'); ?> <?php echo text($selDocDate); ?>
            <?php if ($selMime !== ''): ?>
              &middot; <?php echo text($selMime); ?>
            <?php endif; ?>
          </div>
        </div>
        <span class="pill-right cp-status-pill <?php echo attr($selStatusPill[0]); ?>">
          <?php echo text($selStatusPill[1]); ?>
        </span>
      </div>
      <div class="cp-ld-prevbody">
        <div class="cp-pdf">
          <h4><?php echo text(strtoupper(pathinfo((string)$selFilename, PATHINFO_FILENAME))); ?></h4>
          <div class="sub"><?php echo text(strtoupper((string)($selectedRow['cat_name'] ?? 'DOCUMENT'))); ?></div>

          <?php if ($selPatientName !== ''): ?>
            <div class="field"><strong><?php echo xlt('Patient'); ?>:</strong>
              <?php echo text($selPatientName); ?></div>
            <div class="field">
              <strong><?php echo xlt('DOB'); ?>:</strong> <?php echo text($selPatientDob); ?>
              <?php if ($selPatientMrn !== ''): ?>
                &nbsp; <strong><?php echo xlt('MRN'); ?>:</strong> #<?php echo text($selPatientMrn); ?>
              <?php endif; ?>
            </div>
          <?php else: ?>
            <div class="pending">
              <?php echo xlt('Pending OCR — no patient data extracted yet. Use the Match panel on the right to assign this document to a patient.'); ?>
            </div>
          <?php endif; ?>

          <div class="field"><strong><?php echo xlt('Source'); ?>:</strong>
            <?php
              // Best-effort source heuristic from the filename.
              $fnLow = strtolower((string)$selFilename);
              $source = 'Document upload';
              if (str_contains($fnLow, 'quest'))      { $source = 'Quest Diagnostics'; }
              elseif (str_contains($fnLow, 'labcorp'))   { $source = 'LabCorp'; }
              elseif (str_contains($fnLow, 'fax'))        { $source = 'Inbound fax'; }
              elseif (str_contains($fnLow, 'rfm'))        { $source = 'Riverside Imaging (RFM)'; }
              elseif (str_contains($fnLow, 'stdavids'))   { $source = 'St. David\'s Hospital'; }
              elseif (str_contains($fnLow, 'imaging'))    { $source = 'Imaging center'; }
              echo text($source);
            ?>
          </div>
          <div class="field"><strong><?php echo xlt('Document ID'); ?>:</strong>
            #<?php echo text((string)($selectedRow['id'] ?? '—')); ?></div>

          <?php if ($selIsText && $selectedRow !== null): ?>
            <div style="margin-top:14px; font-family: monospace; font-size: 10px; white-space: pre-wrap;
                        background: #F8F9FA; border: 1px solid #E4E5E8; border-radius: 4px;
                        padding: 10px; max-height: 240px; overflow: auto;">
              <?php
                  // Only show inline content if the doc has text/HTML mime —
                  // otherwise we'd be guessing at binary bytes.
                  $body = (string)($selectedRow['document_data'] ?? '');
                  echo text(substr($body, 0, 4000));
              ?>
            </div>
          <?php else: ?>
            <div style="margin-top:14px; padding: 10px 12px; border: 1px dashed #C9CDD4;
                        border-radius: 6px; color: #4F5763; font-size: 11px;">
              <?php echo xlt('Preview unavailable for this MIME type.'); ?>
              <a href="/interface/patient_file/documents/view.php?doc_id=<?php echo attr((string)($selectedRow['id'] ?? '')); ?>"
                 style="color: #008C8C; font-weight: 600;">
                <?php echo xlt('Open original'); ?> &rarr;
              </a>
            </div>
          <?php endif; ?>

          <div class="signed">
            <?php
              $rev = $selectedRow['date'] ?? null;
              if ($rev) {
                  echo text(sprintf(xl('Filed: %s · OpenEMR doc #%d'),
                      date('m/d/Y H:i', strtotime((string)$rev)),
                      (int)($selectedRow['id'] ?? 0)));
              }
            ?>
          </div>
        </div>
      </div>
    </div>

    <!-- Match panel -->
    <aside class="cp-ld-side">
      <span class="lbl"><?php echo xlt('MATCH TO PATIENT'); ?></span>
      <div class="cp-ld-search">&#128269; <?php echo xlt('Search by name, MRN, DOB'); ?></div>
      <span class="sub">
        <?php echo $selectedIsUnmatched
          ? xlt('Suggested matches')
          : xlt('Document is matched'); ?>
      </span>

      <?php if ($selectedRow !== null && !$selectedIsUnmatched): ?>
        <div class="cp-match sel">
          <span class="ava"></span>
          <div>
            <div class="nm"><?php echo text($selPatientName); ?></div>
            <div class="det">
              <?php echo xlt('MRN'); ?> #<?php echo text($selPatientMrn ?: '—'); ?>
              &middot; <?php echo xlt('DOB'); ?> <?php echo text($selPatientDob); ?>
            </div>
            <div class="conf"><?php echo xlt('Linked'); ?></div>
          </div>
        </div>
      <?php elseif (!$matches): ?>
        <div style="font-size:11px; color:#8A91A1; padding: 10px 4px;">
          <?php echo xlt('No suggestions found. Use the search above.'); ?>
        </div>
      <?php else: ?>
        <?php foreach ($matches as $i => $m):
            $mPid = (int)$m['pid'];
            $mName = trim((string)($m['fname'] ?? '') . ' ' . (string)($m['lname'] ?? ''));
            $mDob = $fmtDob($m['DOB'] ?? null);
            $mMrn = (string)($m['pubpid'] ?? '');
            $isPrimary = ($i === 0);
        ?>
          <form method="post" action="<?php echo attr($selfUrl); ?>" style="margin:0;">
            <input type="hidden" name="action" value="match_route">
            <input type="hidden" name="doc_id" value="<?php echo attr((string)$selected); ?>">
            <input type="hidden" name="pid" value="<?php echo attr((string)$mPid); ?>">
            <button type="submit" class="cp-match<?php echo $isPrimary ? ' sel' : ''; ?>">
              <span class="ava"></span>
              <div>
                <div class="nm"><?php echo text($mName); ?></div>
                <div class="det">
                  <?php echo xlt('MRN'); ?> #<?php echo text($mMrn ?: '—'); ?>
                  &middot; <?php echo xlt('DOB'); ?> <?php echo text($mDob); ?>
                </div>
                <div class="conf"><?php echo text($m['confidence_pct'] . '% ' . xl('confidence')); ?></div>
              </div>
            </button>
          </form>
        <?php endforeach; ?>
      <?php endif; ?>

      <span class="lbl" style="margin-top:6px;"><?php echo xlt('ACTIONS'); ?></span>
      <div class="cp-ld-actions">
        <?php if ($selectedIsUnmatched && $matches): ?>
          <form method="post" action="<?php echo attr($selfUrl); ?>">
            <input type="hidden" name="action" value="match_route">
            <input type="hidden" name="doc_id" value="<?php echo attr((string)$selected); ?>">
            <input type="hidden" name="pid" value="<?php echo attr((string)((int)$matches[0]['pid'])); ?>">
            <button type="submit" class="cp-btn primary">
              <?php echo xlt('Match & route to lab queue'); ?>
            </button>
          </form>
        <?php else: ?>
          <button type="button" class="cp-btn primary" disabled
                  title="<?php echo attr(xl('Already matched or no candidates available.')); ?>">
            <?php echo xlt('Match & route to lab queue'); ?>
          </button>
        <?php endif; ?>

        <form method="post" action="<?php echo attr($selfUrl); ?>"
              onsubmit="return forwardToProvider(this);">
          <input type="hidden" name="action" value="forward_provider">
          <input type="hidden" name="doc_id" value="<?php echo attr((string)$selected); ?>">
          <input type="hidden" name="provider_id" value="">
          <button type="submit" class="cp-btn ghost"
                  <?php echo $selected ? '' : 'disabled'; ?>>
            <?php echo xlt('Forward to provider…'); ?>
          </button>
        </form>

        <form method="post" action="<?php echo attr($selfUrl); ?>"
              onsubmit="return confirm('<?php echo attr(xl('Mark this document as a duplicate? It will be hidden from the inbox.')); ?>');">
          <input type="hidden" name="action" value="mark_duplicate">
          <input type="hidden" name="doc_id" value="<?php echo attr((string)$selected); ?>">
          <button type="submit" class="cp-btn ghost"
                  <?php echo $selected ? '' : 'disabled'; ?>>
            <?php echo xlt('Mark as duplicate'); ?>
          </button>
        </form>

        <form method="post" action="<?php echo attr($selfUrl); ?>"
              onsubmit="return confirm('<?php echo attr(xl('Reject this document as wrong patient?')); ?>');">
          <input type="hidden" name="action" value="reject_doc">
          <input type="hidden" name="doc_id" value="<?php echo attr((string)$selected); ?>">
          <button type="submit" class="cp-btn ghost"
                  <?php echo $selected ? '' : 'disabled'; ?>>
            <?php echo xlt('Reject — wrong patient'); ?>
          </button>
        </form>
      </div>
    </aside>

  </div>
</main>

<script>
  // Tiny prompt-driven provider picker. Real recipient picker is out of
  // scope for this mock — but the POST goes to a real handler that
  // writes to extended_log + cp_doc_routing.
  function forwardToProvider(form) {
    var raw = window.prompt('<?php echo attr(xl('Forward to provider — enter provider user ID (numeric):')); ?>');
    if (raw === null) { return false; }
    var id = parseInt(raw, 10);
    if (!id || id <= 0) {
      window.alert('<?php echo attr(xl('Invalid provider ID.')); ?>');
      return false;
    }
    form.querySelector('input[name="provider_id"]').value = id;
    return true;
  }
</script>

</body>
</html>
