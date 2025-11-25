<?php
/**
 * REST API Controller
 *
 * @package WPCronV2\Api
 */

namespace WPCronV2\Api;

use WP_REST_Controller;
use WP_REST_Server;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

class RestController extends WP_REST_Controller {

    /**
     * Namespace
     *
     * @var string
     */
    protected $namespace = 'wp-cron-v2/v1';

    /**
     * Singleton instanssi
     *
     * @var RestController|null
     */
    private static ?RestController $instance = null;

    /**
     * Hae singleton instanssi
     *
     * @return RestController
     */
    public static function get_instance(): RestController {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Konstruktori
     */
    private function __construct() {
        add_action( 'rest_api_init', [ $this, 'register_routes' ] );
    }

    /**
     * Rekisteröi REST-reitit
     */
    public function register_routes(): void {
        // Tilastot
        register_rest_route( $this->namespace, '/stats', [
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [ $this, 'get_stats' ],
                'permission_callback' => [ $this, 'check_permissions' ],
            ],
        ] );

        // Health check
        register_rest_route( $this->namespace, '/health', [
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [ $this, 'get_health' ],
                'permission_callback' => [ $this, 'check_permissions' ],
            ],
        ] );

        // Jobit (listaus)
        register_rest_route( $this->namespace, '/jobs', [
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [ $this, 'get_jobs' ],
                'permission_callback' => [ $this, 'check_permissions' ],
                'args'                => [
                    'queue' => [
                        'type'              => 'string',
                        'sanitize_callback' => 'sanitize_text_field',
                    ],
                    'status' => [
                        'type'              => 'string',
                        'enum'              => [ 'queued', 'running', 'completed', 'failed' ],
                        'sanitize_callback' => 'sanitize_text_field',
                    ],
                    'limit' => [
                        'type'              => 'integer',
                        'default'           => 20,
                        'minimum'           => 1,
                        'maximum'           => 100,
                        'sanitize_callback' => 'absint',
                    ],
                    'offset' => [
                        'type'              => 'integer',
                        'default'           => 0,
                        'minimum'           => 0,
                        'sanitize_callback' => 'absint',
                    ],
                ],
            ],
        ] );

        // Yksittäinen job
        register_rest_route( $this->namespace, '/jobs/(?P<id>\d+)', [
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [ $this, 'get_job' ],
                'permission_callback' => [ $this, 'check_permissions' ],
            ],
            [
                'methods'             => WP_REST_Server::DELETABLE,
                'callback'            => [ $this, 'delete_job' ],
                'permission_callback' => [ $this, 'check_permissions' ],
            ],
        ] );

        // Job retry
        register_rest_route( $this->namespace, '/jobs/(?P<id>\d+)/retry', [
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [ $this, 'retry_job' ],
                'permission_callback' => [ $this, 'check_permissions' ],
            ],
        ] );

        // Jonot
        register_rest_route( $this->namespace, '/queues', [
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [ $this, 'get_queues' ],
                'permission_callback' => [ $this, 'check_permissions' ],
            ],
        ] );

        // Schedulet
        register_rest_route( $this->namespace, '/schedules', [
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [ $this, 'get_schedules' ],
                'permission_callback' => [ $this, 'check_permissions' ],
            ],
        ] );

        // Batchit
        register_rest_route( $this->namespace, '/batches', [
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [ $this, 'get_batches' ],
                'permission_callback' => [ $this, 'check_permissions' ],
            ],
        ] );

        // Chainit
        register_rest_route( $this->namespace, '/chains', [
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [ $this, 'get_chains' ],
                'permission_callback' => [ $this, 'check_permissions' ],
            ],
        ] );

        // Bulk actions
        register_rest_route( $this->namespace, '/actions/retry-failed', [
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [ $this, 'action_retry_failed' ],
                'permission_callback' => [ $this, 'check_permissions' ],
                'args'                => [
                    'queue' => [
                        'type'              => 'string',
                        'sanitize_callback' => 'sanitize_text_field',
                    ],
                    'limit' => [
                        'type'              => 'integer',
                        'default'           => 100,
                        'sanitize_callback' => 'absint',
                    ],
                ],
            ],
        ] );

        register_rest_route( $this->namespace, '/actions/flush-failed', [
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [ $this, 'action_flush_failed' ],
                'permission_callback' => [ $this, 'check_permissions' ],
                'args'                => [
                    'queue' => [
                        'type'              => 'string',
                        'sanitize_callback' => 'sanitize_text_field',
                    ],
                ],
            ],
        ] );

        register_rest_route( $this->namespace, '/actions/release-stale', [
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [ $this, 'action_release_stale' ],
                'permission_callback' => [ $this, 'check_permissions' ],
                'args'                => [
                    'timeout' => [
                        'type'              => 'integer',
                        'default'           => 30,
                        'sanitize_callback' => 'absint',
                    ],
                ],
            ],
        ] );
    }

    /**
     * Tarkista oikeudet
     *
     * @param WP_REST_Request $request
     * @return bool|WP_Error
     */
    public function check_permissions( WP_REST_Request $request ) {
        if ( ! current_user_can( 'manage_options' ) ) {
            return new WP_Error(
                'rest_forbidden',
                __( 'Sinulla ei ole oikeuksia tähän toimintoon.', 'wp-cron-v2' ),
                [ 'status' => 403 ]
            );
        }
        return true;
    }

    /**
     * Hae tilastot
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public function get_stats( WP_REST_Request $request ): WP_REST_Response {
        global $wpdb;

        $table = $wpdb->prefix . 'job_queue';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $stats = $wpdb->get_row(
            "SELECT
                COUNT(*) as total,
                SUM(CASE WHEN status = 'queued' THEN 1 ELSE 0 END) as queued,
                SUM(CASE WHEN status = 'running' THEN 1 ELSE 0 END) as running,
                SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed,
                SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed
            FROM {$table}",
            ARRAY_A
        );

        // Jonokohtaiset tilastot
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $queues = $wpdb->get_results(
            "SELECT queue,
                COUNT(*) as total,
                SUM(CASE WHEN status = 'queued' THEN 1 ELSE 0 END) as queued,
                SUM(CASE WHEN status = 'running' THEN 1 ELSE 0 END) as running,
                SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed,
                SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed
            FROM {$table}
            GROUP BY queue",
            ARRAY_A
        );

        return new WP_REST_Response( [
            'total'   => (int) $stats['total'],
            'queued'  => (int) $stats['queued'],
            'running' => (int) $stats['running'],
            'completed' => (int) $stats['completed'],
            'failed'  => (int) $stats['failed'],
            'queues'  => $queues,
        ] );
    }

    /**
     * Hae health status
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public function get_health( WP_REST_Request $request ): WP_REST_Response {
        global $wpdb;

        $table = $wpdb->prefix . 'job_queue';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $stats = $wpdb->get_row(
            "SELECT
                SUM(CASE WHEN status = 'queued' THEN 1 ELSE 0 END) as queued,
                SUM(CASE WHEN status = 'running' THEN 1 ELSE 0 END) as running,
                SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed
            FROM {$table}",
            ARRAY_A
        );

        // Stale jobs (SQLite-yhteensopiva)
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $stale_cutoff = gmdate( 'Y-m-d H:i:s', time() - 1800 ); // 30 min
        $stale = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$table}
                WHERE status = 'running' AND updated_at < %s",
                $stale_cutoff
            )
        );

        // Vanhin jonossa
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $oldest_date = $wpdb->get_var(
            "SELECT created_at FROM {$table}
            WHERE status = 'queued'
            ORDER BY created_at ASC
            LIMIT 1"
        );

        $oldest_minutes = null;
        if ( $oldest_date ) {
            $oldest_minutes = (int) ( ( time() - strtotime( $oldest_date ) ) / 60 );
        }

        $issues = [];

        if ( (int) $stale > 0 ) {
            $issues[] = [
                'type'    => 'stale_jobs',
                'message' => sprintf( '%d jumittunutta jobia', $stale ),
                'count'   => (int) $stale,
            ];
        }

        if ( (int) $stats['failed'] > 10 ) {
            $issues[] = [
                'type'    => 'failed_jobs',
                'message' => sprintf( '%d epäonnistunutta jobia', $stats['failed'] ),
                'count'   => (int) $stats['failed'],
            ];
        }

        if ( $oldest_minutes && $oldest_minutes > 60 ) {
            $issues[] = [
                'type'    => 'queue_delay',
                'message' => sprintf( 'Vanhin job jonossa %d min', $oldest_minutes ),
                'minutes' => $oldest_minutes,
            ];
        }

        return new WP_REST_Response( [
            'status'         => empty( $issues ) ? 'healthy' : 'warning',
            'queued'         => (int) $stats['queued'],
            'running'        => (int) $stats['running'],
            'failed'         => (int) $stats['failed'],
            'stale'          => (int) $stale,
            'oldest_minutes' => $oldest_minutes,
            'issues'         => $issues,
        ] );
    }

    /**
     * Hae jobit
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public function get_jobs( WP_REST_Request $request ): WP_REST_Response {
        global $wpdb;

        $table = $wpdb->prefix . 'job_queue';
        $queue = $request->get_param( 'queue' );
        $status = $request->get_param( 'status' );
        $limit = $request->get_param( 'limit' );
        $offset = $request->get_param( 'offset' );

        $where = '1=1';

        if ( $queue ) {
            $where .= $wpdb->prepare( ' AND queue = %s', $queue );
        }

        if ( $status ) {
            $where .= $wpdb->prepare( ' AND status = %s', $status );
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $jobs = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, job_type, queue, priority, status, attempts, max_attempts,
                        available_at, created_at, updated_at, error_message, batch_id
                FROM {$table}
                WHERE {$where}
                ORDER BY created_at DESC
                LIMIT %d OFFSET %d",
                $limit,
                $offset
            ),
            ARRAY_A
        );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $total = $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE {$where}" );

        // Muokkaa job_type luettavammaksi
        foreach ( $jobs as &$job ) {
            $parts = explode( '\\', $job['job_type'] );
            $job['job_name'] = end( $parts );
        }

        return new WP_REST_Response( [
            'jobs'  => $jobs,
            'total' => (int) $total,
            'limit' => $limit,
            'offset' => $offset,
        ] );
    }

    /**
     * Hae yksittäinen job
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    public function get_job( WP_REST_Request $request ) {
        global $wpdb;

        $id = (int) $request->get_param( 'id' );
        $table = $wpdb->prefix . 'job_queue';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $job = $wpdb->get_row(
            $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ),
            ARRAY_A
        );

        if ( ! $job ) {
            return new WP_Error(
                'not_found',
                __( 'Jobia ei löydy.', 'wp-cron-v2' ),
                [ 'status' => 404 ]
            );
        }

        $parts = explode( '\\', $job['job_type'] );
        $job['job_name'] = end( $parts );

        return new WP_REST_Response( $job );
    }

    /**
     * Poista job
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    public function delete_job( WP_REST_Request $request ) {
        global $wpdb;

        $id = (int) $request->get_param( 'id' );
        $table = $wpdb->prefix . 'job_queue';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $job = $wpdb->get_row(
            $wpdb->prepare( "SELECT status FROM {$table} WHERE id = %d", $id )
        );

        if ( ! $job ) {
            return new WP_Error(
                'not_found',
                __( 'Jobia ei löydy.', 'wp-cron-v2' ),
                [ 'status' => 404 ]
            );
        }

        if ( $job->status === 'running' ) {
            return new WP_Error(
                'job_running',
                __( 'Käynnissä olevaa jobia ei voi poistaa.', 'wp-cron-v2' ),
                [ 'status' => 400 ]
            );
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $wpdb->delete( $table, [ 'id' => $id ] );

        return new WP_REST_Response( [
            'deleted' => true,
            'id'      => $id,
        ] );
    }

    /**
     * Yritä jobia uudelleen
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    public function retry_job( WP_REST_Request $request ) {
        global $wpdb;

        $id = (int) $request->get_param( 'id' );
        $table = $wpdb->prefix . 'job_queue';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $job = $wpdb->get_row(
            $wpdb->prepare( "SELECT status FROM {$table} WHERE id = %d", $id )
        );

        if ( ! $job ) {
            return new WP_Error(
                'not_found',
                __( 'Jobia ei löydy.', 'wp-cron-v2' ),
                [ 'status' => 404 ]
            );
        }

        if ( $job->status !== 'failed' ) {
            return new WP_Error(
                'not_failed',
                __( 'Vain epäonnistuneen jobin voi yrittää uudelleen.', 'wp-cron-v2' ),
                [ 'status' => 400 ]
            );
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $wpdb->update(
            $table,
            [
                'status'       => 'queued',
                'attempts'     => 0,
                'available_at' => current_time( 'mysql', true ),
                'updated_at'   => current_time( 'mysql', true ),
            ],
            [ 'id' => $id ]
        );

        return new WP_REST_Response( [
            'retried' => true,
            'id'      => $id,
        ] );
    }

    /**
     * Hae jonot
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public function get_queues( WP_REST_Request $request ): WP_REST_Response {
        global $wpdb;

        $table = $wpdb->prefix . 'job_queue';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $queues = $wpdb->get_results(
            "SELECT queue,
                COUNT(*) as total,
                SUM(CASE WHEN status = 'queued' THEN 1 ELSE 0 END) as queued,
                SUM(CASE WHEN status = 'running' THEN 1 ELSE 0 END) as running,
                SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed,
                SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed
            FROM {$table}
            GROUP BY queue
            ORDER BY queue",
            ARRAY_A
        );

        return new WP_REST_Response( $queues );
    }

    /**
     * Hae schedulet
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public function get_schedules( WP_REST_Request $request ): WP_REST_Response {
        $schedules = wp_cron_v2_scheduler()->get_schedules();

        $data = [];
        foreach ( $schedules as $name => $schedule ) {
            $parts = explode( '\\', $schedule['job_class'] );
            $data[] = [
                'name'     => $name,
                'job_name' => end( $parts ),
                'interval' => $schedule['interval'],
                'queue'    => $schedule['queue'],
                'enabled'  => $schedule['enabled'],
                'next_run' => $schedule['next_run'],
                'last_run' => $schedule['last_run'],
            ];
        }

        return new WP_REST_Response( $data );
    }

    /**
     * Hae batchit
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public function get_batches( WP_REST_Request $request ): WP_REST_Response {
        $batches = \WPCronV2\Queue\Batch::all( 50 );

        $data = [];
        foreach ( $batches as $batch ) {
            $stats = \WPCronV2\Queue\Batch::getStats( $batch['id'] );
            $data[] = [
                'id'         => $batch['id'],
                'name'       => $batch['name'],
                'total_jobs' => $batch['total_jobs'],
                'stats'      => $stats,
                'created_at' => $batch['created_at'],
                'finished_at' => $batch['finished_at'],
            ];
        }

        return new WP_REST_Response( $data );
    }

    /**
     * Hae chainit
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public function get_chains( WP_REST_Request $request ): WP_REST_Response {
        $chains = \WPCronV2\Queue\Chain::all();

        $data = [];
        foreach ( $chains as $chain ) {
            $data[] = [
                'id'            => $chain['id'],
                'name'          => $chain['name'],
                'queue'         => $chain['queue'],
                'total_jobs'    => $chain['total_jobs'],
                'current_index' => $chain['current_index'],
                'status'        => $chain['status'],
                'created_at'    => $chain['created_at'],
                'finished_at'   => $chain['finished_at'] ?? null,
            ];
        }

        return new WP_REST_Response( $data );
    }

    /**
     * Yritä epäonnistuneet uudelleen
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public function action_retry_failed( WP_REST_Request $request ): WP_REST_Response {
        global $wpdb;

        $queue = $request->get_param( 'queue' );
        $limit = $request->get_param( 'limit' );
        $table = $wpdb->prefix . 'job_queue';

        $where = "status = 'failed'";

        if ( $queue ) {
            $where .= $wpdb->prepare( ' AND queue = %s', $queue );
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $count = $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$table}
                SET status = 'queued', attempts = 0, available_at = %s, updated_at = %s
                WHERE {$where}
                LIMIT %d",
                current_time( 'mysql', true ),
                current_time( 'mysql', true ),
                $limit
            )
        );

        return new WP_REST_Response( [
            'retried' => (int) $count,
        ] );
    }

    /**
     * Tyhjennä epäonnistuneet
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public function action_flush_failed( WP_REST_Request $request ): WP_REST_Response {
        global $wpdb;

        $queue = $request->get_param( 'queue' );
        $table = $wpdb->prefix . 'job_queue';

        $where = "status = 'failed'";

        if ( $queue ) {
            $where .= $wpdb->prepare( ' AND queue = %s', $queue );
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $count = $wpdb->query( "DELETE FROM {$table} WHERE {$where}" );

        return new WP_REST_Response( [
            'deleted' => (int) $count,
        ] );
    }

    /**
     * Vapauta jumittuneet jobit
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public function action_release_stale( WP_REST_Request $request ): WP_REST_Response {
        $timeout = $request->get_param( 'timeout' );

        $released = wp_cron_v2()->release_stale_jobs( $timeout );

        return new WP_REST_Response( [
            'released' => $released,
        ] );
    }
}
