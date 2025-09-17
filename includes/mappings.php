<?php
/**
 * Job Import Mappings and Data Constants
 * Consolidated from snippet 1.1 - Mappings and Constants.php
 */

// Province/Region Mapping (normalized domains for links)
function get_province_map() {
    return [
        'antwerp' => 'antwerpen.work',
        'antwerpen' => 'antwerpen.work',
        'anvers' => 'antwerpen.work',
        'brabant flamand' => 'vlaams-brabant.work',
        'brabant wallon' => 'brabant-wallon.work',
        'brabant-wallon' => 'brabant-wallon.work',
        'waals-brabant' => 'brabant-wallon.work',
        'walloon brabant' => 'brabant-wallon.work',
        'brussels capital-region' => 'bruxelles.work',
        'brussels hoofdstedelijk gewest' => 'bruxelles.work',
        'bruxelles' => 'bruxelles.work',
        'brussel' => 'bruxelles.work',
        'east flanders' => 'oost-vlaanderen.work',
        'flandre occidentale' => 'west-vlaanderen.work',
        'flandre orientale' => 'oost-vlaanderen.work',
        'hainaut' => 'hainaut.work',
        'henegouwen' => 'hainaut.work',
        'liège' => 'liege.work',
        'luik' => 'liege.work',
        'limbourg' => 'limburg.work',
        'limburg' => 'limburg.work',
        'luxembourg' => 'luxembourgjobs.work',
        'namen' => 'namur.work',
        'namur' => 'namur.work',
        'oost-vlaanderen' => 'oost-vlaanderen.work',
        'vlaams-brabant' => 'vlaams-brabant.work',
        'west-vlaanderen' => 'west-vlaanderen.work',
        'wallonie' => 'wallonie.work',
        'wallonia' => 'wallonie.work',
        'vlaanderen' => 'vlaanderen.work',
        'flanders' => 'vlaanderen.work',
        'flandre' => 'vlaanderen.work',
    ];
}

// Salary Estimates by Function Group (in EUR, monthly gross)
function get_salary_estimates() {
    return [
        'Accounting' => ['low' => 3500, 'high' => 5000],
        'Comptabilité' => ['low' => 3500, 'high' => 5000],
        'Administratie & Secretariaat' => ['low' => 2800, 'high' => 4200],
        'Administration & Secrétariat' => ['low' => 2800, 'high' => 4200],
        'Assurances' => ['low' => 3200, 'high' => 4800],
        'Insurance' => ['low' => 3200, 'high' => 4800],
        'Bank' => ['low' => 3400, 'high' => 5200],
        'Banque' => ['low' => 3400, 'high' => 5200],
        'Bouw' => ['low' => 3000, 'high' => 4500],
        'Construction' => ['low' => 3000, 'high' => 4500],
        'Contrôle Qualité, Prévention & Environnement' => ['low' => 3300, 'high' => 4800],
        'Q&A, Milieu & Preventie' => ['low' => 3300, 'high' => 4800],
        'Customer Care' => ['low' => 2700, 'high' => 4000],
        'Engineering' => ['low' => 3800, 'high' => 5500],
        'Finance' => ['low' => 3600, 'high' => 5200],
        'Gezondheidszorg, Sociale & Medische Diensten' => ['low' => 3200, 'high' => 4700],
        'Santé, Service Social & Médical' => ['low' => 3200, 'high' => 4700],
        'Grafisch & Architectuur' => ['low' => 3000, 'high' => 4500],
        'Graphisme & Architecture' => ['low' => 3000, 'high' => 4500],
        'Horeca, Évènements & Tourisme' => ['low' => 2500, 'high' => 3800],
        'Horeca, Events & Toerisme' => ['low' => 2500, 'high' => 3800],
        'Human Resources' => ['low' => 3400, 'high' => 5000],
        'Ressources Humaines' => ['low' => 3400, 'high' => 5000],
        'Industrie' => ['low' => 3300, 'high' => 4800],
        'Industry' => ['low' => 3300, 'high' => 4800],
        'Informatique & Télécommunication' => ['low' => 4000, 'high' => 6000],
        'IT & Telecommunicatie' => ['low' => 4000, 'high' => 6000],
        'Juridique' => ['low' => 3800, 'high' => 5500],
        'Juridisch' => ['low' => 3800, 'high' => 5500],
        'Management' => ['low' => 4500, 'high' => 6500],
        'Maritiem' => ['low' => 3500, 'high' => 5000],
        'Maritime' => ['low' => 3500, 'high' => 5000],
        'Onderwijs' => ['low' => 3000, 'high' => 4500],
        'R&D, Science & Recherche Scientifique' => ['low' => 3800, 'high' => 5500],
        'R&D, Wetenschap & Wetenschappelijk Onderzoek' => ['low' => 3800, 'high' => 5500],
        'Sales & Marketing' => ['low' => 3200, 'high' => 4800],
        'Ventes & Marketing' => ['low' => 3200, 'high' => 4800],
        'Technics' => ['low' => 3500, 'high' => 5000],
        'Techniek' => ['low' => 3500, 'high' => 5000],
        'Technique' => ['low' => 3500, 'high' => 5000],
        'Textiel' => ['low' => 2800, 'high' => 4200],
        'Textile' => ['low' => 2800, 'high' => 4200],
        'Transport, Logistics & Purchase' => ['low' => 3000, 'high' => 4500],
        'Transport, Logistiek & Aankoop' => ['low' => 3000, 'high' => 4500],
        'Transport, Logistique & Achat' => ['low' => 3000, 'high' => 4500],
    ];
}

// Icon Mapping by Job Type (CSS classes for frontend)
function get_icon_map() {
    return [
        'Accounting' => 'fa-calculator-alt',
        'Comptabilité' => 'fa-file-invoice-dollar',
        'Administratie & Secretariaat' => 'fa-file-signature',
        'Administration & Secrétariat' => 'fa-file-signature',
        'Assurances' => 'fa-shield-check',
        'Insurance' => 'fa-shield-check',
        'Bank' => 'fa-piggy-bank',
        'Banque' => 'fa-piggy-bank',
        'Bouw' => 'fa-tools',
        'Construction' => 'fa-hard-hat',
        'Contrôle Qualité, Prévention & Environnement' => 'fa-search-dollar',
        'Q&A, Milieu & Preventie' => 'fa-search-dollar',
        'Customer Care' => 'fa-headset',
        'Engineering' => 'fa-drafting-compass',
        'Finance' => 'fa-chart-line',
        'Gezondheidszorg, Sociale & Medische Diensten' => 'fa-stethoscope',
        'Santé, Service Social & Médical' => 'fa-stethoscope',
        'Grafisch & Architectuur' => 'fa-palette',
        'Graphisme & Architecture' => 'fa-palette',
        'Horeca, Évènements & Tourisme' => 'fa-concierge-bell',
        'Horeca, Events & Toerisme' => 'fa-concierge-bell',
        'Human Resources' => 'fa-user-tie',
        'Ressources Humaines' => 'fa-user-tie',
        'Industrie' => 'fa-industry',
        'Industry' => 'fa-industry',
        'Informatique & Télécommunication' => 'fa-network-wired',
        'IT & Telecommunicatie' => 'fa-network-wired',
        'Juridique' => 'fa-balance-scale',
        'Juridisch' => 'fa-balance-scale',
        'Management' => 'fa-users-cog',
        'Maritiem' => 'fa-anchor',
        'Maritime' => 'fa-anchor',
        'Onderwijs' => 'fa-chalkboard-teacher',
        'R&D, Science & Recherche Scientifique' => 'fa-microscope',
        'R&D, Wetenschap & Wetenschappelijk Onderzoek' => 'fa-microscope',
        'Sales & Marketing' => 'fa-handshake',
        'Ventes & Marketing' => 'fa-handshake',
        'Technics' => 'fa-wrench',
        'Techniek' => 'fa-wrench',
        'Technique' => 'fa-wrench',
        'Textiel' => 'fa-tshirt',
        'Textile' => 'fa-tshirt',
        'Transport, Logistics & Purchase' => 'fa-shipping-fast',
        'Transport, Logistiek & Aankoop' => 'fa-shipping-fast',
        'Transport, Logistique & Achat' => 'fa-shipping-fast',
    ];
}

if (!function_exists('get_acf_fields')) {
    function get_acf_fields() {
        return [
            'guid', 'category', 'title', 'description', 'pubdate', 'updated', 'link', 'applylink', 'magiclink',
            'branche', 'postalcode', 'city', 'province', 'provincecode', 'country', 'validfrom', 'validtill',
            'channeltype', 'functiongroup', 'functiongroup2', 'functiongroup3', 'functiongroupid',
            'functiongroupid2', 'functiongroupid3', 'function', 'function2', 'function3', 'functionid',
            'functionid2', 'functionid3', 'functiontitle', 'functiondescription', 'education', 'education2',
            'education3', 'educationid', 'educationid2', 'educationid3', 'educationgroup', 'educationgroup2',
            'educationgroup3', 'educationgroupcode', 'educationgroupcode2', 'educationgroupcode3', 'jobtype',
            'jobtypecode', 'jobtypegroup', 'jobtypegroupcode', 'contracttype', 'contracttype2', 'contracttype3',
            'contracttypecode', 'contracttypecode2', 'contracttypecode3', 'experience', 'experiencecode', 'brand',
            'accountid', 'internal', 'payrollid', 'payroll', 'brancheid', 'label', 'labelid', 'language',
            'language2', 'language3', 'languagecode', 'languagecode2', 'languagecode3', 'languagelevel',
            'languagelevel2', 'languagelevel3', 'languagelevelcode', 'languagelevelcode2', 'languagelevelcode3',
            'office', 'officeid', 'officestreet', 'officehousenumber', 'officeaddition', 'officepostalcode',
            'officecity', 'officetelephone', 'officeemail', 'hours', 'salaryfrom', 'salaryto', 'salarytype',
            'salarytypecode', 'parttime', 'offerdescription', 'requirementsdescription', 'reference', 'shift',
            'shiftcode', 'driverslicense', 'driverslicenseid', 'publicationlanguage', 'companydescription',
            // New fields
            'job_posting', 'job_icon', 'job_title', 'job_slug', 'job_link', 'job_salary', 'job_apply', 'job_car', 'job_time', 'job_description', 'job_remote', 'job_meal_vouchers', 'job_flex_hours', 'job_skills', 'job_ecommerce', 'job_languages',
        ];
    }
}

if (!function_exists('get_zero_empty_fields')) {
    function get_zero_empty_fields() {
        return [
            'functiongroup2', 'functiongroup3', 'functiongroupid2', 'functiongroupid3', 'function2', 'function3',
            'functionid2', 'functionid3', 'education2', 'education3', 'educationid2', 'educationid3',
            'educationgroup2', 'educationgroup3', 'educationgroupcode2', 'educationgroupcode3',
            'contracttype2', 'contracttype3', 'contracttypecode2', 'contracttypecode3',
            'language2', 'language3', 'languagecode2', 'languagecode3', 'languagelevel2', 'languagelevel3',
            'languagelevelcode2', 'languagelevelcode3', 'salaryfrom', 'salaryto',
        ];
    }
}

if (!function_exists('build_job_schema')) {
    function build_job_schema($enhanced_title, $job_desc, $item, $norm_province, $job_time, $job_remote, $fg, $estimate_key) {
        $schema = [
            '@context' => 'https://schema.org',
            '@type' => 'JobPosting',
            'title' => $enhanced_title,
            'description' => $job_desc,
            'datePosted' => isset($item->pubdate) ? date('c', strtotime((string)$item->pubdate)) : date('c'),
            'validThrough' => isset($item->validtill) ? date('c', strtotime((string)$item->validtill)) : null,
            'hiringOrganization' => [
                '@type' => 'Organization',
                'name' => isset($item->companydescription) ? strip_tags((string)$item->companydescription) : 'Unknown',
            ],
            'jobLocation' => [
                '@type' => 'Place',
                'address' => [
                    '@type' => 'PostalAddress',
                    'addressLocality' => isset($item->city) ? (string)$item->city : '',
                    'addressRegion' => isset($item->province) ? (string)$item->province : '',
                    'postalCode' => isset($item->postalcode) ? (string)$item->postalcode : '',
                    'addressCountry' => 'BE',
                ]
            ],
            'employmentType' => $job_time,
            'jobLocationType' => $job_remote ? 'TELECOMMUTE' : null,
            'applicantLocationRequirements' => $job_remote ? [
                '@type' => 'Country',
                'name' => 'Belgium',
            ] : null,
            'occupationalCategory' => $fg,
            'baseSalary' => $estimate_key ? [
                '@type' => 'MonetaryAmount',
                'currency' => 'EUR',
                'value' => [
                    '@type' => 'QuantitativeValue',
                    'minValue' => get_salary_estimates()[$estimate_key]['low'],
                    'maxValue' => get_salary_estimates()[$estimate_key]['high'],
                    'unitText' => 'MONTH',
                ]
            ] : null,
            'url' => isset($item->job_link) ? $item->job_link : 'https://' . $norm_province,
            'identifier' => [
                '@type' => 'PropertyValue',
                'name' => 'GUID',
                'value' => (string)$item->guid,
            ],
        ];
        return $schema;
    }
}

if (!function_exists('build_ecomm_schema')) {
    function build_ecomm_schema($enhanced_title, $job_desc, $item, $estimate_key) {
        $schema = [
            '@context' => 'https://schema.org',
            '@type' => 'Product',
            'name' => $enhanced_title,
            'description' => $job_desc,
            'category' => isset($item->functiongroup) ? (string)$item->functiongroup : '',
            'offers' => $estimate_key ? [
                '@type' => 'Offer',
                'priceCurrency' => 'EUR',
                'price' => (get_salary_estimates()[$estimate_key]['low'] + get_salary_estimates()[$estimate_key]['high']) / 2,
                'availability' => 'https://schema.org/InStock',
                'url' => isset($item->applylink) ? (string)$item->applylink : '',
            ] : null,
        ];
        return $schema;
    }
}
