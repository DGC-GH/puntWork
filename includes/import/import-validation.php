<?php
/**
 * Enhanced Feed Validation & Quality Assurance
 *
 * @package    Puntwork
 * @subpackage Import
 * @since      1.1.0
 */

namespace Puntwork;

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

require_once __DIR__ . '/import-config.php';

/**
 * Comprehensive feed validation with semantic and quality checks
 */

/**
 * Enhanced feed validation with semantic analysis
 */
function validate_feed_comprehensive($json_path) {
    $config = get_import_config();
    $validation_config = $config['validation'];

    PuntWorkLogger::info('Starting comprehensive feed validation', PuntWorkLogger::CONTEXT_IMPORT, [
        'feed_path' => $json_path,
        'validation_config' => $validation_config
    ]);

    $validation_result = [
        'valid' => true,
        'errors' => [],
        'warnings' => [],
        'stats' => [
            'syntax_errors' => 0,
            'semantic_errors' => 0,
            'quality_warnings' => 0,
            'data_completeness_score' => 0,
            'quality_score' => 0
        ],
        'recommendations' => [],
        'sample_issues' => []
    ];

    if (!$validation_config['feed_integrity_check']) {
        PuntWorkLogger::info('Feed validation disabled by configuration', PuntWorkLogger::CONTEXT_IMPORT);
        return $validation_result;
    }

    // Syntax validation
    $syntax_result = validate_feed_syntax($json_path, $validation_config);
    $validation_result['stats']['syntax_errors'] = count($syntax_result['errors']);

    if (!$syntax_result['valid']) {
        $validation_result['valid'] = false;
        $validation_result['errors'] = array_merge($validation_result['errors'], $syntax_result['errors']);
        return $validation_result; // Stop here if syntax is invalid
    }

    // Semantic validation
    $semantic_result = validate_feed_semantics($json_path, $validation_config);
    $validation_result['stats']['semantic_errors'] = count($semantic_result['errors']);

    if (!$semantic_result['valid']) {
        $validation_result['valid'] = false;
        $validation_result['errors'] = array_merge($validation_result['errors'], $semantic_result['errors']);
    }

    // Quality validation
    $quality_result = validate_feed_quality($json_path, $validation_config);
    $validation_result['warnings'] = array_merge($validation_result['warnings'], $quality_result['warnings']);
    $validation_result['stats']['quality_warnings'] = count($quality_result['warnings']);

    // Calculate scores
    $validation_result['stats']['data_completeness_score'] = $syntax_result['data_completeness'] ?? 0;
    $validation_result['stats']['quality_score'] = calculate_quality_score($syntax_result, $semantic_result, $quality_result);

    // Generate recommendations
    $validation_result['recommendations'] = generate_validation_recommendations($validation_result);

    // Log result
    $log_level = $validation_result['valid'] ? 'info' : 'error';
    PuntWorkLogger::{$log_level}('Comprehensive feed validation completed', PuntWorkLogger::CONTEXT_IMPORT, [
        'valid' => $validation_result['valid'],
        'syntax_errors' => $validation_result['stats']['syntax_errors'],
        'semantic_errors' => $validation_result['stats']['semantic_errors'],
        'quality_warnings' => $validation_result['stats']['quality_warnings'],
        'quality_score' => $validation_result['stats']['quality_score'],
        'recommendations' => $validation_result['recommendations']
    ]);

    return $validation_result;
}

/**
 * Validate JSONL syntax and basic structure
 */
function validate_feed_syntax($json_path, $validation_config) {
    $result = [
        'valid' => true,
        'errors' => [],
        'stats' => ['total_lines' => 0, 'valid_lines' => 0, 'invalid_lines' => 0],
        'data_completeness' => 0
    ];

    if (!file_exists($json_path) || !is_readable($json_path)) {
        $result['valid'] = false;
        $result['errors'][] = 'Feed file not accessible';
        return $result;
    }

    $handle = fopen($json_path, 'r');
    if (!$handle) {
        $result['valid'] = false;
        $result['errors'][] = 'Cannot open feed file';
        return $result;
    }

    // Required fields for data completeness
    $required_fields = $validation_config['required_fields'] ?? ['guid', 'title'];
    $field_completeness = [];

    $line_number = 0;
    $sample_size = 100; // Analyze first 100 lines for statistics

    while (($line = fgets($handle)) !== false && $line_number < $sample_size) {
        $line_number++;
        $line = trim($line);

        if (empty($line)) {
            continue;
        }

        // Decode JSON
        $item = json_decode($line, true);
        if ($item === null) {
            $result['stats']['invalid_lines']++;
            $result['errors'][] = "Line {$line_number}: Invalid JSON - " . json_last_error_msg();

            if (count($result['errors']) >= 5) {
                $result['valid'] = false;
                break; // Don't spam with too many errors
            }
            continue;
        }

        $result['stats']['valid_lines']++;

        // Check required fields
        foreach ($required_fields as $field) {
            if (!isset($item[$field]) || empty($item[$field])) {
                if (!isset($field_completeness[$field])) {
                    $field_completeness[$field] = ['present' => 0, 'missing' => 0];
                }
                $field_completeness[$field]['missing']++;
            } else {
                if (!isset($field_completeness[$field])) {
                    $field_completeness[$field] = ['present' => 0, 'missing' => 0];
                }
                $field_completeness[$field]['present']++;
            }
        }

        // Basic structure validation
        if (!is_array($item)) {
            $result['errors'][] = "Line {$line_number}: Expected JSON object, got " . gettype($item);
            $result['valid'] = false;
        }
    }

    fclose($handle);
    $result['stats']['total_lines'] = $line_number;

    // Count all lines in file for completeness
    $total_lines = count(file($json_path));
    $result['stats']['total_file_lines'] = $total_lines;

    // Calculate data completeness score
    if (!empty($field_completeness)) {
        $field_scores = [];
        foreach ($field_completeness as $field => $data) {
            $total = $data['present'] + $data['missing'];
            $score = $total > 0 ? ($data['present'] / $total) * 100 : 0;
            $field_scores[] = $score;
        }
        $result['data_completeness'] = array_sum($field_scores) / count($field_scores);
    }

    // Check if too many lines are invalid
    if ($result['stats']['invalid_lines'] > $result['stats']['valid_lines'] * 0.1) { // More than 10% invalid
        $result['valid'] = false;
        $result['errors'][] = 'Feed has too many invalid lines (' . $result['stats']['invalid_lines'] . ' invalid out of ' . $result['stats']['total_lines'] . ')';
    }

    return $result;
}

/**
 * Validate semantic correctness of feed data
 */
function validate_feed_semantics($json_path, $validation_config) {
    $result = [
        'valid' => true,
        'errors' => [],
        'warnings' => [],
        'stats' => []
    ];

    if (!$validation_config['semantic_validation']) {
        return $result;
    }

    $handle = fopen($json_path, 'r');
    if (!$handle) {
        $result['valid'] = false;
        $result['errors'][] = 'Cannot open feed file for semantic validation';
        return $result;
    }

    $guids = [];
    $validation_issues = [];
    $line_number = 0;
    $sample_limit = 500; // Analyze first 500 items for performance

    while (($line = fgets($handle)) !== false && $line_number < $sample_limit) {
        $line_number++;
        $line = trim($line);

        if (empty($line)) {
            continue;
        }

        $item = json_decode($line, true);
        if (!$item || !isset($item['guid'])) {
            continue; // Skip invalid items
        }

        // Duplicate GUID detection
        $guid = $item['guid'];
        if (isset($guids[$guid])) {
            $validation_issues[] = [
                'type' => 'duplicate_guid',
                'line' => $line_number,
                'guid' => $guid,
                'severity' => 'error'
            ];
        } else {
            $guids[$guid] = $line_number;
        }

        // URL validation
        if (isset($item['url']) && !empty($item['url'])) {
            if (!filter_var($item['url'], FILTER_VALIDATE_URL)) {
                $validation_issues[] = [
                    'type' => 'invalid_url',
                    'line' => $line_number,
                    'field' => 'url',
                    'value' => substr($item['url'], 0, 50),
                    'severity' => 'warning'
                ];
            }
        }

        // Email validation
        if (isset($item['contact_email']) && !empty($item['contact_email'])) {
            if (!filter_var($item['contact_email'], FILTER_VALIDATE_EMAIL)) {
                $validation_issues[] = [
                    'type' => 'invalid_email',
                    'line' => $line_number,
                    'field' => 'contact_email',
                    'severity' => 'warning'
                ];
            }
        }

        // Date validation
        if (isset($item['pubdate']) && !empty($item['pubdate'])) {
            $timestamp = strtotime($item['pubdate']);
            if ($timestamp === false) {
                $validation_issues[] = [
                    'type' => 'invalid_date',
                    'line' => $line_number,
                    'field' => 'pubdate',
                    'value' => $item['pubdate'],
                    'severity' => 'warning'
                ];
            } elseif ($timestamp > time() + 86400) { // More than 1 day in future
                $validation_issues[] = [
                    'type' => 'future_date',
                    'line' => $line_number,
                    'field' => 'pubdate',
                    'value' => $item['pubdate'],
                    'severity' => 'warning'
                ];
            } elseif ($timestamp < strtotime('-2 years')) { // More than 2 years old
                $validation_issues[] = [
                    'type' => 'very_old_date',
                    'line' => $line_number,
                    'field' => 'pubdate',
                    'value' => $item['pubdate'],
                    'severity' => 'info'
                ];
            }
        }

        // Title validation
        if (isset($item['title']) && strlen($item['title']) < 3) {
            $validation_issues[] = [
                'type' => 'title_too_short',
                'line' => $line_number,
                'field' => 'title',
                'value' => substr($item['title'], 0, 20),
                'severity' => 'warning'
            ];
        }

        // Salary validation
        if (isset($item['salary_min']) && isset($item['salary_max'])) {
            $min = (int)$item['salary_min'];
            $max = (int)$item['salary_max'];

            if ($min > $max) {
                $validation_issues[] = [
                    'type' => 'salary_min_greater_than_max',
                    'line' => $line_number,
                    'min' => $min,
                    'max' => $max,
                    'severity' => 'error'
                ];
            }
        }
    }

    fclose($handle);

    // Process validation issues
    foreach ($validation_issues as $issue) {
        if ($issue['severity'] === 'error') {
            $result['valid'] = false;
            $result['errors'][] = "Line {$issue['line']}: " . format_validation_issue($issue);
        } elseif ($issue['severity'] === 'warning') {
            $result['warnings'][] = "Line {$issue['line']}: " . format_validation_issue($issue);
        }
    }

    $result['stats'] = [
        'lines_analyzed' => $line_number,
        'unique_guids' => count($guids),
        'duplicate_guids' => count(array_filter($validation_issues, fn($i) => $i['type'] === 'duplicate_guid')),
        'issues_by_type' => array_count_values(array_column($validation_issues, 'type'))
    ];

    // If duplicate GUIDs found, mark as invalid
    if ($result['stats']['duplicate_guids'] > 0) {
        $result['valid'] = false;
        $result['errors'][] = "Found {$result['stats']['duplicate_guids']} duplicate GUIDs in feed";
    }

    return $result;
}

/**
 * Validate data quality aspects
 */
function validate_feed_quality($json_path, $validation_config) {
    $result = [
        'valid' => true,
        'warnings' => [],
        'stats' => []
    ];

    if (!$validation_config['data_quality_checks']) {
        return $result;
    }

    $handle = fopen($json_path, 'r');
    if (!$handle) {
        return $result;
    }

    $quality_metrics = [
        'titles' => ['total' => 0, 'empty' => 0, 'duplicate' => 0, 'short' => 0],
        'descriptions' => ['total' => 0, 'empty' => 0, 'short' => 0],
        'companies' => ['total' => 0, 'empty' => 0, 'duplicate' => 0],
        'locations' => ['total' => 0, 'empty' => 0, 'duplicate' => 0],
        'salaries' => ['present' => 0, 'range_issues' => 0]
    ];

    $titles_seen = [];
    $companies_seen = [];
    $locations_seen = [];
    $sample_size = 200; // Analyze quality on first 200 items
    $line_number = 0;

    while (($line = fgets($handle)) !== false && $line_number < $sample_size) {
        $line_number++;
        $line = trim($line);

        if (empty($line)) {
            continue;
        }

        $item = json_decode($line, true);
        if (!$item) {
            continue;
        }

        // Title quality
        if (isset($item['title'])) {
            $quality_metrics['titles']['total']++;
            $title = trim($item['title']);

            if (empty($title)) {
                $quality_metrics['titles']['empty']++;
            } elseif (strlen($title) < 10) {
                $quality_metrics['titles']['short']++;
            } elseif (isset($titles_seen[$title])) {
                $quality_metrics['titles']['duplicate']++;
            } else {
                $titles_seen[$title] = true;
            }
        }

        // Description quality
        if (isset($item['description'])) {
            $quality_metrics['descriptions']['total']++;
            $desc = trim(strip_tags($item['description']));

            if (empty($desc)) {
                $quality_metrics['descriptions']['empty']++;
            } elseif (strlen($desc) < 50) {
                $quality_metrics['descriptions']['short']++;
            }
        }

        // Company quality
        if (isset($item['company'])) {
            $quality_metrics['companies']['total']++;
            $company = trim($item['company']);

            if (empty($company)) {
                $quality_metrics['companies']['empty']++;
            } elseif (isset($companies_seen[$company])) {
                $quality_metrics['companies']['duplicate']++;
            } else {
                $companies_seen[$company] = true;
            }
        }

        // Location quality
        if (isset($item['location'])) {
            $quality_metrics['locations']['total']++;
            $location = trim($item['location']);

            if (empty($location)) {
                $quality_metrics['locations']['empty']++;
            } elseif (isset($locations_seen[$location])) {
                $quality_metrics['locations']['duplicate']++;
            } else {
                $locations_seen[$location] = true;
            }
        }

        // Salary quality
        if (isset($item['salary_min']) || isset($item['salary_max'])) {
            $quality_metrics['salaries']['present']++;

            if (isset($item['salary_min']) && isset($item['salary_max'])) {
                $min_val = (int)$item['salary_min'];
                $max_val = (int)$item['salary_max'];

                if ($min_val <= 0 || $max_val <= 0 || $max_val > 1000000) {
                    $quality_metrics['salaries']['range_issues']++;
                }
            }
        }
    }

    fclose($handle);

    // Generate quality warnings
    if ($quality_metrics['titles']['empty'] > 0) {
        $pct = ($quality_metrics['titles']['empty'] / $quality_metrics['titles']['total']) * 100;
        if ($pct > 5) {
            $result['warnings'][] = sprintf('%.1f%% of items have empty titles', $pct);
        }
    }

    if ($quality_metrics['titles']['duplicate'] > 0) {
        $pct = ($quality_metrics['titles']['duplicate'] / $quality_metrics['titles']['total']) * 100;
        if ($pct > 10) {
            $result['warnings'][] = sprintf('%.1f%% of titles are duplicates', $pct);
        }
    }

    if ($quality_metrics['descriptions']['empty'] > 0) {
        $pct = ($quality_metrics['descriptions']['empty'] / $quality_metrics['descriptions']['total']) * 100;
        if ($pct > 10) {
            $result['warnings'][] = sprintf('%.1f%% of items have empty descriptions', $pct);
        }
    }

    $result['stats'] = $quality_metrics;

    return $result;
}

/**
 * Calculate overall quality score
 */
function calculate_quality_score($syntax, $semantic, $quality) {
    $score = 100;

    // Syntax issues heavily penalize score
    $syntax_penalty = count($syntax['errors']) * 10;
    $score -= min($syntax_penalty, 50);

    // Semantic errors also penalize
    $semantic_penalty = count($semantic['errors']) * 5;
    $score -= min($semantic_penalty, 25);

    // Quality warnings have smaller penalty
    $quality_penalty = count($quality['warnings']) * 2;
    $score -= min($quality_penalty, 25);

    // Bonus for high data completeness
    $completeness_bonus = ($syntax['data_completeness'] ?? 0) - 80; // Bonus above 80%
    if ($completeness_bonus > 0) {
        $score += min($completeness_bonus, 10);
    }

    return max(0, min(100, $score));
}

/**
 * Generate validation recommendations
 */
function generate_validation_recommendations($validation_result) {
    $recommendations = [];

    $stats = $validation_result['stats'];

    if ($stats['syntax_errors'] > 0) {
        $recommendations[] = 'Fix JSON syntax errors before importing';
    }

    if ($stats['semantic_errors'] > 0) {
        $recommendations[] = 'Correct semantic issues like duplicate GUIDs';
    }

    if ($stats['quality_warnings'] > 10) {
        $recommendations[] = 'Review and improve data quality issues';
    }

    if (($stats['quality_score'] ?? 100) < 70) {
        $recommendations[] = 'Overall feed quality is poor - consider data source improvements';
    }

    if (($stats['data_completeness_score'] ?? 100) < 80) {
        $recommendations[] = 'Improve data completeness by ensuring all required fields are present';
    }

    return $recommendations;
}

/**
 * Format validation issue for display
 */
function format_validation_issue($issue) {
    switch ($issue['type']) {
        case 'duplicate_guid':
            return "Duplicate GUID: {$issue['guid']}";

        case 'invalid_url':
            return "Invalid URL in {$issue['field']}: {$issue['value']}";

        case 'invalid_email':
            return "Invalid email format";

        case 'invalid_date':
            return "Invalid date in {$issue['field']}: {$issue['value']}";

        case 'future_date':
            return "Future date in {$issue['field']}: {$issue['value']}";

        case 'very_old_date':
            return "Very old date (more than 2 years): {$issue['value']}";

        case 'title_too_short':
            return "Title too short: {$issue['value']}";

        case 'salary_min_greater_than_max':
            return "Salary min ({$issue['min']}) > max ({$issue['max']})";

        default:
            return ucfirst(str_replace('_', ' ', $issue['type']));
    }
}

/**
 * Pre-import feed check with automatic blocking
 */
function pre_import_feed_validation($json_path) {
    $config = get_import_config();
    $validation_config = $config['validation'];

    if ($validation_config['malformed_item_handling'] === 'fail') {
        $comprehensive_result = validate_feed_comprehensive($json_path);

        if (!$comprehensive_result['valid']) {
            PuntWorkLogger::error('Feed validation failed - blocking import', PuntWorkLogger::CONTEXT_IMPORT, [
                'errors' => $comprehensive_result['errors'],
                'warnings' => $comprehensive_result['warnings'],
                'stats' => $comprehensive_result['stats']
            ]);

            // Send admin alert
            send_validation_failure_alert($comprehensive_result);

            return [
                'can_import' => false,
                'blocking_reasons' => $comprehensive_result['errors'],
                'warnings' => $comprehensive_result['warnings'],
                'validation_result' => $comprehensive_result
            ];
        }
    }

    return ['can_import' => true, 'validation_result' => []];
}

/**
 * Send validation failure alert to admin
 */
function send_validation_failure_alert($validation_result) {
    $subject = '[Puntwork Import] Feed Validation Failed - Import Blocked';

    $message = "Feed Validation Failed\n\n";
    $message .= "Import has been blocked due to validation errors.\n\n";

    $message .= "Errors:\n";
    foreach ($validation_result['errors'] as $error) {
        $message .= "- {$error}\n";
    }

    $message .= "\nWarnings:\n";
    foreach ($validation_result['warnings'] as $warning) {
        $message .= "- {$warning}\n";
    }

    $message .= "\nQuality Score: " . ($validation_result['stats']['quality_score'] ?? 'N/A') . "/100\n";
    $message .= "Data Completeness: " . round($validation_result['stats']['data_completeness_score'] ?? 0, 1) . "%\n";

    $message .= "\nRecommendations:\n";
    foreach ($validation_result['recommendations'] as $rec) {
        $message .= "- {$rec}\n";
    }

    // Send email to admin
    $admin_email = get_option('admin_email');
    wp_mail($admin_email, $subject, $message);
}
