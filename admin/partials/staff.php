<?php
/**
 * Admin partial – Staff management (list + add/edit form).
 */
if ( ! defined( 'ABSPATH' ) ) exit;
if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Unauthorised.' );

$edit_id         = (int) ( $_GET['edit'] ?? 0 );
$editing         = $edit_id > 0 ? LVB_Database::get_by_id( 'staff', $edit_id ) : null;
$all_staff       = LVB_Database::get_all( 'staff', [], 'name ASC' );
$all_services    = LVB_Database::get_all( 'services', [ 'status' => 'active' ], 'name ASC' );

// Services currently assigned to editing staff member
$assigned_services = [];
if ( $editing ) {
    foreach ( LVB_Database::get_services_for_staff( $edit_id ) as $s ) {
        $assigned_services[] = (int) $s['id'];
    }
}
?>
<div class="wrap lvb-wrap">
    <h1>LakeVision Booking – Staff</h1>
    <hr class="wp-header-end">

    <div class="lvb-two-col">
        <!-- Left: list -->
        <div class="lvb-col-main">
            <?php if ( empty( $all_staff ) ) : ?>
                <p class="lvb-empty">No staff yet. Add one on the right.</p>
            <?php else : ?>
            <table class="widefat lvb-table striped">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Email</th>
                        <th>Phone</th>
                        <th>Calendar ID</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ( $all_staff as $member ) :
                    $delete_url = wp_nonce_url(
                        add_query_arg( [ 'page' => 'lvb-staff', 'lvb_action' => 'delete_staff', 'id' => $member['id'] ], admin_url( 'admin.php' ) ),
                        'lvb_delete_staff_' . $member['id']
                    );
                    $edit_url = add_query_arg( [ 'page' => 'lvb-staff', 'edit' => $member['id'] ], admin_url( 'admin.php' ) );
                    $services_for = LVB_Database::get_services_for_staff( $member['id'] );
                ?>
                    <tr <?php echo ( $edit_id === (int) $member['id'] ) ? 'class="lvb-row-editing"' : ''; ?>>
                        <td>
                            <strong><?php echo esc_html( $member['name'] ); ?></strong>
                            <?php if ( $services_for ) : ?>
                                <br><small class="lvb-muted"><?php echo esc_html( implode( ', ', wp_list_pluck( $services_for, 'name' ) ) ); ?></small>
                            <?php endif; ?>
                        </td>
                        <td><?php echo $member['email'] ? '<a href="mailto:' . esc_attr( $member['email'] ) . '">' . esc_html( $member['email'] ) . '</a>' : '—'; ?></td>
                        <td><?php echo esc_html( $member['phone'] ?: '—' ); ?></td>
                        <td><code class="lvb-code"><?php echo $member['calendar_id'] ? esc_html( $member['calendar_id'] ) : '<em>Not set</em>'; ?></code></td>
                        <td>
                            <span class="lvb-badge <?php echo esc_attr( $member['status'] ); ?>">
                                <?php echo esc_html( ucfirst( $member['status'] ) ); ?>
                            </span>
                        </td>
                        <td>
                            <a href="<?php echo esc_url( $edit_url ); ?>" class="button button-small">Edit</a>
                            <a href="<?php echo esc_url( $delete_url ); ?>" class="button button-small lvb-btn-danger"
                               onclick="return confirm('Delete this staff member?');">Delete</a>
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
                <h2><?php echo $editing ? 'Edit Staff Member' : 'Add New Staff Member'; ?></h2>
                <form method="post">
                    <?php wp_nonce_field( 'lvb_staff_save' ); ?>
                    <input type="hidden" name="staff_id" value="<?php echo esc_attr( $edit_id ); ?>">

                    <div class="lvb-form-group">
                        <label class="lvb-label">Name <span class="req">*</span></label>
                        <input type="text" name="name" class="widefat" required value="<?php echo esc_attr( $editing['name'] ?? '' ); ?>">
                    </div>
                    <div class="lvb-form-group">
                        <label class="lvb-label">Email</label>
                        <input type="email" name="email" class="widefat" value="<?php echo esc_attr( $editing['email'] ?? '' ); ?>">
                    </div>
                    <div class="lvb-form-group">
                        <label class="lvb-label">Phone</label>
                        <input type="tel" name="phone" class="widefat" value="<?php echo esc_attr( $editing['phone'] ?? '' ); ?>">
                    </div>
                    <div class="lvb-form-group">
                        <label class="lvb-label">Google Calendar ID
                            <span class="lvb-help" title="The calendar ID for this staff member's availability. Format: name@group.calendar.google.com or similar.">?</span>
                        </label>
                        <input type="text" name="calendar_id" class="widefat" placeholder="e.g. abc123@group.calendar.google.com"
                               value="<?php echo esc_attr( $editing['calendar_id'] ?? '' ); ?>">
                        <p class="description">Overrides the default calendar for this staff member's availability.</p>
                    </div>
                    <div class="lvb-form-group">
                        <label class="lvb-label">Status</label>
                        <select name="status">
                            <option value="active"   <?php selected( $editing['status'] ?? 'active', 'active' );   ?>>Active</option>
                            <option value="inactive" <?php selected( $editing['status'] ?? '', 'inactive' ); ?>>Inactive</option>
                        </select>
                    </div>

                    <?php if ( ! empty( $all_services ) ) : ?>
                    <div class="lvb-form-group">
                        <label class="lvb-label">Services</label>
                        <?php foreach ( $all_services as $svc ) : ?>
                            <label class="lvb-checkbox-label">
                                <input type="checkbox" name="service_ids[]" value="<?php echo esc_attr( $svc['id'] ); ?>"
                                    <?php checked( in_array( (int) $svc['id'], $assigned_services, true ) ); ?>>
                                <?php echo esc_html( $svc['name'] ); ?>
                            </label>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>

                    <div class="lvb-form-actions">
                        <?php submit_button( $editing ? 'Update Staff' : 'Add Staff', 'primary', 'lvb_save_staff', false ); ?>
                        <?php if ( $editing ) : ?>
                            <a href="<?php echo esc_url( admin_url( 'admin.php?page=lvb-staff' ) ); ?>" class="button">Cancel</a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
