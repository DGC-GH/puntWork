<?php

/**
 * Salary mapping definitions
 *
 * @package    Puntwork
 * @subpackage Mappings
 * @since      1.0.0
 */

namespace Puntwork;

// Prevent direct access
if (! defined('ABSPATH')) {
    exit;
}

use Puntwork\Utilities\CacheManager;

/**
 * Salary estimates and compensation data
 * Handles salary ranges by job category and industry
 */

/**
 * Get the salary estimates array.
 *
 * @return array Salary estimates by category.
 */
if (! function_exists('GetSalaryEstimates')) {
    function GetSalaryEstimates()
    {
        $cached = CacheManager::get('salary_estimates', CacheManager::GROUP_MAPPINGS);
        if (false === $cached) {
            $cached = array(
            'Accounting'                            => array(
            'low'  => 3500,
            'high' => 5000,
            ),
            'Comptabilité'                          => array(
            'low'  => 3500,
            'high' => 5000,
            ),
            'Administratie & Secretariaat'          => array(
            'low'  => 2800,
            'high' => 4200,
            ),
            'Administration & Secrétariat'          => array(
            'low'  => 2800,
            'high' => 4200,
            ),
            'Assurances'                            => array(
            'low'  => 3200,
            'high' => 4800,
            ),
            'Insurance'                             => array(
            'low'  => 3200,
            'high' => 4800,
            ),
            'Bank'                                  => array(
            'low'  => 3400,
            'high' => 5200,
            ),
            'Banque'                                => array(
            'low'  => 3400,
            'high' => 5200,
            ),
            'Bouw'                                  => array(
            'low'  => 3000,
            'high' => 4500,
            ),
            'Construction'                          => array(
            'low'  => 3000,
            'high' => 4500,
            ),
            'Contrôle Qualité, Prévention & Environnement' => array(
            'low'  => 3300,
            'high' => 4800,
            ),
            'Q&A, Milieu & Preventie'               => array(
            'low'  => 3300,
            'high' => 4800,
            ),
            'Customer Care'                         => array(
            'low'  => 2700,
            'high' => 4000,
            ),
            'Engineering'                           => array(
            'low'  => 3800,
            'high' => 5500,
            ),
            'Finance'                               => array(
            'low'  => 3600,
            'high' => 5200,
            ),
            'Gezondheidszorg, Sociale & Medische Diensten' => array(
            'low'  => 3200,
            'high' => 4700,
            ),
            'Santé, Service Social & Médical'       => array(
            'low'  => 3200,
            'high' => 4700,
            ),
            'Grafisch & Architectuur'               => array(
            'low'  => 3000,
            'high' => 4500,
            ),
            'Graphisme & Architecture'              => array(
            'low'  => 3000,
            'high' => 4500,
            ),
            'Horeca, Évènements & Tourisme'         => array(
            'low'  => 2500,
            'high' => 3800,
            ),
            'Horeca, Events & Toerisme'             => array(
            'low'  => 2500,
            'high' => 3800,
            ),
            'Human Resources'                       => array(
            'low'  => 3400,
            'high' => 5000,
            ),
            'Ressources Humaines'                   => array(
            'low'  => 3400,
            'high' => 5000,
            ),
            'Industrie'                             => array(
            'low'  => 3300,
            'high' => 4800,
            ),
            'Industry'                              => array(
            'low'  => 3300,
            'high' => 4800,
            ),
            'Informatique & Télécommunication'      => array(
            'low'  => 4000,
            'high' => 6000,
            ),
            'IT & Telecommunicatie'                 => array(
            'low'  => 4000,
            'high' => 6000,
            ),
            'Juridique'                             => array(
            'low'  => 3800,
            'high' => 5500,
            ),
            'Juridisch'                             => array(
            'low'  => 3800,
            'high' => 5500,
            ),
            'Management'                            => array(
            'low'  => 4500,
            'high' => 6500,
            ),
            'Maritiem'                              => array(
            'low'  => 3500,
            'high' => 5000,
            ),
            'Maritime'                              => array(
            'low'  => 3500,
            'high' => 5000,
            ),
            'Onderwijs'                             => array(
            'low'  => 3000,
            'high' => 4500,
            ),
            'R&D, Science & Recherche Scientifique' => array(
            'low'  => 3800,
            'high' => 5500,
            ),
            'R&D, Wetenschap & Wetenschappelijk Onderzoek' => array(
            'low'  => 3800,
            'high' => 5500,
            ),
            'Sales & Marketing'                     => array(
            'low'  => 3200,
            'high' => 4800,
            ),
            'Ventes & Marketing'                    => array(
            'low'  => 3200,
            'high' => 4800,
            ),
            'Technics'                              => array(
            'low'  => 3500,
            'high' => 5000,
            ),
            'Techniek'                              => array(
            'low'  => 3500,
            'high' => 5000,
            ),
            'Technique'                             => array(
            'low'  => 3500,
            'high' => 5000,
            ),
            'Textiel'                               => array(
            'low'  => 2800,
            'high' => 4200,
            ),
            'Textile'                               => array(
            'low'  => 2800,
            'high' => 4200,
            ),
            'Transport, Logistics & Purchase'       => array(
            'low'  => 3000,
            'high' => 4500,
            ),
            'Transport, Logistiek & Aankoop'        => array(
            'low'  => 3000,
            'high' => 4500,
            ),
            'Transport, Logistique & Achat'         => array(
            'low'  => 3000,
            'high' => 4500,
            ),
            );
            CacheManager::set('salary_estimates', $cached, CacheManager::GROUP_MAPPINGS, HOUR_IN_SECONDS);
        }
        return $cached;
    }
}
