<?php
/**
 * LVB_Admin – admin menu, asset enqueueing, and page routing.
 *
 * This class is the WordPress admin layer for the LakeVision Booking plugin. It:
 *  - Registers the top-level "LV Booking" menu and its five sub-pages
 *    (Bookings, Customers, Services, Staff, Settings).
 *  - Enqueues the admin stylesheet only on the plugin's own pages to avoid
 *    polluting other admin screens.
 *  - Handles all admin form submissions and GET-action URLs in a single
 *    `admin_init` callback:
 *      Settings save, Google OAuth disconnect, service/staff CRUD,
 *      service reordering, booking cancellation, and booking edit.
 *  - Displays admin notices based on URL parameters set after redirects.
 *  - Delegates actual page rendering to PHP partials in admin/partials/.
 *
 * The class is a singleton to prevent duplicate hook registrations if
 * {@see LVB_Admin::instance()} is called more than once.
 *
 * @package LakeVision_Booking
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Manages the WordPress admin interface for the LakeVision Booking plugin.
 *
 * @package LakeVision_Booking
 */
class LVB_Admin {

    /**
     * Holds the single instance of this class.
     *
     * @var LVB_Admin|null
     */
    private static $instance = null;

    /**
     * Return (and lazily create) the singleton instance.
     *
     * @return LVB_Admin
     */
    public static function instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Private constructor – call {@see instance()} instead.
     *
     * Registers all required WordPress admin hooks at instantiation time.
     */
    private function __construct() {
        add_action( 'admin_menu',            [ $this, 'register_menus' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
        add_action( 'admin_init',            [ $this, 'handle_form_submissions' ] );
        add_action( 'admin_notices',         [ $this, 'show_notices' ] );
    }

    // -----------------------------------------------------------------------
    // Menus
    // -----------------------------------------------------------------------

    /**
     * Register the top-level admin menu and all sub-menu pages.
     *
     * Hooked to `admin_menu`. Adds a "LV Booking" entry to the WordPress
     * sidebar (at position 30, after Comments) with five sub-pages:
     * Bookings, Customers, Services, Staff, and Settings. Each sub-page
     * requires the `manage_options` capability.
     *
     * @return void
     */
    public function register_menus() {
        add_menu_page(
            __( 'LakeVision Booking', 'lakevision-booking' ),
            __( 'LV Booking', 'lakevision-booking' ),
            'manage_options',
            'lvb-bookings',
            [ $this, 'page_bookings' ],
            'dashicons-calendar-alt',
            30
        );

        add_submenu_page( 'lvb-bookings', __( 'Bookings',  'lakevision-booking' ), __( 'Bookings',  'lakevision-booking' ), 'manage_options', 'lvb-bookings',  [ $this, 'page_bookings' ] );
        add_submenu_page( 'lvb-bookings', __( 'Kalender',  'lakevision-booking' ), __( 'Kalender',  'lakevision-booking' ), 'manage_options', 'lvb-calendar',  [ $this, 'page_calendar' ] );
        add_submenu_page( 'lvb-bookings', __( 'Customers', 'lakevision-booking' ), __( 'Customers', 'lakevision-booking' ), 'manage_options', 'lvb-customers', [ $this, 'page_customers' ] );
        add_submenu_page( 'lvb-bookings', __( 'Services',  'lakevision-booking' ), __( 'Services',  'lakevision-booking' ), 'manage_options', 'lvb-services',  [ $this, 'page_services' ] );
        add_submenu_page( 'lvb-bookings', __( 'Staff',     'lakevision-booking' ), __( 'Staff',     'lakevision-booking' ), 'manage_options', 'lvb-staff',     [ $this, 'page_staff' ] );
        if ( get_option( 'lvb_intake_form_enabled', 0 ) ) {
            add_submenu_page( 'lvb-bookings', __( 'Intake Forms', 'lakevision-booking' ), __( 'Intake Forms', 'lakevision-booking' ), 'manage_options', 'lvb-intake-forms', [ $this, 'page_intake_forms' ] );
            add_submenu_page( 'lvb-bookings', __( 'Intake Form Builder', 'lakevision-booking' ), __( 'Intake Form Builder', 'lakevision-booking' ), 'manage_options', 'lvb-form-builder', [ $this, 'page_form_builder' ] );
        }
        add_submenu_page( 'lvb-bookings', __( 'Settings',  'lakevision-booking' ), __( 'Settings',  'lakevision-booking' ), 'manage_options', 'lvb-settings',  [ $this, 'page_settings' ] );
    }

    // -----------------------------------------------------------------------
    // Assets
    // -----------------------------------------------------------------------

    /**
     * Enqueue admin CSS on this plugin's pages only.
     *
     * Hooked to `admin_enqueue_scripts`. Compares the current screen hook
     * against the known hook slugs for the plugin's pages and returns early
     * on any other admin screen to avoid loading unnecessary styles.
     *
     * @param string $hook  The current admin page hook suffix (e.g.
     *                      'toplevel_page_lvb-bookings').
     * @return void
     */
    public function enqueue_assets( $hook ) {
        $lvb_pages = [
            'toplevel_page_lvb-bookings',
            'lv-booking_page_lvb-calendar',
            'lv-booking_page_lvb-customers',
            'lv-booking_page_lvb-services',
            'lv-booking_page_lvb-staff',
            'lv-booking_page_lvb-settings',
            'lv-booking_page_lvb-intake-forms',
            'lv-booking_page_lvb-form-builder',
        ];
        if ( ! in_array( $hook, $lvb_pages, true ) ) {
            return;
        }
        wp_enqueue_style( 'lvb-admin', LVB_PLUGIN_URL . 'assets/css/admin.css', [], LVB_VERSION );

        // Enqueue jQuery UI Sortable on the Form Builder page for drag & drop reordering.
        if ( 'lv-booking_page_lvb-form-builder' === $hook ) {
            wp_enqueue_script( 'jquery-ui-sortable' );
        }

        // Calendar page: FullCalendar bundle + our glue script.
        if ( 'lv-booking_page_lvb-calendar' === $hook ) {
            wp_enqueue_script(
                'lvb-fullcalendar',
                'https://cdn.jsdelivr.net/npm/fullcalendar@6.1.15/index.global.min.js',
                [],
                '6.1.15',
                true
            );
            wp_enqueue_script(
                'lvb-calendar',
                LVB_PLUGIN_URL . 'assets/js/calendar.js',
                [ 'lvb-fullcalendar' ],
                filemtime( LVB_PLUGIN_DIR . 'assets/js/calendar.js' ),
                true
            );
            wp_enqueue_style(
                'lvb-calendar',
                LVB_PLUGIN_URL . 'assets/css/calendar.css',
                [ 'lvb-admin' ],
                filemtime( LVB_PLUGIN_DIR . 'assets/css/calendar.css' )
            );

            $palette = LVB_Google_Calendar::get_event_color_palette();
            $staff   = LVB_Database::get_all( 'staff', [ 'status' => 'active' ], 'name ASC' );
            $staff_options = array_map( function( $s ) use ( $palette ) {
                $cid = (int) ( $s['color_id'] ?? 0 );
                return [
                    'id'    => (int) $s['id'],
                    'name'  => $s['name'],
                    'color' => ( $cid >= 1 && $cid <= 11 ) ? $palette[ $cid ]['hex'] : null,
                ];
            }, $staff );

            wp_localize_script( 'lvb-calendar', 'lvbCalendar', [
                'restUrl' => esc_url_raw( rest_url( 'lvb/v1/calendar/events' ) ),
                'nonce'   => wp_create_nonce( 'wp_rest' ),
                'staff'   => $staff_options,
                'locale'  => 'de',
                'timeMin' => self::normalize_time_string( get_option( 'lvb_calendar_time_min', '07:00' ), '07:00' ),
                'timeMax' => self::normalize_time_string( get_option( 'lvb_calendar_time_max', '22:00' ), '22:00' ),
            ] );
        }
    }

    // -----------------------------------------------------------------------
    // Form submission handler (Settings + CRUD)
    // -----------------------------------------------------------------------

    /**
     * Process all admin form submissions and GET-action URLs for this plugin.
     *
     * Hooked to `admin_init`. Returns immediately if the current user does not
     * have the `manage_options` capability. Otherwise checks for known POST
     * fields and GET action parameters, verifies nonces, calls the appropriate
     * manager method, and redirects back to the relevant admin page.
     *
     * Handled operations:
     *   - Settings form save (`lvb_save_settings` POST field).
     *   - Google Calendar disconnect (`lvb_action=disconnect_google` GET param).
     *   - Service create/update (`lvb_save_service` POST field).
     *   - Service reorder up/down (`lvb_action=move_service_up|down` GET param).
     *   - Service delete (`lvb_action=delete_service` GET param).
     *   - Staff create/update (`lvb_save_staff` POST field).
     *   - Staff delete (`lvb_action=delete_staff` GET param).
     *   - Booking cancel (`lvb_action=cancel_booking` GET param) – also sends
     *     a cancellation notification to the customer.
     *
     * @return void
     */
    public function handle_form_submissions() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        // Settings save
        if ( isset( $_POST['lvb_save_settings'] ) ) {
            check_admin_referer( 'lvb_settings_save' );
            $this->save_settings();
            wp_redirect( add_query_arg( [ 'page' => 'lvb-settings', 'lvb_saved' => '1' ], admin_url( 'admin.php' ) ) );
            exit;
        }

        // Google disconnect
        if ( isset( $_GET['lvb_action'] ) && $_GET['lvb_action'] === 'disconnect_google' ) {
            check_admin_referer( 'lvb_disconnect_google' );
            LVB_Google_Calendar::disconnect();
            wp_redirect( add_query_arg( [ 'page' => 'lvb-settings', 'lvb_disconnected' => '1' ], admin_url( 'admin.php' ) ) );
            exit;
        }

        // Service save
        if ( isset( $_POST['lvb_save_service'] ) ) {
            check_admin_referer( 'lvb_service_save' );
            $id = (int) ( $_POST['service_id'] ?? 0 );
            LVB_Booking_Manager::save_service( $_POST, $id );
            wp_redirect( add_query_arg( [ 'page' => 'lvb-services', 'lvb_saved' => '1' ], admin_url( 'admin.php' ) ) );
            exit;
        }

        // Service reorder (move up / move down)
        if ( isset( $_GET['lvb_action'] ) && in_array( $_GET['lvb_action'], [ 'move_service_up', 'move_service_down' ], true ) ) {
            $id        = (int) ( $_GET['id'] ?? 0 );
            $direction = $_GET['lvb_action'] === 'move_service_up' ? 'up' : 'down';
            check_admin_referer( 'lvb_move_service_' . $id );
            LVB_Booking_Manager::move_service( $id, $direction );
            wp_redirect( add_query_arg( [ 'page' => 'lvb-services' ], admin_url( 'admin.php' ) ) );
            exit;
        }

        // Service delete
        if ( isset( $_GET['lvb_action'] ) && $_GET['lvb_action'] === 'delete_service' ) {
            $id = (int) ( $_GET['id'] ?? 0 );
            check_admin_referer( 'lvb_delete_service_' . $id );
            LVB_Booking_Manager::delete_service( $id );
            wp_redirect( add_query_arg( [ 'page' => 'lvb-services', 'lvb_deleted' => '1' ], admin_url( 'admin.php' ) ) );
            exit;
        }

        // Staff save
        if ( isset( $_POST['lvb_save_staff'] ) ) {
            check_admin_referer( 'lvb_staff_save' );
            $id = (int) ( $_POST['staff_id'] ?? 0 );
            LVB_Booking_Manager::save_staff( $_POST, $id );
            wp_redirect( add_query_arg( [ 'page' => 'lvb-staff', 'lvb_saved' => '1' ], admin_url( 'admin.php' ) ) );
            exit;
        }

        // Staff delete
        if ( isset( $_GET['lvb_action'] ) && $_GET['lvb_action'] === 'delete_staff' ) {
            $id = (int) ( $_GET['id'] ?? 0 );
            check_admin_referer( 'lvb_delete_staff_' . $id );
            LVB_Booking_Manager::delete_staff( $id );
            wp_redirect( add_query_arg( [ 'page' => 'lvb-staff', 'lvb_deleted' => '1' ], admin_url( 'admin.php' ) ) );
            exit;
        }

        // Form Builder save
        if ( isset( $_POST['lvb_save_form_builder'] ) ) {
            check_admin_referer( 'lvb_form_builder_save' );
            $this->save_form_builder();
            wp_redirect( add_query_arg( [ 'page' => 'lvb-form-builder', 'lvb_saved' => '1' ], admin_url( 'admin.php' ) ) );
            exit;
        }

        // Form Builder reset to defaults
        if ( isset( $_GET['lvb_action'] ) && $_GET['lvb_action'] === 'reset_form_fields' ) {
            check_admin_referer( 'lvb_reset_form_fields' );
            delete_option( 'lvb_intake_form_fields' );
            wp_redirect( add_query_arg( [ 'page' => 'lvb-form-builder', 'lvb_saved' => '1' ], admin_url( 'admin.php' ) ) );
            exit;
        }

        // Customer save (create or edit)
        if ( isset( $_POST['lvb_save_customer'] ) ) {
            $id = (int) ( $_POST['customer_id'] ?? 0 );
            check_admin_referer( 'lvb_save_customer_' . $id );

            $result = LVB_Booking_Manager::save_customer( wp_unslash( $_POST ), $id );
            $args   = [ 'page' => 'lvb-customers' ];

            if ( is_wp_error( $result ) ) {
                if ( $id > 0 ) {
                    $args['edit'] = $id;
                } else {
                    $args['new'] = '1';
                }
                $args['lvb_error'] = rawurlencode( $result->get_error_message() );
            } else {
                $args['lvb_saved'] = $id > 0 ? '1' : 'new_customer';
            }
            wp_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
            exit;
        }

        // Customer delete
        if ( isset( $_GET['lvb_action'] ) && $_GET['lvb_action'] === 'delete_customer' ) {
            $id = (int) ( $_GET['id'] ?? 0 );
            check_admin_referer( 'lvb_delete_customer_' . $id );
            global $wpdb;
            // Get customer email before deleting, to clean up related intake forms
            $customer = $wpdb->get_row( $wpdb->prepare( "SELECT email FROM {$wpdb->prefix}lvb_customers WHERE id = %d", $id ), ARRAY_A );
            $wpdb->delete( $wpdb->prefix . 'lvb_customers', [ 'id' => $id ], [ '%d' ] );
            if ( $customer && ! empty( $customer['email'] ) ) {
                $wpdb->delete( $wpdb->prefix . 'lvb_intake_forms', [ 'email' => $customer['email'] ], [ '%s' ] );
            }
            wp_redirect( add_query_arg( [ 'page' => 'lvb-customers', 'lvb_deleted' => '1' ], admin_url( 'admin.php' ) ) );
            exit;
        }

        // Intake form delete
        if ( isset( $_GET['lvb_action'] ) && $_GET['lvb_action'] === 'delete_intake_form' ) {
            $id = (int) ( $_GET['id'] ?? 0 );
            check_admin_referer( 'lvb_delete_intake_form_' . $id );
            global $wpdb;
            $wpdb->delete( $wpdb->prefix . 'lvb_intake_forms', [ 'id' => $id ], [ '%d' ] );
            wp_redirect( add_query_arg( [ 'page' => 'lvb-intake-forms', 'lvb_deleted' => '1' ], admin_url( 'admin.php' ) ) );
            exit;
        }

        // Booking save (create or edit, depending on booking_id)
        if ( isset( $_POST['lvb_save_booking'] ) ) {
            $id = (int) ( $_POST['booking_id'] ?? 0 );
            check_admin_referer( 'lvb_save_booking_' . $id );

            $data    = wp_unslash( $_POST );
            $is_new  = $id === 0;
            $result  = $is_new
                ? LVB_Booking_Manager::create_booking_admin( $data )
                : LVB_Booking_Manager::update_booking( $id, $data );

            $args = [ 'page' => 'lvb-bookings' ];

            if ( is_wp_error( $result ) ) {
                // Stay in form mode so the operator can fix and retry.
                if ( $is_new ) {
                    $args['new'] = '1';
                } else {
                    $args['edit'] = $id;
                }
                $args['lvb_error'] = rawurlencode( $result->get_error_message() );
            } else {
                $new_id = (int) ( $result['id'] ?? $id );
                $args['lvb_saved'] = $is_new ? 'new' : '1';
                if ( ! empty( $_POST['lvb_send_notification'] ) ) {
                    LVB_Notifications::send_booking_confirmation( $new_id, ! $is_new );
                    $args['lvb_notified'] = '1';
                }
                if ( ! empty( $result['gcal_warning'] ) ) {
                    $args['lvb_gcal_error'] = rawurlencode( $result['gcal_warning'] );
                }
            }
            wp_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
            exit;
        }

        // Booking delete (permanent, distinct from cancel)
        if ( isset( $_GET['lvb_action'] ) && $_GET['lvb_action'] === 'delete_booking' ) {
            $id = (int) ( $_GET['id'] ?? 0 );
            check_admin_referer( 'lvb_delete_booking_' . $id );
            // Only allow deletion of cancelled bookings.
            $booking = LVB_Database::get_by_id( 'bookings', $id );
            if ( ! $booking || $booking['status'] !== 'cancelled' ) {
                wp_redirect( add_query_arg( [ 'page' => 'lvb-bookings', 'lvb_error' => 'not_cancelled' ], admin_url( 'admin.php' ) ) );
                exit;
            }
            $result = LVB_Booking_Manager::delete_booking( $id );
            $args   = [ 'page' => 'lvb-bookings', 'lvb_deleted' => '1' ];
            if ( is_array( $result ) && $result['gcal_status'] === 'failed' ) {
                $args['lvb_gcal_error'] = rawurlencode( $result['gcal_error'] );
            }
            wp_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
            exit;
        }

        // Booking cancel
        if ( isset( $_GET['lvb_action'] ) && $_GET['lvb_action'] === 'cancel_booking' ) {
            $id = (int) ( $_GET['id'] ?? 0 );
            check_admin_referer( 'lvb_cancel_booking_' . $id );
            $result = LVB_Booking_Manager::cancel_booking( $id );
            LVB_Notifications::send_cancellation( $id );
            $args = [ 'page' => 'lvb-bookings', 'lvb_cancelled' => '1' ];
            if ( is_array( $result ) && $result['gcal_status'] === 'failed' ) {
                $args['lvb_gcal_error'] = rawurlencode( $result['gcal_error'] );
            }
            wp_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
            exit;
        }
    }

    /**
     * Persist plugin settings from the Settings form to the WordPress options table.
     *
     * Iterates over a hard-coded whitelist of option names, sanitises each value
     * with sanitize_text_field(), and calls update_option(). Only options present
     * in the $_POST array are updated, so partial form submissions are safe.
     *
     * @return void
     */
    /**
     * Normalise a user-entered time string to "HH:MM:SS" (FullCalendar-compatible).
     *
     * Accepts "HH:MM" or "HH:MM:SS". Falls back to the default when invalid so
     * a bad value in the option table cannot break the calendar UI.
     *
     * @param string $value
     * @param string $default Fallback in "HH:MM" form.
     * @return string 24h time in "HH:MM:SS".
     */
    private static function normalize_time_string( $value, $default ) {
        $value = is_string( $value ) ? trim( $value ) : '';
        if ( preg_match( '/^([0-1]?\d|2[0-3]):([0-5]\d)(?::([0-5]\d))?$/', $value, $m ) ) {
            return sprintf( '%02d:%02d:%02d', (int) $m[1], (int) $m[2], isset( $m[3] ) ? (int) $m[3] : 0 );
        }
        return $default . ':00';
    }

    private function save_settings() {
        $text_options = [
            'lvb_google_client_id',
            'lvb_google_client_secret',
            'lvb_google_default_calendar_id',
            'lvb_admin_notification_email',
            'lvb_email_from',
            'lvb_email_from_address',
            'lvb_currency_symbol',
            'lvb_email_logo_url',
            'lvb_staff_label',
            'lvb_service_label',
            'lvb_slot_label',
            'lvb_payment_title',
            'lvb_payment_methods',
            'lvb_whatsapp_url',
            'lvb_booking_title',
            'lvb_booking_subtitle',
            'lvb_min_advance_hours',
            'lvb_cutoff_grid',
            'lvb_slot_realign_grid',
            'lvb_accent_color',
            'lvb_accent2_color',
            'lvb_calendar_time_min',
            'lvb_calendar_time_max',
        ];
        $color_options = [
            'lvb_dark_color',
            'lvb_bg_color',
            'lvb_footer_bg_color',
            'lvb_text_color',
        ];
        // Textarea options
        if ( isset( $_POST['lvb_email_confirmation_text'] ) ) {
            update_option( 'lvb_email_confirmation_text', sanitize_textarea_field( wp_unslash( $_POST['lvb_email_confirmation_text'] ) ) );
        }
        if ( isset( $_POST['lvb_email_cancellation_note'] ) ) {
            update_option( 'lvb_email_cancellation_note', sanitize_textarea_field( wp_unslash( $_POST['lvb_email_cancellation_note'] ) ) );
        }
        // Textarea options – disclaimer text
        if ( isset( $_POST['lvb_disclaimer_text'] ) ) {
            update_option( 'lvb_disclaimer_text', sanitize_textarea_field( wp_unslash( $_POST['lvb_disclaimer_text'] ) ) );
        }
        // Textarea options – intake form disclaimer
        if ( isset( $_POST['lvb_intake_disclaimer'] ) ) {
            update_option( 'lvb_intake_disclaimer', wp_kses_post( wp_unslash( $_POST['lvb_intake_disclaimer'] ) ) );
        }
        // Checkbox options
        update_option( 'lvb_disclaimer_enabled', isset( $_POST['lvb_disclaimer_enabled'] ) ? 1 : 0 );
        update_option( 'lvb_reminder_enabled', isset( $_POST['lvb_reminder_enabled'] ) ? 1 : 0 );
        update_option( 'lvb_theme_inherit', isset( $_POST['lvb_theme_inherit'] ) ? 1 : 0 );
        update_option( 'lvb_intake_form_enabled', isset( $_POST['lvb_intake_form_enabled'] ) ? 1 : 0 );
        // Number options
        if ( isset( $_POST['lvb_reminder_hours'] ) ) {
            update_option( 'lvb_reminder_hours', max( 1, (int) $_POST['lvb_reminder_hours'] ) );
        }
        foreach ( $text_options as $opt ) {
            if ( isset( $_POST[ $opt ] ) ) {
                update_option( $opt, sanitize_text_field( wp_unslash( $_POST[ $opt ] ) ) );
            }
        }
        foreach ( $color_options as $opt ) {
            if ( isset( $_POST[ $opt ] ) ) {
                update_option( $opt, sanitize_hex_color( wp_unslash( $_POST[ $opt ] ) ) );
            }
        }
    }

    /**
     * Persist the form builder fields configuration to wp_options as JSON.
     *
     * @return void
     */
    private function save_form_builder() {
        $raw_fields = isset( $_POST['fields'] ) && is_array( $_POST['fields'] ) ? $_POST['fields'] : [];
        $fields = [];

        // Sort by sort_order
        usort( $raw_fields, function( $a, $b ) {
            return (int) ( $a['sort_order'] ?? 0 ) - (int) ( $b['sort_order'] ?? 0 );
        } );

        foreach ( $raw_fields as $raw ) {
            $id   = sanitize_key( $raw['id'] ?? '' );
            $type = sanitize_text_field( $raw['type'] ?? 'text' );
            if ( empty( $id ) ) {
                continue;
            }

            $field = [
                'id'       => $id,
                'label'    => sanitize_text_field( $raw['label'] ?? '' ),
                'type'     => $type,
                'required' => ! empty( $raw['required'] ),
                'enabled'  => ! empty( $raw['enabled'] ),
            ];

            // Options for select and checkbox-group
            if ( in_array( $type, [ 'select', 'checkbox-group' ], true ) && ! empty( $raw['options'] ) ) {
                $lines = preg_split( '/\r?\n/', trim( $raw['options'] ) );
                $field['options'] = array_values( array_filter( array_map( 'sanitize_text_field', $lines ) ) );
            }

            // Checkbox text for single-checkbox
            if ( $type === 'single-checkbox' && ! empty( $raw['checkbox_text'] ) ) {
                $field['checkbox_text'] = sanitize_text_field( $raw['checkbox_text'] );
            }

            $fields[] = $field;
        }

        update_option( 'lvb_intake_form_fields', wp_json_encode( $fields, JSON_UNESCAPED_UNICODE ) );

        // Save intake form settings (Enable + Disclaimer)
        update_option( 'lvb_intake_form_enabled', isset( $_POST['lvb_intake_form_enabled'] ) ? 1 : 0 );
        if ( isset( $_POST['lvb_intake_disclaimer'] ) ) {
            update_option( 'lvb_intake_disclaimer', sanitize_textarea_field( wp_unslash( $_POST['lvb_intake_disclaimer'] ) ) );
        }
    }

    // -----------------------------------------------------------------------
    // Admin notices
    // -----------------------------------------------------------------------

    /**
     * Render WordPress admin notice banners based on URL query parameters.
     *
     * Hooked to `admin_notices`. After a form submission the handler redirects
     * back to the page with a status parameter in the URL. This method reads
     * those parameters and outputs the appropriate dismissible notice:
     *   lvb_saved        – green "Settings saved" or "Item saved" notice.
     *   lvb_deleted      – green "Item deleted" notice.
     *   lvb_cancelled    – green "Booking cancelled" notice.
     *   lvb_connected    – green "Google Calendar connected" notice.
     *   lvb_disconnected – blue "Google Calendar disconnected" notice.
     *   lvb_error        – red error notice (URL-decoded error message string).
     *
     * @return void
     */
    public function show_notices() {
        if ( isset( $_GET['lvb_saved'] ) ) {
            switch ( $_GET['lvb_saved'] ) {
                case 'new':           $msg = __( 'Buchung angelegt.',  'lakevision-booking' ); break;
                case 'new_customer':  $msg = __( 'Kunde angelegt.',    'lakevision-booking' ); break;
                default:              $msg = __( 'Settings saved.',    'lakevision-booking' ); break;
            }
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $msg ) . '</p></div>';
        }
        if ( isset( $_GET['lvb_deleted'] ) ) {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Item deleted.', 'lakevision-booking' ) . '</p></div>';
        }
        if ( isset( $_GET['lvb_cancelled'] ) ) {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Booking cancelled.', 'lakevision-booking' ) . '</p></div>';
        }
        if ( isset( $_GET['lvb_error'] ) && $_GET['lvb_error'] === 'not_cancelled' ) {
            echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'Buchung kann nur gelöscht werden, wenn sie zuerst storniert wurde.', 'lakevision-booking' ) . '</p></div>';
        }
        if ( isset( $_GET['lvb_notified'] ) ) {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Update-Email an Kunde gesendet.', 'lakevision-booking' ) . '</p></div>';
        }
        if ( isset( $_GET['lvb_gcal_error'] ) ) {
            $msg = sanitize_text_field( urldecode( wp_unslash( $_GET['lvb_gcal_error'] ) ) );
            echo '<div class="notice notice-warning is-dismissible"><p><strong>'
                . esc_html__( 'Event konnte nicht aus Google Calendar entfernt werden – bitte manuell prüfen.', 'lakevision-booking' )
                . '</strong><br><code>' . esc_html( $msg ) . '</code></p></div>';
        }
        if ( isset( $_GET['lvb_connected'] ) ) {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Google Calendar connected successfully!', 'lakevision-booking' ) . '</p></div>';
        }
        if ( isset( $_GET['lvb_disconnected'] ) ) {
            echo '<div class="notice notice-info is-dismissible"><p>' . esc_html__( 'Google Calendar disconnected.', 'lakevision-booking' ) . '</p></div>';
        }
        if ( isset( $_GET['lvb_error'] ) ) {
            echo '<div class="notice notice-error is-dismissible"><p>' . esc_html( urldecode( $_GET['lvb_error'] ) ) . '</p></div>';
        }
    }

    // -----------------------------------------------------------------------
    // Page renderers – delegate to partials
    // -----------------------------------------------------------------------

    /**
     * Render the Bookings admin page.
     *
     * Delegates to the bookings partial which handles listing, search, and
     * pagination of booking records.
     *
     * @return void
     */
    public function page_bookings() {
        require LVB_PLUGIN_DIR . 'admin/partials/bookings.php';
    }

    /**
     * Render the Kalender (calendar) admin page.
     *
     * Delegates to the calendar partial which renders the FullCalendar UI.
     * The page consumes the REST API exposed by LVB_Calendar_API.
     *
     * @return void
     */
    public function page_calendar() {
        require LVB_PLUGIN_DIR . 'admin/partials/calendar.php';
    }

    /**
     * Render the Customers admin page.
     *
     * Delegates to the customers partial which lists all customer records.
     *
     * @return void
     */
    public function page_customers() {
        require LVB_PLUGIN_DIR . 'admin/partials/customers.php';
    }

    /**
     * Render the Services admin page.
     *
     * Delegates to the services partial which handles the service list and the
     * add/edit form.
     *
     * @return void
     */
    public function page_services() {
        require LVB_PLUGIN_DIR . 'admin/partials/services.php';
    }

    /**
     * Render the Staff admin page.
     *
     * Delegates to the staff partial which handles the staff list and the
     * add/edit form including the service assignment checkboxes.
     *
     * @return void
     */
    public function page_staff() {
        require LVB_PLUGIN_DIR . 'admin/partials/staff.php';
    }

    /**
     * Render the Settings admin page.
     *
     * Delegates to the settings partial which contains the Google OAuth
     * configuration and email settings forms.
     *
     * @return void
     */
    public function page_settings() {
        require LVB_PLUGIN_DIR . 'admin/partials/settings.php';
    }

    /**
     * Render the Form Builder admin page.
     *
     * @return void
     */
    public function page_form_builder() {
        require LVB_PLUGIN_DIR . 'admin/partials/form-builder.php';
    }

    /**
     * Render the Intake Forms admin page.
     *
     * Delegates to the intake-forms partial which lists all intake form
     * submissions and allows viewing individual records.
     *
     * @return void
     */
    public function page_intake_forms() {
        require LVB_PLUGIN_DIR . 'admin/partials/intake-forms.php';
    }
}
