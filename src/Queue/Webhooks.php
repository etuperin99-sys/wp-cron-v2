<?php
/**
 * Webhooks - Lähetä HTTP-kutsuja job-tapahtumista
 *
 * @package WPCronV2\Queue
 */

namespace WPCronV2\Queue;

class Webhooks {

    /**
     * Singleton instanssi
     *
     * @var Webhooks|null
     */
    private static ?Webhooks $instance = null;

    /**
     * Rekisteröidyt webhookit
     *
     * @var array
     */
    private array $webhooks = [];

    /**
     * Hae singleton instanssi
     *
     * @return Webhooks
     */
    public static function get_instance(): Webhooks {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Konstruktori
     */
    private function __construct() {
        $this->load_webhooks();
        $this->init_hooks();
    }

    /**
     * Lataa webhookit asetuksista
     */
    private function load_webhooks(): void {
        $this->webhooks = get_option( 'wp_cron_v2_webhooks', [] );
    }

    /**
     * Alusta hookit
     */
    private function init_hooks(): void {
        // Job completed
        add_action( 'wp_cron_v2_job_completed', [ $this, 'on_job_completed' ], 10, 2 );

        // Job failed
        add_action( 'wp_cron_v2_job_failed', [ $this, 'on_job_failed' ], 10, 2 );

        // Batch completed
        add_action( 'wp_cron_v2_batch_dispatched', [ $this, 'on_batch_dispatched' ], 10, 2 );

        // Chain completed
        add_action( 'wp_cron_v2_chain_completed', [ $this, 'on_chain_completed' ], 10, 1 );

        // Chain failed
        add_action( 'wp_cron_v2_chain_failed', [ $this, 'on_chain_failed' ], 10, 2 );

        // Health issues
        add_action( 'wp_cron_v2_health_issue', [ $this, 'on_health_issue' ], 10, 2 );
    }

    /**
     * Rekisteröi uusi webhook
     *
     * @param string $name Webhookin nimi
     * @param string $url Kohde-URL
     * @param array $events Tapahtumat joita kuunnellaan
     * @param array $options Lisäasetukset (headers, secret, etc.)
     * @return bool
     */
    public function register( string $name, string $url, array $events = [], array $options = [] ): bool {
        $this->webhooks[ $name ] = [
            'url'        => $url,
            'events'     => $events ?: [ 'job.completed', 'job.failed' ],
            'secret'     => $options['secret'] ?? '',
            'headers'    => $options['headers'] ?? [],
            'enabled'    => $options['enabled'] ?? true,
            'created_at' => current_time( 'mysql', true ),
        ];

        return $this->save_webhooks();
    }

    /**
     * Poista webhook
     *
     * @param string $name Webhookin nimi
     * @return bool
     */
    public function unregister( string $name ): bool {
        if ( ! isset( $this->webhooks[ $name ] ) ) {
            return false;
        }

        unset( $this->webhooks[ $name ] );
        return $this->save_webhooks();
    }

    /**
     * Ota webhook käyttöön/pois käytöstä
     *
     * @param string $name Webhookin nimi
     * @param bool $enabled
     * @return bool
     */
    public function setEnabled( string $name, bool $enabled ): bool {
        if ( ! isset( $this->webhooks[ $name ] ) ) {
            return false;
        }

        $this->webhooks[ $name ]['enabled'] = $enabled;
        return $this->save_webhooks();
    }

    /**
     * Hae kaikki webhookit
     *
     * @return array
     */
    public function getAll(): array {
        return $this->webhooks;
    }

    /**
     * Hae yksittäinen webhook
     *
     * @param string $name
     * @return array|null
     */
    public function get( string $name ): ?array {
        return $this->webhooks[ $name ] ?? null;
    }

    /**
     * Tallenna webhookit
     *
     * @return bool
     */
    private function save_webhooks(): bool {
        return update_option( 'wp_cron_v2_webhooks', $this->webhooks );
    }

    /**
     * Lähetä webhook
     *
     * @param string $event Tapahtuman nimi
     * @param array $payload Data
     */
    public function dispatch( string $event, array $payload ): void {
        foreach ( $this->webhooks as $name => $webhook ) {
            if ( ! $webhook['enabled'] ) {
                continue;
            }

            if ( ! in_array( $event, $webhook['events'], true ) && ! in_array( '*', $webhook['events'], true ) ) {
                continue;
            }

            $this->send( $webhook, $event, $payload );
        }
    }

    /**
     * Lähetä HTTP-kutsu webhookille
     *
     * @param array $webhook Webhook-asetukset
     * @param string $event Tapahtuma
     * @param array $payload Data
     */
    private function send( array $webhook, string $event, array $payload ): void {
        $body = wp_json_encode( [
            'event'     => $event,
            'timestamp' => current_time( 'mysql', true ),
            'payload'   => $payload,
        ] );

        $headers = array_merge(
            [
                'Content-Type' => 'application/json',
                'User-Agent'   => 'WP-Cron-V2/1.0',
            ],
            $webhook['headers'] ?? []
        );

        // Lisää signature jos secret on asetettu
        if ( ! empty( $webhook['secret'] ) ) {
            $signature = hash_hmac( 'sha256', $body, $webhook['secret'] );
            $headers['X-WPCronV2-Signature'] = $signature;
        }

        // Lähetä asynkronisesti (non-blocking)
        wp_remote_post( $webhook['url'], [
            'body'      => $body,
            'headers'   => $headers,
            'timeout'   => 5,
            'blocking'  => false,
            'sslverify' => true,
        ] );

        do_action( 'wp_cron_v2_webhook_sent', $webhook['url'], $event, $payload );
    }

    /**
     * Job valmistui
     *
     * @param int $job_id
     * @param object $job
     */
    public function on_job_completed( int $job_id, $job ): void {
        $this->dispatch( 'job.completed', [
            'job_id'   => $job_id,
            'job_type' => get_class( $job ),
            'queue'    => $job->queue ?? 'default',
        ] );
    }

    /**
     * Job epäonnistui
     *
     * @param int $job_id
     * @param \Throwable $exception
     */
    public function on_job_failed( int $job_id, \Throwable $exception ): void {
        $this->dispatch( 'job.failed', [
            'job_id'  => $job_id,
            'error'   => $exception->getMessage(),
            'file'    => $exception->getFile(),
            'line'    => $exception->getLine(),
        ] );
    }

    /**
     * Batch lähetetty
     *
     * @param string $batch_id
     * @param int $job_count
     */
    public function on_batch_dispatched( string $batch_id, int $job_count ): void {
        $this->dispatch( 'batch.dispatched', [
            'batch_id'  => $batch_id,
            'job_count' => $job_count,
        ] );
    }

    /**
     * Chain valmistui
     *
     * @param string $chain_id
     */
    public function on_chain_completed( string $chain_id ): void {
        $chain = Chain::find( $chain_id );

        $this->dispatch( 'chain.completed', [
            'chain_id'   => $chain_id,
            'name'       => $chain['name'] ?? '',
            'total_jobs' => $chain['total_jobs'] ?? 0,
        ] );
    }

    /**
     * Chain epäonnistui
     *
     * @param string $chain_id
     * @param \Throwable $exception
     */
    public function on_chain_failed( string $chain_id, \Throwable $exception ): void {
        $chain = Chain::find( $chain_id );

        $this->dispatch( 'chain.failed', [
            'chain_id'      => $chain_id,
            'name'          => $chain['name'] ?? '',
            'failed_at_job' => ( $chain['current_index'] ?? 0 ) + 1,
            'error'         => $exception->getMessage(),
        ] );
    }

    /**
     * Health-ongelma havaittu
     *
     * @param string $issue_type
     * @param array $data
     */
    public function on_health_issue( string $issue_type, array $data ): void {
        $this->dispatch( 'health.issue', [
            'issue_type' => $issue_type,
            'data'       => $data,
        ] );
    }

    /**
     * Testaa webhookia
     *
     * @param string $name Webhookin nimi
     * @return array Response tai error
     */
    public function test( string $name ): array {
        $webhook = $this->get( $name );

        if ( ! $webhook ) {
            return [ 'success' => false, 'error' => 'Webhook not found' ];
        }

        $body = wp_json_encode( [
            'event'     => 'test',
            'timestamp' => current_time( 'mysql', true ),
            'payload'   => [
                'message' => 'This is a test webhook from WP Cron v2',
            ],
        ] );

        $headers = array_merge(
            [
                'Content-Type' => 'application/json',
                'User-Agent'   => 'WP-Cron-V2/1.0',
            ],
            $webhook['headers'] ?? []
        );

        if ( ! empty( $webhook['secret'] ) ) {
            $signature = hash_hmac( 'sha256', $body, $webhook['secret'] );
            $headers['X-WPCronV2-Signature'] = $signature;
        }

        $response = wp_remote_post( $webhook['url'], [
            'body'      => $body,
            'headers'   => $headers,
            'timeout'   => 10,
            'blocking'  => true,
            'sslverify' => true,
        ] );

        if ( is_wp_error( $response ) ) {
            return [
                'success' => false,
                'error'   => $response->get_error_message(),
            ];
        }

        return [
            'success'     => true,
            'status_code' => wp_remote_retrieve_response_code( $response ),
            'body'        => wp_remote_retrieve_body( $response ),
        ];
    }
}
