<?php
/**
 * Admin partial – Settings page (Google OAuth + general config).
 */
if ( ! defined( 'ABSPATH' ) ) exit;
if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Unauthorised.' );

$is_connected  = LVB_Google_Calendar::is_connected();
$callback_url  = LVB_Google_Calendar::callback_url();
$connect_url   = LVB_Google_Calendar::get_auth_url();
$disconnect_url = wp_nonce_url(
    add_query_arg( [ 'page' => 'lvb-settings', 'lvb_action' => 'disconnect_google' ], admin_url( 'admin.php' ) ),
    'lvb_disconnect_google'
);
?>
<div class="wrap lvb-wrap">
    <h1>LakeVision Booking – Settings</h1>
    <hr class="wp-header-end">

    <form method="post">
        <?php wp_nonce_field( 'lvb_settings_save' ); ?>

        <!-- Google Calendar Section -->
        <div class="lvb-settings-section">
            <h2>Google Calendar Integration</h2>

            <div class="lvb-google-status <?php echo $is_connected ? 'connected' : 'disconnected'; ?>">
                <?php if ( $is_connected ) : ?>
                    <span class="dashicons dashicons-yes-alt"></span>
                    <strong>Connected to Google Calendar</strong>
                    <a href="<?php echo esc_url( $disconnect_url ); ?>" class="button lvb-btn-danger lvb-disconnect-btn"
                       onclick="return confirm('Disconnect Google Calendar? Existing bookings will be unaffected.');">
                        Disconnect
                    </a>
                <?php else : ?>
                    <span class="dashicons dashicons-warning"></span>
                    <strong>Not connected.</strong> Enter your credentials below and click Connect.
                <?php endif; ?>
            </div>

            <table class="form-table">
                <tr>
                    <th><label for="lvb_google_client_id">Client ID</label></th>
                    <td>
                        <input type="text" id="lvb_google_client_id" name="lvb_google_client_id" class="regular-text"
                               value="<?php echo esc_attr( get_option( 'lvb_google_client_id', '' ) ); ?>">
                        <p class="description">From your Google Cloud Console → Credentials → OAuth 2.0 Client IDs.</p>
                    </td>
                </tr>
                <tr>
                    <th><label for="lvb_google_client_secret">Client Secret</label></th>
                    <td>
                        <input type="password" id="lvb_google_client_secret" name="lvb_google_client_secret" class="regular-text"
                               value="<?php echo esc_attr( get_option( 'lvb_google_client_secret', '' ) ); ?>">
                    </td>
                </tr>
                <tr>
                    <th><label for="lvb_google_default_calendar_id">Default Calendar ID</label></th>
                    <td>
                        <input type="text" id="lvb_google_default_calendar_id" name="lvb_google_default_calendar_id" class="regular-text"
                               value="<?php echo esc_attr( get_option( 'lvb_google_default_calendar_id', '' ) ); ?>"
                               placeholder="primary  or  name@group.calendar.google.com">
                        <p class="description">The Google Calendar from which availability slots are read. Can be overridden per staff member.</p>
                    </td>
                </tr>
                <tr>
                    <th>OAuth Redirect URI</th>
                    <td>
                        <code class="lvb-code-block"><?php echo esc_url( $callback_url ); ?></code>
                        <p class="description">Add this exact URI as an Authorised Redirect URI in your Google Cloud Console OAuth credentials.</p>
                    </td>
                </tr>
            </table>

            <?php if ( ! $is_connected ) : ?>
                <p>
                    <?php submit_button( 'Save Credentials', 'secondary', 'lvb_save_settings', false ); ?>
                    &nbsp;
                    <a href="<?php echo esc_url( $connect_url ); ?>" class="button button-primary lvb-connect-btn">
                        Connect Google Calendar
                    </a>
                </p>
                <p class="description">
                    <strong>Note:</strong> Save your credentials first, then click "Connect Google Calendar".
                </p>
            <?php endif; ?>
        </div>

        <!-- General Settings -->
        <div class="lvb-settings-section">
            <h2>General Settings</h2>
            <table class="form-table">
                <tr>
                    <th><label for="lvb_currency_symbol">Currency Symbol</label></th>
                    <td>
                        <input type="text" id="lvb_currency_symbol" name="lvb_currency_symbol" class="small-text"
                               value="<?php echo esc_attr( get_option( 'lvb_currency_symbol', '$' ) ); ?>">
                    </td>
                </tr>
                <tr>
                    <th><label for="lvb_admin_notification_email">Admin Notification Email</label></th>
                    <td>
                        <input type="email" id="lvb_admin_notification_email" name="lvb_admin_notification_email" class="regular-text"
                               value="<?php echo esc_attr( get_option( 'lvb_admin_notification_email', get_option( 'admin_email' ) ) ); ?>">
                        <p class="description">New booking alerts are sent to this address.</p>
                    </td>
                </tr>
            </table>
        </div>

        <!-- Email Settings -->
        <div class="lvb-settings-section">
            <h2>Email Settings</h2>
            <table class="form-table">
                <tr>
                    <th><label for="lvb_email_from">From Name</label></th>
                    <td>
                        <input type="text" id="lvb_email_from" name="lvb_email_from" class="regular-text"
                               value="<?php echo esc_attr( get_option( 'lvb_email_from', get_bloginfo( 'name' ) ) ); ?>">
                    </td>
                </tr>
                <tr>
                    <th><label for="lvb_email_from_address">From Email Address</label></th>
                    <td>
                        <input type="email" id="lvb_email_from_address" name="lvb_email_from_address" class="regular-text"
                               value="<?php echo esc_attr( get_option( 'lvb_email_from_address', get_option( 'admin_email' ) ) ); ?>">
                    </td>
                </tr>
            </table>
        </div>

        <!-- Branding & Customization -->
        <div class="lvb-settings-section">
            <h2>Branding &amp; Customization</h2>
            <table class="form-table">
                <tr>
                    <th><label for="lvb_email_logo_url">Email Logo URL</label></th>
                    <td>
                        <input type="url" id="lvb_email_logo_url" name="lvb_email_logo_url" class="large-text"
                               value="<?php echo esc_attr( get_option( 'lvb_email_logo_url', plugins_url( 'assets/img/logo.svg', LVB_PLUGIN_FILE ) ) ); ?>">
                        <p class="description">Logo shown in booking confirmation emails. Leave as default to use the plugin's built-in logo.</p>
                    </td>
                </tr>
                <tr>
                    <th><label for="lvb_staff_label">Staff Label</label></th>
                    <td>
                        <input type="text" id="lvb_staff_label" name="lvb_staff_label" class="regular-text"
                               value="<?php echo esc_attr( get_option( 'lvb_staff_label', 'Instructor' ) ); ?>">
                        <p class="description">Label used for staff in emails, e.g. "Instructor", "Coach", "Guide".</p>
                    </td>
                </tr>
                <tr>
                    <th><label for="lvb_service_label">Service Label</label></th>
                    <td>
                        <input type="text" id="lvb_service_label" name="lvb_service_label" class="regular-text"
                               value="<?php echo esc_attr( get_option( 'lvb_service_label', 'Sportart' ) ); ?>">
                        <p class="description">Label for the service/activity selector, e.g. "Service", "Activity", "Sportart".</p>
                    </td>
                </tr>
                <tr>
                    <th><label for="lvb_email_confirmation_text">Confirmation Email Text</label></th>
                    <td>
                        <textarea id="lvb_email_confirmation_text" name="lvb_email_confirmation_text" class="large-text" rows="3"><?php echo esc_textarea( get_option( 'lvb_email_confirmation_text', 'Your booking is confirmed. We look forward to seeing you!' ) ); ?></textarea>
                        <p class="description">Body text in the customer confirmation email.</p>
                    </td>
                </tr>
                <tr>
                    <th><label for="lvb_whatsapp_url">WhatsApp Channel URL</label></th>
                    <td>
                        <input type="url" id="lvb_whatsapp_url" name="lvb_whatsapp_url" class="large-text"
                               value="<?php echo esc_attr( get_option( 'lvb_whatsapp_url', '' ) ); ?>"
                               placeholder="https://whatsapp.com/channel/...">
                        <p class="description">Optional. If set, a WhatsApp follow button is shown on the booking confirmation screen.</p>
                    </td>
                </tr>
            </table>
        </div>

        <!-- Email Reminder -->
        <div class="lvb-settings-section">
            <h2>Email Reminder</h2>
            <table class="form-table">
                <tr>
                    <th><label for="lvb_reminder_enabled">Erinnerung aktivieren</label></th>
                    <td>
                        <input type="checkbox" id="lvb_reminder_enabled" name="lvb_reminder_enabled" value="1"
                               <?php checked( 1, get_option( 'lvb_reminder_enabled', 0 ) ); ?>>
                        <p class="description">Sendet dem Kunden automatisch eine Erinnerungs-E-Mail vor dem Termin.</p>
                    </td>
                </tr>
                <tr>
                    <th><label for="lvb_reminder_hours">Erinnerung senden</label></th>
                    <td>
                        <input type="number" id="lvb_reminder_hours" name="lvb_reminder_hours" class="small-text"
                               min="1" max="168"
                               value="<?php echo esc_attr( get_option( 'lvb_reminder_hours', 24 ) ); ?>">
                        <span> Stunden vor dem Termin</span>
                        <p class="description">Z.B. 24 = einen Tag vorher, 48 = zwei Tage vorher.</p>
                    </td>
                </tr>
            </table>
        </div>

        <!-- Google Reviews -->
        <div class="lvb-settings-section">
            <h2>Google Reviews</h2>
            <table class="form-table">
                <tr>
                    <th><label for="lvb_places_api_key">Places API Key</label></th>
                    <td>
                        <input type="text" id="lvb_places_api_key" name="lvb_places_api_key" class="regular-text"
                               value="<?php echo esc_attr( get_option( 'lvb_places_api_key', '' ) ); ?>">
                        <p class="description">Google Cloud Console → APIs &amp; Services → Credentials → API Key. Places API muss aktiviert sein.</p>
                    </td>
                </tr>
                <tr>
                    <th><label for="lvb_place_id">Place ID</label></th>
                    <td>
                        <input type="text" id="lvb_place_id" name="lvb_place_id" class="regular-text"
                               value="<?php echo esc_attr( get_option( 'lvb_place_id', '' ) ); ?>"
                               placeholder="ChIJ...">
                        <p class="description">Zu finden auf Google Maps → rechtsklick auf den Ort → "Place ID kopieren".</p>
                    </td>
                </tr>
                <tr>
                    <th>Cache</th>
                    <td>
                        <label>
                            <input type="checkbox" name="lvb_clear_reviews_cache" value="1">
                            Reviews-Cache leeren (wird beim nächsten Seitenaufruf neu geladen)
                        </label>
                    </td>
                </tr>
            </table>
            <p class="description">Shortcode zum Einbinden der Reviews: <code>[lvb_reviews]</code></p>
        </div>

        <!-- Shortcode Help -->
        <div class="lvb-settings-section lvb-help-section">
            <h2>Shortcode Usage</h2>
            <p>Place the booking calendar anywhere on your site:</p>
            <code class="lvb-code-block">[lvb_booking]</code>
            <p>Optional attributes:</p>
            <code class="lvb-code-block">[lvb_booking service_id="1" staff_id="2"]</code>
            <ul>
                <li><strong>service_id</strong> – pre-select a specific service</li>
                <li><strong>staff_id</strong> – pre-select a specific staff member</li>
            </ul>
        </div>

        <?php submit_button( 'Save Settings', 'primary', 'lvb_save_settings' ); ?>
    </form>
</div>
