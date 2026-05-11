<?php
/**
 * Admin partial – Customer form (create + edit).
 *
 * Included from customers.php when ?edit=<id> or ?new=1 is set. On submit
 * the form posts back with `lvb_save_customer`; the handler routes by the
 * hidden customer_id (0 = create, >0 = update).
 */
if ( ! defined( 'ABSPATH' ) ) exit;
if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Unauthorised.' );

$edit_id  = isset( $_GET['edit'] ) ? (int) $_GET['edit'] : 0;
$is_new   = isset( $_GET['new'] ) || $edit_id === 0;
$customer = $edit_id > 0 ? LVB_Database::get_by_id( 'customers', $edit_id ) : null;
if ( $edit_id > 0 && ! $customer ) {
    echo '<div class="wrap lvb-wrap"><h1>Customer not found.</h1><p><a href="'
        . esc_url( admin_url( 'admin.php?page=lvb-customers' ) ) . '">&larr; Back to customers</a></p></div>';
    return;
}

$back_url = admin_url( 'admin.php?page=lvb-customers' );
$heading  = $is_new ? 'Neuer Kunde' : sprintf( 'Kunde #%d bearbeiten', (int) $customer['id'] );
$nonce_id = $is_new ? 0 : (int) $customer['id'];

// Birthday: DB stores YYYY-MM-DD; HTML5 date input expects same format.
$birthday_val = '';
if ( $customer && ! empty( $customer['birthday'] ) && $customer['birthday'] !== '0000-00-00' ) {
    $birthday_val = $customer['birthday'];
}
?>
<div class="wrap lvb-wrap">
    <h1 class="wp-heading-inline"><?php echo esc_html( $heading ); ?></h1>
    <a href="<?php echo esc_url( $back_url ); ?>" class="page-title-action">&larr; Zurück zur Liste</a>
    <hr class="wp-header-end">

    <form method="post" action="<?php echo esc_url( admin_url( 'admin.php?page=lvb-customers' ) ); ?>" class="lvb-card" style="max-width:760px;padding:1.5rem;">
        <?php wp_nonce_field( 'lvb_save_customer_' . $nonce_id ); ?>
        <input type="hidden" name="customer_id" value="<?php echo (int) $nonce_id; ?>">

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
                        <p class="description">Existiert ein Kunde mit dieser Email bereits, werden seine Daten aktualisiert — kein Duplikat.</p>
                    <?php else : ?>
                        <p class="description">Die Email ist der eindeutige Schlüssel. Bei Änderung dürfen keine anderen Kunden dieselbe Email haben.</p>
                    <?php endif; ?>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="lvb_phone">Telefon</label></th>
                <td><input type="text" id="lvb_phone" name="phone"
                           value="<?php echo esc_attr( $customer['phone'] ?? '' ); ?>"
                           class="regular-text"></td>
            </tr>
            <tr>
                <th scope="row"><label for="lvb_birthday">Geburtstag</label></th>
                <td><input type="date" id="lvb_birthday" name="birthday"
                           value="<?php echo esc_attr( $birthday_val ); ?>"></td>
            </tr>
            <tr>
                <th scope="row"><label for="lvb_notes">Notizen</label></th>
                <td>
                    <textarea id="lvb_notes" name="notes" rows="4" class="large-text"><?php echo esc_textarea( $customer['notes'] ?? '' ); ?></textarea>
                    <p class="description">Interne Notizen — nicht für den Kunden sichtbar.</p>
                </td>
            </tr>
        </table>

        <p class="submit">
            <button type="submit" name="lvb_save_customer" value="1" class="button button-primary">
                <?php echo $is_new ? 'Kunde anlegen' : 'Speichern'; ?>
            </button>
            <a href="<?php echo esc_url( $back_url ); ?>" class="button">Abbrechen</a>
        </p>
    </form>
</div>
