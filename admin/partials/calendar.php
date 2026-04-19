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
