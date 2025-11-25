<?php
/**
 * Rate Limiter - Rajoita jobien suoritustahtia
 *
 * @package WPCronV2\Queue
 */

namespace WPCronV2\Queue;

class RateLimiter {

    /**
     * Singleton instanssi
     *
     * @var RateLimiter|null
     */
    private static ?RateLimiter $instance = null;

    /**
     * Transient prefix
     *
     * @var string
     */
    private string $prefix = 'wp_cron_v2_rate_';

    /**
     * Hae singleton instanssi
     *
     * @return RateLimiter
     */
    public static function get_instance(): RateLimiter {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Tarkista voiko jobin suorittaa (sliding window)
     *
     * @param string $key Rajoituksen avain (esim. job-tyyppi tai jono)
     * @param int $max_attempts Maksimi suoritukset aikaikkunassa
     * @param int $decay_seconds Aikaikkunan pituus sekunteina
     * @return bool True jos voi suorittaa, false jos rajoitettu
     */
    public function attempt( string $key, int $max_attempts, int $decay_seconds ): bool {
        $cache_key = $this->prefix . md5( $key );

        // Hae nykyinen tila
        $data = get_transient( $cache_key );

        if ( false === $data ) {
            // Ei aiempia yrityksiä, aloita uusi ikkuna
            $data = [
                'count'      => 1,
                'started_at' => time(),
            ];
            set_transient( $cache_key, $data, $decay_seconds );
            return true;
        }

        // Tarkista onko ikkuna vanhentunut
        if ( ( time() - $data['started_at'] ) >= $decay_seconds ) {
            // Aloita uusi ikkuna
            $data = [
                'count'      => 1,
                'started_at' => time(),
            ];
            set_transient( $cache_key, $data, $decay_seconds );
            return true;
        }

        // Tarkista onko tilaa
        if ( $data['count'] < $max_attempts ) {
            $data['count']++;
            $remaining_time = $decay_seconds - ( time() - $data['started_at'] );
            set_transient( $cache_key, $data, $remaining_time );
            return true;
        }

        // Rate limit ylitetty
        return false;
    }

    /**
     * Tarkista voiko suorittaa (ei lisää laskuria)
     *
     * @param string $key Rajoituksen avain
     * @param int $max_attempts Maksimi suoritukset
     * @param int $decay_seconds Aikaikkunan pituus
     * @return bool
     */
    public function check( string $key, int $max_attempts, int $decay_seconds ): bool {
        $cache_key = $this->prefix . md5( $key );
        $data = get_transient( $cache_key );

        if ( false === $data ) {
            return true;
        }

        if ( ( time() - $data['started_at'] ) >= $decay_seconds ) {
            return true;
        }

        return $data['count'] < $max_attempts;
    }

    /**
     * Hae jäljellä olevat yritykset
     *
     * @param string $key Rajoituksen avain
     * @param int $max_attempts Maksimi suoritukset
     * @param int $decay_seconds Aikaikkunan pituus
     * @return int
     */
    public function remaining( string $key, int $max_attempts, int $decay_seconds ): int {
        $cache_key = $this->prefix . md5( $key );
        $data = get_transient( $cache_key );

        if ( false === $data ) {
            return $max_attempts;
        }

        if ( ( time() - $data['started_at'] ) >= $decay_seconds ) {
            return $max_attempts;
        }

        return max( 0, $max_attempts - $data['count'] );
    }

    /**
     * Hae aika sekunteina kunnes rate limit nollautuu
     *
     * @param string $key Rajoituksen avain
     * @param int $decay_seconds Aikaikkunan pituus
     * @return int Sekuntia jäljellä, 0 jos ei rajoitusta
     */
    public function availableIn( string $key, int $decay_seconds ): int {
        $cache_key = $this->prefix . md5( $key );
        $data = get_transient( $cache_key );

        if ( false === $data ) {
            return 0;
        }

        $elapsed = time() - $data['started_at'];

        if ( $elapsed >= $decay_seconds ) {
            return 0;
        }

        return $decay_seconds - $elapsed;
    }

    /**
     * Nollaa rate limit
     *
     * @param string $key Rajoituksen avain
     * @return bool
     */
    public function reset( string $key ): bool {
        $cache_key = $this->prefix . md5( $key );
        return delete_transient( $cache_key );
    }

    /**
     * Luo rate limit avain job-tyypille
     *
     * @param string $job_type Job-luokan nimi
     * @return string
     */
    public function keyForJobType( string $job_type ): string {
        return 'job_type:' . $job_type;
    }

    /**
     * Luo rate limit avain jonolle
     *
     * @param string $queue Jonon nimi
     * @return string
     */
    public function keyForQueue( string $queue ): string {
        return 'queue:' . $queue;
    }

    /**
     * Luo rate limit avain custom-nimellä
     *
     * @param string $name Custom nimi
     * @return string
     */
    public function keyFor( string $name ): string {
        return 'custom:' . $name;
    }

    /**
     * Tarkista job-tyypin rate limit
     *
     * @param object $job Job-objekti
     * @return bool True jos voi suorittaa
     */
    public function allowsJob( $job ): bool {
        // Tarkista onko jobilla rate limit määritelty
        if ( ! isset( $job->rate_limit ) || empty( $job->rate_limit ) ) {
            return true;
        }

        $limit = $job->rate_limit;

        // Oletusarvot
        $max_attempts = $limit['max'] ?? 60;
        $decay_seconds = $limit['per'] ?? 60;
        $key = $limit['key'] ?? $this->keyForJobType( get_class( $job ) );

        return $this->check( $key, $max_attempts, $decay_seconds );
    }

    /**
     * Kirjaa jobin suoritus rate limitteriin
     *
     * @param object $job Job-objekti
     * @return bool True jos suoritus sallittu ja kirjattu
     */
    public function hitForJob( $job ): bool {
        if ( ! isset( $job->rate_limit ) || empty( $job->rate_limit ) ) {
            return true;
        }

        $limit = $job->rate_limit;

        $max_attempts = $limit['max'] ?? 60;
        $decay_seconds = $limit['per'] ?? 60;
        $key = $limit['key'] ?? $this->keyForJobType( get_class( $job ) );

        return $this->attempt( $key, $max_attempts, $decay_seconds );
    }

    /**
     * Hae rate limit tilastot
     *
     * @param string $key Rajoituksen avain
     * @param int $max_attempts Maksimi
     * @param int $decay_seconds Aikaikkuna
     * @return array
     */
    public function getStats( string $key, int $max_attempts, int $decay_seconds ): array {
        $cache_key = $this->prefix . md5( $key );
        $data = get_transient( $cache_key );

        if ( false === $data ) {
            return [
                'key'           => $key,
                'used'          => 0,
                'remaining'     => $max_attempts,
                'max'           => $max_attempts,
                'resets_in'     => 0,
                'window_seconds' => $decay_seconds,
            ];
        }

        $elapsed = time() - $data['started_at'];
        $resets_in = max( 0, $decay_seconds - $elapsed );

        if ( $elapsed >= $decay_seconds ) {
            return [
                'key'           => $key,
                'used'          => 0,
                'remaining'     => $max_attempts,
                'max'           => $max_attempts,
                'resets_in'     => 0,
                'window_seconds' => $decay_seconds,
            ];
        }

        return [
            'key'           => $key,
            'used'          => $data['count'],
            'remaining'     => max( 0, $max_attempts - $data['count'] ),
            'max'           => $max_attempts,
            'resets_in'     => $resets_in,
            'window_seconds' => $decay_seconds,
        ];
    }
}
