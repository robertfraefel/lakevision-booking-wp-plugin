<?php
/**
 * Plugin Name: LakeVision Booking
 * Plugin URI:  https://github.com/robertfraefel/lakevision-booking-wp-plugin
 * Description: Flexible booking system with Google Calendar integration, time-slot management and email notifications.
 * Version:     2.0.0-alpha.8
 * Author:      LakeVision
 * Author URI:  https://lakevision.ch
 * License:     GPL-2.0+
 * Text Domain: lakevision-booking
 *
 * @package LakeVision_Booking
 *
 * Overview
 * --------
 * LakeVision Booking is a self-contained WordPress plugin that provides a full
 * end-to-end booking workflow for WakeSurf (and similar water-sport) businesses:
 *
 *  - Frontend: a multi-step booking widget rendered via the [lvb_booking] shortcode.
 *    Customers pick a date on an interactive calendar, choose an available time slot
 *    sourced from Google Calendar, select a service, and submit their contact details.
 *
 *  - Backend: a dedicated admin section (LV Booking menu) for managing bookings,
 *    customers, services, and staff members, plus a Settings page for Google OAuth
 *    credentials and email configuration.
 *
 *  - Google Calendar integration: OAuth 2.0 flow (no Composer dependency) to read
 *    availability slots and write confirmed-booking events to one or more calendars.
 *
 *  - Notifications: HTML emails sent to both the customer and the admin on booking
 *    confirmation and cancellation.
 *
 * Architecture
 * ------------
 * The plugin uses a simple singleton entry-point (LakeVision_Booking) that wires up
 * WordPress hooks and delegates all domain logic to dedicated static classes:
 *
 *   LVB_Database          – DB install, generic CRUD helpers, booking queries.
 *   LVB_Google_Calendar   – OAuth flow, Calendar API wrapper, slot helpers.
 *   LVB_Booking_Manager   – High-level booking/service/staff business logic.
 *   LVB_Notifications     – Email generation and delivery.
 *   LVB_Shortcode         – Frontend shortcode rendering and AJAX endpoints.
 *   LVB_Admin             – Admin menu registration, asset enqueue, form handling.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Honour the X-Forwarded-Proto header set by reverse proxies (Coolify/Traefik,
// Cloudflare with Full SSL, etc.). Without this, WordPress sees a plain HTTP
// request even when the browser is on HTTPS, force_ssl_admin() kicks in, and
// /wp-admin/ ends up in a redirect loop. Cheap to evaluate on every request;
// no-op when the header is absent. Runs before plugin constants so it's
// effective during the wp-settings.php plugin-loading phase, well before
// auth_redirect() runs in /wp-admin/admin.php.
if ( ! empty( $_SERVER['HTTP_X_FORWARDED_PROTO'] )
     && strtolower( $_SERVER['HTTP_X_FORWARDED_PROTO'] ) === 'https' ) {
    $_SERVER['HTTPS'] = 'on';
}

// Plugin constants
define( 'LVB_VERSION',     '2.0.0-alpha.8' );
define( 'LVB_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'LVB_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'LVB_PLUGIN_FILE', __FILE__ );

// Autoload includes
require_once LVB_PLUGIN_DIR . 'includes/class-database.php';
require_once LVB_PLUGIN_DIR . 'includes/class-google-calendar.php';
require_once LVB_PLUGIN_DIR . 'includes/class-booking-manager.php';
require_once LVB_PLUGIN_DIR . 'includes/class-notifications.php';
require_once LVB_PLUGIN_DIR . 'includes/class-staff-schedule.php';
require_once LVB_PLUGIN_DIR . 'includes/class-shortcode.php';
require_once LVB_PLUGIN_DIR . 'includes/class-intake-form.php';
require_once LVB_PLUGIN_DIR . 'includes/class-calendar-api.php';
require_once LVB_PLUGIN_DIR . 'includes/class-updater.php';
require_once LVB_PLUGIN_DIR . 'includes/class-capabilities.php';
require_once LVB_PLUGIN_DIR . 'includes/class-admin-api.php';
require_once LVB_PLUGIN_DIR . 'includes/class-admin-shortcode.php';
require_once LVB_PLUGIN_DIR . 'admin/class-admin.php';

/**
 * Main plugin class (singleton).
 *
 * Bootstraps the plugin by registering all WordPress action/filter hooks and
 * instantiating subsystems. Only a single instance is ever created (via
 * {@see LakeVision_Booking::instance()}).
 *
 * @package LakeVision_Booking
 */
final class LakeVision_Booking {

    /**
     * Holds the single instance of this class.
     *
     * @var LakeVision_Booking|null
     */
    private static $instance = null;

    /**
     * Return (and lazily create) the singleton instance.
     *
     * @return LakeVision_Booking
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
     * Immediately delegates to {@see init_hooks()} so all WordPress hooks are
     * registered at instantiation time.
     */
    private function __construct() {
        $this->init_hooks();
    }

    /**
     * Register all WordPress hooks required by the plugin.
     *
     * Covers activation/deactivation lifecycle hooks, frontend asset enqueue,
     * AJAX handlers for the booking widget, the Google OAuth redirect handler,
     * and the admin subsystem.
     *
     * @return void
     */
    private function init_hooks() {
        register_activation_hook( LVB_PLUGIN_FILE, [ 'LVB_Database', 'install' ] );
        register_activation_hook( LVB_PLUGIN_FILE, [ 'LVB_Capabilities', 'on_activation' ] );
        register_activation_hook( LVB_PLUGIN_FILE, [ 'LVB_Admin_Shortcode', 'on_activation' ] );
        register_deactivation_hook( LVB_PLUGIN_FILE, [ __CLASS__, 'deactivate' ] );

        add_action( 'init', [ $this, 'init' ] );
        add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_frontend_assets' ] );

        // AJAX handlers – available to both logged-in and guest users.
        add_action( 'wp_ajax_lvb_get_slots',          [ 'LVB_Shortcode', 'ajax_get_slots' ] );
        add_action( 'wp_ajax_nopriv_lvb_get_slots',   [ 'LVB_Shortcode', 'ajax_get_slots' ] );
        add_action( 'wp_ajax_lvb_submit_booking',     [ 'LVB_Shortcode', 'ajax_submit_booking' ] );
        add_action( 'wp_ajax_nopriv_lvb_submit_booking', [ 'LVB_Shortcode', 'ajax_submit_booking' ] );
        add_action( 'wp_ajax_lvb_get_staff_for_service', [ 'LVB_Shortcode', 'ajax_get_staff_for_service' ] );
        add_action( 'wp_ajax_nopriv_lvb_get_staff_for_service', [ 'LVB_Shortcode', 'ajax_get_staff_for_service' ] );

        // Intake form AJAX handlers
        add_action( 'wp_ajax_lvb_submit_intake_form',        [ 'LVB_Intake_Form', 'ajax_submit' ] );
        add_action( 'wp_ajax_nopriv_lvb_submit_intake_form', [ 'LVB_Intake_Form', 'ajax_submit' ] );

        // Calendar REST API (legacy admin calendar drag/resize)
        LVB_Calendar_API::register();

        // v2.0: capability layer + extended REST API + [lvb_admin] shortcode
        LVB_Capabilities::register();
        LVB_Admin_API::register();
        LVB_Admin_Shortcode::register();

        // Self-update via GitHub Releases / tags
        LVB_Updater::register();

        // Email reminder cron
        add_action( 'lvb_send_reminder', [ 'LVB_Notifications', 'send_reminder' ] );

        // Google Calendar health check (daily)
        add_action( 'lvb_calendar_health_check', [ 'LVB_Google_Calendar', 'health_check' ] );
        if ( ! wp_next_scheduled( 'lvb_calendar_health_check' ) ) {
            wp_schedule_event( time(), 'daily', 'lvb_calendar_health_check' );
        }

        // Google OAuth callback
        add_action( 'admin_post_lvb_google_callback', [ 'LVB_Google_Calendar', 'oauth_callback' ] );

        // Admin
        if ( is_admin() ) {
            LVB_Admin::instance();
        }
    }

    /**
     * WordPress `init` action callback.
     *
     * Loads the plugin text domain for i18n and registers the booking shortcode.
     *
     * @return void
     */
    public function init() {
        load_plugin_textdomain( 'lakevision-booking', false, dirname( plugin_basename( LVB_PLUGIN_FILE ) ) . '/languages' );

        // Run DB migration if version changed (e.g. new tables added).
        if ( get_option( 'lvb_db_version' ) !== LVB_VERSION ) {
            LVB_Database::install();
        }

        LVB_Shortcode::register();
        LVB_Intake_Form::register();
    }

    /**
     * Register frontend CSS and JS so they can be enqueued by the shortcode.
     *
     * Assets are registered (not enqueued) here so they load only when the
     * [lvb_booking] shortcode actually renders – whether in post content or
     * via do_shortcode() in a PHP template.
     * Uses file modification time as the asset version to bust browser caches
     * automatically whenever a file changes in development.
     * The `lvbData` JS object exposes the AJAX URL and a security nonce to the
     * booking script.
     *
     * @return void
     */
    public function enqueue_frontend_assets() {
        wp_register_style(
            'lvb-booking',
            LVB_PLUGIN_URL . 'assets/css/booking.css',
            [],
            filemtime( LVB_PLUGIN_DIR . 'assets/css/booking.css' )
        );
        wp_register_script(
            'lvb-booking',
            LVB_PLUGIN_URL . 'assets/js/booking.js',
            [ 'jquery' ],
            filemtime( LVB_PLUGIN_DIR . 'assets/js/booking.js' ),
            true
        );
        wp_localize_script( 'lvb-booking', 'lvbData', [
            'ajaxUrl'            => admin_url( 'admin-ajax.php' ),
            'nonce'              => wp_create_nonce( 'lvb_booking_nonce' ),
            'disclaimerEnabled'  => (bool) get_option( 'lvb_disclaimer_enabled', '1' ),
            'serviceLabel'       => get_option( 'lvb_service_label', 'Service' ),
            'slotRealignGrid'    => max( 1, (int) get_option( 'lvb_slot_realign_grid', 15 ) ),
        ] );

        // Intake form assets
        wp_register_style(
            'lvb-intake-form',
            LVB_PLUGIN_URL . 'assets/css/intake-form-v2.css',
            [],
            filemtime( LVB_PLUGIN_DIR . 'assets/css/intake-form-v2.css' )
        );
        wp_register_script(
            'lvb-intake-form',
            LVB_PLUGIN_URL . 'assets/js/intake-form.js',
            [],
            filemtime( LVB_PLUGIN_DIR . 'assets/js/intake-form.js' ),
            true
        );
        wp_localize_script( 'lvb-intake-form', 'lvbIntakeData', [
            'ajaxUrl' => admin_url( 'admin-ajax.php' ),
        ] );
    }

    /**
     * Plugin deactivation callback.
     *
     * Currently a no-op placeholder. Future implementations may flush rewrite
     * rules or clean up scheduled events here.
     *
     * @return void
     */
    public static function deactivate() {
        wp_clear_scheduled_hook( 'lvb_calendar_health_check' );
    }
}

// Boot
LakeVision_Booking::instance();
