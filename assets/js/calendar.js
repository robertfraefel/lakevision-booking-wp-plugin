/**
 * LakeVision Booking – admin calendar glue.
 *
 * Mounts a FullCalendar instance against the /lvb/v1/calendar/events REST API.
 * Supports filtering by staff, drag/resize to reschedule (PATCH), and clicking
 * an event to open a modal with details + cancel button.
 */
(function () {
    'use strict';

    if (typeof FullCalendar === 'undefined') {
        console.error('FullCalendar not loaded');
        return;
    }

    var data    = window.lvbCalendar || {};
    var restUrl = data.restUrl;
    var nonce   = data.nonce;
    var staff   = data.staff || [];

    var root        = document.getElementById('lvb-calendar-root');
    var filterSel   = document.getElementById('lvb-cal-staff-filter');
    var legend      = document.getElementById('lvb-cal-legend');
    var modal       = document.getElementById('lvb-cal-modal');
    var btnCancel   = modal.querySelector('.lvb-cal-btn-cancel');

    var activeEvent = null;   // currently open FC event in modal
    var calendar;

    // ----- Populate staff filter + legend -----
    staff.forEach(function (s) {
        var opt = document.createElement('option');
        opt.value = String(s.id);
        opt.textContent = s.name;
        filterSel.appendChild(opt);

        if (s.color) {
            var chip = document.createElement('span');
            chip.className = 'lvb-cal-legend-chip';
            chip.innerHTML = '<span class="dot" style="background:' + s.color + '"></span>' + escapeHtml(s.name);
            legend.appendChild(chip);
        }
    });

    filterSel.addEventListener('change', function () {
        if (calendar) calendar.refetchEvents();
    });

    // ----- Modal wiring -----
    modal.addEventListener('click', function (e) {
        if (e.target.dataset && e.target.dataset.close === '1') closeModal();
    });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && !modal.hasAttribute('hidden')) closeModal();
    });
    btnCancel.addEventListener('click', function () {
        if (!activeEvent) return;
        if (!confirm('Diese Buchung wirklich stornieren? Der Kunde wird per E-Mail benachrichtigt.')) return;

        var id = activeEvent.id;
        fetch(restUrl + '/' + id + '/cancel', {
            method: 'POST',
            headers: { 'X-WP-Nonce': nonce, 'Content-Type': 'application/json' }
        }).then(parseJson).then(function (res) {
            if (res.gcal_warning) {
                alert('Buchung storniert, aber Google Calendar meldete: ' + res.gcal_warning);
            }
            activeEvent.remove();
            closeModal();
        }).catch(function (err) {
            alert('Fehler beim Stornieren: ' + err.message);
        });
    });

    // ----- FullCalendar init -----
    calendar = new FullCalendar.Calendar(root, {
        initialView: 'timeGridWeek',
        locale: data.locale || 'de',
        firstDay: 1,
        slotMinTime: data.timeMin || '07:00:00',
        slotMaxTime: data.timeMax || '22:00:00',
        nowIndicator: true,
        editable: true,
        selectable: false,
        headerToolbar: {
            left:   'prev,next today',
            center: 'title',
            right:  'dayGridMonth,timeGridWeek,timeGridDay,listWeek'
        },
        buttonText: {
            today: 'Heute', month: 'Monat', week: 'Woche', day: 'Tag', list: 'Liste'
        },
        height: 'auto',
        events: fetchEvents,
        eventClick: onEventClick,
        eventDrop: onEventChange,
        eventResize: onEventChange
    });
    calendar.render();

    // ----- Event fetching -----
    function fetchEvents(info, success, failure) {
        var url = new URL(restUrl);
        url.searchParams.set('from', info.startStr);
        url.searchParams.set('to',   info.endStr);
        var staffId = filterSel.value;
        if (staffId) url.searchParams.set('staff_id', staffId);

        fetch(url.toString(), { headers: { 'X-WP-Nonce': nonce } })
            .then(parseJson)
            .then(success)
            .catch(failure);
    }

    // ----- Drag/resize → PATCH -----
    function onEventChange(info) {
        var ev = info.event;
        fetch(restUrl + '/' + ev.id, {
            method: 'PATCH',
            headers: { 'X-WP-Nonce': nonce, 'Content-Type': 'application/json' },
            body: JSON.stringify({ start: ev.start.toISOString(), end: ev.end.toISOString() })
        }).then(parseJson).then(function (res) {
            if (res.gcal_warning) {
                alert('Buchung verschoben, aber Google Calendar meldete: ' + res.gcal_warning);
            }
        }).catch(function (err) {
            alert('Fehler beim Verschieben: ' + err.message);
            info.revert();
        });
    }

    // ----- Click → modal -----
    function onEventClick(info) {
        info.jsEvent.preventDefault();
        activeEvent = info.event;
        var p = info.event.extendedProps;

        setText('service',  p.service);
        setText('staff',    p.staff);
        setText('customer', p.customer_name);
        setHtml('email',    p.customer_email ? '<a href="mailto:' + encodeURI(p.customer_email) + '">' + escapeHtml(p.customer_email) + '</a>' : '—');
        setText('phone',    p.customer_phone);
        setText('when',     formatRange(info.event.start, info.event.end));
        setText('price',    p.price ? p.price : '—');
        setText('status',   p.status);
        setText('notes',    p.notes);

        btnCancel.disabled = (p.status === 'cancelled');
        btnCancel.textContent = (p.status === 'cancelled') ? 'Bereits storniert' : 'Buchung stornieren';

        modal.removeAttribute('hidden');
    }

    function closeModal() {
        activeEvent = null;
        modal.setAttribute('hidden', 'hidden');
    }

    // ----- Helpers -----
    function setText(field, val) {
        var el = modal.querySelector('[data-field="' + field + '"]');
        if (el) el.textContent = val && String(val).trim() ? val : '—';
    }
    function setHtml(field, html) {
        var el = modal.querySelector('[data-field="' + field + '"]');
        if (el) el.innerHTML = html;
    }
    function formatRange(s, e) {
        if (!s) return '—';
        var opts = { weekday: 'short', day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' };
        var out = s.toLocaleString('de-CH', opts);
        if (e) {
            out += ' – ' + e.toLocaleTimeString('de-CH', { hour: '2-digit', minute: '2-digit' });
        }
        return out;
    }
    function escapeHtml(s) {
        return String(s == null ? '' : s)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }
    function parseJson(r) {
        if (!r.ok) {
            return r.json().catch(function () { return {}; }).then(function (body) {
                throw new Error(body.message || ('HTTP ' + r.status));
            });
        }
        return r.json();
    }
})();
