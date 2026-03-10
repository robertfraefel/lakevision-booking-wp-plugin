<?php
/**
 * LVB_Booking_Manager – CRUD logic for bookings, customers, services, staff.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class LVB_Booking_Manager {

    // -----------------------------------------------------------------------
    // Create booking (full flow)
    // -----------------------------------------------------------------------

    /**
     * Process a new booking request from the frontend form.
     *
     * @param array $data  Validated & sanitised form data.
     * @return int|WP_Error  New booking ID or WP_Error.
     */
    public static function create_booking( $data ) {
        // 1. Upsert customer
        $customer_id = self::upsert_customer( [
            'first_name' => $data['first_name'],
            'last_name'  => $data['last_name'],
            'email'      => $data['email'],
            'phone'      => $data['phone']      ?? '',
            'notes'      => $data['customer_notes'] ?? '',
        ] );
        if ( ! $customer_id ) {
            return new WP_Error( 'customer_fail', __( 'Could not save customer details.', 'lakevision-booking' ) );
        }

        // 2. Load service for duration & price
        $service = LVB_Database::get_by_id( 'services', $data['service_id'] );
        if ( ! $service || $service['status'] !== 'active' ) {
            return new WP_Error( 'invalid_service', __( 'Invalid or inactive service.', 'lakevision-booking' ) );
        }

        // 3. Resolve staff & calendar_id
        $staff      = null;
        $calendar_id = get_option( 'lvb_google_calendar_id', '' );

        if ( ! empty( $data['staff_id'] ) ) {
            $staff = LVB_Database::get_by_id( 'staff', $data['staff_id'] );
        } else {
            // Auto-assign if exactly one active staff is linked to this service
            $available_staff = LVB_Database::get_staff_for_service( (int) $service['id'] );
            if ( count( $available_staff ) === 1 ) {
                $staff = $available_staff[0];
            }
        }
        if ( $staff && ! empty( $staff['calendar_id'] ) ) {
            $calendar_id = $staff['calendar_id'];
        }

        // 4. Parse start/end times (string is in WP timezone, specify it to avoid UTC misinterpretation)
        $start_dt = new DateTime( $data['start_datetime'], wp_timezone() );
        $end_dt   = clone $start_dt;
        $end_dt->modify( '+' . (int) $service['duration'] . ' minutes' );
        $buffer_end_dt = clone $end_dt;
        $buffer_end_dt->modify( '+' . (int) $service['buffer_time'] . ' minutes' );

        $start_str = $start_dt->format( 'Y-m-d H:i:s' );
        $end_str   = $end_dt->format( 'Y-m-d H:i:s' );

        // 5. Create booking event in Google Calendar
        $google_event_id = '';
        if ( ! empty( $calendar_id ) && LVB_Google_Calendar::is_connected() ) {
            $gc_result = LVB_Google_Calendar::create_booking_event( $calendar_id, [
                'service_name'  => $service['name'],
                'staff_name'    => $staff ? $staff['name'] : '',
                'customer_name' => trim( $data['first_name'] . ' ' . $data['last_name'] ),
                'start'         => $start_str,
                'end'           => $buffer_end_dt->format( 'Y-m-d H:i:s' ),
                'notes'         => $data['notes'] ?? '',
            ] );

            if ( ! is_wp_error( $gc_result ) ) {
                $google_event_id = $gc_result;
            }

        }

        // 6. Server-side conflict check: no overlapping confirmed bookings
        global $wpdb;
        $conflict = $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}lvb_bookings
             WHERE status = 'confirmed'
             AND start_datetime < %s
             AND end_datetime > %s",
            $end_str,
            $start_str
        ) );
        if ( $conflict > 0 ) {
            return new WP_Error( 'time_conflict', __( 'Dieser Zeitslot ist bereits gebucht. Bitte wähle eine andere Zeit.', 'lakevision-booking' ) );
        }

        // 6b. Ensure booking end doesn't exceed the availability window
        if ( ! empty( $data['slot_win_end'] ) ) {
            $win_end_dt = new DateTime( $data['slot_win_end'] );
            if ( $end_dt > $win_end_dt ) {
                return new WP_Error( 'outside_window', __( 'Die gewählte Zeit überschreitet das verfügbare Zeitfenster.', 'lakevision-booking' ) );
            }
        }

        // 7. Insert into DB
        $booking_id = LVB_Database::insert( 'bookings', [
            'service_id'      => (int) $service['id'],
            'staff_id'        => $staff ? (int) $staff['id'] : null,
            'customer_id'     => $customer_id,
            'start_datetime'  => $start_str,
            'end_datetime'    => $end_str,
            'status'          => 'confirmed',
            'google_event_id' => $google_event_id,
            'price'           => $service['price'],
            'notes'           => sanitize_textarea_field( $data['notes'] ?? '' ),
            'created_at'      => current_time( 'mysql' ),
        ] );

        if ( ! $booking_id ) {
            return new WP_Error( 'db_insert_fail', __( 'Could not save booking to database.', 'lakevision-booking' ) );
        }

        // 8. Send notifications
        LVB_Notifications::send_booking_confirmation( $booking_id );

        return $booking_id;
    }

    // -----------------------------------------------------------------------
    // Customer helpers
    // -----------------------------------------------------------------------

    /**
     * Insert or update a customer record, returning the customer ID.
     */
    public static function upsert_customer( $data ) {
        global $wpdb;
        $existing = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}lvb_customers WHERE email = %s",
                $data['email']
            ),
            ARRAY_A
        );

        if ( $existing ) {
            LVB_Database::update( 'customers', $data, [ 'id' => $existing['id'] ] );
            return (int) $existing['id'];
        }

        $data['created_at'] = current_time( 'mysql' );
        return LVB_Database::insert( 'customers', $data );
    }

    // -----------------------------------------------------------------------
    // Booking status updates
    // -----------------------------------------------------------------------

    public static function cancel_booking( $booking_id ) {
        $booking = LVB_Database::get_by_id( 'bookings', $booking_id );
        if ( ! $booking ) {
            return false;
        }

        // Remove Google Calendar event
        if ( ! empty( $booking['google_event_id'] ) ) {
            $staff       = $booking['staff_id'] ? LVB_Database::get_by_id( 'staff', $booking['staff_id'] ) : null;
            $calendar_id = $staff && ! empty( $staff['calendar_id'] )
                ? $staff['calendar_id']
                : get_option( 'lvb_google_default_calendar_id', '' );

            if ( $calendar_id ) {
                LVB_Google_Calendar::delete_event( $calendar_id, $booking['google_event_id'] );
            }
        }

        return LVB_Database::update( 'bookings', [ 'status' => 'cancelled' ], [ 'id' => $booking_id ] );
    }

    // -----------------------------------------------------------------------
    // Service CRUD
    // -----------------------------------------------------------------------

    public static function save_service( $data, $id = 0 ) {
        $clean = [
            'name'        => sanitize_text_field( $data['name'] ),
            'description' => sanitize_textarea_field( $data['description'] ?? '' ),
            'duration'    => max( 1, (int) ( $data['duration'] ?? 60 ) ),
            'price'       => round( (float) ( $data['price'] ?? 0 ), 2 ),
            'buffer_time' => max( 0, (int) ( $data['buffer_time'] ?? 0 ) ),
            'status'      => in_array( $data['status'] ?? 'active', [ 'active', 'inactive' ], true ) ? $data['status'] : 'active',
        ];

        if ( $id ) {
            LVB_Database::update( 'services', $clean, [ 'id' => $id ] );
            return $id;
        }
        // Assign next sort_order for new services
        global $wpdb;
        $max = (int) $wpdb->get_var( "SELECT MAX(sort_order) FROM {$wpdb->prefix}lvb_services" );
        $clean['sort_order'] = $max + 1;
        return LVB_Database::insert( 'services', $clean );
    }

    public static function move_service( $id, $direction ) {
        global $wpdb;
        $tbl     = $wpdb->prefix . 'lvb_services';
        $current = $wpdb->get_row( $wpdb->prepare( "SELECT id, sort_order FROM $tbl WHERE id = %d", $id ), ARRAY_A );
        if ( ! $current ) return;

        if ( $direction === 'up' ) {
            $neighbor = $wpdb->get_row( $wpdb->prepare(
                "SELECT id, sort_order FROM $tbl WHERE sort_order < %d ORDER BY sort_order DESC LIMIT 1",
                $current['sort_order']
            ), ARRAY_A );
        } else {
            $neighbor = $wpdb->get_row( $wpdb->prepare(
                "SELECT id, sort_order FROM $tbl WHERE sort_order > %d ORDER BY sort_order ASC LIMIT 1",
                $current['sort_order']
            ), ARRAY_A );
        }

        if ( ! $neighbor ) return;

        $wpdb->update( $tbl, [ 'sort_order' => $neighbor['sort_order'] ], [ 'id' => $current['id'] ] );
        $wpdb->update( $tbl, [ 'sort_order' => $current['sort_order'] ], [ 'id' => $neighbor['id'] ] );
    }

    public static function delete_service( $id ) {
        LVB_Database::delete( 'staff_services', [ 'service_id' => $id ] );
        return LVB_Database::delete( 'services', [ 'id' => $id ] );
    }

    // -----------------------------------------------------------------------
    // Staff CRUD
    // -----------------------------------------------------------------------

    public static function save_staff( $data, $id = 0 ) {
        $clean = [
            'name'        => sanitize_text_field( $data['name'] ),
            'email'       => sanitize_email( $data['email'] ?? '' ),
            'phone'       => sanitize_text_field( $data['phone'] ?? '' ),
            'calendar_id' => sanitize_text_field( $data['calendar_id'] ?? '' ),
            'status'      => in_array( $data['status'] ?? 'active', [ 'active', 'inactive' ], true ) ? $data['status'] : 'active',
        ];

        if ( $id ) {
            LVB_Database::update( 'staff', $clean, [ 'id' => $id ] );
        } else {
            $id = LVB_Database::insert( 'staff', $clean );
        }

        // Save staff <-> services pivot
        if ( $id ) {
            self::update_staff_services( $id, array_map( 'intval', $data['service_ids'] ?? [] ) );
        }

        return $id;
    }

    private static function update_staff_services( $staff_id, $service_ids ) {
        global $wpdb;
        $wpdb->delete( $wpdb->prefix . 'lvb_staff_services', [ 'staff_id' => $staff_id ] );
        foreach ( $service_ids as $sid ) {
            if ( $sid > 0 ) {
                $wpdb->replace( $wpdb->prefix . 'lvb_staff_services', [
                    'staff_id'   => $staff_id,
                    'service_id' => $sid,
                ] );
            }
        }
    }

    public static function delete_staff( $id ) {
        LVB_Database::delete( 'staff_services', [ 'staff_id' => $id ] );
        return LVB_Database::delete( 'staff', [ 'id' => $id ] );
    }
}
