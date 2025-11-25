<?php
/**
 * Plugin deaktivaattori
 *
 * @package WPCronV2\Includes
 */

namespace WPCronV2\Includes;

class Deactivator {

    /**
     * Deaktivoi plugin
     */
    public static function deactivate(): void {
        // Poista mahdolliset ajastetut tehtävät
        wp_clear_scheduled_hook( 'wp_cron_v2_cleanup' );
        wp_clear_scheduled_hook( 'wp_cron_v2_monitor' );

        // Tyhjennä rewrite rules
        flush_rewrite_rules();

        // Huom: Emme poista tauluja deaktivoinnissa
        // Käyttäjä voi haluta säilyttää datan
        // Taulut poistetaan vain uninstall.php:ssä
    }
}
