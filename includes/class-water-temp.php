<?php
/**
 * LVB_Water_Temp – fetches & caches Bodensee water temperature.
 *
 * Reads the current water temperature for Lake Constance (Bodensee) from the
 * Canton Thurgau Open Data API (data.tg.ch). The specific monitoring station is:
 *   Feldbach Steckborn – Bodensee Untersee, Kanton Thurgau
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
     * Transient key for the measurement timestamp.
     *
     * @var string
     */
    const TRANSIENT_DATE = 'lvb_water_temp_date';

    /**
     * Cache lifetime in seconds (6 hours – data updates once daily).
     *
     * @var int
     */
    const CACHE_SEC = 21600;

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
            wp_send_json_success( [
                'temp' => $temp,
                'date' => get_transient( self::TRANSIENT_DATE ) ?: null,
            ] );
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

        $result = self::fetch();

        if ( $result !== null ) {
            set_transient( self::TRANSIENT, $result['temp'], self::CACHE_SEC );
            set_transient( self::TRANSIENT_DATE, $result['date'], self::CACHE_SEC );
        }

        return $result ? $result['temp'] : null;
    }

    /**
     * Perform a live HTTP request to the BAFU API and parse the temperature value.
     *
     * Queries the Canton Thurgau Open Data API for the latest temperature reading
     * at station "Feldbach Steckborn" (Bodensee Untersee). The expected response
     * structure is:
     *   { "records": [ { "record": { "fields": { "wert": <float> } } } ] }
     *
     * Returns null on any HTTP error, non-2xx response, JSON parse failure, or
     * missing/non-numeric `wert` field.
     *
     * @return float|null  Parsed water temperature in degrees Celsius, or null on failure.
     */
    private static function fetch() {
        // Kanton Thurgau Open Data – Feldbach Steckborn, Bodensee Untersee
        $url = 'https://data.tg.ch/api/v2/catalog/datasets/dbu-afu-9/records'
             . '?where=messgruppierung_ortsbezeichnung%3D%22Feldbach+Steckborn%22'
             . '&order_by=timestamp+desc&limit=1';

        $resp = wp_remote_get( $url, [ 'timeout' => 8 ] );

        if ( is_wp_error( $resp ) ) {
            return null;
        }

        $body = wp_remote_retrieve_body( $resp );
        $data = json_decode( $body, true );

        $fields = $data['records'][0]['record']['fields'] ?? null;
        $val    = $fields['wert'] ?? null;
        $ts     = $fields['timestamp'] ?? null;

        if ( ! is_numeric( $val ) ) {
            return null;
        }

        // Format date as "9. März 2026"
        $date = null;
        if ( $ts ) {
            $dt   = new DateTime( $ts );
            $months = [ 'Januar','Februar','März','April','Mai','Juni','Juli','August','September','Oktober','November','Dezember' ];
            $date = $dt->format( 'j' ) . '. ' . $months[ (int) $dt->format( 'n' ) - 1 ] . ' ' . $dt->format( 'Y' );
        }

        return [ 'temp' => (float) $val, 'date' => $date ];
    }
}
