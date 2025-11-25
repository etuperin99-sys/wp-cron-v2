<?php
/**
 * Plugin activator
 *
 * @package WPCronV2\Includes
 */

namespace WPCronV2\Includes;

class Activator {

    /**
     * Activate plugin
     */
    public static function activate(): void {
        self::create_tables();
        self::set_default_options();

        // Save version
        update_option( 'wp_cron_v2_version', WP_CRON_V2_VERSION );

        // Flush rewrite rules
        flush_rewrite_rules();
    }

    /**
     * Create database tables
     */
    private static function create_tables(): void {
        global $wpdb;

        $charset_collate = $wpdb->get_charset_collate();
        $table_name = $wpdb->prefix . 'job_queue';

        $sql = "CREATE TABLE {$table_name} (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            job_type varchar(255) NOT NULL,
            payload longtext NOT NULL,
            queue varchar(100) NOT NULL DEFAULT 'default',
            priority varchar(20) NOT NULL DEFAULT 'normal',
            attempts tinyint(3) UNSIGNED NOT NULL DEFAULT 0,
            max_attempts tinyint(3) UNSIGNED NOT NULL DEFAULT 3,
            available_at datetime NOT NULL,
            reserved_at datetime DEFAULT NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            status varchar(20) NOT NULL DEFAULT 'queued',
            error_message text DEFAULT NULL,
            worker_id varchar(100) DEFAULT NULL,
            PRIMARY KEY (id),
            KEY queue_status_available (queue, status, available_at),
            KEY status (status),
            KEY job_type (job_type),
            KEY priority (priority)
        ) {$charset_collate};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );

        // Create failed_jobs table for history
        $failed_table = $wpdb->prefix . 'job_queue_failed';

        $sql_failed = "CREATE TABLE {$failed_table} (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            job_type varchar(255) NOT NULL,
            payload longtext NOT NULL,
            queue varchar(100) NOT NULL,
            exception longtext NOT NULL,
            failed_at datetime NOT NULL,
            PRIMARY KEY (id),
            KEY queue (queue),
            KEY failed_at (failed_at)
        ) {$charset_collate};";

        dbDelta( $sql_failed );

        // Add missing columns - if column already exists, query fails silently
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $wpdb->suppress_errors( true );

        // batch_id
        $wpdb->query(
            "ALTER TABLE {$table_name} ADD COLUMN batch_id varchar(36) DEFAULT NULL"
        );

        // site_id (multisite)
        $wpdb->query(
            "ALTER TABLE {$table_name} ADD COLUMN site_id bigint(20) UNSIGNED DEFAULT NULL"
        );

        // Add site_id index
        $wpdb->query(
            "ALTER TABLE {$table_name} ADD KEY site_id (site_id)"
        );

        $wpdb->suppress_errors( false );

        // Create job_batches table
        $batches_table = $wpdb->prefix . 'job_batches';

        $sql_batches = "CREATE TABLE {$batches_table} (
            id varchar(36) NOT NULL,
            name varchar(255) NOT NULL,
            total_jobs int(10) UNSIGNED NOT NULL DEFAULT 0,
            pending_jobs int(10) UNSIGNED NOT NULL DEFAULT 0,
            failed_jobs int(10) UNSIGNED NOT NULL DEFAULT 0,
            options longtext DEFAULT NULL,
            created_at datetime NOT NULL,
            cancelled_at datetime DEFAULT NULL,
            finished_at datetime DEFAULT NULL,
            PRIMARY KEY (id),
            KEY name (name),
            KEY created_at (created_at)
        ) {$charset_collate};";

        dbDelta( $sql_batches );
    }

    /**
     * Set default options
     */
    private static function set_default_options(): void {
        $defaults = [
            'wp_cron_v2_settings' => [
                'default_queue' => 'default',
                'max_execution_time' => 300,
                'worker_sleep_time' => 3,
                'max_attempts' => 3,
                'backoff_multiplier' => 2,
                'enable_logging' => true,
                'log_retention_days' => 30,
            ],
        ];

        foreach ( $defaults as $option => $value ) {
            if ( false === get_option( $option ) ) {
                add_option( $option, $value );
            }
        }
    }
}
