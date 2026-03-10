<?php
/**
 * LVB_Admin – admin menu, asset enqueueing, and page routing.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class LVB_Admin {

    private static $instance = null;

    public static function instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action( 'admin_menu',            [ $this, 'register_menus' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
        add_action( 'admin_init',            [ $this, 'handle_form_submissions' ] );
        add_action( 'admin_notices',         [ $this, 'show_notices' ] );
    }

    // -----------------------------------------------------------------------
    // Menus
    // -----------------------------------------------------------------------

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
        ];
        // Textarea options
        if ( isset( $_POST['lvb_email_confirmation_text'] ) ) {
            update_option( 'lvb_email_confirmation_text', sanitize_textarea_field( wp_unslash( $_POST['lvb_email_confirmation_text'] ) ) );
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

    public function page_bookings() {
        require LVB_PLUGIN_DIR . 'admin/partials/bookings.php';
    }
    public function page_customers() {
        require LVB_PLUGIN_DIR . 'admin/partials/customers.php';
    }
    public function page_services() {
        require LVB_PLUGIN_DIR . 'admin/partials/services.php';
    }
    public function page_staff() {
        require LVB_PLUGIN_DIR . 'admin/partials/staff.php';
    }
    public function page_settings() {
        require LVB_PLUGIN_DIR . 'admin/partials/settings.php';
    }
}
