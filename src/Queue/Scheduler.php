<?php
/**
 * Scheduler - Toistuvien tehtävien hallinta
 *
 * @package WPCronV2\Queue
 */

namespace WPCronV2\Queue;

use WPCronV2\Jobs\Job;

class Scheduler {

    /**
     * Singleton
     *
     * @var Scheduler|null
     */
    private static ?Scheduler $instance = null;

    /**
     * Hae singleton
     *
     * @return Scheduler
     */
    public static function get_instance(): Scheduler {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Konstruktori
     */
    private function __construct() {
        // Tarkista scheduled jobit kun WP latautuu
        add_action( 'init', [ $this, 'check_scheduled_jobs' ] );
    }

    /**
     * Rekisteröi toistuva job
     *
     * @param string $name Uniikki nimi schedulelle
     * @param string $interval Toistuvuus: minutely, hourly, twicedaily, daily, weekly
     * @param Job $job Job-instanssi
     * @param string $queue Jonon nimi
     * @return bool
     */
    public function schedule( string $name, string $interval, Job $job, string $queue = 'default' ): bool {
        $schedules = $this->get_schedules();

        $intervals = $this->get_intervals();
        if ( ! isset( $intervals[ $interval ] ) ) {
            return false;
        }

        $schedules[ $name ] = [
            'interval' => $interval,
            'interval_seconds' => $intervals[ $interval ],
            'job_class' => get_class( $job ),
            'job_payload' => maybe_serialize( $job ),
            'queue' => $queue,
            'next_run' => time() + $intervals[ $interval ],
            'last_run' => null,
            'enabled' => true,
        ];

        update_option( 'wp_cron_v2_schedules', $schedules );

        return true;
    }

    /**
     * Poista schedule
     *
     * @param string $name
     * @return bool
     */
    public function unschedule( string $name ): bool {
        $schedules = $this->get_schedules();

        if ( ! isset( $schedules[ $name ] ) ) {
            return false;
        }

        unset( $schedules[ $name ] );
        update_option( 'wp_cron_v2_schedules', $schedules );

        return true;
    }

    /**
     * Pysäytä schedule väliaikaisesti
     *
     * @param string $name
     * @return bool
     */
    public function pause( string $name ): bool {
        $schedules = $this->get_schedules();

        if ( ! isset( $schedules[ $name ] ) ) {
            return false;
        }

        $schedules[ $name ]['enabled'] = false;
        update_option( 'wp_cron_v2_schedules', $schedules );

        return true;
    }

    /**
     * Jatka pysäytettyä schedulea
     *
     * @param string $name
     * @return bool
     */
    public function resume( string $name ): bool {
        $schedules = $this->get_schedules();

        if ( ! isset( $schedules[ $name ] ) ) {
            return false;
        }

        $schedules[ $name ]['enabled'] = true;
        $schedules[ $name ]['next_run'] = time();
        update_option( 'wp_cron_v2_schedules', $schedules );

        return true;
    }

    /**
     * Tarkista ja lisää scheduled jobit jonoon
     */
    public function check_scheduled_jobs(): void {
        $schedules = $this->get_schedules();
        $now = time();
        $updated = false;

        foreach ( $schedules as $name => &$schedule ) {
            if ( ! $schedule['enabled'] ) {
                continue;
            }

            if ( $schedule['next_run'] <= $now ) {
                // Lisää job jonoon
                $job = maybe_unserialize( $schedule['job_payload'] );

                if ( is_object( $job ) ) {
                    wp_cron_v2()->queue( $schedule['queue'] )->dispatch( $job );

                    // Päivitä ajat
                    $schedule['last_run'] = $now;
                    $schedule['next_run'] = $now + $schedule['interval_seconds'];
                    $updated = true;
                }
            }
        }

        if ( $updated ) {
            update_option( 'wp_cron_v2_schedules', $schedules );
        }
    }

    /**
     * Hae kaikki schedulet
     *
     * @return array
     */
    public function get_schedules(): array {
        return get_option( 'wp_cron_v2_schedules', [] );
    }

    /**
     * Hae tuetut intervallit
     *
     * @return array
     */
    public function get_intervals(): array {
        $intervals = [
            'minutely' => 60,
            'every_5_minutes' => 300,
            'every_15_minutes' => 900,
            'every_30_minutes' => 1800,
            'hourly' => 3600,
            'twicedaily' => 43200,
            'daily' => 86400,
            'weekly' => 604800,
        ];

        return apply_filters( 'wp_cron_v2_intervals', $intervals );
    }

    /**
     * Hae schedulen tiedot
     *
     * @param string $name
     * @return array|null
     */
    public function get_schedule( string $name ): ?array {
        $schedules = $this->get_schedules();
        return $schedules[ $name ] ?? null;
    }

    /**
     * Onko schedule olemassa
     *
     * @param string $name
     * @return bool
     */
    public function exists( string $name ): bool {
        $schedules = $this->get_schedules();
        return isset( $schedules[ $name ] );
    }
}
