<?php

/**
 * Shared helpers for AgentForge Co-Pilot mock pages.
 *
 * - cp_format_provider_name(): turn a users row into "Dr. First Last, MD".
 * - cp_initials(): two-letter initials from a user/patient row.
 * - cp_decode_log_comment(): decode the base64-encoded `log.comments`
 *   column so it shows readable URLs/queries instead of gibberish.
 *
 * @package OpenEMR
 * @author  AgentForge / Claude Code
 * @license https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

if (!function_exists('cp_format_provider_name')) {
    /**
     * Format a users row as a display name.
     *
     * Rules:
     *   - admin (id=1) → "Site Administrator"
     *   - "Admin davis" / "Admin hamming" → "davis (admin)" / "hamming (admin)"
     *   - Provider with title "MD"/"DO"/"RN"/etc. → "Dr. Eduardo Rivera, MD"
     *     (RN does not get the "Dr." prefix — only doctoral titles do)
     *   - Otherwise → "First Last"
     */
    function cp_format_provider_name(?array $u): string
    {
        if (!$u) { return 'Unknown'; }
        $username = $u['username'] ?? '';
        $fn = trim((string)($u['fname'] ?? ''));
        $ln = trim((string)($u['lname'] ?? ''));
        $title = trim((string)($u['title'] ?? ''));

        if ($username === 'admin' || ($ln === 'Administrator' && $fn === '')) {
            return 'Site Administrator';
        }
        if ($fn === 'Admin' && $ln !== '') {
            return $ln . ' (admin)';
        }
        $doctorPrefixes = ['MD', 'DO', 'DDS', 'DMD', 'DPM', 'DC', 'OD', 'NP', 'PA', 'PHD'];
        $upperT = strtoupper($title);
        $isDoctor = in_array($upperT, $doctorPrefixes, true);
        $base = trim($fn . ' ' . $ln);
        if ($base === '') { return $username ?: 'Unknown'; }
        if ($isDoctor) {
            return 'Dr. ' . $base . ', ' . $title;
        }
        if ($title !== '') {
            return $base . ', ' . $title;
        }
        return $base;
    }
}

if (!function_exists('cp_initials')) {
    function cp_initials(?array $u): string
    {
        if (!$u) { return '??'; }
        $fn = (string)($u['fname'] ?? '');
        $ln = (string)($u['lname'] ?? '');
        $username = (string)($u['username'] ?? '');
        // Prefer first letter of fname + first letter of lname.
        $i1 = strtoupper(substr($fn, 0, 1));
        $i2 = strtoupper(substr($ln, 0, 1));
        $combined = trim($i1 . $i2);
        if ($combined !== '') { return $combined; }
        // Fall back to two letters of the username.
        if ($username !== '') { return strtoupper(substr($username, 0, 2)); }
        return '??';
    }
}

if (!function_exists('cp_decode_log_comment')) {
    /**
     * Decode the OpenEMR audit-log `comments` column.
     *
     * Comments are base64-encoded URL paths / SQL fragments. Everything
     * downstream renders better with the decoded text — and truncation
     * keeps the table tidy.
     */
    function cp_decode_log_comment(string $raw, int $maxLen = 100): string
    {
        $raw = trim($raw);
        if ($raw === '') { return '—'; }
        // Heuristic: base64 strings only contain A-Z a-z 0-9 +/= . Try decode.
        if (preg_match('#^[A-Za-z0-9+/=]+$#', $raw) && strlen($raw) % 4 === 0) {
            $decoded = base64_decode($raw, true);
            if ($decoded !== false && $decoded !== '' && mb_check_encoding($decoded, 'UTF-8')) {
                $raw = $decoded;
            }
        }
        // Collapse whitespace, truncate.
        $raw = preg_replace('/\s+/', ' ', $raw);
        if (mb_strlen($raw) > $maxLen) {
            $raw = mb_substr($raw, 0, $maxLen - 1) . '…';
        }
        return $raw;
    }
}
