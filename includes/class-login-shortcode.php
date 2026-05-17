<?php
/**
 * LVB_Login_Shortcode – branded login page for the [lvb_admin] frontend.
 *
 * Renders a stand-alone login form that delegates to wp_signon() under the
 * hood. Lives at a public page (default /anmelden) and is the redirect
 * target for the [lvb_admin] guard when an anonymous visitor hits the
 * admin URL. Authenticated users get redirected straight to /verwaltung.
 *
 * The form is plain PHP/HTML — no JS bundle dependency — so the login
 * page loads instantly even when the admin app's chunks haven't been
 * downloaded yet.
 *
 * @package LakeVision_Booking
 * @since 2.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class LVB_Login_Shortcode {

    const SHORTCODE     = 'lvb_login';
    const DEFAULT_SLUG  = 'anmelden';
    const DEFAULT_TITLE = 'Anmelden';
    const NONCE_ACTION  = 'lvb_login';

    public static function register() {
        add_shortcode( self::SHORTCODE, [ __CLASS__, 'render' ] );
        add_action( 'template_redirect', [ __CLASS__, 'maybe_handle_post' ], 5 );
    }

    /**
     * If the login form was submitted, run wp_signon() before any output
     * starts (so we can set auth cookies cleanly and redirect on success).
     */
    public static function maybe_handle_post() {
        if ( ! isset( $_POST['lvb_login_submit'] ) ) {
            return;
        }
        $post = get_post();
        if ( ! $post || ! has_shortcode( (string) $post->post_content, self::SHORTCODE ) ) {
            return;
        }
        if ( ! isset( $_POST['_lvb_login_nonce'] )
             || ! wp_verify_nonce( $_POST['_lvb_login_nonce'], self::NONCE_ACTION ) ) {
            self::set_error( 'invalid_nonce' );
            return;
        }
        $creds = [
            'user_login'    => sanitize_user( wp_unslash( $_POST['log'] ?? '' ) ),
            'user_password' => (string) ( $_POST['pwd'] ?? '' ),
            'remember'      => ! empty( $_POST['rememberme'] ),
        ];
        $user = wp_signon( $creds, is_ssl() );
        if ( is_wp_error( $user ) ) {
            self::set_error( $user->get_error_code() );
            return;
        }
        wp_set_current_user( $user->ID );
        $target = self::resolve_redirect( $post );
        wp_safe_redirect( $target );
        exit;
    }

    public static function render( $atts = [], $content = '' ) {
        if ( is_user_logged_in() ) {
            $post = get_post();
            $target = self::resolve_redirect( $post );
            // Use a meta refresh fallback so the markup still renders for
            // edge cases where headers were already sent (e.g. inside the
            // editor preview).
            return '<div class="lvb-login-already">'
                 . '<meta http-equiv="refresh" content="0;url=' . esc_url( $target ) . '">'
                 . esc_html__( 'Du bist bereits angemeldet. Weiterleitung…', 'lakevision-booking' )
                 . ' <a href="' . esc_url( $target ) . '">' . esc_html__( 'hier klicken', 'lakevision-booking' ) . '</a>'
                 . '</div>';
        }

        $error_code = self::get_error();
        $error_msg  = $error_code ? self::error_message( $error_code ) : '';

        $logo_url = method_exists( 'LVB_Notifications', 'get_email_logo_url' )
            ? LVB_Notifications::get_email_logo_url()
            : '';
        $site = get_bloginfo( 'name' );

        ob_start();
        ?>
        <div class="lvb-login-shell" style="min-height:70vh;display:flex;align-items:center;justify-content:center;padding:24px;font-family:ui-sans-serif,system-ui,-apple-system,'Segoe UI',Roboto,sans-serif;">
          <form method="post" class="lvb-login-card" style="width:100%;max-width:380px;background:#ffffff;border:1px solid #e5e7eb;border-radius:12px;padding:28px;box-shadow:0 4px 18px rgba(0,0,0,0.06);">
            <?php if ( $logo_url ) : ?>
              <div style="text-align:center;margin-bottom:18px;">
                <img src="<?php echo esc_url( $logo_url ); ?>" alt="<?php echo esc_attr( $site ); ?>" style="max-width:180px;height:auto;display:inline-block;">
              </div>
            <?php else : ?>
              <h1 style="font-size:20px;font-weight:600;text-align:center;margin:0 0 18px;color:#111827;"><?php echo esc_html( $site ); ?></h1>
            <?php endif; ?>

            <h2 style="font-size:16px;font-weight:500;text-align:center;margin:0 0 20px;color:#374151;">Anmelden</h2>

            <?php if ( $error_msg ) : ?>
              <div role="alert" style="background:#fef2f2;border:1px solid #fecaca;color:#991b1b;padding:10px 12px;border-radius:8px;font-size:13px;margin-bottom:16px;">
                <?php echo esc_html( $error_msg ); ?>
              </div>
            <?php endif; ?>

            <label for="lvb_login_log" style="display:block;font-size:12px;color:#6b7280;margin-bottom:4px;">Benutzername oder E-Mail</label>
            <input id="lvb_login_log" type="text" name="log" autocomplete="username" required autofocus
                   style="width:100%;padding:9px 12px;border:1px solid #d1d5db;border-radius:8px;font-size:14px;margin-bottom:14px;">

            <label for="lvb_login_pwd" style="display:block;font-size:12px;color:#6b7280;margin-bottom:4px;">Passwort</label>
            <input id="lvb_login_pwd" type="password" name="pwd" autocomplete="current-password" required
                   style="width:100%;padding:9px 12px;border:1px solid #d1d5db;border-radius:8px;font-size:14px;margin-bottom:14px;">

            <label style="display:flex;align-items:center;gap:8px;font-size:13px;color:#374151;margin-bottom:18px;cursor:pointer;">
              <input type="checkbox" name="rememberme" value="1" checked style="width:14px;height:14px;">
              Angemeldet bleiben
            </label>

            <button type="submit" name="lvb_login_submit" value="1"
                    style="width:100%;padding:10px 14px;background:#0284c7;color:#fff;border:none;border-radius:8px;font-size:14px;font-weight:600;cursor:pointer;">
              Anmelden
            </button>

            <?php wp_nonce_field( self::NONCE_ACTION, '_lvb_login_nonce' ); ?>
            <?php if ( ! empty( $_GET['redirect_to'] ) ) : ?>
              <input type="hidden" name="redirect_to" value="<?php echo esc_attr( wp_unslash( $_GET['redirect_to'] ) ); ?>">
            <?php endif; ?>

            <div style="margin-top:18px;text-align:center;font-size:12px;color:#6b7280;">
              <a href="<?php echo esc_url( wp_lostpassword_url() ); ?>" style="color:#0284c7;text-decoration:none;">
                Passwort vergessen?
              </a>
            </div>
          </form>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Decide where to send the user after a successful login.
     * Priority: ?redirect_to= → POST redirect_to → /verwaltung → home.
     */
    private static function resolve_redirect( $login_post = null ) {
        $candidate = '';
        if ( ! empty( $_REQUEST['redirect_to'] ) ) {
            $candidate = (string) wp_unslash( $_REQUEST['redirect_to'] );
        }
        if ( $candidate ) {
            $safe = wp_validate_redirect( $candidate, '' );
            if ( $safe ) {
                return $safe;
            }
        }
        $admin_page_id = (int) get_option( 'lvb_admin_page_id', 0 );
        if ( $admin_page_id ) {
            $url = get_permalink( $admin_page_id );
            if ( $url ) {
                return $url;
            }
        }
        return home_url( '/' );
    }

    /**
     * Stash a one-shot login error in a transient keyed to the visitor's
     * session so the error survives the post-redirect-get pattern without
     * needing query strings (and without using add_query_arg, which can
     * collide with theme caching plugins).
     */
    private static function set_error( $code ) {
        $key = self::transient_key();
        if ( $key ) {
            set_transient( $key, sanitize_text_field( $code ), 60 );
        }
    }

    private static function get_error() {
        $key = self::transient_key();
        if ( ! $key ) {
            return '';
        }
        $code = get_transient( $key );
        if ( $code ) {
            delete_transient( $key );
            return (string) $code;
        }
        return '';
    }

    private static function transient_key() {
        $sid = isset( $_COOKIE[ self::cookie_name() ] )
            ? sanitize_text_field( wp_unslash( $_COOKIE[ self::cookie_name() ] ) )
            : '';
        if ( $sid === '' ) {
            $sid = wp_generate_password( 16, false, false );
            // Cookie lives just long enough to survive the form post-redirect.
            setcookie( self::cookie_name(), $sid, time() + 120, COOKIEPATH ?: '/', COOKIE_DOMAIN, is_ssl(), true );
        }
        return 'lvb_login_err_' . md5( $sid );
    }

    private static function cookie_name() {
        return 'lvb_login_sid';
    }

    private static function error_message( $code ) {
        switch ( $code ) {
            case 'invalid_username':
            case 'invalid_email':
                return 'Benutzername oder E-Mail unbekannt.';
            case 'incorrect_password':
                return 'Passwort ist falsch.';
            case 'empty_username':
            case 'empty_password':
                return 'Bitte beide Felder ausfüllen.';
            case 'invalid_nonce':
                return 'Sitzung abgelaufen — bitte erneut versuchen.';
            default:
                return 'Anmeldung fehlgeschlagen. Bitte erneut versuchen.';
        }
    }

    /**
     * Activation-time setup: create the login page if missing, and make
     * sure the shortcode is in its body. Mirrors LVB_Admin_Shortcode.
     */
    public static function on_activation() {
        $existing_id = (int) get_option( 'lvb_login_page_id', 0 );
        if ( $existing_id ) {
            $post = get_post( $existing_id );
            if ( $post ) {
                self::ensure_shortcode_in_post( $post );
                return;
            }
        }
        $by_slug = get_page_by_path( self::DEFAULT_SLUG, OBJECT, 'page' );
        if ( $by_slug ) {
            update_option( 'lvb_login_page_id', (int) $by_slug->ID );
            self::ensure_shortcode_in_post( $by_slug );
            return;
        }
        $page_id = wp_insert_post( [
            'post_title'   => self::DEFAULT_TITLE,
            'post_name'    => self::DEFAULT_SLUG,
            'post_status'  => 'publish',
            'post_type'    => 'page',
            'post_content' => '[' . self::SHORTCODE . ']',
        ] );
        if ( $page_id && ! is_wp_error( $page_id ) ) {
            update_option( 'lvb_login_page_id', (int) $page_id );
        }
    }

    private static function ensure_shortcode_in_post( WP_Post $post ) {
        if ( has_shortcode( (string) $post->post_content, self::SHORTCODE ) ) {
            return;
        }
        $new_content = trim( (string) $post->post_content ) === ''
            ? '[' . self::SHORTCODE . ']'
            : $post->post_content . "\n\n[" . self::SHORTCODE . ']';
        wp_update_post( [
            'ID'           => $post->ID,
            'post_content' => $new_content,
        ] );
    }

    /**
     * Public helper used by other shortcodes (notably LVB_Admin_Shortcode)
     * to redirect anonymous visitors to the branded login page rather than
     * wp-login.php.
     */
    public static function login_url( $redirect_to = '' ) {
        $page_id = (int) get_option( 'lvb_login_page_id', 0 );
        $base    = $page_id ? get_permalink( $page_id ) : '';
        if ( ! $base ) {
            return wp_login_url( $redirect_to );
        }
        if ( $redirect_to ) {
            $base = add_query_arg( 'redirect_to', rawurlencode( $redirect_to ), $base );
        }
        return $base;
    }
}
