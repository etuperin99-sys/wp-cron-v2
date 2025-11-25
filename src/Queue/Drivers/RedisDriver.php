<?php
/**
 * Redis Driver
 *
 * @package WPCronV2\Queue\Drivers
 */

namespace WPCronV2\Queue\Drivers;

class RedisDriver implements DriverInterface {

    /**
     * Redis-yhteys
     *
     * @var \Redis|null
     */
    private ?\Redis $redis = null;

    /**
     * Asetukset
     *
     * @var array
     */
    private array $config;

    /**
     * Prefix avaimille
     *
     * @var string
     */
    private string $prefix;

    /**
     * Job ID counter key
     *
     * @var string
     */
    private string $id_key;

    /**
     * Konstruktori
     *
     * @param array $config Redis-asetukset
     */
    public function __construct( array $config = [] ) {
        $this->config = wp_parse_args( $config, [
            'host'     => '127.0.0.1',
            'port'     => 6379,
            'password' => '',
            'database' => 0,
            'timeout'  => 2.0,
            'prefix'   => 'wp_cron_v2:',
        ] );

        $this->prefix = $this->config['prefix'];
        $this->id_key = $this->prefix . 'job_id_counter';

        $this->connect();
    }

    /**
     * Yhdistä Redisiin
     *
     * @throws \Exception Jos yhteys epäonnistuu
     */
    private function connect(): void {
        if ( ! class_exists( '\Redis' ) ) {
            throw new \Exception( 'PHP Redis extension is not installed' );
        }

        $this->redis = new \Redis();

        $connected = $this->redis->connect(
            $this->config['host'],
            $this->config['port'],
            $this->config['timeout']
        );

        if ( ! $connected ) {
            throw new \Exception( 'Could not connect to Redis' );
        }

        if ( ! empty( $this->config['password'] ) ) {
            $this->redis->auth( $this->config['password'] );
        }

        if ( $this->config['database'] > 0 ) {
            $this->redis->select( $this->config['database'] );
        }

        // Käytä serialisaatiota
        $this->redis->setOption( \Redis::OPT_SERIALIZER, \Redis::SERIALIZER_PHP );
    }

    /**
     * Generoi uusi job ID
     *
     * @return int
     */
    private function generateId(): int {
        return (int) $this->redis->incr( $this->id_key );
    }

    /**
     * Hae queue key
     *
     * @param string $queue Jonon nimi
     * @param string $priority Prioriteetti
     * @return string
     */
    private function getQueueKey( string $queue, string $priority = 'normal' ): string {
        return $this->prefix . "queue:{$queue}:{$priority}";
    }

    /**
     * Hae job key
     *
     * @param int $job_id Job ID
     * @return string
     */
    private function getJobKey( int $job_id ): string {
        return $this->prefix . "job:{$job_id}";
    }

    /**
     * Hae delayed queue key
     *
     * @param string $queue Jonon nimi
     * @return string
     */
    private function getDelayedKey( string $queue ): string {
        return $this->prefix . "delayed:{$queue}";
    }

    /**
     * Hae running jobs key
     *
     * @return string
     */
    private function getRunningKey(): string {
        return $this->prefix . 'running';
    }

    /**
     * {@inheritdoc}
     */
    public function push( array $job_data ) {
        $job_id = $this->generateId();
        $now = time();

        $job = [
            'id'           => $job_id,
            'job_type'     => $job_data['job_type'],
            'payload'      => $job_data['payload'],
            'queue'        => $job_data['queue'] ?? 'default',
            'priority'     => $job_data['priority'] ?? 'normal',
            'attempts'     => 0,
            'max_attempts' => $job_data['max_attempts'] ?? 3,
            'status'       => 'queued',
            'available_at' => $job_data['available_at'] ?? $now,
            'created_at'   => $now,
            'updated_at'   => $now,
            'batch_id'     => $job_data['batch_id'] ?? null,
            'error_message' => null,
        ];

        // Tallenna job-data
        $this->redis->set( $this->getJobKey( $job_id ), $job );

        // Jos viivästetty, lisää sorted setiin
        if ( isset( $job_data['available_at'] ) && is_int( $job_data['available_at'] ) && $job_data['available_at'] > $now ) {
            $this->redis->zAdd(
                $this->getDelayedKey( $job['queue'] ),
                $job_data['available_at'],
                $job_id
            );
        } else {
            // Lisää heti jonoon prioriteetin mukaan
            $this->redis->lPush(
                $this->getQueueKey( $job['queue'], $job['priority'] ),
                $job_id
            );
        }

        // Päivitä tilastot
        $this->redis->hIncrBy( $this->prefix . 'stats', 'total_pushed', 1 );
        $this->redis->sAdd( $this->prefix . 'queues', $job['queue'] );

        return $job_id;
    }

    /**
     * Siirrä viivästetyt jobit jonoon
     *
     * @param string $queue Jonon nimi
     */
    private function migrateDelayed( string $queue ): void {
        $now = time();
        $delayed_key = $this->getDelayedKey( $queue );

        // Hae kaikki jobit joiden aika on tullut
        $job_ids = $this->redis->zRangeByScore( $delayed_key, '-inf', $now );

        foreach ( $job_ids as $job_id ) {
            $job = $this->redis->get( $this->getJobKey( $job_id ) );

            if ( $job && $job['status'] === 'queued' ) {
                // Siirrä varsinaiseen jonoon
                $this->redis->lPush(
                    $this->getQueueKey( $job['queue'], $job['priority'] ),
                    $job_id
                );
            }

            // Poista delayed-listasta
            $this->redis->zRem( $delayed_key, $job_id );
        }
    }

    /**
     * {@inheritdoc}
     */
    public function pop( string $queue ): ?object {
        // Siirrä ensin viivästetyt jobit
        $this->migrateDelayed( $queue );

        // Käy läpi prioriteettijärjestyksessä
        $priorities = [ 'high', 'normal', 'low' ];

        foreach ( $priorities as $priority ) {
            $queue_key = $this->getQueueKey( $queue, $priority );

            // Hae ja poista jonosta (blocking pop timeout 0 = instant)
            $job_id = $this->redis->rPop( $queue_key );

            if ( $job_id ) {
                $job_key = $this->getJobKey( $job_id );
                $job = $this->redis->get( $job_key );

                if ( ! $job || $job['status'] !== 'queued' ) {
                    continue;
                }

                // Päivitä status
                $job['status'] = 'running';
                $job['updated_at'] = time();

                $this->redis->set( $job_key, $job );

                // Lisää running-settiin timeoutin seurantaa varten
                $this->redis->zAdd( $this->getRunningKey(), time(), $job_id );

                return (object) $job;
            }
        }

        return null;
    }

    /**
     * {@inheritdoc}
     */
    public function complete( int $job_id ): bool {
        $job_key = $this->getJobKey( $job_id );
        $job = $this->redis->get( $job_key );

        if ( ! $job ) {
            return false;
        }

        $job['status'] = 'completed';
        $job['updated_at'] = time();

        $this->redis->set( $job_key, $job );

        // Poista running-setistä
        $this->redis->zRem( $this->getRunningKey(), $job_id );

        // Päivitä tilastot
        $this->redis->hIncrBy( $this->prefix . 'stats', 'completed', 1 );

        // Aseta TTL valmiille jobeille (7 päivää)
        $this->redis->expire( $job_key, 7 * 24 * 3600 );

        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function fail( int $job_id, string $error, int $attempts, bool $is_final = false ): bool {
        $job_key = $this->getJobKey( $job_id );
        $job = $this->redis->get( $job_key );

        if ( ! $job ) {
            return false;
        }

        $job['status'] = 'failed';
        $job['attempts'] = $attempts;
        $job['error_message'] = $error;
        $job['updated_at'] = time();

        $this->redis->set( $job_key, $job );

        // Poista running-setistä
        $this->redis->zRem( $this->getRunningKey(), $job_id );

        // Lisää failed-settiin
        $this->redis->sAdd( $this->prefix . 'failed', $job_id );

        // Päivitä tilastot
        $this->redis->hIncrBy( $this->prefix . 'stats', 'failed', 1 );

        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function release( int $job_id, int $delay, int $attempts, string $error = '' ): bool {
        $job_key = $this->getJobKey( $job_id );
        $job = $this->redis->get( $job_key );

        if ( ! $job ) {
            return false;
        }

        $job['status'] = 'queued';
        $job['attempts'] = $attempts;
        $job['available_at'] = time() + $delay;
        $job['updated_at'] = time();

        if ( $error ) {
            $job['error_message'] = $error;
        }

        $this->redis->set( $job_key, $job );

        // Poista running-setistä
        $this->redis->zRem( $this->getRunningKey(), $job_id );

        // Lisää delayed-queueen
        $this->redis->zAdd(
            $this->getDelayedKey( $job['queue'] ),
            $job['available_at'],
            $job_id
        );

        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function find( int $job_id ): ?object {
        $job = $this->redis->get( $this->getJobKey( $job_id ) );

        return $job ? (object) $job : null;
    }

    /**
     * {@inheritdoc}
     */
    public function cancel( int $job_id ): bool {
        $job_key = $this->getJobKey( $job_id );
        $job = $this->redis->get( $job_key );

        if ( ! $job || $job['status'] !== 'queued' ) {
            return false;
        }

        // Poista kaikista jonoista
        $priorities = [ 'high', 'normal', 'low' ];
        foreach ( $priorities as $priority ) {
            $this->redis->lRem( $this->getQueueKey( $job['queue'], $priority ), $job_id, 0 );
        }

        // Poista delayed-queuesta
        $this->redis->zRem( $this->getDelayedKey( $job['queue'] ), $job_id );

        // Poista job-data
        $this->redis->del( $job_key );

        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function releaseStale( int $timeout_minutes = 30 ): int {
        $cutoff = time() - ( $timeout_minutes * 60 );

        // Hae jobit jotka ovat olleet running-tilassa liian kauan
        $stale_ids = $this->redis->zRangeByScore( $this->getRunningKey(), '-inf', $cutoff );

        $released = 0;

        foreach ( $stale_ids as $job_id ) {
            $job = $this->redis->get( $this->getJobKey( $job_id ) );

            if ( ! $job ) {
                $this->redis->zRem( $this->getRunningKey(), $job_id );
                continue;
            }

            $attempts = $job['attempts'] + 1;
            $max_attempts = $job['max_attempts'];

            if ( $attempts >= $max_attempts ) {
                $this->fail(
                    $job_id,
                    'Job timeout - exceeded ' . $timeout_minutes . ' minutes',
                    $attempts,
                    true
                );
                do_action( 'wp_cron_v2_job_timeout', $job_id, 'failed' );
            } else {
                $backoff = pow( 2, $attempts ) * 60;
                $this->release( $job_id, $backoff, $attempts, 'Job timeout - will retry' );
                do_action( 'wp_cron_v2_job_timeout', $job_id, 'retrying' );
            }

            $released++;
        }

        return $released;
    }

    /**
     * {@inheritdoc}
     */
    public function cleanup( int $days = 7, bool $include_failed = false ): int {
        // Redis hoitaa TTL:n kautta completed jobien poiston
        // Tässä voimme pakottaa siivouksen

        $deleted = 0;
        $cutoff = time() - ( $days * 24 * 3600 );

        // Scan läpi kaikki job-avaimet
        $iterator = null;
        while ( $keys = $this->redis->scan( $iterator, $this->prefix . 'job:*', 100 ) ) {
            foreach ( $keys as $key ) {
                $job = $this->redis->get( $key );

                if ( ! $job ) {
                    continue;
                }

                $should_delete = false;

                if ( $job['status'] === 'completed' && $job['updated_at'] < $cutoff ) {
                    $should_delete = true;
                }

                if ( $include_failed && $job['status'] === 'failed' && $job['updated_at'] < $cutoff ) {
                    $should_delete = true;
                    $this->redis->sRem( $this->prefix . 'failed', $job['id'] );
                }

                if ( $should_delete ) {
                    $this->redis->del( $key );
                    $deleted++;
                }
            }
        }

        do_action( 'wp_cron_v2_jobs_cleaned', $deleted );

        return $deleted;
    }

    /**
     * {@inheritdoc}
     */
    public function flushFailed( ?int $older_than_days = null ): int {
        $deleted = 0;
        $cutoff = $older_than_days ? time() - ( $older_than_days * 24 * 3600 ) : null;

        $failed_ids = $this->redis->sMembers( $this->prefix . 'failed' );

        foreach ( $failed_ids as $job_id ) {
            $job = $this->redis->get( $this->getJobKey( $job_id ) );

            if ( ! $job ) {
                $this->redis->sRem( $this->prefix . 'failed', $job_id );
                continue;
            }

            if ( $cutoff === null || $job['updated_at'] < $cutoff ) {
                $this->redis->del( $this->getJobKey( $job_id ) );
                $this->redis->sRem( $this->prefix . 'failed', $job_id );
                $deleted++;
            }
        }

        return $deleted;
    }

    /**
     * {@inheritdoc}
     */
    public function retryFailed( ?string $queue = null, ?int $limit = null ): int {
        $failed_ids = $this->redis->sMembers( $this->prefix . 'failed' );
        $retried = 0;

        foreach ( $failed_ids as $job_id ) {
            if ( $limit && $retried >= $limit ) {
                break;
            }

            $job = $this->redis->get( $this->getJobKey( $job_id ) );

            if ( ! $job || $job['status'] !== 'failed' ) {
                $this->redis->sRem( $this->prefix . 'failed', $job_id );
                continue;
            }

            if ( $queue && $job['queue'] !== $queue ) {
                continue;
            }

            // Nollaa job ja lisää takaisin jonoon
            $job['status'] = 'queued';
            $job['attempts'] = 0;
            $job['error_message'] = null;
            $job['available_at'] = time();
            $job['updated_at'] = time();

            $this->redis->set( $this->getJobKey( $job_id ), $job );

            // Lisää jonoon
            $this->redis->lPush(
                $this->getQueueKey( $job['queue'], $job['priority'] ),
                $job_id
            );

            // Poista failed-setistä
            $this->redis->sRem( $this->prefix . 'failed', $job_id );

            $retried++;
        }

        return $retried;
    }

    /**
     * {@inheritdoc}
     */
    public function getStats( ?string $queue = null ): array {
        $result = [
            'queued'    => 0,
            'running'   => 0,
            'completed' => 0,
            'failed'    => 0,
        ];

        if ( $queue ) {
            // Laske tietyn jonon tilastot
            $priorities = [ 'high', 'normal', 'low' ];
            foreach ( $priorities as $priority ) {
                $result['queued'] += $this->redis->lLen( $this->getQueueKey( $queue, $priority ) );
            }
            $result['queued'] += $this->redis->zCard( $this->getDelayedKey( $queue ) );
        } else {
            // Laske kaikki jonot
            $queues = $this->redis->sMembers( $this->prefix . 'queues' );
            foreach ( $queues as $q ) {
                $priorities = [ 'high', 'normal', 'low' ];
                foreach ( $priorities as $priority ) {
                    $result['queued'] += $this->redis->lLen( $this->getQueueKey( $q, $priority ) );
                }
                $result['queued'] += $this->redis->zCard( $this->getDelayedKey( $q ) );
            }
        }

        $result['running'] = $this->redis->zCard( $this->getRunningKey() );
        $result['failed'] = $this->redis->sCard( $this->prefix . 'failed' );

        // Completed täytyy laskea scannaamalla (tai käyttää tilastoja)
        $stats = $this->redis->hGetAll( $this->prefix . 'stats' );
        $result['completed'] = (int) ( $stats['completed'] ?? 0 );

        return $result;
    }

    /**
     * {@inheritdoc}
     */
    public function getJobs( array $filters = [] ): array {
        $jobs = [];
        $limit = $filters['limit'] ?? 100;
        $offset = $filters['offset'] ?? 0;
        $count = 0;
        $skipped = 0;

        // Scan läpi job-avaimet
        $iterator = null;
        while ( $keys = $this->redis->scan( $iterator, $this->prefix . 'job:*', 100 ) ) {
            foreach ( $keys as $key ) {
                if ( count( $jobs ) >= $limit ) {
                    break 2;
                }

                $job = $this->redis->get( $key );

                if ( ! $job ) {
                    continue;
                }

                // Suodata
                if ( ! empty( $filters['status'] ) && $job['status'] !== $filters['status'] ) {
                    continue;
                }

                if ( ! empty( $filters['queue'] ) && $job['queue'] !== $filters['queue'] ) {
                    continue;
                }

                // Offset
                if ( $skipped < $offset ) {
                    $skipped++;
                    continue;
                }

                $jobs[] = $job;
            }
        }

        // Järjestä created_at mukaan
        usort( $jobs, function( $a, $b ) {
            return $b['created_at'] - $a['created_at'];
        } );

        return $jobs;
    }

    /**
     * {@inheritdoc}
     */
    public function getQueues(): array {
        $queue_names = $this->redis->sMembers( $this->prefix . 'queues' );
        $result = [];

        foreach ( $queue_names as $queue ) {
            $result[ $queue ] = [
                'queued'    => 0,
                'running'   => 0,
                'completed' => 0,
                'failed'    => 0,
            ];

            $priorities = [ 'high', 'normal', 'low' ];
            foreach ( $priorities as $priority ) {
                $result[ $queue ]['queued'] += $this->redis->lLen( $this->getQueueKey( $queue, $priority ) );
            }
            $result[ $queue ]['queued'] += $this->redis->zCard( $this->getDelayedKey( $queue ) );
        }

        return $result;
    }

    /**
     * {@inheritdoc}
     */
    public function isConnected(): bool {
        try {
            return $this->redis && $this->redis->ping();
        } catch ( \Exception $e ) {
            return false;
        }
    }

    /**
     * Hae Redis-instanssi
     *
     * @return \Redis|null
     */
    public function getRedis(): ?\Redis {
        return $this->redis;
    }

    /**
     * Tyhjennä kaikki data
     *
     * @return bool
     */
    public function flush(): bool {
        $iterator = null;
        while ( $keys = $this->redis->scan( $iterator, $this->prefix . '*', 100 ) ) {
            if ( ! empty( $keys ) ) {
                $this->redis->del( $keys );
            }
        }

        return true;
    }
}
