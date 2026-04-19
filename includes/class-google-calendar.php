<?php
/**
 * LVB_Google_Calendar – OAuth2 flow + Google Calendar API wrapper.
 *
 * Implements the full Google OAuth 2.0 authorisation code flow without any
 * Composer dependencies. Token exchange and refresh use wp_remote_post() and
 * the access token is cached in a WP transient (keyed `lvb_google_access_token`)
 * so only one token refresh is needed per expiry period (~60 minutes).
 *
 * Calendar API calls (list events, create event, delete event) are plain REST
 * requests to the Google Calendar v3 API using wp_remote_get/post/request().
 *
 * Stored WP options:
 *   lvb_google_client_id      – OAuth client ID from Google Cloud Console.
 *   lvb_google_client_secret  – OAuth client secret.
 *   lvb_google_refresh_token  – Long-lived refresh token saved after first auth.
 *   lvb_google_calendar_id    – Default calendar ID used when no staff calendar is set.
 *
 * @package LakeVision_Booking
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Handles Google OAuth 2.0 authentication and Google Calendar API interactions.
 *
 * @package LakeVision_Booking
 */
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
     * Build and return the Google OAuth 2.0 authorisation URL.
     *
     * The URL includes a nonce in the `state` parameter which is verified in
     * {@see oauth_callback()} to prevent CSRF attacks. The `access_type=offline`
     * and `prompt=consent` parameters ensure Google issues a refresh token even
     * when the user has previously authorised the application.
     *
     * @return string  The full authorisation URL to redirect the admin user to.
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
     * Return the OAuth redirect_uri registered with Google Cloud Console.
     *
     * Must exactly match the URI configured in the Google Cloud project's
     * authorised redirect URIs list, otherwise Google will reject the callback.
     *
     * @return string  The absolute admin-post.php URL for the OAuth callback action.
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
     * Exchange the one-time authorisation code for access and refresh tokens.
     *
     * Sends a POST request to the Google token endpoint with the authorisation
     * code received during the OAuth callback, and returns the parsed token
     * response array.
     *
     * @param string $code  The one-time authorisation code from Google's callback.
     * @return array|WP_Error  Token array (access_token, refresh_token, expires_in, …)
     *                         or WP_Error on HTTP or API failure.
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
     * Return a valid Google API access token, refreshing from the stored refresh
     * token if the cached token has expired.
     *
     * The access token is cached as a WP transient for (expires_in − 60) seconds
     * to ensure it is refreshed before it actually expires.
     *
     * @return string|WP_Error  A valid Bearer access token string, or WP_Error if
     *                          no refresh token is stored or the refresh request fails.
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
     * Revoke the stored refresh token with Google and delete all stored credentials.
     *
     * Sends a revocation request to Google (best-effort; failure is silently
     * ignored) and then removes both the `lvb_google_refresh_token` option and
     * the `lvb_google_access_token` transient from WordPress.
     *
     * @return void
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
     * Parse a Google token endpoint HTTP response into a token array or WP_Error.
     *
     * Handles wp_remote_post() failures, JSON decode, and Google-level error
     * fields (the `error` / `error_description` response body keys).
     *
     * @param array|WP_Error $response  The raw return value from wp_remote_post().
     * @return array|WP_Error  Decoded token array on success, or WP_Error describing
     *                         the HTTP or API failure.
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
     * Patch (partial update) an event in the given calendar.
     *
     * Sends only the fields in $event_body, leaving untouched fields intact.
     * Typically used to reschedule an event (update start/end) without touching
     * summary/description.
     *
     * @param string $calendar_id
     * @param string $event_id
     * @param array  $event_body  Partial Calendar API event resource.
     * @return array|WP_Error     Updated event resource or WP_Error.
     */
    public static function patch_event( $calendar_id, $event_id, $event_body ) {
        $token = self::get_access_token();
        if ( is_wp_error( $token ) ) {
            return $token;
        }

        $url = self::CAL_BASE . '/' . rawurlencode( $calendar_id ) . '/events/' . rawurlencode( $event_id );

        $response = wp_remote_request( $url, [
            'method'  => 'PATCH',
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
     * Parse a Google Calendar API HTTP response body into a data array or WP_Error.
     *
     * Handles wp_remote_*() failures and Google API-level error objects
     * (the `error.message` field in the response body).
     *
     * @param array|WP_Error $response  The raw return value from a wp_remote_*() call.
     * @return array|WP_Error  Decoded response body array on success, or WP_Error.
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

        $color_id = '11'; // Default: Tomato – visually distinct from availability slots
        if ( isset( $booking['color_id'] ) && $booking['color_id'] !== '' && $booking['color_id'] !== null ) {
            $cid = (int) $booking['color_id'];
            if ( $cid >= 1 && $cid <= 11 ) {
                $color_id = (string) $cid;
            }
        }

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
            'colorId' => $color_id,
        ];

        $result = self::create_event( $calendar_id, $event_body );
        if ( is_wp_error( $result ) ) {
            return $result;
        }

        return $result['id'] ?? '';
    }

    /**
     * Google Calendar event color palette (colorId 1-11).
     *
     * Hex values approximate Google Calendars stock "event colors" as rendered
     * in the Calendar UI. Used by the admin UI to render a color picker.
     *
     * @return array<int, array{name:string, hex:string}>
     */
    public static function get_event_color_palette() {
        return [
            1  => [ 'name' => 'Lavender',  'hex' => '#7986cb' ],
            2  => [ 'name' => 'Sage',      'hex' => '#33b679' ],
            3  => [ 'name' => 'Grape',     'hex' => '#8e24aa' ],
            4  => [ 'name' => 'Flamingo',  'hex' => '#e67c73' ],
            5  => [ 'name' => 'Banana',    'hex' => '#f6c026' ],
            6  => [ 'name' => 'Tangerine', 'hex' => '#f5511d' ],
            7  => [ 'name' => 'Peacock',   'hex' => '#039be5' ],
            8  => [ 'name' => 'Graphite',  'hex' => '#616161' ],
            9  => [ 'name' => 'Blueberry', 'hex' => '#3f51b5' ],
            10 => [ 'name' => 'Basil',     'hex' => '#0b8043' ],
            11 => [ 'name' => 'Tomato',    'hex' => '#d60000' ],
        ];
    }

    /**
     * Check whether the plugin is currently connected to Google Calendar.
     *
     * A non-empty refresh token is the indicator of a live connection. The
     * access token itself may have expired and will be refreshed on the next
     * API call; what matters is that a refresh token is available.
     *
     * @return bool  True if a refresh token is stored, false otherwise.
     */
    public static function is_connected() {
        return ! empty( get_option( 'lvb_google_refresh_token', '' ) );
    }

    /**
     * Health check: verify the Google Calendar connection is working.
     *
     * Attempts to obtain an access token. If it fails, sends an alert email
     * to the admin notification address (max once per 24 hours to avoid spam).
     *
     * Hooked to the `lvb_calendar_health_check` cron event.
     *
     * @return void
     */
    public static function health_check() {
        if ( ! self::is_connected() ) {
            self::send_health_alert( 'Kein Refresh Token vorhanden – Google Calendar ist nicht verbunden.' );
            return;
        }

        $token = self::get_access_token();
        if ( is_wp_error( $token ) ) {
            self::send_health_alert( 'Google Calendar Verbindung fehlgeschlagen: ' . $token->get_error_message() );
        } else {
            // Connection OK – clear any previous alert flag
            delete_option( 'lvb_health_alert_sent' );
        }
    }

    /**
     * Send a health alert email (max once per 24h).
     *
     * @param string $reason  Description of the failure.
     * @return void
     */
    private static function send_health_alert( $reason ) {
        // Only send once per 24 hours
        $last_sent = get_option( 'lvb_health_alert_sent', 0 );
        if ( $last_sent && ( time() - (int) $last_sent ) < DAY_IN_SECONDS ) {
            return;
        }

        $to      = get_option( 'lvb_admin_notification_email', get_option( 'admin_email' ) );
        $subject = '⚠️ Google Calendar Verbindung unterbrochen – ' . get_bloginfo( 'name' );
        $message = '<html><body style="font-family:sans-serif;color:#333;">';
        $message .= '<h2 style="color:#c0392b;">Google Calendar Verbindung unterbrochen</h2>';
        $message .= '<p>' . esc_html( $reason ) . '</p>';
        $message .= '<p>Bitte verbinde Google Calendar neu unter:<br>';
        $message .= '<a href="' . esc_url( admin_url( 'admin.php?page=lvb-settings' ) ) . '">LV Booking → Settings</a></p>';
        $message .= '<p style="color:#999;font-size:12px;">Diese Nachricht wird maximal einmal pro Tag gesendet.</p>';
        $message .= '</body></html>';

        $headers = [ 'Content-Type: text/html; charset=UTF-8' ];

        $from      = get_option( 'lvb_email_from', get_bloginfo( 'name' ) );
        $from_email = get_option( 'lvb_email_from_address', get_option( 'admin_email' ) );
        if ( $from && $from_email ) {
            $headers[] = 'From: ' . $from . ' <' . $from_email . '>';
        }

        wp_mail( $to, $subject, $message, $headers );

        update_option( 'lvb_health_alert_sent', time(), false );
    }
}
