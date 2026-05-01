<?php

/**
 * AgentForge — link orphan form_vitals rows to encounters via the
 * `forms` table. The OpenEMR FHIR Observation handler joins through
 * `forms` to surface vital signs; without a `forms` parent row the
 * vitals are invisible to FHIR (and therefore to the Co-Pilot agent).
 *
 * Idempotent — uses globals.copilot_link_vitals_v1 marker AND a
 * NOT EXISTS guard on the actual INSERT, so re-running is safe.
 *
 * URL: /interface/super/copilot_link_vitals.php?confirm=1
 *
 * @package OpenEMR
 * @author  AgentForge / Claude Code
 * @license https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

require_once(__DIR__ . "/../globals.php");

use OpenEMR\Common\Acl\AclMain;

if (!AclMain::aclCheckCore('admin', 'super')) {
    http_response_code(403);
    exit('Admin access required.');
}

if (($_GET['confirm'] ?? '') !== '1') {
    echo '<pre>Add ?confirm=1 to link form_vitals to encounters.</pre>';
    exit;
}

header('Content-Type: text/plain; charset=utf-8');

echo "AgentForge — linking form_vitals → forms → form_encounter\n";
echo str_repeat('=', 60) . "\n";

$before = sqlQuery("SELECT COUNT(*) AS n FROM forms WHERE formdir = 'vitals'")['n'] ?? 0;
echo "Existing 'vitals' rows in forms: $before\n";

// Insert one forms row per form_vitals row that doesn't already have one.
sqlStatement(
    "INSERT INTO forms (date, encounter, form_name, form_id, pid, user, formdir, deleted, authorized)
     SELECT fv.date,
            COALESCE(
                (SELECT fe.encounter FROM form_encounter fe
                  WHERE fe.pid = fv.pid AND DATE(fe.date) = DATE(fv.date)
                  ORDER BY ABS(TIMESTAMPDIFF(SECOND, fe.date, fv.date)) ASC
                  LIMIT 1),
                0
            ),
            'Vitals',
            fv.id,
            fv.pid,
            COALESCE(fv.user, 'admin'),
            'vitals',
            0,
            1
       FROM form_vitals fv
      WHERE NOT EXISTS (
            SELECT 1 FROM forms f
             WHERE f.formdir = 'vitals' AND f.form_id = fv.id
      )"
);

$after = sqlQuery("SELECT COUNT(*) AS n FROM forms WHERE formdir = 'vitals'")['n'] ?? 0;
$inserted = $after - $before;
echo "After: $after  (inserted $inserted)\n";

// FhirObservationVitalsService inner-joins form_vital_details. Without
// detail rows, FHIR Observation surface stays empty even when forms+
// form_vitals are populated. Insert one details row per vitals column
// (bps, bpd, weight, BMI, etc.) so each becomes a discrete Observation.
echo "\nPopulating form_vital_details...\n";
$detailsBefore = (int)sqlQuery("SELECT COUNT(*) AS n FROM form_vital_details")['n'];
$columns = ['bps', 'bpd', 'weight', 'height', 'temperature', 'pulse', 'respiration', 'BMI', 'oxygen_saturation'];
$rows = sqlStatement("SELECT id FROM form_vitals");
while ($v = sqlFetchArray($rows)) {
    foreach ($columns as $col) {
        sqlStatement(
            "INSERT IGNORE INTO form_vital_details (form_id, vitals_column) VALUES (?, ?)",
            [(int)$v['id'], $col]
        );
    }
}
$detailsAfter = (int)sqlQuery("SELECT COUNT(*) AS n FROM form_vital_details")['n'];
echo "  form_vital_details rows: $detailsBefore → $detailsAfter (inserted " . ($detailsAfter - $detailsBefore) . ")\n";

// Set marker
$exists = sqlQuery("SELECT gl_value FROM globals WHERE gl_name = 'copilot_link_vitals_v1'");
if (!$exists) {
    sqlStatement(
        "INSERT INTO globals (gl_name, gl_index, gl_value) VALUES ('copilot_link_vitals_v1', 0, ?)",
        [date('c')]
    );
    echo "Marker set: copilot_link_vitals_v1\n";
} else {
    echo "Marker already present (was: " . $exists['gl_value'] . "); leaving as-is.\n";
}

echo "\nFHIR should now surface Observations for these patients. Verify with:\n";
echo "  curl 'https://.../apis/default/fhir/Observation?patient={uuid}&category=vital-signs'\n";
