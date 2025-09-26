<?php
/**
 * Advanced Job Deduplication Algorithms
 *
 * @package    Puntwork
 * @subpackage Utilities
 * @since      1.0.14
 */

namespace Puntwork;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Advanced deduplication system with multiple algorithms
 */
class JobDeduplicator {

    // Similarity thresholds
    const EXACT_MATCH = 1.0;
    const HIGH_SIMILARITY = 0.85;
    const MEDIUM_SIMILARITY = 0.70;
    const LOW_SIMILARITY = 0.50;

    // Deduplication strategies
    const STRATEGY_GUID = 'guid';
    const STRATEGY_TITLE_COMPANY = 'title_company';
    const STRATEGY_CONTENT_HASH = 'content_hash';
    const STRATEGY_FUZZY_TITLE = 'fuzzy_title';

    /**
     * Configuration for deduplication rules
     */
    private static $config = [
        'enable_fuzzy_matching' => true,
        'title_similarity_threshold' => 0.85,
        'company_similarity_threshold' => 0.90,
        'max_candidates' => 10,
        'strategies' => [
            self::STRATEGY_GUID,
            self::STRATEGY_TITLE_COMPANY,
            self::STRATEGY_CONTENT_HASH,
            self::STRATEGY_FUZZY_TITLE
        ]
    ];

    /**
     * Find potential duplicates for a job item
     *
     * @param object $job_item Job item to check for duplicates
     * @param array $existing_jobs Array of existing job posts
     * @return array Array of potential duplicates with similarity scores
     */
    public static function find_duplicates($job_item, $existing_jobs = null) {
        if ($existing_jobs === null) {
            $existing_jobs = self::get_existing_jobs_for_comparison($job_item);
        }

        $duplicates = [];

        foreach ($existing_jobs as $existing_job) {
            $similarity = self::calculate_similarity($job_item, $existing_job);

            if ($similarity >= self::$config['title_similarity_threshold']) {
                $duplicates[] = [
                    'post_id' => $existing_job->ID,
                    'similarity' => $similarity,
                    'reasons' => self::get_similarity_reasons($job_item, $existing_job),
                    'strategy' => self::determine_matching_strategy($job_item, $existing_job)
                ];
            }
        }

        // Sort by similarity (highest first)
        usort($duplicates, function($a, $b) {
            return $b['similarity'] <=> $a['similarity'];
        });

        return array_slice($duplicates, 0, self::$config['max_candidates']);
    }

    /**
     * Calculate overall similarity between two job items
     */
    private static function calculate_similarity($job1, $job2) {
        $similarity = 0;
        $weights = 0;

        // Title similarity (highest weight)
        $title_sim = self::calculate_text_similarity(
            self::normalize_text($job1->title ?? ''),
            self::normalize_text($job2->post_title ?? '')
        );
        $similarity += $title_sim * 0.4;
        $weights += 0.4;

        // Company similarity
        $company_sim = self::calculate_text_similarity(
            self::normalize_text($job1->company ?? $job1->companyname ?? ''),
            self::normalize_text(get_post_meta($job2->ID, 'company', true) ?? '')
        );
        $similarity += $company_sim * 0.3;
        $weights += 0.3;

        // Location similarity
        $location_sim = self::calculate_text_similarity(
            self::normalize_text($job1->location ?? $job1->city ?? ''),
            self::normalize_text(get_post_meta($job2->ID, 'location', true) ?? '')
        );
        $similarity += $location_sim * 0.2;
        $weights += 0.2;

        // Content similarity (lower weight)
        $content_sim = self::calculate_text_similarity(
            self::normalize_text($job1->description ?? ''),
            self::normalize_text($job2->post_content ?? '')
        );
        $similarity += $content_sim * 0.1;
        $weights += 0.1;

        return $weights > 0 ? $similarity / $weights : 0;
    }

    /**
     * Calculate text similarity using multiple algorithms
     */
    private static function calculate_text_similarity($text1, $text2) {
        if (empty($text1) || empty($text2)) {
            return empty($text1) && empty($text2) ? 1.0 : 0.0;
        }

        // Exact match
        if (strtolower($text1) === strtolower($text2)) {
            return self::EXACT_MATCH;
        }

        // Levenshtein distance for short strings
        if (strlen($text1) < 100 && strlen($text2) < 100) {
            $levenshtein = levenshtein(strtolower($text1), strtolower($text2));
            $max_len = max(strlen($text1), strlen($text2));
            return $max_len > 0 ? 1 - ($levenshtein / $max_len) : 0;
        }

        // Jaccard similarity for longer texts
        return self::jaccard_similarity($text1, $text2);
    }

    /**
     * Calculate Jaccard similarity coefficient
     */
    private static function jaccard_similarity($text1, $text2) {
        $words1 = self::get_word_tokens($text1);
        $words2 = self::get_word_tokens($text2);

        if (empty($words1) && empty($words2)) {
            return 1.0;
        }

        $intersection = array_intersect($words1, $words2);
        $union = array_unique(array_merge($words1, $words2));

        return count($union) > 0 ? count($intersection) / count($union) : 0;
    }

    /**
     * Tokenize text into words
     */
    private static function get_word_tokens($text) {
        // Remove punctuation and normalize
        $text = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $text);
        $text = preg_replace('/\s+/', ' ', $text);
        $words = explode(' ', strtolower(trim($text)));

        // Filter out common stop words and short words
        $stop_words = ['the', 'a', 'an', 'and', 'or', 'but', 'in', 'on', 'at', 'to', 'for', 'of', 'with', 'by', 'is', 'are', 'was', 'were', 'be', 'been', 'being', 'have', 'has', 'had', 'do', 'does', 'did', 'will', 'would', 'could', 'should', 'may', 'might', 'must', 'can', 'shall'];

        return array_filter($words, function($word) use ($stop_words) {
            return strlen($word) > 2 && !in_array($word, $stop_words);
        });
    }

    /**
     * Normalize text for comparison
     */
    private static function normalize_text($text) {
        // Convert to lowercase and trim
        $text = strtolower(trim($text));

        // Remove extra whitespace
        $text = preg_replace('/\s+/', ' ', $text);

        // Remove common prefixes/suffixes that don't affect meaning
        $prefixes_to_remove = ['job:', 'position:', 'vacancy:', 'opening:'];
        $suffixes_to_remove = ['job', 'position', 'vacancy', 'opening'];

        foreach ($prefixes_to_remove as $prefix) {
            if (strpos($text, $prefix) === 0) {
                $text = trim(substr($text, strlen($prefix)));
                break;
            }
        }

        foreach ($suffixes_to_remove as $suffix) {
            if (substr($text, -strlen($suffix)) === $suffix) {
                $text = trim(substr($text, 0, -strlen($suffix)));
                break;
            }
        }

        return $text;
    }

    /**
     * Get reasons why items are considered similar
     */
    private static function get_similarity_reasons($job1, $job2) {
        $reasons = [];

        // Check title similarity
        $title_sim = self::calculate_text_similarity(
            self::normalize_text($job1->title ?? ''),
            self::normalize_text($job2->post_title ?? '')
        );
        if ($title_sim >= self::HIGH_SIMILARITY) {
            $reasons[] = 'Similar title';
        }

        // Check company similarity
        $company_sim = self::calculate_text_similarity(
            self::normalize_text($job1->company ?? $job1->companyname ?? ''),
            self::normalize_text(get_post_meta($job2->ID, 'company', true) ?? '')
        );
        if ($company_sim >= self::HIGH_SIMILARITY) {
            $reasons[] = 'Same company';
        }

        // Check location similarity
        $location_sim = self::calculate_text_similarity(
            self::normalize_text($job1->location ?? $job1->city ?? ''),
            self::normalize_text(get_post_meta($job2->ID, 'location', true) ?? '')
        );
        if ($location_sim >= self::MEDIUM_SIMILARITY) {
            $reasons[] = 'Similar location';
        }

        return $reasons;
    }

    /**
     * Determine which strategy was used for matching
     */
    private static function determine_matching_strategy($job1, $job2) {
        // Check GUID match
        if (isset($job1->guid) && !empty($job1->guid)) {
            $existing_guid = get_post_meta($job2->ID, 'guid', true);
            if ($existing_guid === $job1->guid) {
                return self::STRATEGY_GUID;
            }
        }

        // Check title + company combination
        $title_sim = self::calculate_text_similarity(
            self::normalize_text($job1->title ?? ''),
            self::normalize_text($job2->post_title ?? '')
        );
        $company_sim = self::calculate_text_similarity(
            self::normalize_text($job1->company ?? $job1->companyname ?? ''),
            self::normalize_text(get_post_meta($job2->ID, 'company', true) ?? '')
        );

        if ($title_sim >= self::HIGH_SIMILARITY && $company_sim >= self::HIGH_SIMILARITY) {
            return self::STRATEGY_TITLE_COMPANY;
        }

        // Check content hash
        $content_hash = self::generate_content_hash($job1);
        $existing_hash = get_post_meta($job2->ID, '_import_hash', true);
        if ($content_hash === $existing_hash) {
            return self::STRATEGY_CONTENT_HASH;
        }

        return self::STRATEGY_FUZZY_TITLE;
    }

    /**
     * Generate content hash for comparison
     */
    private static function generate_content_hash($job) {
        $content = '';
        $content .= $job->title ?? '';
        $content .= $job->company ?? $job->companyname ?? '';
        $content .= $job->location ?? $job->city ?? '';
        $content .= $job->description ?? '';

        return md5(strtolower(trim($content)));
    }

    /**
     * Get existing jobs for comparison (with caching)
     */
    private static function get_existing_jobs_for_comparison($job_item) {
        $cache_key = 'puntwork_dedup_jobs_' . md5($job_item->title ?? '' . $job_item->company ?? '');
        $cached = wp_cache_get($cache_key);

        if ($cached !== false) {
            return $cached;
        }

        // Query recent jobs that might be duplicates
        $args = [
            'post_type' => 'job',
            'post_status' => ['publish', 'draft'],
            'posts_per_page' => 50,
            'orderby' => 'date',
            'order' => 'DESC',
            'date_query' => [
                'after' => '30 days ago'
            ]
        ];

        // If we have company info, filter by company
        if (!empty($job_item->company ?? $job_item->companyname ?? '')) {
            $args['meta_query'] = [
                [
                    'key' => 'company',
                    'value' => $job_item->company ?? $job_item->companyname ?? '',
                    'compare' => 'LIKE'
                ]
            ];
        }

        $jobs = get_posts($args);
        wp_cache_set($cache_key, $jobs, '', 3600); // Cache for 1 hour

        return $jobs;
    }

    /**
     * Enhanced duplicate handling with advanced algorithms
     */
    public static function handle_duplicates_advanced($batch_guids, $existing_by_guid, &$logs, &$duplicates_drafted, &$post_ids_by_guid) {
        global $wpdb;

        // First, handle exact GUID matches with existing logic
        self::handle_exact_guid_duplicates($batch_guids, $existing_by_guid, $logs, $duplicates_drafted, $post_ids_by_guid);

        // Then, apply fuzzy matching for remaining items
        if (self::$config['enable_fuzzy_matching']) {
            self::handle_fuzzy_duplicates($batch_guids, $logs, $duplicates_drafted, $post_ids_by_guid);
        }
    }

    /**
     * Handle exact GUID duplicates (existing logic)
     */
    private static function handle_exact_guid_duplicates($batch_guids, $existing_by_guid, &$logs, &$duplicates_drafted, &$post_ids_by_guid) {
        global $wpdb;

        foreach ($batch_guids as $guid) {
            if (isset($existing_by_guid[$guid])) {
                $posts_data = $existing_by_guid[$guid];
                if (count($posts_data) > 1) {
                    // Extract post IDs for duplicate processing
                    $post_ids = [];
                    foreach ($posts_data as $item) {
                        if (is_array($item) && isset($item['id'])) {
                            $post_ids[] = $item['id'];
                        } else {
                            $post_ids[] = $item;
                        }
                    }

                    $existing = get_posts([
                        'post_type' => 'job',
                        'post__in' => $post_ids,
                        'posts_per_page' => -1,
                        'post_status' => 'any',
                        'fields' => 'ids',
                    ]) ?: [];

                    $post_to_keep = null;
                    $duplicates_to_draft = [];
                    $hashes = [];

                    foreach ($existing as $post_id) {
                        $hashes[$post_id] = get_post_meta($post_id, '_import_hash', true);
                    }

                    foreach ($existing as $post_id) {
                        if ($post_to_keep === null) {
                            $post_to_keep = $post_id;
                        } else {
                            // If hashes are identical, draft the duplicate
                            if ($hashes[$post_to_keep] === $hashes[$post_id]) {
                                $duplicates_to_draft[] = $post_id;
                            } else {
                                // If hashes differ, keep the most recently modified
                                if (strtotime(get_post_field('post_modified', $post_id)) > strtotime(get_post_field('post_modified', $post_to_keep))) {
                                    $duplicates_to_draft[] = $post_to_keep;
                                    $post_to_keep = $post_id;
                                } else {
                                    $duplicates_to_draft[] = $post_id;
                                }
                            }
                        }
                    }

                    // Draft duplicates
                    foreach ($duplicates_to_draft as $dup_id) {
                        $current_title = get_post_field('post_title', $dup_id);
                        $reason = $hashes[$dup_id] === $hashes[$post_to_keep] ? 'Identical content' : 'Older version kept';
                        $new_title = strpos($current_title, 'Duplicate - ') === false ?
                            $current_title . ' [Duplicate - ' . $reason . ']' : $current_title;

                        wp_update_post([
                            'ID' => $dup_id,
                            'post_title' => $new_title,
                            'post_status' => 'draft'
                        ]);

                        $duplicates_drafted++;
                        $logs[] = '[' . date('d-M-Y H:i:s') . ' UTC] ' . 'Drafted duplicate ID: ' . $dup_id . ' GUID: ' . $guid . ' - ' . $reason;
                    }

                    $post_ids_by_guid[$guid] = $post_to_keep;
                } else {
                    // Single existing post for this GUID
                    $first_item = $posts_data[0];
                    $post_ids_by_guid[$guid] = is_array($first_item) && isset($first_item['id']) ? $first_item['id'] : $first_item;
                }
            }
        }
    }

    /**
     * Handle fuzzy duplicates using advanced algorithms
     */
    private static function handle_fuzzy_duplicates($batch_guids, &$logs, &$duplicates_drafted, &$post_ids_by_guid) {
        // Get all jobs in current batch that don't have exact GUID matches
        $batch_jobs = [];
        foreach ($batch_guids as $guid) {
            if (!isset($post_ids_by_guid[$guid])) {
                // This GUID doesn't have an exact match, get the job data
                $batch_jobs[$guid] = self::get_job_data_by_guid($guid);
            }
        }

        // Check each batch job against existing jobs
        foreach ($batch_jobs as $guid => $job_data) {
            if (!$job_data) continue;

            $duplicates = self::find_duplicates($job_data);

            if (!empty($duplicates)) {
                $best_match = $duplicates[0];

                if ($best_match['similarity'] >= self::$config['title_similarity_threshold']) {
                    // Mark this as a duplicate of the existing job
                    $post_ids_by_guid[$guid] = $best_match['post_id'];

                    $reasons = implode(', ', $best_match['reasons']);
                    $logs[] = '[' . date('d-M-Y H:i:s') . ' UTC] ' . sprintf(
                        'Fuzzy duplicate detected: GUID %s matches existing job ID %d (similarity: %.2f) - %s',
                        $guid,
                        $best_match['post_id'],
                        $best_match['similarity'],
                        $reasons
                    );
                }
            }
        }
    }

    /**
     * Get job data by GUID from current import batch
     */
    private static function get_job_data_by_guid($guid) {
        // This would need to be implemented to get job data from the current batch
        // For now, return null - this would be integrated with the import process
        return null;
    }

    /**
     * Update deduplication configuration
     */
    public static function update_config($new_config) {
        self::$config = array_merge(self::$config, $new_config);
    }

    /**
     * Get current configuration
     */
    public static function get_config() {
        return self::$config;
    }
}