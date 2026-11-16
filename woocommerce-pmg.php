<?php

/**
 * Plugin Name: Decta Payments Gateway
 * Plugin URI: 
 * Description: DECTA can be easily integrated into the billing process of e-commerce websites and mobile apps. If your online store is using WooCommerce, download and set up this ready-made plugin.
 * Version: 3.11
 * Requires at least: 3.0.0
 * Requires PHP: 7.0
 * Author: Decta
 * Author URI: https://www.decta.com/
 * License: GNU General Public License v3.0
 * License URI: http://www.gnu.org/licenses/gpl-3.0.html
 */

// based on http://docs.woothemes.com/document/woocommerce-payment-gateway-plugin-base/
// docs http://docs.woothemes.com/document/payment-gateway-api/

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

add_action('plugins_loaded', 'woocommerce_pmg_init');
function woocommerce_pmg_init()
{
    \WooCommercePMG\DotEnv\DotEnv::init();
    require_once('src/devHelpers.php');
}

add_action('init', 'woocommerce_pmg_create_gateway');
function woocommerce_pmg_create_gateway()
{
    if ( class_exists( '\WooCommerce' ) ) {
        $obj = new WooCommercePMG\WooCommercePMG();
    }
}

add_action('init', function() {
    load_plugin_textdomain('woocommerce-payments', false, dirname(plugin_basename(__FILE__)) . '/languages');
});

    /**
     * Add the Gateway to WooCommerce
     * @param $methods
     * @return mixed
     */
    function woocommerce_add_payments_gateway($methods)
    {
        $methods[] = '\WooCommercePMG\WooCommercePMG';
        return $methods;
    }

    add_filter('woocommerce_payment_gateways', 'woocommerce_add_payments_gateway');


if ( ! defined( 'WC_PMG_PLUGIN_FILE' ) ) {
    define( 'WC_PMG_PLUGIN_FILE', __FILE__ );
}

if ( ! defined( 'WC_PMG_PLUGIN_DIR' ) ) {
    define( 'WC_PMG_PLUGIN_DIR', trim( basename( __DIR__ ) ) );
}


/**
 * Custom function to declare compatibility with cart_checkout_blocks feature
 */
function pmg_declare_cart_checkout_blocks_compatibility() {
    // Check if the required class exists
    if (class_exists('\Automattic\WooCommerce\Utilities\FeaturesUtil')) {
        // Declare compatibility for 'cart_checkout_blocks'
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('cart_checkout_blocks', __FILE__, true);
    }
}
// Hook the custom function to the 'before_woocommerce_init' action
add_action('before_woocommerce_init', 'pmg_declare_cart_checkout_blocks_compatibility');

// Hook the custom function to the 'woocommerce_blocks_loaded' action
add_action( 'woocommerce_blocks_loaded', 'pmg_woo_register_order_approval_payment_method_type' );

/**
 * Custom function to register a payment method type

 */
function pmg_woo_register_order_approval_payment_method_type() {
    // Check if the required class exists
    if ( ! class_exists( 'Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType' ) ) {
        return;
    }

    // Include the custom Blocks Checkout class
    require_once plugin_dir_path(__FILE__) . 'src/WooCommercePMGBlocks.php';

    // Hook the registration function to the 'woocommerce_blocks_payment_method_type_registration' action
    add_action(
        'woocommerce_blocks_payment_method_type_registration',
        function( Automattic\WooCommerce\Blocks\Payments\PaymentMethodRegistry $payment_method_registry ) {
            $payment_method_registry->register( new \WooCommercePMG\WooCommercePMGBlocks );
        }
    );
}

require_once __DIR__ . '/src/WebhookHandler.php';
add_action( 'rest_api_init', [ \WooCommercePMG\WebhookHandler::class, 'register_routes' ] );

add_action( 'pmg_process_webhook', [ \WooCommercePMG\WebhookHandler::class, 'process_job' ], 10, 1 );

register_activation_hook(__FILE__, function () {
    if ( function_exists('as_has_scheduled_action') && function_exists('as_schedule_recurring_action') ) {
        // Плануємо щоденне прибирання completed-екшнів
        $cleanupHook  = 'pmg_as_cleanup_completed';
        $cleanupArgs  = [];
        $cleanupGroup = 'pmg-webhooks';

        if ( ! as_has_scheduled_action( $cleanupHook, $cleanupArgs, $cleanupGroup ) ) {
            as_schedule_recurring_action( time() + 60, DAY_IN_SECONDS, $cleanupHook, $cleanupArgs, $cleanupGroup, true );
        }
    }
});

register_deactivation_hook(__FILE__, function () {
    if ( function_exists('as_unschedule_all_actions') ) {
        as_unschedule_all_actions('pmg_webhook_worker', [], 'pmg-webhooks');
        as_unschedule_all_actions('pmg_webhook_worker_retry', [], 'pmg-webhooks');
        as_unschedule_all_actions('pmg_as_cleanup_completed', [], 'pmg-webhooks');
    }
});

add_action('pmg_webhook_worker', [ \WooCommercePMG\WebhookHandler::class, 'process_queue' ]);
add_action('pmg_webhook_worker_retry', [ \WooCommercePMG\WebhookHandler::class, 'process_retry_queue' ]);

add_action('pmg_as_cleanup_completed', [ \WooCommercePMG\WebhookHandler::class, 'cleanup_completed_actions' ], 10, 0);


register_activation_hook( __FILE__, 'pmg_install_webhook_event_table' );

/**
 * crete table woocommerce_pmg_payment_webhook_event on install
 */
function pmg_install_webhook_event_table() {
    global $wpdb;
    $table = $wpdb->prefix . 'woocommerce_pmg_payment_webhook_event';
    $charset_collate = $wpdb->get_charset_collate();

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';

    $sql = "CREATE TABLE {$table} (
        entity_id      INT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'Event ID',
        order_id       VARCHAR(64)  NOT NULL COMMENT 'Order ID',
        transaction_id VARCHAR(64)  NOT NULL COMMENT 'Transaction ID',
        ip             VARCHAR(45)  NOT NULL COMMENT 'Sender IP',
        status         VARCHAR(32)  NOT NULL DEFAULT 'pending' COMMENT 'Processing Status',
        retry_count    INT          NOT NULL DEFAULT 0 COMMENT 'Count of retry requests',
        next_retry_at  TIMESTAMP    NULL DEFAULT NULL COMMENT 'Next Retry At',
        created_at     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Created At',
        updated_at     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT 'Updated At',
        PRIMARY KEY (entity_id),
        KEY idx_status (status)
    ) $charset_collate;";

    dbDelta( $sql );
}

// Note: Uninstall is handled by uninstall.php file (WordPress best practice)
