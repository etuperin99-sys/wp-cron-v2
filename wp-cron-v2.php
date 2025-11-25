<?php
/**
 * Plugin Name: WP Cron v2
 * Plugin URI: https://gitlab.com/etuperin99/wp-cron-v2
 * Description: Modern job queue for WordPress - Laravel Horizon-level background processing system
 * Version: 0.4.0
 * Author: Etuperin99
 * Author URI: https://gitlab.com/etuperin99
 * License: GPL-2.0+
 * License URI: http://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain: wp-cron-v2
 * Domain Path: /languages
 * Requires at least: 6.0
 * Requires PHP: 8.0
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Plugin constants
define( 'WP_CRON_V2_VERSION', '0.4.0' );
define( 'WP_CRON_V2_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'WP_CRON_V2_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'WP_CRON_V2_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

/**
 * Autoloader
 */
spl_autoload_register( function ( $class ) {
    $prefix = 'WPCronV2\\';
    $base_dir = WP_CRON_V2_PLUGIN_DIR . 'src/';

    $len = strlen( $prefix );
    if ( strncmp( $prefix, $class, $len ) !== 0 ) {
        return;
    }

    $relative_class = substr( $class, $len );
    $file = $base_dir . str_replace( '\\', '/', $relative_class ) . '.php';

    if ( file_exists( $file ) ) {
        require $file;
    }
});

/**
 * Plugin activation
 */
function wp_cron_v2_activate() {
    require_once WP_CRON_V2_PLUGIN_DIR . 'includes/class-activator.php';
    WPCronV2\Includes\Activator::activate();
}
register_activation_hook( __FILE__, 'wp_cron_v2_activate' );

/**
 * Plugin deactivation
 */
function wp_cron_v2_deactivate() {
    require_once WP_CRON_V2_PLUGIN_DIR . 'includes/class-deactivator.php';
    WPCronV2\Includes\Deactivator::deactivate();
}
register_deactivation_hook( __FILE__, 'wp_cron_v2_deactivate' );

/**
 * Access plugin instance
 *
 * @return WPCronV2\Queue\Manager
 */
function wp_cron_v2() {
    return WPCronV2\Queue\Manager::get_instance();
}

/**
 * Access WP-Cron adapter
 *
 * @return WPCronV2\Adapter\WPCronAdapter
 */
function wp_cron_v2_adapter() {
    return WPCronV2\Adapter\WPCronAdapter::get_instance();
}

/**
 * Access scheduler
 *
 * @return WPCronV2\Queue\Scheduler
 */
function wp_cron_v2_scheduler() {
    return WPCronV2\Queue\Scheduler::get_instance();
}

/**
 * Create new batch
 *
 * @param string $name Batch name (optional)
 * @return WPCronV2\Queue\Batch
 */
function wp_cron_v2_batch( string $name = '' ) {
    return new WPCronV2\Queue\Batch( $name );
}

/**
 * Create new job chain
 *
 * @param string $name Chain name (optional)
 * @return WPCronV2\Queue\Chain
 */
function wp_cron_v2_chain( string $name = '' ) {
    return new WPCronV2\Queue\Chain( $name );
}

/**
 * Access rate limiter
 *
 * @return WPCronV2\Queue\RateLimiter
 */
function wp_cron_v2_rate_limiter() {
    return WPCronV2\Queue\RateLimiter::get_instance();
}

/**
 * Access webhooks
 *
 * @return WPCronV2\Queue\Webhooks
 */
function wp_cron_v2_webhooks() {
    return WPCronV2\Queue\Webhooks::get_instance();
}

/**
 * Access multisite network manager
 *
 * @return WPCronV2\Multisite\NetworkManager
 */
function wp_cron_v2_network() {
    return WPCronV2\Multisite\NetworkManager::get_instance();
}

/**
 * Initialize plugin
 */
add_action( 'plugins_loaded', function() {
    // Load translations
    load_plugin_textdomain( 'wp-cron-v2', false, dirname( WP_CRON_V2_PLUGIN_BASENAME ) . '/languages' );

    // Initialize Queue Manager
    wp_cron_v2();

    // Initialize Scheduler
    wp_cron_v2_scheduler();

    // Enable WP-Cron adapter if setting is enabled
    $settings = get_option( 'wp_cron_v2_settings', [] );
    if ( ! empty( $settings['enable_wp_cron_adapter'] ) ) {
        wp_cron_v2_adapter()->enable();
    }

    // Admin UI
    if ( is_admin() ) {
        WPCronV2\Admin\AdminPage::get_instance();
    }

    // REST API
    WPCronV2\Api\RestController::get_instance();

    // Webhooks
    WPCronV2\Queue\Webhooks::get_instance();
});

/**
 * WP-CLI commands
 */
if ( defined( 'WP_CLI' ) && WP_CLI ) {
    require_once WP_CRON_V2_PLUGIN_DIR . 'includes/class-cli-commands.php';
}
