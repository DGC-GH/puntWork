<?php
// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

namespace Puntwork;

/**
 * Icon mappings for job categories
 * Maps job categories to FontAwesome icon classes
 */

/**
 * Get the icon mapping array.
 *
 * @return array Icon mappings by category.
 */
if (!function_exists('GetIconMap')) {
    function GetIconMap() {
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
}