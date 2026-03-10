<?php
/**
 * Admin partial – Services management (list + add/edit form).
 */
if ( ! defined( 'ABSPATH' ) ) exit;
if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Unauthorised.' );

$edit_id  = (int) ( $_GET['edit'] ?? 0 );
$editing  = $edit_id > 0 ? LVB_Database::get_by_id( 'services', $edit_id ) : null;
$services = LVB_Database::get_all( 'services', [], 'sort_order ASC, name ASC' );
?>
<div class="wrap lvb-wrap">
    <h1>LakeVision Booking – Services</h1>
    <hr class="wp-header-end">

    <div class="lvb-two-col">
        <!-- Left: list -->
        <div class="lvb-col-main">
            <?php if ( empty( $services ) ) : ?>
                <p class="lvb-empty">No services yet. Add one on the right.</p>
            <?php else : ?>
            <table class="widefat lvb-table striped">
                <thead>
                    <tr>
                        <th style="width:60px">Order</th>
                        <th>Name</th>
                        <th>Duration</th>
                        <th>Price</th>
                        <th>Buffer</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ( $services as $i => $svc ) :
                    $delete_url  = wp_nonce_url(
                        add_query_arg( [ 'page' => 'lvb-services', 'lvb_action' => 'delete_service', 'id' => $svc['id'] ], admin_url( 'admin.php' ) ),
                        'lvb_delete_service_' . $svc['id']
                    );
                    $edit_url    = add_query_arg( [ 'page' => 'lvb-services', 'edit' => $svc['id'] ], admin_url( 'admin.php' ) );
                    $move_up_url = wp_nonce_url(
                        add_query_arg( [ 'page' => 'lvb-services', 'lvb_action' => 'move_service_up', 'id' => $svc['id'] ], admin_url( 'admin.php' ) ),
                        'lvb_move_service_' . $svc['id']
                    );
                    $move_dn_url = wp_nonce_url(
                        add_query_arg( [ 'page' => 'lvb-services', 'lvb_action' => 'move_service_down', 'id' => $svc['id'] ], admin_url( 'admin.php' ) ),
                        'lvb_move_service_' . $svc['id']
                    );
                    $is_first = ( $i === 0 );
                    $is_last  = ( $i === count( $services ) - 1 );
                ?>
                    <tr <?php echo ( $edit_id === (int) $svc['id'] ) ? 'class="lvb-row-editing"' : ''; ?>>
                        <td style="white-space:nowrap">
                            <?php if ( ! $is_first ) : ?>
                                <a href="<?php echo esc_url( $move_up_url ); ?>" class="button button-small" title="Move up">↑</a>
                            <?php endif; ?>
                            <?php if ( ! $is_last ) : ?>
                                <a href="<?php echo esc_url( $move_dn_url ); ?>" class="button button-small" title="Move down">↓</a>
                            <?php endif; ?>
                        </td>
                        <td>
                            <strong><?php echo esc_html( $svc['name'] ); ?></strong>
                            <?php if ( $svc['description'] ) : ?>
                                <br><small class="lvb-muted"><?php echo esc_html( wp_trim_words( $svc['description'], 12 ) ); ?></small>
                            <?php endif; ?>
                        </td>
                        <td><?php echo esc_html( $svc['duration'] ); ?> min</td>
                        <td><?php echo esc_html( get_option( 'lvb_currency_symbol', '$' ) . number_format( (float) $svc['price'], 2 ) ); ?></td>
                        <td><?php echo esc_html( $svc['buffer_time'] ); ?> min</td>
                        <td>
                            <span class="lvb-badge <?php echo esc_attr( $svc['status'] ); ?>">
                                <?php echo esc_html( ucfirst( $svc['status'] ) ); ?>
                            </span>
                        </td>
                        <td>
                            <a href="<?php echo esc_url( $edit_url ); ?>" class="button button-small">Edit</a>
                            <a href="<?php echo esc_url( $delete_url ); ?>" class="button button-small lvb-btn-danger"
                               onclick="return confirm('Delete this service?');">Delete</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>

        <!-- Right: form -->
        <div class="lvb-col-side">
            <div class="lvb-card">
                <h2><?php echo $editing ? 'Edit Service' : 'Add New Service'; ?></h2>
                <form method="post">
                    <?php wp_nonce_field( 'lvb_service_save' ); ?>
                    <input type="hidden" name="service_id" value="<?php echo esc_attr( $edit_id ); ?>">

                    <div class="lvb-form-group">
                        <label class="lvb-label">Name <span class="req">*</span></label>
                        <input type="text" name="name" class="widefat" required value="<?php echo esc_attr( $editing['name'] ?? '' ); ?>">
                    </div>
                    <div class="lvb-form-group">
                        <label class="lvb-label">Description</label>
                        <textarea name="description" class="widefat" rows="3"><?php echo esc_textarea( $editing['description'] ?? '' ); ?></textarea>
                    </div>
                    <div class="lvb-field-row">
                        <div class="lvb-form-group">
                            <label class="lvb-label">Duration (min)</label>
                            <input type="number" name="duration" class="small-text" min="1" value="<?php echo esc_attr( $editing['duration'] ?? 60 ); ?>">
                        </div>
                        <div class="lvb-form-group">
                            <label class="lvb-label">Price</label>
                            <input type="number" name="price" class="small-text" min="0" step="0.01" value="<?php echo esc_attr( $editing['price'] ?? '0.00' ); ?>">
                        </div>
                    </div>
                    <div class="lvb-field-row">
                        <div class="lvb-form-group">
                            <label class="lvb-label">Buffer Time (min)</label>
                            <input type="number" name="buffer_time" class="small-text" min="0" value="<?php echo esc_attr( $editing['buffer_time'] ?? 0 ); ?>">
                        </div>
                        <div class="lvb-form-group">
                            <label class="lvb-label">Status</label>
                            <select name="status">
                                <option value="active"   <?php selected( $editing['status'] ?? 'active', 'active' );   ?>>Active</option>
                                <option value="inactive" <?php selected( $editing['status'] ?? '', 'inactive' ); ?>>Inactive</option>
                            </select>
                        </div>
                    </div>

                    <div class="lvb-form-actions">
                        <?php submit_button( $editing ? 'Update Service' : 'Add Service', 'primary', 'lvb_save_service', false ); ?>
                        <?php if ( $editing ) : ?>
                            <a href="<?php echo esc_url( admin_url( 'admin.php?page=lvb-services' ) ); ?>" class="button">Cancel</a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
