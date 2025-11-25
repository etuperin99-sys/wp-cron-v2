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

    /**
     * Listaa webhookit
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
     *     wp cron-v2 webhooks
     *
     * @param array $args
     * @param array $assoc_args
     */
    public function webhooks( $args, $assoc_args ) {
        $format = $assoc_args['format'] ?? 'table';
        $webhooks = wp_cron_v2_webhooks()->getAll();

        if ( empty( $webhooks ) ) {
            WP_CLI::log( 'Ei webhookeja.' );
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
     * Lisää webhook
     *
     * ## OPTIONS
     *
     * <name>
     * : Webhookin nimi
     *
     * <url>
     * : Kohde-URL
     *
     * [--events=<events>]
     * : Tapahtumat pilkulla erotettuna (oletus: job.completed,job.failed)
     * ---
     * default: job.completed,job.failed
     * ---
     *
     * [--secret=<secret>]
     * : HMAC secret allekirjoitusta varten
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
            WP_CLI::success( "Webhook '{$name}' lisätty." );
        } else {
            WP_CLI::error( "Webhook lisäys epäonnistui." );
        }
    }

    /**
     * Poista webhook
     *
     * ## OPTIONS
     *
     * <name>
     * : Webhookin nimi
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
            WP_CLI::success( "Webhook '{$name}' poistettu." );
        } else {
            WP_CLI::error( "Webhookia '{$name}' ei löydy." );
        }
    }

    /**
     * Testaa webhookia
     *
     * ## OPTIONS
     *
     * <name>
     * : Webhookin nimi
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

        WP_CLI::log( "Testataan webhookia '{$name}'..." );

        $result = wp_cron_v2_webhooks()->test( $name );

        if ( $result['success'] ) {
            WP_CLI::success( "Webhook vastasi: HTTP {$result['status_code']}" );
            if ( ! empty( $result['body'] ) ) {
                WP_CLI::log( "Response: " . substr( $result['body'], 0, 200 ) );
            }
        } else {
            WP_CLI::error( "Webhook testi epäonnistui: " . $result['error'] );
        }
    }

    /**
     * Ota webhook käyttöön/pois
     *
     * ## OPTIONS
     *
     * <name>
     * : Webhookin nimi
     *
     * <status>
     * : on tai off
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
            $status = $enabled ? 'käytössä' : 'pois käytöstä';
            WP_CLI::success( "Webhook '{$name}' nyt {$status}." );
        } else {
            WP_CLI::error( "Webhookia '{$name}' ei löydy." );
        }
    }

    /**
     * Näytä rate limit tilastot
     *
     * ## OPTIONS
     *
     * <key>
     * : Rate limit avain (esim. "job_type:MyJob" tai "queue:emails")
     *
     * [--max=<max>]
     * : Maksimi (oletus: 60)
     * ---
     * default: 60
     * ---
     *
     * [--per=<seconds>]
     * : Aikaikkuna sekunteina (oletus: 60)
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
        WP_CLI::log( "Käytetty:     {$stats['used']} / {$stats['max']}" );
        WP_CLI::log( "Jäljellä:     {$stats['remaining']}" );
        WP_CLI::log( "Nollautuu:    {$stats['resets_in']}s" );
        WP_CLI::log( "Aikaikkuna:   {$stats['window_seconds']}s" );
    }

    /**
     * Nollaa rate limit
     *
     * ## OPTIONS
     *
     * <key>
     * : Rate limit avain
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
            WP_CLI::success( "Rate limit '{$key}' nollattu." );
        } else {
            WP_CLI::log( "Rate limittiä ei ollut asetettu." );
        }
    }

    /**
     * Näytä driver-tiedot
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

        // Muodosta lyhyt nimi
        $driver_name = match ( true ) {
            str_contains( $driver_class, 'RedisDriver' ) => 'Redis',
            str_contains( $driver_class, 'DatabaseDriver' ) => 'Database',
            default => $driver_class,
        };

        WP_CLI::log( WP_CLI::colorize( "%GQueue Driver%n" ) );
        WP_CLI::log( str_repeat( '─', 40 ) );
        WP_CLI::log( "Driver:       {$driver_name}" );
        WP_CLI::log( "Luokka:       {$driver_class}" );
        WP_CLI::log( "Yhteys:       " . ( $driver->isConnected() ? 'OK' : 'VIRHE' ) );

        // Redis-spesifiset tiedot
        if ( $driver_name === 'Redis' && method_exists( $driver, 'getRedis' ) ) {
            $redis = $driver->getRedis();
            if ( $redis ) {
                try {
                    $info = $redis->info();
                    WP_CLI::log( '' );
                    WP_CLI::log( WP_CLI::colorize( "%GRedis Info%n" ) );
                    WP_CLI::log( str_repeat( '─', 40 ) );
                    WP_CLI::log( "Versio:       " . ( $info['redis_version'] ?? 'N/A' ) );
                    WP_CLI::log( "Muisti:       " . ( $info['used_memory_human'] ?? 'N/A' ) );
                    WP_CLI::log( "Clients:      " . ( $info['connected_clients'] ?? 'N/A' ) );
                    WP_CLI::log( "Keys:         " . ( $info['db0'] ?? 'N/A' ) );
                } catch ( \Exception $e ) {
                    WP_CLI::warning( "Redis info haku epäonnistui: " . $e->getMessage() );
                }
            }
        }

        // Näytä tuetut driverit
        WP_CLI::log( '' );
        WP_CLI::log( WP_CLI::colorize( "%GTuetut Driverit%n" ) );
        WP_CLI::log( str_repeat( '─', 40 ) );

        $drivers = \WPCronV2\Queue\Drivers\DriverFactory::getSupportedDrivers();
        foreach ( $drivers as $key => $info ) {
            $status = $info['available'] ? WP_CLI::colorize( '%g✓%n' ) : WP_CLI::colorize( '%r✗%n' );
            WP_CLI::log( "{$status} {$info['name']}" );
        }
    }

    /**
     * Testaa Redis-yhteys
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
     * : Redis salasana
     *
     * [--database=<database>]
     * : Redis database numero
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
            WP_CLI::error( 'PHP Redis extension ei ole asennettu.' );
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

        WP_CLI::log( 'Testataan Redis-yhteyttä...' );

        $result = \WPCronV2\Queue\Drivers\DriverFactory::testRedisConnection( $config );

        if ( $result['success'] ) {
            WP_CLI::success( $result['message'] );
        } else {
            WP_CLI::error( $result['message'] );
        }
    }

    /**
     * Vaihda queue driver
     *
     * ## OPTIONS
     *
     * <driver>
     * : Driver tyyppi (database, redis)
     *
     * [--save]
     * : Tallenna asetus pysyvästi
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
            WP_CLI::error( "Tuntematon driver: {$driver}. Tuetut: " . implode( ', ', $supported ) );
            return;
        }

        if ( $driver === 'redis' && ! \WPCronV2\Queue\Drivers\DriverFactory::isRedisAvailable() ) {
            WP_CLI::error( 'PHP Redis extension ei ole asennettu.' );
            return;
        }

        try {
            if ( $driver === 'redis' ) {
                // Testaa yhteys ensin
                $result = \WPCronV2\Queue\Drivers\DriverFactory::testRedisConnection();
                if ( ! $result['success'] ) {
                    WP_CLI::error( 'Redis-yhteys epäonnistui: ' . $result['message'] );
                    return;
                }
            }

            if ( $save ) {
                $settings = get_option( 'wp_cron_v2_settings', [] );
                $settings['driver'] = $driver;
                update_option( 'wp_cron_v2_settings', $settings );
                WP_CLI::success( "Driver '{$driver}' asetettu ja tallennettu." );
            } else {
                WP_CLI::success( "Driver '{$driver}' asetettu tälle sessiolle." );
                WP_CLI::log( "Käytä --save tallentaaksesi pysyvästi." );
            }

        } catch ( \Exception $e ) {
            WP_CLI::error( 'Virhe: ' . $e->getMessage() );
        }
    }

    /**
     * Tyhjennä Redis-jonot (varoitus: poistaa kaikki jobit!)
     *
     * ## OPTIONS
     *
     * [--yes]
     * : Ohita varmistus
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
            WP_CLI::error( 'Tämä komento toimii vain Redis-driverilla.' );
            return;
        }

        WP_CLI::confirm( 'Tämä poistaa KAIKKI jobit Redistä. Oletko varma?', $assoc_args );

        if ( $driver->flush() ) {
            WP_CLI::success( 'Redis-jonot tyhjennetty.' );
        } else {
            WP_CLI::error( 'Tyhjennys epäonnistui.' );
        }
    }

    /**
     * Näytä multisite-tilastot
     *
     * ## OPTIONS
     *
     * [--format=<format>]
     * : Tulostusmuoto (table, json, csv)
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
            WP_CLI::error( 'Tämä komento toimii vain multisite-ympäristössä.' );
            return;
        }

        $stats = $network->getNetworkStats();

        if ( empty( $stats ) ) {
            WP_CLI::log( 'Ei jobeja.' );
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
     * Listaa multisite-sivustot
     *
     * ## OPTIONS
     *
     * [--format=<format>]
     * : Tulostusmuoto (table, json, csv)
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
            WP_CLI::error( 'Tämä komento toimii vain multisite-ympäristössä.' );
            return;
        }

        $site_ids = $network->getSites();

        if ( empty( $site_ids ) ) {
            WP_CLI::log( 'Ei sivustoja.' );
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
     * Käynnistä worker tietylle sivustolle
     *
     * ## OPTIONS
     *
     * <site_id>
     * : Sivuston ID
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
            WP_CLI::error( 'Tämä komento toimii vain multisite-ympäristössä.' );
            return;
        }

        $site_id = (int) $args[0];
        $queue = $assoc_args['queue'] ?? 'default';
        $sleep = (int) ( $assoc_args['sleep'] ?? 3 );

        // Tarkista että sivusto on olemassa
        $site_info = $network->getSiteInfo( $site_id );
        if ( ! $site_info ) {
            WP_CLI::error( "Sivustoa {$site_id} ei löydy." );
            return;
        }

        WP_CLI::log( "Worker käynnistetty sivustolle {$site_info['name']} (ID: {$site_id}), jono: {$queue}" );

        // Aseta driver käyttämään tiettyä sivustoa
        $driver = wp_cron_v2()->getDriver();
        if ( method_exists( $driver, 'setSiteId' ) ) {
            $driver->setSiteId( $site_id );
        }

        $processed = 0;

        while ( true ) {
            // Prosessoi jobit sivuston kontekstissa
            $result = $network->runOnSite( $site_id, function() use ( $queue ) {
                return wp_cron_v2()->process_next_job( $queue );
            } );

            if ( $result ) {
                $processed++;
                WP_CLI::log( "Job prosessoitu sivustolla {$site_id}. Yhteensä: {$processed}" );
            } else {
                sleep( $sleep );
            }

            if ( function_exists( 'pcntl_signal_dispatch' ) ) {
                pcntl_signal_dispatch();
            }
        }
    }
}

// Rekisteröi komennot
WP_CLI::add_command( 'cron-v2', __NAMESPACE__ . '\\CLI_Commands' );
