<?php
/**
 * WP-Cron Backwards Compatibility Adapter
 *
 * Mahdollistaa vanhojen wp_schedule_event() kutsujen ohjaamisen WP Cron v2:een
 *
 * @package WPCronV2\Adapter
 */

namespace WPCronV2\Adapter;

use WPCronV2\Jobs\Job;

class WPCronAdapter {

    /**
     * Singleton instanssi
     *
     * @var WPCronAdapter|null
     */
    private static ?WPCronAdapter $instance = null;

    /**
     * Onko adapter käytössä
     *
     * @var bool
     */
    private bool $enabled = false;

    /**
     * Rekisteröidyt hookit ja niiden handlerit
     *
     * @var array
     */
    private array $registered_hooks = [];

    /**
     * Hae singleton
     *
     * @return WPCronAdapter
     */
    public static function get_instance(): WPCronAdapter {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Konstruktori
     */
    private function __construct() {
        // Ladataan tallennetut hookit
        $this->registered_hooks = get_option( 'wp_cron_v2_registered_hooks', [] );
    }

    /**
     * Ota adapter käyttöön
     */
    public function enable(): void {
        if ( $this->enabled ) {
            return;
        }

        $this->enabled = true;

        // Korvaa WP-Cron funktiot
        add_filter( 'pre_schedule_event', [ $this, 'intercept_schedule_event' ], 10, 2 );
        add_filter( 'pre_unschedule_event', [ $this, 'intercept_unschedule_event' ], 10, 4 );
        add_filter( 'pre_clear_scheduled_hook', [ $this, 'intercept_clear_scheduled_hook' ], 10, 3 );

        // Suorita rekisteröidyt hookit kun ne triggeröidään
        foreach ( $this->registered_hooks as $hook => $data ) {
            add_action( $hook, [ $this, 'execute_hook' ], 10, 10 );
        }
    }

    /**
     * Poista adapter käytöstä
     */
    public function disable(): void {
        $this->enabled = false;

        remove_filter( 'pre_schedule_event', [ $this, 'intercept_schedule_event' ] );
        remove_filter( 'pre_unschedule_event', [ $this, 'intercept_unschedule_event' ] );
        remove_filter( 'pre_clear_scheduled_hook', [ $this, 'intercept_clear_scheduled_hook' ] );
    }

    /**
     * Intercept wp_schedule_event() ja wp_schedule_single_event()
     *
     * @param null|bool $pre
     * @param object $event
     * @return null|bool
     */
    public function intercept_schedule_event( $pre, $event ) {
        if ( ! $this->enabled || null !== $pre ) {
            return $pre;
        }

        $hook = $event->hook;
        $args = $event->args ?? [];
        $timestamp = $event->timestamp;
        $schedule = $event->schedule ?? false;

        // Laske viive
        $delay = max( 0, $timestamp - time() );

        // Luo job
        $job = new LegacyCronJob( $hook, $args, $schedule );

        // Lähetä jonoon
        if ( $delay > 0 ) {
            wp_cron_v2()->queue( 'wp-cron' )->later( $delay, $job );
        } else {
            wp_cron_v2()->queue( 'wp-cron' )->dispatch( $job );
        }

        // Tallenna hook rekisteriin
        $this->registered_hooks[ $hook ] = [
            'schedule' => $schedule,
            'args' => $args,
        ];
        update_option( 'wp_cron_v2_registered_hooks', $this->registered_hooks );

        // Palauta true estääksemme WP-Cronin oman käsittelyn
        return true;
    }

    /**
     * Intercept wp_unschedule_event()
     *
     * @param null|bool $pre
     * @param int $timestamp
     * @param string $hook
     * @param array $args
     * @return null|bool
     */
    public function intercept_unschedule_event( $pre, $timestamp, $hook, $args ) {
        if ( ! $this->enabled || null !== $pre ) {
            return $pre;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'job_queue';

        // Poista vastaavat jobit jonosta
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$table}
                WHERE job_type = %s
                AND queue = 'wp-cron'
                AND status = 'queued'",
                LegacyCronJob::class
            )
        );

        return true;
    }

    /**
     * Intercept wp_clear_scheduled_hook()
     *
     * @param null|int $pre
     * @param string $hook
     * @param array $args
     * @return null|int
     */
    public function intercept_clear_scheduled_hook( $pre, $hook, $args ) {
        if ( ! $this->enabled || null !== $pre ) {
            return $pre;
        }

        // Poista hook rekisteristä
        if ( isset( $this->registered_hooks[ $hook ] ) ) {
            unset( $this->registered_hooks[ $hook ] );
            update_option( 'wp_cron_v2_registered_hooks', $this->registered_hooks );
        }

        return 0;
    }

    /**
     * Suorita hook kun sitä kutsutaan
     *
     * Tämä metodi ei oikeasti tee mitään, koska jobit suoritetaan workerin kautta.
     * Se on olemassa vain varmuuden vuoksi.
     */
    public function execute_hook(): void {
        // Hookit suoritetaan LegacyCronJob::handle() kautta
    }

    /**
     * Hae rekisteröidyt hookit
     *
     * @return array
     */
    public function get_registered_hooks(): array {
        return $this->registered_hooks;
    }

    /**
     * Onko adapter käytössä
     *
     * @return bool
     */
    public function is_enabled(): bool {
        return $this->enabled;
    }
}

/**
 * Legacy Cron Job - Wrapper vanhoille WP-Cron hookeille
 */
class LegacyCronJob extends Job {

    public int $max_attempts = 3;
    public string $queue = 'wp-cron';
    public string $priority = 'normal';

    private string $hook;
    private array $args;
    private $schedule;

    /**
     * Konstruktori
     *
     * @param string $hook WordPress action hook
     * @param array $args Hookin argumentit
     * @param string|false $schedule Toistuvuus (hourly, daily, jne.) tai false
     */
    public function __construct( string $hook, array $args = [], $schedule = false ) {
        $this->hook = $hook;
        $this->args = $args;
        $this->schedule = $schedule;
    }

    /**
     * Suorita job
     */
    public function handle(): void {
        // Suorita WordPress action hook
        do_action_ref_array( $this->hook, $this->args );

        // Jos toistuva, ajasta seuraava
        if ( $this->schedule ) {
            $schedules = wp_get_schedules();

            if ( isset( $schedules[ $this->schedule ] ) ) {
                $interval = $schedules[ $this->schedule ]['interval'];
                $next_job = new self( $this->hook, $this->args, $this->schedule );
                wp_cron_v2()->queue( 'wp-cron' )->later( $interval, $next_job );
            }
        }
    }

    /**
     * Hae hook-nimi
     *
     * @return string
     */
    public function get_hook(): string {
        return $this->hook;
    }

    /**
     * Hae argumentit
     *
     * @return array
     */
    public function get_args(): array {
        return $this->args;
    }
}
