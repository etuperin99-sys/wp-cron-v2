<?php
/**
 * Job Chain - Suorita jobit peräkkäin ketjuna
 *
 * @package WPCronV2\Queue
 */

namespace WPCronV2\Queue;

use WPCronV2\Jobs\Job;

class Chain {

    /**
     * Chain ID
     *
     * @var string
     */
    private string $id;

    /**
     * Ketjun nimi
     *
     * @var string
     */
    private string $name;

    /**
     * Jobit ketjussa (järjestyksessä)
     *
     * @var array
     */
    private array $jobs = [];

    /**
     * Jono
     *
     * @var string
     */
    private string $queue = 'default';

    /**
     * Callback kun ketju valmis
     *
     * @var callable|null
     */
    private $then_callback = null;

    /**
     * Callback kun jokin epäonnistuu
     *
     * @var callable|null
     */
    private $catch_callback = null;

    /**
     * Konstruktori
     *
     * @param string $name Ketjun nimi
     */
    public function __construct( string $name = '' ) {
        $this->id = wp_generate_uuid4();
        $this->name = $name ?: 'chain-' . $this->id;
    }

    /**
     * Luo uusi ketju
     *
     * @param string $name
     * @return Chain
     */
    public static function create( string $name = '' ): Chain {
        return new self( $name );
    }

    /**
     * Lisää job ketjuun
     *
     * @param Job $job
     * @return Chain
     */
    public function add( Job $job ): Chain {
        $this->jobs[] = $job;
        return $this;
    }

    /**
     * Lisää jobit ketjuun
     *
     * @param array $jobs
     * @return Chain
     */
    public function pipe( array $jobs ): Chain {
        foreach ( $jobs as $job ) {
            if ( $job instanceof Job ) {
                $this->jobs[] = $job;
            }
        }
        return $this;
    }

    /**
     * Aseta jono
     *
     * @param string $queue
     * @return Chain
     */
    public function onQueue( string $queue ): Chain {
        $this->queue = sanitize_key( $queue );
        return $this;
    }

    /**
     * Callback kun ketju valmis
     *
     * @param callable $callback
     * @return Chain
     */
    public function then( callable $callback ): Chain {
        $this->then_callback = $callback;
        return $this;
    }

    /**
     * Callback kun jokin epäonnistuu
     *
     * @param callable $callback
     * @return Chain
     */
    public function catch( callable $callback ): Chain {
        $this->catch_callback = $callback;
        return $this;
    }

    /**
     * Käynnistä ketju
     *
     * @return string Chain ID
     */
    public function dispatch(): string {
        if ( empty( $this->jobs ) ) {
            return $this->id;
        }

        // Tallenna ketjun metatiedot
        $this->save_chain_meta();

        // Lähetä ensimmäinen job
        $first_job = $this->jobs[0];
        $first_job->chain_id = $this->id;
        $first_job->chain_position = 0;

        wp_cron_v2()
            ->queue( $this->queue )
            ->dispatch( $first_job );

        do_action( 'wp_cron_v2_chain_started', $this->id, count( $this->jobs ) );

        return $this->id;
    }

    /**
     * Tallenna ketjun metatiedot
     */
    private function save_chain_meta(): void {
        $chain_data = [
            'id'             => $this->id,
            'name'           => $this->name,
            'queue'          => $this->queue,
            'jobs'           => array_map( function( $job ) {
                return [
                    'class'   => get_class( $job ),
                    'payload' => maybe_serialize( $job ),
                ];
            }, $this->jobs ),
            'total_jobs'     => count( $this->jobs ),
            'current_index'  => 0,
            'status'         => 'running',
            'then_callback'  => $this->serialize_callback( $this->then_callback ),
            'catch_callback' => $this->serialize_callback( $this->catch_callback ),
            'created_at'     => current_time( 'mysql', true ),
        ];

        update_option( 'wp_cron_v2_chain_' . $this->id, $chain_data, false );
    }

    /**
     * Serialize callback
     *
     * @param callable|null $callback
     * @return string|null
     */
    private function serialize_callback( $callback ): ?string {
        if ( ! $callback ) {
            return null;
        }

        if ( is_string( $callback ) ) {
            return $callback;
        }

        if ( is_array( $callback ) && count( $callback ) === 2 ) {
            if ( is_string( $callback[0] ) && is_string( $callback[1] ) ) {
                return maybe_serialize( $callback );
            }
        }

        return null;
    }

    /**
     * Hae ketjun tiedot
     *
     * @param string $chain_id
     * @return array|null
     */
    public static function find( string $chain_id ): ?array {
        return get_option( 'wp_cron_v2_chain_' . $chain_id, null );
    }

    /**
     * Käsittele seuraava job ketjussa
     *
     * Kutsutaan kun edellinen job on valmis.
     *
     * @param string $chain_id
     * @param int $completed_index Juuri valmistuneen jobin indeksi
     */
    public static function processNext( string $chain_id, int $completed_index ): void {
        $chain_data = self::find( $chain_id );

        if ( ! $chain_data ) {
            return;
        }

        $next_index = $completed_index + 1;

        // Tarkista onko ketju valmis
        if ( $next_index >= $chain_data['total_jobs'] ) {
            self::markComplete( $chain_id );
            return;
        }

        // Päivitä current_index
        $chain_data['current_index'] = $next_index;
        update_option( 'wp_cron_v2_chain_' . $chain_id, $chain_data, false );

        // Lähetä seuraava job
        $job_data = $chain_data['jobs'][ $next_index ];
        $job = maybe_unserialize( $job_data['payload'] );

        if ( is_object( $job ) ) {
            $job->chain_id = $chain_id;
            $job->chain_position = $next_index;

            wp_cron_v2()
                ->queue( $chain_data['queue'] )
                ->dispatch( $job );
        }
    }

    /**
     * Merkitse ketju valmiiksi
     *
     * @param string $chain_id
     */
    public static function markComplete( string $chain_id ): void {
        $chain_data = self::find( $chain_id );

        if ( ! $chain_data ) {
            return;
        }

        $chain_data['status'] = 'completed';
        $chain_data['finished_at'] = current_time( 'mysql', true );
        update_option( 'wp_cron_v2_chain_' . $chain_id, $chain_data, false );

        // Kutsu then callback
        if ( $chain_data['then_callback'] ) {
            $callback = maybe_unserialize( $chain_data['then_callback'] );
            if ( is_callable( $callback ) ) {
                call_user_func( $callback, $chain_data );
            }
        }

        do_action( 'wp_cron_v2_chain_completed', $chain_id );
    }

    /**
     * Merkitse ketju epäonnistuneeksi
     *
     * @param string $chain_id
     * @param \Throwable $exception
     */
    public static function markFailed( string $chain_id, \Throwable $exception ): void {
        $chain_data = self::find( $chain_id );

        if ( ! $chain_data ) {
            return;
        }

        $chain_data['status'] = 'failed';
        $chain_data['error'] = $exception->getMessage();
        $chain_data['failed_at'] = current_time( 'mysql', true );
        update_option( 'wp_cron_v2_chain_' . $chain_id, $chain_data, false );

        // Kutsu catch callback
        if ( $chain_data['catch_callback'] ) {
            $callback = maybe_unserialize( $chain_data['catch_callback'] );
            if ( is_callable( $callback ) ) {
                call_user_func( $callback, $chain_data, $exception );
            }
        }

        do_action( 'wp_cron_v2_chain_failed', $chain_id, $exception );
    }

    /**
     * Hae kaikki ketjut
     *
     * @return array
     */
    public static function all(): array {
        global $wpdb;

        $chains = [];

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $options = $wpdb->get_results(
            "SELECT option_name, option_value
            FROM {$wpdb->options}
            WHERE option_name LIKE 'wp_cron_v2_chain_%'
            ORDER BY option_id DESC
            LIMIT 50"
        );

        foreach ( $options as $option ) {
            $chain_data = maybe_unserialize( $option->option_value );
            if ( is_array( $chain_data ) ) {
                $chains[] = $chain_data;
            }
        }

        return $chains;
    }

    /**
     * Poista vanha ketju
     *
     * @param string $chain_id
     * @return bool
     */
    public static function delete( string $chain_id ): bool {
        return delete_option( 'wp_cron_v2_chain_' . $chain_id );
    }

    /**
     * Hae ID
     *
     * @return string
     */
    public function getId(): string {
        return $this->id;
    }
}
