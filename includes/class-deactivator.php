<?php
/**
 * Plugin deactivator
 *
 * @package WPCronV2\Includes
 */

namespace WPCronV2\Includes;

class Deactivator {

    /**
     * Deactivate plugin
     */
    public static function deactivate(): void {
        // Remove scheduled tasks
        wp_clear_scheduled_hook( 'wp_cron_v2_cleanup' );
        wp_clear_scheduled_hook( 'wp_cron_v2_monitor' );

        // Flush rewrite rules
        flush_rewrite_rules();

        // Note: We don't remove tables on deactivation
        // User may want to preserve data
        // Tables are only removed in uninstall.php
    }
}
