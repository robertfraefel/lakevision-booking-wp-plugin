<?php
/**
 * LVB_Intake_Form – frontend shortcode and AJAX handler for the intake/registration form.
 *
 * Renders the [lvb_intake_form] shortcode and processes submissions via AJAX.
 * Data is stored in the wp_lvb_intake_forms table and linked to existing
 * customers by email when possible.
 *
 * @package LakeVision_Booking
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class LVB_Intake_Form {

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
        wp_enqueue_style( 'lvb-intake-form' );
        wp_enqueue_script( 'lvb-intake-form' );

        ob_start();
        ?>
        <div class="lvb-intake-form-wrap" id="lvb-intake-form-wrap">
            <form id="lvb-intake-form" class="lvb-intake-form" novalidate>
                <?php wp_nonce_field( 'lvb_intake_form_nonce', 'lvb_intake_nonce' ); ?>

                <!-- 1. E-Mail -->
                <div class="lvb-if-field">
                    <label for="lvb-if-email">E-Mail-Adresse <span class="lvb-if-req">*</span></label>
                    <input type="email" id="lvb-if-email" name="email" required>
                </div>

                <!-- 2. Name -->
                <div class="lvb-if-field">
                    <label for="lvb-if-name">Name Vorname <span class="lvb-if-req">*</span></label>
                    <input type="text" id="lvb-if-name" name="name" required>
                </div>

                <!-- 3. Telefon -->
                <div class="lvb-if-field">
                    <label for="lvb-if-phone">Telefonnummer <span class="lvb-if-req">*</span></label>
                    <input type="tel" id="lvb-if-phone" name="phone" required>
                </div>

                <!-- 4. Wishes checkboxes -->
                <div class="lvb-if-field">
                    <label>Was wünschst du dir von der Sitzung? <span class="lvb-if-req">*</span></label>
                    <div class="lvb-if-checkboxes">
                        <?php
                        $wishes = [
                            'Tiefe Entspannung',
                            'Mehr innere Stabilität',
                            'Klarheit',
                            'Energieaufbau',
                            'Loslassen von Spannungen',
                            'Einfach eine Auszeit für mich',
                            'Ich weiss es noch nicht genau',
                        ];
                        foreach ( $wishes as $w ) : ?>
                            <label class="lvb-if-checkbox-label">
                                <input type="checkbox" name="wishes[]" value="<?php echo esc_attr( $w ); ?>">
                                <span><?php echo esc_html( $w ); ?></span>
                            </label>
                        <?php endforeach; ?>
                        <label class="lvb-if-checkbox-label">
                            <input type="checkbox" name="wishes[]" value="Sonstiges" id="lvb-if-wish-other">
                            <span>Sonstiges</span>
                        </label>
                        <div class="lvb-if-other-wrap" id="lvb-if-other-wrap" style="display:none;">
                            <input type="text" name="wishes_other" id="lvb-if-wishes-other" placeholder="Bitte beschreiben...">
                        </div>
                    </div>
                </div>

                <!-- 5. Health issues -->
                <div class="lvb-if-field">
                    <label for="lvb-if-health">Hast du gesundheitliche Beschwerden oder Diagnosen, die ich wissen sollte? <span class="lvb-if-req">*</span></label>
                    <textarea id="lvb-if-health" name="health_issues" rows="4" required></textarea>
                </div>

                <!-- 6. Medications -->
                <div class="lvb-if-field">
                    <label for="lvb-if-medications">Nimmst du aktuell Medikamente ein? <span class="lvb-if-req">*</span></label>
                    <select id="lvb-if-medications" name="medications" required>
                        <option value="">— Bitte wählen —</option>
                        <option value="yes">Ja</option>
                        <option value="no">Nein</option>
                    </select>
                </div>

                <!-- 7. Psychotherapy -->
                <div class="lvb-if-field">
                    <label for="lvb-if-psychotherapy">Befindest du dich aktuell in psychotherapeutischer oder psychiatrischer Behandlung? <span class="lvb-if-req">*</span></label>
                    <select id="lvb-if-psychotherapy" name="psychotherapy" required>
                        <option value="">— Bitte wählen —</option>
                        <option value="yes">Ja</option>
                        <option value="no">Nein</option>
                    </select>
                </div>

                <!-- 8. Disclaimer -->
                <div class="lvb-if-field lvb-if-disclaimer-field">
                    <div class="lvb-if-disclaimer-text">
                        <?php echo wp_kses_post( get_option( 'lvb_intake_disclaimer', 'ChiroBalance&reg; ist eine komplementäre Methode und ersetzt keine medizinische oder psychotherapeutische Behandlung. Bei akuten körperlichen oder psychischen Beschwerden wende dich bitte an eine Fachperson. Ich arbeite achtsam und innerhalb meiner Kompetenzen.' ) ); ?>
                    </div>
                    <label class="lvb-if-checkbox-label">
                        <input type="checkbox" name="disclaimer_accepted" value="1" required>
                        <span>Ja, ich habe diesen Hinweis gelesen und verstanden. <span class="lvb-if-req">*</span></span>
                    </label>
                </div>

                <!-- 9. Confirmation -->
                <div class="lvb-if-field">
                    <label class="lvb-if-checkbox-label">
                        <input type="checkbox" name="data_confirmed" value="1" required>
                        <span>Ich bestätige die Richtigkeit meiner Angaben. <span class="lvb-if-req">*</span></span>
                    </label>
                </div>

                <div class="lvb-if-submit-wrap">
                    <button type="submit" class="lvb-if-submit" id="lvb-if-submit">Formular absenden</button>
                </div>

                <div class="lvb-if-message" id="lvb-if-message" style="display:none;"></div>
            </form>
        </div>
        <script>
        (function(){
            var otherCb = document.getElementById('lvb-if-wish-other');
            var otherWrap = document.getElementById('lvb-if-other-wrap');
            if(otherCb){
                otherCb.addEventListener('change', function(){
                    otherWrap.style.display = this.checked ? 'block' : 'none';
                });
            }

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

                fetch(lvbIntakeData.ajaxUrl, {
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
     */
    public static function ajax_submit() {
        check_ajax_referer( 'lvb_intake_form_nonce', 'lvb_intake_nonce' );

        $email = sanitize_email( $_POST['email'] ?? '' );
        $name  = sanitize_text_field( $_POST['name'] ?? '' );
        $phone = sanitize_text_field( $_POST['phone'] ?? '' );

        // Validate required fields
        if ( empty( $email ) || ! is_email( $email ) ) {
            wp_send_json_error( [ 'message' => 'Bitte gib eine gültige E-Mail-Adresse ein.' ] );
        }
        if ( empty( $name ) ) {
            wp_send_json_error( [ 'message' => 'Bitte gib deinen Namen ein.' ] );
        }
        if ( empty( $phone ) ) {
            wp_send_json_error( [ 'message' => 'Bitte gib deine Telefonnummer ein.' ] );
        }

        // Wishes
        $wishes_arr = isset( $_POST['wishes'] ) && is_array( $_POST['wishes'] ) ? array_map( 'sanitize_text_field', $_POST['wishes'] ) : [];
        if ( empty( $wishes_arr ) ) {
            wp_send_json_error( [ 'message' => 'Bitte wähle mindestens einen Wunsch aus.' ] );
        }
        // If "Sonstiges" selected, append the text
        if ( in_array( 'Sonstiges', $wishes_arr, true ) && ! empty( $_POST['wishes_other'] ) ) {
            $other_text = sanitize_text_field( $_POST['wishes_other'] );
            // Replace "Sonstiges" with "Sonstiges: <text>"
            $key = array_search( 'Sonstiges', $wishes_arr, true );
            $wishes_arr[ $key ] = 'Sonstiges: ' . $other_text;
        }
        $wishes = implode( ', ', $wishes_arr );

        $health_issues = sanitize_textarea_field( $_POST['health_issues'] ?? '' );
        if ( empty( $health_issues ) ) {
            wp_send_json_error( [ 'message' => 'Bitte beantworte die Frage zu gesundheitlichen Beschwerden.' ] );
        }

        $medications   = in_array( $_POST['medications'] ?? '', [ 'yes', 'no' ], true ) ? $_POST['medications'] : '';
        $psychotherapy = in_array( $_POST['psychotherapy'] ?? '', [ 'yes', 'no' ], true ) ? $_POST['psychotherapy'] : '';

        if ( empty( $medications ) ) {
            wp_send_json_error( [ 'message' => 'Bitte beantworte die Frage zu Medikamenten.' ] );
        }
        if ( empty( $psychotherapy ) ) {
            wp_send_json_error( [ 'message' => 'Bitte beantworte die Frage zur psychotherapeutischen Behandlung.' ] );
        }

        $disclaimer_accepted = ! empty( $_POST['disclaimer_accepted'] ) ? 1 : 0;
        $data_confirmed      = ! empty( $_POST['data_confirmed'] ) ? 1 : 0;

        if ( ! $disclaimer_accepted ) {
            wp_send_json_error( [ 'message' => 'Bitte bestätige den Disclaimer.' ] );
        }
        if ( ! $data_confirmed ) {
            wp_send_json_error( [ 'message' => 'Bitte bestätige die Richtigkeit deiner Angaben.' ] );
        }

        // Try to find matching customer by email
        global $wpdb;
        $customer_id = null;
        $customer = $wpdb->get_row(
            $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}lvb_customers WHERE email = %s", $email ),
            ARRAY_A
        );
        if ( $customer ) {
            $customer_id = (int) $customer['id'];
        }

        $insert_data = [
            'customer_id'         => $customer_id,
            'email'               => $email,
            'name'                => $name,
            'phone'               => $phone,
            'wishes'              => $wishes,
            'health_issues'       => $health_issues,
            'medications'         => $medications,
            'psychotherapy'       => $psychotherapy,
            'disclaimer_accepted' => $disclaimer_accepted,
            'data_confirmed'      => $data_confirmed,
            'created_at'          => current_time( 'mysql' ),
        ];

        $result = LVB_Database::insert( 'intake_forms', $insert_data );

        if ( $result ) {
            wp_send_json_success( [ 'message' => 'Vielen Dank! Dein Fragebogen wurde erfolgreich gesendet.' ] );
        } else {
            wp_send_json_error( [ 'message' => 'Ein Fehler ist aufgetreten. Bitte versuche es später erneut.' ] );
        }
    }
}
