<?php
// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

function infer_item_details(&$item, $fallback_domain, $lang, &$job_obj) {
    $province = strtolower(trim(isset($item->province) ? (string)$item->province : ''));
    $norm_province = get_province_map()[$province] ?? $fallback_domain;

    $title = isset($item->functiontitle) ? (string)$item->functiontitle : '';
    $enhanced_title = $title;
    if (isset($item->city)) $enhanced_title .= ' in ' . (string)$item->city;
    if (isset($item->province)) $enhanced_title .= ', ' . (string)$item->province;
    $enhanced_title = trim($enhanced_title);

    $slug = sanitize_title($enhanced_title . '-' . (string)$item->guid);
    $job_link = 'https://' . $norm_province . '/job/' . $slug;

    $fg = strtolower(trim(isset($item->functiongroup) ? (string)$item->functiongroup : ''));
    $estimate_key = array_reduce(array_keys(get_salary_estimates()), function($carry, $key) use ($fg) {
        return strpos($fg, strtolower($key)) !== false ? $key : $carry;
    }, null);

    $salary_text = '';
    if (isset($item->salaryfrom) && $item->salaryfrom != '0' && isset($item->salaryto) && $item->salaryto != '0') {
        $salary_text = '€' . (string)$item->salaryfrom . ' - €' . (string)$item->salaryto;
    } elseif (isset($item->salaryfrom) && $item->salaryfrom != '0') {
        $salary_text = '€' . (string)$item->salaryfrom;
    } else {
        $est_prefix = ($lang == 'nl' ? 'Geschat ' : ($lang == 'fr' ? 'Estimé ' : 'Est. '));
        if ($estimate_key) {
            $low = get_salary_estimates()[$estimate_key]['low'];
            $high = get_salary_estimates()[$estimate_key]['high'];
            $salary_text = $est_prefix . '€' . $low . ' - €' . $high;
        } else {
            $salary_text = '€3000 - €4500';
        }
    }

    $apply_link = isset($item->applylink) ? (string)$item->applylink : '';
    if ($apply_link) $apply_link .= '?utm_source=puntwork&utm_term=' . (string)$item->guid;

    $icon_key = array_reduce(array_keys(get_icon_map()), function($carry, $key) use ($fg) {
        return strpos($fg, strtolower($key)) !== false ? $key : $carry;
    }, null);
    $icon = $icon_key ? '<i class="fas ' . get_icon_map()[$icon_key] . '"></i>' : '<i class="fas fa-briefcase"></i>';

    $all_text = strtolower(implode(' ', [(string)$item->functiontitle, (string)$item->description, (string)$item->functiondescription, (string)$item->offerdescription, (string)$item->requirementsdescription, (string)$item->companydescription]));
    $job_car = (bool)preg_match('/bedrijfs(wagen|auto)|firmawagen|voiture de société|company car/i', $all_text);
    $job_remote = (bool)preg_match('/thuiswerk|télétravail|remote work|home office/i', $all_text);
    $job_meal_vouchers = (bool)preg_match('/maaltijdcheques|chèques repas|meal vouchers/i', $all_text);
    $job_flex_hours = (bool)preg_match('/flexibele uren|heures flexibles|flexible hours/i', $all_text);
    $job_skills = [];
    if (preg_match('/\bexcel\b|\bmicrosoft excel\b|\bms excel\b/i', $all_text)) $job_skills[] = 'Excel';
    if (preg_match('/\bwinbooks\b/i', $all_text)) $job_skills[] = 'WinBooks';

    $parttime = isset($item->parttime) && (string)$item->parttime == 'true';
    $job_time = $parttime ? ($lang == 'nl' ? 'Deeltijds' : ($lang == 'fr' ? 'Temps partiel' : 'Part-time')) : ($lang == 'nl' ? 'Voltijds' : ($lang == 'fr' ? 'Temps plein' : 'Full-time'));

    $job_desc = ($lang == 'nl' ? 'Vacature' : ($lang == 'fr' ? 'Emploi' : 'Job')) . ': ' . $enhanced_title . '. ' . (isset($item->functiondescription) ? (string)$item->functiondescription : '') . ($lang == 'nl' ? ' Bij ' : ($lang == 'fr' ? ' Chez ' : ' At ')) . (isset($item->companydescription) ? (string)$item->companydescription : ($lang == 'nl' ? 'bedrijf' : ($lang == 'fr' ? 'entreprise' : 'company'))) . '. ' . ($lang == 'nl' ? 'Voordelen: ' : ($lang == 'fr' ? 'Avantages: ' : 'Benefits: ')) . ($job_car ? ($lang == 'nl' ? 'Bedrijfswagen, ' : ($lang == 'fr' ? 'Voiture de société, ' : 'Company car, ')) : '') . ($job_meal_vouchers ? ($lang == 'nl' ? 'Maaltijdcheques, ' : ($lang == 'fr' ? 'Chèques repas, ' : 'Meal vouchers, ')) : '') . ($job_remote ? ($lang == 'nl' ? 'Thuiswerk, ' : ($lang == 'fr' ? 'Télétravail, ' : 'Remote work, ')) : '') . ($job_flex_hours ? ($lang == 'nl' ? 'Flexibele uren. ' : ($lang == 'fr' ? 'Heures flexibles. ' : 'Flexible hours. ')) : '') . ($lang == 'nl' ? 'Salaris: ' : ($lang == 'fr' ? 'Salaire: ' : 'Salary: ')) . $salary_text . '. ' . ($lang == 'nl' ? 'Vaardigheden: ' : ($lang == 'fr' ? 'Compétences: ' : 'Skills: ')) . implode(', ', $job_skills) . '. ' . ($lang == 'nl' ? 'Solliciteer nu!' : ($lang == 'fr' ? 'Postulez maintenant!' : 'Apply now!'));

    $languages = [];
    for ($i = 1; $i <= 3; $i++) {
        $lang_field = $i == 1 ? 'language' : "language$i";
        $level_field = $i == 1 ? 'languagelevel' : "languagelevel$i";
        if (isset($item->$lang_field) && !empty($item->$lang_field)) {
            $lang_name = (string)$item->$lang_field;
            $level = isset($item->$level_field) ? (string)$item->$level_field : '';
            $level_parts = explode(' - ', $level, 2);
            $level_num = isset($level_parts[0]) ? trim($level_parts[0]) : '';
            $level_label = isset($level_parts[1]) ? trim($level_parts[1]) : '';
            $formatted = "$lang_name: $level_label ($level_num/5)";
            $languages[] = "<li>$formatted</li>";
        }
    }
    $job_languages = !empty($languages) ? '<ul>' . implode('', $languages) . '</ul>' : '';

    $job_car_text = $job_car ? ($lang == 'nl' ? 'Bedrijfswagen inbegrepen' : ($lang == 'fr' ? 'Voiture de société incluse' : 'Company car included')) : '';
    $job_remote_text = $job_remote ? ($lang == 'nl' ? 'Thuiswerk mogelijk' : ($lang == 'fr' ? 'Télétravail possible' : 'Remote work available')) : '';
    $job_meal_vouchers_text = $job_meal_vouchers ? ($lang == 'nl' ? 'Maaltijdcheques voorzien' : ($lang == 'fr' ? 'Chèques repas inclus' : 'Meal vouchers provided')) : '';
    $job_flex_hours_text = $job_flex_hours ? ($lang == 'nl' ? 'Flexibele uren' : ($lang == 'fr' ? 'Heures flexibles' : 'Flexible hours')) : '';

    $job_obj['job_title'] = $enhanced_title;
    $job_obj['job_slug'] = $slug;
    $job_obj['job_link'] = $job_link;
    $job_obj['job_salary'] = $salary_text;
    $job_obj['job_apply'] = $apply_link;
    $job_obj['job_icon'] = $icon;
    $job_obj['job_car'] = $job_car_text;
    $job_obj['job_time'] = $job_time;
    $job_obj['job_description'] = $job_desc;
    $job_obj['job_remote'] = $job_remote_text;
    $job_obj['job_meal_vouchers'] = $job_meal_vouchers_text;
    $job_obj['job_flex_hours'] = $job_flex_hours_text;
    $job_obj['job_skills'] = $job_skills;
    $job_obj['job_posting'] = json_encode(build_job_schema($enhanced_title, $job_desc, $item, $norm_province, $job_time, $job_remote, $fg, $estimate_key));
    $job_obj['job_ecommerce'] = json_encode(build_ecomm_schema($enhanced_title, $job_desc, $item, $estimate_key));
    $job_obj['job_languages'] = $job_languages;
}
