<?php
/**
 * LVB_Capabilities – fine-grained permissions for the React admin frontend.
 *
 * The new [lvb_admin] frontend doesn't gate access via the coarse
 * `manage_options` capability anymore. Instead, every module is keyed to a
 * dedicated `lvb_*` capability so the site owner can hand out narrow access
 * to staff members ("only the calendar, only your own bookings").
 *
 * Capabilities are registered on plugin activation and on every load
 * (idempotent — `add_cap()` is a no-op for already-granted caps). The site
 * administrator role gets all caps by default; a configurable per-user
 * override lives in the `lvb_capabilities` user meta as a JSON array.
 *
 * @package LakeVision_Booking
 * @since 2.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class LVB_Capabilities {

    /**
     * All capabilities the new admin frontend understands. Keep this list in
     * sync with the React app's permission checks and the REST callbacks in
     * LVB_Admin_API.
     */
    const CAPS = [
        'lvb_view_calendar'       => 'Kalender ansehen',
        'lvb_edit_own_bookings'   => 'Eigene Termine bearbeiten',
        'lvb_edit_all_bookings'   => 'Alle Termine bearbeiten',
        'lvb_manage_customers'    => 'Kunden verwalten',
        'lvb_manage_services'     => 'Services verwalten',
        'lvb_manage_staff'        => 'Mitarbeiter verwalten',
        'lvb_manage_settings'     => 'Einstellungen verwalten',
        'lvb_manage_intake_forms' => 'Anmeldeformulare verwalten',
        'lvb_manage_permissions'  => 'Berechtigungen verwalten',
    ];

    /**
     * Caps the WordPress 'administrator' role gets by default. Other roles
     * start empty; the site owner assigns them via the React permissions UI.
     */
    public static function admin_defaults() {
        return array_keys( self::CAPS );
    }

    /**
     * Register all caps on the administrator role. Idempotent — safe to call
     * on every request, the plugin activation hook, and after updates.
     */
    public static function ensure_admin_caps() {
        $admin = get_role( 'administrator' );
        if ( ! $admin ) {
            return;
        }
        foreach ( self::admin_defaults() as $cap ) {
            if ( ! $admin->has_cap( $cap ) ) {
                $admin->add_cap( $cap );
            }
        }
    }

    /**
     * Read the per-user capability override stored in user meta. Returns an
     * array of cap-slugs the user has been explicitly granted. Empty array
     * means "no override → fall back to role caps".
     *
     * @param int $user_id
     * @return string[]
     */
    public static function user_overrides( $user_id ) {
        $raw = get_user_meta( (int) $user_id, 'lvb_capabilities', true );
        if ( ! is_array( $raw ) ) {
            return [];
        }
        return array_values( array_intersect( $raw, array_keys( self::CAPS ) ) );
    }

    /**
     * Persist a per-user capability override. Pass an empty array to clear
     * the override (user falls back to role-based caps).
     *
     * @param int      $user_id
     * @param string[] $caps  Cap slugs to grant.
     * @return bool
     */
    public static function set_user_overrides( $user_id, array $caps ) {
        $valid = array_values( array_intersect( $caps, array_keys( self::CAPS ) ) );
        if ( empty( $valid ) ) {
            return (bool) delete_user_meta( (int) $user_id, 'lvb_capabilities' );
        }
        return (bool) update_user_meta( (int) $user_id, 'lvb_capabilities', $valid );
    }

    /**
     * Filter user_has_cap to honour the per-user override. If a user has any
     * `lvb_capabilities` meta set, that list becomes the source of truth for
     * all `lvb_*` caps — role-granted caps are masked.
     *
     * Registered in LVB_Capabilities::register().
     *
     * @param array   $allcaps  Map of cap → bool the user currently has.
     * @param array   $caps     Caps being requested.
     * @param array   $args     [ $cap, $user_id, ...$extra ]
     * @param WP_User $user
     * @return array
     */
    public static function filter_user_caps( $allcaps, $caps, $args, $user ) {
        if ( ! $user instanceof WP_User ) {
            return $allcaps;
        }
        $overrides = self::user_overrides( $user->ID );
        if ( empty( $overrides ) ) {
            return $allcaps;
        }
        // Mask every lvb_* cap to false, then re-grant only the overrides.
        foreach ( array_keys( self::CAPS ) as $cap ) {
            $allcaps[ $cap ] = false;
        }
        foreach ( $overrides as $cap ) {
            $allcaps[ $cap ] = true;
        }
        return $allcaps;
    }

    /**
     * Hook registration. Called from the plugin bootstrap.
     */
    public static function register() {
        add_action( 'init', [ __CLASS__, 'ensure_admin_caps' ] );
        add_filter( 'user_has_cap', [ __CLASS__, 'filter_user_caps' ], 10, 4 );
    }

    /**
     * Activation-time setup. Runs once when the plugin is activated.
     */
    public static function on_activation() {
        self::ensure_admin_caps();
    }
}
