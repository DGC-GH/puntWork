<?php
// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

namespace Puntwork;

/**
 * ACF field definitions and field mappings
 * Handles field configurations and zero/empty field definitions
 */

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