<?php
/**
 * Queue Driver Interface
 *
 * @package WPCronV2\Queue\Drivers
 */

namespace WPCronV2\Queue\Drivers;

interface DriverInterface {

    /**
     * Lisää job jonoon
     *
     * @param array $job_data Job-tiedot
     * @return int|false Job ID tai false virheessä
     */
    public function push( array $job_data );

    /**
     * Hae ja lukitse seuraava job
     *
     * @param string $queue Jonon nimi
     * @return object|null Job-rivi tai null
     */
    public function pop( string $queue ): ?object;

    /**
     * Merkitse job valmiiksi
     *
     * @param int $job_id Job ID
     * @return bool
     */
    public function complete( int $job_id ): bool;

    /**
     * Merkitse job epäonnistuneeksi
     *
     * @param int $job_id Job ID
     * @param string $error Virheilmoitus
     * @param int $attempts Yrityskerrat
     * @param bool $is_final Onko lopullinen epäonnistuminen
     * @return bool
     */
    public function fail( int $job_id, string $error, int $attempts, bool $is_final = false ): bool;

    /**
     * Palauta job jonoon (retry)
     *
     * @param int $job_id Job ID
     * @param int $delay Viive sekunteina
     * @param int $attempts Yrityskerrat
     * @param string $error Virheilmoitus
     * @return bool
     */
    public function release( int $job_id, int $delay, int $attempts, string $error = '' ): bool;

    /**
     * Hae job ID:llä
     *
     * @param int $job_id Job ID
     * @return object|null
     */
    public function find( int $job_id ): ?object;

    /**
     * Peruuta job
     *
     * @param int $job_id Job ID
     * @return bool
     */
    public function cancel( int $job_id ): bool;

    /**
     * Vapauta stale jobit
     *
     * @param int $timeout_minutes Timeout minuuteissa
     * @return int Vapautettujen määrä
     */
    public function releaseStale( int $timeout_minutes = 30 ): int;

    /**
     * Siivoa vanhat jobit
     *
     * @param int $days Päivien määrä
     * @param bool $include_failed Sisällytä epäonnistuneet
     * @return int Poistettujen määrä
     */
    public function cleanup( int $days = 7, bool $include_failed = false ): int;

    /**
     * Poista epäonnistuneet jobit
     *
     * @param int|null $older_than_days Poista vanhemmat kuin X päivää (null = kaikki)
     * @return int Poistettujen määrä
     */
    public function flushFailed( ?int $older_than_days = null ): int;

    /**
     * Yritä epäonnistuneet uudelleen
     *
     * @param string|null $queue Jonon nimi (null = kaikki)
     * @param int|null $limit Maksimimäärä
     * @return int Uudelleenyritettyjen määrä
     */
    public function retryFailed( ?string $queue = null, ?int $limit = null ): int;

    /**
     * Hae tilastot
     *
     * @param string|null $queue Jonon nimi (null = kaikki)
     * @return array
     */
    public function getStats( ?string $queue = null ): array;

    /**
     * Hae jobit
     *
     * @param array $filters Suodattimet (status, queue, limit, offset)
     * @return array
     */
    public function getJobs( array $filters = [] ): array;

    /**
     * Hae jonot
     *
     * @return array
     */
    public function getQueues(): array;

    /**
     * Tarkista yhteys
     *
     * @return bool
     */
    public function isConnected(): bool;
}
