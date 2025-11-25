<?php
/**
 * Queue Manager - Jonohallinnan ydin
 *
 * @package WPCronV2\Queue
 */

namespace WPCronV2\Queue;

class Manager {

    /**
     * Singleton instanssi
     *
     * @var Manager|null
     */
    private static ?Manager $instance = null;

    /**
     * Aktiivinen jono
     *
     * @var string
     */
    private string $current_queue = 'default';

    /**
     * Prioriteetti
     *
     * @var string
     */
    private string $priority = 'normal';

    /**
     * Hae singleton instanssi
     *
     * @return Manager
     */
    public static function get_instance(): Manager {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Konstruktori
     */
    private function __construct() {
        $this->init_hooks();
    }

    /**
     * Alusta hookit
     */
    private function init_hooks(): void {
        add_action( 'init', [ $this, 'maybe_process_queue' ] );
    }

    /**
     * Aseta jono
     *
     * @param string $queue Jonon nimi
     * @return Manager
     */
    public function queue( string $queue ): Manager {
        $this->current_queue = sanitize_key( $queue );
        return $this;
    }

    /**
     * Aseta prioriteetti
     *
     * @param string $priority Prioriteetti (low, normal, high)
     * @return Manager
     */
    public function priority( string $priority ): Manager {
        $allowed = [ 'low', 'normal', 'high' ];
        $this->priority = in_array( $priority, $allowed, true ) ? $priority : 'normal';
        return $this;
    }

    /**
     * Lähetä job jonoon välittömästi
     *
     * @param Jobs\Job $job Job-instanssi
     * @return int|false Job ID tai false virheessä
     */
    public function dispatch( $job ) {
        return $this->push_to_queue( $job, 0 );
    }

    /**
     * Lähetä job jonoon viiveellä
     *
     * @param int $delay Viive sekunteina
     * @param Jobs\Job $job Job-instanssi
     * @return int|false Job ID tai false virheessä
     */
    public function later( int $delay, $job ) {
        return $this->push_to_queue( $job, $delay );
    }

    /**
     * Lisää job tietokantajonoon
     *
     * @param Jobs\Job $job Job-instanssi
     * @param int $delay Viive sekunteina
     * @return int|false
     */
    private function push_to_queue( $job, int $delay = 0 ) {
        global $wpdb;

        $table = $wpdb->prefix . 'job_queue';
        $now = current_time( 'mysql', true );

        $available_at = $delay > 0
            ? gmdate( 'Y-m-d H:i:s', time() + $delay )
            : $now;

        $data = [
            'job_type'     => get_class( $job ),
            'payload'      => maybe_serialize( $job ),
            'queue'        => $this->current_queue,
            'priority'     => $this->priority,
            'attempts'     => 0,
            'max_attempts' => $job->max_attempts ?? 3,
            'available_at' => $available_at,
            'created_at'   => $now,
            'updated_at'   => $now,
            'status'       => 'queued',
        ];

        $result = $wpdb->insert( $table, $data );

        // Resetoi arvot
        $this->current_queue = 'default';
        $this->priority = 'normal';

        if ( $result ) {
            do_action( 'wp_cron_v2_job_queued', $wpdb->insert_id, $job );
            return $wpdb->insert_id;
        }

        return false;
    }

    /**
     * Prosessoi jonoa (fallback HTTP-triggerille)
     */
    public function maybe_process_queue(): void {
        // Tämä on fallback jos worker daemon ei ole käynnissä
        // Oikeassa tuotannossa käytetään wp cron worker -komentoa
        if ( defined( 'DOING_CRON' ) && DOING_CRON ) {
            $this->process_next_job();
        }
    }

    /**
     * Prosessoi seuraava job jonosta
     *
     * @param string $queue Jonon nimi
     * @return bool
     */
    public function process_next_job( string $queue = 'default' ): bool {
        global $wpdb;

        $table = $wpdb->prefix . 'job_queue';
        $now = current_time( 'mysql', true );

        // Hae seuraava käsiteltävä job (prioriteetin mukaan)
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $job_row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$table}
                WHERE queue = %s
                AND status = 'queued'
                AND available_at <= %s
                ORDER BY FIELD(priority, 'high', 'normal', 'low'), created_at ASC
                LIMIT 1",
                $queue,
                $now
            )
        );

        if ( ! $job_row ) {
            return false;
        }

        // Lukitse job
        $locked = $wpdb->update(
            $table,
            [
                'status' => 'running',
                'updated_at' => $now
            ],
            [
                'id' => $job_row->id,
                'status' => 'queued'
            ]
        );

        if ( ! $locked ) {
            return false; // Toinen prosessi ehti ensin
        }

        // Suorita job
        try {
            $job = maybe_unserialize( $job_row->payload );

            if ( ! is_object( $job ) || ! method_exists( $job, 'handle' ) ) {
                throw new \Exception( 'Invalid job payload' );
            }

            $job->handle();

            // Merkitse valmiiksi
            $wpdb->update(
                $table,
                [
                    'status' => 'completed',
                    'updated_at' => current_time( 'mysql', true )
                ],
                [ 'id' => $job_row->id ]
            );

            do_action( 'wp_cron_v2_job_completed', $job_row->id, $job );
            return true;

        } catch ( \Throwable $e ) {
            $attempts = (int) $job_row->attempts + 1;
            $max_attempts = (int) $job_row->max_attempts;

            if ( $attempts >= $max_attempts ) {
                // Lopullinen epäonnistuminen
                $wpdb->update(
                    $table,
                    [
                        'status' => 'failed',
                        'attempts' => $attempts,
                        'error_message' => $e->getMessage(),
                        'updated_at' => current_time( 'mysql', true )
                    ],
                    [ 'id' => $job_row->id ]
                );

                do_action( 'wp_cron_v2_job_failed', $job_row->id, $e );
            } else {
                // Yritä uudelleen (exponential backoff)
                $backoff = pow( 2, $attempts ) * 60; // 2min, 4min, 8min...
                $next_attempt = gmdate( 'Y-m-d H:i:s', time() + $backoff );

                $wpdb->update(
                    $table,
                    [
                        'status' => 'queued',
                        'attempts' => $attempts,
                        'available_at' => $next_attempt,
                        'error_message' => $e->getMessage(),
                        'updated_at' => current_time( 'mysql', true )
                    ],
                    [ 'id' => $job_row->id ]
                );

                do_action( 'wp_cron_v2_job_retrying', $job_row->id, $attempts, $e );
            }

            return false;
        }
    }

    /**
     * Hae jonon tilastot
     *
     * @param string $queue Jonon nimi
     * @return array
     */
    public function get_stats( string $queue = 'default' ): array {
        global $wpdb;
        $table = $wpdb->prefix . 'job_queue';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $stats = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT status, COUNT(*) as count
                FROM {$table}
                WHERE queue = %s
                GROUP BY status",
                $queue
            ),
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
}
