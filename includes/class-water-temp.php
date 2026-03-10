<?php
/**
 * LVB_Water_Temp – fetches & caches Bodensee water temperature.
 *
 * Reads the current water temperature for Lake Constance (Bodensee) from the
 * Swiss federal BAFU (Bundesamt für Umwelt) real-time hydrological data API
 * provided by api.existenz.ch. The specific monitoring station used is:
 *   Station 2135 – Bodensee Untersee (Steckborn)
 *
 * The result is cached in a WP transient for one hour (CACHE_SEC = 3600) so
 * the external API is not hit on every page load. If the API is unreachable or
 * returns unexpected data the method returns null and no transient is set,
 * allowing the next request to retry immediately.
 *
 * The temperature value is exposed to the frontend via an AJAX action
 * (lvb_water_temp) that can be called by any page-level JavaScript. A
 * corresponding widget or block script can display it without coupling to this
 * plugin's PHP render cycle.
 *
 * @package LakeVision_Booking
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Provides a cached AJAX proxy for the Bodensee water temperature.
 *
 * @package LakeVision_Booking
 */
class LVB_Water_Temp {

    /**
     * WP transient key used to cache the fetched temperature value.
     *
     * @var string
     */
    const TRANSIENT = 'lvb_water_temp';

    /**
     * Cache lifetime in seconds (1 hour).
     *
     * @var int
     */
    const CACHE_SEC = 3600;

    /**
     * Register the AJAX actions for the water-temperature endpoint.
     *
     * Must be called during plugin initialisation (before any request handling).
     * Registers handlers for both logged-in (`wp_ajax_`) and guest
     * (`wp_ajax_nopriv_`) contexts so the temperature is accessible on all
     * front-end pages regardless of authentication state.
     *
     * @return void
     */
    public static function register() {
        add_action( 'wp_ajax_lvb_water_temp',        [ __CLASS__, 'ajax' ] );
        add_action( 'wp_ajax_nopriv_lvb_water_temp', [ __CLASS__, 'ajax' ] );
    }

    /**
     * AJAX handler: return the cached water temperature as a JSON response.
     *
     * Sends a JSON success response with a `temp` key (float, degrees Celsius)
     * when the temperature is available, or a JSON error with `message =
     * 'unavailable'` when the fetch failed or returned no usable data.
     *
     * @return void  Terminates via wp_send_json_success() or wp_send_json_error().
     */
    public static function ajax() {
        $temp = self::get();
        if ( $temp !== null ) {
            wp_send_json_success( [ 'temp' => $temp ] );
        } else {
            wp_send_json_error( [ 'message' => 'unavailable' ] );
        }
    }

    /**
     * Return the current water temperature, using the transient cache when available.
     *
     * If no valid cached value exists, delegates to {@see fetch()} and stores
     * the result in the transient. Returns null (without caching) when the fetch
     * fails so that the next call will retry the external API.
     *
     * @return float|null  Water temperature in degrees Celsius, or null if unavailable.
     */
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

    /**
     * Perform a live HTTP request to the BAFU API and parse the temperature value.
     *
     * Queries the api.existenz.ch endpoint for the latest temperature reading at
     * BAFU station 2135 (Bodensee Untersee/Steckborn). The expected response
     * structure is:
     *   { "payload": [ { "val": <float> } ] }
     *
     * Returns null on any HTTP error, non-2xx response, JSON parse failure, or
     * missing/non-numeric `val` field.
     *
     * @return float|null  Parsed water temperature in degrees Celsius, or null on failure.
     */
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
