<?php
/**
 * WP-CLI komennot
 *
 * @package WPCronV2\Includes
 */

namespace WPCronV2\Includes;

use WP_CLI;
use WP_CLI_Command;

/**
 * WP Cron v2 worker ja jonohallinnan komennot
 */
class CLI_Commands extends WP_CLI_Command {

    /**
     * Käynnistä worker prosessoimaan jonoa
     *
     * ## OPTIONS
     *
     * [--queue=<queue>]
     * : Jonon nimi
     * ---
     * default: default
     * ---
     *
     * [--sleep=<seconds>]
     * : Odotusaika sekunteina kun jono on tyhjä
     * ---
     * default: 3
     * ---
     *
     * [--max-jobs=<number>]
     * : Maksimi jobien määrä ennen pysäytystä (0 = rajaton)
     * ---
     * default: 0
     * ---
     *
     * [--timeout=<seconds>]
     * : Maksimi ajoaika sekunteina (0 = rajaton)
     * ---
     * default: 0
     * ---
     *
     * ## EXAMPLES
     *
     *     # Käynnistä worker oletusjonolle
     *     wp cron-v2 worker --queue=default
     *
     *     # Käynnistä worker WooCommerce-jonolle
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

        WP_CLI::log( "Worker {$worker_id} käynnistetty jonolle '{$queue}'" );

        while ( true ) {
            // Tarkista timeout
            if ( $timeout > 0 && ( time() - $start_time ) >= $timeout ) {
                WP_CLI::success( "Timeout saavutettu. Prosessoitu {$processed} jobia." );
                break;
            }

            // Tarkista max jobs
            if ( $max_jobs > 0 && $processed >= $max_jobs ) {
                WP_CLI::success( "Maksimi jobien määrä saavutettu: {$processed}" );
                break;
            }

            // Prosessoi seuraava job
            $result = wp_cron_v2()->process_next_job( $queue );

            if ( $result ) {
                $processed++;
                WP_CLI::log( "Job prosessoitu. Yhteensä: {$processed}" );
            } else {
                // Ei jobeja, odota
                sleep( $sleep );
            }

            // Tarkista signaalit (SIGTERM, SIGINT)
            if ( function_exists( 'pcntl_signal_dispatch' ) ) {
                pcntl_signal_dispatch();
            }
        }
    }

    /**
     * Näytä jonon tilastot
     *
     * ## OPTIONS
     *
     * [--queue=<queue>]
     * : Jonon nimi (tyhjä = kaikki)
     *
     * [--format=<format>]
     * : Tulostusmuoto
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
            // Hae kaikkien jonojen tilastot
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
            WP_CLI::log( 'Ei jobeja jonossa.' );
            return;
        }

        WP_CLI\Utils\format_items( $format, $data, [ 'queue', 'queued', 'running', 'completed', 'failed' ] );
    }

    /**
     * Tyhjennä epäonnistuneet jobit
     *
     * ## OPTIONS
     *
     * [--queue=<queue>]
     * : Jonon nimi (tyhjä = kaikki)
     *
     * [--older-than=<days>]
     * : Poista vain tätä vanhemmat (päivinä)
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

        WP_CLI::success( "Poistettu {$count} epäonnistunutta jobia." );
    }

    /**
     * Yritä epäonnistuneita jobeja uudelleen
     *
     * ## OPTIONS
     *
     * [--queue=<queue>]
     * : Jonon nimi (tyhjä = kaikki)
     *
     * [--limit=<number>]
     * : Maksimi määrä
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

        WP_CLI::success( "Palautettu {$count} jobia jonoon uudelleenyritystä varten." );
    }

    /**
     * Listaa jobit jonossa
     *
     * ## OPTIONS
     *
     * [--queue=<queue>]
     * : Jonon nimi (tyhjä = kaikki)
     *
     * [--status=<status>]
     * : Suodata statuksen mukaan (queued, running, completed, failed)
     *
     * [--limit=<number>]
     * : Näytettävien jobien määrä
     * ---
     * default: 20
     * ---
     *
     * [--format=<format>]
     * : Tulostusmuoto
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
            WP_CLI::log( 'Ei jobeja.' );
            return;
        }

        // Lyhennä job_type näyttöä varten
        foreach ( $jobs as &$job ) {
            $parts = explode( '\\', $job['job_type'] );
            $job['job_type'] = end( $parts );
            $job['error_message'] = $job['error_message'] ? substr( $job['error_message'], 0, 40 ) . '...' : '';
        }

        WP_CLI\Utils\format_items( $format, $jobs, [ 'id', 'job_type', 'queue', 'priority', 'status', 'attempts', 'created_at', 'error_message' ] );
    }

    /**
     * Poista valmiit jobit
     *
     * ## OPTIONS
     *
     * [--queue=<queue>]
     * : Jonon nimi (tyhjä = kaikki)
     *
     * [--older-than=<days>]
     * : Poista vain tätä vanhemmat (päivinä)
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

        WP_CLI::success( "Poistettu {$count} valmista jobia." );
    }

    /**
     * Näytä yksittäisen jobin tiedot
     *
     * ## OPTIONS
     *
     * <id>
     * : Jobin ID
     *
     * [--format=<format>]
     * : Tulostusmuoto
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
            WP_CLI::error( "Jobia ID {$id} ei löydy." );
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
     * Peruuta job jonosta
     *
     * ## OPTIONS
     *
     * <id>...
     * : Jobin ID(t)
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
                WP_CLI::log( "Job {$id} peruutettu." );
            } else {
                WP_CLI::warning( "Job {$id} ei voitu peruuttaa (ei jonossa tai ei löydy)." );
            }
        }

        WP_CLI::success( "Peruutettu {$cancelled} jobia." );
    }
}

// Rekisteröi komennot
WP_CLI::add_command( 'cron-v2', __NAMESPACE__ . '\\CLI_Commands' );
