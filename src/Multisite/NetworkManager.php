<?php
/**
 * Multisite Network Manager
 *
 * Hallitsee job-jonoja multisite-ympäristössä.
 *
 * @package WPCronV2\Multisite
 */

namespace WPCronV2\Multisite;

class NetworkManager {

    /**
     * Singleton instanssi
     *
     * @var NetworkManager|null
     */
    private static ?NetworkManager $instance = null;

    /**
     * Onko multisite aktiivinen
     *
     * @var bool
     */
    private bool $is_multisite;

    /**
     * Nykyinen site ID
     *
     * @var int
     */
    private int $current_site_id;

    /**
     * Hae singleton instanssi
     *
     * @return NetworkManager
     */
    public static function get_instance(): NetworkManager {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Konstruktori
     */
    private function __construct() {
        $this->is_multisite = is_multisite();
        $this->current_site_id = $this->is_multisite ? get_current_blog_id() : 0;
    }

    /**
     * Tarkista onko multisite
     *
     * @return bool
     */
    public function isMultisite(): bool {
        return $this->is_multisite;
    }

    /**
     * Hae nykyinen site ID
     *
     * @return int
     */
    public function getCurrentSiteId(): int {
        return $this->current_site_id;
    }

    /**
     * Aseta nykyinen site ID (workerille)
     *
     * @param int $site_id
     */
    public function setCurrentSiteId( int $site_id ): void {
        $this->current_site_id = $site_id;
    }

    /**
     * Hae kaikki sivustot
     *
     * @return array
     */
    public function getSites(): array {
        if ( ! $this->is_multisite ) {
            return [];
        }

        $sites = get_sites( [
            'fields'     => 'ids',
            'number'     => 0,
            'network_id' => get_current_network_id(),
        ] );

        return $sites;
    }

    /**
     * Hae sivuston tiedot
     *
     * @param int $site_id
     * @return array|null
     */
    public function getSiteInfo( int $site_id ): ?array {
        if ( ! $this->is_multisite ) {
            return null;
        }

        $site = get_site( $site_id );

        if ( ! $site ) {
            return null;
        }

        return [
            'id'     => $site->blog_id,
            'domain' => $site->domain,
            'path'   => $site->path,
            'name'   => get_blog_option( $site_id, 'blogname' ),
        ];
    }

    /**
     * Suorita callback tietyllä sivustolla
     *
     * @param int $site_id
     * @param callable $callback
     * @return mixed
     */
    public function runOnSite( int $site_id, callable $callback ) {
        if ( ! $this->is_multisite ) {
            return $callback();
        }

        $current_site = get_current_blog_id();

        if ( $current_site !== $site_id ) {
            switch_to_blog( $site_id );
        }

        try {
            $result = $callback();
        } finally {
            if ( $current_site !== $site_id ) {
                restore_current_blog();
            }
        }

        return $result;
    }

    /**
     * Hae tilastot kaikille sivustoille
     *
     * @return array
     */
    public function getNetworkStats(): array {
        if ( ! $this->is_multisite ) {
            return [];
        }

        global $wpdb;

        $table = $wpdb->base_prefix . 'job_queue';

        // Tarkista onko taulu olemassa
        $table_exists = $wpdb->get_var(
            $wpdb->prepare(
                "SHOW TABLES LIKE %s",
                $table
            )
        );

        if ( ! $table_exists ) {
            return [];
        }

        // Hae tilastot per sivusto
        $stats = $wpdb->get_results(
            "SELECT
                site_id,
                status,
                COUNT(*) as count
            FROM {$table}
            GROUP BY site_id, status
            ORDER BY site_id",
            ARRAY_A
        );

        $result = [];

        foreach ( $stats as $row ) {
            $site_id = (int) ( $row['site_id'] ?? 0 );

            if ( ! isset( $result[ $site_id ] ) ) {
                $site_info = $this->getSiteInfo( $site_id );
                $result[ $site_id ] = [
                    'site_id'   => $site_id,
                    'site_name' => $site_info['name'] ?? 'Site ' . $site_id,
                    'domain'    => $site_info['domain'] ?? '',
                    'queued'    => 0,
                    'running'   => 0,
                    'completed' => 0,
                    'failed'    => 0,
                ];
            }

            $status = $row['status'];
            if ( isset( $result[ $site_id ][ $status ] ) ) {
                $result[ $site_id ][ $status ] = (int) $row['count'];
            }
        }

        return $result;
    }

    /**
     * Hae jonot per sivusto
     *
     * @param int $site_id
     * @return array
     */
    public function getSiteQueues( int $site_id ): array {
        global $wpdb;

        $table = $wpdb->base_prefix . 'job_queue';

        $where = $site_id > 0 ? $wpdb->prepare( 'WHERE site_id = %d', $site_id ) : 'WHERE site_id IS NULL OR site_id = 0';

        $queues = $wpdb->get_results(
            "SELECT
                queue,
                status,
                COUNT(*) as count
            FROM {$table}
            {$where}
            GROUP BY queue, status
            ORDER BY queue",
            ARRAY_A
        );

        $result = [];

        foreach ( $queues as $row ) {
            $queue = $row['queue'];

            if ( ! isset( $result[ $queue ] ) ) {
                $result[ $queue ] = [
                    'queued'    => 0,
                    'running'   => 0,
                    'completed' => 0,
                    'failed'    => 0,
                ];
            }

            $status = $row['status'];
            if ( isset( $result[ $queue ][ $status ] ) ) {
                $result[ $queue ][ $status ] = (int) $row['count'];
            }
        }

        return $result;
    }

    /**
     * Aktivoi plugin kaikille sivustoille
     *
     * @param callable $activate_callback
     */
    public function networkActivate( callable $activate_callback ): void {
        if ( ! $this->is_multisite ) {
            $activate_callback();
            return;
        }

        $sites = $this->getSites();

        foreach ( $sites as $site_id ) {
            $this->runOnSite( $site_id, $activate_callback );
        }
    }

    /**
     * Aktivoi plugin uudelle sivustolle kun se luodaan
     *
     * @param \WP_Site $new_site
     * @param callable $activate_callback
     */
    public function onNewSite( \WP_Site $new_site, callable $activate_callback ): void {
        if ( ! is_plugin_active_for_network( WP_CRON_V2_PLUGIN_BASENAME ) ) {
            return;
        }

        $this->runOnSite( $new_site->blog_id, $activate_callback );
    }

    /**
     * Tarkista onko network admin
     *
     * @return bool
     */
    public function isNetworkAdmin(): bool {
        return $this->is_multisite && is_network_admin();
    }

    /**
     * Hae asetukset (network tai site)
     *
     * @param string $option
     * @param mixed $default
     * @return mixed
     */
    public function getSettings( string $option, $default = [] ) {
        if ( $this->is_multisite && $this->isNetworkAdmin() ) {
            return get_site_option( $option, $default );
        }

        return get_option( $option, $default );
    }

    /**
     * Tallenna asetukset (network tai site)
     *
     * @param string $option
     * @param mixed $value
     * @return bool
     */
    public function updateSettings( string $option, $value ): bool {
        if ( $this->is_multisite && $this->isNetworkAdmin() ) {
            return update_site_option( $option, $value );
        }

        return update_option( $option, $value );
    }
}
