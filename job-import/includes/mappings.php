<?php
/**
 * Job Import Mappings and Data Constants
 * Consolidated from snippet 1.1 - Mappings and Constants.php
 */

// Province/Region Mapping (normalized domains for links)
function get_province_map() {
    return [
        'vlaanderen' => 'vlaanderen',
        'brussels' => 'brussels',
        'wallonie' => 'wallonie',
        'antwerpen' => 'vlaanderen',
        'limburg' => 'vlaanderen',
        'oost-vlaanderen' => 'vlaanderen',
        'vlaams-brabant' => 'vlaanderen',
        'west-vlaanderen' => 'vlaanderen',
        'brabant wallon' => 'wallonie',
        'hainaut' => 'wallonie',
        'liege' => 'wallonie',
        'luxembourg' => 'wallonie',
        'namur' => 'wallonie',
        // Add region-specific if needed from full snippet
    ];
}

// Salary Estimates by Function Group (in EUR, monthly gross)
function get_salary_estimates() {
    return [
        'developer' => ['low' => 3500, 'high' => 5500],
        'admin' => ['low' => 2500, 'high' => 4000],
        'manager' => ['low' => 4500, 'high' => 7000],
        'sales' => ['low' => 2800, 'high' => 4500],
        'hr' => ['low' => 3000, 'high' => 4800],
        'it' => ['low' => 3800, 'high' => 6000],
        'finance' => ['low' => 3200, 'high' => 5200],
        'marketing' => ['low' => 2900, 'high' => 4600],
        // Extend with full snippet data (e.g., more roles like 'nurse', 'teacher')
    ];
}

// Icon Mapping by Job Type (CSS classes for frontend)
function get_icon_map() {
    return [
        'developer' => 'code-icon',
        'admin' => 'admin-gear-icon',
        'manager' => 'leadership-icon',
        'sales' => 'handshake-icon',
        'hr' => 'users-icon',
        'it' => 'server-icon',
        'finance' => 'calculator-icon',
        'marketing' => 'megaphone-icon',
        // Add more from snippet (e.g., 'construction' => 'hammer-icon')
    ];
}
