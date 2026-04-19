<?php
/**
 * LVB_Shortcode – frontend booking calendar & form.
 *
 * Registers and renders the [lvb_booking] shortcode and handles all related
 * AJAX endpoints called by the booking.js frontend script.
 *
 * Shortcode usage:
 *   [lvb_booking]
 *   [lvb_booking service_id="3"]          – Pre-select a service.
 *   [lvb_booking service_id="3" staff_id="1"] – Pre-select service and staff.
 *
 * The rendered widget is a four-step booking flow:
 *   Step 1 – Month calendar: highlights days that have available slots.
 *   Step 2 – Time slot list for the selected day, filtered by service duration.
 *   Step 3 – Customer contact form with disclaimer checkbox.
 *   Step 4 – Success / confirmation screen.
 *
 * AJAX actions (available to both authenticated and guest users):
 *   lvb_get_slots           – Return available Google Calendar slots for a date range.
 *   lvb_submit_booking      – Validate and create a new booking.
 *   lvb_get_staff_for_service – Return active staff members for a given service.
 *
 * @package LakeVision_Booking
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Renders the frontend booking widget shortcode and handles its AJAX requests.
 *
 * @package LakeVision_Booking
 */
class LVB_Shortcode {

    /**
     * Register the [lvb_booking] shortcode with WordPress.
     *
     * Should be called on the `init` action (after WordPress is fully loaded).
     *
     * @return void
     */
    public static function register() {
        add_shortcode( 'lvb_booking', [ __CLASS__, 'render' ] );
    }

    // -----------------------------------------------------------------------
    // Shortcode output
    // -----------------------------------------------------------------------

    /**
     * Render the [lvb_booking] shortcode output.
     *
     * Outputs the full four-step booking widget HTML. Active services are
     * loaded from the database and injected into the service dropdown (Step 2)
     * at render time to avoid an extra AJAX request.
     *
     * @param array $atts {
     *     Shortcode attributes.
     *
     *     @type string $service_id  Optional service ID to pre-select in the
     *                               service dropdown. Empty string = no pre-selection.
     *     @type string $staff_id    Optional staff ID to pre-select. Forwarded to JS
     *                               for slot filtering. Empty string = no pre-selection.
     * }
     * @return string  The shortcode HTML output (captured via output buffering).
     */
    public static function render( $atts ) {
        $atts = shortcode_atts( [
            'service_id' => '',
            'staff_id'   => '',
            // 'inherit' = blend into the active page theme (transparent
            // background, inherited text color). Empty = use plugin defaults.
            'theme'      => '',
        ], $atts, 'lvb_booking' );

        // Theme-inherit is active when either the shortcode attr requests it
        // or the site-wide option is enabled. The shortcode attr wins if set.
        $theme_attr     = strtolower( trim( (string) $atts['theme'] ) );
        $theme_inherit  = $theme_attr === 'inherit'
            || ( $theme_attr === '' && (int) get_option( 'lvb_theme_inherit', 0 ) === 1 );
        $app_classes    = 'lvb-booking-app' . ( $theme_inherit ? ' lvb-theme-inherit' : '' );

        // Enqueue assets – they were registered on wp_enqueue_scripts.
        wp_enqueue_style( 'lvb-booking' );
        wp_enqueue_script( 'lvb-booking' );

        // Output accent color overrides if configured. The second stop
        // defaults to empty so --lvb-accent2 inherits from --lvb-accent
        // (see booking.css), which gives a clean solid look when only one
        // accent is provided.
        $accent  = trim( (string) get_option( 'lvb_accent_color', '' ) );
        $accent2 = trim( (string) get_option( 'lvb_accent2_color', '' ) );
        $overrides = '';
        if ( $accent !== '' ) {
            $overrides .= '--lvb-accent:' . esc_attr( $accent ) . ';';
        }
        if ( $accent2 !== '' ) {
            $overrides .= '--lvb-accent2:' . esc_attr( $accent2 ) . ';';
        }
        if ( $overrides !== '' ) {
            wp_add_inline_style( 'lvb-booking', '#lvb-booking-app{' . $overrides . '}' );
        }

        // Pre-load active services for the dropdown
        $services      = LVB_Database::get_all( 'services', [ 'status' => 'active' ], 'sort_order ASC, name ASC' );
        $service_label = get_option( 'lvb_service_label', 'Service' );

        // Pre-load active staff. The picker is rendered unconditionally but
        // JS hides it when there are 0 or 1 active staff (the single-staff
        // case auto-assigns without a UI).
        $staff_members = LVB_Database::get_all( 'staff', [ 'status' => 'active' ], 'name ASC' );
        $staff_label   = get_option( 'lvb_staff_label', __( 'Mitarbeiter:in', 'lakevision-booking' ) );
        $staff_any     = get_option( 'lvb_staff_any_label', __( 'Nächster freier Platz', 'lakevision-booking' ) );

        ob_start();
        ?>
        <div id="lvb-booking-app" class="<?php echo esc_attr( $app_classes ); ?>"
             data-service="<?php echo esc_attr( $atts['service_id'] ); ?>"
             data-staff="<?php echo esc_attr( $atts['staff_id'] ); ?>"
             data-staff-count="<?php echo esc_attr( count( $staff_members ) ); ?>">

            <?php
            $booking_title    = get_option( 'lvb_booking_title', 'Jetzt einen Termin vereinbaren' );
            $booking_subtitle = get_option( 'lvb_booking_subtitle', 'Wähle einen passenden Termin und buche direkt online.' );
            if ( $booking_title !== '' ) : ?>
            <div class="lvb-booking-header" style="text-align:center;margin-bottom:24px;">
                <h2 style="font-family:inherit;font-size:28px;font-weight:700;margin:0 0 8px;"><?php echo esc_html( $booking_title ); ?></h2>
                <?php if ( $booking_subtitle !== '' ) : ?>
                <p style="font-family:inherit;font-size:15px;color:var(--lvb-muted);margin:0;"><?php echo esc_html( $booking_subtitle ); ?></p>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <!-- Step indicator -->
            <div class="lvb-steps">
                <div class="lvb-step active" data-step="1"><span>1</span> Datum wählen</div>
                <div class="lvb-step" data-step="2"><span>2</span> <?php echo esc_html( get_option( 'lvb_slot_label', 'Sitzung' ) ); ?> wählen</div>
                <div class="lvb-step" data-step="3"><span>3</span> Deine Angaben</div>
                <div class="lvb-step" data-step="4"><span>4</span> Bestätigt</div>
            </div>

            <!-- Step 1: Calendar -->
            <div class="lvb-panel active" id="lvb-step-1">
                <div class="lvb-calendar-header">
                    <button class="lvb-btn-nav" id="lvb-prev-month" aria-label="Vorheriger Monat">&#8249;</button>
                    <h3 id="lvb-month-label" class="lvb-month-label"></h3>
                    <button class="lvb-btn-nav" id="lvb-next-month" aria-label="Nächster Monat">&#8250;</button>
                </div>
                <div class="lvb-calendar-grid" id="lvb-calendar-grid">
                    <div class="lvb-cal-loading">Kalender wird geladen…</div>
                </div>
                <div class="lvb-legend">
                    <span class="lvb-legend-dot available"></span> Verfügbar
                    <span class="lvb-legend-dot unavailable"></span> Nicht verfügbar
                </div>
            </div>

            <!-- Step 2: Time slots -->
            <div class="lvb-panel" id="lvb-step-2">
                <div class="lvb-step2-header">
                    <button class="lvb-back" data-target="1">&#8592; Zurück</button>
                    <h3 id="lvb-selected-date-label" class="lvb-date-label"></h3>
                </div>
                <?php if ( count( $staff_members ) > 0 ) : ?>
                <div class="lvb-form-group lvb-staff-picker" id="lvb-staff-wrap" hidden>
                    <label for="lvb-staff-select" class="lvb-label"><?php echo esc_html( $staff_label ); ?></label>
                    <select id="lvb-staff-select" class="lvb-select">
                        <option value="0">— <?php echo esc_html( $staff_any ); ?> —</option>
                        <?php foreach ( $staff_members as $st ) : ?>
                            <option value="<?php echo esc_attr( $st['id'] ); ?>"
                                    <?php selected( $atts['staff_id'], $st['id'] ); ?>>
                                <?php echo esc_html( $st['name'] ); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>
                <div class="lvb-form-group lvb-service-picker">
                    <label for="lvb-service-select" class="lvb-label"><?php echo esc_html( $service_label ); ?> <span class="req">*</span></label>
                    <select id="lvb-service-select" class="lvb-select" required>
                        <option value="">— <?php echo esc_html( $service_label ); ?> wählen —</option>
                        <?php foreach ( $services as $svc ) : ?>
                            <option value="<?php echo esc_attr( $svc['id'] ); ?>"
                                    data-duration="<?php echo esc_attr( $svc['duration'] ); ?>"
                                    data-buffer="<?php echo esc_attr( (int) $svc['buffer_time'] ); ?>"
                                    <?php selected( $atts['service_id'], $svc['id'] ); ?>>
                                <?php echo esc_html( $svc['name'] ); ?>
                                (<?php echo esc_html( $svc['duration'] ); ?> Min.
                                <?php echo $svc['price'] > 0 ? '– CHF ' . number_format( (float)$svc['price'], 2 ) : ''; ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div id="lvb-slots-container" class="lvb-slots-container">
                    <p class="lvb-no-slots">Bitte wähle zuerst eine <?php echo esc_html( $service_label ); ?> aus.</p>
                </div>
            </div>

            <!-- Step 3: Customer form -->
            <div class="lvb-panel" id="lvb-step-3">
                <div class="lvb-step2-header">
                    <button class="lvb-back" data-target="2">&#8592; Zurück</button>
                    <h3 class="lvb-date-label">Deine Angaben</h3>
                </div>
                <div class="lvb-booking-summary" id="lvb-booking-summary"></div>
                <form id="lvb-booking-form" class="lvb-form" novalidate>
                    <?php wp_nonce_field( 'lvb_booking_nonce', 'lvb_nonce' ); ?>
                    <input type="hidden" id="lvb-slot-event-id"    name="slot_event_id"   value="">
                    <input type="hidden" id="lvb-start-datetime"   name="start_datetime"  value="">
                    <input type="hidden" id="lvb-slot-win-end"     name="slot_win_end"    value="">
                    <input type="hidden" id="lvb-service-id-input" name="service_id"      value="">
                    <input type="hidden" id="lvb-staff-id-input"   name="staff_id"        value="">

                    <div class="lvb-form-row">
                        <div class="lvb-form-group">
                            <label for="lvb-first-name" class="lvb-label">Vorname <span class="req">*</span></label>
                            <input type="text" id="lvb-first-name" name="first_name" class="lvb-input" required autocomplete="given-name">
                        </div>
                        <div class="lvb-form-group">
                            <label for="lvb-last-name" class="lvb-label">Nachname <span class="req">*</span></label>
                            <input type="text" id="lvb-last-name" name="last_name" class="lvb-input" required autocomplete="family-name">
                        </div>
                    </div>
                    <div class="lvb-form-row">
                        <div class="lvb-form-group">
                            <label for="lvb-email" class="lvb-label">E-Mail <span class="req">*</span></label>
                            <input type="email" id="lvb-email" name="email" class="lvb-input" required autocomplete="email">
                        </div>
                        <div class="lvb-form-group">
                            <label for="lvb-phone" class="lvb-label">Telefon <span class="req">*</span></label>
                            <input type="tel" id="lvb-phone" name="phone" class="lvb-input" required autocomplete="tel">
                        </div>
                    </div>
                    <div class="lvb-form-group">
                        <label for="lvb-notes" class="lvb-label">Bemerkungen</label>
                        <textarea id="lvb-notes" name="notes" class="lvb-textarea" rows="3"></textarea>
                    </div>
                    <?php
                    $disclaimer_enabled = get_option( 'lvb_disclaimer_enabled', '1' );
                    if ( $disclaimer_enabled ) :
                        $disclaimer_text = get_option( 'lvb_disclaimer_text', 'Ich habe den Sicherheitshinweis gelesen und akzeptiert: Unfälle sind selten, aber möglich. Die Versicherung ist Sache der Teilnehmenden. Ausreichende körperliche Fitness wird vorausgesetzt. Ich erkenne meine eigenen Grenzen und akzeptiere, dass die Crew bei Bedarf eingreifen kann.' );
                    ?>
                    <div class="lvb-disclaimer-box">
                        <label class="lvb-disclaimer-label">
                            <input type="checkbox" id="lvb-disclaimer-check" required>
                            <span><?php echo esc_html( $disclaimer_text ); ?></span>
                        </label>
                    </div>
                    <?php endif; ?>
                    <div id="lvb-form-error" class="lvb-error" style="display:none;"></div>
                    <button type="submit" class="lvb-btn-primary" id="lvb-submit-btn">
                        Jetzt buchen
                    </button>
                </form>
            </div>

            <!-- Step 4: Success -->
            <div class="lvb-panel" id="lvb-step-4">
                <div class="lvb-success-screen">
                    <div class="lvb-success-icon">&#10003;</div>
                    <h2>Buchung bestätigt!</h2>
                    <p>Deine <?php echo esc_html( $service_label ); ?> ist gebucht. Du erhältst in Kürze eine Bestätigung per E-Mail.</p>
                    <div id="lvb-confirmation-details" class="lvb-confirmation-details"></div>

                    <?php
                    $payment_title   = get_option( 'lvb_payment_title', 'Zahlung vor Ort' );
                    $payment_methods = array_map( 'trim', explode( ';', get_option( 'lvb_payment_methods', 'Twint;Bar;Debit;Credit' ) ) );
                    $payment_methods = array_filter( $payment_methods );
                    ?>
                    <?php if ( $payment_methods ) : ?>
                    <div class="lvb-payment-hint">
                        <span class="lvb-payment-label"><?php echo esc_html( $payment_title ); ?>:</span>
                        <div class="lvb-payment-logos">
                            <?php foreach ( $payment_methods as $method ) :
                                $method_lower = strtolower( $method );
                                if ( $method_lower === 'twint' ) : ?>
                                    <img src="<?php echo esc_url( plugins_url( 'assets/img/twint-logo.png', LVB_PLUGIN_FILE ) ); ?>" alt="Twint" class="lvb-twint-logo">
                                <?php elseif ( $method_lower === 'bar' ) : ?>
                                    <span class="lvb-cash-badge">
                                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="17" height="17" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                            <rect x="1" y="5" width="22" height="14" rx="2"/>
                                            <circle cx="12" cy="12" r="3"/>
                                            <path d="M1 9h2M21 9h2M1 15h2M21 15h2"/>
                                        </svg>
                                        Bar (CHF)
                                    </span>
                                <?php elseif ( $method_lower === 'debit' ) : ?>
                                    <span class="lvb-cash-badge">
                                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="17" height="17" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                            <rect x="1" y="4" width="22" height="16" rx="2"/>
                                            <path d="M1 10h22"/>
                                        </svg>
                                        Debit
                                    </span>
                                <?php elseif ( $method_lower === 'credit' ) : ?>
                                    <span class="lvb-cash-badge">
                                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="17" height="17" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                            <rect x="1" y="4" width="22" height="16" rx="2"/>
                                            <path d="M1 10h22"/>
                                            <path d="M5 15h4"/>
                                        </svg>
                                        Credit
                                    </span>
                                <?php else : ?>
                                    <span class="lvb-cash-badge"><?php echo esc_html( $method ); ?></span>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endif; ?>

                    <?php $wa_url = get_option( 'lvb_whatsapp_url', '' ); ?>
                    <?php if ( $wa_url ) : ?>
                    <div class="lvb-wa-hint">
                        <p>📢 <strong>Tipp:</strong> Tritt unserer WhatsApp-Gruppe bei – dort informieren wir kurzfristig über Änderungen, Ausfälle oder besondere Bedingungen.</p>
                        <a class="lvb-wa-btn" href="<?php echo esc_url( $wa_url ); ?>" target="_blank" rel="noopener">
                            <svg viewBox="0 0 24 24" fill="currentColor" width="18" height="18"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z"/></svg>
                            WhatsApp-Gruppe beitreten
                        </a>
                    </div>
                    <?php endif; ?>
                    <button class="lvb-btn-primary" id="lvb-book-another">Weitere <?php echo esc_html( $service_label ); ?> buchen</button>
                </div>
            </div>

        </div><!-- /.lvb-booking-app -->
        <?php
        return ob_get_clean();
    }

    // -----------------------------------------------------------------------
    // AJAX: get available slots for a date range + optional service/staff
    // -----------------------------------------------------------------------

    /**
     * AJAX handler: return available Google Calendar slots for a date range.
     *
     * Reads availability events from the appropriate Google Calendar (staff
     * calendar if staff_id is provided, otherwise the default plugin calendar),
     * merges with confirmed bookings from the database, and returns the data
     * grouped by date for the JS calendar widget.
     *
     * Expected POST parameters (all optional):
     *   date_from   string  Y-m-d  Start of the date range (defaults to today).
     *   date_to     string  Y-m-d  End of the date range (defaults to +30 days).
     *   service_id  int     Filter slots to those long enough for the service duration.
     *   staff_id    int     Use the staff member's own Google Calendar.
     *
     * Sends a JSON success response with:
     *   slots          array  All availability slot objects.
     *   by_date        array  Availability slots keyed by date string (Y-m-d).
     *   booked_by_date array  Booking/buffer blocks keyed by date string.
     *
     * @return void  Terminates via wp_send_json_success() or wp_send_json_error().
     */
    public static function ajax_get_slots() {
        check_ajax_referer( 'lvb_booking_nonce', 'nonce' );

        $date_from  = sanitize_text_field( $_POST['date_from']  ?? '' );
        $date_to    = sanitize_text_field( $_POST['date_to']    ?? '' );
        $service_id = (int) ( $_POST['service_id'] ?? 0 );
        $staff_id   = (int) ( $_POST['staff_id']   ?? 0 );

        if ( empty( $date_from ) ) {
            $date_from = gmdate( 'Y-m-d' );
        }
        if ( empty( $date_to ) ) {
            $date_to = gmdate( 'Y-m-d', strtotime( $date_from . ' +30 days' ) );
        }

        // Determine availability source: staff schedule (if set) overrides Google Calendar.
        $calendar_id = get_option( 'lvb_google_calendar_id', '' );
        $staff       = $staff_id ? LVB_Database::get_by_id( 'staff', $staff_id ) : null;
        if ( $staff && ! empty( $staff['calendar_id'] ) ) {
            $calendar_id = $staff['calendar_id'];
        }

        $use_schedule = $staff && LVB_Staff_Schedule::has_schedule( $staff );

        if ( ! $use_schedule && ( empty( $calendar_id ) || ! LVB_Google_Calendar::is_connected() ) ) {
            // Return demo slots when not configured
            wp_send_json_success( [ 'slots' => [], 'message' => 'Google Calendar not connected.' ] );
            return;
        }

        $service = $service_id ? LVB_Database::get_by_id( 'services', $service_id ) : null;

        if ( $use_schedule ) {
            $slots = LVB_Staff_Schedule::generate_windows( $staff, $date_from, $date_to );
        } else {
            $slots = LVB_Google_Calendar::get_available_slots( $calendar_id, $date_from, $date_to );
        }

        if ( is_wp_error( $slots ) ) {
            wp_send_json_error( [ 'message' => $slots->get_error_message() ] );
            return;
        }

        // Filter slots by minimum advance booking time
        $min_advance = (int) get_option( 'lvb_min_advance_hours', 24 );
        if ( $min_advance > 0 ) {
            $cutoff = new DateTime( 'now', wp_timezone() );
            $cutoff->modify( '+' . $min_advance . ' hours' );
            // Round up to next grid-minute increment (configurable)
            $grid_min  = max( 1, (int) get_option( 'lvb_cutoff_grid', 15 ) );
            $min       = (int) $cutoff->format( 'i' );
            $remainder = $min % $grid_min;
            if ( $remainder > 0 ) {
                $cutoff->modify( '+' . ( $grid_min - $remainder ) . ' minutes' );
            }
            $cutoff->setTime( (int) $cutoff->format( 'H' ), (int) $cutoff->format( 'i' ), 0 );
            $cutoff_str = $cutoff->format( 'Y-m-d H:i:s' );
            // Remove slots that end before the cutoff; trim slots that start before cutoff but end after
            $filtered = [];
            foreach ( $slots as $slot ) {
                if ( $slot['end'] <= $cutoff_str ) {
                    continue; // entire slot is before cutoff
                }
                if ( $slot['start'] < $cutoff_str ) {
                    // Trim: move start to cutoff
                    $slot['start']      = $cutoff_str;
                    $slot['start_date'] = substr( $cutoff_str, 0, 10 );
                    $slot['start_time'] = substr( $cutoff_str, 11, 5 );
                    $start_ts = ( new DateTime( $slot['start'], wp_timezone() ) )->getTimestamp();
                    $end_ts   = ( new DateTime( $slot['end'], wp_timezone() ) )->getTimestamp();
                    $slot['duration'] = (int) ( ( $end_ts - $start_ts ) / 60 );
                }
                $filtered[] = $slot;
            }
            $slots = $filtered;
        }

        // Filter slots by service duration if provided
        if ( $service ) {
            $min_duration = (int) $service['duration'];
            $slots = array_values( array_filter( $slots, function( $slot ) use ( $min_duration ) {
                return $slot['duration'] >= $min_duration;
            } ) );
        }

        // Separate availability windows from booking events (created by this plugin)
        $availability = [];
        $booked       = [];
        foreach ( $slots as $slot ) {
            if ( strncmp( $slot['title'], '[Booking]', 9 ) === 0
                 || strncmp( $slot['title'], '[Puffer]', 8 ) === 0 ) {
                $booked[] = $slot;
            } else {
                $availability[] = $slot;
            }
        }

        // Merge DB confirmed bookings into booked list (ensures accuracy even if GCal is out of sync)
        global $wpdb;
        $db_bookings = $wpdb->get_results( $wpdb->prepare(
            "SELECT b.start_datetime, b.end_datetime, COALESCE(s.buffer_time, 0) AS buffer_time
             FROM {$wpdb->prefix}lvb_bookings b
             LEFT JOIN {$wpdb->prefix}lvb_services s ON b.service_id = s.id
             WHERE b.status = 'confirmed'
             AND DATE(b.start_datetime) BETWEEN %s AND %s",
            $date_from, $date_to
        ), ARRAY_A );

        foreach ( $db_bookings as $db_b ) {
            // Extend end by buffer_time so the buffer window is also blocked
            $end_obj = new DateTime( $db_b['end_datetime'] );
            $end_obj->modify( '+' . (int) $db_b['buffer_time'] . ' minutes' );
            $booked[] = [
                'title'      => '[Booking] DB',
                'start'      => $db_b['start_datetime'],
                'end'        => $end_obj->format( 'Y-m-d H:i:s' ),
                'start_date' => substr( $db_b['start_datetime'], 0, 10 ),
            ];
        }

        // Drop availability pieces shorter than the shortest active service —
        // they can never be booked, so they clutter the UI.
        $shortest = (int) $wpdb->get_var( "SELECT MIN(duration) FROM {$wpdb->prefix}lvb_services WHERE status = 'active' AND duration > 0" );
        if ( $shortest > 0 ) {
            $availability = self::split_availability_by_bookings( $availability, $booked, $shortest );
        }

        // Group by date for easy JS consumption
        $by_date        = [];
        $booked_by_date = [];
        foreach ( $availability as $slot ) {
            $by_date[ $slot['start_date'] ][] = $slot;
        }
        foreach ( $booked as $slot ) {
            $booked_by_date[ $slot['start_date'] ][] = $slot;
        }

        wp_send_json_success( [
            'slots'          => $availability,
            'by_date'        => $by_date,
            'booked_by_date' => $booked_by_date,
        ] );
    }

    // -----------------------------------------------------------------------
    // AJAX: get staff for a service
    // -----------------------------------------------------------------------

    /**
     * AJAX handler: return active staff members assigned to a service.
     *
     * Used by the booking widget to populate the optional instructor dropdown
     * after the customer selects a service. If service_id is 0 or missing, an
     * empty array is returned immediately.
     *
     * Expected POST parameters:
     *   service_id  int  The service ID to look up staff for.
     *
     * Sends a JSON success response with:
     *   staff  array  Array of staff row arrays (id, name, email, …).
     *
     * @return void  Terminates via wp_send_json_success().
     */
    public static function ajax_get_staff_for_service() {
        check_ajax_referer( 'lvb_booking_nonce', 'nonce' );

        $service_id = (int) ( $_POST['service_id'] ?? 0 );
        if ( ! $service_id ) {
            wp_send_json_success( [ 'staff' => [] ] );
            return;
        }

        $staff = LVB_Database::get_staff_for_service( $service_id );
        wp_send_json_success( [ 'staff' => $staff ] );
    }

    // -----------------------------------------------------------------------
    // AJAX: submit booking
    // -----------------------------------------------------------------------

    /**
     * AJAX handler: validate and create a new booking from the frontend form.
     *
     * Verifies the nonce, validates required fields and email format, sanitises
     * all inputs, and delegates to {@see LVB_Booking_Manager::create_booking()}.
     * On success, returns a confirmation payload consumed by booking.js to
     * render the Step 4 success screen.
     *
     * Required POST parameters:
     *   first_name      string  Customer's first name.
     *   last_name       string  Customer's last name.
     *   email           string  Customer's email address (validated with is_email()).
     *   start_datetime  string  Booking start in 'Y-m-d H:i' format (WP timezone).
     *   service_id      int     ID of the selected service.
     *
     * Optional POST parameters:
     *   phone           string  Customer phone number.
     *   notes           string  Freeform notes from the customer.
     *   staff_id        int     Requested staff member ID (0 = auto-assign).
     *   slot_event_id   string  Google Calendar event ID of the availability slot.
     *   slot_win_end    string  End of the availability window (used for overlap check).
     *
     * Sends a JSON success response with:
     *   booking_id    int     New booking ID.
     *   service_name  string  Name of the booked service.
     *   date          string  Formatted date string (WP date_format option).
     *   time          string  Formatted time range string (e.g. '10:00 – 11:30').
     *   message       string  Localised success message.
     *
     * @return void  Terminates via wp_send_json_success() or wp_send_json_error().
     */
    public static function ajax_submit_booking() {
        check_ajax_referer( 'lvb_booking_nonce', 'nonce' );

        // Validate required fields
        $required = [ 'first_name', 'last_name', 'email', 'start_datetime', 'service_id' ];
        foreach ( $required as $field ) {
            if ( empty( $_POST[ $field ] ) ) {
                wp_send_json_error( [ 'message' => sprintf( __( 'Field "%s" is required.', 'lakevision-booking' ), $field ) ] );
                return;
            }
        }

        if ( ! is_email( $_POST['email'] ) ) {
            wp_send_json_error( [ 'message' => __( 'Please enter a valid email address.', 'lakevision-booking' ) ] );
            return;
        }

        $data = [
            'first_name'     => sanitize_text_field( wp_unslash( $_POST['first_name'] ) ),
            'last_name'      => sanitize_text_field( wp_unslash( $_POST['last_name'] ) ),
            'email'          => sanitize_email( wp_unslash( $_POST['email'] ) ),
            'phone'          => sanitize_text_field( wp_unslash( $_POST['phone'] ?? '' ) ),
            'notes'          => sanitize_textarea_field( wp_unslash( $_POST['notes'] ?? '' ) ),
            'start_datetime' => sanitize_text_field( wp_unslash( $_POST['start_datetime'] ) ),
            'service_id'     => (int) $_POST['service_id'],
            'staff_id'       => (int) ( $_POST['staff_id'] ?? 0 ),
            'slot_event_id'  => sanitize_text_field( wp_unslash( $_POST['slot_event_id'] ?? '' ) ),
            'slot_win_end'   => sanitize_text_field( wp_unslash( $_POST['slot_win_end']   ?? '' ) ),
        ];

        // Validate minimum advance booking time
        $min_advance = (int) get_option( 'lvb_min_advance_hours', 24 );
        if ( $min_advance > 0 ) {
            $cutoff = new DateTime( 'now', wp_timezone() );
            $cutoff->modify( '+' . $min_advance . ' hours' );
            $booking_start = new DateTime( $data['start_datetime'], wp_timezone() );
            if ( $booking_start < $cutoff ) {
                wp_send_json_error( [ 'message' => sprintf( 'Buchungen müssen mindestens %d Stunden im Voraus erfolgen.', $min_advance ) ] );
                return;
            }
        }

        $booking_id = LVB_Booking_Manager::create_booking( $data );

        if ( is_wp_error( $booking_id ) ) {
            wp_send_json_error( [ 'message' => $booking_id->get_error_message() ] );
            return;
        }

        $booking  = LVB_Database::get_by_id( 'bookings', $booking_id );
        $service  = LVB_Database::get_by_id( 'services', $booking['service_id'] );
        $tz       = wp_timezone();
        $start    = new DateTime( $booking['start_datetime'], $tz );
        $end      = new DateTime( $booking['end_datetime'], $tz );

        wp_send_json_success( [
            'booking_id'   => $booking_id,
            'service_name' => $service['name'],
            'date'         => wp_date( get_option( 'date_format' ), $start->getTimestamp() ),
            'time'         => $start->format( 'H:i' ) . ' – ' . $end->format( 'H:i' ),
            'message'      => __( 'Booking confirmed! Check your email for details.', 'lakevision-booking' ),
        ] );
    }

    /**
     * Split availability windows by booked ranges and drop sub-pieces that
     * are shorter than $min_minutes (i.e. too short for the shortest active
     * service). Each returned piece has recomputed start/end/duration.
     *
     * @param array $windows       Availability windows from GCal.
     * @param array $booked        Booked+buffer ranges (start/end as 'Y-m-d H:i:s').
     * @param int   $min_minutes   Minimum usable piece length in minutes.
     * @return array  Filtered availability pieces.
     */
    private static function split_availability_by_bookings( $windows, $booked, $min_minutes ) {
        if ( empty( $windows ) ) return [];

        // Normalise booked ranges to unix timestamps, merge overlaps.
        $ranges = [];
        foreach ( $booked as $b ) {
            $s = strtotime( $b['start'] );
            $e = strtotime( $b['end'] );
            if ( $s && $e && $e > $s ) $ranges[] = [ $s, $e ];
        }
        usort( $ranges, function( $a, $b ) { return $a[0] - $b[0]; } );
        $merged = [];
        foreach ( $ranges as $r ) {
            if ( $merged && $r[0] <= end( $merged )[1] ) {
                $merged[ count( $merged ) - 1 ][1] = max( end( $merged )[1], $r[1] );
            } else {
                $merged[] = $r;
            }
        }

        $min_seconds = $min_minutes * 60;
        $tz          = wp_timezone();
        $out         = [];

        foreach ( $windows as $w ) {
            $ws = strtotime( $w['start'] );
            $we = strtotime( $w['end'] );
            if ( ! $ws || ! $we || $we <= $ws ) continue;

            // Walk through merged booked ranges, cutting pieces between them.
            $cursor = $ws;
            foreach ( $merged as $r ) {
                if ( $r[1] <= $cursor ) continue;   // fully before
                if ( $r[0] >= $we )     break;      // fully after window
                if ( $r[0] > $cursor ) {
                    $out[] = self::make_piece( $w, $cursor, min( $r[0], $we ), $min_seconds, $tz );
                }
                $cursor = max( $cursor, $r[1] );
                if ( $cursor >= $we ) break;
            }
            if ( $cursor < $we ) {
                $out[] = self::make_piece( $w, $cursor, $we, $min_seconds, $tz );
            }
        }

        return array_values( array_filter( $out ) );
    }

    /**
     * Build an availability slot piece, or null if shorter than $min_seconds.
     */
    private static function make_piece( $base, $start_ts, $end_ts, $min_seconds, $tz ) {
        if ( $end_ts - $start_ts < $min_seconds ) return null;
        $start = ( new DateTime( '@' . $start_ts ) )->setTimezone( $tz );
        $end   = ( new DateTime( '@' . $end_ts ) )->setTimezone( $tz );
        return array_merge( $base, [
            'start'      => $start->format( 'Y-m-d H:i:s' ),
            'end'        => $end->format( 'Y-m-d H:i:s' ),
            'start_date' => $start->format( 'Y-m-d' ),
            'start_time' => $start->format( 'H:i' ),
            'end_time'   => $end->format( 'H:i' ),
            'duration'   => (int) ( ( $end_ts - $start_ts ) / 60 ),
        ] );
    }
}
