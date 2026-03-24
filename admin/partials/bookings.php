<?php
/**
 * Admin partial – Bookings list.
 */
if ( ! defined( 'ABSPATH' ) ) exit;
if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Unauthorised.' );

// Query params
$current_status = isset( $_GET['status'] )   ? sanitize_text_field( wp_unslash( $_GET['status'] ) )  : '';
$search         = isset( $_GET['s'] )         ? sanitize_text_field( wp_unslash( $_GET['s'] ) )        : '';
$paged          = max( 1, (int) ( $_GET['paged'] ?? 1 ) );
$per_page       = 20;

$allowed_orderby = [
    'id'             => 'b.id',
    'start_datetime' => 'b.start_datetime',
    'customer'       => 'c.last_name',
    'service'        => 'sv.name',
    'price'          => 'b.price',
    'status'         => 'b.status',
];
$orderby_key = isset( $_GET['orderby'] ) && array_key_exists( $_GET['orderby'], $allowed_orderby )
    ? sanitize_text_field( wp_unslash( $_GET['orderby'] ) )
    : 'start_datetime';
$order = isset( $_GET['order'] ) && strtoupper( $_GET['order'] ) === 'ASC' ? 'ASC' : 'DESC';

$args = [
    'status'   => $current_status,
    'search'   => $search,
    'per_page' => $per_page,
    'page'     => $paged,
    'order_by' => $allowed_orderby[ $orderby_key ],
    'order'    => $order,
];

$bookings    = LVB_Database::get_bookings( $args );
$total       = LVB_Database::count_bookings( $args );
$total_pages = max( 1, (int) ceil( $total / $per_page ) );

$status_labels = [
    'pending'   => '<span class="lvb-badge pending">Pending</span>',
    'confirmed' => '<span class="lvb-badge confirmed">Confirmed</span>',
    'cancelled' => '<span class="lvb-badge cancelled">Cancelled</span>',
];
?>
<div class="wrap lvb-wrap">
    <h1 class="wp-heading-inline">LakeVision Booking – Bookings</h1>
    <hr class="wp-header-end">

    <!-- Filter bar -->
    <form method="get" class="lvb-filter-form">
        <input type="hidden" name="page" value="lvb-bookings">
        <div class="lvb-filter-row">
            <input type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="Search customer name or email…" class="regular-text">
            <select name="status">
                <option value="">All Statuses</option>
                <?php foreach ( [ 'pending', 'confirmed', 'cancelled' ] as $s ) : ?>
                    <option value="<?php echo esc_attr( $s ); ?>" <?php selected( $current_status, $s ); ?>>
                        <?php echo esc_html( ucfirst( $s ) ); ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <?php submit_button( 'Filter', 'secondary', '', false ); ?>
            <?php if ( $search || $current_status ) : ?>
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=lvb-bookings' ) ); ?>" class="button">Reset</a>
            <?php endif; ?>
        </div>
    </form>

    <!-- Summary counts -->
    <div class="lvb-stat-row">
        <?php
        foreach ( [ '', 'pending', 'confirmed', 'cancelled' ] as $s ) {
            $cnt   = LVB_Database::count_bookings( [ 'status' => $s ] );
            $label = $s ? ucfirst( $s ) : 'All';
            $url   = add_query_arg( [ 'page' => 'lvb-bookings', 'status' => $s ], admin_url( 'admin.php' ) );
            echo '<a href="' . esc_url( $url ) . '" class="lvb-stat-pill ' . esc_attr( $s ) . ( $current_status === $s ? ' active' : '' ) . '">'
                . esc_html( $label ) . ' <strong>' . esc_html( $cnt ) . '</strong></a> ';
        }
        ?>
    </div>

    <?php if ( empty( $bookings ) ) : ?>
        <p class="lvb-empty">No bookings found.</p>
    <?php else : ?>
    <?php
    $sort_url = function( $col ) use ( $orderby_key, $order ) {
        $new_order = ( $orderby_key === $col && $order === 'ASC' ) ? 'DESC' : 'ASC';
        $url = add_query_arg( [ 'orderby' => $col, 'order' => $new_order ], remove_query_arg( [ 'orderby', 'order' ] ) );
        $arrow = $orderby_key === $col ? ( $order === 'ASC' ? ' ↑' : ' ↓' ) : '';
        return '<a href="' . esc_url( $url ) . '">' . ( $col === 'id' ? '#' : ucfirst( str_replace( '_', ' ', $col ) ) ) . esc_html( $arrow ) . '</a>';
    };
    ?>
    <table class="widefat lvb-table striped">
        <thead>
            <tr>
                <th><?php echo $sort_url( 'id' ); ?></th>
                <th><?php echo $sort_url( 'start_datetime' ); ?></th>
                <th><?php echo $sort_url( 'customer' ); ?></th>
                <th><?php echo $sort_url( 'service' ); ?></th>
                <th>Staff</th>
                <th><?php echo $sort_url( 'price' ); ?></th>
                <th><?php echo $sort_url( 'status' ); ?></th>
                <th>Gebucht am</th>
                <th>Erinnerung</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ( $bookings as $b ) :
            $start  = new DateTime( $b['start_datetime'], wp_timezone() );
            $end    = new DateTime( $b['end_datetime'],   wp_timezone() );
            $cancel_url = wp_nonce_url(
                add_query_arg( [ 'page' => 'lvb-bookings', 'lvb_action' => 'cancel_booking', 'id' => $b['id'] ], admin_url( 'admin.php' ) ),
                'lvb_cancel_booking_' . $b['id']
            );
        ?>
            <tr>
                <td><?php echo esc_html( $b['id'] ); ?></td>
                <td>
                    <strong><?php echo esc_html( $start->format( get_option( 'date_format' ) ) ); ?></strong><br>
                    <span class="lvb-time"><?php echo esc_html( $start->format( 'H:i' ) . ' – ' . $end->format( 'H:i' ) ); ?></span>
                </td>
                <td>
                    <?php echo esc_html( $b['first_name'] . ' ' . $b['last_name'] ); ?><br>
                    <a href="mailto:<?php echo esc_attr( $b['customer_email'] ); ?>"><?php echo esc_html( $b['customer_email'] ); ?></a>
                </td>
                <td><?php echo esc_html( $b['service_name'] ?? '—' ); ?></td>
                <td><?php echo esc_html( $b['staff_name']   ?? '—' ); ?></td>
                <td><?php echo esc_html( get_option( 'lvb_currency_symbol', '$' ) . number_format( (float) $b['price'], 2 ) ); ?></td>
                <td><?php echo wp_kses_post( $status_labels[ $b['status'] ] ?? esc_html( $b['status'] ) ); ?></td>
                <td>
                    <?php
                    $created = new DateTime( $b['created_at'], wp_timezone() );
                    echo esc_html( $created->format( get_option( 'date_format' ) . ' H:i' ) );
                    ?>
                </td>
                <td>
                    <?php if ( ! empty( $b['reminder_sent'] ) ) : ?>
                        <span class="dashicons dashicons-yes-alt" style="color:#46b450" title="Erinnerung gesendet"></span>
                    <?php else : ?>
                        <span class="lvb-muted">—</span>
                    <?php endif; ?>
                </td>
                <td>
                    <?php if ( $b['status'] !== 'cancelled' ) : ?>
                        <a href="<?php echo esc_url( $cancel_url ); ?>"
                           class="button button-small lvb-btn-danger"
                           onclick="return confirm('Cancel this booking?');">Cancel</a>
                    <?php else : ?>
                        <span class="lvb-muted">—</span>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <!-- Pagination -->
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
