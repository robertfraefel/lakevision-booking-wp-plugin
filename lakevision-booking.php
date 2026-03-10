<?php
/**
 * Plugin Name: LakeVision Booking
 * Plugin URI:  https://github.com/robertfraefel/lakevision-booking-wp-plugin
 * Description: Flexible booking system with Google Calendar integration, time-slot management and email notifications.
 * Version:     1.1.0
 * Author:      LakeVision
 * Author URI:  https://lakevision.ch
 * License:     GPL-2.0+
 * Text Domain: lakevision-booking
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Plugin constants
define( 'LVB_VERSION',     '1.0.0' );
define( 'LVB_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'LVB_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'LVB_PLUGIN_FILE', __FILE__ );

// Autoload includes
require_once LVB_PLUGIN_DIR . 'includes/class-database.php';
require_once LVB_PLUGIN_DIR . 'includes/class-google-calendar.php';
require_once LVB_PLUGIN_DIR . 'includes/class-booking-manager.php';
require_once LVB_PLUGIN_DIR . 'includes/class-notifications.php';
require_once LVB_PLUGIN_DIR . 'includes/class-shortcode.php';
require_once LVB_PLUGIN_DIR . 'includes/class-water-temp.php';
require_once LVB_PLUGIN_DIR . 'admin/class-admin.php';

/**
 * Main plugin class (singleton).
 */
final class LakeVision_Booking {

    private static $instance = null;

    public static function instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->init_hooks();
    }

    private function init_hooks() {
        register_activation_hook( LVB_PLUGIN_FILE, [ 'LVB_Database', 'install' ] );
        register_deactivation_hook( LVB_PLUGIN_FILE, [ __CLASS__, 'deactivate' ] );

        add_action( 'init', [ $this, 'init' ] );
        add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_frontend_assets' ] );

        // AJAX handlers
        add_action( 'wp_ajax_lvb_get_slots',          [ 'LVB_Shortcode', 'ajax_get_slots' ] );
        add_action( 'wp_ajax_nopriv_lvb_get_slots',   [ 'LVB_Shortcode', 'ajax_get_slots' ] );
        add_action( 'wp_ajax_lvb_submit_booking',     [ 'LVB_Shortcode', 'ajax_submit_booking' ] );
        add_action( 'wp_ajax_nopriv_lvb_submit_booking', [ 'LVB_Shortcode', 'ajax_submit_booking' ] );
        add_action( 'wp_ajax_lvb_get_staff_for_service', [ 'LVB_Shortcode', 'ajax_get_staff_for_service' ] );
        add_action( 'wp_ajax_nopriv_lvb_get_staff_for_service', [ 'LVB_Shortcode', 'ajax_get_staff_for_service' ] );

        // Water temperature proxy
        LVB_Water_Temp::register();

        // Google OAuth callback
        add_action( 'admin_post_lvb_google_callback', [ 'LVB_Google_Calendar', 'oauth_callback' ] );

        // Admin
        if ( is_admin() ) {
            LVB_Admin::instance();
        }
    }

    public function init() {
        load_plugin_textdomain( 'lakevision-booking', false, dirname( plugin_basename( LVB_PLUGIN_FILE ) ) . '/languages' );
        LVB_Shortcode::register();
    }

    public function enqueue_frontend_assets() {
        global $post;
        if ( is_a( $post, 'WP_Post' ) && has_shortcode( $post->post_content, 'lvb_booking' ) ) {
            wp_enqueue_style(
                'lvb-booking',
                LVB_PLUGIN_URL . 'assets/css/booking.css',
                [],
                filemtime( LVB_PLUGIN_DIR . 'assets/css/booking.css' )
            );
            wp_enqueue_script(
                'lvb-booking',
                LVB_PLUGIN_URL . 'assets/js/booking.js',
                [ 'jquery' ],
                filemtime( LVB_PLUGIN_DIR . 'assets/js/booking.js' ),
                true
            );
            wp_localize_script( 'lvb-booking', 'lvbData', [
                'ajaxUrl' => admin_url( 'admin-ajax.php' ),
                'nonce'   => wp_create_nonce( 'lvb_booking_nonce' ),
            ] );
        }
    }

    public static function deactivate() {
        // Future: flush rewrite rules, etc.
    }
}

// Boot
LakeVision_Booking::instance();
