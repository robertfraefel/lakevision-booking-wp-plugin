<?php
/**
 * Admin partial – Customers list.
 */
if ( ! defined( 'ABSPATH' ) ) exit;
if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Unauthorised.' );

global $wpdb;

$search   = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
$paged    = max( 1, (int) ( $_GET['paged'] ?? 1 ) );
$per_page = 25;
$offset   = ( $paged - 1 ) * $per_page;

if ( $search ) {
    $like       = '%' . $wpdb->esc_like( $search ) . '%';
    $customers  = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT c.*, COUNT(b.id) AS booking_count
             FROM {$wpdb->prefix}lvb_customers c
             LEFT JOIN {$wpdb->prefix}lvb_bookings b ON b.customer_id = c.id
             WHERE c.email LIKE %s OR c.first_name LIKE %s OR c.last_name LIKE %s
             GROUP BY c.id
             ORDER BY c.created_at DESC
             LIMIT %d OFFSET %d",
            $like, $like, $like, $per_page, $offset
        ),
        ARRAY_A
    );
    $total = (int) $wpdb->get_var(
        $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}lvb_customers
             WHERE email LIKE %s OR first_name LIKE %s OR last_name LIKE %s",
            $like, $like, $like
        )
    );
} else {
    $customers = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT c.*, COUNT(b.id) AS booking_count
             FROM {$wpdb->prefix}lvb_customers c
             LEFT JOIN {$wpdb->prefix}lvb_bookings b ON b.customer_id = c.id
             GROUP BY c.id
             ORDER BY c.created_at DESC
             LIMIT %d OFFSET %d",
            $per_page, $offset
        ),
        ARRAY_A
    );
    $total = LVB_Database::count( 'customers' );
}

$total_pages = max( 1, (int) ceil( $total / $per_page ) );
?>
<div class="wrap lvb-wrap">
    <h1 class="wp-heading-inline">LakeVision Booking – Customers</h1>
    <hr class="wp-header-end">

    <form method="get" class="lvb-filter-form">
        <input type="hidden" name="page" value="lvb-customers">
        <div class="lvb-filter-row">
            <input type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="Search by name or email…" class="regular-text">
            <?php submit_button( 'Search', 'secondary', '', false ); ?>
            <?php if ( $search ) : ?>
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=lvb-customers' ) ); ?>" class="button">Reset</a>
            <?php endif; ?>
        </div>
    </form>

    <p class="lvb-total-count"><?php echo esc_html( sprintf( '%d customers total', $total ) ); ?></p>

    <?php if ( empty( $customers ) ) : ?>
        <p class="lvb-empty">No customers found.</p>
    <?php else : ?>
    <table class="widefat lvb-table striped">
        <thead>
            <tr>
                <th>#</th>
                <th>Name</th>
                <th>Email</th>
                <th>Phone</th>
                <th>Bookings</th>
                <th>Member Since</th>
                <th>Notes</th>
                <th>Formular</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ( $customers as $c ) :
            $joined = new DateTime( $c['created_at'] );
            $joined->setTimezone( wp_timezone() );
        ?>
            <tr>
                <td><?php echo esc_html( $c['id'] ); ?></td>
                <td><strong><?php echo esc_html( $c['first_name'] . ' ' . $c['last_name'] ); ?></strong></td>
                <td><a href="mailto:<?php echo esc_attr( $c['email'] ); ?>"><?php echo esc_html( $c['email'] ); ?></a></td>
                <td><?php echo esc_html( $c['phone'] ?: '—' ); ?></td>
                <td>
                    <a href="<?php echo esc_url( add_query_arg( [ 'page' => 'lvb-bookings', 's' => $c['email'] ], admin_url( 'admin.php' ) ) ); ?>">
                        <?php echo esc_html( $c['booking_count'] ); ?>
                    </a>
                </td>
                <td><?php echo esc_html( $joined->format( get_option( 'date_format' ) ) ); ?></td>
                <td><?php echo esc_html( $c['notes'] ? wp_trim_words( $c['notes'], 10 ) : '—' ); ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <?php if ( $total_pages > 1 ) : ?>
    <div class="tablenav bottom">
        <div class="tablenav-pages">
            <span class="displaying-num"><?php echo esc_html( $total . ' items' ); ?></span>
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
