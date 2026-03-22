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
 *      service reordering, and booking cancellation.
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
        add_submenu_page( 'lvb-bookings', __( 'Customers', 'lakevision-booking' ), __( 'Customers', 'lakevision-booking' ), 'manage_options', 'lvb-customers', [ $this, 'page_customers' ] );
        add_submenu_page( 'lvb-bookings', __( 'Services',  'lakevision-booking' ), __( 'Services',  'lakevision-booking' ), 'manage_options', 'lvb-services',  [ $this, 'page_services' ] );
        add_submenu_page( 'lvb-bookings', __( 'Staff',     'lakevision-booking' ), __( 'Staff',     'lakevision-booking' ), 'manage_options', 'lvb-staff',     [ $this, 'page_staff' ] );
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
            'lv-booking_page_lvb-customers',
            'lv-booking_page_lvb-services',
            'lv-booking_page_lvb-staff',
            'lv-booking_page_lvb-settings',
        ];
        if ( ! in_array( $hook, $lvb_pages, true ) ) {
            return;
        }
        wp_enqueue_style( 'lvb-admin', LVB_PLUGIN_URL . 'assets/css/admin.css', [], LVB_VERSION );
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

        // Booking cancel
        if ( isset( $_GET['lvb_action'] ) && $_GET['lvb_action'] === 'cancel_booking' ) {
            $id = (int) ( $_GET['id'] ?? 0 );
            check_admin_referer( 'lvb_cancel_booking_' . $id );
            LVB_Booking_Manager::cancel_booking( $id );
            LVB_Notifications::send_cancellation( $id );
            wp_redirect( add_query_arg( [ 'page' => 'lvb-bookings', 'lvb_cancelled' => '1' ], admin_url( 'admin.php' ) ) );
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
            'lvb_whatsapp_url',
            'lvb_accent_color',
            'lvb_accent2_color',
        ];
        // Textarea options
        if ( isset( $_POST['lvb_email_confirmation_text'] ) ) {
            update_option( 'lvb_email_confirmation_text', sanitize_textarea_field( wp_unslash( $_POST['lvb_email_confirmation_text'] ) ) );
        }
        // Checkbox options
        update_option( 'lvb_reminder_enabled', isset( $_POST['lvb_reminder_enabled'] ) ? 1 : 0 );
        // Number options
        if ( isset( $_POST['lvb_reminder_hours'] ) ) {
            update_option( 'lvb_reminder_hours', max( 1, (int) $_POST['lvb_reminder_hours'] ) );
        }
        foreach ( $text_options as $opt ) {
            if ( isset( $_POST[ $opt ] ) ) {
                update_option( $opt, sanitize_text_field( wp_unslash( $_POST[ $opt ] ) ) );
            }
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
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Settings saved.', 'lakevision-booking' ) . '</p></div>';
        }
        if ( isset( $_GET['lvb_deleted'] ) ) {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Item deleted.', 'lakevision-booking' ) . '</p></div>';
        }
        if ( isset( $_GET['lvb_cancelled'] ) ) {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Booking cancelled.', 'lakevision-booking' ) . '</p></div>';
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
}
