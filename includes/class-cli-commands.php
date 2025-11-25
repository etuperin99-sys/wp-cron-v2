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

    /**
     * Suorita yksittäinen job heti (ohita jono)
     *
     * ## OPTIONS
     *
     * <id>
     * : Jobin ID
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
            WP_CLI::error( "Jobia ID {$id} ei löydy." );
        }

        if ( $job_row['status'] === 'running' ) {
            WP_CLI::error( "Job {$id} on jo käynnissä." );
        }

        if ( $job_row['status'] === 'completed' ) {
            WP_CLI::error( "Job {$id} on jo suoritettu." );
        }

        // Merkitse käynnissä
        $wpdb->update(
            $table,
            [ 'status' => 'running', 'updated_at' => current_time( 'mysql', true ) ],
            [ 'id' => $id ]
        );

        WP_CLI::log( "Suoritetaan job {$id}..." );

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

            WP_CLI::success( "Job {$id} suoritettu onnistuneesti ({$duration}ms)." );

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

            WP_CLI::error( "Job {$id} epäonnistui: " . $e->getMessage() );
        }
    }

    /**
     * Prosessoi kaikki jonossa olevat jobit kerralla
     *
     * ## OPTIONS
     *
     * [--queue=<queue>]
     * : Jonon nimi
     * ---
     * default: default
     * ---
     *
     * [--limit=<number>]
     * : Maksimi määrä prosessoitavia jobeja
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

        WP_CLI::log( "Prosessoidaan jonoa '{$queue}'..." );

        while ( $processed < $limit ) {
            $result = wp_cron_v2()->process_next_job( $queue );

            if ( $result === false ) {
                // Ei enää jobeja
                break;
            }

            if ( $result ) {
                $processed++;
            } else {
                $failed++;
            }
        }

        WP_CLI::success( "Valmis. Prosessoitu: {$processed}, epäonnistunut: {$failed}" );
    }

    /**
     * Näytä jonon tilastot yksityiskohtaisesti
     *
     * ## OPTIONS
     *
     * [--format=<format>]
     * : Tulostusmuoto
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

        // Yleistilastot
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
        WP_CLI::log( WP_CLI::colorize( '%GWPC Cron v2 Status%n' ) );
        WP_CLI::log( str_repeat( '─', 40 ) );
        WP_CLI::log( "Jobeja yhteensä:       {$total}" );
        WP_CLI::log( "Vanhin jonossa:        " . ( $oldest_queued ?: '-' ) );
        WP_CLI::log( "Keskim. yrityksiä:     " . ( $avg_attempts ? round( $avg_attempts, 2 ) : '-' ) );
        WP_CLI::log( '' );

        if ( ! empty( $queues ) ) {
            WP_CLI::log( WP_CLI::colorize( '%YJonot:%n' ) );
            WP_CLI\Utils\format_items( 'table', $queues, [ 'queue', 'total', 'queued', 'running', 'completed', 'failed' ] );
        }
    }

    /**
     * Listaa ajastetut tehtävät
     *
     * ## OPTIONS
     *
     * [--format=<format>]
     * : Tulostusmuoto
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
            WP_CLI::log( 'Ei ajastettuja tehtäviä.' );
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
     * Pysäytä ajastettu tehtävä
     *
     * ## OPTIONS
     *
     * <name>
     * : Schedulen nimi
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
            WP_CLI::success( "Schedule '{$name}' pysäytetty." );
        } else {
            WP_CLI::error( "Schedulea '{$name}' ei löydy." );
        }
    }

    /**
     * Jatka pysäytettyä ajastettua tehtävää
     *
     * ## OPTIONS
     *
     * <name>
     * : Schedulen nimi
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
            WP_CLI::success( "Schedule '{$name}' jatkuu." );
        } else {
            WP_CLI::error( "Schedulea '{$name}' ei löydy." );
        }
    }

    /**
     * Poista ajastettu tehtävä
     *
     * ## OPTIONS
     *
     * <name>
     * : Schedulen nimi
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
            WP_CLI::success( "Schedule '{$name}' poistettu." );
        } else {
            WP_CLI::error( "Schedulea '{$name}' ei löydy." );
        }
    }

    /**
     * Vapauta jumittuneet jobit (stale/timeout)
     *
     * ## OPTIONS
     *
     * [--timeout=<minutes>]
     * : Kuinka monta minuuttia running-tilassa = timeout
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
            WP_CLI::success( "Vapautettu {$released} jumittunutta jobia." );
        } else {
            WP_CLI::log( 'Ei jumittuneita jobeja.' );
        }
    }

    /**
     * Siivoa vanhat valmiit jobit
     *
     * ## OPTIONS
     *
     * [--days=<days>]
     * : Poista tätä vanhemmat (päivinä)
     * ---
     * default: 7
     * ---
     *
     * [--include-failed]
     * : Poista myös epäonnistuneet
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

        // Poista valmiit
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

        WP_CLI::success( "Siivottu {$total} jobia (completed: {$deleted_completed}, failed: {$deleted_failed})." );
    }

    /**
     * Näytä jonon health status
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

        // Tilastot
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

        // Vanhin jonossa (SQLite-yhteensopiva)
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
            $issues[] = WP_CLI::colorize( "%R{$stats->stale} jumittunutta jobia%n" );
        }

        if ( (int) $stats->failed > 10 ) {
            $issues[] = WP_CLI::colorize( "%Y{$stats->failed} epäonnistunutta jobia%n" );
        }

        if ( $oldest && (int) $oldest > 60 ) {
            $issues[] = WP_CLI::colorize( "%YVanhin job jonossa {$oldest} min%n" );
        }

        WP_CLI::log( '' );
        WP_CLI::log( WP_CLI::colorize( '%GWP Cron v2 Health Check%n' ) );
        WP_CLI::log( str_repeat( '─', 40 ) );
        WP_CLI::log( "Jonossa:        {$stats->queued}" );
        WP_CLI::log( "Käynnissä:      {$stats->running}" );
        WP_CLI::log( "Epäonnistuneet: {$stats->failed}" );
        WP_CLI::log( "Jumittuneet:    {$stats->stale}" );

        if ( $oldest ) {
            WP_CLI::log( "Vanhin jonossa: {$oldest} min" );
        }

        WP_CLI::log( '' );

        if ( empty( $issues ) ) {
            WP_CLI::success( 'Kaikki OK!' );
        } else {
            WP_CLI::log( WP_CLI::colorize( '%ROngelmat:%n' ) );
            foreach ( $issues as $issue ) {
                WP_CLI::log( "  - {$issue}" );
            }
            WP_CLI::log( '' );
            WP_CLI::log( 'Korjausehdotukset:' );
            WP_CLI::log( '  wp cron-v2 release-stale    # Vapauta jumittuneet' );
            WP_CLI::log( '  wp cron-v2 retry-failed     # Yritä epäonnistuneet' );
            WP_CLI::log( '  wp cron-v2 worker           # Käynnistä worker' );
        }
    }

    /**
     * Listaa batchit
     *
     * ## OPTIONS
     *
     * [--limit=<number>]
     * : Näytettävien batchien määrä
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
            WP_CLI::log( 'Ei batcheja.' );
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
     * Näytä batchin tiedot
     *
     * ## OPTIONS
     *
     * <id>
     * : Batch ID (tai alku siitä)
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

        // Etsi batch (osittaisella ID:llä)
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $batch = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE id LIKE %s LIMIT 1",
                $id . '%'
            ),
            ARRAY_A
        );

        if ( ! $batch ) {
            WP_CLI::error( "Batchia '{$id}' ei löydy." );
        }

        $stats = \WPCronV2\Queue\Batch::getStats( $batch['id'] );

        WP_CLI::log( '' );
        WP_CLI::log( WP_CLI::colorize( '%GBatch: ' . $batch['name'] . '%n' ) );
        WP_CLI::log( str_repeat( '─', 40 ) );
        WP_CLI::log( "ID:         {$batch['id']}" );
        WP_CLI::log( "Luotu:      {$batch['created_at']}" );
        WP_CLI::log( "Valmistunut: " . ( $batch['finished_at'] ?: '-' ) );
        WP_CLI::log( '' );
        WP_CLI::log( WP_CLI::colorize( '%YTilastot:%n' ) );
        WP_CLI::log( "Yhteensä:     {$stats['total']}" );
        WP_CLI::log( "Jonossa:      {$stats['queued']}" );
        WP_CLI::log( "Käynnissä:    {$stats['running']}" );
        WP_CLI::log( "Valmiita:     {$stats['completed']}" );
        WP_CLI::log( "Epäonnist.:   {$stats['failed']}" );
        WP_CLI::log( "Edistyminen:  {$stats['progress']}%" );
    }

    /**
     * Peruuta batch
     *
     * ## OPTIONS
     *
     * <id>
     * : Batch ID (tai alku siitä)
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

        // Etsi batch
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $batch = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT id FROM {$table} WHERE id LIKE %s LIMIT 1",
                $id . '%'
            )
        );

        if ( ! $batch ) {
            WP_CLI::error( "Batchia '{$id}' ei löydy." );
        }

        $cancelled = \WPCronV2\Queue\Batch::cancel( $batch->id );

        WP_CLI::success( "Peruutettu {$cancelled} jobia batchista." );
    }

    /**
     * Listaa job chainit
     *
     * ## OPTIONS
     *
     * [--limit=<number>]
     * : Näytettävien chainien määrä
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
            WP_CLI::log( 'Ei job chaineja.' );
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
     * Näytä chainin tiedot
     *
     * ## OPTIONS
     *
     * <id>
     * : Chain ID (tai alku siitä)
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

        // Etsi chain
        $chains = \WPCronV2\Queue\Chain::all();
        $chain = null;

        foreach ( $chains as $c ) {
            if ( strpos( $c['id'], $id ) === 0 ) {
                $chain = $c;
                break;
            }
        }

        if ( ! $chain ) {
            WP_CLI::error( "Chainia '{$id}' ei löydy." );
        }

        WP_CLI::log( '' );
        WP_CLI::log( WP_CLI::colorize( '%GChain: ' . $chain['name'] . '%n' ) );
        WP_CLI::log( str_repeat( '─', 40 ) );
        WP_CLI::log( "ID:           {$chain['id']}" );
        WP_CLI::log( "Status:       {$chain['status']}" );
        WP_CLI::log( "Jono:         {$chain['queue']}" );
        WP_CLI::log( "Luotu:        {$chain['created_at']}" );
        WP_CLI::log( "Valmistunut:  " . ( $chain['finished_at'] ?? '-' ) );
        WP_CLI::log( '' );
        WP_CLI::log( WP_CLI::colorize( '%YJobit (' . $chain['total_jobs'] . '):%n' ) );

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
            WP_CLI::log( WP_CLI::colorize( '%RVirhe:%n ' . $chain['error'] ) );
        }
    }

    /**
     * Poista chain
     *
     * ## OPTIONS
     *
     * <id>
     * : Chain ID (tai alku siitä)
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

        // Etsi chain
        $chains = \WPCronV2\Queue\Chain::all();
        $chain_id = null;

        foreach ( $chains as $c ) {
            if ( strpos( $c['id'], $id ) === 0 ) {
                $chain_id = $c['id'];
                break;
            }
        }

        if ( ! $chain_id ) {
            WP_CLI::error( "Chainia '{$id}' ei löydy." );
        }

        if ( \WPCronV2\Queue\Chain::delete( $chain_id ) ) {
            WP_CLI::success( "Chain poistettu." );
        } else {
            WP_CLI::error( "Chain poisto epäonnistui." );
        }
    }
}

// Rekisteröi komennot
WP_CLI::add_command( 'cron-v2', __NAMESPACE__ . '\\CLI_Commands' );
