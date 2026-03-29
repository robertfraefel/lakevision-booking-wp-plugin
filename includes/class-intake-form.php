<?php
/**
 * LVB_Intake_Form – frontend shortcode and AJAX handler for the intake/registration form.
 *
 * Renders the [lvb_intake_form] shortcode and processes submissions via AJAX.
 * Data is stored in the wp_lvb_intake_forms table and linked to existing
 * customers by email when possible.
 *
 * Form fields are now read from the `lvb_intake_form_fields` option,
 * which can be configured via the Form Builder admin page.
 *
 * @package LakeVision_Booking
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class LVB_Intake_Form {

    /**
     * Default field configuration matching the original hardcoded form.
     *
     * @return array
     */
    public static function get_default_fields() {
        return [
            [ 'id' => 'email',         'label' => 'E-Mail-Adresse', 'type' => 'email', 'required' => true, 'enabled' => true ],
            [ 'id' => 'name',          'label' => 'Name Vorname',   'type' => 'text',  'required' => true, 'enabled' => true ],
            [ 'id' => 'phone',         'label' => 'Telefonnummer',  'type' => 'tel',   'required' => true, 'enabled' => true ],
            [ 'id' => 'wishes',        'label' => 'Was wünschst du dir von der Sitzung?', 'type' => 'checkbox-group', 'required' => true, 'enabled' => true, 'options' => [ 'Tiefe Entspannung', 'Mehr innere Stabilität', 'Klarheit', 'Energieaufbau', 'Loslassen von Spannungen', 'Einfach eine Auszeit für mich', 'Ich weiss es noch nicht genau', 'Sonstiges' ] ],
            [ 'id' => 'health',        'label' => 'Hast du gesundheitliche Beschwerden oder Diagnosen, die ich wissen sollte?', 'type' => 'textarea', 'required' => true, 'enabled' => true ],
            [ 'id' => 'medications',   'label' => 'Nimmst du aktuell Medikamente ein?', 'type' => 'select', 'required' => true, 'enabled' => true, 'options' => [ 'Ja', 'Nein' ] ],
            [ 'id' => 'psychotherapy', 'label' => 'Befindest du dich aktuell in psychotherapeutischer oder psychiatrischer Behandlung?', 'type' => 'select', 'required' => true, 'enabled' => true, 'options' => [ 'Ja', 'Nein' ] ],
            [ 'id' => 'disclaimer',    'label' => 'Disclaimer', 'type' => 'single-checkbox', 'required' => true, 'enabled' => true, 'checkbox_text' => 'Ja, ich habe diesen Hinweis gelesen und verstanden.' ],
            [ 'id' => 'confirm',       'label' => 'Bestätigung', 'type' => 'single-checkbox', 'required' => true, 'enabled' => true, 'checkbox_text' => 'Ich bestätige die Richtigkeit meiner Angaben.' ],
        ];
    }

    /**
     * Get the current fields configuration from the database or defaults.
     *
     * @return array
     */
    public static function get_fields_config() {
        $json = get_option( 'lvb_intake_form_fields', '' );
        if ( ! empty( $json ) ) {
            $fields = json_decode( $json, true );
            if ( is_array( $fields ) && ! empty( $fields ) ) {
                return $fields;
            }
        }
        return self::get_default_fields();
    }

    /**
     * Known DB columns in the intake_forms table (excluding custom_fields).
     */
    private static $known_columns = [
        'email', 'name', 'phone', 'wishes', 'health', 'medications',
        'psychotherapy', 'disclaimer', 'confirm',
    ];

    /**
     * Map field IDs to their database column names.
     *
     * @return array  field_id => db_column_name
     */
    private static function field_to_column_map() {
        return [
            'email'         => 'email',
            'name'          => 'name',
            'phone'         => 'phone',
            'wishes'        => 'wishes',
            'health'        => 'health_issues',
            'medications'   => 'medications',
            'psychotherapy' => 'psychotherapy',
            'disclaimer'    => 'disclaimer_accepted',
            'confirm'       => 'data_confirmed',
        ];
    }

    /**
     * Register the shortcode.
     */
    public static function register() {
        add_shortcode( 'lvb_intake_form', [ __CLASS__, 'render' ] );
    }

    /**
     * Render the intake form via the [lvb_intake_form] shortcode.
     *
     * @return string  HTML output.
     */
    public static function render() {
        if ( ! get_option( 'lvb_intake_form_enabled', '0' ) ) {
            return '<p style="text-align:center;color:#7A756C;">Das Anmeldeformular ist derzeit nicht verfügbar.</p>';
        }

        wp_enqueue_style( 'lvb-intake-form' );
        wp_enqueue_script( 'lvb-intake-form' );

        $fields = self::get_fields_config();

        ob_start();
        ?>
        <div class="lvb-intake-form-wrap" id="lvb-intake-form-wrap">
            <form id="lvb-intake-form" class="lvb-intake-form" novalidate>
                <input type="text" name="website_url" style="display:none;position:absolute;left:-9999px;" tabindex="-1" autocomplete="off">

                <?php foreach ( $fields as $field ) :
                    if ( empty( $field['enabled'] ) ) continue;
                    $id       = esc_attr( $field['id'] );
                    $label    = esc_html( $field['label'] );
                    $type     = $field['type'];
                    $required = ! empty( $field['required'] );
                    $req_star = $required ? ' <span class="lvb-if-req">*</span>' : '';
                    $req_attr = $required ? ' required' : '';
                ?>

                <?php if ( $type === 'email' || $type === 'text' || $type === 'tel' ) : ?>
                <div class="lvb-if-field">
                    <label for="lvb-if-<?php echo $id; ?>"><?php echo $label; ?><?php echo $req_star; ?></label>
                    <input type="<?php echo esc_attr( $type ); ?>" id="lvb-if-<?php echo $id; ?>" name="<?php echo $id; ?>"<?php echo $req_attr; ?>>
                </div>

                <?php elseif ( $type === 'textarea' ) : ?>
                <div class="lvb-if-field">
                    <label for="lvb-if-<?php echo $id; ?>"><?php echo $label; ?><?php echo $req_star; ?></label>
                    <textarea id="lvb-if-<?php echo $id; ?>" name="<?php echo $id; ?>" rows="4"<?php echo $req_attr; ?>></textarea>
                </div>

                <?php elseif ( $type === 'select' ) : ?>
                <div class="lvb-if-field">
                    <label for="lvb-if-<?php echo $id; ?>"><?php echo $label; ?><?php echo $req_star; ?></label>
                    <select id="lvb-if-<?php echo $id; ?>" name="<?php echo $id; ?>"<?php echo $req_attr; ?>>
                        <option value="">&mdash; Bitte w&auml;hlen &mdash;</option>
                        <?php foreach ( (array) ( $field['options'] ?? [] ) as $opt ) : ?>
                            <option value="<?php echo esc_attr( $opt ); ?>"><?php echo esc_html( $opt ); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <?php elseif ( $type === 'checkbox-group' ) : ?>
                <div class="lvb-if-field">
                    <label><?php echo $label; ?><?php echo $req_star; ?></label>
                    <div class="lvb-if-checkboxes">
                        <?php
                        $options = (array) ( $field['options'] ?? [] );
                        $has_sonstiges = in_array( 'Sonstiges', $options, true );
                        foreach ( $options as $opt ) :
                            if ( $opt === 'Sonstiges' ) continue; // render last with special handling
                        ?>
                            <label class="lvb-if-checkbox-label">
                                <input type="checkbox" name="<?php echo $id; ?>[]" value="<?php echo esc_attr( $opt ); ?>">
                                <span><?php echo esc_html( $opt ); ?></span>
                            </label>
                        <?php endforeach; ?>
                        <?php if ( $has_sonstiges ) : ?>
                            <label class="lvb-if-checkbox-label">
                                <input type="checkbox" name="<?php echo $id; ?>[]" value="Sonstiges" id="lvb-if-<?php echo $id; ?>-other">
                                <span>Sonstiges</span>
                            </label>
                            <div class="lvb-if-other-wrap" id="lvb-if-<?php echo $id; ?>-other-wrap" style="display:none;">
                                <input type="text" name="<?php echo $id; ?>_other" id="lvb-if-<?php echo $id; ?>-other-text" placeholder="Bitte beschreiben...">
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <?php elseif ( $type === 'single-checkbox' ) : ?>
                <div class="lvb-if-field<?php echo $id === 'disclaimer' ? ' lvb-if-disclaimer-field' : ''; ?>">
                    <?php if ( $id === 'disclaimer' ) : ?>
                        <div class="lvb-if-disclaimer-text">
                            <?php echo wp_kses_post( get_option( 'lvb_intake_disclaimer', 'ChiroBalance&reg; ist eine komplementäre Methode und ersetzt keine medizinische oder psychotherapeutische Behandlung. Bei akuten körperlichen oder psychischen Beschwerden wende dich bitte an eine Fachperson. Ich arbeite achtsam und innerhalb meiner Kompetenzen.' ) ); ?>
                        </div>
                    <?php endif; ?>
                    <label class="lvb-if-checkbox-label">
                        <input type="checkbox" name="<?php echo $id; ?>" value="1"<?php echo $req_attr; ?>>
                        <span><?php echo esc_html( $field['checkbox_text'] ?? $label ); ?><?php echo $req_star; ?></span>
                    </label>
                </div>

                <?php endif; ?>

                <?php endforeach; ?>

                <div class="lvb-if-submit-wrap">
                    <button type="submit" class="lvb-if-submit" id="lvb-if-submit">Formular absenden</button>
                </div>

                
            </form>
                <div class="lvb-if-message" id="lvb-if-message" style="display:none;"></div>
        </div>
        <script>
        (function(){
            // Handle "Sonstiges" toggles for all checkbox-group fields
            <?php foreach ( $fields as $field ) :
                if ( $field['type'] !== 'checkbox-group' || empty( $field['enabled'] ) ) continue;
                $options = (array) ( $field['options'] ?? [] );
                if ( ! in_array( 'Sonstiges', $options, true ) ) continue;
                $fid = esc_js( $field['id'] );
            ?>
            (function(){
                var otherCb = document.getElementById('lvb-if-<?php echo $fid; ?>-other');
                var otherWrap = document.getElementById('lvb-if-<?php echo $fid; ?>-other-wrap');
                if(otherCb && otherWrap){
                    otherCb.addEventListener('change', function(){
                        otherWrap.style.display = this.checked ? 'block' : 'none';
                    });
                }
            })();
            <?php endforeach; ?>

            var form = document.getElementById('lvb-intake-form');
            if(!form) return;

            form.addEventListener('submit', function(e){
                e.preventDefault();
                var btn = document.getElementById('lvb-if-submit');
                var msg = document.getElementById('lvb-if-message');
                btn.disabled = true;
                btn.textContent = 'Wird gesendet...';
                msg.style.display = 'none';

                var fd = new FormData(form);
                fd.append('action', 'lvb_submit_intake_form');

                fetch("<?php echo admin_url("admin-ajax.php"); ?>", {
                    method: 'POST',
                    credentials: 'same-origin',
                    body: fd
                })
                .then(function(r){ return r.json(); })
                .then(function(data){
                    msg.style.display = 'block';
                    if(data.success){
                        msg.className = 'lvb-if-message lvb-if-success';
                        msg.innerHTML = data.data.message || 'Vielen Dank! Dein Fragebogen wurde erfolgreich gesendet.';
                        form.style.display = 'none';
                    } else {
                        msg.className = 'lvb-if-message lvb-if-error';
                        msg.innerHTML = data.data.message || 'Ein Fehler ist aufgetreten.';
                        btn.disabled = false;
                        btn.textContent = 'Formular absenden';
                    }
                })
                .catch(function(){
                    msg.style.display = 'block';
                    msg.className = 'lvb-if-message lvb-if-error';
                    msg.innerHTML = 'Netzwerkfehler. Bitte versuche es erneut.';
                    btn.disabled = false;
                    btn.textContent = 'Formular absenden';
                });
            });
        })();
        </script>
        <?php
        return ob_get_clean();
    }

    /**
     * AJAX handler – process intake form submission.
     *
     * Dynamically processes fields based on the stored configuration.
     */
    public static function ajax_submit() {
        // Nonce check disabled for Cloudflare cache compatibility
        // Spam protection via honeypot field instead
        if ( ! empty( $_POST['website_url'] ) ) { wp_send_json_error( ['message' => 'Spam detected.'] ); return; }

        $fields  = self::get_fields_config();
        $col_map = self::field_to_column_map();

        $insert_data    = [];
        $custom_fields  = [];

        foreach ( $fields as $field ) {
            if ( empty( $field['enabled'] ) ) {
                continue;
            }

            $id       = $field['id'];
            $type     = $field['type'];
            $required = ! empty( $field['required'] );
            $label    = $field['label'];

            // Determine the submitted value based on field type
            $value = null;

            if ( $type === 'checkbox-group' ) {
                $arr = isset( $_POST[ $id ] ) && is_array( $_POST[ $id ] ) ? array_map( 'sanitize_text_field', $_POST[ $id ] ) : [];
                if ( $required && empty( $arr ) ) {
                    wp_send_json_error( [ 'message' => sprintf( 'Bitte fülle das Feld "%s" aus.', $label ) ] );
                }
                // Handle "Sonstiges" text
                if ( in_array( 'Sonstiges', $arr, true ) && ! empty( $_POST[ $id . '_other' ] ) ) {
                    $other_text = sanitize_text_field( $_POST[ $id . '_other' ] );
                    $key = array_search( 'Sonstiges', $arr, true );
                    $arr[ $key ] = 'Sonstiges: ' . $other_text;
                }
                $value = implode( ', ', $arr );

            } elseif ( $type === 'single-checkbox' ) {
                $value = ! empty( $_POST[ $id ] ) ? 1 : 0;
                if ( $required && ! $value ) {
                    wp_send_json_error( [ 'message' => sprintf( 'Bitte bestätige: %s', $field['checkbox_text'] ?? $label ) ] );
                }

            } elseif ( $type === 'email' ) {
                $value = sanitize_email( $_POST[ $id ] ?? '' );
                if ( $required && ( empty( $value ) || ! is_email( $value ) ) ) {
                    wp_send_json_error( [ 'message' => 'Bitte gib eine gültige E-Mail-Adresse ein.' ] );
                }

            } elseif ( $type === 'textarea' ) {
                $value = sanitize_textarea_field( $_POST[ $id ] ?? '' );
                if ( $required && empty( $value ) ) {
                    wp_send_json_error( [ 'message' => sprintf( 'Bitte fülle das Feld "%s" aus.', $label ) ] );
                }

            } elseif ( $type === 'select' ) {
                $value = sanitize_text_field( $_POST[ $id ] ?? '' );
                if ( $required && empty( $value ) ) {
                    wp_send_json_error( [ 'message' => sprintf( 'Bitte fülle das Feld "%s" aus.', $label ) ] );
                }

            } else {
                // text, tel, etc.
                $value = sanitize_text_field( $_POST[ $id ] ?? '' );
                if ( $required && empty( $value ) ) {
                    wp_send_json_error( [ 'message' => sprintf( 'Bitte fülle das Feld "%s" aus.', $label ) ] );
                }
            }

            // Map to known DB column or custom_fields
            if ( isset( $col_map[ $id ] ) ) {
                $db_col = $col_map[ $id ];
                // Special handling for medications/psychotherapy: map Ja/Nein to yes/no
                if ( in_array( $id, [ 'medications', 'psychotherapy' ], true ) && $type === 'select' ) {
                    $val_map = [ 'Ja' => 'yes', 'Nein' => 'no' ];
                    $value = $val_map[ $value ] ?? $value;
                }
                $insert_data[ $db_col ] = $value;
            } else {
                // Custom field — store in custom_fields JSON
                $custom_fields[ $id ] = $value;
            }
        }

        // Try to find matching customer by email
        global $wpdb;
        $customer_id = null;
        if ( ! empty( $insert_data['email'] ) ) {
            $customer = $wpdb->get_row(
                $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}lvb_customers WHERE email = %s", $insert_data['email'] ),
                ARRAY_A
            );
            if ( $customer ) {
                $customer_id = (int) $customer['id'];
            }
        }

        $insert_data['customer_id'] = $customer_id;
        $insert_data['created_at']  = current_time( 'mysql' );

        // Store custom fields as JSON if any
        if ( ! empty( $custom_fields ) ) {
            $insert_data['custom_fields'] = wp_json_encode( $custom_fields, JSON_UNESCAPED_UNICODE );
        }

        $result = LVB_Database::insert( 'intake_forms', $insert_data );

        if ( $result ) {
            // Sync birthday from custom_fields to the customer record
            if ( $customer_id && ! empty( $custom_fields['birthday'] ) ) {
                $wpdb->update(
                    $wpdb->prefix . 'lvb_customers',
                    [ 'birthday' => sanitize_text_field( $custom_fields['birthday'] ) ],
                    [ 'id' => $customer_id ]
                );
            }

            // Send admin email notification with the form data
            LVB_Notifications::send_intake_form_notification( $insert_data, $result );

            // Send confirmation email to the customer
            LVB_Notifications::send_intake_form_customer_confirmation( $insert_data );

            wp_send_json_success( [ 'message' => '<div style="text-align:center;padding:2rem;"><div style="width:60px;height:60px;border-radius:50%;background:linear-gradient(135deg,#C4A68A,#B8976A);display:inline-flex;align-items:center;justify-content:center;margin-bottom:1rem;"><span style="color:#fff;font-size:28px;">✓</span></div><h2 style="font-family:Georgia,serif;font-size:1.8rem;color:#1E1C19;margin:0 0 0.8rem;">Vielen Dank!</h2><p style="color:#3A3530;font-size:1.05rem;line-height:1.7;margin:0 0 0.5rem;">Dein Anmeldeformular wurde erfolgreich gesendet.</p><p style="color:#7A756C;font-size:0.95rem;">Ich habe deine Angaben erhalten und werde mich gut auf unsere Begegnung vorbereiten.</p></div>' ] );
        } else {
            wp_send_json_error( [ 'message' => 'Ein Fehler ist aufgetreten. Bitte versuche es später erneut.' ] );
        }
    }
}
