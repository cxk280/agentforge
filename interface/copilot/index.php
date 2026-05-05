<?php
/**
 * Clinical Co-Pilot — patient chart integration page.
 *
 * Drop this file into interface/copilot/ inside the OpenEMR installation.
 * Add a link to it from the patient chart navigation (see below).
 *
 * The page reads the active patient PID from the OpenEMR session and
 * embeds the Co-Pilot chat UI in a full-height iframe.
 *
 * @package   ClinicalCoPilot
 */

require_once '../globals.php';

use OpenEMR\Common\Acl\AclMain;

// globals.php / auth.inc.php already redirect unauthenticated users to login

// Prefer the pid from the URL (set by the menu system); fall back to globals
$pid = (int)($_GET['pid'] ?? $GLOBALS['pid'] ?? 0);
if ($pid <= 0) {
    die('<p style="font-family:sans-serif;padding:20px;color:#cc2222;">No patient selected. Open a patient chart first.</p>');
}

// ACL check — user must have at least patient demographics read access
if (!AclMain::aclCheckCore('patients', 'demo')) {
    die('<p style="font-family:sans-serif;padding:20px;color:#cc2222;">Access denied.</p>');
}

// Agent backend URL — set COPILOT_BACKEND_URL in your environment or globals
// Order: $GLOBALS (DB-backed) → COPILOT_BACKEND_URL env var → localhost (local dev)
$backend_url = $GLOBALS['copilot_backend_url']
    ?? (getenv('COPILOT_BACKEND_URL') ?: 'http://localhost:8400');
$backend_url = rtrim($backend_url, '/');

// Active OpenEMR user — passed through to the chat UI so /chat POSTs
// can attribute Langfuse traces per clinician (closes residual risk
// R4 in SECURITY.md). We pass the username (stable, human-readable
// identifier), not authUserID, so the trace dashboard reads cleanly.
$activeUser = '';
$_uid = (int)($_SESSION['authUserID'] ?? 0);
if ($_uid > 0) {
    $r = sqlQuery("SELECT username FROM users WHERE id = ?", [$_uid]);
    if ($r) {
        $activeUser = (string)($r['username'] ?? '');
    }
}

// Build the iframe src — pass pid so the UI can resolve the FHIR UUID,
// plus active_user so the chat UI can attribute /chat POSTs.
$iframe_src = htmlspecialchars(
    $backend_url
    . '/?pid=' . $pid
    . '&backend=' . urlencode($backend_url)
    . ($activeUser !== '' ? '&user=' . urlencode($activeUser) : '')
);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Clinical Co-Pilot</title>
  <style>
    html, body { margin: 0; padding: 0; height: 100%; overflow: hidden; }
    iframe { width: 100%; height: 100%; border: none; display: block; }
  </style>
</head>
<body>
  <iframe
    src="<?php echo $iframe_src; ?>"
    title="Clinical Co-Pilot"
    allow="clipboard-write"
    sandbox="allow-scripts allow-same-origin allow-forms"
  ></iframe>
</body>
</html>
