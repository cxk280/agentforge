<?php

/**
 * Patient Education library — Screen 48.
 *
 * Patient-scoped library of education handouts. Left rail of category
 * counts, top filter bar (search + reading level + format + source),
 * AI suggestion banner, and an 8-card grid of handouts with print /
 * portal / preview actions.
 *
 * The chrome (top nav, demographics banner, navtabs) is rendered by the
 * parent shell; this page renders only the body.
 *
 * Data sources:
 *   - Patient identity: `patient_data` (real).
 *   - Suggestion banner: `lists` (active medical_problem rows) + most
 *     recent A1C from `procedure_result` joined through procedure_report
 *     and procedure_order (real, falls back gracefully when absent).
 *   - Catalog: a static array of real CDC/ADA/MedlinePlus handouts. The
 *     `document_templates` table in OpenEMR is for fillable form
 *     templates (HIPAA, insurance, etc.), not patient education
 *     handouts. In production this would live in a dedicated
 *     `patient_education_catalog` table or a CMS join — for the demo we
 *     keep the catalog in code and treat it as the source of truth.
 *   - "Print" → POST `action=print` writes to `extended_log` and
 *     redirects to /interface/main/copilot_print_preview.php.
 *   - "Portal" / "Share selected via portal" → POST
 *     `action=send_portal` / `action=bulk_send_portal` insert real rows
 *     into `onsite_messages`.
 *
 * @package OpenEMR
 * @author  AgentForge / Claude Code
 * @license https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

require_once(__DIR__ . "/../../globals.php");

use OpenEMR\Common\Session\SessionWrapperFactory;
require_once(__DIR__ . "/../../main/copilot_helpers.php");

$pid = (int)(SessionWrapperFactory::getInstance()->getActiveSession()->get('pid') ?? 1);

// ─────────────────────────────────────────────────────────────────────────
// Static catalog — would live in a `patient_education_catalog` table in
// production. Each row matches the visual structure the mock expects.
//
// Fields per item:
//   id          stable string ID (used for print/portal action targets)
//   title
//   desc
//   source      vendor / publisher (CDC, ADA, AHA, MedlinePlus, …)
//   level       reading level
//   format      'Handout' | 'Video' | 'Web page'
//   pages       short label like "8 pgs"
//   langs       comma-separated language tags
//   art         icon key (matches the SVG switch below)
//   bg          art-block background colour
//   category    category key (must match $categories below)
//   reviewed    last-reviewed date (YYYY-MM-DD)
//
// The catalog is intentionally larger than the 8 cards on screen so the
// search / category filters have something to filter against.
// ─────────────────────────────────────────────────────────────────────────
$catalog = [
    ['id' => 'edu-diab-001', 'title' => 'Living with type 2 diabetes',   'desc' => 'Diabetes basics — symptoms, monitoring, lifestyle', 'source' => 'MedlinePlus', 'level' => '6th grade', 'format' => 'Handout', 'pages' => '8 pgs',  'langs' => 'EN/ES', 'art' => 'book',    'bg' => '#E8EEF5', 'category' => 'diab', 'reviewed' => '2025-09-12'],
    ['id' => 'edu-diab-002', 'title' => 'Understanding your A1C',        'desc' => 'What it means, target ranges, action plan',         'source' => 'ADA',         'level' => '6th grade', 'format' => 'Handout', 'pages' => '4 pgs',  'langs' => 'EN/ES', 'art' => 'tube',    'bg' => '#EAF2EE', 'category' => 'diab', 'reviewed' => '2025-11-04'],
    ['id' => 'edu-diab-003', 'title' => 'Diabetes meal planning',        'desc' => 'Plate method, carb counting, sample meals',         'source' => 'ADA',         'level' => '5th grade', 'format' => 'Handout', 'pages' => '12 pgs', 'langs' => 'EN/ES', 'art' => 'salad',   'bg' => '#EFEAE0', 'category' => 'diab', 'reviewed' => '2025-08-20'],
    ['id' => 'edu-diab-004', 'title' => 'Foot care for diabetics',       'desc' => 'Daily checks, when to call provider',               'source' => 'MedlinePlus', 'level' => '5th grade', 'format' => 'Handout', 'pages' => '6 pgs',  'langs' => 'EN/ES', 'art' => 'foot',    'bg' => '#F5EEEA', 'category' => 'diab', 'reviewed' => '2025-07-15'],
    ['id' => 'edu-diab-005', 'title' => 'Insulin injection technique',   'desc' => 'Step-by-step with diagrams',                        'source' => 'CDC',         'level' => '6th grade', 'format' => 'Handout', 'pages' => '8 pgs',  'langs' => 'EN/ES', 'art' => 'syringe', 'bg' => '#EEEAF2', 'category' => 'diab', 'reviewed' => '2025-10-02'],
    ['id' => 'edu-diab-006', 'title' => 'Hypoglycemia: low blood sugar', 'desc' => 'Symptoms, treatment, prevention',                   'source' => 'ADA',         'level' => '5th grade', 'format' => 'Handout', 'pages' => '4 pgs',  'langs' => 'EN/ES', 'art' => 'warn',    'bg' => '#F2EBE3', 'category' => 'diab', 'reviewed' => '2025-12-18'],
    ['id' => 'edu-diab-007', 'title' => 'Continuous glucose monitors',   'desc' => 'How CGMs work, sensor setup',                       'source' => 'Dexcom',      'level' => '7th grade', 'format' => 'Handout', 'pages' => '10 pgs', 'langs' => 'EN/ES', 'art' => 'phone',   'bg' => '#E8EEF1', 'category' => 'diab', 'reviewed' => '2025-06-30'],
    ['id' => 'edu-diab-008', 'title' => 'Diabetes and your eyes',        'desc' => 'Retinopathy screening, prevention',                 'source' => 'MedlinePlus', 'level' => '5th grade', 'format' => 'Handout', 'pages' => '6 pgs',  'langs' => 'EN/ES', 'art' => 'eyeball', 'bg' => '#F0E9E1', 'category' => 'diab', 'reviewed' => '2025-09-01'],

    ['id' => 'edu-card-001', 'title' => 'Managing high blood pressure',  'desc' => 'Lifestyle and medication basics',                   'source' => 'AHA',         'level' => '6th grade', 'format' => 'Handout', 'pages' => '6 pgs',  'langs' => 'EN/ES', 'art' => 'book',    'bg' => '#E8EEF5', 'category' => 'card', 'reviewed' => '2025-10-15'],
    ['id' => 'edu-card-002', 'title' => 'Heart-healthy eating',          'desc' => 'DASH diet basics and shopping tips',                'source' => 'AHA',         'level' => '5th grade', 'format' => 'Handout', 'pages' => '8 pgs',  'langs' => 'EN/ES', 'art' => 'salad',   'bg' => '#EFEAE0', 'category' => 'card', 'reviewed' => '2025-09-22'],
    ['id' => 'edu-card-003', 'title' => 'After your heart attack',       'desc' => 'Recovery, cardiac rehab, warning signs',            'source' => 'AHA',         'level' => '7th grade', 'format' => 'Handout', 'pages' => '12 pgs', 'langs' => 'EN/ES', 'art' => 'warn',    'bg' => '#F2EBE3', 'category' => 'card', 'reviewed' => '2025-08-05'],

    ['id' => 'edu-resp-001', 'title' => 'Living with asthma',            'desc' => 'Triggers, action plans, inhaler technique',         'source' => 'CDC',         'level' => '5th grade', 'format' => 'Handout', 'pages' => '8 pgs',  'langs' => 'EN/ES', 'art' => 'phone',   'bg' => '#E8EEF1', 'category' => 'resp', 'reviewed' => '2025-11-12'],
    ['id' => 'edu-resp-002', 'title' => 'COPD basics',                   'desc' => 'Symptoms, breathing exercises, inhalers',           'source' => 'MedlinePlus', 'level' => '6th grade', 'format' => 'Handout', 'pages' => '10 pgs', 'langs' => 'EN/ES', 'art' => 'tube',    'bg' => '#EAF2EE', 'category' => 'resp', 'reviewed' => '2025-07-08'],

    ['id' => 'edu-mind-001', 'title' => 'Coping with depression',        'desc' => 'When to seek help, treatment options',              'source' => 'NIMH',        'level' => '7th grade', 'format' => 'Handout', 'pages' => '6 pgs',  'langs' => 'EN/ES', 'art' => 'book',    'bg' => '#E8EEF5', 'category' => 'mind', 'reviewed' => '2025-10-28'],
    ['id' => 'edu-mind-002', 'title' => 'Managing anxiety',              'desc' => 'Relaxation, breathing, when to call',               'source' => 'NIMH',        'level' => '6th grade', 'format' => 'Handout', 'pages' => '4 pgs',  'langs' => 'EN/ES', 'art' => 'warn',    'bg' => '#F2EBE3', 'category' => 'mind', 'reviewed' => '2025-12-01'],

    ['id' => 'edu-bone-001', 'title' => 'Low back pain',                 'desc' => 'Self-care, exercises, red flags',                   'source' => 'MedlinePlus', 'level' => '5th grade', 'format' => 'Handout', 'pages' => '6 pgs',  'langs' => 'EN/ES', 'art' => 'foot',    'bg' => '#F5EEEA', 'category' => 'bone', 'reviewed' => '2025-06-15'],
    ['id' => 'edu-skin-001', 'title' => 'Wound care at home',            'desc' => 'Cleaning, dressing, infection signs',               'source' => 'MedlinePlus', 'level' => '6th grade', 'format' => 'Handout', 'pages' => '4 pgs',  'langs' => 'EN/ES', 'art' => 'syringe', 'bg' => '#EEEAF2', 'category' => 'skin', 'reviewed' => '2025-09-30'],
    ['id' => 'edu-eye-001',  'title' => 'Cataracts: what to expect',     'desc' => 'Surgery, recovery, follow-up',                      'source' => 'MedlinePlus', 'level' => '6th grade', 'format' => 'Handout', 'pages' => '6 pgs',  'langs' => 'EN/ES', 'art' => 'eyeball', 'bg' => '#F0E9E1', 'category' => 'eye',  'reviewed' => '2025-08-12'],
    ['id' => 'edu-kid-001',  'title' => 'Childhood vaccine schedule',    'desc' => 'CDC recommended schedule birth–18',                 'source' => 'CDC',         'level' => '5th grade', 'format' => 'Handout', 'pages' => '4 pgs',  'langs' => 'EN/ES', 'art' => 'syringe', 'bg' => '#EEEAF2', 'category' => 'kid',  'reviewed' => '2025-11-20'],
    ['id' => 'edu-fem-001',  'title' => 'Mammogram: what to expect',     'desc' => 'Preparation, the visit, results',                   'source' => 'CDC',         'level' => '6th grade', 'format' => 'Handout', 'pages' => '4 pgs',  'langs' => 'EN/ES', 'art' => 'book',    'bg' => '#E8EEF5', 'category' => 'fem',  'reviewed' => '2025-10-10'],
    ['id' => 'edu-med-001',  'title' => 'Metformin: medication guide',   'desc' => 'How to take it, side effects, missed dose',         'source' => 'FDA',         'level' => '6th grade', 'format' => 'Handout', 'pages' => '2 pgs',  'langs' => 'EN/ES', 'art' => 'tube',    'bg' => '#EAF2EE', 'category' => 'med',  'reviewed' => '2025-12-05'],
    ['id' => 'edu-med-002',  'title' => 'Lisinopril: medication guide',  'desc' => 'How to take it, side effects, missed dose',         'source' => 'FDA',         'level' => '6th grade', 'format' => 'Handout', 'pages' => '2 pgs',  'langs' => 'EN/ES', 'art' => 'tube',    'bg' => '#EAF2EE', 'category' => 'med',  'reviewed' => '2025-12-05'],
    ['id' => 'edu-nut-001',  'title' => 'Reading nutrition labels',      'desc' => 'Servings, calories, sodium, sugar',                 'source' => 'FDA',         'level' => '5th grade', 'format' => 'Handout', 'pages' => '4 pgs',  'langs' => 'EN/ES', 'art' => 'salad',   'bg' => '#EFEAE0', 'category' => 'nut',  'reviewed' => '2025-08-28'],
    ['id' => 'edu-lab-001',  'title' => 'Understanding your lipid panel','desc' => 'LDL, HDL, triglycerides — what to watch',           'source' => 'AHA',         'level' => '6th grade', 'format' => 'Handout', 'pages' => '4 pgs',  'langs' => 'EN/ES', 'art' => 'tube',    'bg' => '#EAF2EE', 'category' => 'lab',  'reviewed' => '2025-09-18'],
    ['id' => 'edu-proc-001', 'title' => 'Preparing for a colonoscopy',   'desc' => 'Diet, prep, day-of instructions',                   'source' => 'MedlinePlus', 'level' => '6th grade', 'format' => 'Handout', 'pages' => '4 pgs',  'langs' => 'EN/ES', 'art' => 'book',    'bg' => '#E8EEF5', 'category' => 'proc', 'reviewed' => '2025-07-02'],
];

// ─────────────────────────────────────────────────────────────────────────
// Categories. The `key` is the GET-param value. Counts are derived from
// the catalog (so they stay honest as the catalog changes).
// ─────────────────────────────────────────────────────────────────────────
$categoryDefs = [
    ['name' => 'All conditions',   'key' => 'all',  'icon' => '&#9776;'],
    ['name' => 'Diabetes',         'key' => 'diab', 'icon' => '&#127814;'],
    ['name' => 'Cardiovascular',   'key' => 'card', 'icon' => '&#9829;'],
    ['name' => 'Respiratory',      'key' => 'resp', 'icon' => '&#127881;'],
    ['name' => 'Mental health',    'key' => 'mind', 'icon' => '&#9728;'],
    ['name' => 'Musculoskeletal',  'key' => 'bone', 'icon' => '&#127947;'],
    ['name' => 'Skin & wound',     'key' => 'skin', 'icon' => '&#128137;'],
    ['name' => 'Vision',           'key' => 'eye',  'icon' => '&#128065;'],
    ['name' => 'Pediatric',        'key' => 'kid',  'icon' => '&#128118;'],
    ['name' => 'Womens health',    'key' => 'fem',  'icon' => '&#128105;'],
    ['name' => 'Medication guides','key' => 'med',  'icon' => '&#128138;'],
    ['name' => 'Nutrition / diet', 'key' => 'nut',  'icon' => '&#127831;'],
    ['name' => 'Lab understanding','key' => 'lab',  'icon' => '&#128300;'],
    ['name' => 'Procedure prep',   'key' => 'proc', 'icon' => '&#128203;'],
];
$validCategoryKeys = array_map(static fn($c) => $c['key'], $categoryDefs);

// Map a string id to its catalog row.
$catalogIndex = [];
foreach ($catalog as $row) {
    $catalogIndex[$row['id']] = $row;
}

// ─────────────────────────────────────────────────────────────────────────
// POST handlers (CSRF skipped — internal mock page, matches sibling
// copilot_* pages).
// ─────────────────────────────────────────────────────────────────────────
$method = $_SERVER['REQUEST_METHOD'] ?? '';
if ($method === 'POST') {
    $action = (string)($_POST['action'] ?? '');

    // ── Print: log the print event then bounce to the print preview shell.
    if ($action === 'print') {
        $eduId = (string)($_POST['id'] ?? '');
        if (isset($catalogIndex[$eduId])) {
            $item = $catalogIndex[$eduId];
            $user = (string)($_SESSION['authUser'] ?? 'admin');
            sqlStatement(
                "INSERT INTO extended_log (date, event, user, recipient, description, patient_id) "
                . "VALUES (NOW(), ?, ?, ?, ?, ?)",
                [
                    'patient-education-print',
                    $user,
                    'patient',
                    'Education handout: ' . $item['title'] . ' (' . $item['source'] . ')',
                    $pid,
                ]
            );
            header('Location: /interface/main/copilot_print_preview.php?type=education&id=' . urlencode($eduId));
            exit;
        }
        header('Location: ' . $_SERVER['PHP_SELF'] . '?msg=missing');
        exit;
    }

    // ── Portal: send a single handout to the patient portal inbox.
    if ($action === 'send_portal') {
        $eduId = (string)($_POST['id'] ?? '');
        if (isset($catalogIndex[$eduId])) {
            $item = $catalogIndex[$eduId];
            $user = (string)($_SESSION['authUser'] ?? 'admin');
            $body = "Your care team shared an education handout with you:\n\n"
                  . $item['title'] . " — " . $item['source'] . "\n"
                  . $item['desc'] . "\n\n"
                  . "Open the document in your portal to read or download.";
            $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1');
            sqlStatement(
                "INSERT INTO onsite_messages (username, message, ip, date, sender_id, recip_id) "
                . "VALUES (?, ?, ?, NOW(), ?, ?)",
                [$user, $body, $ip, $user, 'patient:' . $pid]
            );
            header('Location: ' . $_SERVER['PHP_SELF'] . '?msg=sent&cat=' . urlencode((string)($_POST['cat'] ?? 'all')));
            exit;
        }
        header('Location: ' . $_SERVER['PHP_SELF'] . '?msg=missing');
        exit;
    }

    // ── Bulk portal: share several handouts at once.
    if ($action === 'bulk_send_portal') {
        $ids = $_POST['ids'] ?? [];
        if (!is_array($ids)) {
            $ids = [];
        }
        $user = (string)($_SESSION['authUser'] ?? 'admin');
        $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1');
        $sent = 0;
        foreach ($ids as $rawId) {
            $eduId = (string)$rawId;
            if (!isset($catalogIndex[$eduId])) {
                continue;
            }
            $item = $catalogIndex[$eduId];
            $body = "Your care team shared an education handout with you:\n\n"
                  . $item['title'] . " — " . $item['source'] . "\n"
                  . $item['desc'] . "\n\n"
                  . "Open the document in your portal to read or download.";
            sqlStatement(
                "INSERT INTO onsite_messages (username, message, ip, date, sender_id, recip_id) "
                . "VALUES (?, ?, ?, NOW(), ?, ?)",
                [$user, $body, $ip, $user, 'patient:' . $pid]
            );
            $sent++;
        }
        header('Location: ' . $_SERVER['PHP_SELF'] . '?msg=bulk_sent&n=' . $sent);
        exit;
    }
}

// ─────────────────────────────────────────────────────────────────────────
// Filters (GET params).
// ─────────────────────────────────────────────────────────────────────────
$cat = (string)($_GET['cat'] ?? 'diab');
if (!in_array($cat, $validCategoryKeys, true)) {
    $cat = 'diab';
}
$q = trim((string)($_GET['q'] ?? ''));

$validLevels = ['', '5th grade', '6th grade', '7th grade'];
$level = (string)($_GET['level'] ?? '');
if (!in_array($level, $validLevels, true)) {
    $level = '';
}

$validFormats = ['', 'Handout', 'Video', 'Web page'];
$format = (string)($_GET['format'] ?? '');
if (!in_array($format, $validFormats, true)) {
    $format = '';
}

$allSources = [];
foreach ($catalog as $row) {
    if (!in_array($row['source'], $allSources, true)) {
        $allSources[] = $row['source'];
    }
}
sort($allSources);
$source = (string)($_GET['source'] ?? '');
if ($source !== '' && !in_array($source, $allSources, true)) {
    $source = '';
}

// Apply filters (everything is in-memory: catalog is small).
$filtered = array_values(array_filter($catalog, static function (array $row) use ($cat, $q, $level, $format, $source): bool {
    if ($cat !== 'all' && $row['category'] !== $cat) {
        return false;
    }
    if ($level !== '' && $row['level'] !== $level) {
        return false;
    }
    if ($format !== '' && $row['format'] !== $format) {
        return false;
    }
    if ($source !== '' && $row['source'] !== $source) {
        return false;
    }
    if ($q !== '') {
        $needle = strtolower($q);
        $hay = strtolower($row['title'] . ' ' . $row['desc'] . ' ' . $row['source'] . ' ' . $row['category']);
        if (!str_contains($hay, $needle)) {
            return false;
        }
    }
    return true;
}));

// Display 8 cards (4×2) — extras drop off the grid.
$cards = array_slice($filtered, 0, 8);

// Counts per category, computed from the catalog.
$counts = [];
foreach ($categoryDefs as $def) {
    if ($def['key'] === 'all') {
        $counts[$def['key']] = count($catalog);
        continue;
    }
    $counts[$def['key']] = count(array_filter($catalog, static fn($r) => $r['category'] === $def['key']));
}

// ─────────────────────────────────────────────────────────────────────────
// Patient identity + suggestion banner data.
// ─────────────────────────────────────────────────────────────────────────
$pat = sqlQuery("SELECT pid, fname, lname FROM patient_data WHERE pid = ?", [$pid]);
$patientName = trim(($pat['fname'] ?? '') . ' ' . ($pat['lname'] ?? '')) ?: 'this patient';

// Active medical problems (deduplicated by title — `lists` has dup rows
// per migration in the seed DB).
$problems = [];
$rows = sqlStatement(
    "SELECT DISTINCT title, diagnosis FROM lists "
    . "WHERE pid = ? AND type = 'medical_problem' "
    . "AND COALESCE(outcome, 0) != 1 "
    . "AND COALESCE(enddate, '0000-00-00') = '0000-00-00' "
    . "ORDER BY date ASC",
    [$pid]
);
while ($r = sqlFetchArray($rows)) {
    $problems[] = [
        'title'     => (string)$r['title'],
        'diagnosis' => (string)($r['diagnosis'] ?? ''),
    ];
}

// Most-recent A1C (HbA1c) from procedure_result, if any.
$a1cRow = sqlQuery(
    "SELECT pr.result, pr.units, pr.date "
    . "FROM procedure_result pr "
    . "JOIN procedure_report rpt ON rpt.procedure_report_id = pr.procedure_report_id "
    . "JOIN procedure_order po ON po.procedure_order_id = rpt.procedure_order_id "
    . "WHERE po.patient_id = ? "
    . "AND (LOWER(pr.result_text) LIKE '%a1c%' OR LOWER(pr.result_text) LIKE '%hba1c%' OR pr.result_code IN ('4548-4','17856-6')) "
    . "ORDER BY pr.date DESC LIMIT 1",
    [$pid]
);

// Build the suggestion banner text from real data.
$suggestionParts = [];
$diabetesProblem = null;
foreach ($problems as $p) {
    $title = strtolower($p['title']);
    if (str_contains($title, 'diabetes') || str_starts_with($p['diagnosis'], 'ICD10:E11') || str_starts_with($p['diagnosis'], 'ICD10:E10')) {
        $diabetesProblem = $p;
        break;
    }
}
if ($diabetesProblem) {
    $code = $diabetesProblem['diagnosis'];
    $code = preg_replace('/^ICD10:/', '', $code) ?: $code;
    $shortLabel = $diabetesProblem['title'];
    if (stripos($shortLabel, 'type 2') !== false) {
        $shortLabel = 'T2DM';
    } elseif (stripos($shortLabel, 'type 1') !== false) {
        $shortLabel = 'T1DM';
    }
    $suggestionParts[] = $code . ' (' . $shortLabel . ')';
}
if ($a1cRow && is_numeric($a1cRow['result'])) {
    $suggestionParts[] = 'recent A1C of ' . rtrim(rtrim(number_format((float)$a1cRow['result'], 1), '0'), '.') . '%';
} elseif (!$diabetesProblem && $problems) {
    // Fall back to the first active problem so the banner still says
    // something true.
    $first = $problems[0];
    $code = preg_replace('/^ICD10:/', '', $first['diagnosis']) ?: $first['diagnosis'];
    $suggestionParts[] = ($code !== '' ? $code . ' (' : '') . $first['title'] . ($code !== '' ? ')' : '');
}
$suggestionDetail = $suggestionParts ? implode(' and ', $suggestionParts) : 'their active conditions';

// Flash message.
$flashRaw = (string)($_GET['msg'] ?? '');
$flashN   = (int)($_GET['n'] ?? 0);
$flash = match ($flashRaw) {
    'sent'      => 'Sent to patient portal.',
    'bulk_sent' => 'Shared ' . $flashN . ' handout' . ($flashN === 1 ? '' : 's') . ' to portal.',
    'missing'   => 'That handout could not be found.',
    default     => null,
};

// Helper to build a self-link that preserves filter state.
$selfBase = $_SERVER['PHP_SELF'];
$buildUrl = static function (array $overrides) use ($cat, $q, $level, $format, $source, $selfBase): string {
    $params = array_filter([
        'cat'    => $cat,
        'q'      => $q,
        'level'  => $level,
        'format' => $format,
        'source' => $source,
    ], static fn($v) => $v !== '' && $v !== null);
    foreach ($overrides as $k => $v) {
        if ($v === null || $v === '') {
            unset($params[$k]);
        } else {
            $params[$k] = $v;
        }
    }
    return $selfBase . ($params ? '?' . http_build_query($params) : '');
};

?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo xlt('Patient Education'); ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/public/copilot-archetype.css">
<style>
  /* Page header dot + lang pill + help */
  .cp-pagehead .titleSm { font-size: 16px; }
  .cp-pagehead .star { color: #008C8C; font-size: 13px; margin: 0 2px 0 0; }
  .cp-pagehead .meta-l { color: #4F5763; font-size: 12px; line-height: 1; }
  .cp-pagehead .head-right { display: flex; align-items: center; gap: 10px; }
  .lang-pill {
    display: inline-flex; align-items: center; gap: 6px;
    background: #FFFFFF; border: 1px solid #E4E5E8; border-radius: 999px;
    padding: 5px 12px; font-size: 12px; color: #4F5763; font-weight: 500;
  }
  .lang-pill .globe { color: #008C8C; font-size: 11px; }
  .lang-pill .caret { color: #8A91A1; font-size: 9px; }
  .help-link {
    background: transparent; border: 0; padding: 0;
    font-size: 12px; color: #4F5763; font-weight: 500;
  }

  /* Edu page shell — left rail + main */
  .edu-shell { flex: 1 1 auto; display: flex; min-height: 0; background: #F5F6F7; }

  /* Left rail */
  .edu-rail {
    flex: 0 0 220px;
    background: #FFFFFF;
    border-right: 1px solid #E4E5E8;
    padding: 14px 0;
    overflow-y: auto;
  }
  .edu-rail .lbl {
    font-size: 10px; font-weight: 600; letter-spacing: 0.7px;
    color: #8A91A1; padding: 0 18px 8px;
  }
  .edu-cat {
    display: flex; align-items: center; gap: 10px;
    width: 100%; padding: 8px 18px;
    border: 0; background: transparent; text-align: left;
    color: #4F5763; font-size: 13px; font-weight: 500;
    line-height: 1.2;
    position: relative;
    text-decoration: none;
  }
  .edu-cat:hover { background: #F8F9FA; color: #0D1B2A; }
  .edu-cat .ic {
    width: 16px; height: 16px; flex: 0 0 16px;
    display: inline-flex; align-items: center; justify-content: center;
    color: #8A91A1; font-size: 12px;
  }
  .edu-cat .name { flex: 1; }
  .edu-cat .ct { color: #8A91A1; font-size: 11px; font-weight: 500; }
  .edu-cat.active {
    background: rgba(0, 140, 140, 0.08);
    color: #008C8C; font-weight: 600;
  }
  .edu-cat.active::before {
    content: ''; position: absolute;
    left: 0; top: 0; bottom: 0; width: 3px;
    background: #008C8C;
  }
  .edu-cat.active .ic, .edu-cat.active .ct { color: #008C8C; }

  /* Main */
  .edu-main {
    flex: 1 1 auto;
    padding: 18px 22px 28px;
    overflow-y: auto;
    display: flex; flex-direction: column; gap: 14px;
  }

  /* Top filter row: big search + 3 dropdown selects */
  .edu-filters {
    display: flex; align-items: center; gap: 10px;
  }
  .edu-search {
    flex: 1;
    display: flex; align-items: center; gap: 8px;
    background: #FFFFFF;
    border: 1px solid #E4E5E8;
    border-radius: 10px;
    height: 42px;
    padding: 0 14px;
  }
  .edu-search .ic { color: #8A91A1; font-size: 13px; }
  .edu-search input {
    flex: 1; border: 0; outline: none; background: transparent;
    font-size: 13px; color: #0D1B2A;
  }
  .edu-search input::placeholder { color: #8A91A1; }
  .edu-sel {
    background: #FFFFFF;
    border: 1px solid #E4E5E8;
    border-radius: 10px;
    height: 42px;
    padding: 4px 14px;
    display: flex; flex-direction: column; justify-content: center;
    min-width: 140px;
    position: relative;
  }
  .edu-sel .lbl {
    font-size: 10px; color: #8A91A1; font-weight: 500;
    line-height: 1; margin-bottom: 3px;
  }
  .edu-sel .val {
    display: flex; align-items: center; justify-content: space-between;
    font-size: 13px; color: #0D1B2A; font-weight: 500;
    line-height: 1;
  }
  .edu-sel .caret { color: #8A91A1; font-size: 9px; margin-left: 8px; }
  .edu-sel select {
    position: absolute; inset: 0;
    width: 100%; height: 100%;
    opacity: 0; cursor: pointer; border: 0; background: transparent;
  }

  /* Suggestion banner */
  .edu-suggest {
    background: rgba(0, 140, 140, 0.06);
    border: 1px solid rgba(0, 140, 140, 0.20);
    border-radius: 10px;
    padding: 10px 14px;
    display: flex; align-items: center; gap: 10px;
    font-size: 12px; color: #0D1B2A;
  }
  .edu-suggest .star { color: #008C8C; font-size: 12px; }
  .edu-suggest .txt { flex: 1; }
  .edu-suggest .show {
    color: #008C8C; font-size: 12px; font-weight: 600;
    background: transparent; border: 0; padding: 0;
    text-decoration: none;
  }

  /* Flash banner */
  .edu-flash {
    background: #E8F4EE; border: 1px solid #BFE3CD;
    color: #1F6B43; border-radius: 10px;
    padding: 8px 12px; font-size: 12px; font-weight: 500;
  }

  /* Card grid */
  .edu-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 14px;
  }
  .edu-empty {
    grid-column: 1 / -1;
    background: #FFFFFF; border: 1px dashed #E4E5E8; border-radius: 12px;
    padding: 32px; text-align: center; color: #8A91A1; font-size: 13px;
  }
  .edu-card {
    background: #FFFFFF;
    border: 1px solid #E4E5E8;
    border-radius: 12px;
    overflow: hidden;
    display: flex; flex-direction: column;
  }
  .edu-art {
    height: 132px;
    display: flex; align-items: center; justify-content: center;
    position: relative;
  }
  .edu-check {
    position: absolute; top: 10px; right: 10px;
    width: 22px; height: 22px;
    border-radius: 6px;
    background: #FFFFFF;
    border: 1px solid #E4E5E8;
    display: flex; align-items: center; justify-content: center;
    color: #FFFFFF; font-size: 12px; font-weight: 700;
    cursor: pointer;
  }
  .edu-check input { position: absolute; inset: 0; opacity: 0; cursor: pointer; }
  .edu-check.on { background: #008C8C; border-color: #008C8C; }
  .edu-art .glyph { font-size: 44px; line-height: 1; }
  .edu-body {
    padding: 12px 14px 14px;
    display: flex; flex-direction: column; gap: 6px;
  }
  .edu-body .ttl {
    font-size: 13px; font-weight: 700; color: #0D1B2A;
    line-height: 1.25;
  }
  .edu-body .desc {
    font-size: 11px; color: #4F5763; line-height: 1.35;
    min-height: 30px;
  }
  .edu-body .src {
    font-size: 11px; color: #008C8C; font-weight: 600;
    line-height: 1.2;
  }
  .edu-body .meta {
    font-size: 11px; color: #8A91A1; line-height: 1.2;
  }
  .edu-actions {
    display: flex; gap: 6px; margin-top: 8px;
  }
  .edu-actions form { flex: 1; display: flex; }
  .edu-actions button, .edu-actions a {
    flex: 1;
    height: 28px;
    border-radius: 999px;
    border: 1px solid #E4E5E8;
    background: #FFFFFF;
    color: #4F5763;
    font-size: 11px; font-weight: 500;
    line-height: 1;
    display: inline-flex; align-items: center; justify-content: center; gap: 4px;
    text-decoration: none;
    cursor: pointer;
  }
  .edu-actions a.preview {
    color: #008C8C;
    border-color: rgba(0, 140, 140, 0.35);
    background: rgba(0, 140, 140, 0.06);
    font-weight: 600;
  }

  /* SVG art coloring */
  .edu-art svg { display: block; }
</style>
</head>
<body class="cp-arch">

<header class="cp-pagehead">
  <div class="info" style="flex-direction: row; align-items: center; gap: 8px;">
    <span class="titleSm"><?php echo xlt('Patient Education'); ?></span>
    <span class="star">&#10022;</span>
    <span class="meta-l"><?php echo xlt('Find handouts to share via portal or print'); ?></span>
  </div>
  <div class="head-right">
    <span class="lang-pill"><span class="globe">&#127760;</span><?php echo xlt('English'); ?> <span class="caret">&#9660;</span></span>
    <button type="submit" form="edu-bulk-form" class="cp-btn primary" id="edu-bulk-btn">
      <?php echo xlt('Share selected via portal'); ?>
    </button>
    <button type="button" class="help-link">? <?php echo xlt('Help'); ?></button>
  </div>
</header>

<form id="edu-bulk-form" method="post" action="<?php echo attr($selfBase); ?>">
  <input type="hidden" name="action" value="bulk_send_portal">

  <div class="edu-shell">

    <aside class="edu-rail">
      <div class="lbl"><?php echo xlt('CATEGORIES'); ?></div>
      <?php foreach ($categoryDefs as $def): ?>
        <a class="edu-cat<?php echo ($cat === $def['key']) ? ' active' : ''; ?>"
           href="<?php echo attr($buildUrl(['cat' => $def['key']])); ?>">
          <span class="ic"><?php echo $def['icon']; ?></span>
          <span class="name"><?php echo text($def['name']); ?></span>
          <span class="ct"><?php echo text((string)($counts[$def['key']] ?? 0)); ?></span>
        </a>
      <?php endforeach; ?>
    </aside>

    <main class="edu-main">

      <form method="get" action="<?php echo attr($selfBase); ?>" class="edu-filters">
        <input type="hidden" name="cat" value="<?php echo attr($cat); ?>">
        <div class="edu-search">
          <span class="ic">&#128269;</span>
          <input type="text" name="q" value="<?php echo attr($q); ?>"
                 placeholder="<?php echo xla('Search by topic, condition, or keyword...'); ?>"
                 onchange="this.form.submit()">
        </div>
        <label class="edu-sel">
          <span class="lbl"><?php echo xlt('Reading level'); ?></span>
          <span class="val"><?php echo text($level !== '' ? $level : (string)xl('Any')); ?> <span class="caret">&#9660;</span></span>
          <select name="level" onchange="this.form.submit()">
            <option value="" <?php echo $level === '' ? 'selected' : ''; ?>><?php echo xlt('Any'); ?></option>
            <?php foreach (['5th grade', '6th grade', '7th grade'] as $opt): ?>
              <option value="<?php echo attr($opt); ?>" <?php echo $level === $opt ? 'selected' : ''; ?>>
                <?php echo text($opt); ?>
              </option>
            <?php endforeach; ?>
          </select>
        </label>
        <label class="edu-sel">
          <span class="lbl"><?php echo xlt('Format'); ?></span>
          <span class="val"><?php echo text($format !== '' ? $format : (string)xl('Any')); ?> <span class="caret">&#9660;</span></span>
          <select name="format" onchange="this.form.submit()">
            <option value="" <?php echo $format === '' ? 'selected' : ''; ?>><?php echo xlt('Any'); ?></option>
            <?php foreach (['Handout', 'Video', 'Web page'] as $opt): ?>
              <option value="<?php echo attr($opt); ?>" <?php echo $format === $opt ? 'selected' : ''; ?>>
                <?php echo text($opt); ?>
              </option>
            <?php endforeach; ?>
          </select>
        </label>
        <label class="edu-sel">
          <span class="lbl"><?php echo xlt('Source'); ?></span>
          <span class="val"><?php echo text($source !== '' ? $source : (string)xl('Any')); ?> <span class="caret">&#9660;</span></span>
          <select name="source" onchange="this.form.submit()">
            <option value="" <?php echo $source === '' ? 'selected' : ''; ?>><?php echo xlt('Any'); ?></option>
            <?php foreach ($allSources as $opt): ?>
              <option value="<?php echo attr($opt); ?>" <?php echo $source === $opt ? 'selected' : ''; ?>>
                <?php echo text($opt); ?>
              </option>
            <?php endforeach; ?>
          </select>
        </label>
      </form>

      <?php if ($flash !== null): ?>
        <div class="edu-flash"><?php echo text($flash); ?></div>
      <?php endif; ?>

      <div class="edu-suggest">
        <span class="star">&#10022;</span>
        <span class="txt">
          <?php echo xlt('Suggested for'); ?> <strong><?php echo text($patientName); ?></strong>
          &mdash; <?php echo xlt('based on'); ?> <?php echo text($suggestionDetail); ?>
        </span>
        <a class="show" href="<?php echo attr($buildUrl(['cat' => $diabetesProblem ? 'diab' : 'all', 'q' => null])); ?>">
          <?php echo xlt('Show all'); ?> &rarr;
        </a>
      </div>

      <div class="edu-grid">
        <?php if (!$cards): ?>
          <div class="edu-empty"><?php echo xlt('No handouts match those filters.'); ?></div>
        <?php endif; ?>
        <?php foreach ($cards as $card): ?>
          <div class="edu-card">
            <div class="edu-art" style="background: <?php echo attr($card['bg']); ?>;">
              <?php
              switch ($card['art']) {
                  case 'book':
                      echo '<svg width="68" height="58" viewBox="0 0 68 58" fill="none">'
                         . '<path d="M6 8 Q6 4 10 4 L32 4 Q34 4 34 8 L34 50 Q34 54 30 54 L10 54 Q6 54 6 50 Z" fill="#5B7FA8" stroke="#3F5A7F" stroke-width="1.5"/>'
                         . '<path d="M34 8 Q34 4 38 4 L58 4 Q62 4 62 8 L62 50 Q62 54 58 54 L38 54 Q34 54 34 50 Z" fill="#7AA0CC" stroke="#3F5A7F" stroke-width="1.5"/>'
                         . '<line x1="14" y1="16" x2="28" y2="16" stroke="#FFFFFF" stroke-width="1"/>'
                         . '<line x1="14" y1="22" x2="28" y2="22" stroke="#FFFFFF" stroke-width="1"/>'
                         . '<line x1="14" y1="28" x2="26" y2="28" stroke="#FFFFFF" stroke-width="1"/>'
                         . '<line x1="40" y1="16" x2="56" y2="16" stroke="#FFFFFF" stroke-width="1"/>'
                         . '<line x1="40" y1="22" x2="56" y2="22" stroke="#FFFFFF" stroke-width="1"/>'
                         . '<line x1="40" y1="28" x2="54" y2="28" stroke="#FFFFFF" stroke-width="1"/>'
                         . '</svg>';
                      break;
                  case 'tube':
                      echo '<svg width="40" height="68" viewBox="0 0 40 68" fill="none">'
                         . '<rect x="13" y="4" width="14" height="56" rx="7" fill="#FFFFFF" stroke="#3F8C5F" stroke-width="2"/>'
                         . '<rect x="13" y="34" width="14" height="26" rx="7" fill="#5FBF7F"/>'
                         . '<line x1="9" y1="4" x2="31" y2="4" stroke="#3F8C5F" stroke-width="3" stroke-linecap="round"/>'
                         . '</svg>';
                      break;
                  case 'salad':
                      echo '<svg width="68" height="56" viewBox="0 0 68 56" fill="none">'
                         . '<path d="M4 28 Q4 50 34 50 Q64 50 64 28 Z" fill="#D9D9D9" stroke="#9AA0AB" stroke-width="1.5"/>'
                         . '<circle cx="20" cy="32" r="7" fill="#D94545"/>'
                         . '<circle cx="34" cy="26" r="8" fill="#5FBF55"/>'
                         . '<circle cx="46" cy="32" r="6" fill="#D94545"/>'
                         . '<circle cx="28" cy="36" r="5" fill="#FFB347"/>'
                         . '<circle cx="42" cy="38" r="4" fill="#5FBF55"/>'
                         . '</svg>';
                      break;
                  case 'foot':
                      echo '<svg width="46" height="68" viewBox="0 0 46 68" fill="none">'
                         . '<path d="M14 44 Q10 50 12 58 Q14 64 22 64 Q32 64 34 56 Q36 48 32 42 Q28 36 30 28 Q32 18 24 14 Q14 12 12 22 Q10 32 14 44 Z" fill="#E8B89A" stroke="#A87655" stroke-width="1.5"/>'
                         . '<circle cx="14" cy="10" r="3" fill="#E8B89A" stroke="#A87655" stroke-width="1"/>'
                         . '<circle cx="20" cy="6" r="3" fill="#E8B89A" stroke="#A87655" stroke-width="1"/>'
                         . '<circle cx="26" cy="6" r="3" fill="#E8B89A" stroke="#A87655" stroke-width="1"/>'
                         . '<circle cx="32" cy="8" r="3" fill="#E8B89A" stroke="#A87655" stroke-width="1"/>'
                         . '<circle cx="36" cy="14" r="3" fill="#E8B89A" stroke="#A87655" stroke-width="1"/>'
                         . '</svg>';
                      break;
                  case 'syringe':
                      echo '<svg width="80" height="50" viewBox="0 0 80 50" fill="none">'
                         . '<line x1="2" y1="25" x2="14" y2="25" stroke="#9AA0AB" stroke-width="2"/>'
                         . '<rect x="14" y="18" width="6" height="14" fill="#C7CBD2" stroke="#6F7785" stroke-width="1"/>'
                         . '<rect x="20" y="14" width="40" height="22" rx="2" fill="#FFFFFF" stroke="#6F7785" stroke-width="1.5"/>'
                         . '<rect x="20" y="14" width="20" height="22" fill="#A8C8E8"/>'
                         . '<rect x="60" y="20" width="6" height="10" fill="#C7CBD2" stroke="#6F7785" stroke-width="1"/>'
                         . '<rect x="66" y="14" width="4" height="22" fill="#C7CBD2" stroke="#6F7785" stroke-width="1"/>'
                         . '<rect x="70" y="22" width="8" height="6" fill="#C7CBD2" stroke="#6F7785" stroke-width="1"/>'
                         . '</svg>';
                      break;
                  case 'warn':
                      echo '<svg width="68" height="58" viewBox="0 0 68 58" fill="none">'
                         . '<path d="M34 4 L64 54 L4 54 Z" fill="#F0C674" stroke="#A87E33" stroke-width="2" stroke-linejoin="round"/>'
                         . '<rect x="32" y="20" width="4" height="18" fill="#5C4422"/>'
                         . '<circle cx="34" cy="46" r="2.5" fill="#5C4422"/>'
                         . '</svg>';
                      break;
                  case 'phone':
                      echo '<svg width="42" height="68" viewBox="0 0 42 68" fill="none">'
                         . '<rect x="6" y="4" width="30" height="60" rx="5" fill="#1F2937" stroke="#0D1B2A" stroke-width="1.5"/>'
                         . '<rect x="9" y="9" width="24" height="44" rx="2" fill="#3A4756"/>'
                         . '<circle cx="21" cy="58" r="2" fill="#6F7785"/>'
                         . '<line x1="13" y1="20" x2="29" y2="20" stroke="#6F8FA8" stroke-width="1"/>'
                         . '<polyline points="13,32 17,28 21,34 25,24 29,30" fill="none" stroke="#5FBF7F" stroke-width="1.5"/>'
                         . '<line x1="13" y1="44" x2="29" y2="44" stroke="#6F8FA8" stroke-width="1"/>'
                         . '</svg>';
                      break;
                  case 'eyeball':
                      echo '<svg width="72" height="50" viewBox="0 0 72 50" fill="none">'
                         . '<ellipse cx="36" cy="25" rx="32" ry="20" fill="#FFFFFF" stroke="#6F7785" stroke-width="1.5"/>'
                         . '<circle cx="36" cy="25" r="14" fill="#6B5436"/>'
                         . '<circle cx="36" cy="25" r="6" fill="#1F1A12"/>'
                         . '<circle cx="32" cy="21" r="2" fill="#FFFFFF"/>'
                         . '</svg>';
                      break;
              }
              ?>
              <label class="edu-check">
                <input type="checkbox" name="ids[]" value="<?php echo attr($card['id']); ?>"
                       onchange="this.closest('.edu-check').classList.toggle('on', this.checked); document.getElementById('edu-check-' + this.value).textContent = this.checked ? '✓' : ''; updateBulkLabel();">
                <span id="edu-check-<?php echo attr($card['id']); ?>"></span>
              </label>
            </div>
            <div class="edu-body">
              <div class="ttl"><?php echo text($card['title']); ?></div>
              <div class="desc"><?php echo text($card['desc']); ?></div>
              <div class="src"><?php echo text($card['source']); ?></div>
              <div class="meta">
                <?php echo text($card['level']); ?>
                &middot; <?php echo text($card['pages']); ?>
                &middot; <?php echo text($card['langs']); ?>
                &middot; <?php echo xlt('reviewed'); ?>
                <?php echo text(date('m/Y', strtotime($card['reviewed']))); ?>
              </div>
              <div class="edu-actions">
                <form method="post" action="<?php echo attr($selfBase); ?>">
                  <input type="hidden" name="action" value="print">
                  <input type="hidden" name="id" value="<?php echo attr($card['id']); ?>">
                  <button type="submit" title="<?php echo xla('Print and log'); ?>">&#128424; <?php echo xlt('Print'); ?></button>
                </form>
                <form method="post" action="<?php echo attr($selfBase); ?>">
                  <input type="hidden" name="action" value="send_portal">
                  <input type="hidden" name="id" value="<?php echo attr($card['id']); ?>">
                  <input type="hidden" name="cat" value="<?php echo attr($cat); ?>">
                  <button type="submit" title="<?php echo xla('Send to patient portal'); ?>">&#10150; <?php echo xlt('Portal'); ?></button>
                </form>
                <a class="preview" target="_blank" rel="noopener"
                   href="/interface/main/copilot_print_preview.php?type=education&id=<?php echo attr_url($card['id']); ?>">
                  <?php echo xlt('Preview'); ?> &rarr;
                </a>
              </div>
            </div>
          </div>
        <?php endforeach; ?>
      </div>

    </main>

  </div>
</form>

<script>
  // Keep the "Share N selected via portal" header button label in sync
  // with the actual checkbox count.
  function updateBulkLabel() {
    var n = document.querySelectorAll('#edu-bulk-form input[name="ids[]"]:checked').length;
    var btn = document.getElementById('edu-bulk-btn');
    if (!btn) return;
    btn.textContent = n > 0
      ? <?php echo json_encode((string)xl('Share')); ?> + ' ' + n + ' ' + <?php echo json_encode((string)xl('selected via portal')); ?>
      : <?php echo json_encode((string)xl('Share selected via portal')); ?>;
    btn.disabled = n === 0;
  }
  document.addEventListener('DOMContentLoaded', updateBulkLabel);
</script>

</body>
</html>
