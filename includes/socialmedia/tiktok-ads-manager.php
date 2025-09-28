<?php

/**
 * TikTok Ads Manager
 *
 * @package    Puntwork
 * @subpackage SocialMedia
 * @since      2.2.1
 */

namespace Puntwork\SocialMedia;

// Prevent direct access
if (! defined('ABSPATH') ) {
    exit;
}

/**
 * TikTok Ads API integration
 */
class TikTokAdsManager
{

    /**
     * Parent platform instance
     */
    private TikTokPlatform $platform;

    /**
     * Ads API base URL
     */
    private string $ads_api_base = 'https://business-api.tiktok.com/open_api/v1.3';

    /**
     * Constructor
     */
    public function __construct( TikTokPlatform $platform )
    {
        $this->platform = $platform;
    }

    /**
     * Create ads campaign for job posting
     */
    public function postJobWithAds( array $post_data, array $ads_config ): array
    {
        try {
            // Create campaign
            $campaign = $this->createCampaign($ads_config);

            // Create ad group
            $ad_group = $this->createAdGroup($campaign['campaign_id'], $ads_config);

            // Create ad creative
            $creative = $this->createAdCreative($ad_group['adgroup_id'], $post_data, $ads_config);

            // Create ad
            $ad = $this->createAd($ad_group['adgroup_id'], $creative['creative_id'], $ads_config);

            return array(
            'success'     => true,
            'campaign_id' => $campaign['campaign_id'],
            'ad_group_id' => $ad_group['adgroup_id'],
            'creative_id' => $creative['creative_id'],
            'ad_id'       => $ad['ad_id'],
            'platform'    => 'tiktok',
            'timestamp'   => time(),
            );
        } catch ( \Exception $e ) {
            PuntWorkLogger::error(
                'TikTok ads campaign creation failed',
                PuntWorkLogger::CONTEXT_SOCIAL,
                array(
                'error'      => $e->getMessage(),
                'ads_config' => $ads_config,
                )
            );

            return array(
            'success'   => false,
            'error'     => $e->getMessage(),
            'platform'  => 'tiktok',
            'timestamp' => time(),
            );
        }
    }

    /**
     * Create campaign
     */
    private function createCampaign( array $ads_config ): array
    {
        $campaign_data = array(
        'advertiser_id'    => $this->platform->getCredentials()['advertiser_id'],
        'campaign_name'    => $ads_config['campaign_name'] ?? 'Job Posting Campaign - ' . date('Y-m-d H:i:s'),
        'objective_type'   => $ads_config['objective'] ?? 'REACH', // REACH, TRAFFIC, APP_INSTALLS, LEAD_GENERATION, VIDEO_VIEWS
        'budget_mode'      => $ads_config['budget_mode'] ?? 'BUDGET_MODE_DAY', // BUDGET_MODE_DAY, BUDGET_MODE_TOTAL
        'budget'           => $ads_config['budget'] ?? 50.00, // Daily budget in USD
        'operation_status' => 'ENABLE',
        );

        $response = $this->makeAdsApiRequest('campaign/create/', $campaign_data, 'POST');

        return array(
        'campaign_id' => $response['data']['campaign_id'],
        );
    }

    /**
     * Create ad group
     */
    private function createAdGroup( string $campaign_id, array $ads_config ): array
    {
        $ad_group_data = array(
        'advertiser_id'     => $this->platform->getCredentials()['advertiser_id'],
        'campaign_id'       => $campaign_id,
        'adgroup_name'      => $ads_config['ad_group_name'] ?? 'Job Posting Ad Group - ' . date('Y-m-d H:i:s'),
        'placement_type'    => $ads_config['placement_type'] ?? 'PLACEMENT_TYPE_AUTOMATIC',
        'optimization_goal' => $ads_config['optimization_goal'] ?? 'REACH', // REACH, CLICK, IMPRESSION, APP_INSTALL, LEAD_GENERATION
        'billing_event'     => $ads_config['billing_event'] ?? 'CPC', // CPC, CPM, CPA
        'bid_price'         => $ads_config['bid_price'] ?? 0.50,
        'targeting'         => $this->buildTargeting($ads_config['targeting'] ?? array()),
        );

        $response = $this->makeAdsApiRequest('adgroup/create/', $ad_group_data, 'POST');

        return array(
        'adgroup_id' => $response['data']['adgroup_id'],
        );
    }

    /**
     * Create ad creative
     */
    private function createAdCreative( string $adgroup_id, array $post_data, array $ads_config ): array
    {
        $creative_data = array(
        'advertiser_id'  => $this->platform->getCredentials()['advertiser_id'],
        'adgroup_id'     => $adgroup_id,
        'creative_name'  => $ads_config['creative_name'] ?? 'Job Posting Creative - ' . date('Y-m-d H:i:s'),
        'call_to_action' => $ads_config['call_to_action'] ?? 'LEARN_MORE',
        'creative_type'  => 'CREATIVE_TYPE_VIDEO',
        'video_id'       => $post_data['video_id'] ?? '',
        'text'           => $ads_config['ad_text'] ?? 'Check out this job opportunity!',
        'headline'       => $ads_config['headline'] ?? 'New Job Opening',
        'description'    => $ads_config['description'] ?? 'Apply now for this exciting opportunity.',
        );

        $response = $this->makeAdsApiRequest('creative/create/', $creative_data, 'POST');

        return array(
        'creative_id' => $response['data']['creative_id'],
        );
    }

    /**
     * Create ad
     */
    private function createAd( string $adgroup_id, string $creative_id, array $ads_config ): array
    {
        $ad_data = array(
        'advertiser_id' => $this->platform->getCredentials()['advertiser_id'],
        'adgroup_id'    => $adgroup_id,
        'creative_id'   => $creative_id,
        'ad_name'       => $ads_config['ad_name'] ?? 'Job Posting Ad - ' . date('Y-m-d H:i:s'),
        'status'        => 'AD_STATUS_DELIVERING',
        );

        $response = $this->makeAdsApiRequest('ad/create/', $ad_data, 'POST');

        return array(
        'ad_id' => $response['data']['ad_id'],
        );
    }

    /**
     * Build targeting parameters
     */
    private function buildTargeting( array $targeting_config ): array
    {
        $targeting = array(
        'age'               => $targeting_config['age'] ?? array( '18', '65' ), // Age range
        'gender'            => $targeting_config['gender'] ?? 'GENDER_UNLIMITED', // GENDER_UNLIMITED, GENDER_MALE, GENDER_FEMALE
        'location'          => $targeting_config['location'] ?? array(), // Array of location codes
        'interest_category' => $targeting_config['interests'] ?? array(), // Array of interest categories
        'device_platform'   => $targeting_config['platforms'] ?? array( 'ANDROID', 'IOS' ), // Device platforms
        'operating_system'  => $targeting_config['os'] ?? array(), // Operating systems
        );

        return $targeting;
    }

    /**
     * Get campaign metrics
     */
    public function getCampaignMetrics( string $campaign_id, string $advertiser_id ): array
    {
        $params = array(
        'advertiser_id' => $advertiser_id,
        'campaign_ids'  => array( $campaign_id ),
        'metrics'       => array(
        'impressions',
        'clicks',
        'spend',
        'reach',
        'video_views',
        'video_completions',
        'conversions',
        ),
        'data_level'    => 'AUCTION_CAMPAIGN',
        'start_date'    => date('Y-m-d', strtotime('-30 days')),
        'end_date'      => date('Y-m-d'),
        );

        $response = $this->makeAdsApiRequest('report/campaign/get/', $params, 'GET');

        if (isset($response['data']['list'][0]) ) {
            return $response['data']['list'][0]['metrics'];
        }

        return array();
    }

    /**
     * Get ad group metrics
     */
    public function getAdGroupMetrics( string $adgroup_id, string $advertiser_id ): array
    {
        $params = array(
        'advertiser_id' => $advertiser_id,
        'adgroup_ids'   => array( $adgroup_id ),
        'metrics'       => array(
        'impressions',
        'clicks',
        'spend',
        'reach',
        'video_views',
        'video_completions',
        'conversions',
        ),
        'data_level'    => 'AUCTION_ADGROUP',
        'start_date'    => date('Y-m-d', strtotime('-30 days')),
        'end_date'      => date('Y-m-d'),
        );

        $response = $this->makeAdsApiRequest('report/adgroup/get/', $params, 'GET');

        if (isset($response['data']['list'][0]) ) {
            return $response['data']['list'][0]['metrics'];
        }

        return array();
    }

    /**
     * Pause campaign
     */
    public function pauseCampaign( string $campaign_id, string $advertiser_id ): bool
    {
        $data = array(
        'advertiser_id'    => $advertiser_id,
        'campaign_id'      => $campaign_id,
        'operation_status' => 'DISABLE',
        );

        $response = $this->makeAdsApiRequest('campaign/update/status/', $data, 'POST');
        return isset($response['code']) && $response['code'] === 0;
    }

    /**
     * Resume campaign
     */
    public function resumeCampaign( string $campaign_id, string $advertiser_id ): bool
    {
        $data = array(
        'advertiser_id'    => $advertiser_id,
        'campaign_id'      => $campaign_id,
        'operation_status' => 'ENABLE',
        );

        $response = $this->makeAdsApiRequest('campaign/update/status/', $data, 'POST');
        return isset($response['code']) && $response['code'] === 0;
    }

    /**
     * Update campaign budget
     */
    public function updateCampaignBudget( string $campaign_id, string $advertiser_id, float $budget ): bool
    {
        $data = array(
        'advertiser_id' => $advertiser_id,
        'campaign_id'   => $campaign_id,
        'budget'        => $budget,
        );

        $response = $this->makeAdsApiRequest('campaign/update/budget/', $data, 'POST');
        return isset($response['code']) && $response['code'] === 0;
    }

    /**
     * Make TikTok Ads API request
     */
    private function makeAdsApiRequest( string $endpoint, array $params, string $method )
    {
        $url = $this->ads_api_base . '/' . $endpoint;

        $args = array(
        'method'  => $method,
        'headers' => array(
        'Access-Token' => $this->platform->getCredentials()['access_token'],
        'Content-Type' => 'application/json',
        ),
        'timeout' => 30,
        );

        if ($method === 'GET' ) {
            $url .= '?' . http_build_query($params);
        } else {
            $args['body'] = json_encode($params);
        }

        $response = wp_remote_request($url, $args);

        if (is_wp_error($response) ) {
            throw new \Exception('TikTok Ads API request failed: ' . $response->get_error_message());
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if (json_last_error() !== JSON_ERROR_NONE ) {
            throw new \Exception('Invalid JSON response from TikTok Ads API');
        }

        if (isset($data['code']) && $data['code'] !== 0 ) {
            $message = $data['message'] ?? 'Unknown error';
            throw new \Exception('TikTok Ads API Error: ' . $message);
        }

        return $data;
    }
}
