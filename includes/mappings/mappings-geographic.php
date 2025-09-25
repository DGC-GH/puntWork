<?php
/**
 * Geographic mapping definitions
 *
 * @package    Puntwork
 * @subpackage Mappings
 * @since      1.0.0
 */

namespace Puntwork;

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Geographic mappings and province data
 * Handles province name normalization and domain mappings
 */

/**
 * Get the province mapping array.
 *
 * @return array Province mappings.
 */
if (!function_exists('GetProvinceMap')) {
    function GetProvinceMap(): array {
        $cached = get_transient('puntwork_province_map');
        if (false === $cached) {
            $cached = [
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
            set_transient('puntwork_province_map', $cached, HOUR_IN_SECONDS);
        }
        return $cached;
    }
}