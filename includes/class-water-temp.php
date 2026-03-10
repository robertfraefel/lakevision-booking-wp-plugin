<?php
/**
 * LVB_Water_Temp – fetches & caches Bodensee water temperature.
 * Source: api.existenz.ch (BAFU station 2135 – Bodensee Untersee/Steckborn)
 * Cached 1h via WP transients.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class LVB_Water_Temp {

    const TRANSIENT = 'lvb_water_temp';
    const CACHE_SEC = 3600; // 1 hour

    public static function register() {
        add_action( 'wp_ajax_lvb_water_temp',        [ __CLASS__, 'ajax' ] );
        add_action( 'wp_ajax_nopriv_lvb_water_temp', [ __CLASS__, 'ajax' ] );
    }

    public static function ajax() {
        $temp = self::get();
        if ( $temp !== null ) {
            wp_send_json_success( [ 'temp' => $temp ] );
        } else {
            wp_send_json_error( [ 'message' => 'unavailable' ] );
        }
    }

    public static function get() {
        $cached = get_transient( self::TRANSIENT );
        if ( $cached !== false ) {
            return $cached;
        }

        $temp = self::fetch();

        if ( $temp !== null ) {
            set_transient( self::TRANSIENT, $temp, self::CACHE_SEC );
        }

        return $temp;
    }

    private static function fetch() {
        // BAFU station 2135 = Bodensee Untersee (Steckborn) – official Swiss federal data
        $url  = 'https://api.existenz.ch/apiv1/hydro/latest?locations=2135&parameters=temperature&app=lakevision.ch&version=1.0.0';
        $resp = wp_remote_get( $url, [ 'timeout' => 8 ] );

        if ( is_wp_error( $resp ) ) {
            return null;
        }

        $body = wp_remote_retrieve_body( $resp );
        $data = json_decode( $body, true );

        if (
            isset( $data['payload'][0]['val'] ) &&
            is_numeric( $data['payload'][0]['val'] )
        ) {
            return (float) $data['payload'][0]['val'];
        }

        return null;
    }
}
