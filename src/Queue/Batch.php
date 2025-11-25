<?php
/**
 * Job Batch - Käsittele useita jobeja ryhmänä
 *
 * @package WPCronV2\Queue
 */

namespace WPCronV2\Queue;

use WPCronV2\Jobs\Job;

class Batch {

    /**
     * Batch ID
     *
     * @var string
     */
    private string $id;

    /**
     * Batchin nimi
     *
     * @var string
     */
    private string $name;

    /**
     * Jobit batchissa
     *
     * @var array
     */
    private array $jobs = [];

    /**
     * Job ID:t tietokannassa
     *
     * @var array
     */
    private array $job_ids = [];

    /**
     * Jono
     *
     * @var string
     */
    private string $queue = 'default';

    /**
     * Callback kun kaikki valmiit
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
     * Callback kun kaikki käsitelty (onnistui tai ei)
     *
     * @var callable|null
     */
    private $finally_callback = null;

    /**
     * Salli virheiden jälkeen jatkaminen
     *
     * @var bool
     */
    private bool $allow_failures = true;

    /**
     * Konstruktori
     *
     * @param string $name Batchin nimi
     */
    public function __construct( string $name = '' ) {
        $this->id = wp_generate_uuid4();
        $this->name = $name ?: 'batch-' . $this->id;
    }

    /**
     * Luo uusi batch
     *
     * @param string $name
     * @return Batch
     */
    public static function create( string $name = '' ): Batch {
        return new self( $name );
    }

    /**
     * Lisää job batchiin
     *
     * @param Job $job
     * @return Batch
     */
    public function add( Job $job ): Batch {
        $this->jobs[] = $job;
        return $this;
    }

    /**
     * Lisää useita jobeja
     *
     * @param array $jobs
     * @return Batch
     */
    public function addMany( array $jobs ): Batch {
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
     * @return Batch
     */
    public function onQueue( string $queue ): Batch {
        $this->queue = sanitize_key( $queue );
        return $this;
    }

    /**
     * Callback kun kaikki onnistuneet
     *
     * @param callable $callback function( Batch $batch )
     * @return Batch
     */
    public function then( callable $callback ): Batch {
        $this->then_callback = $callback;
        return $this;
    }

    /**
     * Callback kun jokin epäonnistuu
     *
     * @param callable $callback function( Batch $batch, \Throwable $e )
     * @return Batch
     */
    public function catch( callable $callback ): Batch {
        $this->catch_callback = $callback;
        return $this;
    }

    /**
     * Callback kun kaikki käsitelty
     *
     * @param callable $callback function( Batch $batch )
     * @return Batch
     */
    public function finally( callable $callback ): Batch {
        $this->finally_callback = $callback;
        return $this;
    }

    /**
     * Älä salli virheiden jälkeen jatkamista
     *
     * @return Batch
     */
    public function dontAllowFailures(): Batch {
        $this->allow_failures = false;
        return $this;
    }

    /**
     * Lähetä batch jonoon
     *
     * @return string Batch ID
     */
    public function dispatch(): string {
        global $wpdb;

        if ( empty( $this->jobs ) ) {
            return $this->id;
        }

        // Tallenna batch metatieto
        $this->save_batch_meta();

        // Lisää jobit jonoon
        $table = $wpdb->prefix . 'job_queue';
        $now = current_time( 'mysql', true );

        foreach ( $this->jobs as $job ) {
            $data = [
                'job_type'     => get_class( $job ),
                'payload'      => maybe_serialize( $job ),
                'queue'        => $this->queue,
                'priority'     => $job->priority ?? 'normal',
                'attempts'     => 0,
                'max_attempts' => $job->max_attempts ?? 3,
                'available_at' => $now,
                'created_at'   => $now,
                'updated_at'   => $now,
                'status'       => 'queued',
                'batch_id'     => $this->id,
            ];

            $wpdb->insert( $table, $data );
            $this->job_ids[] = $wpdb->insert_id;
        }

        do_action( 'wp_cron_v2_batch_dispatched', $this->id, count( $this->jobs ) );

        return $this->id;
    }

    /**
     * Tallenna batch metadata
     */
    private function save_batch_meta(): void {
        global $wpdb;

        $table = $wpdb->prefix . 'job_batches';

        $wpdb->insert(
            $table,
            [
                'id'               => $this->id,
                'name'             => $this->name,
                'total_jobs'       => count( $this->jobs ),
                'pending_jobs'     => count( $this->jobs ),
                'failed_jobs'      => 0,
                'options'          => maybe_serialize( [
                    'allow_failures'   => $this->allow_failures,
                    'then_callback'    => $this->serialize_callback( $this->then_callback ),
                    'catch_callback'   => $this->serialize_callback( $this->catch_callback ),
                    'finally_callback' => $this->serialize_callback( $this->finally_callback ),
                ] ),
                'created_at'       => current_time( 'mysql', true ),
                'finished_at'      => null,
            ]
        );
    }

    /**
     * Serialize callback (vain jos se on tallennettavissa)
     *
     * @param callable|null $callback
     * @return string|null
     */
    private function serialize_callback( $callback ): ?string {
        if ( ! $callback ) {
            return null;
        }

        // Vain string-callbackit (funktioiden nimet) voidaan tallentaa
        if ( is_string( $callback ) ) {
            return $callback;
        }

        // Array callbackit (luokka + metodi)
        if ( is_array( $callback ) && count( $callback ) === 2 ) {
            if ( is_string( $callback[0] ) && is_string( $callback[1] ) ) {
                return maybe_serialize( $callback );
            }
        }

        return null;
    }

    /**
     * Hae batch ID
     *
     * @return string
     */
    public function getId(): string {
        return $this->id;
    }

    /**
     * Hae batchin nimi
     *
     * @return string
     */
    public function getName(): string {
        return $this->name;
    }

    /**
     * Hae jobien määrä
     *
     * @return int
     */
    public function getJobCount(): int {
        return count( $this->jobs );
    }

    /**
     * Hae batch tietokannasta
     *
     * @param string $batch_id
     * @return array|null
     */
    public static function find( string $batch_id ): ?array {
        global $wpdb;

        $table = $wpdb->prefix . 'job_batches';

        $batch = $wpdb->get_row(
            $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %s", $batch_id ),
            ARRAY_A
        );

        if ( ! $batch ) {
            return null;
        }

        $batch['options'] = maybe_unserialize( $batch['options'] );

        return $batch;
    }

    /**
     * Hae batchin tilastot
     *
     * @param string $batch_id
     * @return array
     */
    public static function getStats( string $batch_id ): array {
        global $wpdb;

        $table = $wpdb->prefix . 'job_queue';

        $stats = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT status, COUNT(*) as count
                FROM {$table}
                WHERE batch_id = %s
                GROUP BY status",
                $batch_id
            ),
            ARRAY_A
        );

        $result = [
            'queued'    => 0,
            'running'   => 0,
            'completed' => 0,
            'failed'    => 0,
        ];

        foreach ( $stats as $stat ) {
            if ( isset( $result[ $stat['status'] ] ) ) {
                $result[ $stat['status'] ] = (int) $stat['count'];
            }
        }

        $result['total'] = array_sum( $result );
        $result['progress'] = $result['total'] > 0
            ? round( ( $result['completed'] + $result['failed'] ) / $result['total'] * 100, 1 )
            : 0;

        return $result;
    }

    /**
     * Tarkista onko batch valmis
     *
     * @param string $batch_id
     * @return bool
     */
    public static function isFinished( string $batch_id ): bool {
        $stats = self::getStats( $batch_id );
        return ( $stats['queued'] + $stats['running'] ) === 0;
    }

    /**
     * Peruuta batch
     *
     * @param string $batch_id
     * @return int Peruutettujen jobien määrä
     */
    public static function cancel( string $batch_id ): int {
        global $wpdb;

        $table = $wpdb->prefix . 'job_queue';

        $cancelled = $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$table}
                SET status = 'cancelled', updated_at = %s
                WHERE batch_id = %s AND status = 'queued'",
                current_time( 'mysql', true ),
                $batch_id
            )
        );

        do_action( 'wp_cron_v2_batch_cancelled', $batch_id, $cancelled );

        return (int) $cancelled;
    }

    /**
     * Listaa batchit
     *
     * @param int $limit
     * @return array
     */
    public static function all( int $limit = 50 ): array {
        global $wpdb;

        $table = $wpdb->prefix . 'job_batches';

        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$table} ORDER BY created_at DESC LIMIT %d",
                $limit
            ),
            ARRAY_A
        );
    }
}
