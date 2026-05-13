<?php
/**
 * Admin partial – Booking form (create + edit).
 *
 * Included from bookings.php when:
 *   - ?edit=<id> is present → edit existing booking, prefilled from DB.
 *   - ?new=1   is present  → create a new booking, empty defaults.
 *
 * On submit, the form posts back to ?page=lvb-bookings with `lvb_save_booking`.
 * The hidden `booking_id` field distinguishes create (0) from edit (>0); the
 * admin handler routes to the matching LVB_Booking_Manager method.
 */
if ( ! defined( 'ABSPATH' ) ) exit;
if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Unauthorised.' );

$edit_id = isset( $_GET['edit'] ) ? (int) $_GET['edit'] : 0;
$is_new  = isset( $_GET['new'] ) || $edit_id === 0;

$booking  = $edit_id > 0 ? LVB_Database::get_by_id( 'bookings', $edit_id ) : null;
if ( $edit_id > 0 && ! $booking ) {
    echo '<div class="wrap lvb-wrap"><h1>Booking not found.</h1><p><a href="'
        . esc_url( admin_url( 'admin.php?page=lvb-bookings' ) ) . '">&larr; Back to bookings</a></p></div>';
    return;
}

$customer = $booking ? LVB_Database::get_by_id( 'customers', $booking['customer_id'] ) : null;
$services = LVB_Database::get_all( 'services', [ 'status' => 'active' ], 'sort_order ASC, name ASC' );
$staff    = LVB_Database::get_all( 'staff',    [ 'status' => 'active' ], 'name ASC' );

$tz = wp_timezone();
if ( $booking ) {
    $start_dt = new DateTime( $booking['start_datetime'], $tz );
    $end_dt   = new DateTime( $booking['end_datetime'],   $tz );
} else {
    // Pre-fill the start with "next full hour" so the operator only needs
    // one click to adjust. End stays blank — the description below the
    // field promises "leer lassen, um die Dauer vom Service zu übernehmen"
    // and the backend honours that by deriving end from the service
    // duration. Pre-filling end here would contradict that contract.
    $start_dt = new DateTime( 'now', $tz );
    $start_dt->setTime( (int) $start_dt->format( 'H' ) + 1, 0 );
    $end_dt = null;
}

$back_url = admin_url( 'admin.php?page=lvb-bookings' );
$heading  = $is_new ? 'Neue Buchung' : sprintf( 'Buchung #%d bearbeiten', (int) $booking['id'] );
$nonce_id = $is_new ? 0 : (int) $booking['id'];
?>
<div class="wrap lvb-wrap">
    <h1 class="wp-heading-inline"><?php echo esc_html( $heading ); ?></h1>
    <a href="<?php echo esc_url( $back_url ); ?>" class="page-title-action">&larr; Zurück zur Liste</a>
    <hr class="wp-header-end">

    <form method="post" action="<?php echo esc_url( admin_url( 'admin.php?page=lvb-bookings' ) ); ?>" class="lvb-card" style="max-width:760px;padding:1.5rem;">
        <?php wp_nonce_field( 'lvb_save_booking_' . $nonce_id ); ?>
        <input type="hidden" name="booking_id" value="<?php echo (int) $nonce_id; ?>">

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
                <td>
                    <input type="email" id="lvb_email" name="email"
                           value="<?php echo esc_attr( $customer['email'] ?? '' ); ?>"
                           class="regular-text" required>
                    <?php if ( $is_new ) : ?>
                        <p class="description">Existiert ein Kunde mit dieser Email schon, wird sein Datensatz aktualisiert — kein Duplikat.</p>
                    <?php endif; ?>
                </td>
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
                        <?php if ( $is_new ) : ?>
                            <option value="">— Wählen —</option>
                        <?php endif; ?>
                        <?php foreach ( $services as $svc ) : ?>
                            <option value="<?php echo (int) $svc['id']; ?>"
                                <?php selected( (int) ( $booking['service_id'] ?? 0 ), (int) $svc['id'] ); ?>>
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
                           value="<?php echo $end_dt ? esc_attr( $end_dt->format( 'Y-m-d\TH:i' ) ) : ''; ?>"
                           <?php echo $is_new ? '' : 'required'; ?>>
                    <?php if ( $is_new ) : ?>
                        <p class="description">Leer lassen, um die Dauer vom Service zu übernehmen.</p>
                    <?php endif; ?>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="lvb_price">Preis (<?php echo esc_html( get_option( 'lvb_currency_symbol', '$' ) ); ?>)</label></th>
                <td>
                    <input type="number" id="lvb_price" name="price" step="0.01" min="0"
                           value="<?php echo esc_attr( $booking ? number_format( (float) $booking['price'], 2, '.', '' ) : '' ); ?>"
                           class="small-text">
                    <p class="description">
                        <?php if ( $is_new ) : ?>
                            Leer lassen, um den Standardpreis vom Service zu übernehmen.
                        <?php else : ?>
                            Wird beim Service-Wechsel <strong>nicht</strong> automatisch übernommen — manuell anpassen, falls gewünscht.
                        <?php endif; ?>
                    </p>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="lvb_status">Status</label></th>
                <td>
                    <select id="lvb_status" name="status">
                        <?php
                        $status_options = $is_new
                            ? [ 'confirmed' => 'Confirmed', 'pending' => 'Pending' ]
                            : [ 'pending' => 'Pending', 'confirmed' => 'Confirmed', 'cancelled' => 'Cancelled' ];
                        $current = $booking['status'] ?? 'confirmed';
                        foreach ( $status_options as $val => $label ) :
                        ?>
                            <option value="<?php echo esc_attr( $val ); ?>"
                                <?php selected( $current, $val ); ?>>
                                <?php echo esc_html( $label ); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <?php if ( ! $is_new ) : ?>
                        <p class="description">Wechsel auf <em>Cancelled</em> löscht das Google-Calendar-Event und entfernt geplante Reminder.</p>
                    <?php endif; ?>
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
                <input type="checkbox" name="lvb_send_notification" value="1" <?php checked( $is_new ); ?>>
                <?php if ( $is_new ) : ?>
                    Kunde per Email bestätigen (mit ICS-Anhang)
                <?php else : ?>
                    Kunde per Email über die Änderungen informieren
                    (mit neuem ICS-Anhang, Betreff <code>[Aktualisiert]</code>)
                <?php endif; ?>
            </label>
        </p>

        <p class="submit">
            <button type="submit" name="lvb_save_booking" value="1" class="button button-primary">
                <?php echo $is_new ? 'Buchung anlegen' : 'Speichern'; ?>
            </button>
            <a href="<?php echo esc_url( $back_url ); ?>" class="button">Abbrechen</a>
        </p>
    </form>
</div>
<script>
(function () {
    var startInput   = document.getElementById('lvb_start_datetime');
    var endInput     = document.getElementById('lvb_end_datetime');
    var serviceSelect = document.getElementById('lvb_service_id');

    if (!startInput || !endInput) return;

    // Returns minutes extracted from option label text like "60 min" or "(60 min, …)"
    function durationFromServiceOption(option) {
        if (!option) return null;
        var m = option.textContent.match(/\(?\s*(\d+)\s*min/i);
        return m ? parseInt(m[1], 10) : null;
    }

    // Parse a datetime-local string to a Date object (treats it as local time)
    function parseLocal(val) {
        if (!val) return null;
        // datetime-local format: YYYY-MM-DDTHH:MM
        var parts = val.match(/^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2})/);
        if (!parts) return null;
        return new Date(+parts[1], +parts[2]-1, +parts[3], +parts[4], +parts[5]);
    }

    // Format a Date as datetime-local value YYYY-MM-DDTHH:MM
    function formatLocal(d) {
        var pad = function(n){ return n < 10 ? '0'+n : ''+n; };
        return d.getFullYear() + '-' + pad(d.getMonth()+1) + '-' + pad(d.getDate())
            + 'T' + pad(d.getHours()) + ':' + pad(d.getMinutes());
    }

    // Stored duration in minutes (kept in sync with end-start or service duration)
    var durationMinutes = null;

    function computeInitialDuration() {
        var s = parseLocal(startInput.value);
        var e = parseLocal(endInput.value);
        if (s && e && e > s) {
            durationMinutes = Math.round((e - s) / 60000);
        } else if (serviceSelect) {
            durationMinutes = durationFromServiceOption(serviceSelect.options[serviceSelect.selectedIndex]);
        }
    }

    computeInitialDuration();

    // When end is manually changed → recalculate duration
    endInput.addEventListener('change', function () {
        var s = parseLocal(startInput.value);
        var e = parseLocal(endInput.value);
        if (s && e && e > s) {
            durationMinutes = Math.round((e - s) / 60000);
        }
    });

    // When start changes → shift end by the same duration
    startInput.addEventListener('change', function () {
        if (durationMinutes === null || durationMinutes <= 0) return;
        var s = parseLocal(startInput.value);
        if (!s) return;
        var newEnd = new Date(s.getTime() + durationMinutes * 60000);
        endInput.value = formatLocal(newEnd);
    });

    // For new bookings: when service changes → update duration from service option
    if (serviceSelect) {
        serviceSelect.addEventListener('change', function () {
            var mins = durationFromServiceOption(serviceSelect.options[serviceSelect.selectedIndex]);
            if (mins && mins > 0) {
                durationMinutes = mins;
                // Also update end if start is set
                var s = parseLocal(startInput.value);
                if (s) {
                    endInput.value = formatLocal(new Date(s.getTime() + durationMinutes * 60000));
                }
            }
        });
    }
})();
</script>
