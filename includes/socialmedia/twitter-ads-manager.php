<?php

/**
 * Twitter/X Ads Integration
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
 * Twitter/X Ads Manager
 */
class TwitterAdsManager
{

    /**
     * Twitter Platform instance
     */
    private TwitterPlatform $twitter_platform;

    /**
     * Ads API base URL
     */
    private string $ads_api_base = 'https://ads-api.twitter.com/12';

    /**
     * Constructor
     */
    public function __construct( TwitterPlatform $twitter_platform )
    {
        $this->twitter_platform = $twitter_platform;
    }

    /**
     * Create a Twitter Ads campaign
     */
    public function createCampaign( array $campaign_data ): array
    {
        $required_fields = array( 'name', 'account_id', 'objective', 'budget', 'start_time', 'end_time' );
        foreach ( $required_fields as $field ) {
            if (! isset($campaign_data[ $field ]) ) {
                throw new \Exception("Missing required field: {$field}");
            }
        }

        $params = array(
        'name'                            => $campaign_data['name'],
        'account_id'                      => $campaign_data['account_id'],
        'objective'                       => $campaign_data['objective'], // AWARENESS, ENGAGEMENT, etc.
        'daily_budget_amount_local_micro' => $this->convertToMicro($campaign_data['budget']),
        'start_time'                      => $campaign_data['start_time'],
        'end_time'                        => $campaign_data['end_time'],
        'status'                          => $campaign_data['status'] ?? 'PAUSED',
        );

        $response = $this->makeAdsApiRequest('accounts/' . $campaign_data['account_id'] . '/campaigns', $params, 'POST');

        return array(
        'success'     => true,
        'campaign_id' => $response['data']['id'] ?? null,
        'data'        => $response['data'] ?? array(),
        );
    }

    /**
     * Create a promoted tweet
     */
    public function createPromotedTweet( string $tweet_id, array $options ): array
    {
        $params = array(
        'tweet_id' => $tweet_id,
        'paused'   => $options['paused'] ?? false,
        );

        $response = $this->makeAdsApiRequest('accounts/' . $options['account_id'] . '/promoted_tweets', $params, 'POST');

        return array(
        'success'           => true,
        'promoted_tweet_id' => $response['data']['id'] ?? null,
        'data'              => $response['data'] ?? array(),
        );
    }

    /**
     * Create targeting criteria for ads
     */
    public function createTargeting( array $targeting_data ): array
    {
        $params = array();

        // Location targeting
        if (! empty($targeting_data['locations']) ) {
            $params['location'] = $targeting_data['locations'];
        }

        // Interest targeting
        if (! empty($targeting_data['interests']) ) {
            $params['interest'] = $targeting_data['interests'];
        }

        // Keyword targeting
        if (! empty($targeting_data['keywords']) ) {
            $params['phrase_keyword'] = array_map(
                function ( $keyword ) {
                    return array( 'phrase' => $keyword );
                },
                $targeting_data['keywords']
            );
        }

        // Age targeting
        if (! empty($targeting_data['age_min']) || ! empty($targeting_data['age_max']) ) {
            $params['age'] = array(
            'min' => $targeting_data['age_min'] ?? 18,
            'max' => $targeting_data['age_max'] ?? 65,
            );
        }

        $response = $this->makeAdsApiRequest('accounts/' . $targeting_data['account_id'] . '/targeting_criteria', $params, 'POST');

        return array(
        'success'       => true,
        'targeting_ids' => array_column($response['data'] ?? array(), 'id'),
        'data'          => $response['data'] ?? array(),
        );
    }

    /**
     * Post job with ads campaign
     */
    public function postJobWithAds( array $job_data, array $ads_config ): array
    {
        // First post the tweet
        $tweet_result = $this->twitter_platform->post(
            array(
            'text'  => $this->createJobAdText($job_data),
            'media' => $job_data['company_logo'] ?? array(),
            )
        );

        if (! $tweet_result['success'] ) {
            return $tweet_result;
        }

        $tweet_id = $tweet_result['post_id'];

        try {
            // Create campaign
            $campaign_result = $this->createCampaign($ads_config['campaign']);

            // Create promoted tweet
            $promoted_result = $this->createPromotedTweet(
                $tweet_id,
                array(
                'account_id' => $ads_config['account_id'],
                )
            );

            // Create targeting
            $targeting_result = $this->createTargeting(
                array_merge(
                    $ads_config['targeting'],
                    array( 'account_id' => $ads_config['account_id'] )
                )
            );

            return array(
            'success'           => true,
            'tweet_id'          => $tweet_id,
            'campaign_id'       => $campaign_result['campaign_id'],
            'promoted_tweet_id' => $promoted_result['promoted_tweet_id'],
            'targeting_ids'     => $targeting_result['targeting_ids'],
            'total_cost'        => $ads_config['campaign']['budget'],
            );
        } catch ( \Exception $e ) {
            // If ads creation fails, the tweet still exists
            PuntWorkLogger::error(
                'Twitter ads creation failed',
                PuntWorkLogger::CONTEXT_SOCIAL,
                array(
                'tweet_id' => $tweet_id,
                'error'    => $e->getMessage(),
                )
            );

            return array(
            'success'  => false,
            'error'    => 'Tweet posted but ads creation failed: ' . $e->getMessage(),
            'tweet_id' => $tweet_id,
            );
        }
    }

    /**
     * Create compelling ad text for job posting
     */
    private function createJobAdText( array $job_data ): string
    {
        $title    = $job_data['title'] ?? '';
        $company  = $job_data['company'] ?? '';
        $location = $job_data['location'] ?? '';
        $salary   = $job_data['salary'] ?? '';
        $url      = $job_data['url'] ?? '';

        $text  = "🚀 HOT JOB ALERT! 🚀\n\n";
        $text .= "💼 {$title}\n";
        $text .= "🏢 {$company}\n";

        if ($location ) {
            $text .= "📍 {$location}\n";
        }

        if ($salary ) {
            $text .= "💰 {$salary}\n";
        }

        $text .= "\nApply NOW: {$url}\n\n";
        $text .= '#JobOpening #Hiring #Career';

        return $text;
    }

    /**
     * Get campaign performance metrics
     */
    public function getCampaignMetrics( string $campaign_id, string $account_id ): array
    {
        $response = $this->makeAdsApiRequest(
            "accounts/{$account_id}/campaigns/{$campaign_id}/stats",
            array( 'granularity' => 'DAY' ),
            'GET'
        );

        return array(
        'success'     => true,
        'metrics'     => $response['data'] ?? array(),
        'impressions' => $response['data']['impressions'] ?? 0,
        'clicks'      => $response['data']['clicks'] ?? 0,
        'spend'       => $response['data']['spend'] ?? 0,
        );
    }

    /**
     * Convert currency to micro units
     */
    private function convertToMicro( float $amount ): int
    {
        return (int) ( $amount * 1000000 );
    }

    /**
     * Make Ads API request
     */
    private function makeAdsApiRequest( string $endpoint, array $params, string $method )
    {
        $url = $this->ads_api_base . '/' . $endpoint;

        // Use the same authentication as the main Twitter platform
        $args = array(
        'method'  => $method,
        'headers' => array(
        'Authorization' => 'Bearer ' . ( $this->twitter_platform->getCredentials()['bearer_token'] ?? '' ),
        'Content-Type'  => 'application/json',
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
            throw new \Exception('Ads API request failed: ' . $response->get_error_message());
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);

        if (wp_remote_retrieve_response_code($response) >= 400 ) {
            throw new \Exception('Ads API error: ' . ( $body['errors'][0]['message'] ?? 'Unknown error' ));
        }

        return $body;
    }
}
