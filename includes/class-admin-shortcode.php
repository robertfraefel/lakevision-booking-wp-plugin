<?php
/**
 * LVB_Admin_Shortcode – the [lvb_admin] shortcode that mounts the React app.
 *
 * Separate from LVB_Shortcode (which handles the public booking widget,
 * [lvb_booking]) so the two contexts can never interfere. A page tagged
 * with [lvb_admin] turns into the staff-only "Verwaltung" view:
 *
 *  - Anonymous visitors → redirected to wp-login.php (with redirect_to
 *    back to the page).
 *  - Logged-in users without any lvb_* capability → 404.
 *  - Authorized users → React bundle mounted into #lvb-admin-root.
 *
 * The React bundle lives at assets/admin-app/dist/index.js + index.css.
 * The build step (npm --prefix assets/admin-app run build) produces those
 * two files without content hashing — WordPress's enqueue versioning
 * (LVB_VERSION) handles cache-busting.
 *
 * @package LakeVision_Booking
 * @since 2.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class LVB_Admin_Shortcode {

    const SHORTCODE      = 'lvb_admin';
    const HANDLE_JS      = 'lvb-admin-app';
    const HANDLE_CSS     = 'lvb-admin-app-css';
    const DEFAULT_SLUG   = 'verwaltung';
    const DEFAULT_TITLE  = 'Verwaltung';

    public static function register() {
        add_shortcode( self::SHORTCODE, [ __CLASS__, 'render' ] );
        add_action( 'template_redirect', [ __CLASS__, 'guard_anonymous' ] );
    }

    /**
     * If the requested page contains the [lvb_admin] shortcode and the
     * visitor is not logged in, send them to wp-login.php with a redirect
     * back to the page. Avoids the React app even loading for guests.
     */
    public static function guard_anonymous() {
        if ( is_user_logged_in() || is_admin() ) {
            return;
        }
        $post = get_post();
        if ( ! $post || ! has_shortcode( $post->post_content, self::SHORTCODE ) ) {
            return;
        }
        wp_safe_redirect( wp_login_url( get_permalink( $post ) ) );
        exit;
    }

    /**
     * Render the shortcode. For authorized users, enqueue the bundle and
     * return the root <div>. For unauthorized users, return an empty
     * string (the public booking widget shouldn't accidentally see this
     * either, so we don't leak any markup).
     */
    public static function render( $atts = [], $content = '' ) {
        if ( ! is_user_logged_in() ) {
            return '';
        }
        if ( ! self::user_has_any_lvb_cap() ) {
            // Logged in but no permission — show a polite, non-leaky notice.
            return '<div class="lvb-admin-no-access" style="padding:24px;text-align:center;color:#666;">'
                 . esc_html__( 'Du hast keine Berechtigung für diesen Bereich.', 'lakevision-booking' )
                 . '</div>';
        }

        self::enqueue_assets();

        // The id is what the React app mounts on (see assets/admin-app/src/main.tsx).
        return '<div id="lvb-admin-root" data-lvb-mount="1"></div>';
    }

    private static function user_has_any_lvb_cap() {
        foreach ( array_keys( LVB_Capabilities::CAPS ) as $cap ) {
            if ( current_user_can( $cap ) ) {
                return true;
            }
        }
        return false;
    }

    private static function enqueue_assets() {
        $base_url = LVB_PLUGIN_URL . 'assets/admin-app/dist/';
        $base_dir = LVB_PLUGIN_DIR . 'assets/admin-app/dist/';

        $js_path  = $base_dir . 'index.js';
        $css_path = $base_dir . 'index.css';

        if ( ! file_exists( $js_path ) ) {
            // Build artifact missing — render a clear hint to the operator
            // instead of a silent blank page.
            add_action( 'wp_footer', [ __CLASS__, 'render_build_missing_notice' ], 1 );
            return;
        }

        wp_enqueue_script(
            self::HANDLE_JS,
            $base_url . 'index.js',
            [],
            LVB_VERSION,
            true
        );

        if ( file_exists( $css_path ) ) {
            wp_enqueue_style(
                self::HANDLE_CSS,
                $base_url . 'index.css',
                [],
                LVB_VERSION
            );
        }

        // Bootstrap globals consumed by src/main.tsx
        wp_localize_script( self::HANDLE_JS, 'lvbAdmin', [
            'restRoot' => esc_url_raw( rest_url( LVB_Admin_API::NAMESPACE_BASE . '/' ) ),
            'nonce'    => wp_create_nonce( 'wp_rest' ),
            'basePath' => self::base_path(),
            'siteName' => get_bloginfo( 'name' ),
            'locale'   => str_replace( '_', '-', get_locale() ),
        ] );
    }

    /**
     * The path prefix the React Router treats as its base — so the app can
     * be mounted on any slug (we default to /verwaltung but the site owner
     * can rename the page).
     */
    private static function base_path() {
        $post = get_post();
        if ( ! $post ) {
            return '/' . self::DEFAULT_SLUG;
        }
        $link = get_permalink( $post );
        $path = wp_parse_url( $link, PHP_URL_PATH );
        return rtrim( $path ?: ( '/' . self::DEFAULT_SLUG ), '/' );
    }

    public static function render_build_missing_notice() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        echo '<div style="position:fixed;bottom:16px;right:16px;background:#fff3cd;border:1px solid #ffeeba;padding:12px 16px;border-radius:6px;max-width:420px;font-family:sans-serif;font-size:13px;z-index:99999;">'
           . '<strong>LakeVision Booking:</strong> Admin-Frontend-Bundle fehlt.<br>'
           . 'Build ausführen: <code>npm --prefix assets/admin-app run build</code>'
           . '</div>';
    }

    /**
     * On plugin activation, make sure a private page with the [lvb_admin]
     * shortcode exists. If the site owner deletes or trashes it, this
     * doesn't recreate it — only adds it the first time.
     *
     * If a page with the slug already exists (e.g. created by a theme
     * generator), adopt it but make sure the shortcode is present in
     * the content — otherwise the page would render blank.
     */
    public static function on_activation() {
        $existing_id = (int) get_option( 'lvb_admin_page_id', 0 );
        if ( $existing_id ) {
            $post = get_post( $existing_id );
            if ( $post ) {
                self::ensure_shortcode_in_post( $post );
                return;
            }
        }
        // Don't accidentally double-create if a previous slug is around.
        $by_slug = get_page_by_path( self::DEFAULT_SLUG, OBJECT, 'page' );
        if ( $by_slug ) {
            update_option( 'lvb_admin_page_id', (int) $by_slug->ID );
            self::ensure_shortcode_in_post( $by_slug );
            return;
        }
        $page_id = wp_insert_post( [
            'post_title'   => self::DEFAULT_TITLE,
            'post_name'    => self::DEFAULT_SLUG,
            'post_status'  => 'private',
            'post_type'    => 'page',
            'post_content' => '[' . self::SHORTCODE . ']',
        ] );
        if ( $page_id && ! is_wp_error( $page_id ) ) {
            update_option( 'lvb_admin_page_id', (int) $page_id );
        }
    }

    /**
     * Make sure the given page actually contains the [lvb_admin] shortcode.
     * If it doesn't, append it so the React app has somewhere to mount.
     * We preserve any other content the user (or a theme generator) added.
     */
    private static function ensure_shortcode_in_post( WP_Post $post ) {
        if ( has_shortcode( $post->post_content, self::SHORTCODE ) ) {
            return;
        }
        $new_content = trim( $post->post_content ) === ''
            ? '[' . self::SHORTCODE . ']'
            : $post->post_content . "\n\n[" . self::SHORTCODE . ']';
        wp_update_post( [
            'ID'           => $post->ID,
            'post_content' => $new_content,
        ] );
    }
}
