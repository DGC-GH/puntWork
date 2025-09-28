<?php

/**
 * Batch processing utilities
 *
 * @package    Puntwork
 * @subpackage Batch
 * @since      1.0.0
 */

declare(strict_types=1);

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Puntwork\Utilities\CacheManager;
use Puntwork\Utilities\EnhancedCacheManager;

/**
 * Batch processing logic
 * Handles the core batch processing operations for job imports
 */

// Include database optimization utilities
require_once __DIR__ . '/../utilities/database-optimization.php';

// Include duplicate handling utilities
require_once __DIR__ . '/../utilities/handle-duplicates.php';

// Include performance monitoring utilities
require_once __DIR__ . '/../utilities/performance-functions.php';

// Include batch size management utilities
require_once __DIR__ . '/batch-size-management.php';

// Include async processing utilities
require_once __DIR__ . '/../utilities/async-processing.php';

// Include tracing utilities
require_once __DIR__ . '/../utilities/PuntworkTracing.php';

// Include job deduplicator utilities
require_once __DIR__ . '/../utilities/JobDeduplicator.php';

// Include advanced memory manager utilities
require_once __DIR__ . '/../utilities/AdvancedMemoryManager.php';

// Include base memory manager utilities
require_once __DIR__ . '/../utilities/MemoryManager.php';

// Include utility helpers
require_once __DIR__ . '/../utilities/utility-helpers.php';

// Include batch processing modules
require_once __DIR__ . '/batch-processing-core.php';
require_once __DIR__ . '/batch-loading.php';
require_once __DIR__ . '/batch-metadata.php';
require_once __DIR__ . '/batch-duplicates.php';
require_once __DIR__ . '/batch-enhanced.php';
