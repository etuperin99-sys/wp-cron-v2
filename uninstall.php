<?php
/**
 * Uninstall - Poistetaan pluginin data kun se poistetaan
 *
 * @package WPCronV2
 */

// Jos tätä ei kutsuta WordPressin kautta, poistu
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

global $wpdb;

// Poista tietokantataulut
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}job_queue" );
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}job_queue_failed" );

// Poista optiot
delete_option( 'wp_cron_v2_version' );
delete_option( 'wp_cron_v2_settings' );
delete_option( 'wp_cron_v2_schedules' );
delete_option( 'wp_cron_v2_registered_hooks' );

// Poista transientit
$wpdb->query(
    "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_wp_cron_v2_%'"
);
$wpdb->query(
    "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_wp_cron_v2_%'"
);

// Poista mahdolliset scheduloidut WP-Cron eventit
wp_clear_scheduled_hook( 'wp_cron_v2_cleanup' );
wp_clear_scheduled_hook( 'wp_cron_v2_monitor' );
