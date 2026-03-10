<?php
/**
 * LVB_Google_Calendar – OAuth2 flow + Google Calendar API wrapper.
 *
 * All HTTP calls use wp_remote_get / wp_remote_post (no Composer).
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class LVB_Google_Calendar {

    const AUTH_URL    = 'https://accounts.google.com/o/oauth2/v2/auth';
    const TOKEN_URL   = 'https://oauth2.googleapis.com/token';
    const REVOKE_URL  = 'https://oauth2.googleapis.com/revoke';
    const CAL_BASE    = 'https://www.googleapis.com/calendar/v3/calendars';
    const SCOPE       = 'https://www.googleapis.com/auth/calendar';

    // -----------------------------------------------------------------------
    // OAuth2 helpers
    // -----------------------------------------------------------------------

    /**
     * Build the Google authorisation URL and redirect the browser to it.
     */
    public static function get_auth_url() {
        $client_id    = get_option( 'lvb_google_client_id', '' );
        $redirect_uri = self::callback_url();

        $params = [
            'client_id'             => $client_id,
            'redirect_uri'          => $redirect_uri,
            'response_type'         => 'code',
            'scope'                 => self::SCOPE,
            'access_type'           => 'offline',
            'prompt'                => 'consent',
            'state'                 => wp_create_nonce( 'lvb_google_oauth' ),
        ];

        return self::AUTH_URL . '?' . http_build_query( $params );
    }

    /**
     * Callback URL used as the OAuth redirect_uri.
     */
    public static function callback_url() {
        return admin_url( 'admin-post.php?action=lvb_google_callback' );
    }

    /**
     * Handle the OAuth callback from Google (called via admin-post hook).
     */
    public static function oauth_callback() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Unauthorised.', 'lakevision-booking' ) );
        }

        // Verify state nonce
        $state = isset( $_GET['state'] ) ? sanitize_text_field( wp_unslash( $_GET['state'] ) ) : '';
        if ( ! wp_verify_nonce( $state, 'lvb_google_oauth' ) ) {
            wp_die( esc_html__( 'Invalid state parameter.', 'lakevision-booking' ) );
        }

        if ( isset( $_GET['error'] ) ) {
            $error = sanitize_text_field( wp_unslash( $_GET['error'] ) );
            wp_redirect( add_query_arg( [ 'page' => 'lvb-settings', 'lvb_error' => urlencode( $error ) ], admin_url( 'admin.php' ) ) );
            exit;
        }

        $code = isset( $_GET['code'] ) ? sanitize_text_field( wp_unslash( $_GET['code'] ) ) : '';
        if ( empty( $code ) ) {
            wp_die( esc_html__( 'No code returned by Google.', 'lakevision-booking' ) );
        }

        $tokens = self::exchange_code_for_tokens( $code );

        if ( is_wp_error( $tokens ) ) {
            wp_redirect( add_query_arg( [ 'page' => 'lvb-settings', 'lvb_error' => urlencode( $tokens->get_error_message() ) ], admin_url( 'admin.php' ) ) );
            exit;
        }

        if ( ! empty( $tokens['refresh_token'] ) ) {
            update_option( 'lvb_google_refresh_token', $tokens['refresh_token'] );
        }
        if ( ! empty( $tokens['access_token'] ) ) {
            set_transient( 'lvb_google_access_token', $tokens['access_token'], max( 0, (int) $tokens['expires_in'] - 60 ) );
        }

        wp_redirect( add_query_arg( [ 'page' => 'lvb-settings', 'lvb_connected' => '1' ], admin_url( 'admin.php' ) ) );
        exit;
    }

    /**
     * Exchange the one-time authorisation code for access + refresh tokens.
     */
    private static function exchange_code_for_tokens( $code ) {
        $response = wp_remote_post( self::TOKEN_URL, [
            'body' => [
                'code'          => $code,
                'client_id'     => get_option( 'lvb_google_client_id', '' ),
                'client_secret' => get_option( 'lvb_google_client_secret', '' ),
                'redirect_uri'  => self::callback_url(),
                'grant_type'    => 'authorization_code',
            ],
        ] );

        return self::parse_token_response( $response );
    }

    /**
     * Get a valid access token, refreshing if necessary.
     */
    public static function get_access_token() {
        $cached = get_transient( 'lvb_google_access_token' );
        if ( $cached ) {
            return $cached;
        }

        $refresh_token = get_option( 'lvb_google_refresh_token', '' );
        if ( empty( $refresh_token ) ) {
            return new WP_Error( 'no_refresh_token', __( 'Google Calendar not connected. Please authorise in Settings.', 'lakevision-booking' ) );
        }

        $response = wp_remote_post( self::TOKEN_URL, [
            'body' => [
                'refresh_token' => $refresh_token,
                'client_id'     => get_option( 'lvb_google_client_id', '' ),
                'client_secret' => get_option( 'lvb_google_client_secret', '' ),
                'grant_type'    => 'refresh_token',
            ],
        ] );

        $tokens = self::parse_token_response( $response );
        if ( is_wp_error( $tokens ) ) {
            return $tokens;
        }

        $access_token = $tokens['access_token'];
        set_transient( 'lvb_google_access_token', $access_token, max( 0, (int) $tokens['expires_in'] - 60 ) );
        return $access_token;
    }

    /**
     * Revoke & remove stored tokens (disconnect).
     */
    public static function disconnect() {
        $refresh_token = get_option( 'lvb_google_refresh_token', '' );
        if ( $refresh_token ) {
            wp_remote_post( self::REVOKE_URL, [
                'body' => [ 'token' => $refresh_token ],
            ] );
        }
        delete_option( 'lvb_google_refresh_token' );
        delete_transient( 'lvb_google_access_token' );
    }

    /**
     * Parse the token endpoint response into an array or WP_Error.
     */
    private static function parse_token_response( $response ) {
        if ( is_wp_error( $response ) ) {
            return $response;
        }
        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( ! empty( $body['error'] ) ) {
            return new WP_Error( 'google_token_error', $body['error_description'] ?? $body['error'] );
        }
        return $body;
    }

    // -----------------------------------------------------------------------
    // Calendar API calls
    // -----------------------------------------------------------------------

    /**
     * List events from a calendar between two RFC3339 timestamps.
     *
     * @param string $calendar_id   Google Calendar ID.
     * @param string $time_min      RFC3339 start (e.g. 2025-06-01T00:00:00Z).
     * @param string $time_max      RFC3339 end.
     * @param int    $max_results   Max number of events (default 250).
     * @return array|WP_Error       Array of event objects or WP_Error.
     */
    public static function list_events( $calendar_id, $time_min, $time_max, $max_results = 250 ) {
        $token = self::get_access_token();
        if ( is_wp_error( $token ) ) {
            return $token;
        }

        $url = self::CAL_BASE . '/' . rawurlencode( $calendar_id ) . '/events?' . http_build_query( [
            'timeMin'      => $time_min,
            'timeMax'      => $time_max,
            'singleEvents' => 'true',
            'orderBy'      => 'startTime',
            'maxResults'   => $max_results,
        ] );

        $response = wp_remote_get( $url, [
            'headers' => [ 'Authorization' => 'Bearer ' . $token ],
            'timeout' => 15,
        ] );

        return self::parse_calendar_response( $response );
    }

    /**
     * Create a new event in the given calendar.
     *
     * @param string $calendar_id
     * @param array  $event_body  Associative array matching the Calendar API event resource.
     * @return array|WP_Error     Created event resource or WP_Error.
     */
    public static function create_event( $calendar_id, $event_body ) {
        $token = self::get_access_token();
        if ( is_wp_error( $token ) ) {
            return $token;
        }

        $url = self::CAL_BASE . '/' . rawurlencode( $calendar_id ) . '/events';

        $response = wp_remote_post( $url, [
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Content-Type'  => 'application/json',
            ],
            'body'    => wp_json_encode( $event_body ),
            'timeout' => 15,
        ] );

        return self::parse_calendar_response( $response );
    }

    /**
     * Delete an event from the given calendar.
     *
     * @param string $calendar_id
     * @param string $event_id
     * @return true|WP_Error
     */
    public static function delete_event( $calendar_id, $event_id ) {
        $token = self::get_access_token();
        if ( is_wp_error( $token ) ) {
            return $token;
        }

        $url = self::CAL_BASE . '/' . rawurlencode( $calendar_id ) . '/events/' . rawurlencode( $event_id );

        $response = wp_remote_request( $url, [
            'method'  => 'DELETE',
            'headers' => [ 'Authorization' => 'Bearer ' . $token ],
            'timeout' => 15,
        ] );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $code = wp_remote_retrieve_response_code( $response );
        if ( $code === 204 || $code === 200 ) {
            return true;
        }

        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        return new WP_Error( 'google_delete_error', $body['error']['message'] ?? 'Unknown error deleting event.' );
    }

    /**
     * Parse a Calendar API response body.
     */
    private static function parse_calendar_response( $response ) {
        if ( is_wp_error( $response ) ) {
            return $response;
        }
        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( ! empty( $body['error'] ) ) {
            return new WP_Error( 'google_api_error', $body['error']['message'] ?? 'Unknown Google API error.' );
        }
        return $body;
    }

    // -----------------------------------------------------------------------
    // High-level helpers used by the booking flow
    // -----------------------------------------------------------------------

    /**
     * Fetch available slots from a calendar for a given date range.
     * Returns an array of simplified slot objects.
     *
     * @param string $calendar_id
     * @param string $date_from  Y-m-d
     * @param string $date_to    Y-m-d
     * @return array|WP_Error
     */
    public static function get_available_slots( $calendar_id, $date_from, $date_to ) {
        // Use timezone stored in WP settings.
        $tz       = wp_timezone();
        $time_min = ( new DateTime( $date_from . 'T00:00:00', $tz ) )->format( DateTime::RFC3339 );
        $time_max = ( new DateTime( $date_to   . 'T23:59:59', $tz ) )->format( DateTime::RFC3339 );

        $result = self::list_events( $calendar_id, $time_min, $time_max );
        if ( is_wp_error( $result ) ) {
            return $result;
        }

        $slots = [];
        foreach ( $result['items'] ?? [] as $event ) {
            // Skip cancelled events
            if ( isset( $event['status'] ) && $event['status'] === 'cancelled' ) {
                continue;
            }
            // Only timed events (not all-day)
            if ( empty( $event['start']['dateTime'] ) ) {
                continue;
            }

            $tz    = wp_timezone();
            $start = new DateTime( $event['start']['dateTime'] );
            $start->setTimezone( $tz );
            $end   = new DateTime( $event['end']['dateTime'] );
            $end->setTimezone( $tz );

            $slots[] = [
                'id'          => $event['id'],
                'title'       => $event['summary'] ?? 'Available',
                'start'       => $start->format( 'Y-m-d H:i:s' ),
                'end'         => $end->format( 'Y-m-d H:i:s' ),
                'start_date'  => $start->format( 'Y-m-d' ),
                'start_time'  => $start->format( 'H:i' ),
                'end_time'    => $end->format( 'H:i' ),
                'duration'    => (int) ( ( $end->getTimestamp() - $start->getTimestamp() ) / 60 ),
            ];
        }

        return $slots;
    }

    /**
     * Create a booking event in Google Calendar and return its ID.
     *
     * @param string $calendar_id
     * @param array  $booking  {service_name, staff_name, customer_name, start, end, notes}
     * @return string|WP_Error  Event ID or WP_Error.
     */
    public static function create_booking_event( $calendar_id, $booking ) {
        $tz = wp_timezone_string();

        $event_body = [
            'summary'     => sprintf(
                '[Booking] %s – %s',
                $booking['service_name'],
                $booking['customer_name']
            ),
            'description' => implode( "\n", array_filter( [
                'Service: ' . $booking['service_name'],
                'Staff: '   . ( $booking['staff_name'] ?? '' ),
                'Customer: '. $booking['customer_name'],
                'Notes: '   . ( $booking['notes'] ?? '' ),
            ] ) ),
            'start' => [
                'dateTime' => ( new DateTime( $booking['start'], wp_timezone() ) )->format( DateTime::RFC3339 ),
                'timeZone' => $tz,
            ],
            'end'   => [
                'dateTime' => ( new DateTime( $booking['end'], wp_timezone() ) )->format( DateTime::RFC3339 ),
                'timeZone' => $tz,
            ],
            'colorId' => '11', // Tomato – visually distinct from availability slots
        ];

        $result = self::create_event( $calendar_id, $event_body );
        if ( is_wp_error( $result ) ) {
            return $result;
        }

        return $result['id'] ?? '';
    }

    /**
     * True if the plugin is connected to Google Calendar.
     */
    public static function is_connected() {
        return ! empty( get_option( 'lvb_google_refresh_token', '' ) );
    }
}
