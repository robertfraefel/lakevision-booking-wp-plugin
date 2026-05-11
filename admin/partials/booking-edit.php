<?php
/**
 * Admin partial – Edit existing booking.
 *
 * Included from bookings.php when ?edit=<id> is present. Renders a form with
 * all editable fields (customer, service, staff, datetime, price, status,
 * notes) plus a checkbox to optionally re-send the confirmation email.
 */
if ( ! defined( 'ABSPATH' ) ) exit;
if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Unauthorised.' );

$edit_id = (int) ( $_GET['edit'] ?? 0 );
$booking = $edit_id > 0 ? LVB_Database::get_by_id( 'bookings', $edit_id ) : null;
if ( ! $booking ) {
    echo '<div class="wrap lvb-wrap"><h1>Booking not found.</h1><p><a href="'
        . esc_url( admin_url( 'admin.php?page=lvb-bookings' ) ) . '">&larr; Back to bookings</a></p></div>';
    return;
}

$customer = LVB_Database::get_by_id( 'customers', $booking['customer_id'] );
$services = LVB_Database::get_all( 'services', [], 'sort_order ASC, name ASC' );
$staff    = LVB_Database::get_all( 'staff',    [], 'name ASC' );

$tz       = wp_timezone();
$start_dt = new DateTime( $booking['start_datetime'], $tz );
$end_dt   = new DateTime( $booking['end_datetime'],   $tz );
$back_url = admin_url( 'admin.php?page=lvb-bookings' );
?>
<div class="wrap lvb-wrap">
    <h1 class="wp-heading-inline">Buchung #<?php echo (int) $booking['id']; ?> bearbeiten</h1>
    <a href="<?php echo esc_url( $back_url ); ?>" class="page-title-action">&larr; Zurück zur Liste</a>
    <hr class="wp-header-end">

    <form method="post" action="<?php echo esc_url( admin_url( 'admin.php?page=lvb-bookings' ) ); ?>" class="lvb-card" style="max-width:760px;padding:1.5rem;">
        <?php wp_nonce_field( 'lvb_save_booking_' . $booking['id'] ); ?>
        <input type="hidden" name="booking_id" value="<?php echo (int) $booking['id']; ?>">

        <h2 style="margin-top:0;">Kunde</h2>
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row"><label for="lvb_first_name">Vorname</label></th>
                <td><input type="text" id="lvb_first_name" name="first_name"
                           value="<?php echo esc_attr( $customer['first_name'] ?? '' ); ?>"
                           class="regular-text" required></td>
            </tr>
            <tr>
                <th scope="row"><label for="lvb_last_name">Nachname</label></th>
                <td><input type="text" id="lvb_last_name" name="last_name"
                           value="<?php echo esc_attr( $customer['last_name'] ?? '' ); ?>"
                           class="regular-text" required></td>
            </tr>
            <tr>
                <th scope="row"><label for="lvb_email">Email</label></th>
                <td><input type="email" id="lvb_email" name="email"
                           value="<?php echo esc_attr( $customer['email'] ?? '' ); ?>"
                           class="regular-text" required></td>
            </tr>
            <tr>
                <th scope="row"><label for="lvb_phone">Telefon</label></th>
                <td><input type="text" id="lvb_phone" name="phone"
                           value="<?php echo esc_attr( $customer['phone'] ?? '' ); ?>"
                           class="regular-text"></td>
            </tr>
        </table>

        <h2>Buchung</h2>
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row"><label for="lvb_service_id">Service</label></th>
                <td>
                    <select id="lvb_service_id" name="service_id" required>
                        <?php foreach ( $services as $svc ) : ?>
                            <option value="<?php echo (int) $svc['id']; ?>"
                                <?php selected( (int) $booking['service_id'], (int) $svc['id'] ); ?>>
                                <?php echo esc_html( $svc['name'] ); ?>
                                (<?php echo (int) $svc['duration']; ?> min,
                                <?php echo esc_html( get_option( 'lvb_currency_symbol', '$' ) . number_format( (float) $svc['price'], 2 ) ); ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="lvb_staff_id">Staff</label></th>
                <td>
                    <select id="lvb_staff_id" name="staff_id">
                        <option value="">— Keiner —</option>
                        <?php foreach ( $staff as $s ) : ?>
                            <option value="<?php echo (int) $s['id']; ?>"
                                <?php selected( (int) ( $booking['staff_id'] ?? 0 ), (int) $s['id'] ); ?>>
                                <?php echo esc_html( $s['name'] ); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="lvb_start_datetime">Start</label></th>
                <td>
                    <input type="datetime-local" id="lvb_start_datetime" name="start_datetime"
                           value="<?php echo esc_attr( $start_dt->format( 'Y-m-d\TH:i' ) ); ?>"
                           required>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="lvb_end_datetime">Ende</label></th>
                <td>
                    <input type="datetime-local" id="lvb_end_datetime" name="end_datetime"
                           value="<?php echo esc_attr( $end_dt->format( 'Y-m-d\TH:i' ) ); ?>"
                           required>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="lvb_price">Preis (<?php echo esc_html( get_option( 'lvb_currency_symbol', '$' ) ); ?>)</label></th>
                <td>
                    <input type="number" id="lvb_price" name="price" step="0.01" min="0"
                           value="<?php echo esc_attr( number_format( (float) $booking['price'], 2, '.', '' ) ); ?>"
                           class="small-text">
                    <p class="description">Wird beim Service-Wechsel <strong>nicht</strong> automatisch übernommen — manuell anpassen, falls gewünscht.</p>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="lvb_status">Status</label></th>
                <td>
                    <select id="lvb_status" name="status">
                        <?php foreach ( [ 'pending' => 'Pending', 'confirmed' => 'Confirmed', 'cancelled' => 'Cancelled' ] as $val => $label ) : ?>
                            <option value="<?php echo esc_attr( $val ); ?>"
                                <?php selected( $booking['status'], $val ); ?>>
                                <?php echo esc_html( $label ); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <p class="description">Wechsel auf <em>Cancelled</em> löscht das Google-Calendar-Event und entfernt geplante Reminder.</p>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="lvb_notes">Bemerkungen</label></th>
                <td>
                    <textarea id="lvb_notes" name="notes" rows="4" class="large-text"><?php echo esc_textarea( $booking['notes'] ?? '' ); ?></textarea>
                </td>
            </tr>
        </table>

        <h2>Benachrichtigung</h2>
        <p>
            <label>
                <input type="checkbox" name="lvb_send_notification" value="1">
                Kunde per Email über die Änderungen informieren
                (mit neuem ICS-Anhang, Betreff <code>[Aktualisiert]</code>)
            </label>
        </p>

        <p class="submit">
            <button type="submit" name="lvb_save_booking" value="1" class="button button-primary">Speichern</button>
            <a href="<?php echo esc_url( $back_url ); ?>" class="button">Abbrechen</a>
        </p>
    </form>
</div>
