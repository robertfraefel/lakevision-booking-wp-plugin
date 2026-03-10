<?php
/**
 * LVB_Notifications – email confirmation & admin alert.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

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

        $date_str  = $start->format( get_option( 'date_format' ) );
        $time_str  = $start->format( get_option( 'time_format' ) ) . ' – ' . $end->format( get_option( 'time_format' ) );
        $site_name = get_bloginfo( 'name' );
        $currency  = get_option( 'lvb_currency_symbol', '$' );

        // ---- Customer email ----
        $customer_email   = $customer['email'];
        $customer_name    = trim( $customer['first_name'] . ' ' . $customer['last_name'] );
        $customer_subject = sprintf( __( 'Your Booking Confirmation – %s', 'lakevision-booking' ), $site_name );
        $customer_body    = self::customer_email_body( [
            'customer_name' => $customer_name,
            'service_name'  => $service['name'],
            'staff_name'    => $staff ? $staff['name'] : '',
            'date'          => $date_str,
            'time'          => $time_str,
            'price'         => $currency . number_format( (float) $booking['price'], 2 ),
            'notes'         => $booking['notes'],
            'booking_id'    => $booking_id,
            'site_name'     => $site_name,
        ] );

        self::send( $customer_email, $customer_subject, $customer_body );

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
        ] );

        self::send( $admin_email, $admin_subject, $admin_body );
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
            'date'          => $start->format( get_option( 'date_format' ) ),
            'time'          => $start->format( get_option( 'time_format' ) ),
            'site_name'     => get_bloginfo( 'name' ),
        ] );

        self::send( $customer['email'], $subject, $body );
    }

    // -----------------------------------------------------------------------
    // Internal helpers
    // -----------------------------------------------------------------------

    private static function send( $to, $subject, $body ) {
        $headers = [ 'Content-Type: text/html; charset=UTF-8' ];
        $from    = get_option( 'lvb_email_from', get_bloginfo( 'name' ) );
        $from_email = get_option( 'lvb_email_from_address', get_option( 'admin_email' ) );
        $headers[] = 'From: ' . $from . ' <' . $from_email . '>';
        wp_mail( $to, $subject, $body, $headers );
    }

    private static function customer_email_body( $vars ) {
        return self::render( 'customer_confirmation', $vars );
    }

    private static function admin_email_body( $vars ) {
        return self::render( 'admin_notification', $vars );
    }

    /**
     * Very simple template engine – keeps HTML inline to avoid file deps.
     */
    private static function render( $template, $vars ) {
        $primary   = '#00F5C4';
        $secondary = '#00C2FF';
        $dark      = '#0d1117';

        $wrap_style  = 'font-family:Arial,sans-serif;max-width:620px;margin:0 auto;background:#ffffff;border-radius:8px;overflow:hidden;';
        $header_style = "background:$dark;padding:32px 24px;text-align:center;";
        $body_style   = 'padding:32px 24px;color:#333333;';
        $footer_style = "background:#f5f5f5;padding:16px 24px;text-align:center;font-size:12px;color:#999999;";
        $btn_style    = "display:inline-block;background:$primary;color:$dark;padding:12px 28px;border-radius:6px;text-decoration:none;font-weight:bold;margin-top:16px;";
        $row_style    = 'padding:8px 0;border-bottom:1px solid #eeeeee;';

        $site         = esc_html( $vars['site_name'] );
        $logo_url     = get_option( 'lvb_email_logo_url', plugins_url( 'assets/img/logo.svg', LVB_PLUGIN_FILE ) );
        $logo         = '<img src="' . esc_url( $logo_url ) . '" alt="' . $site . '" width="200" style="display:block;margin:0 auto;max-width:200px;">';
        $staff_label  = get_option( 'lvb_staff_label', 'Instructor' );
        $confirm_text = get_option( 'lvb_email_confirmation_text', 'Your booking is confirmed. We look forward to seeing you!' );

        switch ( $template ) {
            case 'customer_confirmation':
                $rows = self::detail_rows( [
                    'Service'       => esc_html( $vars['service_name'] ),
                    $staff_label    => esc_html( $vars['staff_name'] ?: 'TBD' ),
                    'Date'          => esc_html( $vars['date'] ),
                    'Time'          => esc_html( $vars['time'] ),
                    'Price'         => esc_html( $vars['price'] ),
                    'Booking #'     => esc_html( $vars['booking_id'] ),
                ], $row_style );

                return '<div style="' . $wrap_style . '">
                    <div style="' . $header_style . '">' . $logo . '</div>
                    <div style="' . $body_style . '">
                        <h2 style="color:' . $dark . ';margin-top:0;">Booking Confirmed!</h2>
                        <p>Hi ' . esc_html( $vars['customer_name'] ) . ',</p>
                        <p>' . esc_html( $confirm_text ) . '</p>
                        <table style="width:100%;border-collapse:collapse;">' . $rows . '</table>
                        ' . ( $vars['notes'] ? '<p><strong>Notes:</strong> ' . esc_html( $vars['notes'] ) . '</p>' : '' ) . '
                        <p style="margin-top:24px;">If you need to reschedule or cancel, please contact us as soon as possible.</p>
                    </div>
                    <div style="' . $footer_style . '">&copy; ' . gmdate( 'Y' ) . ' ' . $site . '. All rights reserved.</div>
                </div>';

            case 'admin_notification':
                $rows = self::detail_rows( [
                    'Service'  => esc_html( $vars['service_name'] ),
                    'Staff'    => esc_html( $vars['staff_name'] ?: 'Unassigned' ),
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
                        <a href="' . esc_url( $admin_url ) . '" style="' . $btn_style . '">View in Dashboard</a>
                    </div>
                    <div style="' . $footer_style . '">' . $site . ' Admin</div>
                </div>';

            case 'cancellation':
                return '<div style="' . $wrap_style . '">
                    <div style="' . $header_style . '">' . $logo . '</div>
                    <div style="' . $body_style . '">
                        <h2 style="color:' . $dark . ';margin-top:0;">Booking Cancelled</h2>
                        <p>Hi ' . esc_html( $vars['customer_name'] ) . ',</p>
                        <p>Your booking for <strong>' . esc_html( $vars['service_name'] ) . '</strong> on
                           <strong>' . esc_html( $vars['date'] ) . '</strong> at <strong>' . esc_html( $vars['time'] ) . '</strong>
                           has been cancelled.</p>
                        <p>If this was unexpected or you\'d like to rebook, please contact us.</p>
                    </div>
                    <div style="' . $footer_style . '">&copy; ' . gmdate( 'Y' ) . ' ' . $site . '.</div>
                </div>';

            default:
                return '';
        }
    }

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
}
