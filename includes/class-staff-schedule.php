<?php
/**
 * LVB_Staff_Schedule – per-staff recurring working hours + time-off.
 *
 * When a staff member has a schedule defined, it REPLACES the Google Calendar
 * free-block flow: availability windows are generated directly from the stored
 * weekly pattern, minus any configured time-off date ranges. Confirmed bookings
 * are still subtracted downstream in LVB_Shortcode::ajax_get_slots().
 *
 * Data shape (stored as JSON in wp_lvb_staff.working_hours / time_off):
 *
 *   working_hours: {
 *     "mon": [{"s":"08:00","e":"12:00"},{"s":"13:00","e":"17:00"}],
 *     "tue": [],          // empty array = closed
 *     ...
 *   }
 *
 *   time_off: [
 *     {"from":"2026-05-01","to":"2026-05-10","reason":"Ferien"},
 *     {"from":"2026-06-15","to":"2026-06-15","reason":""}
 *   ]
 *
 * @package LakeVision_Booking
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class LVB_Staff_Schedule {

    const DAY_KEYS = [ 'mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun' ];

    /**
     * Whether this staff member has an active working-hours schedule.
     *
     * @param array $staff Row from wp_lvb_staff.
     * @return bool
     */
    public static function has_schedule( $staff ) {
        if ( empty( $staff['working_hours'] ) ) {
            return false;
        }
        $parsed = self::parse_working_hours( $staff['working_hours'] );
        foreach ( $parsed as $windows ) {
            if ( ! empty( $windows ) ) return true;
        }
        return false;
    }

    /**
     * Decode working_hours JSON into a normalised array keyed by mon..sun.
     *
     * @param string|null $raw
     * @return array<string,array<int,array{s:string,e:string}>>
     */
    public static function parse_working_hours( $raw ) {
        $out = array_fill_keys( self::DAY_KEYS, [] );
        if ( empty( $raw ) ) return $out;
        $decoded = json_decode( $raw, true );
        if ( ! is_array( $decoded ) ) return $out;
        foreach ( self::DAY_KEYS as $day ) {
            if ( empty( $decoded[ $day ] ) || ! is_array( $decoded[ $day ] ) ) continue;
            foreach ( $decoded[ $day ] as $win ) {
                if ( ! is_array( $win ) ) continue;
                $s = isset( $win['s'] ) ? self::normalise_time( $win['s'] ) : '';
                $e = isset( $win['e'] ) ? self::normalise_time( $win['e'] ) : '';
                if ( $s !== '' && $e !== '' && $s < $e ) {
                    $out[ $day ][] = [ 's' => $s, 'e' => $e ];
                }
            }
        }
        return $out;
    }

    /**
     * Decode time_off JSON into a normalised list of ranges.
     *
     * @param string|null $raw
     * @return array<int,array{from:string,to:string,reason:string}>
     */
    public static function parse_time_off( $raw ) {
        if ( empty( $raw ) ) return [];
        $decoded = json_decode( $raw, true );
        if ( ! is_array( $decoded ) ) return [];
        $out = [];
        foreach ( $decoded as $r ) {
            if ( ! is_array( $r ) ) continue;
            $from = isset( $r['from'] ) ? self::normalise_date( $r['from'] ) : '';
            $to   = isset( $r['to'] )   ? self::normalise_date( $r['to'] )   : '';
            if ( $from === '' ) continue;
            if ( $to === '' || $to < $from ) $to = $from;
            $out[] = [
                'from'   => $from,
                'to'     => $to,
                'reason' => isset( $r['reason'] ) ? sanitize_text_field( $r['reason'] ) : '',
            ];
        }
        return $out;
    }

    /**
     * Generate availability windows for a staff member between two dates.
     *
     * Return shape matches LVB_Google_Calendar::get_available_slots() so the
     * downstream consumer (ajax_get_slots) can treat both the same way.
     *
     * @param array  $staff     Row from wp_lvb_staff.
     * @param string $date_from Y-m-d (inclusive).
     * @param string $date_to   Y-m-d (inclusive).
     * @return array<int,array<string,mixed>>
     */
    public static function generate_windows( $staff, $date_from, $date_to ) {
        $hours    = self::parse_working_hours( $staff['working_hours'] ?? null );
        $time_off = self::parse_time_off( $staff['time_off'] ?? null );
        $tz       = wp_timezone();

        try {
            $start = new DateTime( $date_from . ' 00:00:00', $tz );
            $end   = new DateTime( $date_to   . ' 00:00:00', $tz );
        } catch ( Exception $e ) {
            return [];
        }
        if ( $end < $start ) return [];

        $slots = [];
        $cursor = clone $start;
        while ( $cursor <= $end ) {
            $date_str = $cursor->format( 'Y-m-d' );
            if ( ! self::is_date_off( $date_str, $time_off ) ) {
                $day_key = self::DAY_KEYS[ (int) $cursor->format( 'N' ) - 1 ];
                foreach ( $hours[ $day_key ] as $win ) {
                    $slot_start = new DateTime( $date_str . ' ' . $win['s'] . ':00', $tz );
                    $slot_end   = new DateTime( $date_str . ' ' . $win['e'] . ':00', $tz );
                    $slots[] = [
                        'id'         => 'sched-' . $date_str . '-' . $win['s'],
                        'title'      => 'Available',
                        'start'      => $slot_start->format( 'Y-m-d H:i:s' ),
                        'end'        => $slot_end->format( 'Y-m-d H:i:s' ),
                        'start_date' => $date_str,
                        'start_time' => $slot_start->format( 'H:i' ),
                        'end_time'   => $slot_end->format( 'H:i' ),
                        'duration'   => (int) ( ( $slot_end->getTimestamp() - $slot_start->getTimestamp() ) / 60 ),
                    ];
                }
            }
            $cursor->modify( '+1 day' );
        }
        return $slots;
    }

    /**
     * Encode a working-hours array for storage (normalises + drops empty/invalid).
     *
     * @param array|null $data  Raw form input (already array-shaped).
     * @return string  JSON.
     */
    public static function encode_working_hours( $data ) {
        $out = array_fill_keys( self::DAY_KEYS, [] );
        if ( is_array( $data ) ) {
            foreach ( self::DAY_KEYS as $day ) {
                if ( empty( $data[ $day ] ) || ! is_array( $data[ $day ] ) ) continue;
                foreach ( $data[ $day ] as $win ) {
                    if ( ! is_array( $win ) ) continue;
                    $s = self::normalise_time( $win['s'] ?? '' );
                    $e = self::normalise_time( $win['e'] ?? '' );
                    if ( $s !== '' && $e !== '' && $s < $e ) {
                        $out[ $day ][] = [ 's' => $s, 'e' => $e ];
                    }
                }
            }
        }
        // If every day is empty return empty string so has_schedule() reports false.
        foreach ( $out as $windows ) {
            if ( ! empty( $windows ) ) {
                return wp_json_encode( $out );
            }
        }
        return '';
    }

    /**
     * Encode a time-off array for storage.
     *
     * @param array|null $data Raw form input.
     * @return string  JSON (or '' when no rows).
     */
    public static function encode_time_off( $data ) {
        if ( ! is_array( $data ) ) return '';
        $out = [];
        foreach ( $data as $row ) {
            if ( ! is_array( $row ) ) continue;
            $from = self::normalise_date( $row['from'] ?? '' );
            $to   = self::normalise_date( $row['to'] ?? '' );
            if ( $from === '' ) continue;
            if ( $to === '' || $to < $from ) $to = $from;
            $out[] = [
                'from'   => $from,
                'to'     => $to,
                'reason' => sanitize_text_field( $row['reason'] ?? '' ),
            ];
        }
        return $out ? wp_json_encode( $out ) : '';
    }

    // ---------------------------------------------------------------------
    // Internal helpers
    // ---------------------------------------------------------------------

    private static function is_date_off( $date, $ranges ) {
        foreach ( $ranges as $r ) {
            if ( $date >= $r['from'] && $date <= $r['to'] ) return true;
        }
        return false;
    }

    private static function normalise_time( $v ) {
        $v = trim( (string) $v );
        if ( $v === '' ) return '';
        if ( ! preg_match( '/^([01]?\d|2[0-3]):([0-5]\d)$/', $v, $m ) ) return '';
        return sprintf( '%02d:%s', (int) $m[1], $m[2] );
    }

    private static function normalise_date( $v ) {
        $v = trim( (string) $v );
        if ( $v === '' ) return '';
        if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $v ) ) return '';
        // Quick validity check via DateTime
        $d = DateTime::createFromFormat( 'Y-m-d', $v );
        return ( $d && $d->format( 'Y-m-d' ) === $v ) ? $v : '';
    }
}
