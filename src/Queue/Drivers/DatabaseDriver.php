<?php
/**
 * Database Driver (MySQL/SQLite)
 *
 * @package WPCronV2\Queue\Drivers
 */

namespace WPCronV2\Queue\Drivers;

class DatabaseDriver implements DriverInterface {

    /**
     * Taulun nimi
     *
     * @var string
     */
    private string $table;

    /**
     * Failed-taulun nimi
     *
     * @var string
     */
    private string $failed_table;

    /**
     * Site ID (multisite)
     *
     * @var int|null
     */
    private ?int $site_id = null;

    /**
     * Konstruktori
     *
     * @param int|null $site_id Site ID multisitelle (null = ei suodatusta)
     */
    public function __construct( ?int $site_id = null ) {
        global $wpdb;
        $this->table = $wpdb->prefix . 'job_queue';
        $this->failed_table = $wpdb->prefix . 'job_queue_failed';

        // Multisite: käytä nykyistä sivustoa jos ei määritelty
        if ( $site_id === null && is_multisite() ) {
            $this->site_id = get_current_blog_id();
        } else {
            $this->site_id = $site_id;
        }
    }

    /**
     * Aseta site ID
     *
     * @param int|null $site_id
     */
    public function setSiteId( ?int $site_id ): void {
        $this->site_id = $site_id;
    }

    /**
     * Hae site ID
     *
     * @return int|null
     */
    public function getSiteId(): ?int {
        return $this->site_id;
    }

    /**
     * Rakenna site_id WHERE-ehto
     *
     * @return string
     */
    private function getSiteIdWhere(): string {
        if ( $this->site_id === null || $this->site_id === 0 ) {
            return '';
        }

        global $wpdb;
        return $wpdb->prepare( ' AND site_id = %d', $this->site_id );
    }

    /**
     * {@inheritdoc}
     */
    public function push( array $job_data ) {
        global $wpdb;

        $now = current_time( 'mysql', true );

        $data = [
            'job_type'     => $job_data['job_type'],
            'payload'      => $job_data['payload'],
            'queue'        => $job_data['queue'] ?? 'default',
            'priority'     => $job_data['priority'] ?? 'normal',
            'attempts'     => 0,
            'max_attempts' => $job_data['max_attempts'] ?? 3,
            'available_at' => $job_data['available_at'] ?? $now,
            'created_at'   => $now,
            'updated_at'   => $now,
            'status'       => 'queued',
        ];

        // Lisää batch_id jos annettu
        if ( ! empty( $job_data['batch_id'] ) ) {
            $data['batch_id'] = $job_data['batch_id'];
        }

        // Lisää site_id multisite-ympäristössä
        if ( $this->site_id !== null && $this->site_id > 0 ) {
            $data['site_id'] = $this->site_id;
        }

        $result = $wpdb->insert( $this->table, $data );

        return $result ? $wpdb->insert_id : false;
    }

    /**
     * {@inheritdoc}
     */
    public function pop( string $queue ): ?object {
        global $wpdb;

        $now = current_time( 'mysql', true );
        $site_where = $this->getSiteIdWhere();

        // Hae seuraava käsiteltävä job (prioriteetin mukaan)
        $job_row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$this->table}
                WHERE queue = %s
                AND status = 'queued'
                AND available_at <= %s
                {$site_where}
                ORDER BY FIELD(priority, 'high', 'normal', 'low'), created_at ASC
                LIMIT 1",
                $queue,
                $now
            )
        );

        if ( ! $job_row ) {
            return null;
        }

        // Lukitse job
        $locked = $wpdb->update(
            $this->table,
            [
                'status'     => 'running',
                'updated_at' => $now,
            ],
            [
                'id'     => $job_row->id,
                'status' => 'queued',
            ]
        );

        if ( ! $locked ) {
            return null; // Toinen prosessi ehti ensin
        }

        // Päivitä status muistissa
        $job_row->status = 'running';

        return $job_row;
    }

    /**
     * {@inheritdoc}
     */
    public function complete( int $job_id ): bool {
        global $wpdb;

        return (bool) $wpdb->update(
            $this->table,
            [
                'status'     => 'completed',
                'updated_at' => current_time( 'mysql', true ),
            ],
            [ 'id' => $job_id ]
        );
    }

    /**
     * {@inheritdoc}
     */
    public function fail( int $job_id, string $error, int $attempts, bool $is_final = false ): bool {
        global $wpdb;

        if ( $is_final ) {
            return (bool) $wpdb->update(
                $this->table,
                [
                    'status'        => 'failed',
                    'attempts'      => $attempts,
                    'error_message' => $error,
                    'updated_at'    => current_time( 'mysql', true ),
                ],
                [ 'id' => $job_id ]
            );
        }

        return false;
    }

    /**
     * {@inheritdoc}
     */
    public function release( int $job_id, int $delay, int $attempts, string $error = '' ): bool {
        global $wpdb;

        $next_attempt = gmdate( 'Y-m-d H:i:s', time() + $delay );

        $data = [
            'status'       => 'queued',
            'attempts'     => $attempts,
            'available_at' => $next_attempt,
            'updated_at'   => current_time( 'mysql', true ),
        ];

        if ( $error ) {
            $data['error_message'] = $error;
        }

        return (bool) $wpdb->update( $this->table, $data, [ 'id' => $job_id ] );
    }

    /**
     * {@inheritdoc}
     */
    public function find( int $job_id ): ?object {
        global $wpdb;

        return $wpdb->get_row(
            $wpdb->prepare( "SELECT * FROM {$this->table} WHERE id = %d", $job_id )
        );
    }

    /**
     * {@inheritdoc}
     */
    public function cancel( int $job_id ): bool {
        global $wpdb;

        return (bool) $wpdb->delete( $this->table, [ 'id' => $job_id, 'status' => 'queued' ] );
    }

    /**
     * {@inheritdoc}
     */
    public function releaseStale( int $timeout_minutes = 30 ): int {
        global $wpdb;

        $cutoff = gmdate( 'Y-m-d H:i:s', time() - ( $timeout_minutes * 60 ) );

        // Hae jumittuneet jobit
        $stale_jobs = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$this->table}
                WHERE status = 'running'
                AND updated_at < %s",
                $cutoff
            )
        );

        $released = 0;

        foreach ( $stale_jobs as $job_row ) {
            $attempts = (int) $job_row->attempts + 1;
            $max_attempts = (int) $job_row->max_attempts;

            if ( $attempts >= $max_attempts ) {
                // Merkitse epäonnistuneeksi
                $this->fail(
                    $job_row->id,
                    'Job timeout - exceeded ' . $timeout_minutes . ' minutes',
                    $attempts,
                    true
                );

                do_action( 'wp_cron_v2_job_timeout', $job_row->id, 'failed' );
            } else {
                // Palauta jonoon retry-logiikalla
                $backoff = pow( 2, $attempts ) * 60;
                $this->release( $job_row->id, $backoff, $attempts, 'Job timeout - will retry' );

                do_action( 'wp_cron_v2_job_timeout', $job_row->id, 'retrying' );
            }

            $released++;
        }

        return $released;
    }

    /**
     * {@inheritdoc}
     */
    public function cleanup( int $days = 7, bool $include_failed = false ): int {
        global $wpdb;

        $cutoff = gmdate( 'Y-m-d H:i:s', strtotime( "-{$days} days" ) );

        $statuses = [ 'completed' ];
        if ( $include_failed ) {
            $statuses[] = 'failed';
        }

        $placeholders = implode( ', ', array_fill( 0, count( $statuses ), '%s' ) );

        $deleted = $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$this->table}
                WHERE status IN ({$placeholders})
                AND updated_at < %s",
                array_merge( $statuses, [ $cutoff ] )
            )
        );

        do_action( 'wp_cron_v2_jobs_cleaned', $deleted );

        return (int) $deleted;
    }

    /**
     * {@inheritdoc}
     */
    public function flushFailed( ?int $older_than_days = null ): int {
        global $wpdb;

        if ( $older_than_days !== null ) {
            $cutoff = gmdate( 'Y-m-d H:i:s', strtotime( "-{$older_than_days} days" ) );

            return (int) $wpdb->query(
                $wpdb->prepare(
                    "DELETE FROM {$this->table}
                    WHERE status = 'failed'
                    AND updated_at < %s",
                    $cutoff
                )
            );
        }

        return (int) $wpdb->query(
            "DELETE FROM {$this->table} WHERE status = 'failed'"
        );
    }

    /**
     * {@inheritdoc}
     */
    public function retryFailed( ?string $queue = null, ?int $limit = null ): int {
        global $wpdb;

        $where = "status = 'failed'";
        $params = [];

        if ( $queue ) {
            $where .= " AND queue = %s";
            $params[] = $queue;
        }

        $limit_sql = $limit ? "LIMIT " . intval( $limit ) : "";

        // Hae epäonnistuneet jobit
        if ( empty( $params ) ) {
            $failed_jobs = $wpdb->get_results(
                "SELECT id FROM {$this->table} WHERE {$where} {$limit_sql}"
            );
        } else {
            $failed_jobs = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT id FROM {$this->table} WHERE {$where} {$limit_sql}",
                    $params
                )
            );
        }

        $retried = 0;

        foreach ( $failed_jobs as $job ) {
            $wpdb->update(
                $this->table,
                [
                    'status'        => 'queued',
                    'attempts'      => 0,
                    'available_at'  => current_time( 'mysql', true ),
                    'error_message' => null,
                    'updated_at'    => current_time( 'mysql', true ),
                ],
                [ 'id' => $job->id ]
            );
            $retried++;
        }

        return $retried;
    }

    /**
     * {@inheritdoc}
     */
    public function getStats( ?string $queue = null ): array {
        global $wpdb;

        if ( $queue ) {
            $stats = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT status, COUNT(*) as count
                    FROM {$this->table}
                    WHERE queue = %s
                    GROUP BY status",
                    $queue
                ),
                ARRAY_A
            );
        } else {
            $stats = $wpdb->get_results(
                "SELECT status, COUNT(*) as count
                FROM {$this->table}
                GROUP BY status",
                ARRAY_A
            );
        }

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

        return $result;
    }

    /**
     * {@inheritdoc}
     */
    public function getJobs( array $filters = [] ): array {
        global $wpdb;

        $where = '1=1';
        $params = [];

        if ( ! empty( $filters['status'] ) ) {
            $where .= ' AND status = %s';
            $params[] = $filters['status'];
        }

        if ( ! empty( $filters['queue'] ) ) {
            $where .= ' AND queue = %s';
            $params[] = $filters['queue'];
        }

        $limit = isset( $filters['limit'] ) ? intval( $filters['limit'] ) : 100;
        $offset = isset( $filters['offset'] ) ? intval( $filters['offset'] ) : 0;

        $sql = "SELECT * FROM {$this->table} WHERE {$where} ORDER BY created_at DESC LIMIT {$limit} OFFSET {$offset}";

        if ( ! empty( $params ) ) {
            return $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A );
        }

        return $wpdb->get_results( $sql, ARRAY_A );
    }

    /**
     * {@inheritdoc}
     */
    public function getQueues(): array {
        global $wpdb;

        $queues = $wpdb->get_results(
            "SELECT queue, status, COUNT(*) as count
            FROM {$this->table}
            GROUP BY queue, status
            ORDER BY queue",
            ARRAY_A
        );

        $result = [];

        foreach ( $queues as $row ) {
            $queue_name = $row['queue'];
            if ( ! isset( $result[ $queue_name ] ) ) {
                $result[ $queue_name ] = [
                    'queued'    => 0,
                    'running'   => 0,
                    'completed' => 0,
                    'failed'    => 0,
                ];
            }
            $result[ $queue_name ][ $row['status'] ] = (int) $row['count'];
        }

        return $result;
    }

    /**
     * {@inheritdoc}
     */
    public function isConnected(): bool {
        global $wpdb;

        return $wpdb->check_connection();
    }
}
