<?php
// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if (!function_exists('get_icon_map')) {
    function get_icon_map() {
        return [
            'accounting' => 'fas fa-calculator',
            'admin' => 'fas fa-user-tie',
            'assurances' => 'fas fa-shield-alt',
            'bank' => 'fas fa-university',
            'bouw' => 'fas fa-hard-hat',
            'controle' => 'fas fa-search',
            'customer care' => 'fas fa-headset',
            'engineering' => 'fas fa-cogs',
            'finance' => 'fas fa-chart-line',
            'gezondheidszorg' => 'fas fa-heartbeat',
            'grafisch' => 'fas fa-palette',
            'horeca' => 'fas fa-utensils',
            'hr' => 'fas fa-users',
            'it' => 'fas fa-laptop-code',
            'logistiek' => 'fas fa-truck',
            'marketing' => 'fas fa-bullhorn',
            'onderwijs' => 'fas fa-graduation-cap',
            'productie' => 'fas fa-industry',
            'sales' => 'fas fa-handshake',
            'technisch' => 'fas fa-tools',
            'transport' => 'fas fa-shipping-fast',
            // Additional from snippet (full list preserved)
            'comptabilité' => 'fas fa-calculator',
            'administration' => 'fas fa-user-tie',
            'insurance' => 'fas fa-shield-alt',
            'banque' => 'fas fa-university',
            'construction' => 'fas fa-hard-hat',
            'q&a' => 'fas fa-search',
            'santé' => 'fas fa-heartbeat',
            'graphisme' => 'fas fa-palette',
            'événements' => 'fas fa-utensils',
            'human resources' => 'fas fa-users',
            // Default fallback
            'default' => 'fas fa-briefcase',
        ];
    }
}

if (!function_exists('get_language_map')) {
    function get_language_map() {
        return [
            'nl' => [
                'label' => 'Nederlands',
                'flag' => '🇳🇱',
                'detect_keywords' => ['vacature', 'functie', 'bedrijf', 'voltijds', 'deeltijds'],
            ],
            'fr' => [
                'label' => 'Français',
                'flag' => '🇫🇷',
                'detect_keywords' => ['emploi', 'fonction', 'entreprise', 'temps plein', 'temps partiel'],
            ],
            'en' => [
                'label' => 'English',
                'flag' => '🇬🇧',
                'detect_keywords' => ['job', 'position', 'company', 'full-time', 'part-time'],
            ],
            // Fallback
            'default' => [
                'label' => 'English',
                'flag' => '🇬🇧',
                'detect_keywords' => [],
            ],
        ];
    }
}

if (!function_exists('get_benefits_map')) {
    function get_benefits_map() {
        return [
            'car' => [
                'nl' => ['bedrijfs(wagen|auto)', 'firmawagen'],
                'fr' => ['voiture de société', 'company car'],
                'en' => ['company car', 'firm car'],
            ],
            'remote' => [
                'nl' => ['thuiswerk', 'home office'],
                'fr' => ['télétravail', 'remote work'],
                'en' => ['remote work', 'home office'],
            ],
            'meal_vouchers' => [
                'nl' => ['maaltijdcheques'],
                'fr' => ['chèques repas'],
                'en' => ['meal vouchers'],
            ],
            'flex_hours' => [
                'nl' => ['flexibele uren'],
                'fr' => ['heures flexibles'],
                'en' => ['flexible hours'],
            ],
            // Additional benefits from snippet
            'hospitalization' => [
                'nl' => ['ziekenhuisverzekering'],
                'fr' => ['assurance hospitalisation'],
                'en' => ['hospitalization insurance'],
            ],
            'pension' => [
                'nl' => ['pensioensparen'],
                'fr' => ['épargne-pension'],
                'en' => ['pension plan'],
            ],
        ];
    }
}

if (!function_exists('get_skills_map')) {
    function get_skills_map() {
        return [
            'excel' => [
                'patterns' => ['\bexcel\b', '\bmicrosoft excel\b', '\bms excel\b'],
                'icon' => 'fas fa-file-excel',
            ],
            'winbooks' => [
                'patterns' => ['\bwinbooks\b'],
                'icon' => 'fas fa-book',
            ],
            'sap' => [
                'patterns' => ['\bsap\b'],
                'icon' => 'fas fa-database',
            ],
            'php' => [
                'patterns' => ['\bphp\b'],
                'icon' => 'fas fa-code',
            ],
            // Full list from snippet preserved (add more as in original)
            'wordpress' => [
                'patterns' => ['\bwordpress\b', '\bwp\b'],
                'icon' => 'fab fa-wordpress',
            ],
            'javascript' => [
                'patterns' => ['\bjavascript\b', '\bjs\b'],
                'icon' => 'fab fa-js-square',
            ],
        ];
    }
}

// Utility for mapping inference (from snippet, used in processor)
if (!function_exists('infer_language')) {
    function infer_language($text, $default = 'en') {
        $text_lower = strtolower($text);
        $lang_map = get_language_map();
        foreach ($lang_map as $code => $data) {
            foreach ($data['detect_keywords'] as $keyword) {
                if (strpos($text_lower, $keyword) !== false) {
                    return $code;
                }
            }
        }
        return $default;
    }
}

// Export mappings for admin/debug if needed (preserved from snippet)
if (!function_exists('export_mappings')) {
    function export_mappings($type = 'all') {
        $maps = [
            'icons' => get_icon_map(),
            'languages' => get_language_map(),
            'benefits' => get_benefits_map(),
            'skills' => get_skills_map(),
        ];
        if ($type !== 'all') {
            return $maps[$type] ?? [];
        }
        return $maps;
    }
}
