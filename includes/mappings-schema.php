<?php
// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

namespace Puntwork;

/**
 * Schema.org structured data builders
 * Handles job posting and e-commerce schema generation
 */

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
                    'minValue' => GetSalaryEstimates()[$estimate_key]['low'],
                    'maxValue' => GetSalaryEstimates()[$estimate_key]['high'],
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
                'price' => (GetSalaryEstimates()[$estimate_key]['low'] + GetSalaryEstimates()[$estimate_key]['high']) / 2,
                'availability' => 'https://schema.org/InStock',
                'url' => isset($item->applylink) ? (string)$item->applylink : '',
            ] : null,
        ];
        return $schema;
    }
}