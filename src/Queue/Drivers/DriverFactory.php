<?php
/**
 * Driver Factory
 *
 * @package WPCronV2\Queue\Drivers
 */

namespace WPCronV2\Queue\Drivers;

class DriverFactory {

    /**
     * Luo driver
     *
     * @param string|null $driver Driver tyyppi (null = asetuksista)
     * @param array $config Asetukset
     * @return DriverInterface
     * @throws \Exception Jos tuntematon driver
     */
    public static function create( ?string $driver = null, array $config = [] ): DriverInterface {
        if ( $driver === null ) {
            $settings = get_option( 'wp_cron_v2_settings', [] );
            $driver = $settings['driver'] ?? 'database';
        }

        switch ( $driver ) {
            case 'database':
            case 'mysql':
            case 'sqlite':
                return new DatabaseDriver();

            case 'redis':
                $redis_config = self::getRedisConfig( $config );
                return new RedisDriver( $redis_config );

            default:
                // Tarkista onko custom driver rekisteröity
                $custom_driver = apply_filters( 'wp_cron_v2_driver_' . $driver, null, $config );

                if ( $custom_driver instanceof DriverInterface ) {
                    return $custom_driver;
                }

                throw new \Exception( "Unknown queue driver: {$driver}" );
        }
    }

    /**
     * Hae Redis-asetukset
     *
     * @param array $override Ohittavat asetukset
     * @return array
     */
    private static function getRedisConfig( array $override = [] ): array {
        $settings = get_option( 'wp_cron_v2_settings', [] );

        $defaults = [
            'host'     => defined( 'WP_CRON_V2_REDIS_HOST' ) ? WP_CRON_V2_REDIS_HOST : '127.0.0.1',
            'port'     => defined( 'WP_CRON_V2_REDIS_PORT' ) ? WP_CRON_V2_REDIS_PORT : 6379,
            'password' => defined( 'WP_CRON_V2_REDIS_PASSWORD' ) ? WP_CRON_V2_REDIS_PASSWORD : '',
            'database' => defined( 'WP_CRON_V2_REDIS_DATABASE' ) ? WP_CRON_V2_REDIS_DATABASE : 0,
            'timeout'  => 2.0,
            'prefix'   => 'wp_cron_v2:',
        ];

        // Yhdistä wp-config.php vakiot, asetukset ja override
        $from_settings = [
            'host'     => $settings['redis_host'] ?? null,
            'port'     => $settings['redis_port'] ?? null,
            'password' => $settings['redis_password'] ?? null,
            'database' => $settings['redis_database'] ?? null,
        ];

        // Poista null arvot
        $from_settings = array_filter( $from_settings, function( $v ) {
            return $v !== null;
        } );

        return array_merge( $defaults, $from_settings, $override );
    }

    /**
     * Tarkista onko Redis käytettävissä
     *
     * @return bool
     */
    public static function isRedisAvailable(): bool {
        return class_exists( '\Redis' );
    }

    /**
     * Testaa Redis-yhteys
     *
     * @param array $config Asetukset
     * @return array ['success' => bool, 'message' => string]
     */
    public static function testRedisConnection( array $config = [] ): array {
        if ( ! self::isRedisAvailable() ) {
            return [
                'success' => false,
                'message' => 'PHP Redis extension is not installed',
            ];
        }

        try {
            $redis_config = self::getRedisConfig( $config );
            $driver = new RedisDriver( $redis_config );

            if ( $driver->isConnected() ) {
                return [
                    'success' => true,
                    'message' => 'Connected to Redis at ' . $redis_config['host'] . ':' . $redis_config['port'],
                ];
            }

            return [
                'success' => false,
                'message' => 'Could not connect to Redis',
            ];
        } catch ( \Exception $e ) {
            return [
                'success' => false,
                'message' => $e->getMessage(),
            ];
        }
    }

    /**
     * Hae tuetut driverit
     *
     * @return array
     */
    public static function getSupportedDrivers(): array {
        $drivers = [
            'database' => [
                'name'        => 'Database (MySQL/SQLite)',
                'description' => 'Default WordPress database',
                'available'   => true,
            ],
            'redis' => [
                'name'        => 'Redis',
                'description' => 'High-performance in-memory data store',
                'available'   => self::isRedisAvailable(),
            ],
        ];

        return apply_filters( 'wp_cron_v2_supported_drivers', $drivers );
    }
}
