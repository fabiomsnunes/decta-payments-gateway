<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly.
}

// if uninstall not called from WordPress exit.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

/**
 * own autoloader for WooCommercePMG plugin.
 * we need it for using namespaces in our plugin
 */
spl_autoload_register( function ( $class ) {
    list( $plugin_space ) = explode( '\\', $class );
    if ( $plugin_space !== 'WooCommercePMG' ) {
        return;
    }

    // Default directory for all code is plugin's /src/.
    $plugin_dir = basename( __DIR__ );
    $base_dir = plugin_dir_path(__DIR__)  . $plugin_dir . '/src/';

    // Get the relative class name.
    $relative_class = substr( $class, strlen( $plugin_space ) + 1 );

    // Prepare a path to a file.
    $file = wp_normalize_path( $base_dir . $relative_class . '.php' );

    // If the file exists, require it.
    if ( is_readable( $file ) ) {
        require_once $file;
    }
} );

\WooCommercePMG\DotEnv\DotEnv::init();

if( isset( $_ENV['ENV'] )
    && isset( $_ENV['ENV'] ) == 'local'
    && trim( basename( __DIR__ ) ) == 'woocommerce-pmg'){
    exit;
}

/**
 * Uninstall WooCommerce PMG Plugin
 * 
 * This file is called when the plugin is uninstalled from WordPress admin.
 * It removes all plugin data from the database.
 */

global $wpdb;

/**
 * Remove plugin database table
 */
$table_name = $wpdb->prefix . 'woocommerce_pmg_payment_webhook_event';
$wpdb->query("DROP TABLE IF EXISTS {$table_name}");

/**
 * Remove plugin options/settings
 * WooCommerce stores gateway settings as 'woocommerce_{gateway_id}_settings'
 * 
 * Try to get plugin ID from multiple sources
 */
$plugin_id = 'payments_pmg'; // Default

// Try to get from .env file if exists
$env_file = dirname(__FILE__) . '/.env';
if (file_exists($env_file)) {
    $env_content = file_get_contents($env_file);
    if (preg_match('/PLUGIN_ID=(.+)/', $env_content, $matches)) {
        $plugin_id = trim($matches[1]);
    }
}

// Try to find plugin ID in WooCommerce options
$all_options = $wpdb->get_results(
    $wpdb->prepare(
        "SELECT option_name FROM {$wpdb->options}
         WHERE option_name LIKE %s
         OR option_name LIKE %s",
        $wpdb->esc_like('woocommerce_') . '%' . $wpdb->esc_like('pmg') . '%' . $wpdb->esc_like('_settings'),
        $wpdb->esc_like('woocommerce_' . $plugin_id) . '%'
    )
);

// Remove gateway settings - delete all found options
foreach ($all_options as $option) {
    delete_option($option->option_name);
}

// Also try standard WooCommerce gateway settings format
delete_option('woocommerce_' . $plugin_id . '_settings');

/**
 * Remove scheduled actions (Action Scheduler)
 */
if (function_exists('as_unschedule_all_actions')) {
    as_unschedule_all_actions('pmg_webhook_worker', [], 'pmg-webhooks');
    as_unschedule_all_actions('pmg_webhook_worker_retry', [], 'pmg-webhooks');
    as_unschedule_all_actions('pmg_as_cleanup_completed', [], 'pmg-webhooks');
}

/**
 * Clean up Action Scheduler custom table entries for this plugin
 * Action Scheduler stores actions in custom tables
 */
if (class_exists('ActionScheduler')) {
    try {
        $store = ActionScheduler::store();
        
        // Cancel all pending/in-progress actions for our hooks
        $hooks = ['pmg_webhook_worker', 'pmg_webhook_worker_retry', 'pmg_as_cleanup_completed'];
        
        foreach ($hooks as $hook) {
            $actions = $store->query_actions([
                'hook' => $hook,
                'status' => ActionScheduler_Store::STATUS_PENDING,
                'per_page' => -1,
            ]);
            
            foreach ($actions as $action_id) {
                $store->cancel_action($action_id);
            }
        }
    } catch (Exception $e) {
        error_log('PMG uninstall: Failed to clean up Action Scheduler: ' . $e->getMessage());
    }
}

// Clear any cached data
wp_cache_flush();
