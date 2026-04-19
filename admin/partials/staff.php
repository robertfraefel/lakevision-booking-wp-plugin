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

$working_hours = LVB_Staff_Schedule::parse_working_hours( $editing['working_hours'] ?? null );
$time_off      = LVB_Staff_Schedule::parse_time_off( $editing['time_off'] ?? null );
$day_labels    = [
    'mon' => 'Mo', 'tue' => 'Di', 'wed' => 'Mi', 'thu' => 'Do',
    'fri' => 'Fr', 'sat' => 'Sa', 'sun' => 'So',
];
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

                    <div class="lvb-form-group">
                        <label class="lvb-label">Arbeitszeiten</label>
                        <p class="description" style="margin-top:0;">
                            Wiederkehrende Arbeitstage und -stunden. Wenn gesetzt, werden die
                            Verfügbarkeiten daraus generiert (ersetzt den Google Calendar).
                            Ein Tag ohne Zeiten = geschlossen.
                        </p>
                        <table class="lvb-schedule-table widefat" style="margin-top:8px;">
                            <tbody>
                            <?php foreach ( $day_labels as $day_key => $day_label ) : ?>
                                <tr data-day="<?php echo esc_attr( $day_key ); ?>">
                                    <th style="width:48px;vertical-align:top;padding-top:12px;"><?php echo esc_html( $day_label ); ?></th>
                                    <td>
                                        <div class="lvb-schedule-windows" data-day="<?php echo esc_attr( $day_key ); ?>">
                                            <?php
                                            $windows = $working_hours[ $day_key ] ?: [ [ 's' => '', 'e' => '' ] ];
                                            foreach ( $windows as $i => $win ) :
                                            ?>
                                                <div class="lvb-schedule-row">
                                                    <input type="time" name="working_hours[<?php echo esc_attr( $day_key ); ?>][<?php echo (int) $i; ?>][s]" value="<?php echo esc_attr( $win['s'] ); ?>" step="900">
                                                    <span>–</span>
                                                    <input type="time" name="working_hours[<?php echo esc_attr( $day_key ); ?>][<?php echo (int) $i; ?>][e]" value="<?php echo esc_attr( $win['e'] ); ?>" step="900">
                                                    <button type="button" class="button button-small lvb-remove-window" title="Entfernen">×</button>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                        <button type="button" class="button button-small lvb-add-window" data-day="<?php echo esc_attr( $day_key ); ?>">+ Fenster hinzufügen</button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <div class="lvb-form-group">
                        <label class="lvb-label">Ferien / Freie Tage</label>
                        <p class="description" style="margin-top:0;">
                            Datumsbereiche, an denen dieser Mitarbeiter nicht verfügbar ist.
                        </p>
                        <div id="lvb-time-off-list" style="margin-top:8px;">
                            <?php
                            $rows = $time_off ?: [ [ 'from' => '', 'to' => '', 'reason' => '' ] ];
                            foreach ( $rows as $i => $row ) :
                            ?>
                                <div class="lvb-time-off-row" style="display:flex;gap:8px;margin-bottom:6px;align-items:center;">
                                    <input type="date" name="time_off[<?php echo (int) $i; ?>][from]" value="<?php echo esc_attr( $row['from'] ); ?>">
                                    <span>bis</span>
                                    <input type="date" name="time_off[<?php echo (int) $i; ?>][to]"   value="<?php echo esc_attr( $row['to'] ); ?>">
                                    <input type="text" name="time_off[<?php echo (int) $i; ?>][reason]" value="<?php echo esc_attr( $row['reason'] ); ?>" placeholder="Grund (optional)" class="regular-text" style="flex:1;">
                                    <button type="button" class="button button-small lvb-remove-timeoff" title="Entfernen">×</button>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <button type="button" class="button button-small" id="lvb-add-timeoff">+ Zeitraum hinzufügen</button>
                    </div>

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

<style>
.lvb-schedule-table th,.lvb-schedule-table td{padding:6px 8px;border-bottom:1px solid #eee;}
.lvb-schedule-row{display:flex;gap:6px;align-items:center;margin-bottom:4px;}
.lvb-schedule-row input[type=time]{width:110px;}
.lvb-time-off-row input[type=date]{width:145px;}
</style>
<script>
(function(){
    function reindex(container, baseName){
        container.querySelectorAll('.lvb-schedule-row, .lvb-time-off-row').forEach(function(row,i){
            row.querySelectorAll('input').forEach(function(inp){
                inp.name = inp.name.replace(/\[\d+\]/, '[' + i + ']');
            });
        });
    }
    document.querySelectorAll('.lvb-add-window').forEach(function(btn){
        btn.addEventListener('click', function(){
            var day = btn.getAttribute('data-day');
            var wrap = document.querySelector('.lvb-schedule-windows[data-day="' + day + '"]');
            var idx  = wrap.querySelectorAll('.lvb-schedule-row').length;
            var row  = document.createElement('div');
            row.className = 'lvb-schedule-row';
            row.innerHTML = '<input type="time" name="working_hours[' + day + '][' + idx + '][s]" step="900">'
                          + '<span>–</span>'
                          + '<input type="time" name="working_hours[' + day + '][' + idx + '][e]" step="900">'
                          + '<button type="button" class="button button-small lvb-remove-window" title="Entfernen">×</button>';
            wrap.appendChild(row);
        });
    });
    document.addEventListener('click', function(e){
        if (e.target.classList.contains('lvb-remove-window')) {
            var wrap = e.target.closest('.lvb-schedule-windows');
            e.target.closest('.lvb-schedule-row').remove();
            reindex(wrap);
        }
        if (e.target.classList.contains('lvb-remove-timeoff')) {
            var list = document.getElementById('lvb-time-off-list');
            e.target.closest('.lvb-time-off-row').remove();
            reindex(list);
        }
    });
    var addTo = document.getElementById('lvb-add-timeoff');
    if (addTo) addTo.addEventListener('click', function(){
        var list = document.getElementById('lvb-time-off-list');
        var idx  = list.querySelectorAll('.lvb-time-off-row').length;
        var row  = document.createElement('div');
        row.className = 'lvb-time-off-row';
        row.style.cssText = 'display:flex;gap:8px;margin-bottom:6px;align-items:center;';
        row.innerHTML = '<input type="date" name="time_off[' + idx + '][from]">'
                      + '<span>bis</span>'
                      + '<input type="date" name="time_off[' + idx + '][to]">'
                      + '<input type="text" name="time_off[' + idx + '][reason]" placeholder="Grund (optional)" class="regular-text" style="flex:1;">'
                      + '<button type="button" class="button button-small lvb-remove-timeoff" title="Entfernen">×</button>';
        list.appendChild(row);
    });
})();
</script>
