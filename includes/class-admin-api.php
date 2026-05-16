<?php
/**
 * LVB_Admin_API – REST endpoints powering the React admin frontend.
 *
 * Sibling to LVB_Calendar_API (which stays as-is — drag/resize endpoints
 * for the legacy FullCalendar admin screen still live there). This class
 * adds the CRUD surface for Bookings, Customers, Services, Staff, Settings,
 * Intake Forms, and Permissions.
 *
 * All endpoints sit under /wp-json/lvb/v1/admin/* and require a specific
 * `lvb_*` capability (see LVB_Capabilities). Auth uses standard WordPress
 * cookie + nonce — the React app picks up the nonce from the global emitted
 * by the [lvb_admin] shortcode.
 *
 * Business logic is delegated to the existing managers (LVB_Booking_Manager,
 * LVB_Database, LVB_Notifications). No logic is duplicated here.
 *
 * @package LakeVision_Booking
 * @since 2.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class LVB_Admin_API {

    const NAMESPACE_BASE = 'lvb/v1';

    /**
     * Register all routes. Called once from the plugin bootstrap on the
     * `rest_api_init` action.
     */
    public static function register() {
        add_action( 'rest_api_init', [ __CLASS__, 'register_routes' ] );
    }

    public static function register_routes() {
        // -- Identity / Capability discovery -----------------------------------
        register_rest_route( self::NAMESPACE_BASE, '/admin/me', [
            'methods'             => 'GET',
            'callback'            => [ __CLASS__, 'me' ],
            'permission_callback' => [ __CLASS__, 'is_logged_in' ],
        ] );

        // -- Bookings ----------------------------------------------------------
        register_rest_route( self::NAMESPACE_BASE, '/admin/bookings', [
            [
                'methods'             => 'GET',
                'callback'            => [ __CLASS__, 'list_bookings' ],
                'permission_callback' => self::cap( 'lvb_view_calendar' ),
            ],
            [
                'methods'             => 'POST',
                'callback'            => [ __CLASS__, 'create_booking' ],
                'permission_callback' => self::cap( 'lvb_edit_all_bookings' ),
            ],
        ] );

        register_rest_route( self::NAMESPACE_BASE, '/admin/bookings/(?P<id>\d+)', [
            [
                'methods'             => 'GET',
                'callback'            => [ __CLASS__, 'get_booking' ],
                'permission_callback' => self::cap( 'lvb_view_calendar' ),
            ],
            [
                'methods'             => 'PATCH',
                'callback'            => [ __CLASS__, 'update_booking' ],
                'permission_callback' => self::cap( 'lvb_edit_all_bookings' ),
            ],
            [
                'methods'             => 'DELETE',
                'callback'            => [ __CLASS__, 'delete_booking' ],
                'permission_callback' => self::cap( 'lvb_edit_all_bookings' ),
            ],
        ] );

        // -- Customers ---------------------------------------------------------
        register_rest_route( self::NAMESPACE_BASE, '/admin/customers', [
            [
                'methods'             => 'GET',
                'callback'            => [ __CLASS__, 'list_customers' ],
                'permission_callback' => self::cap( 'lvb_manage_customers' ),
            ],
            [
                'methods'             => 'POST',
                'callback'            => [ __CLASS__, 'create_customer' ],
                'permission_callback' => self::cap( 'lvb_manage_customers' ),
            ],
        ] );

        register_rest_route( self::NAMESPACE_BASE, '/admin/customers/(?P<id>\d+)', [
            [
                'methods'             => 'PATCH',
                'callback'            => [ __CLASS__, 'update_customer' ],
                'permission_callback' => self::cap( 'lvb_manage_customers' ),
            ],
            [
                'methods'             => 'DELETE',
                'callback'            => [ __CLASS__, 'delete_customer' ],
                'permission_callback' => self::cap( 'lvb_manage_customers' ),
            ],
        ] );

        // Customer search (for booking-edit's customer-picker — needs only
        // booking-edit permission, not full customer management).
        register_rest_route( self::NAMESPACE_BASE, '/admin/customers/search', [
            'methods'             => 'GET',
            'callback'            => [ __CLASS__, 'search_customers' ],
            'permission_callback' => self::cap( 'lvb_edit_all_bookings' ),
        ] );

        // -- Services ----------------------------------------------------------
        register_rest_route( self::NAMESPACE_BASE, '/admin/services', [
            [
                'methods'             => 'GET',
                'callback'            => [ __CLASS__, 'list_services' ],
                'permission_callback' => [ __CLASS__, 'can_read_services' ],
            ],
            [
                'methods'             => 'POST',
                'callback'            => [ __CLASS__, 'create_service' ],
                'permission_callback' => self::cap( 'lvb_manage_services' ),
            ],
        ] );
        register_rest_route( self::NAMESPACE_BASE, '/admin/services/(?P<id>\d+)', [
            [
                'methods'             => 'PATCH',
                'callback'            => [ __CLASS__, 'update_service' ],
                'permission_callback' => self::cap( 'lvb_manage_services' ),
            ],
            [
                'methods'             => 'DELETE',
                'callback'            => [ __CLASS__, 'delete_service' ],
                'permission_callback' => self::cap( 'lvb_manage_services' ),
            ],
        ] );
        register_rest_route( self::NAMESPACE_BASE, '/admin/services/reorder', [
            'methods'             => 'POST',
            'callback'            => [ __CLASS__, 'reorder_services' ],
            'permission_callback' => self::cap( 'lvb_manage_services' ),
        ] );

        // -- Staff -------------------------------------------------------------
        register_rest_route( self::NAMESPACE_BASE, '/admin/staff', [
            [
                'methods'             => 'GET',
                'callback'            => [ __CLASS__, 'list_staff' ],
                'permission_callback' => [ __CLASS__, 'can_read_staff' ],
            ],
            [
                'methods'             => 'POST',
                'callback'            => [ __CLASS__, 'create_staff' ],
                'permission_callback' => self::cap( 'lvb_manage_staff' ),
            ],
        ] );
        register_rest_route( self::NAMESPACE_BASE, '/admin/staff/(?P<id>\d+)', [
            [
                'methods'             => 'GET',
                'callback'            => [ __CLASS__, 'get_staff' ],
                'permission_callback' => self::cap( 'lvb_manage_staff' ),
            ],
            [
                'methods'             => 'PATCH',
                'callback'            => [ __CLASS__, 'update_staff' ],
                'permission_callback' => self::cap( 'lvb_manage_staff' ),
            ],
            [
                'methods'             => 'DELETE',
                'callback'            => [ __CLASS__, 'delete_staff' ],
                'permission_callback' => self::cap( 'lvb_manage_staff' ),
            ],
        ] );

        // Combined meta for the new-booking modal (services + staff + mapping)
        register_rest_route( self::NAMESPACE_BASE, '/admin/meta', [
            'methods'             => 'GET',
            'callback'            => [ __CLASS__, 'meta' ],
            'permission_callback' => self::cap( 'lvb_view_calendar' ),
        ] );

        // -- Booking cancel (soft) ---------------------------------------------
        register_rest_route( self::NAMESPACE_BASE, '/admin/bookings/(?P<id>\d+)/cancel', [
            'methods'             => 'POST',
            'callback'            => [ __CLASS__, 'cancel_booking' ],
            'permission_callback' => self::cap( 'lvb_edit_all_bookings' ),
        ] );

        // -- Permissions (user → caps mapping) ---------------------------------
        register_rest_route( self::NAMESPACE_BASE, '/admin/permissions/users', [
            'methods'             => 'GET',
            'callback'            => [ __CLASS__, 'list_users_with_caps' ],
            'permission_callback' => self::cap( 'lvb_manage_permissions' ),
        ] );

        register_rest_route( self::NAMESPACE_BASE, '/admin/permissions/users/(?P<id>\d+)', [
            'methods'             => 'PUT',
            'callback'            => [ __CLASS__, 'set_user_caps' ],
            'permission_callback' => self::cap( 'lvb_manage_permissions' ),
        ] );
    }

    // ------------------------------------------------------------------------
    // Permission callback helpers
    // ------------------------------------------------------------------------

    /**
     * Build a permission callback that checks a specific capability. Used
     * for every route registration to keep them declarative.
     */
    private static function cap( $cap ) {
        return function () use ( $cap ) {
            return is_user_logged_in() && current_user_can( $cap );
        };
    }

    public static function is_logged_in() {
        return is_user_logged_in();
    }

    /**
     * Services + Staff lists are needed by every booking-related screen,
     * not just the dedicated CRUD modules. Anyone who can view the calendar
     * or manage bookings/services/staff should be able to read them.
     */
    public static function can_read_services() {
        return is_user_logged_in() && (
            current_user_can( 'lvb_manage_services' ) ||
            current_user_can( 'lvb_view_calendar' )   ||
            current_user_can( 'lvb_edit_all_bookings' )
        );
    }

    public static function can_read_staff() {
        return is_user_logged_in() && (
            current_user_can( 'lvb_manage_staff' )    ||
            current_user_can( 'lvb_view_calendar' )   ||
            current_user_can( 'lvb_edit_all_bookings' )
        );
    }

    // ------------------------------------------------------------------------
    // Identity
    // ------------------------------------------------------------------------

    /**
     * Tell the React app who the current user is and which lvb_* caps they
     * have. The frontend uses this to drive its routing and hide modules
     * the user can't access.
     */
    public static function me( WP_REST_Request $req ) {
        $user = wp_get_current_user();
        $caps = [];
        foreach ( array_keys( LVB_Capabilities::CAPS ) as $cap ) {
            if ( user_can( $user, $cap ) ) {
                $caps[] = $cap;
            }
        }
        return rest_ensure_response( [
            'id'           => $user->ID,
            'display_name' => $user->display_name,
            'email'        => $user->user_email,
            'capabilities' => $caps,
            'staff_id'     => self::resolve_linked_staff_id( $user ),
        ] );
    }

    /**
     * Optional: a WP user can be linked to an LVB staff row via the user meta
     * `lvb_linked_staff_id`. The React app uses this to scope "own bookings"
     * for staff members who don't have `lvb_edit_all_bookings`.
     */
    private static function resolve_linked_staff_id( $user ) {
        $id = (int) get_user_meta( $user->ID, 'lvb_linked_staff_id', true );
        return $id > 0 ? $id : null;
    }

    // ------------------------------------------------------------------------
    // Bookings
    // ------------------------------------------------------------------------

    public static function list_bookings( WP_REST_Request $req ) {
        $args = [
            'status'   => sanitize_text_field( $req->get_param( 'status' ) ?? '' ),
            'search'   => sanitize_text_field( $req->get_param( 'search' ) ?? '' ),
            'per_page' => max( 1, min( 200, (int) ( $req->get_param( 'per_page' ) ?? 50 ) ) ),
            'page'     => max( 1, (int) ( $req->get_param( 'page' ) ?? 1 ) ),
            'order_by' => sanitize_text_field( $req->get_param( 'order_by' ) ?? 'start_datetime' ),
            'order'    => strtoupper( sanitize_text_field( $req->get_param( 'order' ) ?? 'DESC' ) ) === 'ASC' ? 'ASC' : 'DESC',
        ];
        if ( method_exists( 'LVB_Database', 'get_bookings' ) ) {
            $rows  = LVB_Database::get_bookings( $args );
            $total = method_exists( 'LVB_Database', 'count_bookings' )
                ? LVB_Database::count_bookings( [ 'status' => $args['status'], 'search' => $args['search'] ] )
                : count( $rows );
        } else {
            $rows  = LVB_Database::get_all( 'bookings', [], 'start_datetime DESC' );
            $total = count( $rows );
        }
        return rest_ensure_response( [
            'items' => $rows,
            'total' => (int) $total,
            'page'  => $args['page'],
            'per_page' => $args['per_page'],
        ] );
    }

    public static function get_booking( WP_REST_Request $req ) {
        $id      = (int) $req['id'];
        $booking = LVB_Database::get_by_id( 'bookings', $id );
        if ( ! $booking ) {
            return new WP_Error( 'lvb_not_found', 'Booking not found', [ 'status' => 404 ] );
        }
        return rest_ensure_response( $booking );
    }

    public static function create_booking( WP_REST_Request $req ) {
        $data   = $req->get_json_params();
        $result = LVB_Booking_Manager::create_booking_admin( $data ?: [] );
        if ( is_wp_error( $result ) ) {
            return $result;
        }
        return rest_ensure_response( [ 'ok' => true, 'id' => (int) ( $result['id'] ?? $result ) ] );
    }

    public static function update_booking( WP_REST_Request $req ) {
        $id     = (int) $req['id'];
        $data   = $req->get_json_params() ?: [];
        $result = LVB_Booking_Manager::update_booking( $id, $data );
        if ( is_wp_error( $result ) ) {
            return $result;
        }
        return rest_ensure_response( [ 'ok' => true, 'id' => (int) ( $result['id'] ?? $id ) ] );
    }

    public static function delete_booking( WP_REST_Request $req ) {
        $id = (int) $req['id'];
        if ( ! method_exists( 'LVB_Booking_Manager', 'delete_booking' ) ) {
            return new WP_Error( 'lvb_not_supported', 'delete_booking unavailable', [ 'status' => 501 ] );
        }
        $result = LVB_Booking_Manager::delete_booking( $id );
        if ( is_wp_error( $result ) ) {
            return $result;
        }
        return rest_ensure_response( [ 'ok' => true, 'id' => $id ] );
    }

    public static function cancel_booking( WP_REST_Request $req ) {
        $id     = (int) $req['id'];
        $result = LVB_Booking_Manager::cancel_booking( $id );
        if ( is_wp_error( $result ) ) {
            return $result;
        }
        LVB_Notifications::send_cancellation( $id );
        return rest_ensure_response( [ 'ok' => true, 'id' => $id ] );
    }

    // ------------------------------------------------------------------------
    // Customers
    // ------------------------------------------------------------------------

    public static function list_customers( WP_REST_Request $req ) {
        $rows = LVB_Database::get_all( 'customers', [], 'last_name ASC, first_name ASC' );
        return rest_ensure_response( [ 'items' => $rows, 'total' => count( $rows ) ] );
    }

    /**
     * Lightweight search used by the booking-edit customer-picker.
     * Matches against first_name, last_name, email, phone (LIKE).
     * Limited to 20 results to keep the typeahead snappy.
     */
    public static function search_customers( WP_REST_Request $req ) {
        global $wpdb;
        $q = trim( sanitize_text_field( $req->get_param( 'q' ) ?? '' ) );
        if ( $q === '' ) {
            return rest_ensure_response( [ 'items' => [] ] );
        }
        $like = '%' . $wpdb->esc_like( $q ) . '%';
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, first_name, last_name, email, phone
             FROM {$wpdb->prefix}lvb_customers
             WHERE first_name LIKE %s
                OR last_name  LIKE %s
                OR email      LIKE %s
                OR phone      LIKE %s
             ORDER BY last_name, first_name
             LIMIT 20",
            $like, $like, $like, $like
        ), ARRAY_A );
        return rest_ensure_response( [ 'items' => $rows ?: [] ] );
    }

    public static function create_customer( WP_REST_Request $req ) {
        $data = $req->get_json_params() ?: [];
        if ( ! method_exists( 'LVB_Booking_Manager', 'save_customer' ) ) {
            return new WP_Error( 'lvb_not_supported', 'save_customer unavailable', [ 'status' => 501 ] );
        }
        $id = LVB_Booking_Manager::save_customer( $data, 0 );
        if ( is_wp_error( $id ) ) {
            return $id;
        }
        return rest_ensure_response( [ 'ok' => true, 'id' => (int) $id ] );
    }

    public static function update_customer( WP_REST_Request $req ) {
        $id   = (int) $req['id'];
        $data = $req->get_json_params() ?: [];
        if ( ! method_exists( 'LVB_Booking_Manager', 'save_customer' ) ) {
            return new WP_Error( 'lvb_not_supported', 'save_customer unavailable', [ 'status' => 501 ] );
        }
        $result = LVB_Booking_Manager::save_customer( $data, $id );
        if ( is_wp_error( $result ) ) {
            return $result;
        }
        return rest_ensure_response( [ 'ok' => true, 'id' => $id ] );
    }

    public static function delete_customer( WP_REST_Request $req ) {
        global $wpdb;
        $id    = (int) $req['id'];
        $count = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}lvb_bookings WHERE customer_id = %d", $id
        ) );
        if ( $count > 0 ) {
            return new WP_Error( 'lvb_has_bookings', 'Kunde hat noch Buchungen', [ 'status' => 409 ] );
        }
        LVB_Database::delete( 'customers', [ 'id' => $id ] );
        return rest_ensure_response( [ 'ok' => true, 'id' => $id ] );
    }

    // ------------------------------------------------------------------------
    // Services + Staff (read-only stubs for now — CRUD comes in Phase 3)
    // ------------------------------------------------------------------------

    public static function list_services( WP_REST_Request $req ) {
        $rows = LVB_Database::get_all( 'services', [], 'sort_order ASC, name ASC' );
        return rest_ensure_response( [ 'items' => $rows, 'total' => count( $rows ) ] );
    }

    public static function create_service( WP_REST_Request $req ) {
        $id = LVB_Booking_Manager::save_service( $req->get_json_params() ?: [], 0 );
        if ( is_wp_error( $id ) ) return $id;
        return rest_ensure_response( [ 'ok' => true, 'id' => (int) $id ] );
    }

    public static function update_service( WP_REST_Request $req ) {
        $id     = (int) $req['id'];
        $result = LVB_Booking_Manager::save_service( $req->get_json_params() ?: [], $id );
        if ( is_wp_error( $result ) ) return $result;
        return rest_ensure_response( [ 'ok' => true, 'id' => $id ] );
    }

    public static function delete_service( WP_REST_Request $req ) {
        $id = (int) $req['id'];
        $r  = LVB_Booking_Manager::delete_service( $id );
        if ( is_wp_error( $r ) ) return $r;
        return rest_ensure_response( [ 'ok' => true, 'id' => $id ] );
    }

    /**
     * Bulk sort_order update for drag-reorder. Body: { ids: [3, 1, 2, …] }.
     * Position in the array becomes the new sort_order (1-based).
     */
    public static function reorder_services( WP_REST_Request $req ) {
        global $wpdb;
        $body = $req->get_json_params() ?: [];
        $ids  = is_array( $body['ids'] ?? null ) ? array_map( 'intval', $body['ids'] ) : [];
        if ( empty( $ids ) ) {
            return new WP_Error( 'lvb_bad_request', 'ids required', [ 'status' => 400 ] );
        }
        foreach ( $ids as $position => $service_id ) {
            $wpdb->update(
                $wpdb->prefix . 'lvb_services',
                [ 'sort_order' => $position + 1 ],
                [ 'id' => $service_id ],
                [ '%d' ],
                [ '%d' ]
            );
        }
        return rest_ensure_response( [ 'ok' => true ] );
    }

    public static function list_staff( WP_REST_Request $req ) {
        $rows = LVB_Database::get_all( 'staff', [], 'name ASC' );
        return rest_ensure_response( [ 'items' => $rows, 'total' => count( $rows ) ] );
    }

    public static function get_staff( WP_REST_Request $req ) {
        $id    = (int) $req['id'];
        $staff = LVB_Database::get_by_id( 'staff', $id );
        if ( ! $staff ) {
            return new WP_Error( 'lvb_not_found', 'Staff not found', [ 'status' => 404 ] );
        }
        // Augment with the linked service ids so the React form can hydrate
        // its multi-select without a second request.
        $service_ids = [];
        foreach ( LVB_Database::get_services_for_staff( $id ) as $svc ) {
            $service_ids[] = (int) $svc['id'];
        }
        $staff['service_ids'] = $service_ids;
        return rest_ensure_response( $staff );
    }

    public static function create_staff( WP_REST_Request $req ) {
        $id = LVB_Booking_Manager::save_staff( $req->get_json_params() ?: [], 0 );
        if ( is_wp_error( $id ) ) return $id;
        return rest_ensure_response( [ 'ok' => true, 'id' => (int) $id ] );
    }

    public static function update_staff( WP_REST_Request $req ) {
        $id     = (int) $req['id'];
        $result = LVB_Booking_Manager::save_staff( $req->get_json_params() ?: [], $id );
        if ( is_wp_error( $result ) ) return $result;
        return rest_ensure_response( [ 'ok' => true, 'id' => $id ] );
    }

    public static function delete_staff( WP_REST_Request $req ) {
        $id = (int) $req['id'];
        $r  = LVB_Booking_Manager::delete_staff( $id );
        if ( is_wp_error( $r ) ) return $r;
        return rest_ensure_response( [ 'ok' => true, 'id' => $id ] );
    }

    /**
     * One-shot fetch for screens that need the full booking-context: services
     * + staff + which staff are assigned to which service. Mirrors the
     * /calendar/meta endpoint in LVB_Calendar_API but lives under the new
     * admin namespace so the React app only needs one base URL.
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
            'services'      => $services,
            'staff'         => $staff,
            'service_staff' => $service_staff,
            'staff_label'   => get_option( 'lvb_staff_label',   'Begleiterin' ),
            'service_label' => get_option( 'lvb_service_label', 'Sitzung' ),
        ] );
    }

    // ------------------------------------------------------------------------
    // Permissions
    // ------------------------------------------------------------------------

    public static function list_users_with_caps( WP_REST_Request $req ) {
        $users  = get_users( [ 'fields' => [ 'ID', 'display_name', 'user_email' ] ] );
        $result = [];
        foreach ( $users as $u ) {
            $overrides = LVB_Capabilities::user_overrides( $u->ID );
            $effective = [];
            foreach ( array_keys( LVB_Capabilities::CAPS ) as $cap ) {
                if ( user_can( $u->ID, $cap ) ) {
                    $effective[] = $cap;
                }
            }
            $result[] = [
                'id'           => (int) $u->ID,
                'display_name' => $u->display_name,
                'email'        => $u->user_email,
                'overrides'    => $overrides,
                'effective'    => $effective,
            ];
        }
        return rest_ensure_response( [ 'items' => $result, 'caps' => LVB_Capabilities::CAPS ] );
    }

    public static function set_user_caps( WP_REST_Request $req ) {
        $id   = (int) $req['id'];
        $body = $req->get_json_params() ?: [];
        $caps = is_array( $body['capabilities'] ?? null ) ? $body['capabilities'] : [];
        LVB_Capabilities::set_user_overrides( $id, $caps );
        return rest_ensure_response( [ 'ok' => true, 'id' => $id, 'overrides' => LVB_Capabilities::user_overrides( $id ) ] );
    }
}
