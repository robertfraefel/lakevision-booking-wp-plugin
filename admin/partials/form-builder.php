<?php
/**
 * Admin partial – Form Builder page.
 *
 * Allows configuring the intake form fields: labels, types, required/enabled
 * status, options for select/checkbox-group fields.
 */
if ( ! defined( 'ABSPATH' ) ) exit;
if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Unauthorised.' );

$fields = LVB_Intake_Form::get_fields_config();
?>
<div class="wrap lvb-wrap">
    <h1>LakeVision Booking &ndash; Intake Form Builder</h1>
    <hr class="wp-header-end">

    <p class="description">Configure the intake form fields displayed on the frontend. Drag rows or change the sort order numbers to reorder fields.</p>

    <form method="post" id="lvb-form-builder">
        <?php wp_nonce_field( 'lvb_form_builder_save' ); ?>

        <!-- Intake Form Settings -->
        <div class="lvb-settings-section" style="margin-bottom:24px;">
            <h2>Intake Form Settings</h2>
            <table class="form-table">
                <tr>
                    <th><label for="lvb_intake_form_enabled">Enable Intake Form</label></th>
                    <td>
                        <input type="checkbox" id="lvb_intake_form_enabled" name="lvb_intake_form_enabled" value="1"
                               <?php checked( 1, get_option( 'lvb_intake_form_enabled', 0 ) ); ?>>
                        <p class="description">Show the intake form on the frontend. When disabled, the shortcode will show a message that the form is currently not available.</p>
                    </td>
                </tr>
                <tr>
                    <th><label for="lvb_intake_disclaimer">Intake Form Disclaimer</label></th>
                    <td>
                        <textarea id="lvb_intake_disclaimer" name="lvb_intake_disclaimer" class="large-text" rows="4"><?php echo esc_textarea( get_option( 'lvb_intake_disclaimer', 'ChiroBalance® ist eine komplementäre Methode und ersetzt keine medizinische oder psychotherapeutische Behandlung. Bei akuten körperlichen oder psychischen Beschwerden wende dich bitte an eine Fachperson. Ich arbeite achtsam und innerhalb meiner Kompetenzen.' ) ); ?></textarea>
                        <p class="description">Disclaimer text shown on the intake form. HTML entities like &amp;reg; are supported.</p>
                    </td>
                </tr>
            </table>
        </div>

        <table class="widefat striped" id="lvb-fb-table">
            <thead>
                <tr>
                    <th style="width:20px" title="Drag to reorder">&#8597;</th>
                    <th style="width:30px">#</th>
                    <th>Field ID</th>
                    <th>Label</th>
                    <th>Type</th>
                    <th>Required</th>
                    <th>Enabled</th>
                    <th>Options / Checkbox Text</th>
                    <th style="width:50px"></th>
                </tr>
            </thead>
            <tbody id="lvb-fb-body">
                <?php foreach ( $fields as $i => $field ) : ?>
                <tr class="lvb-fb-row" data-index="<?php echo $i; ?>">
                    <td class="lvb-drag-handle" title="Drag to reorder"><span class="lvb-grip">&#8942;&#8942;</span></td>
                    <td>
                        <input type="number" name="fields[<?php echo $i; ?>][sort_order]" value="<?php echo $i + 1; ?>" class="small-text" min="1" style="width:50px;">
                    </td>
                    <td>
                        <input type="text" name="fields[<?php echo $i; ?>][id]" value="<?php echo esc_attr( $field['id'] ); ?>" class="regular-text" style="width:120px;" <?php echo in_array( $field['id'], [ 'email', 'name', 'phone', 'wishes', 'health', 'medications', 'psychotherapy', 'disclaimer', 'confirm' ], true ) ? 'readonly' : ''; ?>>
                    </td>
                    <td>
                        <input type="text" name="fields[<?php echo $i; ?>][label]" value="<?php echo esc_attr( $field['label'] ); ?>" class="regular-text" style="width:100%;">
                    </td>
                    <td>
                        <select name="fields[<?php echo $i; ?>][type]" class="lvb-fb-type-select">
                            <?php
                            $types = [ 'text', 'email', 'tel', 'textarea', 'select', 'checkbox-group', 'single-checkbox' ];
                            foreach ( $types as $t ) :
                            ?>
                                <option value="<?php echo esc_attr( $t ); ?>" <?php selected( $field['type'], $t ); ?>><?php echo esc_html( $t ); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                    <td style="text-align:center;">
                        <input type="checkbox" name="fields[<?php echo $i; ?>][required]" value="1" <?php checked( ! empty( $field['required'] ) ); ?>>
                    </td>
                    <td style="text-align:center;">
                        <input type="checkbox" name="fields[<?php echo $i; ?>][enabled]" value="1" <?php checked( ! empty( $field['enabled'] ) ); ?>>
                    </td>
                    <td>
                        <?php if ( in_array( $field['type'], [ 'select', 'checkbox-group' ], true ) && ! empty( $field['options'] ) ) : ?>
                            <textarea name="fields[<?php echo $i; ?>][options]" rows="3" class="large-text" style="width:100%;" placeholder="One option per line"><?php echo esc_textarea( implode( "\n", (array) $field['options'] ) ); ?></textarea>
                        <?php elseif ( $field['type'] === 'single-checkbox' && ! empty( $field['checkbox_text'] ) ) : ?>
                            <input type="text" name="fields[<?php echo $i; ?>][checkbox_text]" value="<?php echo esc_attr( $field['checkbox_text'] ); ?>" class="regular-text" style="width:100%;" placeholder="Checkbox label text">
                        <?php else : ?>
                            <textarea name="fields[<?php echo $i; ?>][options]" rows="3" class="large-text lvb-fb-options" style="width:100%;display:<?php echo in_array( $field['type'], [ 'select', 'checkbox-group' ], true ) ? 'block' : 'none'; ?>;" placeholder="One option per line"></textarea>
                            <input type="text" name="fields[<?php echo $i; ?>][checkbox_text]" value="" class="regular-text lvb-fb-checkbox-text" style="width:100%;display:<?php echo $field['type'] === 'single-checkbox' ? 'block' : 'none'; ?>;" placeholder="Checkbox label text">
                        <?php endif; ?>
                    </td>
                    <td>
                        <button type="button" class="button lvb-fb-remove" title="Remove field">&times;</button>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <p style="margin-top:12px;">
            <button type="button" class="button" id="lvb-fb-add-field">+ Add Field</button>
            &nbsp;
            <a href="<?php echo esc_url( wp_nonce_url( add_query_arg( [ 'page' => 'lvb-form-builder', 'lvb_action' => 'reset_form_fields' ], admin_url( 'admin.php' ) ), 'lvb_reset_form_fields' ) ); ?>"
               class="button" onclick="return confirm('Reset all form fields to defaults? Custom fields will be lost.');">
                Reset to Defaults
            </a>
        </p>

        <?php submit_button( 'Save Form Fields', 'primary', 'lvb_save_form_builder' ); ?>
    </form>
</div>

<script>
(function(){
    var index = <?php echo count( $fields ); ?>;
    var tbody = document.getElementById('lvb-fb-body');

    document.getElementById('lvb-fb-add-field').addEventListener('click', function(){
        var tr = document.createElement('tr');
        tr.className = 'lvb-fb-row';
        tr.innerHTML = '<td class="lvb-drag-handle" title="Drag to reorder"><span class="lvb-grip">&#8942;&#8942;</span></td>'
            + '<td><input type="number" name="fields['+index+'][sort_order]" value="'+(index+1)+'" class="small-text" min="1" style="width:50px;"></td>'
            + '<td><input type="text" name="fields['+index+'][id]" value="" class="regular-text" style="width:120px;" placeholder="field_id"></td>'
            + '<td><input type="text" name="fields['+index+'][label]" value="" class="regular-text" style="width:100%;" placeholder="Field Label"></td>'
            + '<td><select name="fields['+index+'][type]" class="lvb-fb-type-select">'
            + '<option value="text">text</option><option value="email">email</option><option value="tel">tel</option>'
            + '<option value="textarea">textarea</option><option value="select">select</option>'
            + '<option value="checkbox-group">checkbox-group</option><option value="single-checkbox">single-checkbox</option>'
            + '</select></td>'
            + '<td style="text-align:center;"><input type="checkbox" name="fields['+index+'][required]" value="1"></td>'
            + '<td style="text-align:center;"><input type="checkbox" name="fields['+index+'][enabled]" value="1" checked></td>'
            + '<td><textarea name="fields['+index+'][options]" rows="3" class="large-text lvb-fb-options" style="width:100%;display:none;" placeholder="One option per line"></textarea>'
            + '<input type="text" name="fields['+index+'][checkbox_text]" value="" class="regular-text lvb-fb-checkbox-text" style="width:100%;display:none;" placeholder="Checkbox label text"></td>'
            + '<td><button type="button" class="button lvb-fb-remove" title="Remove field">&times;</button></td>';
        tbody.appendChild(tr);
        index++;
    });

    document.addEventListener('click', function(e){
        if(e.target.classList.contains('lvb-fb-remove')){
            if(confirm('Remove this field?')){
                e.target.closest('tr').remove();
            }
        }
    });

    document.addEventListener('change', function(e){
        if(e.target.classList.contains('lvb-fb-type-select')){
            var row = e.target.closest('tr');
            var opts = row.querySelector('.lvb-fb-options') || row.querySelector('textarea[name*="[options]"]');
            var cbText = row.querySelector('.lvb-fb-checkbox-text') || row.querySelector('input[name*="[checkbox_text]"]');
            var val = e.target.value;
            if(opts) opts.style.display = (val === 'select' || val === 'checkbox-group') ? 'block' : 'none';
            if(cbText) cbText.style.display = (val === 'single-checkbox') ? 'block' : 'none';
        }
    });
})();

/* --- Drag & Drop Sortable --- */
jQuery(function($){
    $('#lvb-fb-body').sortable({
        handle: '.lvb-drag-handle',
        axis: 'y',
        cursor: 'grabbing',
        opacity: 0.8,
        placeholder: 'lvb-sortable-placeholder',
        update: function() {
            // Re-number sort order inputs after reorder.
            $('#lvb-fb-body .lvb-fb-row').each(function(i){
                $(this).find('input[name$="[sort_order]"]').val(i + 1);
            });
        }
    });
});
</script>

<style>
    .lvb-drag-handle {
        cursor: move;
        text-align: center;
        width: 20px;
        user-select: none;
    }
    .lvb-grip {
        color: #b4b9be;
        font-size: 16px;
        letter-spacing: -3px;
        line-height: 1;
    }
    .lvb-fb-row:hover .lvb-grip {
        color: #50575e;
    }
    .lvb-sortable-placeholder {
        background: #f0f6fc !important;
        border: 1px dashed #2271b1;
        visibility: visible !important;
    }
    .lvb-sortable-placeholder td {
        visibility: hidden;
    }
</style>
