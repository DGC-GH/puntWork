<?php
/**
 * Plugin Name: puntWork Job Import
 * Plugin URI: https://github.com/DGC-GH/puntWork
 * Description: Imports job listings from RSS/XML feeds stored in custom post types.
 * Version: 1.0.0
 * Author: DGC-GH
 * License: GPL v2 or later
 * Text Domain: puntwork
 * Domain Path: /languages
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Define constants.
define( 'PUNTWORK_VERSION', '1.0.0' );
define( 'PUNTWORK_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'PUNTWORK_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'PUNTWORK_LOGS_DIR', PUNTWORK_PLUGIN_DIR . 'logs/' );
define( 'PUNTWORK_JOBS_CPT', 'job' );
define( 'PUNTWORK_FEEDS_CPT', 'job-feed' );

// Ensure logs directory exists.
if ( ! file_exists( PUNTWORK_LOGS_DIR ) ) {
    wp_mkdir_p( PUNTWORK_LOGS_DIR );
}

/**
 * Main Plugin Class
 */
class Puntwork_JobImport {

    /**
     * Constructor.
     */
    public function __construct() {
        add_action( 'init', array( $this, 'init' ) );
        register_activation_hook( __FILE__, array( $this, 'activate' ) );
        register_deactivation_hook( __FILE__, array( $this, 'deactivate' ) );
        register_uninstall_hook( __FILE__, array( __CLASS__, 'uninstall' ) );

        // Include admin if in admin.
        if ( is_admin() ) {
            require_once PUNTWORK_PLUGIN_DIR . 'includes/admin.php';
        }
    }

    /**
     * Activation hook.
     */
    public function activate() {
        $this->register_cpt();
        flush_rewrite_rules();
    }

    /**
     * Deactivation hook.
     */
    public function deactivate() {
        flush_rewrite_rules();
    }

    /**
     * Uninstall hook (static for class).
     */
    public static function uninstall() {
        $jobs = get_posts( array(
            'post_type'   => PUNTWORK_JOBS_CPT,
            'post_status' => 'any',
            'numberposts' => -1,
        ) );
        foreach ( $jobs as $job ) {
            wp_delete_post( $job->ID, true );
        }
        delete_option( 'puntwork_options' );
        flush_rewrite_rules();
    }

    /**
     * Register Job CPT.
     */
    private function register_cpt() {
        $labels = array(
            'name'                  => _x( 'Jobs', 'Post type general name', 'puntwork' ),
            'singular_name'         => _x( 'Job', 'Post type singular name', 'puntwork' ),
            'menu_name'             => _x( 'Jobs', 'Admin Menu text', 'puntwork' ),
            'name_admin_bar'        => _x( 'Job', 'Add New on Toolbar', 'puntwork' ),
            'add_new'               => __( 'Add New', 'puntwork' ),
            'add_new_item'          => __( 'Add New Job', 'puntwork' ),
            'new_item'              => __( 'New Job', 'puntwork' ),
            'edit_item'             => __( 'Edit Job', 'puntwork' ),
            'view_item'             => __( 'View Job', 'puntwork' ),
            'all_items'             => __( 'All Jobs', 'puntwork' ),
            'search_items'          => __( 'Search Jobs', 'puntwork' ),
            'parent_item_colon'     => __( 'Parent Jobs:', 'puntwork' ),
            'not_found'             => __( 'No jobs found.', 'puntwork' ),
            'not_found_in_trash'    => __( 'No jobs found in Trash.', 'puntwork' ),
        );

        $args = array(
            'labels'                => $labels,
            'description'           => __( 'Job listings imported from feeds.', 'puntwork' ),
            'public'                => true,
            'publicly_queryable'    => true,
            'show_ui'               => true,
            'show_in_menu'          => true,
            'query_var'             => true,
            'rewrite'               => array( 'slug' => 'jobs' ),
            'capability_type'       => 'post',
            'has_archive'           => true,
            'hierarchical'          => false,
            'menu_position'         => null,
            'supports'              => array( 'title', 'editor', 'excerpt', 'custom-fields' ),
            'show_in_rest'          => true,
        );

        register_post_type( PUNTWORK_JOBS_CPT, $args );

        // Register Feed CPT if not exists (basic setup; expand in admin snippet).
        register_post_type( PUNTWORK_FEEDS_CPT, array(
            'labels' => array(
                'name' => 'Job Feeds',
                'singular_name' => 'Job Feed',
            ),
            'public' => false,
            'show_ui' => true,
            'show_in_menu' => 'edit.php?post_type=' . PUNTWORK_JOBS_CPT,
            'supports' => array( 'title' ),
        ) );
    }

    /**
     * Init hook: Core import logic.
     */
    public function init() {
        // Fetch feeds dynamically from job_feed CPT.
        $feed_posts = get_posts( array(
            'post_type'      => PUNTWORK_FEEDS_CPT,
            'posts_per_page' => -1,
            'post_status'    => 'publish',
            'fields'         => 'ids',
        ) );

        if ( empty( $feed_posts ) ) {
            $this->log( 'No job_feed posts found. Create some to add URLs.' );
            return;
        }

        foreach ( $feed_posts as $feed_id ) {
            $feed_url = get_post_meta( $feed_id, '_feed_url', true );
            if ( empty( $feed_url ) ) {
                $this->log( "No URL in meta for feed ID $feed_id." );
                continue;
            }

            // Cache check: Skip if recent.
            $cache_key = 'puntwork_feed_' . md5( $feed_url );
            $cached = get_transient( $cache_key );
            if ( $cached ) {
                $this->log( "Feed $feed_url cached recently." );
                continue;
            }

            // Download feed.
            $response = wp_remote_get( $feed_url, array(
                'timeout' => 30,
                'user-agent' => 'puntWork/' . PUNTWORK_VERSION,
            ) );

            if ( is_wp_error( $response ) ) {
                $this->log( 'Download error for ' . $feed_url . ': ' . $response->get_error_message() );
                continue;
            }

            $body = wp_remote_retrieve_body( $response );
            if ( empty( $body ) ) {
                $this->log( 'Empty response for ' . $feed_url );
                continue;
            }

            // Parse XML.
            $xml = @simplexml_load_string( $body );
            if ( ! $xml ) {
                $this->log( 'Invalid XML for ' . $feed_url );
                continue;
            }

            // Handle RSS or custom XML (assume <channel><item> or <jobs><job>).
            $items = array();
            if ( isset( $xml->channel->item ) ) {
                $items = $xml->channel->item;
            } elseif ( isset( $xml->job ) ) {
                $items = $xml->job;
            } // Add more namespaces if needed.

            foreach ( $items as $item ) {
                $this->process_item( $item, $feed_id );
            }

            // Cache for 24h.
            set_transient( $cache_key, true, DAY_IN_SECONDS );
            $this->log( "Processed feed: $feed_url (" . count( $items ) . ' items)' );
        }

        do_action( 'puntwork_import_complete' );
    }

    /**
     * Process single item.
     *
     * @param SimpleXMLElement $item XML item.
     * @param int $feed_id Feed post ID.
     */
    private function process_item( $item, $feed_id ) {
        $title = (string) $item->title;
        if ( empty( $title ) ) return;

        // Duplicate check: By title slug.
        $slug = sanitize_title( $title );
        $existing = get_posts( array(
            'post_type'   => PUNTWORK_JOBS_CPT,
            'name'        => $slug,
            'post_status' => 'any',
            'numberposts' => 1,
        ) );

        if ( ! empty( $existing ) ) {
            $this->log( "Duplicate skipped: $title" );
            return;
        }

        $post_data = array(
            'post_title'   => $title,
            'post_content' => wp_kses_post( (string) $item->description ),
            'post_excerpt' => (string) $item->excerpt ?? '',
            'post_type'    => PUNTWORK_JOBS_CPT,
            'post_status'  => 'publish',
            'post_date'    => (string) $item->pubDate ?? current_time( 'mysql' ),
        );

        $post_id = wp_insert_post( $post_data );
        if ( is_wp_error( $post_id ) ) {
            $this->log( 'Insert error for ' . $title . ': ' . $post_id->get_error_message() );
            return;
        }

        // Add meta.
        update_post_meta( $post_id, '_source_feed', $feed_id );
        update_post_meta( $post_id, '_source_url', (string) $item->link ?? '' );

        apply_filters( 'puntwork_before_insert', $post_id, $item ); // For later cleaning/inference.

        $this->log( "Inserted job: $title (ID $post_id)" );
    }

    /**
     * Log message using WP filesystem.
     *
     * @param string $message Message.
     */
    private function log( $message ) {
        $fs = get_filesystem_method();
        if ( ! function_exists( 'WP_Filesystem' ) || ! WP_Filesystem( false, '', $fs ) ) {
            error_log( '[puntWork] ' . $message );
            return;
        }

        global $wp_filesystem;
        $log_file = PUNTWORK_LOGS_DIR . 'import-' . date( 'Y-m-d' ) . '.log';
        $wp_filesystem->put_contents(
            $log_file,
            date( 'Y-m-d H:i:s' ) . ' - ' . $message . PHP_EOL,
            FS_CHMOD_FILE // 0644
        );
    }
}

// Instantiate.
new Puntwork_JobImport();
