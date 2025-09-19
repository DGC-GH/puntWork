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
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Salary estimates and compensation data
 * Handles salary ranges by job category and industry
 */

/**
 * Get the salary estimates array.
 *
 * @return array Salary estimates by category.
 */
if (!function_exists('GetSalaryEstimates')) {
    function GetSalaryEstimates() {
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
}