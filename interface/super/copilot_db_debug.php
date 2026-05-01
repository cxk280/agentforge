<?php

/**
 * Throwaway diagnostic — dump the first few patient_data rows + their
 * UUID column state so we can see what the agent's resolver sees.
 *
 * Delete this file after the demo.
 *
 * @license https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

require_once(__DIR__ . "/../globals.php");

use OpenEMR\Common\Acl\AclMain;

if (!AclMain::aclCheckCore('admin', 'super')) {
    http_response_code(403);
    exit('Admin access required.');
}

header('Content-Type: text/plain; charset=utf-8');

echo "FHIR vitals join trace (Ted Shaw, pid=1):\n";
echo str_repeat('=', 70) . "\n";
$rows = sqlStatement("
    SELECT vitals.id AS v_id, vitals.pid AS v_pid, vitals.date AS v_date,
           vitals.bps, vitals.bpd, vitals.BMI,
           forms.id AS f_id, forms.encounter AS f_enc, forms.deleted AS f_del,
           fe.encounter AS fe_enc, fe.id AS fe_id
      FROM form_vitals vitals
      LEFT JOIN forms ON forms.form_id = vitals.id AND forms.formdir = 'vitals'
      LEFT JOIN form_encounter fe ON fe.encounter = forms.encounter AND fe.pid = forms.pid
     WHERE vitals.pid = 1
");
$count = 0;
while ($r = sqlFetchArray($rows)) {
    print_r($r);
    $count++;
}
echo "rows: $count\n\n";

echo "All forms entries pid=1:\n";
$rows = sqlStatement("SELECT id, pid, encounter, formdir, form_id, deleted FROM forms WHERE pid = 1");
while ($r = sqlFetchArray($rows)) {
    print_r($r);
}

echo "\npatient_data — UUID inspection\n";
echo str_repeat('=', 70) . "\n";
$rows = sqlStatement("SELECT pid, fname, lname, uuid IS NULL AS is_null, uuid = '' AS is_empty, LENGTH(uuid) AS len, HEX(uuid) AS hex FROM patient_data ORDER BY pid LIMIT 20");
while ($r = sqlFetchArray($rows)) {
    printf(
        "pid=%-5s  %-12s %-15s  is_null=%s  is_empty=%s  len=%-3s  hex=%s\n",
        $r['pid'],
        $r['fname'],
        $r['lname'],
        $r['is_null'],
        $r['is_empty'],
        $r['len'] ?? '(null)',
        $r['hex'] ?? '(null)'
    );
}

echo "\nMarkers:\n";
$markers = sqlStatement("SELECT gl_name, gl_value FROM globals WHERE gl_name LIKE 'copilot%'");
while ($r = sqlFetchArray($markers)) {
    echo "  {$r['gl_name']} = {$r['gl_value']}\n";
}

echo "\nDB connection info:\n";
echo "  user=" . sqlQuery("SELECT CURRENT_USER() AS u")['u'] . "\n";
echo "  db=" . sqlQuery("SELECT DATABASE() AS d")['d'] . "\n";
echo "  patient_count=" . sqlQuery("SELECT COUNT(*) AS n FROM patient_data")['n'] . "\n";
echo "  uuid_null_count=" . sqlQuery("SELECT COUNT(*) AS n FROM patient_data WHERE uuid IS NULL")['n'] . "\n";
