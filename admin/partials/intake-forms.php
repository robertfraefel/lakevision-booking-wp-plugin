<?php
/**
 * Admin partial – Intake Forms list.
 */
if ( ! defined( 'ABSPATH' ) ) exit;
if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Unauthorised.' );

// Single-record view?
$view_id = isset( $_GET['view'] ) ? (int) $_GET['view'] : 0;

if ( $view_id ) :
    $form = LVB_Database::get_by_id( 'intake_forms', $view_id );
    if ( ! $form ) {
        echo '<div class="wrap"><p>Intake form not found.</p></div>';
        return;
    }
    $back_url = admin_url( 'admin.php?page=lvb-intake-forms' );
?>
<div class="wrap lvb-wrap">
    <h1 class="wp-heading-inline">
        <a href="<?php echo esc_url( $back_url ); ?>">&larr; Zurück</a> &nbsp; Intake Form #<?php echo esc_html( $form['id'] ); ?>
    </h1>
    <hr class="wp-header-end">

    <table class="widefat fixed striped" style="max-width:700px;">
        <tbody>
            <tr><th style="width:35%;">E-Mail</th><td><?php echo esc_html( $form['email'] ); ?></td></tr>
            <tr><th>Name</th><td><?php echo esc_html( $form['name'] ); ?></td></tr>
            <tr><th>Telefon</th><td><?php echo esc_html( $form['phone'] ); ?></td></tr>
            <tr><th>Wünsche</th><td><?php echo esc_html( $form['wishes'] ); ?></td></tr>
            <tr><th>Gesundheitliche Beschwerden</th><td><?php echo nl2br( esc_html( $form['health_issues'] ) ); ?></td></tr>
            <tr><th>Medikamente</th><td><?php echo $form['medications'] === 'yes' ? 'Ja' : 'Nein'; ?></td></tr>
            <tr><th>Psychotherapeutische Behandlung</th><td><?php echo $form['psychotherapy'] === 'yes' ? 'Ja' : 'Nein'; ?></td></tr>
            <tr><th>Disclaimer akzeptiert</th><td><?php echo $form['disclaimer_accepted'] ? 'Ja' : 'Nein'; ?></td></tr>
            <tr><th>Angaben bestätigt</th><td><?php echo $form['data_confirmed'] ? 'Ja' : 'Nein'; ?></td></tr>
            <tr><th>Verknüpfter Kunde</th><td><?php
                if ( $form['customer_id'] ) {
                    $cust = LVB_Database::get_by_id( 'customers', $form['customer_id'] );
                    if ( $cust ) {
                        echo esc_html( $cust['first_name'] . ' ' . $cust['last_name'] . ' (#' . $cust['id'] . ')' );
                    } else {
                        echo '#' . esc_html( $form['customer_id'] ) . ' (gelöscht)';
                    }
                } else {
                    echo '<em>Kein Kunde verknüpft</em>';
                }
            ?></td></tr>
            <tr><th>Erstellt am</th><td><?php echo esc_html( $form['created_at'] ); ?></td></tr>
        </tbody>
    </table>
</div>
<?php return; endif; ?>

<?php
// List view
$search  = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
$paged   = max( 1, (int) ( $_GET['paged'] ?? 1 ) );
$per_page = 20;

global $wpdb;
$table = $wpdb->prefix . 'lvb_intake_forms';

$where_sql = '';
$params    = [];
if ( ! empty( $search ) ) {
    $like      = '%' . $wpdb->esc_like( $search ) . '%';
    $where_sql = 'WHERE (email LIKE %s OR name LIKE %s)';
    $params[]  = $like;
    $params[]  = $like;
}

$count_sql = "SELECT COUNT(*) FROM $table $where_sql";
$total     = $params
    ? (int) $wpdb->get_var( $wpdb->prepare( $count_sql, ...$params ) )
    : (int) $wpdb->get_var( $count_sql );

$total_pages = max( 1, (int) ceil( $total / $per_page ) );
$offset      = ( $paged - 1 ) * $per_page;

$data_sql = "SELECT * FROM $table $where_sql ORDER BY created_at DESC LIMIT %d OFFSET %d";
$data_params = array_merge( $params, [ $per_page, $offset ] );
$forms = $wpdb->get_results( $wpdb->prepare( $data_sql, ...$data_params ), ARRAY_A );
?>
<div class="wrap lvb-wrap">
    <h1 class="wp-heading-inline">LakeVision Booking – Intake Forms</h1>
    <hr class="wp-header-end">

    <form method="get" class="lvb-filter-form">
        <input type="hidden" name="page" value="lvb-intake-forms">
        <div class="lvb-filter-row">
            <input type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="Suche nach Name oder E-Mail…" class="regular-text">
            <?php submit_button( 'Filter', 'secondary', '', false ); ?>
            <?php if ( $search ) : ?>
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=lvb-intake-forms' ) ); ?>" class="button">Reset</a>
            <?php endif; ?>
        </div>
    </form>

    <p class="lvb-stat-row"><strong><?php echo esc_html( $total ); ?></strong> Intake-Formulare insgesamt</p>

    <?php if ( empty( $forms ) ) : ?>
        <p class="lvb-empty">Keine Formulare gefunden.</p>
    <?php else : ?>
    <table class="widefat lvb-table striped">
        <thead>
            <tr>
                <th>#</th>
                <th>Name</th>
                <th>E-Mail</th>
                <th>Telefon</th>
                <th>Medikamente</th>
                <th>Psychotherapie</th>
                <th>Kunde</th>
                <th>Erstellt am</th>
                <th>Aktion</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ( $forms as $f ) : ?>
            <tr>
                <td><?php echo esc_html( $f['id'] ); ?></td>
                <td><?php echo esc_html( $f['name'] ); ?></td>
                <td><?php echo esc_html( $f['email'] ); ?></td>
                <td><?php echo esc_html( $f['phone'] ); ?></td>
                <td><?php echo $f['medications'] === 'yes' ? 'Ja' : 'Nein'; ?></td>
                <td><?php echo $f['psychotherapy'] === 'yes' ? 'Ja' : 'Nein'; ?></td>
                <td><?php echo $f['customer_id'] ? '#' . esc_html( $f['customer_id'] ) : '—'; ?></td>
                <td><?php echo esc_html( $f['created_at'] ); ?></td>
                <td>
                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=lvb-intake-forms&view=' . $f['id'] ) ); ?>" class="button button-small">Anzeigen</a>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <?php if ( $total_pages > 1 ) : ?>
    <div class="tablenav bottom">
        <div class="tablenav-pages">
            <span class="displaying-num"><?php echo esc_html( sprintf( '%d items', $total ) ); ?></span>
            <?php
            echo paginate_links( [
                'base'      => add_query_arg( 'paged', '%#%' ),
                'format'    => '',
                'current'   => $paged,
                'total'     => $total_pages,
                'prev_text' => '&laquo;',
                'next_text' => '&raquo;',
            ] );
            ?>
        </div>
    </div>
    <?php endif; ?>
    <?php endif; ?>
</div>
