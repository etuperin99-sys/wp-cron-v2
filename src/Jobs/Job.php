<?php
/**
 * Abstrakti Job-luokka
 *
 * @package WPCronV2\Jobs
 */

namespace WPCronV2\Jobs;

abstract class Job {

    /**
     * Maksimi yritykset
     *
     * @var int
     */
    public int $max_attempts = 3;

    /**
     * Jono johon job kuuluu
     *
     * @var string
     */
    public string $queue = 'default';

    /**
     * Prioriteetti
     *
     * @var string
     */
    public string $priority = 'normal';

    /**
     * Timeout sekunteina
     *
     * @var int
     */
    public int $timeout = 60;

    /**
     * Suorita job
     *
     * @return void
     */
    abstract public function handle(): void;

    /**
     * Kutsutaan kun job epäonnistuu lopullisesti
     *
     * @param \Throwable $exception
     * @return void
     */
    public function failed( \Throwable $exception ): void {
        // Ylikirjoita alaluokassa tarvittaessa
    }

    /**
     * Lähetä job jonoon
     *
     * @return int|false
     */
    public function dispatch() {
        return wp_cron_v2()
            ->queue( $this->queue )
            ->priority( $this->priority )
            ->dispatch( $this );
    }

    /**
     * Lähetä job jonoon viiveellä
     *
     * @param int $delay Viive sekunteina
     * @return int|false
     */
    public function delay( int $delay ) {
        return wp_cron_v2()
            ->queue( $this->queue )
            ->priority( $this->priority )
            ->later( $delay, $this );
    }
}
