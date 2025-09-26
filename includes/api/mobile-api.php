<?php
/**
 * Mobile App API Endpoints
 *
 * @package    Puntwork
 * @subpackage API
 * @since      2.3.1
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Register mobile app REST API endpoints
 */
function register_mobile_api_endpoints() {
    // Authentication endpoints
    register_rest_route('puntwork-mobile/v1', '/auth/login', [
        'methods' => 'POST',
        'callback' => 'mobile_api_login',
        'permission_callback' => '__return_true',
        'args' => [
            'username' => [
                'required' => true,
                'sanitize_callback' => 'sanitize_text_field'
            ],
            'password' => [
                'required' => true,
                'sanitize_callback' => 'sanitize_text_field'
            ]
        ]
    ]);

    register_rest_route('puntwork-mobile/v1', '/auth/logout', [
        'methods' => 'POST',
        'callback' => 'mobile_api_logout',
        'permission_callback' => 'mobile_api_permission_check'
    ]);

    // Jobs endpoints
    register_rest_route('puntwork-mobile/v1', '/jobs', [
        'methods' => 'GET',
        'callback' => 'mobile_api_get_jobs',
        'permission_callback' => 'mobile_api_permission_check',
        'args' => [
            'page' => [
                'default' => 1,
                'sanitize_callback' => 'absint'
            ],
            'per_page' => [
                'default' => 20,
                'sanitize_callback' => 'absint'
            ],
            'search' => [
                'sanitize_callback' => 'sanitize_text_field'
            ],
            'category' => [
                'sanitize_callback' => 'sanitize_text_field'
            ],
            'location' => [
                'sanitize_callback' => 'sanitize_text_field'
            ]
        ]
    ]);

    register_rest_route('puntwork-mobile/v1', '/jobs/(?P<id>\d+)', [
        'methods' => 'GET',
        'callback' => 'mobile_api_get_job',
        'permission_callback' => 'mobile_api_permission_check',
        'args' => [
            'id' => [
                'sanitize_callback' => 'absint'
            ]
        ]
    ]);

    // Applications endpoints
    register_rest_route('puntwork-mobile/v1', '/applications', [
        'methods' => 'GET',
        'callback' => 'mobile_api_get_applications',
        'permission_callback' => 'mobile_api_permission_check',
        'args' => [
            'page' => [
                'default' => 1,
                'sanitize_callback' => 'absint'
            ],
            'per_page' => [
                'default' => 20,
                'sanitize_callback' => 'absint'
            ],
            'status' => [
                'sanitize_callback' => 'sanitize_text_field'
            ]
        ]
    ]);

    register_rest_route('puntwork-mobile/v1', '/applications', [
        'methods' => 'POST',
        'callback' => 'mobile_api_create_application',
        'permission_callback' => 'mobile_api_permission_check',
        'args' => [
            'job_id' => [
                'required' => true,
                'sanitize_callback' => 'absint'
            ],
            'first_name' => [
                'required' => true,
                'sanitize_callback' => 'sanitize_text_field'
            ],
            'last_name' => [
                'required' => true,
                'sanitize_callback' => 'sanitize_text_field'
            ],
            'email' => [
                'required' => true,
                'sanitize_callback' => 'sanitize_email'
            ],
            'phone' => [
                'sanitize_callback' => 'sanitize_text_field'
            ],
            'cover_letter' => [
                'sanitize_callback' => 'sanitize_textarea_field'
            ],
            'resume' => [
                'sanitize_callback' => 'absint' // Attachment ID
            ]
        ]
    ]);

    // Dashboard endpoints
    register_rest_route('puntwork-mobile/v1', '/dashboard/stats', [
        'methods' => 'GET',
        'callback' => 'mobile_api_get_dashboard_stats',
        'permission_callback' => 'mobile_api_permission_check'
    ]);

    // User profile endpoints
    register_rest_route('puntwork-mobile/v1', '/profile', [
        'methods' => 'GET',
        'callback' => 'mobile_api_get_profile',
        'permission_callback' => 'mobile_api_permission_check'
    ]);

    register_rest_route('puntwork-mobile/v1', '/profile', [
        'methods' => 'POST',
        'callback' => 'mobile_api_update_profile',
        'permission_callback' => 'mobile_api_permission_check'
    ]);

    // File upload endpoint
    register_rest_route('puntwork-mobile/v1', '/upload', [
        'methods' => 'POST',
        'callback' => 'mobile_api_upload_file',
        'permission_callback' => 'mobile_api_permission_check'
    ]);
}

add_action('rest_api_init', 'register_mobile_api_endpoints');

/**
 * Mobile API permission check
 */
function mobile_api_permission_check($request) {
    // Check for valid JWT token or WordPress authentication
    $auth_header = $request->get_header('authorization');

    if (!$auth_header) {
        return new WP_Error('rest_forbidden', __('Authentication required', 'puntwork'), ['status' => 401]);
    }

    // Check for Bearer token
    if (preg_match('/Bearer\s+(.*)$/i', $auth_header, $matches)) {
        $token = $matches[1];

        // Verify JWT token (simplified - in production use proper JWT library)
        $user_id = mobile_verify_jwt_token($token);

        if ($user_id) {
            wp_set_current_user($user_id);
            return true;
        }
    }

    return new WP_Error('rest_forbidden', __('Invalid authentication token', 'puntwork'), ['status' => 401]);
}

/**
 * Mobile API login
 */
function mobile_api_login($request) {
    $username = $request->get_param('username');
    $password = $request->get_param('password');

    $user = wp_authenticate($username, $password);

    if (is_wp_error($user)) {
        return new WP_Error('login_failed', __('Invalid credentials', 'puntwork'), ['status' => 401]);
    }

    // Generate JWT token (simplified - in production use proper JWT library)
    $token = mobile_generate_jwt_token($user->ID);

    return [
        'success' => true,
        'token' => $token,
        'user' => [
            'id' => $user->ID,
            'username' => $user->user_login,
            'email' => $user->user_email,
            'display_name' => $user->display_name,
            'roles' => $user->roles
        ]
    ];
}

/**
 * Mobile API logout
 */
function mobile_api_logout($request) {
    // Invalidate token (simplified - in production maintain token blacklist)
    return [
        'success' => true,
        'message' => __('Logged out successfully', 'puntwork')
    ];
}

/**
 * Get jobs for mobile app
 */
function mobile_api_get_jobs($request) {
    $page = $request->get_param('page');
    $per_page = $request->get_param('per_page');
    $search = $request->get_param('search');
    $category = $request->get_param('category');
    $location = $request->get_param('location');

    $args = [
        'post_type' => 'job_listing',
        'post_status' => 'publish',
        'posts_per_page' => $per_page,
        'paged' => $page,
        'meta_query' => [
            [
                'key' => '_job_expires',
                'value' => current_time('mysql'),
                'compare' => '>',
                'type' => 'DATETIME'
            ]
        ]
    ];

    // Add search
    if ($search) {
        $args['s'] = $search;
    }

    // Add category filter
    if ($category) {
        $args['tax_query'][] = [
            'taxonomy' => 'job_listing_category',
            'field' => 'slug',
            'terms' => $category
        ];
    }

    // Add location filter
    if ($location) {
        $args['meta_query'][] = [
            'key' => '_job_location',
            'value' => $location,
            'compare' => 'LIKE'
        ];
    }

    $query = new WP_Query($args);
    $jobs = [];

    foreach ($query->posts as $post) {
        $jobs[] = mobile_format_job_data($post);
    }

    return [
        'jobs' => $jobs,
        'total' => $query->found_posts,
        'pages' => $query->max_num_pages,
        'current_page' => $page
    ];
}

/**
 * Get single job for mobile app
 */
function mobile_api_get_job($request) {
    $job_id = $request->get_param('id');

    $post = get_post($job_id);

    if (!$post || $post->post_type !== 'job_listing') {
        return new WP_Error('job_not_found', __('Job not found', 'puntwork'), ['status' => 404]);
    }

    return [
        'job' => mobile_format_job_data($post, true)
    ];
}

/**
 * Get applications for mobile app
 */
function mobile_api_get_applications($request) {
    $page = $request->get_param('page');
    $per_page = $request->get_param('per_page');
    $status = $request->get_param('status');

    // This would need to be implemented based on your application storage system
    // For now, return mock data
    return [
        'applications' => [],
        'total' => 0,
        'pages' => 0,
        'current_page' => $page
    ];
}

/**
 * Create job application via mobile app
 */
function mobile_api_create_application($request) {
    $job_id = $request->get_param('job_id');
    $first_name = $request->get_param('first_name');
    $last_name = $request->get_param('last_name');
    $email = $request->get_param('email');
    $phone = $request->get_param('phone');
    $cover_letter = $request->get_param('cover_letter');
    $resume_id = $request->get_param('resume');

    // Validate job exists
    $job = get_post($job_id);
    if (!$job || $job->post_type !== 'job_listing') {
        return new WP_Error('invalid_job', __('Invalid job ID', 'puntwork'), ['status' => 400]);
    }

    // Create application data
    $application_data = [
        'job_id' => $job_id,
        'first_name' => $first_name,
        'last_name' => $last_name,
        'email' => $email,
        'phone' => $phone,
        'cover_letter' => $cover_letter,
        'resume_id' => $resume_id,
        'application_date' => current_time('mysql'),
        'source' => 'mobile_app'
    ];

    // Save application (this would need to be implemented based on your storage system)
    $application_id = mobile_save_application($application_data);

    // Auto-sync to CRM if enabled
    if (get_option('puntwork_crm_auto_sync_applications', false)) {
        $crm_manager = new \Puntwork\CRM\CRMManager();
        $crm_manager->syncJobApplication($application_data);
    }

    return [
        'success' => true,
        'application_id' => $application_id,
        'message' => __('Application submitted successfully', 'puntwork')
    ];
}

/**
 * Get dashboard statistics for mobile app
 */
function mobile_api_get_dashboard_stats($request) {
    // Get job statistics
    $total_jobs = wp_count_posts('job_listing')->publish;

    // Get application statistics (mock data - implement based on your system)
    $total_applications = 0;
    $pending_applications = 0;
    $approved_applications = 0;

    return [
        'total_jobs' => $total_jobs,
        'total_applications' => $total_applications,
        'pending_applications' => $pending_applications,
        'approved_applications' => $approved_applications,
        'recent_activity' => []
    ];
}

/**
 * Get user profile for mobile app
 */
function mobile_api_get_profile($request) {
    $user = wp_get_current_user();

    return [
        'id' => $user->ID,
        'username' => $user->user_login,
        'email' => $user->user_email,
        'display_name' => $user->display_name,
        'first_name' => $user->first_name,
        'last_name' => $user->last_name,
        'bio' => get_user_meta($user->ID, 'description', true),
        'avatar' => get_avatar_url($user->ID),
        'roles' => $user->roles
    ];
}

/**
 * Update user profile via mobile app
 */
function mobile_api_update_profile($request) {
    $user_id = get_current_user_id();

    $allowed_fields = ['first_name', 'last_name', 'description'];
    $updated = false;

    foreach ($allowed_fields as $field) {
        if ($request->has_param($field)) {
            $value = $request->get_param($field);
            update_user_meta($user_id, $field, sanitize_text_field($value));
            $updated = true;
        }
    }

    if ($updated) {
        return [
            'success' => true,
            'message' => __('Profile updated successfully', 'puntwork')
        ];
    }

    return new WP_Error('no_changes', __('No changes made', 'puntwork'), ['status' => 400]);
}

/**
 * Upload file via mobile app
 */
function mobile_api_upload_file($request) {
    if (!function_exists('wp_handle_upload')) {
        require_once(ABSPATH . 'wp-admin/includes/file.php');
    }

    $files = $request->get_file_params();

    if (empty($files)) {
        return new WP_Error('no_file', __('No file uploaded', 'puntwork'), ['status' => 400]);
    }

    // Handle file upload
    $file = $files['file'];
    $upload_overrides = [
        'test_form' => false,
        'upload_error_handler' => function($file, $message) {
            return new WP_Error('upload_error', $message);
        }
    ];

    $uploaded_file = wp_handle_upload($file, $upload_overrides);

    if (isset($uploaded_file['error'])) {
        return new WP_Error('upload_failed', $uploaded_file['error'], ['status' => 400]);
    }

    // Create attachment
    $attachment_id = wp_insert_attachment([
        'guid' => $uploaded_file['url'],
        'post_mime_type' => $uploaded_file['type'],
        'post_title' => basename($uploaded_file['file']),
        'post_content' => '',
        'post_status' => 'inherit'
    ], $uploaded_file['file']);

    if (is_wp_error($attachment_id)) {
        return $attachment_id;
    }

    // Generate metadata
    require_once(ABSPATH . 'wp-admin/includes/image.php');
    $attachment_data = wp_generate_attachment_metadata($attachment_id, $uploaded_file['file']);
    wp_update_attachment_metadata($attachment_id, $attachment_data);

    return [
        'success' => true,
        'attachment_id' => $attachment_id,
        'url' => $uploaded_file['url'],
        'message' => __('File uploaded successfully', 'puntwork')
    ];
}

/**
 * Format job data for mobile API
 */
function mobile_format_job_data($post, $detailed = false) {
    $job_data = [
        'id' => $post->ID,
        'title' => $post->post_title,
        'description' => $post->post_content,
        'excerpt' => $post->post_excerpt ?: wp_trim_words($post->post_content, 30),
        'date' => $post->post_date,
        'modified' => $post->post_modified,
        'status' => $post->post_status,
        'featured_image' => get_the_post_thumbnail_url($post->ID, 'medium'),
        'permalink' => get_permalink($post->ID)
    ];

    // Add job-specific metadata
    $meta_fields = [
        '_job_location',
        '_job_type',
        '_job_salary',
        '_job_expires',
        '_company_name',
        '_company_website',
        '_company_logo'
    ];

    foreach ($meta_fields as $field) {
        $value = get_post_meta($post->ID, $field, true);
        if ($value) {
            $key = str_replace('_job_', '', str_replace('_company_', '', $field));
            $job_data[$key] = $value;
        }
    }

    // Add categories and tags
    $job_data['categories'] = wp_get_post_terms($post->ID, 'job_listing_category', ['fields' => 'names']);
    $job_data['tags'] = wp_get_post_terms($post->ID, 'job_listing_tag', ['fields' => 'names']);

    if ($detailed) {
        // Add additional detailed information
        $job_data['author'] = get_the_author_meta('display_name', $post->post_author);
        $job_data['application_url'] = get_post_meta($post->ID, '_application_url', true);
        $job_data['application_email'] = get_post_meta($post->ID, '_application_email', true);
    }

    return $job_data;
}

/**
 * Save job application (placeholder - implement based on your system)
 */
function mobile_save_application($application_data) {
    // This should be implemented based on your application storage system
    // For now, return a mock ID
    return uniqid('app_', true);
}

/**
 * Generate JWT token (simplified - use proper JWT library in production)
 */
function mobile_generate_jwt_token($user_id) {
    $header = json_encode(['typ' => 'JWT', 'alg' => 'HS256']);
    $payload = json_encode([
        'user_id' => $user_id,
        'iat' => time(),
        'exp' => time() + (7 * 24 * 60 * 60) // 7 days
    ]);

    $header_encoded = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($header));
    $payload_encoded = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($payload));

    $signature = hash_hmac('sha256', $header_encoded . "." . $payload_encoded, wp_salt(), true);
    $signature_encoded = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($signature));

    return $header_encoded . '.' . $payload_encoded . '.' . $signature_encoded;
}

/**
 * Verify JWT token (simplified - use proper JWT library in production)
 */
function mobile_verify_jwt_token($token) {
    $parts = explode('.', $token);

    if (count($parts) !== 3) {
        return false;
    }

    $header = $parts[0];
    $payload = $parts[1];
    $signature = $parts[2];

    $expected_signature = hash_hmac('sha256', $header . "." . $payload, wp_salt(), true);
    $expected_signature_encoded = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($expected_signature));

    if (!hash_equals($signature, $expected_signature_encoded)) {
        return false;
    }

    $payload_data = json_decode(base64_decode(str_replace(['-', '_'], ['+', '/'], $payload)), true);

    if (!$payload_data || !isset($payload_data['exp']) || $payload_data['exp'] < time()) {
        return false;
    }

    return $payload_data['user_id'];
}