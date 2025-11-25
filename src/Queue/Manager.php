<?php
/**
 * Queue Manager - Jonohallinnan ydin
 *
 * @package WPCronV2\Queue
 */

namespace WPCronV2\Queue;

use WPCronV2\Queue\Drivers\DriverInterface;
use WPCronV2\Queue\Drivers\DriverFactory;

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
     * Queue driver
     *
     * @var DriverInterface
     */
    private DriverInterface $driver;

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
        $this->driver = DriverFactory::create();
        $this->init_hooks();
    }

    /**
     * Hae aktiivinen driver
     *
     * @return DriverInterface
     */
    public function getDriver(): DriverInterface {
        return $this->driver;
    }

    /**
     * Vaihda driver
     *
     * @param string $driver Driver tyyppi
     * @param array $config Asetukset
     * @return Manager
     */
    public function setDriver( string $driver, array $config = [] ): Manager {
        $this->driver = DriverFactory::create( $driver, $config );
        return $this;
    }

    /**
     * Alusta hookit
     */
    private function init_hooks(): void {
        add_action( 'init', [ $this, 'maybe_process_queue' ] );

        // Käsittele ketjun jatkaminen kun job valmistuu
        add_action( 'wp_cron_v2_job_completed', [ $this, 'handle_chain_progress' ], 10, 2 );

        // Käsittele ketjun epäonnistuminen
        add_action( 'wp_cron_v2_job_failed', [ $this, 'handle_chain_failure' ], 10, 2 );
    }

    /**
     * Käsittele ketjun eteneminen
     *
     * @param int $job_id Job ID
     * @param object $job Job-instanssi
     */
    public function handle_chain_progress( int $job_id, $job ): void {
        if ( ! isset( $job->chain_id ) || ! $job->chain_id ) {
            return;
        }

        Chain::processNext( $job->chain_id, $job->chain_position ?? 0 );
    }

    /**
     * Käsittele ketjun epäonnistuminen
     *
     * @param int $job_id Job ID
     * @param \Throwable $exception Virhe
     */
    public function handle_chain_failure( int $job_id, \Throwable $exception ): void {
        global $wpdb;

        $table = $wpdb->prefix . 'job_queue';

        // Hae jobin tiedot
        $job_row = $wpdb->get_row(
            $wpdb->prepare( "SELECT payload FROM {$table} WHERE id = %d", $job_id )
        );

        if ( ! $job_row ) {
            return;
        }

        $job = maybe_unserialize( $job_row->payload );

        if ( ! isset( $job->chain_id ) || ! $job->chain_id ) {
            return;
        }

        Chain::markFailed( $job->chain_id, $exception );
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
     * Lisää job jonoon
     *
     * @param Jobs\Job $job Job-instanssi
     * @param int $delay Viive sekunteina
     * @return int|false
     */
    private function push_to_queue( $job, int $delay = 0 ) {
        // Tarkista unique constraint
        if ( ! empty( $job->unique_key ) ) {
            if ( $this->is_unique_locked( $job->unique_key ) ) {
                // Job on jo jonossa tai käsittelyssä
                do_action( 'wp_cron_v2_job_duplicate_blocked', $job );

                // Resetoi arvot
                $this->current_queue = 'default';
                $this->priority = 'normal';

                return false;
            }

            // Aseta unique lock
            $lock_time = $job->unique_for ?? 3600; // Oletus 1 tunti
            $this->set_unique_lock( $job->unique_key, $lock_time );
        }

        $now = current_time( 'mysql', true );

        $available_at = $delay > 0
            ? gmdate( 'Y-m-d H:i:s', time() + $delay )
            : $now;

        // Redis-driver käyttää timestampeja
        $available_at_value = $delay > 0 ? time() + $delay : $available_at;

        $job_data = [
            'job_type'     => get_class( $job ),
            'payload'      => maybe_serialize( $job ),
            'queue'        => $this->current_queue,
            'priority'     => $this->priority,
            'max_attempts' => $job->max_attempts ?? 3,
            'available_at' => $available_at_value,
        ];

        $job_id = $this->driver->push( $job_data );

        // Resetoi arvot
        $this->current_queue = 'default';
        $this->priority = 'normal';

        if ( $job_id ) {
            do_action( 'wp_cron_v2_job_queued', $job_id, $job );
            return $job_id;
        }

        // Jos insert epäonnistui, vapauta unique lock
        if ( ! empty( $job->unique_key ) ) {
            $this->release_unique_lock( $job->unique_key );
        }

        return false;
    }

    /**
     * Tarkista onko unique lock voimassa
     *
     * @param string $key Unique key
     * @return bool
     */
    public function is_unique_locked( string $key ): bool {
        return false !== get_transient( 'wp_cron_v2_unique_' . md5( $key ) );
    }

    /**
     * Aseta unique lock
     *
     * @param string $key Unique key
     * @param int $ttl Lock kesto sekunteina
     */
    public function set_unique_lock( string $key, int $ttl ): void {
        set_transient( 'wp_cron_v2_unique_' . md5( $key ), time(), $ttl );
    }

    /**
     * Vapauta unique lock
     *
     * @param string $key Unique key
     */
    public function release_unique_lock( string $key ): void {
        delete_transient( 'wp_cron_v2_unique_' . md5( $key ) );
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
        // Hae ja lukitse seuraava job
        $job_row = $this->driver->pop( $queue );

        if ( ! $job_row ) {
            return false;
        }

        // Suorita job
        try {
            $job = maybe_unserialize( $job_row->payload );

            if ( ! is_object( $job ) || ! method_exists( $job, 'handle' ) ) {
                throw new \Exception( 'Invalid job payload' );
            }

            // Tarkista rate limit
            $rate_limiter = RateLimiter::get_instance();
            if ( ! $rate_limiter->hitForJob( $job ) ) {
                // Rate limit ylitetty - palauta jonoon pienellä viiveellä
                $delay = $rate_limiter->availableIn(
                    $job->rate_limit['key'] ?? $rate_limiter->keyForJobType( get_class( $job ) ),
                    $job->rate_limit['per'] ?? 60
                );

                $this->driver->release( $job_row->id, max( 1, $delay ), (int) $job_row->attempts );

                do_action( 'wp_cron_v2_job_rate_limited', $job_row->id, $job, $delay );
                return false;
            }

            $job->handle();

            // Merkitse valmiiksi
            $this->driver->complete( $job_row->id );

            // Vapauta unique lock jos asetettu
            if ( ! empty( $job->unique_key ) ) {
                $this->release_unique_lock( $job->unique_key );
            }

            do_action( 'wp_cron_v2_job_completed', $job_row->id, $job );
            return true;

        } catch ( \Throwable $e ) {
            $attempts = (int) $job_row->attempts + 1;
            $max_attempts = (int) $job_row->max_attempts;

            // Hae job uudelleen unique_key:n vapauttamista varten
            $job = maybe_unserialize( $job_row->payload );

            if ( $attempts >= $max_attempts ) {
                // Lopullinen epäonnistuminen
                $this->driver->fail( $job_row->id, $e->getMessage(), $attempts, true );

                // Vapauta unique lock jos asetettu
                if ( is_object( $job ) && ! empty( $job->unique_key ) ) {
                    $this->release_unique_lock( $job->unique_key );
                }

                do_action( 'wp_cron_v2_job_failed', $job_row->id, $e );
            } else {
                // Yritä uudelleen (exponential backoff)
                $backoff = pow( 2, $attempts ) * 60; // 2min, 4min, 8min...

                $this->driver->release( $job_row->id, $backoff, $attempts, $e->getMessage() );

                do_action( 'wp_cron_v2_job_retrying', $job_row->id, $attempts, $e );
            }

            return false;
        }
    }

    /**
     * Vapauta jumittuneet jobit (stale jobs)
     *
     * Jos job on ollut 'running' tilassa yli timeout-ajan,
     * se palautetaan jonoon tai merkitään epäonnistuneeksi.
     *
     * @param int $timeout_minutes Timeout minuuteissa (oletus 30)
     * @return int Vapautettujen jobien määrä
     */
    public function release_stale_jobs( int $timeout_minutes = 30 ): int {
        return $this->driver->releaseStale( $timeout_minutes );
    }

    /**
     * Siivoa vanhat valmiit jobit
     *
     * @param int $days_old Poista tätä vanhemmat (päivinä)
     * @param bool $include_failed Sisällytä epäonnistuneet
     * @return int Poistettujen määrä
     */
    public function cleanup_old_jobs( int $days_old = 7, bool $include_failed = false ): int {
        return $this->driver->cleanup( $days_old, $include_failed );
    }

    /**
     * Poista epäonnistuneet jobit
     *
     * @param int|null $older_than_days Poista vanhemmat kuin X päivää (null = kaikki)
     * @return int Poistettujen määrä
     */
    public function flush_failed( ?int $older_than_days = null ): int {
        return $this->driver->flushFailed( $older_than_days );
    }

    /**
     * Yritä epäonnistuneet jobit uudelleen
     *
     * @param string|null $queue Jonon nimi (null = kaikki)
     * @param int|null $limit Maksimimäärä
     * @return int Uudelleenyritettyjen määrä
     */
    public function retry_failed( ?string $queue = null, ?int $limit = null ): int {
        return $this->driver->retryFailed( $queue, $limit );
    }

    /**
     * Siirrä epäonnistunut job historiaan
     *
     * @param int $job_id
     * @return bool
     */
    public function move_to_failed_history( int $job_id ): bool {
        global $wpdb;

        $table = $wpdb->prefix . 'job_queue';
        $failed_table = $wpdb->prefix . 'job_queue_failed';

        // Hae job
        $job = $this->driver->find( $job_id );

        if ( ! $job || $job->status !== 'failed' ) {
            return false;
        }

        // Lisää historiaan (vain database-driverille)
        $wpdb->insert(
            $failed_table,
            [
                'job_type'  => $job->job_type,
                'payload'   => $job->payload,
                'queue'     => $job->queue,
                'exception' => $job->error_message ?? '',
                'failed_at' => current_time( 'mysql', true ),
            ]
        );

        // Poista alkuperäisestä
        $wpdb->delete( $table, [ 'id' => $job_id ] );

        return true;
    }

    /**
     * Hae jonon tilastot
     *
     * @param string|null $queue Jonon nimi (null = kaikki)
     * @return array
     */
    public function get_stats( ?string $queue = null ): array {
        return $this->driver->getStats( $queue );
    }

    /**
     * Hae jobit
     *
     * @param array $filters Suodattimet
     * @return array
     */
    public function get_jobs( array $filters = [] ): array {
        return $this->driver->getJobs( $filters );
    }

    /**
     * Hae jonot
     *
     * @return array
     */
    public function get_queues(): array {
        return $this->driver->getQueues();
    }

    /**
     * Hae job
     *
     * @param int $job_id Job ID
     * @return object|null
     */
    public function find_job( int $job_id ): ?object {
        return $this->driver->find( $job_id );
    }

    /**
     * Peruuta job
     *
     * @param int $job_id Job ID
     * @return bool
     */
    public function cancel_job( int $job_id ): bool {
        return $this->driver->cancel( $job_id );
    }

    /**
     * Tarkista driver-yhteys
     *
     * @return bool
     */
    public function is_connected(): bool {
        return $this->driver->isConnected();
    }
}
