<?php
/**
 * Admin partial – Calendar view.
 *
 * Renders the FullCalendar shell + staff filter. All data loading and user
 * interactions are handled by assets/js/calendar.js against the LVB_Calendar_API
 * REST endpoints.
 */
if ( ! defined( 'ABSPATH' ) ) exit;
if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Unauthorised.' );
?>
<div class="wrap lvb-wrap">
    <h1 class="wp-heading-inline">LakeVision Booking – Kalender</h1>
    <hr class="wp-header-end">

    <div class="lvb-cal-toolbar">
        <label for="lvb-cal-staff-filter"><strong>Mitarbeiter:</strong></label>
        <select id="lvb-cal-staff-filter">
            <option value="">Alle</option>
        </select>
        <span class="lvb-cal-legend" id="lvb-cal-legend"></span>
    </div>

    <div id="lvb-calendar-root" class="lvb-cal-root"></div>

    <!-- Create booking modal -->
    <div id="lvb-cal-create-modal" class="lvb-cal-modal" hidden>
        <div class="lvb-cal-modal-backdrop" data-close="1"></div>
        <div class="lvb-cal-modal-dialog" role="dialog" aria-modal="true" aria-labelledby="lvb-cal-create-title">
            <button type="button" class="lvb-cal-modal-close" data-close="1" aria-label="Schliessen">&times;</button>
            <h2 id="lvb-cal-create-title">Neue Buchung</h2>

            <form id="lvb-cal-create-form" class="lvb-cal-form">
                <div class="lvb-cal-form-row">
                    <label>Service <span class="req">*</span></label>
                    <select name="service_id" required></select>
                </div>
                <div class="lvb-cal-form-row">
                    <label>Mitarbeiter</label>
                    <select name="staff_id">
                        <option value="">— automatisch —</option>
                    </select>
                </div>
                <div class="lvb-cal-form-row lvb-cal-form-readonly">
                    <label>Start</label>
                    <output name="start_display">—</output>
                </div>
                <hr>
                <div class="lvb-cal-form-row">
                    <label>E-Mail <span class="req">*</span></label>
                    <input type="email" name="email" required>
                </div>
                <div class="lvb-cal-form-grid2">
                    <div class="lvb-cal-form-row">
                        <label>Vorname <span class="req">*</span></label>
                        <input type="text" name="first_name" required>
                    </div>
                    <div class="lvb-cal-form-row">
                        <label>Nachname <span class="req">*</span></label>
                        <input type="text" name="last_name" required>
                    </div>
                </div>
                <div class="lvb-cal-form-row">
                    <label>Telefon</label>
                    <input type="tel" name="phone">
                </div>
                <div class="lvb-cal-form-row">
                    <label>Bemerkungen</label>
                    <textarea name="notes" rows="2"></textarea>
                </div>

                <p class="description">Eine bestehende Kundin wird anhand der E-Mail-Adresse erkannt und aktualisiert.</p>

                <div class="lvb-cal-modal-actions">
                    <button type="button" class="button" data-close="1">Abbrechen</button>
                    <button type="submit" class="button button-primary">Buchung anlegen</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Event detail modal -->
    <div id="lvb-cal-modal" class="lvb-cal-modal" hidden>
        <div class="lvb-cal-modal-backdrop" data-close="1"></div>
        <div class="lvb-cal-modal-dialog" role="dialog" aria-modal="true" aria-labelledby="lvb-cal-modal-title">
            <button type="button" class="lvb-cal-modal-close" data-close="1" aria-label="Schliessen">&times;</button>
            <h2 id="lvb-cal-modal-title">Buchung</h2>
            <dl class="lvb-cal-modal-meta">
                <dt>Service</dt>        <dd data-field="service">—</dd>
                <dt>Mitarbeiter</dt>    <dd data-field="staff">—</dd>
                <dt>Kunde</dt>          <dd data-field="customer">—</dd>
                <dt>Email</dt>          <dd data-field="email">—</dd>
                <dt>Telefon</dt>        <dd data-field="phone">—</dd>
                <dt>Datum</dt>          <dd data-field="when">—</dd>
                <dt>Preis</dt>          <dd data-field="price">—</dd>
                <dt>Status</dt>         <dd data-field="status">—</dd>
                <dt>Bemerkungen</dt>    <dd data-field="notes">—</dd>
            </dl>
            <div class="lvb-cal-modal-actions">
                <button type="button" class="button" data-close="1">Schliessen</button>
                <button type="button" class="button button-danger lvb-cal-btn-cancel">Buchung stornieren</button>
            </div>
        </div>
    </div>
</div>
