<?php
/**
 * LVB_Notifications – email confirmation & admin alert.
 *
 * Generates and sends HTML emails for the two main notification events:
 *  - Booking confirmation: one email to the customer and one alert to the admin.
 *  - Booking cancellation: one email to the customer.
 *
 * Email templates are rendered inline (no external template files) to keep the
 * plugin self-contained. Brand colours, layout, and copy are all defined within
 * the {@see LVB_Notifications::render()} method.
 *
 * Configuration (stored as WP options):
 *   lvb_admin_notification_email  – Address for admin alerts (falls back to admin_email).
 *   lvb_email_from                – "From" display name for sent emails.
 *   lvb_email_from_address        – "From" email address (falls back to admin_email).
 *   lvb_currency_symbol           – Currency symbol prepended to price values.
 *
 * @package LakeVision_Booking
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Sends HTML email notifications to customers and the admin on booking events.
 *
 * @package LakeVision_Booking
 */
class LVB_Notifications {

    /**
     * Send confirmation email to the customer and alert to the admin.
     *
     * @param int $booking_id
     */
    public static function send_booking_confirmation( $booking_id ) {
        $booking = LVB_Database::get_by_id( 'bookings', $booking_id );
        if ( ! $booking ) {
            return;
        }

        $service  = LVB_Database::get_by_id( 'services',  $booking['service_id'] );
        $customer = LVB_Database::get_by_id( 'customers', $booking['customer_id'] );
        $staff    = $booking['staff_id'] ? LVB_Database::get_by_id( 'staff', $booking['staff_id'] ) : null;

        if ( ! $service || ! $customer ) {
            return;
        }

        $tz    = wp_timezone();
        $start = new DateTime( $booking['start_datetime'], $tz );
        $end   = new DateTime( $booking['end_datetime'], $tz );

        $date_str  = wp_date( get_option( 'date_format' ), $start->getTimestamp() );
        $time_str  = $start->format( get_option( 'time_format' ) ) . ' – ' . $end->format( get_option( 'time_format' ) );
        $site_name = get_bloginfo( 'name' );
        $currency  = get_option( 'lvb_currency_symbol', '$' );

        // ---- Determine if customer has an intake form on file ----
        $customer_email   = $customer['email'];
        $no_intake_form   = ! self::has_intake_form( $customer_email );

        // ---- Customer email ----
        $customer_name    = trim( $customer['first_name'] . ' ' . $customer['last_name'] );
        $customer_subject = sprintf( __( 'Your Booking Confirmation – %s', 'lakevision-booking' ), $site_name );
        $customer_body    = self::customer_email_body( [
            'customer_name'       => $customer_name,
            'customer_first_name' => $customer['first_name'],
            'customer_email'      => $customer_email,
            'service_name'        => $service['name'],
            'staff_name'    => $staff ? $staff['name'] : '',
            'date'          => $date_str,
            'time'          => $time_str,
            'price'         => $currency . number_format( (float) $booking['price'], 2 ),
            'notes'         => $booking['notes'],
            'booking_id'    => $booking_id,
            'site_name'     => $site_name,
            'no_intake_form' => $no_intake_form,
        ] );

        // ---- ICS calendar attachment ----
        $ics_content = self::generate_ics( $booking_id, $start, $end, $service['name'], $site_name, $booking['notes'] );
        $ics_file    = wp_tempnam( 'lvb-booking-' . $booking_id );
        file_put_contents( $ics_file, $ics_content );
        rename( $ics_file, $ics_file . '.ics' );
        $ics_file .= '.ics';

        self::send( $customer_email, $customer_subject, $customer_body, [ $ics_file ] );
        @unlink( $ics_file );

        // ---- Admin email ----
        $admin_email   = get_option( 'lvb_admin_notification_email', get_option( 'admin_email' ) );
        $admin_subject = sprintf( __( '[New Booking] %s – %s', 'lakevision-booking' ), $service['name'], $customer_name );
        $admin_body    = self::admin_email_body( [
            'customer_name'  => $customer_name,
            'customer_email' => $customer_email,
            'customer_phone' => $customer['phone'],
            'service_name'   => $service['name'],
            'staff_name'     => $staff ? $staff['name'] : '',
            'date'           => $date_str,
            'time'           => $time_str,
            'price'          => $currency . number_format( (float) $booking['price'], 2 ),
            'notes'          => $booking['notes'],
            'booking_id'     => $booking_id,
            'site_name'      => $site_name,
            'no_intake_form' => $no_intake_form,
        ] );

        self::send( $admin_email, $admin_subject, $admin_body );
    }

    /**
     * Schedule a reminder email for a booking via WordPress Cron.
     *
     * @param int      $booking_id
     * @param DateTime $start  Booking start time (in WP timezone).
     */
    public static function schedule_reminder( $booking_id, $start ) {
        if ( ! get_option( 'lvb_reminder_enabled' ) ) {
            return;
        }

        $hours  = (int) get_option( 'lvb_reminder_hours', 24 );
        $send_at = clone $start;
        $send_at->modify( '-' . $hours . ' hours' );

        // Only schedule if the reminder time is still in the future
        if ( $send_at->getTimestamp() <= time() ) {
            return;
        }

        wp_schedule_single_event(
            $send_at->getTimestamp(),
            'lvb_send_reminder',
            [ $booking_id ]
        );
    }

    /**
     * Send a reminder email to the customer. Called by WordPress Cron.
     *
     * @param int $booking_id
     */
    public static function send_reminder( $booking_id ) {
        global $wpdb;

        $booking  = LVB_Database::get_by_id( 'bookings',  $booking_id );
        $service  = $booking ? LVB_Database::get_by_id( 'services',  $booking['service_id'] )  : null;
        $customer = $booking ? LVB_Database::get_by_id( 'customers', $booking['customer_id'] ) : null;

        if ( ! $booking || ! $service || ! $customer ) {
            return;
        }

        // Don't send if already sent or booking is cancelled
        if ( $booking['reminder_sent'] || $booking['status'] === 'cancelled' ) {
            return;
        }

        $tz    = wp_timezone();
        $start = new DateTime( $booking['start_datetime'], $tz );
        $end   = new DateTime( $booking['end_datetime'], $tz );

        $subject = sprintf( __( 'Erinnerung: Dein Termin morgen – %s', 'lakevision-booking' ), get_bloginfo( 'name' ) );
        $body    = self::render( 'reminder', [
            'customer_name'       => trim( $customer['first_name'] . ' ' . $customer['last_name'] ),
            'customer_first_name' => $customer['first_name'],
            'service_name'  => $service['name'],
            'date'          => wp_date( get_option( 'date_format' ), $start->getTimestamp() ),
            'time'          => $start->format( get_option( 'time_format' ) ) . ' – ' . $end->format( get_option( 'time_format' ) ),
            'site_name'     => get_bloginfo( 'name' ),
        ] );

        self::send( $customer['email'], $subject, $body );

        // Mark as sent
        $wpdb->update(
            $wpdb->prefix . 'lvb_bookings',
            [ 'reminder_sent' => 1 ],
            [ 'id' => $booking_id ],
            [ '%d' ],
            [ '%d' ]
        );
    }

    /**
     * Send a cancellation notice to the customer.
     *
     * @param int $booking_id
     */
    public static function send_cancellation( $booking_id ) {
        $booking  = LVB_Database::get_by_id( 'bookings',  $booking_id );
        $service  = $booking ? LVB_Database::get_by_id( 'services',  $booking['service_id'] )  : null;
        $customer = $booking ? LVB_Database::get_by_id( 'customers', $booking['customer_id'] ) : null;

        if ( ! $booking || ! $service || ! $customer ) {
            return;
        }

        $start = new DateTime( $booking['start_datetime'] );
        $start->setTimezone( wp_timezone() );

        $subject = sprintf( __( 'Booking Cancelled – %s', 'lakevision-booking' ), get_bloginfo( 'name' ) );
        $body    = self::render( 'cancellation', [
            'customer_name' => trim( $customer['first_name'] . ' ' . $customer['last_name'] ),
            'service_name'  => $service['name'],
            'date'          => wp_date( get_option( 'date_format' ), $start->getTimestamp() ),
            'time'          => $start->format( get_option( 'time_format' ) ),
            'site_name'     => get_bloginfo( 'name' ),
        ] );

        self::send( $customer['email'], $subject, $body );
    }

    // -----------------------------------------------------------------------
    // Internal helpers
    // -----------------------------------------------------------------------

    /**
     * Send an HTML email via wp_mail().
     *
     * Sets Content-Type to text/html and builds the From header from the
     * `lvb_email_from` and `lvb_email_from_address` options.
     *
     * @param string $to      Recipient email address.
     * @param string $subject Email subject line.
     * @param string $body    HTML email body.
     * @return void
     */
    private static function send( $to, $subject, $body, $attachments = [] ) {
        $headers = [ 'Content-Type: text/html; charset=UTF-8' ];
        $from    = get_option( 'lvb_email_from', get_bloginfo( 'name' ) );
        $from_email = get_option( 'lvb_email_from_address', get_option( 'admin_email' ) );
        $headers[] = 'From: ' . $from . ' <' . $from_email . '>';
        wp_mail( $to, $subject, $body, $headers, $attachments );
    }

    /**
     * Generate an iCalendar (.ics) string for a booking.
     *
     * @param int      $booking_id
     * @param DateTime $start
     * @param DateTime $end
     * @param string   $service_name
     * @param string   $site_name
     * @param string   $notes
     * @return string  ICS file contents.
     */
    private static function generate_ics( $booking_id, $start, $end, $service_name, $site_name, $notes = '' ) {
        $utc       = new DateTimeZone( 'UTC' );
        $start_utc = ( clone $start )->setTimezone( $utc );
        $end_utc   = ( clone $end )->setTimezone( $utc );

        $uid     = 'booking-' . $booking_id . '@' . wp_parse_url( home_url(), PHP_URL_HOST );
        $dtstamp = gmdate( 'Ymd\THis\Z' );
        $dtstart = $start_utc->format( 'Ymd\THis\Z' );
        $dtend   = $end_utc->format( 'Ymd\THis\Z' );
        $summary = $service_name . ' – ' . $site_name;

        $lines = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//LakeVision Booking//EN',
            'CALSCALE:GREGORIAN',
            'METHOD:PUBLISH',
            'BEGIN:VEVENT',
            'UID:' . $uid,
            'DTSTAMP:' . $dtstamp,
            'DTSTART:' . $dtstart,
            'DTEND:' . $dtend,
            'SUMMARY:' . $summary,
            'DESCRIPTION:' . str_replace( [ "\r", "\n" ], ' ', $notes ),
            'END:VEVENT',
            'END:VCALENDAR',
        ];

        return implode( "\r\n", $lines );
    }

    /**
     * Build the HTML body for the customer booking-confirmation email.
     *
     * Delegates to {@see render()} with the 'customer_confirmation' template.
     *
     * @param array $vars  Template variables (customer_name, service_name, date,
     *                     time, price, booking_id, staff_name, notes, site_name).
     * @return string  Rendered HTML string.
     */
    private static function customer_email_body( $vars ) {
        return self::render( 'customer_confirmation', $vars );
    }

    /**
     * Build the HTML body for the admin new-booking notification email.
     *
     * Delegates to {@see render()} with the 'admin_notification' template.
     *
     * @param array $vars  Template variables (customer_name, customer_email,
     *                     customer_phone, service_name, staff_name, date, time,
     *                     price, notes, booking_id, site_name).
     * @return string  Rendered HTML string.
     */
    private static function admin_email_body( $vars ) {
        return self::render( 'admin_notification', $vars );
    }

    /**
     * Render an inline HTML email template and return the resulting string.
     *
     * All HTML is generated in-memory rather than from separate template files,
     * making the plugin fully self-contained. Supported template names are:
     *   - 'customer_confirmation' – booking details sent to the customer.
     *   - 'admin_notification'    – new-booking alert sent to the admin.
     *   - 'cancellation'          – cancellation notice sent to the customer.
     *
     * All output is run through esc_html() / esc_url() before inclusion.
     *
     * @param string $template  Template identifier (see above).
     * @param array  $vars      Associative array of variables available to the template.
     * @return string  Rendered HTML string, or an empty string for an unknown template.
     */
    private static function render( $template, $vars ) {
        $primary   = get_option( 'lvb_accent_color', '#00F5C4' );
        $secondary = get_option( 'lvb_accent2_color', '#00C2FF' );
        $dark      = '#1E1C19';

        $wrap_style  = 'font-family:Georgia,serif;max-width:620px;margin:0 auto;background:#FAF7F2;border-radius:12px;overflow:hidden;';
        $header_style = "background:$dark;padding:32px 24px;text-align:center;";
        $body_style   = 'padding:32px 24px;color:#1A2332;';
        $footer_style = "background:#F2EDE5;padding:16px 24px;text-align:center;font-size:12px;color:#7A756C;";
        $btn_style    = "display:inline-block;background:$primary;color:#ffffff;padding:12px 28px;border-radius:60px;text-decoration:none;font-weight:bold;margin-top:16px;";
        $row_style    = 'padding:8px 0;border-bottom:1px solid #EAE5DD;';

        $site         = esc_html( $vars['site_name'] );
        $logo_url     = get_option( 'lvb_email_logo_url', plugins_url( 'assets/img/logo.svg', LVB_PLUGIN_FILE ) );
        $staff_label  = get_option( 'lvb_staff_label', 'Instructor' );
        $confirm_text = get_option( 'lvb_email_confirmation_text', 'Your booking is confirmed. We look forward to seeing you!' );
        $logo         = '<img src="' . esc_url( $logo_url ) . '" alt="' . $site . '" width="320" style="display:block;margin:0 auto;max-width:320px;">';

        switch ( $template ) {
            case 'customer_confirmation':
                $rows = self::detail_rows( [
                    'Service'    => esc_html( $vars['service_name'] ),
                    $staff_label => esc_html( $vars['staff_name'] ?: 'TBD' ),
                    'Datum'      => esc_html( $vars['date'] ),
                    'Zeit'       => esc_html( $vars['time'] ),
                    'Preis'      => esc_html( $vars['price'] ),
                    'Buchung #'  => esc_html( $vars['booking_id'] ),
                ], $row_style );

                $first_name = esc_html( $vars['customer_first_name'] ?? $vars['customer_name'] );

                return '<div style="' . $wrap_style . '">
                    <div style="' . $header_style . '">' . $logo . '</div>
                    <div style="' . $body_style . '">
                        <h2 style="color:' . $dark . ';margin-top:0;">Buchung bestätigt!</h2>
                        <p>Hi ' . $first_name . ',</p>
                        <p>' . esc_html( $confirm_text ) . '</p>
                        <table style="width:100%;border-collapse:collapse;">' . $rows . '</table>
                        ' . ( $vars['notes'] ? '<p><strong>Notiz:</strong> ' . esc_html( $vars['notes'] ) . '</p>' : '' ) . '
                        ' . self::intake_form_section( $vars, $btn_style ) . '
                        <p style="margin-top:24px;">Falls du absagen oder umbuchen möchtest, melde dich bitte so früh wie möglich bei uns.</p>
                    </div>
                    <div style="' . $footer_style . '">&copy; ' . gmdate( 'Y' ) . ' ' . $site . '. Alle Rechte vorbehalten.</div>
                </div>';

            case 'admin_notification':
                $rows = self::detail_rows( [
                    'Service'      => esc_html( $vars['service_name'] ),
                    $staff_label   => esc_html( $vars['staff_name'] ?: 'Unassigned' ),
                    'Date'     => esc_html( $vars['date'] ),
                    'Time'     => esc_html( $vars['time'] ),
                    'Price'    => esc_html( $vars['price'] ),
                    'Customer' => esc_html( $vars['customer_name'] ),
                    'Email'    => esc_html( $vars['customer_email'] ),
                    'Phone'    => esc_html( $vars['customer_phone'] ),
                    'Notes'    => esc_html( $vars['notes'] ),
                    'Booking #'=> esc_html( $vars['booking_id'] ),
                ], $row_style );

                $admin_url = admin_url( 'admin.php?page=lvb-bookings' );

                return '<div style="' . $wrap_style . '">
                    <div style="' . $header_style . '">' . $logo . '</div>
                    <div style="' . $body_style . '">
                        <h2 style="color:' . $dark . ';margin-top:0;">New Booking Received</h2>
                        <table style="width:100%;border-collapse:collapse;">' . $rows . '</table>
                        ' . self::admin_first_time_note( $vars ) . '
                        <a href="' . esc_url( $admin_url ) . '" style="' . $btn_style . '">View in Dashboard</a>
                    </div>
                    <div style="' . $footer_style . '">' . $site . ' Admin</div>
                </div>';

            case 'reminder':
                $rows = self::detail_rows( [
                    'Service' => esc_html( $vars['service_name'] ),
                    'Datum'   => esc_html( $vars['date'] ),
                    'Zeit'    => esc_html( $vars['time'] ),
                ], $row_style );

                return '<div style="' . $wrap_style . '">
                    <div style="' . $header_style . '">' . $logo . '</div>
                    <div style="' . $body_style . '">
                        <h2 style="color:' . $dark . ';margin-top:0;">&#128337; Erinnerung an deinen Termin</h2>
                        <p>Hallo ' . esc_html( $vars['customer_first_name'] ?? $vars['customer_name'] ) . ',</p>
                        <p>Dies ist eine freundliche Erinnerung an deinen bevorstehenden Termin bei <strong>' . $site . '</strong>.</p>
                        <table style="width:100%;border-collapse:collapse;">' . $rows . '</table>
                        <p style="margin-top:24px;">Bei Fragen oder falls du absagen möchtest, melde dich bitte so früh wie möglich bei uns.</p>
                    </div>
                    <div style="' . $footer_style . '">&copy; ' . gmdate( 'Y' ) . ' ' . $site . '. All rights reserved.</div>
                </div>';

            case 'intake_form_notification':
                // Build label => value pairs from the insert data for the table
                $field_labels = [
                    'name'                => 'Name',
                    'email'               => 'E-Mail',
                    'phone'               => 'Telefon',
                    'wishes'              => 'Wünsche',
                    'health_issues'       => 'Gesundheitliche Beschwerden',
                    'medications'         => 'Medikamente',
                    'psychotherapy'       => 'Psychotherapie',
                    'disclaimer_accepted' => 'Disclaimer akzeptiert',
                    'data_confirmed'      => 'Daten bestätigt',
                ];

                $intake_pairs = [];
                foreach ( $field_labels as $key => $label ) {
                    if ( ! isset( $vars[ $key ] ) || $vars[ $key ] === '' ) continue;
                    $val = $vars[ $key ];
                    // Translate yes/no and 1/0 for readability
                    if ( $val === 'yes' ) $val = 'Ja';
                    if ( $val === 'no' ) $val = 'Nein';
                    if ( $val === 1 || $val === '1' ) $val = 'Ja';
                    if ( $val === 0 || $val === '0' ) $val = 'Nein';
                    $intake_pairs[ $label ] = esc_html( $val );
                }

                // Include custom fields if present
                if ( ! empty( $vars['custom_fields'] ) ) {
                    $custom = json_decode( $vars['custom_fields'], true );
                    if ( is_array( $custom ) ) {
                        foreach ( $custom as $cf_key => $cf_val ) {
                            $intake_pairs[ esc_html( $cf_key ) ] = esc_html( $cf_val );
                        }
                    }
                }

                $intake_pairs['Eingegangen am'] = esc_html( $vars['created_at'] ?? '' );
                $intake_pairs['Formular #'] = esc_html( $vars['form_id'] ?? '' );

                $intake_rows = self::detail_rows( $intake_pairs, $row_style );
                $intake_admin_url = admin_url( 'admin.php?page=lvb-intake-forms&view=' . intval( $vars['form_id'] ?? 0 ) );

                return '<div style="' . $wrap_style . '">
                    <div style="' . $header_style . '">' . $logo . '</div>
                    <div style="' . $body_style . '">
                        <h2 style="color:' . $dark . ';margin-top:0;">Neues Anmeldeformular eingegangen</h2>
                        <p>Ein neues Anmeldeformular wurde von <strong>' . esc_html( $vars['name'] ?? 'Unbekannt' ) . '</strong> ausgefüllt.</p>
                        <table style="width:100%;border-collapse:collapse;">' . $intake_rows . '</table>
                        <a href="' . esc_url( $intake_admin_url ) . '" style="' . $btn_style . '">Im Dashboard ansehen</a>
                    </div>
                    <div style="' . $footer_style . '">' . $site . ' Admin</div>
                </div>';

            case 'intake_customer_confirmation':
                $customer_name_ic = esc_html( $vars['name'] ?? '' );

                // Build summary of submitted form data
                $ic_field_labels = [
                    'name'           => 'Name',
                    'email'          => 'E-Mail',
                    'phone'          => 'Telefon',
                    'wishes'         => 'Wünsche',
                    'health_issues'  => 'Gesundheitliche Beschwerden',
                    'medications'    => 'Medikamente',
                    'psychotherapy'  => 'Psychotherapie',
                ];

                $ic_pairs = [];
                foreach ( $ic_field_labels as $key => $label ) {
                    if ( ! isset( $vars[ $key ] ) || $vars[ $key ] === '' ) continue;
                    $val = $vars[ $key ];
                    if ( $val === 'yes' ) $val = 'Ja';
                    if ( $val === 'no' ) $val = 'Nein';
                    if ( $val === 1 || $val === '1' ) $val = 'Ja';
                    if ( $val === 0 || $val === '0' ) $val = 'Nein';
                    $ic_pairs[ $label ] = esc_html( $val );
                }

                // Include custom fields if present
                if ( ! empty( $vars['custom_fields'] ) ) {
                    $ic_custom = json_decode( $vars['custom_fields'], true );
                    if ( is_array( $ic_custom ) ) {
                        foreach ( $ic_custom as $cf_key => $cf_val ) {
                            $ic_pairs[ esc_html( $cf_key ) ] = esc_html( $cf_val );
                        }
                    }
                }

                $ic_rows = self::detail_rows( $ic_pairs, $row_style );

                return '<div style="' . $wrap_style . '">
                    <div style="' . $header_style . '">' . $logo . '</div>
                    <div style="' . $body_style . '">
                        <h2 style="color:' . $dark . ';margin-top:0;">Vielen Dank für dein Anmeldeformular</h2>
                        <p>Liebe/r ' . $customer_name_ic . ',</p>
                        <p>Vielen Dank für das Ausfüllen des Anmeldeformulars.</p>
                        <p>Ich habe deine Angaben erhalten und werde mich gut auf unsere Begegnung vorbereiten.</p>
                        <h3 style="color:' . $dark . ';margin-top:24px;">Deine Angaben</h3>
                        <table style="width:100%;border-collapse:collapse;">' . $ic_rows . '</table>
                        <p>Falls du noch Fragen hast, kannst du mich jederzeit kontaktieren.</p>
                        <p style="margin-top:24px;">Herzliche Grüsse,<br><strong>' . $site . '</strong></p>
                    </div>
                    <div style="' . $footer_style . '">&copy; ' . gmdate( 'Y' ) . ' ' . $site . '. Alle Rechte vorbehalten.</div>
                </div>';

            case 'cancellation':
                $first_name_c = esc_html( $vars['customer_first_name'] ?? $vars['customer_name'] );
                return '<div style="' . $wrap_style . '">
                    <div style="' . $header_style . '">' . $logo . '</div>
                    <div style="' . $body_style . '">
                        <h2 style="color:' . $dark . ';margin-top:0;">Buchung storniert</h2>
                        <p>Hi ' . $first_name_c . ',</p>
                        <p>Deine Buchung für <strong>' . esc_html( $vars['service_name'] ) . '</strong> am
                           <strong>' . esc_html( $vars['date'] ) . '</strong> um <strong>' . esc_html( $vars['time'] ) . '</strong>
                           wurde storniert.</p>
                        <p>Falls du neu buchen möchtest, melde dich gerne bei uns.</p>
                    </div>
                    <div style="' . $footer_style . '">&copy; ' . gmdate( 'Y' ) . ' ' . $site . '.</div>
                </div>';

            default:
                return '';
        }
    }

    /**
     * Convert a key-value map into HTML table rows for the email templates.
     *
     * Rows with an empty value are skipped to avoid cluttering the email with
     * blank lines. Each value should already be run through esc_html() by the
     * caller; this method trusts the value as safe HTML.
     *
     * @param array  $pairs      Associative array of label => value pairs.
     * @param string $row_style  Inline CSS string applied to each <tr> element.
     * @return string  HTML string containing zero or more <tr> elements.
     */
    private static function detail_rows( $pairs, $row_style ) {
        $html = '';
        foreach ( $pairs as $label => $value ) {
            if ( $value === '' ) continue;
            $html .= '<tr style="' . $row_style . '">
                <td style="width:35%;font-weight:bold;padding:8px 0;">' . esc_html( $label ) . '</td>
                <td>' . $value . '</td>
            </tr>';
        }
        return $html;
    }

    /**
     * Send an admin notification when a customer submits an intake form.
     *
     * @param array $insert_data  The data that was inserted into the intake_forms table.
     * @param int   $form_id      The ID of the newly created intake form record.
     */
    public static function send_intake_form_notification( $insert_data, $form_id ) {
        $admin_email = get_option( 'lvb_admin_notification_email', get_option( 'admin_email' ) );
        $name        = ! empty( $insert_data['name'] ) ? $insert_data['name'] : 'Unbekannt';
        $subject     = sprintf( 'Neues Anmeldeformular von %s', $name );
        $body        = self::render( 'intake_form_notification', array_merge( $insert_data, [
            'form_id'   => $form_id,
            'site_name' => get_bloginfo( 'name' ),
        ] ) );

        self::send( $admin_email, $subject, $body );
    }

    /**
     * Send a confirmation email to the customer after intake form submission.
     *
     * @param array $insert_data  The data that was inserted into the intake_forms table.
     */
    public static function send_intake_form_customer_confirmation( $insert_data ) {
        if ( empty( $insert_data['email'] ) ) {
            return;
        }

        $subject = 'Vielen Dank für dein Anmeldeformular';
        $body    = self::render( 'intake_customer_confirmation', array_merge( $insert_data, [
            'site_name' => get_bloginfo( 'name' ),
        ] ) );

        self::send( $insert_data['email'], $subject, $body );
    }

    /**
     * Check if a customer email has only one confirmed booking (i.e. first time).
     *
     * @param string $email Customer email address.
     * @return bool True if this is the customer's first confirmed booking.
     */
    private static function is_first_time_customer( $email ) {
        global $wpdb;
        $count = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}lvb_bookings b
             JOIN {$wpdb->prefix}lvb_customers c ON c.id = b.customer_id
             WHERE c.email = %s AND b.status = 'confirmed'",
            $email
        ) );
        return $count === 1;
    }

    /**
     * Check if a customer email has an existing intake form on file.
     *
     * @param string $email Customer email address.
     * @return bool True if an intake form exists for this email.
     */
    private static function has_intake_form( $email ) {
        global $wpdb;
        $count = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}lvb_intake_forms WHERE email = %s",
            $email
        ) );
        return $count > 0;
    }

    /**
     * Render the intake form section for customers without an intake form on file.
     *
     * @param array  $vars      Template variables (must include 'customer_email' or 'no_intake_form').
     * @param string $btn_style Inline CSS for the button.
     * @return string HTML string, or empty if not applicable.
     */
    private static function intake_form_section( $vars, $btn_style ) {
        if ( ! get_option( 'lvb_intake_form_enabled', '0' ) ) {
            return '';
        }
        if ( empty( $vars['no_intake_form'] ) ) {
            return '';
        }
        $url = home_url( '/anmeldeformular' );
        return '<div style="margin-top:24px;padding:20px;background:#F2EDE5;border-radius:8px;">
            <p style="margin:0 0 12px 0;">Vor deiner ersten Sitzung bitte ich dich, ein kurzes Anmeldeformular auszufüllen.</p>
            <a href="' . esc_url( $url ) . '" style="' . $btn_style . '">Anmeldeformular ausfüllen</a>
        </div>';
    }

    /**
     * Render a note for the admin when customer has no intake form on file.
     *
     * @param array $vars Template variables (must include 'no_intake_form').
     * @return string HTML string, or empty if not applicable.
     */
    private static function admin_first_time_note( $vars ) {
        if ( empty( $vars['no_intake_form'] ) || ! get_option( 'lvb_intake_form_enabled', '0' ) ) {
            return '';
        }
        return '<p style="margin-top:16px;padding:12px;background:#FFF8E1;border-left:4px solid #FFC107;border-radius:4px;">&#11088; Kein Anmeldeformular vorhanden! Dem Kunden wurde der Link zum Anmeldeformular zugestellt.</p>';
    }
}
