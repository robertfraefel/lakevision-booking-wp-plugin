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

            <div class="lvb-info-box" style="background:#f0f6fc;border-left:4px solid #2271b1;padding:12px 16px;margin:12px 0;">
                <p style="margin:0 0 8px 0;"><strong>Wie Verfügbarkeiten und Google Calendar zusammenspielen</strong></p>
                <p style="margin:0 0 6px 0;">
                    Es gibt <strong>zwei getrennte Fragen</strong>, die beide hier konfiguriert werden:
                </p>
                <ol style="margin:0 0 8px 18px;">
                    <li>
                        <strong>Woher kommen die Slots?</strong> (= welche Zeiten sind buchbar)<br>
                        Entweder aus <em>Google Calendar</em> (dann werden alle nicht-Booking-Events im unten
                        angegebenen Kalender als Verfügbarkeitsfenster gelesen) oder aus den
                        <em>Arbeitszeiten pro Mitarbeiter</em> (Staff → Arbeitszeiten). Sobald ein Mitarbeiter
                        Arbeitszeiten hinterlegt hat, ersetzen diese den Google-Kalender für diesen
                        Mitarbeiter – der Kalender wird dann ignoriert.
                    </li>
                    <li>
                        <strong>Wohin werden bestätigte Buchungen geschrieben?</strong><br>
                        Nur in den Google-Kalender, <em>wenn</em> (a) das Plugin verbunden ist und (b) der
                        Mitarbeiter (oder dieser Default) eine Calendar-ID hat. Sonst leben Buchungen nur in
                        der WP-Datenbank. Die DB ist die Wahrheit – Slots blockieren unabhängig davon.
                    </li>
                </ol>
                <p style="margin:0;">
                    → <strong>Kein Google nötig</strong>, wenn alle Mitarbeiter Arbeitszeiten gepflegt haben.
                    Google wird erst interessant, wenn Buchungen auch in einem externen Kalender landen
                    sollen (z.&nbsp;B. Handy-Synchronisation).
                </p>
            </div>

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

        <!-- Booking Page -->
        <div class="lvb-settings-section">
            <h2>Booking Page</h2>
            <table class="form-table">
                <tr>
                    <th><label for="lvb_booking_title">Booking Page Title</label></th>
                    <td>
                        <input type="text" id="lvb_booking_title" name="lvb_booking_title" class="regular-text"
                               value="<?php echo esc_attr( get_option( 'lvb_booking_title', 'Jetzt einen Termin vereinbaren' ) ); ?>">
                        <p class="description">Main heading above the booking widget. Leave empty to hide the header.</p>
                    </td>
                </tr>
                <tr>
                    <th><label for="lvb_booking_subtitle">Booking Page Subtitle</label></th>
                    <td>
                        <input type="text" id="lvb_booking_subtitle" name="lvb_booking_subtitle" class="regular-text"
                               value="<?php echo esc_attr( get_option( 'lvb_booking_subtitle', 'Wähle einen passenden Termin und buche direkt online.' ) ); ?>">
                        <p class="description">Subtitle shown below the main heading.</p>
                    </td>
                </tr>
                <tr>
                    <th><label for="lvb_min_advance_hours">Minimum Advance Booking</label></th>
                    <td>
                        <input type="number" id="lvb_min_advance_hours" name="lvb_min_advance_hours" class="small-text" min="0" step="1"
                               value="<?php echo esc_attr( get_option( 'lvb_min_advance_hours', '24' ) ); ?>"> Stunden
                        <p class="description">Wie viele Stunden im Voraus muss ein Termin mindestens gebucht werden. 0 = keine Einschränkung.</p>
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
                        <?php $lvb_logo_override = get_option( 'lvb_email_logo_url', '' ); ?>
                        <input type="url" id="lvb_email_logo_url" name="lvb_email_logo_url" class="large-text"
                               value="<?php echo esc_attr( $lvb_logo_override ); ?>"
                               placeholder="<?php echo esc_attr( LVB_Notifications::get_email_logo_url() ); ?>">
                        <p class="description">
                            Optional override for the email banner logo. Leave empty to auto-use the site's
                            custom logo (Appearance → Customize → Site Identity); if none is set, the site
                            icon or the plugin's default logo is used.<br>
                            <?php if ( $lvb_logo_override === '' ) : ?>
                                <strong>Currently auto-detected:</strong>
                                <code><?php echo esc_html( LVB_Notifications::get_email_logo_url() ); ?></code>
                            <?php endif; ?>
                        </p>
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
                               value="<?php echo esc_attr( get_option( 'lvb_service_label', 'Service' ) ); ?>">
                        <p class="description">Label for the service/activity selector, e.g. "Service", "Activity", "Sportart".</p>
                    </td>
                </tr>
                <tr>
                    <th><label for="lvb_slot_label">Slot Label</label></th>
                    <td>
                        <input type="text" id="lvb_slot_label" name="lvb_slot_label" class="regular-text"
                               value="<?php echo esc_attr( get_option( 'lvb_slot_label', 'Sitzung' ) ); ?>">
                        <p class="description">Label für Schritt 2 im Buchungsformular, z.B. "Sitzung", "Slot", "Zeitfenster".</p>
                    </td>
                </tr>
                <tr>
                    <th><label for="lvb_payment_title">Payment Title</label></th>
                    <td>
                        <input type="text" id="lvb_payment_title" name="lvb_payment_title" class="regular-text"
                               value="<?php echo esc_attr( get_option( 'lvb_payment_title', 'Zahlung vor Ort' ) ); ?>">
                        <p class="description">Titel über den Zahlungsarten, z.B. "Vor Ort Zahlen", "Zahlung vor Ort".</p>
                    </td>
                </tr>
                <tr>
                    <th><label for="lvb_payment_methods">Payment Methods</label></th>
                    <td>
                        <input type="text" id="lvb_payment_methods" name="lvb_payment_methods" class="regular-text"
                               value="<?php echo esc_attr( get_option( 'lvb_payment_methods', 'Twint;Bar;Debit;Credit' ) ); ?>">
                        <p class="description">Zahlungsarten getrennt durch Semikolon. Unterstützt: Twint, Bar, Debit, Credit (mit Icon). Andere werden als Text angezeigt.</p>
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
                    <th><label for="lvb_whatsapp_url">WhatsApp Gruppe URL</label></th>
                    <td>
                        <input type="url" id="lvb_whatsapp_url" name="lvb_whatsapp_url" class="large-text"
                               value="<?php echo esc_attr( get_option( 'lvb_whatsapp_url', '' ) ); ?>"
                               placeholder="https://chat.whatsapp.com/...">
                        <p class="description">Optional. If set, a WhatsApp group button is shown on the booking confirmation screen.</p>
                    </td>
                </tr>
            </table>
        </div>

        <!-- Disclaimer -->
        <div class="lvb-settings-section">
            <h2>Disclaimer</h2>
            <table class="form-table">
                <tr>
                    <th><label for="lvb_disclaimer_enabled">Enable Disclaimer</label></th>
                    <td>
                        <input type="checkbox" id="lvb_disclaimer_enabled" name="lvb_disclaimer_enabled" value="1"
                               <?php checked( 1, get_option( 'lvb_disclaimer_enabled', 1 ) ); ?>>
                        <p class="description">Show the disclaimer checkbox on the booking form. When disabled, the disclaimer is hidden and not required.</p>
                    </td>
                </tr>
                <tr>
                    <th><label for="lvb_disclaimer_text">Disclaimer Text</label></th>
                    <td>
                        <textarea id="lvb_disclaimer_text" name="lvb_disclaimer_text" class="large-text" rows="4"><?php echo esc_textarea( get_option( 'lvb_disclaimer_text', 'Ich habe den Sicherheitshinweis gelesen und akzeptiert: Unfälle sind selten, aber möglich. Die Versicherung ist Sache der Teilnehmenden. Ausreichende körperliche Fitness wird vorausgesetzt. Ich erkenne meine eigenen Grenzen und akzeptiere, dass die Crew bei Bedarf eingreifen kann.' ) ); ?></textarea>
                        <p class="description">The disclaimer text shown next to the checkbox on the booking form.</p>
                    </td>
                </tr>
            </table>
        </div>

        <!-- Accent Colors -->
        <div class="lvb-settings-section">
            <h2>Accent Colors</h2>
            <p class="description">Override the default accent colors used in the booking widget.</p>
            <table class="form-table">
                <tr>
                    <th><label for="lvb_theme_inherit">Inherit Site Theme</label></th>
                    <td>
                        <input type="checkbox" id="lvb_theme_inherit" name="lvb_theme_inherit" value="1"
                               <?php checked( 1, get_option( 'lvb_theme_inherit', 0 ) ); ?>>
                        <label for="lvb_theme_inherit">Blend into the active page theme</label>
                        <p class="description">
                            When enabled, the widget drops its own background/text colors and inherits
                            them from the surrounding page — only the accent color below is applied.
                            Useful when embedding on a light theme. Can also be enabled per-instance
                            via <code>[lvb_booking theme="inherit"]</code>.
                        </p>
                    </td>
                </tr>
                <tr>
                    <th><label for="lvb_accent_color">Primary Accent Color</label></th>
                    <td>
                        <input type="color" id="lvb_accent_color" name="lvb_accent_color"
                               value="<?php echo esc_attr( get_option( 'lvb_accent_color', '#00F5C4' ) ); ?>">
                        <code><?php echo esc_html( get_option( 'lvb_accent_color', '#00F5C4' ) ); ?></code>
                        <p class="description">Default: <code>#00F5C4</code></p>
                    </td>
                </tr>
                <tr>
                    <th><label for="lvb_accent2_color">Secondary Accent Color</label></th>
                    <td>
                        <input type="color" id="lvb_accent2_color" name="lvb_accent2_color"
                               value="<?php echo esc_attr( get_option( 'lvb_accent2_color', '#00C2FF' ) ); ?>">
                        <code><?php echo esc_html( get_option( 'lvb_accent2_color', '#00C2FF' ) ); ?></code>
                        <p class="description">Default: <code>#00C2FF</code></p>
                    </td>
                </tr>
                <tr>
                    <th><label for="lvb_dark_color">Header/Dark Background</label></th>
                    <td>
                        <input type="color" id="lvb_dark_color" name="lvb_dark_color"
                               value="<?php echo esc_attr( get_option( 'lvb_dark_color', '#1E1C19' ) ); ?>">
                        <code><?php echo esc_html( get_option( 'lvb_dark_color', '#1E1C19' ) ); ?></code>
                        <p class="description">Used for email header background. Default: <code>#1E1C19</code></p>
                    </td>
                </tr>
                <tr>
                    <th><label for="lvb_bg_color">Body Background</label></th>
                    <td>
                        <input type="color" id="lvb_bg_color" name="lvb_bg_color"
                               value="<?php echo esc_attr( get_option( 'lvb_bg_color', '#FAF7F2' ) ); ?>">
                        <code><?php echo esc_html( get_option( 'lvb_bg_color', '#FAF7F2' ) ); ?></code>
                        <p class="description">Used for email body background. Default: <code>#FAF7F2</code></p>
                    </td>
                </tr>
                <tr>
                    <th><label for="lvb_footer_bg_color">Footer Background</label></th>
                    <td>
                        <input type="color" id="lvb_footer_bg_color" name="lvb_footer_bg_color"
                               value="<?php echo esc_attr( get_option( 'lvb_footer_bg_color', '#F2EDE5' ) ); ?>">
                        <code><?php echo esc_html( get_option( 'lvb_footer_bg_color', '#F2EDE5' ) ); ?></code>
                        <p class="description">Used for email footer background. Default: <code>#F2EDE5</code></p>
                    </td>
                </tr>
                <tr>
                    <th><label for="lvb_text_color">Text Color</label></th>
                    <td>
                        <input type="color" id="lvb_text_color" name="lvb_text_color"
                               value="<?php echo esc_attr( get_option( 'lvb_text_color', '#1A2332' ) ); ?>">
                        <code><?php echo esc_html( get_option( 'lvb_text_color', '#1A2332' ) ); ?></code>
                        <p class="description">Used for email body text. Default: <code>#1A2332</code></p>
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
