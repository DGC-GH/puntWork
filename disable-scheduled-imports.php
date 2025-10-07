<?php
/**
 * Disable scheduled imports for puntWork plugin
 *
 * This script disables automatic scheduled imports by setting the
 * "Enable automatic imports" toggle to false.
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
    define( 'ABSPATH', dirname( __DIR__ ) . '/' );
}

// Load WordPress
require_once( ABSPATH . 'wp-load.php' );

// Load puntWork plugin includes
require_once( plugin_dir_path( __FILE__ ) . 'includes/scheduling/scheduling-core.php' );

echo "Disabling scheduled imports...\n";

// Call the function to disable scheduled imports
$result = \Puntwork\disable_scheduled_imports();

if ( $result['success'] ) {
    echo "Scheduled imports have been DISABLED.\n";
    echo "The 'Enable automatic imports' toggle is now set to false.\n";
    echo "No automatic imports will run until manually re-enabled.\n\n";

    echo "Previous schedule settings:\n";
    $prev = $result['previous_schedule'];
    echo "Enabled: " . ($prev['enabled'] ? 'true' : 'false') . "\n";
    echo "Frequency: " . ($prev['frequency'] ?? 'daily') . "\n";
    echo "Interval: " . ($prev['interval'] ?? 24) . " hours\n";
    echo "Time: " . ($prev['hour'] ?? 9) . ":" . str_pad($prev['minute'] ?? 0, 2, '0', STR_PAD_LEFT) . "\n\n";

    echo "Updated schedule settings:\n";
    $new = $result['new_schedule'];
    echo "Enabled: false\n";
    echo "Frequency: " . $new['frequency'] . "\n";
    echo "Interval: " . $new['interval'] . " hours\n";
    echo "Time: " . $new['hour'] . ":" . str_pad($new['minute'], 2, '0', STR_PAD_LEFT) . "\n";

    echo "\nDone!\n";
} else {
    echo "Failed to disable scheduled imports: " . ($result['message'] ?? 'Unknown error') . "\n";
}