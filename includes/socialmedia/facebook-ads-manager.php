<?php

/**
 * Facebook Ads Integration
 *
 * @package    Puntwork
 * @subpackage SocialMedia
 * @since      2.2.1
 */

namespace Puntwork\SocialMedia;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Facebook Ads Manager
 */
class FacebookAdsManager
{
    /**
     * Facebook Platform instance
     */
    private FacebookPlatform $facebook_platform;

    /**
     * Ads API base URL
     */
    private string $ads_api_base = 'https://graph.facebook.com/v18.0';

    /**
     * Constructor
     */
    public function __construct(FacebookPlatform $facebook_platform)
    {
        $this->facebook_platform = $facebook_platform;
    }

    /**
     * Create a Facebook Ads campaign
     */
    public function createCampaign(array $campaign_data): array
    {
        $required_fields = ['name', 'account_id', 'objective', 'budget', 'start_time', 'end_time'];
        foreach ($required_fields as $field) {
            if (!isset($campaign_data[$field])) {
                throw new \Exception("Missing required field: {$field}");
            }
        }

        $params = [
            'name' => $campaign_data['name'],
            'account_id' => $campaign_data['account_id'],
            'objective' => $campaign_data['objective'], // CONVERSIONS, TRAFFIC, ENGAGEMENT, etc.
            'daily_budget' => $this->convertToCents($campaign_data['budget']),
            'start_time' => $campaign_data['start_time'],
            'end_time' => $campaign_data['end_time'],
            'status' => $campaign_data['status'] ?? 'PAUSED',
            'special_ad_categories' => ['EMPLOYMENT'] // Important for job ads
        ];

        $response = $this->makeAdsApiRequest('act_' . $campaign_data['account_id'] . '/campaigns', $params, 'POST');

        return [
            'success' => true,
            'campaign_id' => $response['id'] ?? null,
            'data' => $response
        ];
    }

    /**
     * Create an ad set
     */
    public function createAdSet(array $adset_data): array
    {
        $required_fields = ['name', 'campaign_id', 'account_id', 'budget', 'targeting'];
        foreach ($required_fields as $field) {
            if (!isset($adset_data[$field])) {
                throw new \Exception("Missing required field: {$field}");
            }
        }

        $params = [
            'name' => $adset_data['name'],
            'campaign_id' => $adset_data['campaign_id'],
            'daily_budget' => $this->convertToCents($adset_data['budget']),
            'start_time' => $adset_data['start_time'] ?? date('c', strtotime('+1 hour')),
            'end_time' => $adset_data['end_time'] ?? date('c', strtotime('+7 days')),
            'targeting' => $this->formatTargeting($adset_data['targeting']),
            'status' => $adset_data['status'] ?? 'PAUSED',
            'billing_event' => 'IMPRESSIONS',
            'optimization_goal' => $adset_data['optimization_goal'] ?? 'REACH'
        ];

        $response = $this->makeAdsApiRequest('act_' . $adset_data['account_id'] . '/adsets', $params, 'POST');

        return [
            'success' => true,
            'adset_id' => $response['id'] ?? null,
            'data' => $response
        ];
    }

    /**
     * Create an ad creative
     */
    public function createAdCreative(array $creative_data): array
    {
        $required_fields = ['account_id', 'page_id'];
        foreach ($required_fields as $field) {
            if (!isset($creative_data[$field])) {
                throw new \Exception("Missing required field: {$field}");
            }
        }

        $params = [
            'name' => $creative_data['name'] ?? 'Job Post Creative',
            'account_id' => $creative_data['account_id'],
            'page_id' => $creative_data['page_id'],
            'title' => $creative_data['title'] ?? '',
            'body' => $creative_data['body'] ?? '',
            'link_url' => $creative_data['link_url'] ?? '',
            'image_url' => $creative_data['image_url'] ?? ''
        ];

        // Remove empty fields
        $params = array_filter($params, function ($value) {
            return !empty($value);
        });

        $response = $this->makeAdsApiRequest('act_' . $creative_data['account_id'] . '/adcreatives', $params, 'POST');

        return [
            'success' => true,
            'creative_id' => $response['id'] ?? null,
            'data' => $response
        ];
    }

    /**
     * Create an ad
     */
    public function createAd(array $ad_data): array
    {
        $required_fields = ['name', 'adset_id', 'creative_id', 'account_id'];
        foreach ($required_fields as $field) {
            if (!isset($ad_data[$field])) {
                throw new \Exception("Missing required field: {$field}");
            }
        }

        $params = [
            'name' => $ad_data['name'],
            'adset_id' => $ad_data['adset_id'],
            'creative' => ['creative_id' => $ad_data['creative_id']],
            'status' => $ad_data['status'] ?? 'PAUSED'
        ];

        $response = $this->makeAdsApiRequest('act_' . $ad_data['account_id'] . '/ads', $params, 'POST');

        return [
            'success' => true,
            'ad_id' => $response['id'] ?? null,
            'data' => $response
        ];
    }

    /**
     * Post job with ads campaign
     */
    public function postJobWithAds(array $job_data, array $ads_config): array
    {
        // First create the campaign
        $campaign_result = $this->createCampaign($ads_config['campaign']);

        if (!$campaign_result['success']) {
            throw new \Exception('Failed to create campaign: ' . ($campaign_result['error'] ?? 'Unknown error'));
        }

        // Create ad set
        $adset_config = array_merge($ads_config['adset'] ?? [], [
            'campaign_id' => $campaign_result['campaign_id'],
            'account_id' => $ads_config['account_id'],
            'budget' => $ads_config['campaign']['budget'],
            'targeting' => $ads_config['targeting'] ?? []
        ]);

        $adset_result = $this->createAdSet($adset_config);

        if (!$adset_result['success']) {
            throw new \Exception('Failed to create ad set: ' . ($adset_result['error'] ?? 'Unknown error'));
        }

        // Create ad creative
        $creative_config = array_merge($ads_config['creative'] ?? [], [
            'account_id' => $ads_config['account_id'],
            'page_id' => $this->facebook_platform->getCredentials()['page_id'],
            'title' => $this->createJobAdTitle($job_data),
            'body' => $this->createJobAdText($job_data),
            'link_url' => $job_data['url'] ?? '',
            'image_url' => $job_data['company_logo'] ?? ''
        ]);

        $creative_result = $this->createAdCreative($creative_config);

        if (!$creative_result['success']) {
            throw new \Exception('Failed to create ad creative: ' . ($creative_result['error'] ?? 'Unknown error'));
        }

        // Create the ad
        $ad_config = [
            'name' => 'Job: ' . ($job_data['title'] ?? 'Position Available'),
            'adset_id' => $adset_result['adset_id'],
            'creative_id' => $creative_result['creative_id'],
            'account_id' => $ads_config['account_id']
        ];

        $ad_result = $this->createAd($ad_config);

        if (!$ad_result['success']) {
            throw new \Exception('Failed to create ad: ' . ($ad_result['error'] ?? 'Unknown error'));
        }

        return [
            'success' => true,
            'campaign_id' => $campaign_result['campaign_id'],
            'adset_id' => $adset_result['adset_id'],
            'creative_id' => $creative_result['creative_id'],
            'ad_id' => $ad_result['ad_id'],
            'total_cost' => $ads_config['campaign']['budget']
        ];
    }

    /**
     * Create compelling ad title for job posting
     */
    private function createJobAdTitle(array $job_data): string
    {
        $title = $job_data['title'] ?? '';
        $company = $job_data['company'] ?? '';

        if (strlen($title) > 40) {
            $title = substr($title, 0, 37) . '...';
        }

        return "Job Opening: {$title} at {$company}";
    }

    /**
     * Create compelling ad text for job posting
     */
    private function createJobAdText(array $job_data): string
    {
        $title = $job_data['title'] ?? '';
        $company = $job_data['company'] ?? '';
        $location = $job_data['location'] ?? '';
        $salary = $job_data['salary'] ?? '';

        $text = "🚀 HOT JOB OPPORTUNITY! 🚀\n\n";
        $text .= "Position: {$title}\n";
        $text .= "Company: {$company}\n";

        if ($location) {
            $text .= "Location: {$location}\n";
        }

        if ($salary) {
            $text .= "Salary: {$salary}\n";
        }

        $text .= "\nApply now and take the next step in your career!\n\n";
        $text .= "#JobOpening #Hiring #CareerOpportunity";

        return $text;
    }

    /**
     * Format targeting for Facebook API
     */
    private function formatTargeting(array $targeting): array
    {
        $formatted = [];

        // Age targeting
        if (!empty($targeting['age_min']) || !empty($targeting['age_max'])) {
            $formatted['age_min'] = $targeting['age_min'] ?? 18;
            $formatted['age_max'] = $targeting['age_max'] ?? 65;
        }

        // Gender targeting
        if (!empty($targeting['genders'])) {
            $formatted['genders'] = $targeting['genders']; // [1=male, 2=female]
        }

        // Location targeting
        if (!empty($targeting['geo_locations'])) {
            $formatted['geo_locations'] = $targeting['geo_locations'];
        }

        // Interest targeting
        if (!empty($targeting['interests'])) {
            $formatted['interests'] = array_map(function ($interest) {
                return ['id' => $interest, 'name' => $interest];
            }, $targeting['interests']);
        }

        // Job title targeting
        if (!empty($targeting['job_titles'])) {
            $formatted['job_titles'] = $targeting['job_titles'];
        }

        return $formatted;
    }

    /**
     * Get campaign performance metrics
     */
    public function getCampaignMetrics(string $campaign_id, string $account_id): array
    {
        $response = $this->makeAdsApiRequest(
            'act_' . $account_id . '/campaigns',
            [
                'fields' => 'id,name,status,insights{impressions,clicks,spend,reach,actions}',
                'filtering' => [['field' => 'campaign.id', 'operator' => 'EQUAL', 'value' => $campaign_id]]
            ],
            'GET'
        );

        $campaign = $response['data'][0] ?? [];

        return [
            'success' => true,
            'campaign_id' => $campaign_id,
            'name' => $campaign['name'] ?? '',
            'status' => $campaign['status'] ?? '',
            'insights' => $campaign['insights'] ?? [],
            'impressions' => $campaign['insights']['data'][0]['impressions'] ?? 0,
            'clicks' => $campaign['insights']['data'][0]['clicks'] ?? 0,
            'spend' => $campaign['insights']['data'][0]['spend'] ?? 0,
            'reach' => $campaign['insights']['data'][0]['reach'] ?? 0
        ];
    }

    /**
     * Convert currency to cents
     */
    private function convertToCents(float $amount): int
    {
        return (int)($amount * 100);
    }

    /**
     * Make Ads API request
     */
    private function makeAdsApiRequest(string $endpoint, array $params, string $method)
    {
        $url = $this->ads_api_base . '/' . $endpoint;

        $args = [
            'method' => $method,
            'headers' => [
                'Authorization' => 'Bearer ' . ($this->facebook_platform->getCredentials()['access_token'] ?? ''),
                'Content-Type' => 'application/json'
            ],
            'timeout' => 30
        ];

        if ($method === 'GET') {
            $url .= '?' . http_build_query($params);
        } else {
            $args['body'] = json_encode($params);
        }

        $response = wp_remote_request($url, $args);

        if (is_wp_error($response)) {
            throw new \Exception('Ads API request failed: ' . $response->get_error_message());
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);

        if (isset($body['error'])) {
            throw new \Exception('Facebook Ads API error: ' . $body['error']['message']);
        }

        return $body;
    }
}
