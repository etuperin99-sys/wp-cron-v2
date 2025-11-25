<?php
/**
 * WP-CLI commands
 *
 * @package WPCronV2\Includes
 */

namespace WPCronV2\Includes;

use WP_CLI;
use WP_CLI_Command;

/**
 * WP Cron v2 worker and queue management commands
 */
class CLI_Commands extends WP_CLI_Command {

    /**
     * Start worker to process queue
     *
     * ## OPTIONS
     *
     * [--queue=<queue>]
     * : Queue name
     * ---
     * default: default
     * ---
     *
     * [--sleep=<seconds>]
     * : Sleep time in seconds when queue is empty
     * ---
     * default: 3
     * ---
     *
     * [--max-jobs=<number>]
     * : Maximum jobs before stopping (0 = unlimited)
     * ---
     * default: 0
     * ---
     *
     * [--timeout=<seconds>]
     * : Maximum runtime in seconds (0 = unlimited)
     * ---
     * default: 0
     * ---
     *
     * ## EXAMPLES
     *
     *     # Start worker for default queue
     *     wp cron-v2 worker --queue=default
     *
     *     # Start worker for WooCommerce queue
     *     wp cron-v2 worker --queue=woocommerce --sleep=1
     *
     * @param array $args
     * @param array $assoc_args
     */
    public function worker( $args, $assoc_args ) {
        $queue = $assoc_args['queue'] ?? 'default';
        $sleep = (int) ( $assoc_args['sleep'] ?? 3 );
        $max_jobs = (int) ( $assoc_args['max-jobs'] ?? 0 );
        $timeout = (int) ( $assoc_args['timeout'] ?? 0 );

        $worker_id = 'worker-' . wp_generate_uuid4();
        $start_time = time();
        $processed = 0;

        WP_CLI::log( "Worker {$worker_id} started for queue '{$queue}'" );

        while ( true ) {
            // Check timeout
            if ( $timeout > 0 && ( time() - $start_time ) >= $timeout ) {
                WP_CLI::success( "Timeout reached. Processed {$processed} jobs." );
                break;
            }

            // Check max jobs
            if ( $max_jobs > 0 && $processed >= $max_jobs ) {
                WP_CLI::success( "Maximum job count reached: {$processed}" );
                break;
            }

            // Process next job
            $result = wp_cron_v2()->process_next_job( $queue );

            if ( $result ) {
                $processed++;
                WP_CLI::log( "Job processed. Total: {$processed}" );
            } else {
                // No jobs, wait
                sleep( $sleep );
            }

            // Check signals (SIGTERM, SIGINT)
            if ( function_exists( 'pcntl_signal_dispatch' ) ) {
                pcntl_signal_dispatch();
            }
        }
    }

    /**
     * Show queue statistics
     *
     * ## OPTIONS
     *
     * [--queue=<queue>]
     * : Queue name (empty = all)
     *
     * [--format=<format>]
     * : Output format
     * ---
     * default: table
     * options:
     *   - table
     *   - json
     *   - csv
     * ---
     *
     * ## EXAMPLES
     *
     *     wp cron-v2 stats
     *     wp cron-v2 stats --queue=emails --format=json
     *
     * @param array $args
     * @param array $assoc_args
     */
    public function stats( $args, $assoc_args ) {
        global $wpdb;

        $queue = $assoc_args['queue'] ?? null;
        $format = $assoc_args['format'] ?? 'table';
        $table = $wpdb->prefix . 'job_queue';

        if ( $queue ) {
            $stats = wp_cron_v2()->get_stats( $queue );
            $data = [
                [
                    'queue' => $queue,
                    'queued' => $stats['queued'],
                    'running' => $stats['running'],
                    'completed' => $stats['completed'],
                    'failed' => $stats['failed'],
                ]
            ];
        } else {
            // Get all queue statistics
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $queues = $wpdb->get_col( "SELECT DISTINCT queue FROM {$table}" );
            $data = [];

            foreach ( $queues as $q ) {
                $stats = wp_cron_v2()->get_stats( $q );
                $data[] = [
                    'queue' => $q,
                    'queued' => $stats['queued'],
                    'running' => $stats['running'],
                    'completed' => $stats['completed'],
                    'failed' => $stats['failed'],
                ];
            }
        }

        if ( empty( $data ) ) {
            WP_CLI::log( 'No jobs in queue.' );
            return;
        }

        WP_CLI\Utils\format_items( $format, $data, [ 'queue', 'queued', 'running', 'completed', 'failed' ] );
    }

    /**
     * Flush failed jobs
     *
     * ## OPTIONS
     *
     * [--queue=<queue>]
     * : Queue name (empty = all)
     *
     * [--older-than=<days>]
     * : Delete only older than this (in days)
     * ---
     * default: 0
     * ---
     *
     * ## EXAMPLES
     *
     *     wp cron-v2 flush-failed
     *     wp cron-v2 flush-failed --queue=emails --older-than=7
     *
     * @param array $args
     * @param array $assoc_args
     */
    public function flush_failed( $args, $assoc_args ) {
        global $wpdb;

        $queue = $assoc_args['queue'] ?? null;
        $older_than = (int) ( $assoc_args['older-than'] ?? 0 );
        $table = $wpdb->prefix . 'job_queue';

        $where = "status = 'failed'";

        if ( $queue ) {
            $where .= $wpdb->prepare( ' AND queue = %s', $queue );
        }

        if ( $older_than > 0 ) {
            $date = gmdate( 'Y-m-d H:i:s', strtotime( "-{$older_than} days" ) );
            $where .= $wpdb->prepare( ' AND updated_at < %s', $date );
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $count = $wpdb->query( "DELETE FROM {$table} WHERE {$where}" );

        WP_CLI::success( "Deleted {$count} failed jobs." );
    }

    /**
     * Retry failed jobs
     *
     * ## OPTIONS
     *
     * [--queue=<queue>]
     * : Queue name (empty = all)
     *
     * [--limit=<number>]
     * : Maximum count
     * ---
     * default: 100
     * ---
     *
     * ## EXAMPLES
     *
     *     wp cron-v2 retry-failed
     *     wp cron-v2 retry-failed --queue=emails --limit=50
     *
     * @param array $args
     * @param array $assoc_args
     */
    public function retry_failed( $args, $assoc_args ) {
        global $wpdb;

        $queue = $assoc_args['queue'] ?? null;
        $limit = (int) ( $assoc_args['limit'] ?? 100 );
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

        WP_CLI::success( "Returned {$count} jobs to queue for retry." );
    }

    /**
     * List jobs in queue
     *
     * ## OPTIONS
     *
     * [--queue=<queue>]
     * : Queue name (empty = all)
     *
     * [--status=<status>]
     * : Filter by status (queued, running, completed, failed)
     *
     * [--limit=<number>]
     * : Number of jobs to show
     * ---
     * default: 20
     * ---
     *
     * [--format=<format>]
     * : Output format
     * ---
     * default: table
     * options:
     *   - table
     *   - json
     *   - csv
     * ---
     *
     * ## EXAMPLES
     *
     *     wp cron-v2 list
     *     wp cron-v2 list --status=failed
     *     wp cron-v2 list --queue=emails --limit=50
     *
     * @subcommand list
     * @param array $args
     * @param array $assoc_args
     */
    public function list_jobs( $args, $assoc_args ) {
        global $wpdb;

        $queue = $assoc_args['queue'] ?? null;
        $status = $assoc_args['status'] ?? null;
        $limit = (int) ( $assoc_args['limit'] ?? 20 );
        $format = $assoc_args['format'] ?? 'table';
        $table = $wpdb->prefix . 'job_queue';

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
                "SELECT id, job_type, queue, priority, status, attempts, max_attempts, created_at, error_message
                FROM {$table}
                WHERE {$where}
                ORDER BY created_at DESC
                LIMIT %d",
                $limit
            ),
            ARRAY_A
        );

        if ( empty( $jobs ) ) {
            WP_CLI::log( 'No jobs.' );
            return;
        }

        // Shorten job_type for display
        foreach ( $jobs as &$job ) {
            $parts = explode( '\\', $job['job_type'] );
            $job['job_type'] = end( $parts );
            $job['error_message'] = $job['error_message'] ? substr( $job['error_message'], 0, 40 ) . '...' : '';
        }

        WP_CLI\Utils\format_items( $format, $jobs, [ 'id', 'job_type', 'queue', 'priority', 'status', 'attempts', 'created_at', 'error_message' ] );
    }

    /**
     * Delete completed jobs
     *
     * ## OPTIONS
     *
     * [--queue=<queue>]
     * : Queue name (empty = all)
     *
     * [--older-than=<days>]
     * : Delete only older than this (in days)
     * ---
     * default: 0
     * ---
     *
     * ## EXAMPLES
     *
     *     wp cron-v2 purge-completed
     *     wp cron-v2 purge-completed --older-than=7
     *
     * @subcommand purge-completed
     * @param array $args
     * @param array $assoc_args
     */
    public function purge_completed( $args, $assoc_args ) {
        global $wpdb;

        $queue = $assoc_args['queue'] ?? null;
        $older_than = (int) ( $assoc_args['older-than'] ?? 0 );
        $table = $wpdb->prefix . 'job_queue';

        $where = "status = 'completed'";

        if ( $queue ) {
            $where .= $wpdb->prepare( ' AND queue = %s', $queue );
        }

        if ( $older_than > 0 ) {
            $date = gmdate( 'Y-m-d H:i:s', strtotime( "-{$older_than} days" ) );
            $where .= $wpdb->prepare( ' AND updated_at < %s', $date );
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $count = $wpdb->query( "DELETE FROM {$table} WHERE {$where}" );

        WP_CLI::success( "Deleted {$count} completed jobs." );
    }

    /**
     * Show single job details
     *
     * ## OPTIONS
     *
     * <id>
     * : Job ID
     *
     * [--format=<format>]
     * : Output format
     * ---
     * default: table
     * options:
     *   - table
     *   - json
     *   - yaml
     * ---
     *
     * ## EXAMPLES
     *
     *     wp cron-v2 show 123
     *
     * @param array $args
     * @param array $assoc_args
     */
    public function show( $args, $assoc_args ) {
        global $wpdb;

        $id = (int) $args[0];
        $format = $assoc_args['format'] ?? 'table';
        $table = $wpdb->prefix . 'job_queue';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $job = $wpdb->get_row(
            $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ),
            ARRAY_A
        );

        if ( ! $job ) {
            WP_CLI::error( "Job ID {$id} not found." );
        }

        if ( $format === 'table' ) {
            $data = [];
            foreach ( $job as $key => $value ) {
                $data[] = [ 'field' => $key, 'value' => $value ];
            }
            WP_CLI\Utils\format_items( 'table', $data, [ 'field', 'value' ] );
        } else {
            WP_CLI\Utils\format_items( $format, [ $job ], array_keys( $job ) );
        }
    }

    /**
     * Cancel job from queue
     *
     * ## OPTIONS
     *
     * <id>...
     * : Job ID(s)
     *
     * ## EXAMPLES
     *
     *     wp cron-v2 cancel 123
     *     wp cron-v2 cancel 123 124 125
     *
     * @param array $args
     * @param array $assoc_args
     */
    public function cancel( $args, $assoc_args ) {
        global $wpdb;

        $table = $wpdb->prefix . 'job_queue';
        $cancelled = 0;

        foreach ( $args as $id ) {
            $id = (int) $id;

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $result = $wpdb->delete(
                $table,
                [
                    'id' => $id,
                    'status' => 'queued'
                ]
            );

            if ( $result ) {
                $cancelled++;
                WP_CLI::log( "Job {$id} cancelled." );
            } else {
                WP_CLI::warning( "Job {$id} could not be cancelled (not in queue or not found)." );
            }
        }

        WP_CLI::success( "Cancelled {$cancelled} jobs." );
    }

    /**
     * Run single job immediately (bypass queue)
     *
     * ## OPTIONS
     *
     * <id>
     * : Job ID
     *
     * ## EXAMPLES
     *
     *     wp cron-v2 run 123
     *
     * @param array $args
     * @param array $assoc_args
     */
    public function run( $args, $assoc_args ) {
        global $wpdb;

        $id = (int) $args[0];
        $table = $wpdb->prefix . 'job_queue';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $job_row = $wpdb->get_row(
            $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ),
            ARRAY_A
        );

        if ( ! $job_row ) {
            WP_CLI::error( "Job ID {$id} not found." );
        }

        if ( $job_row['status'] === 'running' ) {
            WP_CLI::error( "Job {$id} is already running." );
        }

        if ( $job_row['status'] === 'completed' ) {
            WP_CLI::error( "Job {$id} is already completed." );
        }

        // Mark as running
        $wpdb->update(
            $table,
            [ 'status' => 'running', 'updated_at' => current_time( 'mysql', true ) ],
            [ 'id' => $id ]
        );

        WP_CLI::log( "Running job {$id}..." );

        try {
            $job = maybe_unserialize( $job_row['payload'] );

            if ( ! is_object( $job ) || ! method_exists( $job, 'handle' ) ) {
                throw new \Exception( 'Invalid job payload' );
            }

            $start = microtime( true );
            $job->handle();
            $duration = round( ( microtime( true ) - $start ) * 1000 );

            $wpdb->update(
                $table,
                [ 'status' => 'completed', 'updated_at' => current_time( 'mysql', true ) ],
                [ 'id' => $id ]
            );

            WP_CLI::success( "Job {$id} executed successfully ({$duration}ms)." );

        } catch ( \Throwable $e ) {
            $wpdb->update(
                $table,
                [
                    'status' => 'failed',
                    'error_message' => $e->getMessage(),
                    'updated_at' => current_time( 'mysql', true )
                ],
                [ 'id' => $id ]
            );

            WP_CLI::error( "Job {$id} failed: " . $e->getMessage() );
        }
    }

    /**
     * Process all queued jobs at once
     *
     * ## OPTIONS
     *
     * [--queue=<queue>]
     * : Queue name
     * ---
     * default: default
     * ---
     *
     * [--limit=<number>]
     * : Maximum jobs to process
     * ---
     * default: 100
     * ---
     *
     * ## EXAMPLES
     *
     *     wp cron-v2 work
     *     wp cron-v2 work --queue=emails --limit=50
     *
     * @param array $args
     * @param array $assoc_args
     */
    public function work( $args, $assoc_args ) {
        $queue = $assoc_args['queue'] ?? 'default';
        $limit = (int) ( $assoc_args['limit'] ?? 100 );
        $processed = 0;
        $failed = 0;

        WP_CLI::log( "Processing queue '{$queue}'..." );

        while ( $processed < $limit ) {
            $result = wp_cron_v2()->process_next_job( $queue );

            if ( $result === false ) {
                // No more jobs
                break;
            }

            if ( $result ) {
                $processed++;
            } else {
                $failed++;
            }
        }

        WP_CLI::success( "Done. Processed: {$processed}, failed: {$failed}" );
    }

    /**
     * Show detailed queue statistics
     *
     * ## OPTIONS
     *
     * [--format=<format>]
     * : Output format
     * ---
     * default: table
     * options:
     *   - table
     *   - json
     * ---
     *
     * ## EXAMPLES
     *
     *     wp cron-v2 info
     *
     * @param array $args
     * @param array $assoc_args
     */
    public function info( $args, $assoc_args ) {
        global $wpdb;

        $format = $assoc_args['format'] ?? 'table';
        $table = $wpdb->prefix . 'job_queue';

        // General statistics
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $total = $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $oldest_queued = $wpdb->get_var(
            "SELECT created_at FROM {$table} WHERE status = 'queued' ORDER BY created_at ASC LIMIT 1"
        );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $avg_attempts = $wpdb->get_var(
            "SELECT AVG(attempts) FROM {$table} WHERE status = 'completed'"
        );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $queues = $wpdb->get_results(
            "SELECT queue, COUNT(*) as total,
                SUM(CASE WHEN status = 'queued' THEN 1 ELSE 0 END) as queued,
                SUM(CASE WHEN status = 'running' THEN 1 ELSE 0 END) as running,
                SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed,
                SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed
            FROM {$table}
            GROUP BY queue",
            ARRAY_A
        );

        if ( $format === 'json' ) {
            WP_CLI::log( wp_json_encode( [
                'total_jobs' => (int) $total,
                'oldest_queued' => $oldest_queued,
                'avg_attempts' => round( (float) $avg_attempts, 2 ),
                'queues' => $queues,
            ], JSON_PRETTY_PRINT ) );
            return;
        }

        WP_CLI::log( '' );
        WP_CLI::log( WP_CLI::colorize( '%GWP Cron v2 Status%n' ) );
        WP_CLI::log( str_repeat( '─', 40 ) );
        WP_CLI::log( "Total jobs:        {$total}" );
        WP_CLI::log( "Oldest in queue:   " . ( $oldest_queued ?: '-' ) );
        WP_CLI::log( "Avg attempts:      " . ( $avg_attempts ? round( $avg_attempts, 2 ) : '-' ) );
        WP_CLI::log( '' );

        if ( ! empty( $queues ) ) {
            WP_CLI::log( WP_CLI::colorize( '%YQueues:%n' ) );
            WP_CLI\Utils\format_items( 'table', $queues, [ 'queue', 'total', 'queued', 'running', 'completed', 'failed' ] );
        }
    }

    /**
     * List scheduled tasks
     *
     * ## OPTIONS
     *
     * [--format=<format>]
     * : Output format
     * ---
     * default: table
     * options:
     *   - table
     *   - json
     * ---
     *
     * ## EXAMPLES
     *
     *     wp cron-v2 schedules
     *
     * @param array $args
     * @param array $assoc_args
     */
    public function schedules( $args, $assoc_args ) {
        $format = $assoc_args['format'] ?? 'table';
        $schedules = wp_cron_v2_scheduler()->get_schedules();

        if ( empty( $schedules ) ) {
            WP_CLI::log( 'No scheduled tasks.' );
            return;
        }

        $data = [];
        foreach ( $schedules as $name => $schedule ) {
            $parts = explode( '\\', $schedule['job_class'] );
            $data[] = [
                'name' => $name,
                'job' => end( $parts ),
                'interval' => $schedule['interval'],
                'queue' => $schedule['queue'],
                'enabled' => $schedule['enabled'] ? 'yes' : 'no',
                'next_run' => $schedule['next_run'] ? gmdate( 'Y-m-d H:i:s', $schedule['next_run'] ) : '-',
                'last_run' => $schedule['last_run'] ? gmdate( 'Y-m-d H:i:s', $schedule['last_run'] ) : '-',
            ];
        }

        WP_CLI\Utils\format_items( $format, $data, [ 'name', 'job', 'interval', 'queue', 'enabled', 'next_run', 'last_run' ] );
    }

    /**
     * Pause scheduled task
     *
     * ## OPTIONS
     *
     * <name>
     * : Schedule name
     *
     * ## EXAMPLES
     *
     *     wp cron-v2 pause-schedule cleanup-logs
     *
     * @subcommand pause-schedule
     * @param array $args
     * @param array $assoc_args
     */
    public function pause_schedule( $args, $assoc_args ) {
        $name = $args[0];

        if ( wp_cron_v2_scheduler()->pause( $name ) ) {
            WP_CLI::success( "Schedule '{$name}' paused." );
        } else {
            WP_CLI::error( "Schedule '{$name}' not found." );
        }
    }

    /**
     * Resume paused scheduled task
     *
     * ## OPTIONS
     *
     * <name>
     * : Schedule name
     *
     * ## EXAMPLES
     *
     *     wp cron-v2 resume-schedule cleanup-logs
     *
     * @subcommand resume-schedule
     * @param array $args
     * @param array $assoc_args
     */
    public function resume_schedule( $args, $assoc_args ) {
        $name = $args[0];

        if ( wp_cron_v2_scheduler()->resume( $name ) ) {
            WP_CLI::success( "Schedule '{$name}' resumed." );
        } else {
            WP_CLI::error( "Schedule '{$name}' not found." );
        }
    }

    /**
     * Remove scheduled task
     *
     * ## OPTIONS
     *
     * <name>
     * : Schedule name
     *
     * ## EXAMPLES
     *
     *     wp cron-v2 remove-schedule cleanup-logs
     *
     * @subcommand remove-schedule
     * @param array $args
     * @param array $assoc_args
     */
    public function remove_schedule( $args, $assoc_args ) {
        $name = $args[0];

        if ( wp_cron_v2_scheduler()->unschedule( $name ) ) {
            WP_CLI::success( "Schedule '{$name}' removed." );
        } else {
            WP_CLI::error( "Schedule '{$name}' not found." );
        }
    }

    /**
     * Release stuck jobs (stale/timeout)
     *
     * ## OPTIONS
     *
     * [--timeout=<minutes>]
     * : How many minutes in running state = timeout
     * ---
     * default: 30
     * ---
     *
     * ## EXAMPLES
     *
     *     wp cron-v2 release-stale
     *     wp cron-v2 release-stale --timeout=60
     *
     * @subcommand release-stale
     * @param array $args
     * @param array $assoc_args
     */
    public function release_stale( $args, $assoc_args ) {
        $timeout = (int) ( $assoc_args['timeout'] ?? 30 );

        $released = wp_cron_v2()->release_stale_jobs( $timeout );

        if ( $released > 0 ) {
            WP_CLI::success( "Released {$released} stuck jobs." );
        } else {
            WP_CLI::log( 'No stuck jobs.' );
        }
    }

    /**
     * Clean up old completed jobs
     *
     * ## OPTIONS
     *
     * [--days=<days>]
     * : Delete older than this (in days)
     * ---
     * default: 7
     * ---
     *
     * [--include-failed]
     * : Also delete failed jobs
     *
     * ## EXAMPLES
     *
     *     wp cron-v2 cleanup
     *     wp cron-v2 cleanup --days=30
     *     wp cron-v2 cleanup --days=7 --include-failed
     *
     * @param array $args
     * @param array $assoc_args
     */
    public function cleanup( $args, $assoc_args ) {
        global $wpdb;

        $days = (int) ( $assoc_args['days'] ?? 7 );
        $include_failed = isset( $assoc_args['include-failed'] );
        $table = $wpdb->prefix . 'job_queue';

        // Delete completed
        $deleted_completed = wp_cron_v2()->cleanup_old_jobs( $days );

        $deleted_failed = 0;
        if ( $include_failed ) {
            $cutoff = gmdate( 'Y-m-d H:i:s', strtotime( "-{$days} days" ) );

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $deleted_failed = $wpdb->query(
                $wpdb->prepare(
                    "DELETE FROM {$table}
                    WHERE status = 'failed'
                    AND updated_at < %s",
                    $cutoff
                )
            );
        }

        $total = $deleted_completed + $deleted_failed;

        WP_CLI::success( "Cleaned up {$total} jobs (completed: {$deleted_completed}, failed: {$deleted_failed})." );
    }

    /**
     * Show queue health status
     *
     * ## EXAMPLES
     *
     *     wp cron-v2 health
     *
     * @param array $args
     * @param array $assoc_args
     */
    public function health( $args, $assoc_args ) {
        global $wpdb;

        $table = $wpdb->prefix . 'job_queue';

        // Statistics
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $stats = $wpdb->get_row(
            "SELECT
                COUNT(*) as total,
                SUM(CASE WHEN status = 'queued' THEN 1 ELSE 0 END) as queued,
                SUM(CASE WHEN status = 'running' THEN 1 ELSE 0 END) as running,
                SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed,
                SUM(CASE WHEN status = 'running' AND updated_at < DATE_SUB(NOW(), INTERVAL 30 MINUTE) THEN 1 ELSE 0 END) as stale
            FROM {$table}"
        );

        // Oldest in queue (SQLite compatible)
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $oldest_date = $wpdb->get_var(
            "SELECT created_at
            FROM {$table}
            WHERE status = 'queued'
            ORDER BY created_at ASC
            LIMIT 1"
        );

        $oldest = null;
        if ( $oldest_date ) {
            $oldest = (int) ( ( time() - strtotime( $oldest_date ) ) / 60 );
        }

        $issues = [];

        if ( (int) $stats->stale > 0 ) {
            $issues[] = WP_CLI::colorize( "%R{$stats->stale} stuck jobs%n" );
        }

        if ( (int) $stats->failed > 10 ) {
            $issues[] = WP_CLI::colorize( "%Y{$stats->failed} failed jobs%n" );
        }

        if ( $oldest && (int) $oldest > 60 ) {
            $issues[] = WP_CLI::colorize( "%YOldest job in queue {$oldest} min%n" );
        }

        WP_CLI::log( '' );
        WP_CLI::log( WP_CLI::colorize( '%GWP Cron v2 Health Check%n' ) );
        WP_CLI::log( str_repeat( '─', 40 ) );
        WP_CLI::log( "Queued:     {$stats->queued}" );
        WP_CLI::log( "Running:    {$stats->running}" );
        WP_CLI::log( "Failed:     {$stats->failed}" );
        WP_CLI::log( "Stuck:      {$stats->stale}" );

        if ( $oldest ) {
            WP_CLI::log( "Oldest:     {$oldest} min" );
        }

        WP_CLI::log( '' );

        if ( empty( $issues ) ) {
            WP_CLI::success( 'All OK!' );
        } else {
            WP_CLI::log( WP_CLI::colorize( '%RIssues:%n' ) );
            foreach ( $issues as $issue ) {
                WP_CLI::log( "  - {$issue}" );
            }
            WP_CLI::log( '' );
            WP_CLI::log( 'Suggestions:' );
            WP_CLI::log( '  wp cron-v2 release-stale    # Release stuck jobs' );
            WP_CLI::log( '  wp cron-v2 retry-failed     # Retry failed jobs' );
            WP_CLI::log( '  wp cron-v2 worker           # Start worker' );
        }
    }

    /**
     * List batches
     *
     * ## OPTIONS
     *
     * [--limit=<number>]
     * : Number of batches to show
     * ---
     * default: 20
     * ---
     *
     * [--format=<format>]
     * : Output format
     * ---
     * default: table
     * options:
     *   - table
     *   - json
     * ---
     *
     * ## EXAMPLES
     *
     *     wp cron-v2 batches
     *
     * @param array $args
     * @param array $assoc_args
     */
    public function batches( $args, $assoc_args ) {
        $limit = (int) ( $assoc_args['limit'] ?? 20 );
        $format = $assoc_args['format'] ?? 'table';

        $batches = \WPCronV2\Queue\Batch::all( $limit );

        if ( empty( $batches ) ) {
            WP_CLI::log( 'No batches.' );
            return;
        }

        $data = [];
        foreach ( $batches as $batch ) {
            $stats = \WPCronV2\Queue\Batch::getStats( $batch['id'] );
            $data[] = [
                'id' => substr( $batch['id'], 0, 8 ) . '...',
                'name' => $batch['name'],
                'total' => $batch['total_jobs'],
                'completed' => $stats['completed'],
                'failed' => $stats['failed'],
                'progress' => $stats['progress'] . '%',
                'created_at' => $batch['created_at'],
            ];
        }

        WP_CLI\Utils\format_items( $format, $data, [ 'id', 'name', 'total', 'completed', 'failed', 'progress', 'created_at' ] );
    }

    /**
     * Show batch details
     *
     * ## OPTIONS
     *
     * <id>
     * : Batch ID (or beginning of it)
     *
     * ## EXAMPLES
     *
     *     wp cron-v2 batch-show abc12345
     *
     * @subcommand batch-show
     * @param array $args
     * @param array $assoc_args
     */
    public function batch_show( $args, $assoc_args ) {
        global $wpdb;

        $id = $args[0];
        $table = $wpdb->prefix . 'job_batches';

        // Find batch (with partial ID)
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $batch = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE id LIKE %s LIMIT 1",
                $id . '%'
            ),
            ARRAY_A
        );

        if ( ! $batch ) {
            WP_CLI::error( "Batch '{$id}' not found." );
        }

        $stats = \WPCronV2\Queue\Batch::getStats( $batch['id'] );

        WP_CLI::log( '' );
        WP_CLI::log( WP_CLI::colorize( '%GBatch: ' . $batch['name'] . '%n' ) );
        WP_CLI::log( str_repeat( '─', 40 ) );
        WP_CLI::log( "ID:         {$batch['id']}" );
        WP_CLI::log( "Created:    {$batch['created_at']}" );
        WP_CLI::log( "Finished:   " . ( $batch['finished_at'] ?: '-' ) );
        WP_CLI::log( '' );
        WP_CLI::log( WP_CLI::colorize( '%YStatistics:%n' ) );
        WP_CLI::log( "Total:      {$stats['total']}" );
        WP_CLI::log( "Queued:     {$stats['queued']}" );
        WP_CLI::log( "Running:    {$stats['running']}" );
        WP_CLI::log( "Completed:  {$stats['completed']}" );
        WP_CLI::log( "Failed:     {$stats['failed']}" );
        WP_CLI::log( "Progress:   {$stats['progress']}%" );
    }

    /**
     * Cancel batch
     *
     * ## OPTIONS
     *
     * <id>
     * : Batch ID (or beginning of it)
     *
     * ## EXAMPLES
     *
     *     wp cron-v2 batch-cancel abc12345
     *
     * @subcommand batch-cancel
     * @param array $args
     * @param array $assoc_args
     */
    public function batch_cancel( $args, $assoc_args ) {
        global $wpdb;

        $id = $args[0];
        $table = $wpdb->prefix . 'job_batches';

        // Find batch
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $batch = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT id FROM {$table} WHERE id LIKE %s LIMIT 1",
                $id . '%'
            )
        );

        if ( ! $batch ) {
            WP_CLI::error( "Batch '{$id}' not found." );
        }

        $cancelled = \WPCronV2\Queue\Batch::cancel( $batch->id );

        WP_CLI::success( "Cancelled {$cancelled} jobs from batch." );
    }

    /**
     * List job chains
     *
     * ## OPTIONS
     *
     * [--limit=<number>]
     * : Number of chains to show
     * ---
     * default: 20
     * ---
     *
     * [--format=<format>]
     * : Output format
     * ---
     * default: table
     * options:
     *   - table
     *   - json
     * ---
     *
     * ## EXAMPLES
     *
     *     wp cron-v2 chains
     *
     * @param array $args
     * @param array $assoc_args
     */
    public function chains( $args, $assoc_args ) {
        $format = $assoc_args['format'] ?? 'table';

        $chains = \WPCronV2\Queue\Chain::all();

        if ( empty( $chains ) ) {
            WP_CLI::log( 'No job chains.' );
            return;
        }

        $data = [];
        foreach ( $chains as $chain ) {
            $data[] = [
                'id' => substr( $chain['id'], 0, 8 ) . '...',
                'name' => $chain['name'],
                'total' => $chain['total_jobs'],
                'current' => $chain['current_index'] + 1,
                'status' => $chain['status'],
                'created_at' => $chain['created_at'],
            ];
        }

        WP_CLI\Utils\format_items( $format, $data, [ 'id', 'name', 'total', 'current', 'status', 'created_at' ] );
    }

    /**
     * Show chain details
     *
     * ## OPTIONS
     *
     * <id>
     * : Chain ID (or beginning of it)
     *
     * ## EXAMPLES
     *
     *     wp cron-v2 chain-show abc12345
     *
     * @subcommand chain-show
     * @param array $args
     * @param array $assoc_args
     */
    public function chain_show( $args, $assoc_args ) {
        $id = $args[0];

        // Find chain
        $chains = \WPCronV2\Queue\Chain::all();
        $chain = null;

        foreach ( $chains as $c ) {
            if ( strpos( $c['id'], $id ) === 0 ) {
                $chain = $c;
                break;
            }
        }

        if ( ! $chain ) {
            WP_CLI::error( "Chain '{$id}' not found." );
        }

        WP_CLI::log( '' );
        WP_CLI::log( WP_CLI::colorize( '%GChain: ' . $chain['name'] . '%n' ) );
        WP_CLI::log( str_repeat( '─', 40 ) );
        WP_CLI::log( "ID:         {$chain['id']}" );
        WP_CLI::log( "Status:     {$chain['status']}" );
        WP_CLI::log( "Queue:      {$chain['queue']}" );
        WP_CLI::log( "Created:    {$chain['created_at']}" );
        WP_CLI::log( "Finished:   " . ( $chain['finished_at'] ?? '-' ) );
        WP_CLI::log( '' );
        WP_CLI::log( WP_CLI::colorize( '%YJobs (' . $chain['total_jobs'] . '):%n' ) );

        foreach ( $chain['jobs'] as $index => $job_data ) {
            $parts = explode( '\\', $job_data['class'] );
            $job_name = end( $parts );

            $status = '';
            if ( $index < $chain['current_index'] ) {
                $status = WP_CLI::colorize( '%G✓%n' );
            } elseif ( $index === $chain['current_index'] && $chain['status'] === 'running' ) {
                $status = WP_CLI::colorize( '%Y►%n' );
            } else {
                $status = WP_CLI::colorize( '%K○%n' );
            }

            WP_CLI::log( "  {$status} " . ( $index + 1 ) . ". {$job_name}" );
        }

        if ( ! empty( $chain['error'] ) ) {
            WP_CLI::log( '' );
            WP_CLI::log( WP_CLI::colorize( '%RError:%n ' . $chain['error'] ) );
        }
    }

    /**
     * Delete chain
     *
     * ## OPTIONS
     *
     * <id>
     * : Chain ID (or beginning of it)
     *
     * ## EXAMPLES
     *
     *     wp cron-v2 chain-delete abc12345
     *
     * @subcommand chain-delete
     * @param array $args
     * @param array $assoc_args
     */
    public function chain_delete( $args, $assoc_args ) {
        $id = $args[0];

        // Find chain
        $chains = \WPCronV2\Queue\Chain::all();
        $chain_id = null;

        foreach ( $chains as $c ) {
            if ( strpos( $c['id'], $id ) === 0 ) {
                $chain_id = $c['id'];
                break;
            }
        }

        if ( ! $chain_id ) {
            WP_CLI::error( "Chain '{$id}' not found." );
        }

        if ( \WPCronV2\Queue\Chain::delete( $chain_id ) ) {
            WP_CLI::success( "Chain deleted." );
        } else {
            WP_CLI::error( "Chain deletion failed." );
        }
    }

    /**
     * List webhooks
     *
     * ## OPTIONS
     *
     * [--format=<format>]
     * : Output format
     * ---
     * default: table
     * options:
     *   - table
     *   - json
     * ---
     *
     * ## EXAMPLES
     *
     *     wp cron-v2 webhooks
     *
     * @param array $args
     * @param array $assoc_args
     */
    public function webhooks( $args, $assoc_args ) {
        $format = $assoc_args['format'] ?? 'table';
        $webhooks = wp_cron_v2_webhooks()->getAll();

        if ( empty( $webhooks ) ) {
            WP_CLI::log( 'No webhooks.' );
            return;
        }

        $data = [];
        foreach ( $webhooks as $name => $webhook ) {
            $data[] = [
                'name'    => $name,
                'url'     => substr( $webhook['url'], 0, 50 ) . ( strlen( $webhook['url'] ) > 50 ? '...' : '' ),
                'events'  => implode( ', ', $webhook['events'] ),
                'enabled' => $webhook['enabled'] ? 'yes' : 'no',
            ];
        }

        WP_CLI\Utils\format_items( $format, $data, [ 'name', 'url', 'events', 'enabled' ] );
    }

    /**
     * Add webhook
     *
     * ## OPTIONS
     *
     * <name>
     * : Webhook name
     *
     * <url>
     * : Target URL
     *
     * [--events=<events>]
     * : Events comma separated (default: job.completed,job.failed)
     * ---
     * default: job.completed,job.failed
     * ---
     *
     * [--secret=<secret>]
     * : HMAC secret for signature
     *
     * ## EXAMPLES
     *
     *     wp cron-v2 webhook-add slack https://hooks.slack.com/xxx
     *     wp cron-v2 webhook-add monitor https://example.com/webhook --events=job.failed,chain.failed --secret=mysecret
     *
     * @subcommand webhook-add
     * @param array $args
     * @param array $assoc_args
     */
    public function webhook_add( $args, $assoc_args ) {
        $name = $args[0];
        $url = $args[1];
        $events = array_map( 'trim', explode( ',', $assoc_args['events'] ?? 'job.completed,job.failed' ) );
        $secret = $assoc_args['secret'] ?? '';

        if ( wp_cron_v2_webhooks()->register( $name, $url, $events, [ 'secret' => $secret ] ) ) {
            WP_CLI::success( "Webhook '{$name}' added." );
        } else {
            WP_CLI::error( "Failed to add webhook." );
        }
    }

    /**
     * Remove webhook
     *
     * ## OPTIONS
     *
     * <name>
     * : Webhook name
     *
     * ## EXAMPLES
     *
     *     wp cron-v2 webhook-remove slack
     *
     * @subcommand webhook-remove
     * @param array $args
     * @param array $assoc_args
     */
    public function webhook_remove( $args, $assoc_args ) {
        $name = $args[0];

        if ( wp_cron_v2_webhooks()->unregister( $name ) ) {
            WP_CLI::success( "Webhook '{$name}' removed." );
        } else {
            WP_CLI::error( "Webhook '{$name}' not found." );
        }
    }

    /**
     * Test webhook
     *
     * ## OPTIONS
     *
     * <name>
     * : Webhook name
     *
     * ## EXAMPLES
     *
     *     wp cron-v2 webhook-test slack
     *
     * @subcommand webhook-test
     * @param array $args
     * @param array $assoc_args
     */
    public function webhook_test( $args, $assoc_args ) {
        $name = $args[0];

        WP_CLI::log( "Testing webhook '{$name}'..." );

        $result = wp_cron_v2_webhooks()->test( $name );

        if ( $result['success'] ) {
            WP_CLI::success( "Webhook responded: HTTP {$result['status_code']}" );
            if ( ! empty( $result['body'] ) ) {
                WP_CLI::log( "Response: " . substr( $result['body'], 0, 200 ) );
            }
        } else {
            WP_CLI::error( "Webhook test failed: " . $result['error'] );
        }
    }

    /**
     * Enable/disable webhook
     *
     * ## OPTIONS
     *
     * <name>
     * : Webhook name
     *
     * <status>
     * : on or off
     *
     * ## EXAMPLES
     *
     *     wp cron-v2 webhook-toggle slack off
     *     wp cron-v2 webhook-toggle slack on
     *
     * @subcommand webhook-toggle
     * @param array $args
     * @param array $assoc_args
     */
    public function webhook_toggle( $args, $assoc_args ) {
        $name = $args[0];
        $enabled = strtolower( $args[1] ) === 'on';

        if ( wp_cron_v2_webhooks()->setEnabled( $name, $enabled ) ) {
            $status = $enabled ? 'enabled' : 'disabled';
            WP_CLI::success( "Webhook '{$name}' is now {$status}." );
        } else {
            WP_CLI::error( "Webhook '{$name}' not found." );
        }
    }

    /**
     * Show rate limit statistics
     *
     * ## OPTIONS
     *
     * <key>
     * : Rate limit key (e.g. "job_type:MyJob" or "queue:emails")
     *
     * [--max=<max>]
     * : Maximum (default: 60)
     * ---
     * default: 60
     * ---
     *
     * [--per=<seconds>]
     * : Time window in seconds (default: 60)
     * ---
     * default: 60
     * ---
     *
     * ## EXAMPLES
     *
     *     wp cron-v2 rate-limit-stats "job_type:SendEmailJob" --max=10 --per=60
     *
     * @subcommand rate-limit-stats
     * @param array $args
     * @param array $assoc_args
     */
    public function rate_limit_stats( $args, $assoc_args ) {
        $key = $args[0];
        $max = (int) ( $assoc_args['max'] ?? 60 );
        $per = (int) ( $assoc_args['per'] ?? 60 );

        $stats = wp_cron_v2_rate_limiter()->getStats( $key, $max, $per );

        WP_CLI::log( '' );
        WP_CLI::log( WP_CLI::colorize( '%GRate Limit: ' . $key . '%n' ) );
        WP_CLI::log( str_repeat( '─', 40 ) );
        WP_CLI::log( "Used:       {$stats['used']} / {$stats['max']}" );
        WP_CLI::log( "Remaining:  {$stats['remaining']}" );
        WP_CLI::log( "Resets in:  {$stats['resets_in']}s" );
        WP_CLI::log( "Window:     {$stats['window_seconds']}s" );
    }

    /**
     * Reset rate limit
     *
     * ## OPTIONS
     *
     * <key>
     * : Rate limit key
     *
     * ## EXAMPLES
     *
     *     wp cron-v2 rate-limit-reset "job_type:SendEmailJob"
     *
     * @subcommand rate-limit-reset
     * @param array $args
     * @param array $assoc_args
     */
    public function rate_limit_reset( $args, $assoc_args ) {
        $key = $args[0];

        if ( wp_cron_v2_rate_limiter()->reset( $key ) ) {
            WP_CLI::success( "Rate limit '{$key}' reset." );
        } else {
            WP_CLI::log( "Rate limit was not set." );
        }
    }

    /**
     * Show driver info
     *
     * ## EXAMPLES
     *
     *     wp cron-v2 driver
     *
     * @param array $args
     * @param array $assoc_args
     */
    public function driver( $args, $assoc_args ) {
        $manager = wp_cron_v2();
        $driver = $manager->getDriver();
        $driver_class = get_class( $driver );

        // Format short name
        $driver_name = match ( true ) {
            str_contains( $driver_class, 'RedisDriver' ) => 'Redis',
            str_contains( $driver_class, 'DatabaseDriver' ) => 'Database',
            default => $driver_class,
        };

        WP_CLI::log( WP_CLI::colorize( "%GQueue Driver%n" ) );
        WP_CLI::log( str_repeat( '─', 40 ) );
        WP_CLI::log( "Driver:     {$driver_name}" );
        WP_CLI::log( "Class:      {$driver_class}" );
        WP_CLI::log( "Connection: " . ( $driver->isConnected() ? 'OK' : 'ERROR' ) );

        // Redis-specific info
        if ( $driver_name === 'Redis' && method_exists( $driver, 'getRedis' ) ) {
            $redis = $driver->getRedis();
            if ( $redis ) {
                try {
                    $info = $redis->info();
                    WP_CLI::log( '' );
                    WP_CLI::log( WP_CLI::colorize( "%GRedis Info%n" ) );
                    WP_CLI::log( str_repeat( '─', 40 ) );
                    WP_CLI::log( "Version:    " . ( $info['redis_version'] ?? 'N/A' ) );
                    WP_CLI::log( "Memory:     " . ( $info['used_memory_human'] ?? 'N/A' ) );
                    WP_CLI::log( "Clients:    " . ( $info['connected_clients'] ?? 'N/A' ) );
                    WP_CLI::log( "Keys:       " . ( $info['db0'] ?? 'N/A' ) );
                } catch ( \Exception $e ) {
                    WP_CLI::warning( "Redis info fetch failed: " . $e->getMessage() );
                }
            }
        }

        // Show supported drivers
        WP_CLI::log( '' );
        WP_CLI::log( WP_CLI::colorize( "%GSupported Drivers%n" ) );
        WP_CLI::log( str_repeat( '─', 40 ) );

        $drivers = \WPCronV2\Queue\Drivers\DriverFactory::getSupportedDrivers();
        foreach ( $drivers as $key => $info ) {
            $status = $info['available'] ? WP_CLI::colorize( '%g✓%n' ) : WP_CLI::colorize( '%r✗%n' );
            WP_CLI::log( "{$status} {$info['name']}" );
        }
    }

    /**
     * Test Redis connection
     *
     * ## OPTIONS
     *
     * [--host=<host>]
     * : Redis host
     * ---
     * default: 127.0.0.1
     * ---
     *
     * [--port=<port>]
     * : Redis port
     * ---
     * default: 6379
     * ---
     *
     * [--password=<password>]
     * : Redis password
     *
     * [--database=<database>]
     * : Redis database number
     * ---
     * default: 0
     * ---
     *
     * ## EXAMPLES
     *
     *     wp cron-v2 redis-test
     *     wp cron-v2 redis-test --host=redis.example.com --port=6380
     *
     * @subcommand redis-test
     * @param array $args
     * @param array $assoc_args
     */
    public function redis_test( $args, $assoc_args ) {
        if ( ! \WPCronV2\Queue\Drivers\DriverFactory::isRedisAvailable() ) {
            WP_CLI::error( 'PHP Redis extension is not installed.' );
            return;
        }

        $config = [];

        if ( isset( $assoc_args['host'] ) ) {
            $config['host'] = $assoc_args['host'];
        }
        if ( isset( $assoc_args['port'] ) ) {
            $config['port'] = (int) $assoc_args['port'];
        }
        if ( isset( $assoc_args['password'] ) ) {
            $config['password'] = $assoc_args['password'];
        }
        if ( isset( $assoc_args['database'] ) ) {
            $config['database'] = (int) $assoc_args['database'];
        }

        WP_CLI::log( 'Testing Redis connection...' );

        $result = \WPCronV2\Queue\Drivers\DriverFactory::testRedisConnection( $config );

        if ( $result['success'] ) {
            WP_CLI::success( $result['message'] );
        } else {
            WP_CLI::error( $result['message'] );
        }
    }

    /**
     * Switch queue driver
     *
     * ## OPTIONS
     *
     * <driver>
     * : Driver type (database, redis)
     *
     * [--save]
     * : Save setting permanently
     *
     * ## EXAMPLES
     *
     *     wp cron-v2 set-driver redis
     *     wp cron-v2 set-driver database --save
     *
     * @subcommand set-driver
     * @param array $args
     * @param array $assoc_args
     */
    public function set_driver( $args, $assoc_args ) {
        $driver = $args[0];
        $save = isset( $assoc_args['save'] );

        $supported = [ 'database', 'redis' ];

        if ( ! in_array( $driver, $supported, true ) ) {
            WP_CLI::error( "Unknown driver: {$driver}. Supported: " . implode( ', ', $supported ) );
            return;
        }

        if ( $driver === 'redis' && ! \WPCronV2\Queue\Drivers\DriverFactory::isRedisAvailable() ) {
            WP_CLI::error( 'PHP Redis extension is not installed.' );
            return;
        }

        try {
            if ( $driver === 'redis' ) {
                // Test connection first
                $result = \WPCronV2\Queue\Drivers\DriverFactory::testRedisConnection();
                if ( ! $result['success'] ) {
                    WP_CLI::error( 'Redis connection failed: ' . $result['message'] );
                    return;
                }
            }

            if ( $save ) {
                $settings = get_option( 'wp_cron_v2_settings', [] );
                $settings['driver'] = $driver;
                update_option( 'wp_cron_v2_settings', $settings );
                WP_CLI::success( "Driver '{$driver}' set and saved." );
            } else {
                WP_CLI::success( "Driver '{$driver}' set for this session." );
                WP_CLI::log( "Use --save to save permanently." );
            }

        } catch ( \Exception $e ) {
            WP_CLI::error( 'Error: ' . $e->getMessage() );
        }
    }

    /**
     * Flush Redis queues (warning: removes all jobs!)
     *
     * ## OPTIONS
     *
     * [--yes]
     * : Skip confirmation
     *
     * ## EXAMPLES
     *
     *     wp cron-v2 redis-flush --yes
     *
     * @subcommand redis-flush
     * @param array $args
     * @param array $assoc_args
     */
    public function redis_flush( $args, $assoc_args ) {
        $driver = wp_cron_v2()->getDriver();

        if ( ! $driver instanceof \WPCronV2\Queue\Drivers\RedisDriver ) {
            WP_CLI::error( 'This command only works with Redis driver.' );
            return;
        }

        WP_CLI::confirm( 'This will remove ALL jobs from Redis. Are you sure?', $assoc_args );

        if ( $driver->flush() ) {
            WP_CLI::success( 'Redis queues flushed.' );
        } else {
            WP_CLI::error( 'Flush failed.' );
        }
    }

    /**
     * Show multisite statistics
     *
     * ## OPTIONS
     *
     * [--format=<format>]
     * : Output format (table, json, csv)
     * ---
     * default: table
     * ---
     *
     * ## EXAMPLES
     *
     *     wp cron-v2 network-stats
     *     wp cron-v2 network-stats --format=json
     *
     * @subcommand network-stats
     * @param array $args
     * @param array $assoc_args
     */
    public function network_stats( $args, $assoc_args ) {
        $network = wp_cron_v2_network();

        if ( ! $network->isMultisite() ) {
            WP_CLI::error( 'This command only works in multisite environment.' );
            return;
        }

        $stats = $network->getNetworkStats();

        if ( empty( $stats ) ) {
            WP_CLI::log( 'No jobs.' );
            return;
        }

        $format = $assoc_args['format'] ?? 'table';

        $rows = [];
        foreach ( $stats as $site_stats ) {
            $rows[] = [
                'site_id'   => $site_stats['site_id'],
                'site_name' => $site_stats['site_name'],
                'queued'    => $site_stats['queued'],
                'running'   => $site_stats['running'],
                'completed' => $site_stats['completed'],
                'failed'    => $site_stats['failed'],
            ];
        }

        WP_CLI\Utils\format_items( $format, $rows, [ 'site_id', 'site_name', 'queued', 'running', 'completed', 'failed' ] );
    }

    /**
     * List multisite sites
     *
     * ## OPTIONS
     *
     * [--format=<format>]
     * : Output format (table, json, csv)
     * ---
     * default: table
     * ---
     *
     * ## EXAMPLES
     *
     *     wp cron-v2 sites
     *
     * @param array $args
     * @param array $assoc_args
     */
    public function sites( $args, $assoc_args ) {
        $network = wp_cron_v2_network();

        if ( ! $network->isMultisite() ) {
            WP_CLI::error( 'This command only works in multisite environment.' );
            return;
        }

        $site_ids = $network->getSites();

        if ( empty( $site_ids ) ) {
            WP_CLI::log( 'No sites.' );
            return;
        }

        $format = $assoc_args['format'] ?? 'table';

        $rows = [];
        foreach ( $site_ids as $site_id ) {
            $info = $network->getSiteInfo( $site_id );
            $queues = $network->getSiteQueues( $site_id );

            $total_jobs = 0;
            foreach ( $queues as $queue_stats ) {
                $total_jobs += array_sum( $queue_stats );
            }

            $rows[] = [
                'id'         => $info['id'],
                'name'       => $info['name'],
                'domain'     => $info['domain'] . $info['path'],
                'queues'     => count( $queues ),
                'total_jobs' => $total_jobs,
            ];
        }

        WP_CLI\Utils\format_items( $format, $rows, [ 'id', 'name', 'domain', 'queues', 'total_jobs' ] );
    }

    /**
     * Start worker for specific site
     *
     * ## OPTIONS
     *
     * <site_id>
     * : Site ID
     *
     * [--queue=<queue>]
     * : Queue name
     * ---
     * default: default
     * ---
     *
     * [--sleep=<seconds>]
     * : Sleep time in seconds when queue is empty
     * ---
     * default: 3
     * ---
     *
     * ## EXAMPLES
     *
     *     wp cron-v2 site-worker 2 --queue=default
     *
     * @subcommand site-worker
     * @param array $args
     * @param array $assoc_args
     */
    public function site_worker( $args, $assoc_args ) {
        $network = wp_cron_v2_network();

        if ( ! $network->isMultisite() ) {
            WP_CLI::error( 'This command only works in multisite environment.' );
            return;
        }

        $site_id = (int) $args[0];
        $queue = $assoc_args['queue'] ?? 'default';
        $sleep = (int) ( $assoc_args['sleep'] ?? 3 );

        // Check site exists
        $site_info = $network->getSiteInfo( $site_id );
        if ( ! $site_info ) {
            WP_CLI::error( "Site {$site_id} not found." );
            return;
        }

        WP_CLI::log( "Worker started for site {$site_info['name']} (ID: {$site_id}), queue: {$queue}" );

        // Set driver to use specific site
        $driver = wp_cron_v2()->getDriver();
        if ( method_exists( $driver, 'setSiteId' ) ) {
            $driver->setSiteId( $site_id );
        }

        $processed = 0;

        while ( true ) {
            // Process jobs in site context
            $result = $network->runOnSite( $site_id, function() use ( $queue ) {
                return wp_cron_v2()->process_next_job( $queue );
            } );

            if ( $result ) {
                $processed++;
                WP_CLI::log( "Job processed on site {$site_id}. Total: {$processed}" );
            } else {
                sleep( $sleep );
            }

            if ( function_exists( 'pcntl_signal_dispatch' ) ) {
                pcntl_signal_dispatch();
            }
        }
    }
}

// Register commands
WP_CLI::add_command( 'cron-v2', __NAMESPACE__ . '\\CLI_Commands' );
