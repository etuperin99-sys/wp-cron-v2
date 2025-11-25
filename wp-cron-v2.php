<?php
/**
 * Plugin Name: WP Cron v2
 * Plugin URI: https://gitlab.com/etuperin99/wp-cron-v2
 * Description: Moderni job queue WordPressille - Laravel Horizon -tason taustaprosessijärjestelmä
 * Version: 0.1.0
 * Author: Etuperin99
 * Author URI: https://gitlab.com/etuperin99
 * License: GPL-2.0+
 * License URI: http://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain: wp-cron-v2
 * Domain Path: /languages
 * Requires at least: 6.0
 * Requires PHP: 8.0
 */

// Estä suora pääsy
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Pluginin vakiot
define( 'WP_CRON_V2_VERSION', '0.1.0' );
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
 * Plugin aktivointi
 */
function wp_cron_v2_activate() {
    require_once WP_CRON_V2_PLUGIN_DIR . 'includes/class-activator.php';
    WPCronV2\Includes\Activator::activate();
}
register_activation_hook( __FILE__, 'wp_cron_v2_activate' );

/**
 * Plugin deaktivointi
 */
function wp_cron_v2_deactivate() {
    require_once WP_CRON_V2_PLUGIN_DIR . 'includes/class-deactivator.php';
    WPCronV2\Includes\Deactivator::deactivate();
}
register_deactivation_hook( __FILE__, 'wp_cron_v2_deactivate' );

/**
 * Pääsy pluginin instanssiin
 *
 * @return WPCronV2\Queue\Manager
 */
function wp_cron_v2() {
    return WPCronV2\Queue\Manager::get_instance();
}

/**
 * Alusta plugin
 */
add_action( 'plugins_loaded', function() {
    // Lataa käännökset
    load_plugin_textdomain( 'wp-cron-v2', false, dirname( WP_CRON_V2_PLUGIN_BASENAME ) . '/languages' );

    // Alusta Queue Manager
    wp_cron_v2();
});

/**
 * WP-CLI komennot
 */
if ( defined( 'WP_CLI' ) && WP_CLI ) {
    require_once WP_CRON_V2_PLUGIN_DIR . 'includes/class-cli-commands.php';
}
