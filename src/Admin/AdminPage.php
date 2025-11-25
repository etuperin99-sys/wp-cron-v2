<?php
/**
 * Admin panel
 *
 * @package WPCronV2\Admin
 */

namespace WPCronV2\Admin;

class AdminPage {

    /**
     * Singleton
     *
     * @var AdminPage|null
     */
    private static ?AdminPage $instance = null;

    /**
     * Get singleton
     *
     * @return AdminPage
     */
    public static function get_instance(): AdminPage {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor
     */
    private function __construct() {
        add_action( 'admin_menu', [ $this, 'add_menu_page' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
        add_action( 'wp_ajax_wp_cron_v2_get_stats', [ $this, 'ajax_get_stats' ] );
        add_action( 'wp_ajax_wp_cron_v2_retry_job', [ $this, 'ajax_retry_job' ] );
        add_action( 'wp_ajax_wp_cron_v2_cancel_job', [ $this, 'ajax_cancel_job' ] );
    }

    /**
     * Add menu page
     */
    public function add_menu_page(): void {
        add_menu_page(
            __( 'WP Cron v2', 'wp-cron-v2' ),
            __( 'Cron v2', 'wp-cron-v2' ),
            'manage_options',
            'wp-cron-v2',
            [ $this, 'render_page' ],
            'dashicons-clock',
            80
        );

        add_submenu_page(
            'wp-cron-v2',
            __( 'Queue', 'wp-cron-v2' ),
            __( 'Queue', 'wp-cron-v2' ),
            'manage_options',
            'wp-cron-v2',
            [ $this, 'render_page' ]
        );

        add_submenu_page(
            'wp-cron-v2',
            __( 'Settings', 'wp-cron-v2' ),
            __( 'Settings', 'wp-cron-v2' ),
            'manage_options',
            'wp-cron-v2-settings',
            [ $this, 'render_settings_page' ]
        );
    }

    /**
     * Load CSS/JS
     *
     * @param string $hook
     */
    public function enqueue_assets( string $hook ): void {
        if ( strpos( $hook, 'wp-cron-v2' ) === false ) {
            return;
        }

        wp_enqueue_style(
            'wp-cron-v2-admin',
            WP_CRON_V2_PLUGIN_URL . 'assets/css/admin.css',
            [],
            WP_CRON_V2_VERSION
        );

        wp_enqueue_script(
            'wp-cron-v2-admin',
            WP_CRON_V2_PLUGIN_URL . 'assets/js/admin.js',
            [ 'jquery' ],
            WP_CRON_V2_VERSION,
            true
        );

        wp_localize_script( 'wp-cron-v2-admin', 'wpCronV2', [
            'ajaxUrl' => admin_url( 'admin-ajax.php' ),
            'nonce' => wp_create_nonce( 'wp_cron_v2_admin' ),
        ] );
    }

    /**
     * Render main page
     */
    public function render_page(): void {
        global $wpdb;
        $table = $wpdb->prefix . 'job_queue';

        // Get stats
        $stats = $this->get_all_stats();

        // Get jobs
        $status_filter = isset( $_GET['status'] ) ? sanitize_key( $_GET['status'] ) : '';
        $queue_filter = isset( $_GET['queue'] ) ? sanitize_key( $_GET['queue'] ) : '';

        $where = '1=1';
        if ( $status_filter ) {
            $where .= $wpdb->prepare( ' AND status = %s', $status_filter );
        }
        if ( $queue_filter ) {
            $where .= $wpdb->prepare( ' AND queue = %s', $queue_filter );
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $jobs = $wpdb->get_results(
            "SELECT * FROM {$table} WHERE {$where} ORDER BY created_at DESC LIMIT 50",
            ARRAY_A
        );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $queues = $wpdb->get_col( "SELECT DISTINCT queue FROM {$table}" );

        ?>
        <div class="wrap wp-cron-v2-admin">
            <h1><?php esc_html_e( 'WP Cron v2 - Job Queue', 'wp-cron-v2' ); ?></h1>

            <!-- Stats -->
            <div class="wp-cron-v2-stats">
                <div class="stat-card stat-queued">
                    <span class="stat-number"><?php echo esc_html( $stats['queued'] ); ?></span>
                    <span class="stat-label"><?php esc_html_e( 'Queued', 'wp-cron-v2' ); ?></span>
                </div>
                <div class="stat-card stat-running">
                    <span class="stat-number"><?php echo esc_html( $stats['running'] ); ?></span>
                    <span class="stat-label"><?php esc_html_e( 'Running', 'wp-cron-v2' ); ?></span>
                </div>
                <div class="stat-card stat-completed">
                    <span class="stat-number"><?php echo esc_html( $stats['completed'] ); ?></span>
                    <span class="stat-label"><?php esc_html_e( 'Completed', 'wp-cron-v2' ); ?></span>
                </div>
                <div class="stat-card stat-failed">
                    <span class="stat-number"><?php echo esc_html( $stats['failed'] ); ?></span>
                    <span class="stat-label"><?php esc_html_e( 'Failed', 'wp-cron-v2' ); ?></span>
                </div>
            </div>

            <!-- Filters -->
            <div class="wp-cron-v2-filters">
                <form method="get">
                    <input type="hidden" name="page" value="wp-cron-v2">

                    <select name="status">
                        <option value=""><?php esc_html_e( 'All statuses', 'wp-cron-v2' ); ?></option>
                        <option value="queued" <?php selected( $status_filter, 'queued' ); ?>><?php esc_html_e( 'Queued', 'wp-cron-v2' ); ?></option>
                        <option value="running" <?php selected( $status_filter, 'running' ); ?>><?php esc_html_e( 'Running', 'wp-cron-v2' ); ?></option>
                        <option value="completed" <?php selected( $status_filter, 'completed' ); ?>><?php esc_html_e( 'Completed', 'wp-cron-v2' ); ?></option>
                        <option value="failed" <?php selected( $status_filter, 'failed' ); ?>><?php esc_html_e( 'Failed', 'wp-cron-v2' ); ?></option>
                    </select>

                    <select name="queue">
                        <option value=""><?php esc_html_e( 'All queues', 'wp-cron-v2' ); ?></option>
                        <?php foreach ( $queues as $q ) : ?>
                            <option value="<?php echo esc_attr( $q ); ?>" <?php selected( $queue_filter, $q ); ?>><?php echo esc_html( $q ); ?></option>
                        <?php endforeach; ?>
                    </select>

                    <button type="submit" class="button"><?php esc_html_e( 'Filter', 'wp-cron-v2' ); ?></button>
                </form>
            </div>

            <!-- Job list -->
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th style="width: 50px;">ID</th>
                        <th><?php esc_html_e( 'Job', 'wp-cron-v2' ); ?></th>
                        <th style="width: 100px;"><?php esc_html_e( 'Queue', 'wp-cron-v2' ); ?></th>
                        <th style="width: 80px;"><?php esc_html_e( 'Priority', 'wp-cron-v2' ); ?></th>
                        <th style="width: 100px;"><?php esc_html_e( 'Status', 'wp-cron-v2' ); ?></th>
                        <th style="width: 80px;"><?php esc_html_e( 'Attempts', 'wp-cron-v2' ); ?></th>
                        <th style="width: 150px;"><?php esc_html_e( 'Created', 'wp-cron-v2' ); ?></th>
                        <th style="width: 120px;"><?php esc_html_e( 'Actions', 'wp-cron-v2' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ( empty( $jobs ) ) : ?>
                        <tr>
                            <td colspan="8"><?php esc_html_e( 'No jobs.', 'wp-cron-v2' ); ?></td>
                        </tr>
                    <?php else : ?>
                        <?php foreach ( $jobs as $job ) : ?>
                            <tr data-job-id="<?php echo esc_attr( $job['id'] ); ?>">
                                <td><?php echo esc_html( $job['id'] ); ?></td>
                                <td>
                                    <strong><?php echo esc_html( $this->get_short_class_name( $job['job_type'] ) ); ?></strong>
                                    <?php if ( $job['error_message'] ) : ?>
                                        <br><small class="error-message"><?php echo esc_html( $job['error_message'] ); ?></small>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo esc_html( $job['queue'] ); ?></td>
                                <td>
                                    <span class="priority-badge priority-<?php echo esc_attr( $job['priority'] ); ?>">
                                        <?php echo esc_html( $job['priority'] ); ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="status-badge status-<?php echo esc_attr( $job['status'] ); ?>">
                                        <?php echo esc_html( $job['status'] ); ?>
                                    </span>
                                </td>
                                <td><?php echo esc_html( $job['attempts'] . '/' . $job['max_attempts'] ); ?></td>
                                <td><?php echo esc_html( $job['created_at'] ); ?></td>
                                <td>
                                    <?php if ( $job['status'] === 'failed' ) : ?>
                                        <button class="button button-small retry-job" data-id="<?php echo esc_attr( $job['id'] ); ?>">
                                            <?php esc_html_e( 'Retry', 'wp-cron-v2' ); ?>
                                        </button>
                                    <?php endif; ?>
                                    <?php if ( $job['status'] === 'queued' ) : ?>
                                        <button class="button button-small cancel-job" data-id="<?php echo esc_attr( $job['id'] ); ?>">
                                            <?php esc_html_e( 'Cancel', 'wp-cron-v2' ); ?>
                                        </button>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    /**
     * Render settings page
     */
    public function render_settings_page(): void {
        // Save settings
        if ( isset( $_POST['wp_cron_v2_save_settings'] ) && wp_verify_nonce( $_POST['_wpnonce'], 'wp_cron_v2_settings' ) ) {
            $settings = [
                'enable_wp_cron_adapter' => ! empty( $_POST['enable_wp_cron_adapter'] ),
                'default_queue' => sanitize_key( $_POST['default_queue'] ?? 'default' ),
                'max_attempts' => absint( $_POST['max_attempts'] ?? 3 ),
            ];
            update_option( 'wp_cron_v2_settings', $settings );
            echo '<div class="notice notice-success"><p>' . esc_html__( 'Settings saved.', 'wp-cron-v2' ) . '</p></div>';
        }

        $settings = get_option( 'wp_cron_v2_settings', [] );
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'WP Cron v2 - Settings', 'wp-cron-v2' ); ?></h1>

            <form method="post">
                <?php wp_nonce_field( 'wp_cron_v2_settings' ); ?>

                <table class="form-table">
                    <tr>
                        <th scope="row"><?php esc_html_e( 'WP-Cron Adapter', 'wp-cron-v2' ); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="enable_wp_cron_adapter" value="1" <?php checked( ! empty( $settings['enable_wp_cron_adapter'] ) ); ?>>
                                <?php esc_html_e( 'Route legacy wp_schedule_event() calls to WP Cron v2', 'wp-cron-v2' ); ?>
                            </label>
                            <p class="description"><?php esc_html_e( 'When enabled, cron tasks from legacy plugins are automatically routed to the WP Cron v2 queue.', 'wp-cron-v2' ); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Default Queue', 'wp-cron-v2' ); ?></th>
                        <td>
                            <input type="text" name="default_queue" value="<?php echo esc_attr( $settings['default_queue'] ?? 'default' ); ?>" class="regular-text">
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Max Attempts', 'wp-cron-v2' ); ?></th>
                        <td>
                            <input type="number" name="max_attempts" value="<?php echo esc_attr( $settings['max_attempts'] ?? 3 ); ?>" min="1" max="10">
                        </td>
                    </tr>
                </table>

                <p class="submit">
                    <button type="submit" name="wp_cron_v2_save_settings" class="button button-primary">
                        <?php esc_html_e( 'Save Settings', 'wp-cron-v2' ); ?>
                    </button>
                </p>
            </form>

            <hr>

            <h2><?php esc_html_e( 'Worker Commands', 'wp-cron-v2' ); ?></h2>
            <p><?php esc_html_e( 'Start the worker in terminal:', 'wp-cron-v2' ); ?></p>
            <pre style="background: #23282d; color: #fff; padding: 15px; border-radius: 4px;">wp cron-v2 worker --queue=default</pre>
        </div>
        <?php
    }

    /**
     * Get all queue stats combined
     *
     * @return array
     */
    private function get_all_stats(): array {
        global $wpdb;
        $table = $wpdb->prefix . 'job_queue';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $stats = $wpdb->get_results(
            "SELECT status, COUNT(*) as count FROM {$table} GROUP BY status",
            ARRAY_A
        );

        $result = [
            'queued' => 0,
            'running' => 0,
            'completed' => 0,
            'failed' => 0,
        ];

        foreach ( $stats as $stat ) {
            if ( isset( $result[ $stat['status'] ] ) ) {
                $result[ $stat['status'] ] = (int) $stat['count'];
            }
        }

        return $result;
    }

    /**
     * Short class name
     *
     * @param string $class
     * @return string
     */
    private function get_short_class_name( string $class ): string {
        $parts = explode( '\\', $class );
        return end( $parts );
    }

    /**
     * AJAX: Get stats
     */
    public function ajax_get_stats(): void {
        check_ajax_referer( 'wp_cron_v2_admin', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized' );
        }

        wp_send_json_success( $this->get_all_stats() );
    }

    /**
     * AJAX: Retry job
     */
    public function ajax_retry_job(): void {
        check_ajax_referer( 'wp_cron_v2_admin', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized' );
        }

        global $wpdb;
        $id = absint( $_POST['id'] ?? 0 );
        $table = $wpdb->prefix . 'job_queue';

        $result = $wpdb->update(
            $table,
            [
                'status' => 'queued',
                'attempts' => 0,
                'available_at' => current_time( 'mysql', true ),
                'updated_at' => current_time( 'mysql', true ),
                'error_message' => null,
            ],
            [ 'id' => $id, 'status' => 'failed' ]
        );

        if ( $result ) {
            wp_send_json_success();
        } else {
            wp_send_json_error( 'Job not found or not failed' );
        }
    }

    /**
     * AJAX: Cancel job
     */
    public function ajax_cancel_job(): void {
        check_ajax_referer( 'wp_cron_v2_admin', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized' );
        }

        global $wpdb;
        $id = absint( $_POST['id'] ?? 0 );
        $table = $wpdb->prefix . 'job_queue';

        $result = $wpdb->delete( $table, [ 'id' => $id, 'status' => 'queued' ] );

        if ( $result ) {
            wp_send_json_success();
        } else {
            wp_send_json_error( 'Job not found or not queued' );
        }
    }
}
