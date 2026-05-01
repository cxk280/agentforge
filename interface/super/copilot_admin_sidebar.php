<?php

/**
 * Shared admin sidebar partial — used by all AgentForge admin sub-page
 * archetypes (Practice Settings, Users & Groups, ACL, Facilities,
 * Forms & Layouts, Templates, Coding & Lists, Modules, System,
 * Logs & Audit). Pass the active key to mark the current row.
 *
 * @package OpenEMR
 * @author  AgentForge / Claude Code
 * @license https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

if (!function_exists('cp_admin_sidebar')) {
    function cp_admin_sidebar(string $active = ''): string
    {
        $cats = [
            ['overview',         'Overview',          '/interface/super/copilot_admin.php'],
            ['settings',         'Practice Settings', '/interface/super/copilot_practice_settings.php'],
            ['users',            'Users & Groups',    '/interface/super/copilot_users.php'],
            ['acl',              'ACL',               '/interface/super/copilot_acl.php'],
            ['facilities',       'Facilities',        '/interface/super/copilot_facilities.php'],
            ['forms_layouts',    'Forms & Layouts',   '/interface/super/copilot_forms_layouts.php'],
            ['templates',        'Templates',         '/interface/super/copilot_templates.php'],
            ['coding_lists',     'Coding & Lists',    '/interface/super/copilot_coding_lists.php'],
            ['module_installer', 'Modules',           '/interface/super/copilot_module_installer.php'],
            ['system',           'System',            '/interface/super/copilot_system.php'],
            ['audit',            'Logs & Audit',      '/interface/super/copilot_audit.php'],
        ];

        $out  = '<aside class="cp-sidebar">';
        $out .= '<div class="cp-side-lbl">' . xlt('ADMIN') . '</div>';
        foreach ($cats as [$key, $label, $href]) {
            $cls = ($key === $active) ? 'cp-cat active' : 'cp-cat';
            $out .= '<a class="' . $cls . '" href="' . attr($href) . '">' . text($label) . '</a>';
        }
        $out .= '</aside>';
        return $out;
    }
}
