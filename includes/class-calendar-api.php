<?php
/**
 * LVB_Calendar_API – REST endpoints powering the admin calendar view.
 *
 * Exposes three routes under the `lvb/v1` namespace:
 *   GET    /calendar/events           list bookings in a date range
 *   PATCH  /calendar/events/{id}      reschedule a booking (drag/resize)
 *   POST   /calendar/events/{id}/cancel   cancel a booking
 *
 * All routes require the `manage_options` capability. The list endpoint joins
 * customer/service/staff data and emits a payload in FullCalendars event shape,
 * including a per-staff color pulled from LVB_Google_Calendar::get_event_color_palette().
 *
 * When a booking is rescheduled and has a linked Google Calendar event, the
 * corresponding event is patched so the main calendar stays in sync.
 *
 * @package LakeVision_Booking
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class LVB_Calendar_API {

    const REST_NAMESPACE = 'lvb/v1';

    public static function register() {
        add_action( 'rest_api_init', [ __CLASS__, 'register_routes' ] );
    }

    public static function register_routes() {
        register_rest_route( self::REST_NAMESPACE, '/calendar/events', [
            [
                'methods'             => 'GET',
                'callback'            => [ __CLASS__, 'list_events' ],
                'permission_callback' => [ __CLASS__, 'permission_check' ],
            ],
            [
                'methods'             => 'POST',
                'callback'            => [ __CLASS__, 'create_event' ],
                'permission_callback' => [ __CLASS__, 'permission_check' ],
            ],
        ] );

        register_rest_route( self::REST_NAMESPACE, '/calendar/meta', [
            'methods'             => 'GET',
            'callback'            => [ __CLASS__, 'meta' ],
            'permission_callback' => [ __CLASS__, 'permission_check' ],
        ] );

        register_rest_route( self::REST_NAMESPACE, '/calendar/events/(?P<id>\d+)', [
            'methods'             => 'PATCH',
            'callback'            => [ __CLASS__, 'patch_event' ],
            'permission_callback' => [ __CLASS__, 'permission_check' ],
        ] );

        register_rest_route( self::REST_NAMESPACE, '/calendar/events/(?P<id>\d+)/cancel', [
            'methods'             => 'POST',
            'callback'            => [ __CLASS__, 'cancel_event' ],
            'permission_callback' => [ __CLASS__, 'permission_check' ],
        ] );
    }

    public static function permission_check() {
        return current_user_can( 'manage_options' );
    }

    /**
     * GET /calendar/events?from=ISO&to=ISO&staff_id=int
     */
    public static function list_events( WP_REST_Request $req ) {
        global $wpdb;

        $from     = sanitize_text_field( $req->get_param( 'from' ) ?? '' );
        $to       = sanitize_text_field( $req->get_param( 'to' ) ?? '' );
        $staff_id = (int) ( $req->get_param( 'staff_id' ) ?? 0 );

        // Default to a sane window if unset.
        $tz = wp_timezone();
        try {
            $from_dt = $from ? new DateTime( $from, $tz ) : ( new DateTime( 'now', $tz ) )->modify( '-30 days' );
            $to_dt   = $to   ? new DateTime( $to,   $tz ) : ( new DateTime( 'now', $tz ) )->modify( '+60 days' );
        } catch ( Exception $e ) {
            return new WP_Error( 'lvb_bad_range', 'Invalid from/to', [ 'status' => 400 ] );
        }

        $where  = [ "b.status != 'cancelled'", 'b.start_datetime < %s', 'b.end_datetime > %s' ];
        $params = [ $to_dt->format( 'Y-m-d H:i:s' ), $from_dt->format( 'Y-m-d H:i:s' ) ];

        if ( $staff_id > 0 ) {
            $where[]  = 'b.staff_id = %d';
            $params[] = $staff_id;
        }

        $where_sql = 'WHERE ' . implode( ' AND ', $where );

        $sql = "SELECT b.id, b.start_datetime, b.end_datetime, b.status, b.notes,
                       b.staff_id, b.price,
                       sv.name AS service_name, sv.buffer_time,
                       st.name AS staff_name, st.color_id,
                       c.first_name, c.last_name, c.email AS customer_email, c.phone AS customer_phone
                FROM {$wpdb->prefix}lvb_bookings b
                LEFT JOIN {$wpdb->prefix}lvb_services sv ON sv.id = b.service_id
                LEFT JOIN {$wpdb->prefix}lvb_staff st    ON st.id = b.staff_id
                LEFT JOIN {$wpdb->prefix}lvb_customers c ON c.id  = b.customer_id
                $where_sql
                ORDER BY b.start_datetime ASC";

        $rows = $wpdb->get_results( $wpdb->prepare( $sql, ...$params ), ARRAY_A );

        $palette = LVB_Google_Calendar::get_event_color_palette();

        $events = [];
        foreach ( $rows as $r ) {
            $cid   = (int) ( $r['color_id'] ?? 0 );
            $color = ( $cid >= 1 && $cid <= 11 ) ? $palette[ $cid ]['hex'] : '#d60000';

            $customer = trim( ( $r['first_name'] ?? '' ) . ' ' . ( $r['last_name'] ?? '' ) );

            // Convert stored WP-tz datetime to ISO8601 with offset so FullCalendar renders correctly.
            $start = ( new DateTime( $r['start_datetime'], $tz ) )->format( DateTime::ATOM );
            $end   = ( new DateTime( $r['end_datetime'],   $tz ) )->format( DateTime::ATOM );

            $events[] = [
                'id'              => (int) $r['id'],
                'title'           => trim( ( $r['service_name'] ?? '' ) . ' – ' . $customer ),
                'start'           => $start,
                'end'             => $end,
                'backgroundColor' => $color,
                'borderColor'     => $color,
                'extendedProps'   => [
                    'status'         => $r['status'],
                    'service'        => $r['service_name'],
                    'staff'          => $r['staff_name'],
                    'staff_id'       => (int) $r['staff_id'],
                    'customer_name'  => $customer,
                    'customer_email' => $r['customer_email'],
                    'customer_phone' => $r['customer_phone'],
                    'price'          => $r['price'],
                    'notes'          => $r['notes'],
                ],
            ];

            $buffer_minutes = (int) ( $r['buffer_time'] ?? 0 );
            if ( $buffer_minutes > 0 ) {
                $buf_start_dt = new DateTime( $r['end_datetime'], $tz );
                $buf_end_dt   = clone $buf_start_dt;
                $buf_end_dt->modify( '+' . $buffer_minutes . ' minutes' );

                $events[] = [
                    'id'              => 'buffer-' . (int) $r['id'],
                    'title'           => 'Puffer',
                    'start'           => $buf_start_dt->format( DateTime::ATOM ),
                    'end'             => $buf_end_dt->format( DateTime::ATOM ),
                    'backgroundColor' => '#9e9e9e',
                    'borderColor'     => '#9e9e9e',
                    'textColor'       => '#ffffff',
                    'editable'        => false,
                    'startEditable'   => false,
                    'durationEditable'=> false,
                    'classNames'      => [ 'lvb-buffer-event' ],
                    'extendedProps'   => [
                        'is_buffer'     => true,
                        'booking_id'    => (int) $r['id'],
                        'staff_id'      => (int) $r['staff_id'],
                        'service'       => $r['service_name'],
                    ],
                ];
            }
        }

        return rest_ensure_response( $events );
    }

    /**
     * PATCH /calendar/events/{id}  body: { start: ISO, end: ISO }
     */
    public static function patch_event( WP_REST_Request $req ) {
        global $wpdb;

        $id    = (int) $req['id'];
        $start = sanitize_text_field( $req->get_param( 'start' ) ?? '' );
        $end   = sanitize_text_field( $req->get_param( 'end' ) ?? '' );

        if ( ! $start || ! $end ) {
            return new WP_Error( 'lvb_missing_range', 'start and end required', [ 'status' => 400 ] );
        }

        $booking = LVB_Database::get_by_id( 'bookings', $id );
        if ( ! $booking ) {
            return new WP_Error( 'lvb_not_found', 'Booking not found', [ 'status' => 404 ] );
        }

        $tz = wp_timezone();
        try {
            $start_dt = new DateTime( $start, $tz );
            $end_dt   = new DateTime( $end,   $tz );
        } catch ( Exception $e ) {
            return new WP_Error( 'lvb_bad_range', 'Invalid datetimes', [ 'status' => 400 ] );
        }

        if ( $end_dt <= $start_dt ) {
            return new WP_Error( 'lvb_bad_range', 'End must be after start', [ 'status' => 400 ] );
        }

        // Conflict check against other confirmed bookings for the same staff.
        $conflict_sql = "SELECT COUNT(*) FROM {$wpdb->prefix}lvb_bookings
                         WHERE id != %d
                           AND status = 'confirmed'
                           AND staff_id <=> %s
                           AND start_datetime < %s
                           AND end_datetime > %s";
        $conflict = (int) $wpdb->get_var( $wpdb->prepare(
            $conflict_sql,
            $id,
            $booking['staff_id'],
            $end_dt->format( 'Y-m-d H:i:s' ),
            $start_dt->format( 'Y-m-d H:i:s' )
        ) );
        if ( $conflict > 0 ) {
            return new WP_Error( 'lvb_conflict', 'Zeitslot überlappt mit anderer Buchung', [ 'status' => 409 ] );
        }

        $wpdb->update(
            $wpdb->prefix . 'lvb_bookings',
            [
                'start_datetime' => $start_dt->format( 'Y-m-d H:i:s' ),
                'end_datetime'   => $end_dt->format( 'Y-m-d H:i:s' ),
            ],
            [ 'id' => $id ]
        );

        // Sync the Google Calendar event(s) if linked.
        $gcal_warning = null;
        if ( LVB_Google_Calendar::is_connected()
             && ( ! empty( $booking['google_event_id'] ) || ! empty( $booking['buffer_event_id'] ) ) ) {
            $calendar_id = self::resolve_calendar_id( $booking );
            if ( $calendar_id ) {
                $tz_string = wp_timezone_string();
                if ( ! empty( $booking['google_event_id'] ) ) {
                    $patch_body = [
                        'start' => [ 'dateTime' => $start_dt->format( DateTime::RFC3339 ), 'timeZone' => $tz_string ],
                        'end'   => [ 'dateTime' => $end_dt->format( DateTime::RFC3339 ),   'timeZone' => $tz_string ],
                    ];
                    $result = LVB_Google_Calendar::patch_event( $calendar_id, $booking['google_event_id'], $patch_body );
                    if ( is_wp_error( $result ) ) {
                        $gcal_warning = $result->get_error_message();
                    }
                }
                if ( ! empty( $booking['buffer_event_id'] ) ) {
                    $service = LVB_Database::get_by_id( 'services', (int) $booking['service_id'] );
                    $buffer_minutes = $service ? (int) $service['buffer_time'] : 0;
                    if ( $buffer_minutes > 0 ) {
                        $buf_end_dt = clone $end_dt;
                        $buf_end_dt->modify( '+' . $buffer_minutes . ' minutes' );
                        $buf_body = [
                            'start' => [ 'dateTime' => $end_dt->format( DateTime::RFC3339 ), 'timeZone' => $tz_string ],
                            'end'   => [ 'dateTime' => $buf_end_dt->format( DateTime::RFC3339 ), 'timeZone' => $tz_string ],
                        ];
                        $buf_result = LVB_Google_Calendar::patch_event( $calendar_id, $booking['buffer_event_id'], $buf_body );
                        if ( is_wp_error( $buf_result ) && ! $gcal_warning ) {
                            $gcal_warning = $buf_result->get_error_message();
                        }
                    }
                }
            }
        }

        return rest_ensure_response( [
            'ok'           => true,
            'id'           => $id,
            'gcal_warning' => $gcal_warning,
        ] );
    }

    /**
     * POST /calendar/events/{id}/cancel
     */
    public static function cancel_event( WP_REST_Request $req ) {
        $id = (int) $req['id'];
        $booking = LVB_Database::get_by_id( 'bookings', $id );
        if ( ! $booking ) {
            return new WP_Error( 'lvb_not_found', 'Booking not found', [ 'status' => 404 ] );
        }

        $result = LVB_Booking_Manager::cancel_booking( $id );
        LVB_Notifications::send_cancellation( $id );

        $gcal_warning = null;
        if ( is_array( $result ) && ( $result['gcal_status'] ?? '' ) === 'failed' ) {
            $gcal_warning = $result['gcal_error'] ?? '';
        }

        return rest_ensure_response( [
            'ok'           => true,
            'id'           => $id,
            'gcal_warning' => $gcal_warning,
        ] );
    }

    /**
     * GET /calendar/meta
     *
     * Payload for the create-booking modal: active services (with duration +
     * price), active staff, and the service→staff mapping so the staff list
     * can be narrowed after a service is picked.
     */
    public static function meta( WP_REST_Request $req ) {
        $services = LVB_Database::get_all( 'services', [ 'status' => 'active' ], 'sort_order ASC, name ASC' );
        $staff    = LVB_Database::get_all( 'staff',    [ 'status' => 'active' ], 'name ASC' );

        $service_staff = [];
        foreach ( $services as $svc ) {
            $ids = [];
            foreach ( LVB_Database::get_staff_for_service( (int) $svc['id'] ) as $s ) {
                $ids[] = (int) $s['id'];
            }
            $service_staff[ (int) $svc['id'] ] = $ids;
        }

        return rest_ensure_response( [
            'services'      => array_map( function( $s ) {
                return [
                    'id'       => (int) $s['id'],
                    'name'     => $s['name'],
                    'duration' => (int) $s['duration'],
                    'price'    => $s['price'],
                ];
            }, $services ),
            'staff'         => array_map( function( $s ) {
                return [ 'id' => (int) $s['id'], 'name' => $s['name'] ];
            }, $staff ),
            'service_staff' => $service_staff,
        ] );
    }

    /**
     * POST /calendar/events
     *
     * Create a booking on behalf of a customer from the admin calendar view.
     * Reuses the standard LVB_Booking_Manager::create_booking() pipeline so
     * GCal sync, conflict checks, and notifications all fire identically.
     */
    public static function create_event( WP_REST_Request $req ) {
        $required = [ 'service_id', 'start', 'first_name', 'last_name', 'email' ];
        foreach ( $required as $f ) {
            if ( ! $req->get_param( $f ) ) {
                return new WP_Error( 'lvb_missing', "Feld fehlt: $f", [ 'status' => 400 ] );
            }
        }

        $email = sanitize_email( $req->get_param( 'email' ) );
        if ( ! is_email( $email ) ) {
            return new WP_Error( 'lvb_bad_email', 'Ungültige E-Mail-Adresse', [ 'status' => 400 ] );
        }

        $data = [
            'service_id'     => (int) $req->get_param( 'service_id' ),
            'staff_id'       => (int) ( $req->get_param( 'staff_id' ) ?? 0 ),
            'start_datetime' => self::iso_to_wp_tz( sanitize_text_field( $req->get_param( 'start' ) ) ),
            'first_name'     => sanitize_text_field( $req->get_param( 'first_name' ) ),
            'last_name'      => sanitize_text_field( $req->get_param( 'last_name' ) ),
            'email'          => $email,
            'phone'          => sanitize_text_field( $req->get_param( 'phone' ) ?? '' ),
            'notes'          => sanitize_textarea_field( $req->get_param( 'notes' ) ?? '' ),
        ];

        if ( ! $data['start_datetime'] ) {
            return new WP_Error( 'lvb_bad_start', 'Ungültiger Startzeitpunkt', [ 'status' => 400 ] );
        }

        $result = LVB_Booking_Manager::create_booking( $data );
        if ( is_wp_error( $result ) ) {
            $status = 400;
            if ( in_array( $result->get_error_code(), [ 'time_conflict' ], true ) ) {
                $status = 409;
            }
            return new WP_Error( $result->get_error_code(), $result->get_error_message(), [ 'status' => $status ] );
        }

        return rest_ensure_response( [ 'ok' => true, 'id' => (int) $result ] );
    }

    /**
     * Convert an ISO-8601 datetime to the WP-timezone "Y-m-d H:i:s" string
     * that create_booking() expects.
     */
    private static function iso_to_wp_tz( $iso ) {
        try {
            $dt = new DateTime( $iso );
            $dt->setTimezone( wp_timezone() );
            return $dt->format( 'Y-m-d H:i:s' );
        } catch ( Exception $e ) {
            return '';
        }
    }

    /**
     * Resolve which Google Calendar holds an event for the given booking.
     *
     * Mirrors the staff-override-then-default fallback used by create_booking().
     */
    private static function resolve_calendar_id( $booking ) {
        if ( ! empty( $booking['staff_id'] ) ) {
            $staff = LVB_Database::get_by_id( 'staff', (int) $booking['staff_id'] );
            if ( $staff && ! empty( $staff['calendar_id'] ) ) {
                return $staff['calendar_id'];
            }
        }
        return get_option( 'lvb_google_calendar_id', '' );
    }
}
