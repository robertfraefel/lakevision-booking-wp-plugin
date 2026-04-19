<?php
/**
 * LVB_Booking_Manager – CRUD logic for bookings, customers, services, staff.
 *
 * This class is the central business-logic layer of the plugin. It orchestrates
 * the complete booking workflow (customer upsert → conflict check → DB insert →
 * Google Calendar event → email notification) and provides CRUD helpers for the
 * services and staff entities managed in the admin area.
 *
 * All public methods are static; the class is never instantiated.
 *
 * @package LakeVision_Booking
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Handles booking creation, cancellation, and management of services and staff.
 *
 * @package LakeVision_Booking
 */
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

        // 5. Create booking event in Google Calendar (main event ends at $end_str;
        //    buffer, if any, becomes a separate event created after DB insert).
        $google_event_id = '';
        if ( ! empty( $calendar_id ) && LVB_Google_Calendar::is_connected() ) {
            $gc_result = LVB_Google_Calendar::create_booking_event( $calendar_id, [
                'service_name'  => $service['name'],
                'staff_name'    => $staff ? $staff['name'] : '',
                'customer_name' => trim( $data['first_name'] . ' ' . $data['last_name'] ),
                'start'         => $start_str,
                'end'           => $end_str,
                'notes'         => $data['notes'] ?? '',
                'color_id'      => ( $staff && ! empty( $staff['color_id'] ) ) ? (int) $staff['color_id'] : null,
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
            $win_end_dt = new DateTime( $data['slot_win_end'], wp_timezone() );
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

        // 7b. Create buffer event in Google Calendar (separate entry after the booking)
        if ( (int) $service['buffer_time'] > 0 && ! empty( $calendar_id ) && LVB_Google_Calendar::is_connected() ) {
            $buffer_result = LVB_Google_Calendar::create_buffer_event( $calendar_id, [
                'service_name'  => $service['name'],
                'customer_name' => trim( $data['first_name'] . ' ' . $data['last_name'] ),
                'start'         => $end_str,
                'end'           => $buffer_end_dt->format( 'Y-m-d H:i:s' ),
            ] );
            if ( ! is_wp_error( $buffer_result ) && ! empty( $buffer_result ) ) {
                LVB_Database::update( 'bookings', [ 'buffer_event_id' => $buffer_result ], [ 'id' => $booking_id ] );
            }
        }

        // 8. Send notifications + schedule reminder
        LVB_Notifications::send_booking_confirmation( $booking_id );
        LVB_Notifications::schedule_reminder( $booking_id, $start_dt );

        return $booking_id;
    }

    // -----------------------------------------------------------------------
    // Customer helpers
    // -----------------------------------------------------------------------

    /**
     * Insert or update a customer record, returning the customer ID.
     *
     * Looks up the customer by email address. If a matching record exists it is
     * updated with the supplied data and the existing ID is returned. If no
     * record is found a new customer row is inserted.
     *
     * @param array $data {
     *     Customer fields to save.
     *
     *     @type string $first_name Customer's first name.
     *     @type string $last_name  Customer's last name.
     *     @type string $email      Customer's email address (used as the unique key).
     *     @type string $phone      Optional phone number.
     *     @type string $notes      Optional freeform notes.
     * }
     * @return int|false  The customer ID on success, or false on insert failure.
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

    /**
     * Cancel a booking by ID.
     *
     * Sets the booking status to 'cancelled' in the database and removes the
     * associated Google Calendar event (if one exists). The staff member's own
     * calendar is preferred; the plugin default calendar is used as a fallback.
     *
     * @param int $booking_id  The ID of the booking to cancel.
     * @return array|false     False when the booking wasn't found. Otherwise:
     *                         { rows:int, gcal_status:'deleted'|'failed'|'skipped', gcal_error:string }.
     */
    public static function cancel_booking( $booking_id ) {
        $booking = LVB_Database::get_by_id( 'bookings', $booking_id );
        if ( ! $booking ) {
            return false;
        }

        // Remove Google Calendar event.
        // gcal_status: 'deleted' on success, 'failed' on API error, 'skipped' when
        //              there was nothing to delete (no event id or no calendar id).
        $gcal_status = 'skipped';
        $gcal_error  = '';

        $buffer_cleared = false;
        if ( ! empty( $booking['google_event_id'] ) || ! empty( $booking['buffer_event_id'] ) ) {
            $staff       = $booking['staff_id'] ? LVB_Database::get_by_id( 'staff', $booking['staff_id'] ) : null;
            $calendar_id = $staff && ! empty( $staff['calendar_id'] )
                ? $staff['calendar_id']
                : get_option( 'lvb_google_default_calendar_id', '' );

            if ( $calendar_id && LVB_Google_Calendar::is_connected() ) {
                if ( ! empty( $booking['google_event_id'] ) ) {
                    $result = LVB_Google_Calendar::delete_event( $calendar_id, $booking['google_event_id'] );
                    if ( is_wp_error( $result ) ) {
                        $gcal_status = 'failed';
                        $gcal_error  = $result->get_error_message();
                    } else {
                        $gcal_status = 'deleted';
                    }
                }
                if ( ! empty( $booking['buffer_event_id'] ) ) {
                    $buf_result = LVB_Google_Calendar::delete_event( $calendar_id, $booking['buffer_event_id'] );
                    if ( ! is_wp_error( $buf_result ) ) {
                        $buffer_cleared = true;
                    }
                }
            }
        }

        // Remove scheduled reminder if not yet sent
        $timestamp = wp_next_scheduled( 'lvb_send_reminder', [ $booking_id ] );
        if ( $timestamp ) {
            wp_unschedule_event( $timestamp, 'lvb_send_reminder', [ $booking_id ] );
        }

        $update = [ 'status' => 'cancelled' ];
        // Clear the GCal reference on success so re-cancelling doesn't retry.
        if ( $gcal_status === 'deleted' ) {
            $update['google_event_id'] = '';
        }
        if ( $buffer_cleared ) {
            $update['buffer_event_id'] = '';
        }
        $rows = LVB_Database::update( 'bookings', $update, [ 'id' => $booking_id ] );

        return [
            'rows'        => $rows,
            'gcal_status' => $gcal_status,
            'gcal_error'  => $gcal_error,
        ];
    }

    // -----------------------------------------------------------------------
    // Service CRUD
    // -----------------------------------------------------------------------

    /**
     * Create or update a service record.
     *
     * Sanitises and validates all fields before writing to the database. When
     * creating a new service the next available sort_order value is assigned
     * automatically so the service appears last in the listing.
     *
     * @param array $data {
     *     Service fields (raw, will be sanitised internally).
     *
     *     @type string $name        Service display name.
     *     @type string $description Optional longer description.
     *     @type int    $duration    Duration in minutes (minimum 1).
     *     @type float  $price       Price in the configured currency.
     *     @type int    $buffer_time Buffer time in minutes added after the booking end.
     *     @type string $status      'active' or 'inactive'.
     * }
     * @param int $id  Existing service ID to update, or 0 to create a new record.
     * @return int     The service ID (new or existing).
     */
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

    /**
     * Swap the sort_order of a service with its nearest neighbour.
     *
     * Used by the admin UI to let operators reorder how services are presented
     * to customers in the booking widget dropdown.
     *
     * @param int    $id        Service ID to move.
     * @param string $direction 'up' to decrease sort order (move towards top),
     *                          'down' to increase sort order (move towards bottom).
     * @return void
     */
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

    /**
     * Permanently delete a service and its staff pivot rows.
     *
     * Removes entries from the staff_services pivot table first to avoid orphaned
     * records, then deletes the service itself.
     *
     * @param int $id  Service ID to delete.
     * @return int|false  Number of rows deleted from the services table, or false on failure.
     */
    public static function delete_service( $id ) {
        LVB_Database::delete( 'staff_services', [ 'service_id' => $id ] );
        return LVB_Database::delete( 'services', [ 'id' => $id ] );
    }

    // -----------------------------------------------------------------------
    // Staff CRUD
    // -----------------------------------------------------------------------

    /**
     * Create or update a staff member record.
     *
     * Sanitises all fields and writes the staff record, then synchronises the
     * staff–service pivot table via {@see update_staff_services()}.
     *
     * @param array $data {
     *     Staff fields (raw, will be sanitised internally).
     *
     *     @type string   $name        Staff member's display name.
     *     @type string   $email       Optional email address.
     *     @type string   $phone       Optional phone number.
     *     @type string   $calendar_id Optional Google Calendar ID for this staff member.
     *     @type string   $status      'active' or 'inactive'.
     *     @type int[]    $service_ids Array of service IDs assigned to this staff member.
     * }
     * @param int $id  Existing staff ID to update, or 0 to create a new record.
     * @return int|false  The staff ID on success, or false on DB failure.
     */
    public static function save_staff( $data, $id = 0 ) {
        $clean = [
            'name'          => sanitize_text_field( $data['name'] ),
            'email'         => sanitize_email( $data['email'] ?? '' ),
            'phone'         => sanitize_text_field( $data['phone'] ?? '' ),
            'calendar_id'   => sanitize_text_field( $data['calendar_id'] ?? '' ),
            'working_hours' => LVB_Staff_Schedule::encode_working_hours( $data['working_hours'] ?? null ),
            'time_off'      => LVB_Staff_Schedule::encode_time_off( $data['time_off'] ?? null ),
            'color_id'      => ( isset( $data['color_id'] ) && (int) $data['color_id'] >= 1 && (int) $data['color_id'] <= 11 ) ? (int) $data['color_id'] : null,
            'status'        => in_array( $data['status'] ?? 'active', [ 'active', 'inactive' ], true ) ? $data['status'] : 'active',
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

    /**
     * Synchronise the staff–service pivot table for a given staff member.
     *
     * Deletes all existing pivot rows for the staff member and inserts fresh rows
     * for each valid service ID in $service_ids. Using DELETE + INSERT (via
     * wpdb::replace) is simpler than a diff and acceptable given the small row
     * counts expected in this table.
     *
     * @param int   $staff_id    Staff member ID.
     * @param int[] $service_ids Array of service IDs to assign (already cast to int).
     * @return void
     */
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

    /**
     * Permanently delete a staff member and their service pivot rows.
     *
     * Removes the staff_services pivot entries first, then the staff record itself.
     *
     * @param int $id  Staff member ID to delete.
     * @return int|false  Number of rows deleted from the staff table, or false on failure.
     */
    public static function delete_staff( $id ) {
        LVB_Database::delete( 'staff_services', [ 'staff_id' => $id ] );
        return LVB_Database::delete( 'staff', [ 'id' => $id ] );
    }
}
