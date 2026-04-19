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
    var createModal = document.getElementById('lvb-cal-create-modal');
    var createForm  = document.getElementById('lvb-cal-create-form');

    var activeEvent = null;   // currently open FC event in modal
    var pendingStart = null;  // ISO start string selected via drag/click
    var meta = null;          // {services, staff, service_staff}
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
        initialView: pickInitialView(),
        locale: data.locale || 'de',
        firstDay: 1,
        slotMinTime: data.timeMin || '07:00:00',
        slotMaxTime: data.timeMax || '22:00:00',
        nowIndicator: true,
        editable: true,
        selectable: true,
        selectMirror: true,
        select: onSelect,
        expandRows: true,
        headerToolbar: headerToolbarForWidth(window.innerWidth),
        buttonText: {
            today: 'Heute', month: 'Monat', week: 'Woche', day: 'Tag', list: 'Liste'
        },
        height: '100%',
        events: fetchEvents,
        eventClick: onEventClick,
        eventDrop: onEventChange,
        eventResize: onEventChange,
        windowResize: function () {
            calendar.setOption('headerToolbar', headerToolbarForWidth(window.innerWidth));
            var target = pickInitialView();
            if (calendar.view.type !== target && shouldAutoSwitch(calendar.view.type, target)) {
                calendar.changeView(target);
            }
        }
    });
    calendar.render();

    function pickInitialView() {
        var w = window.innerWidth;
        if (w < 640)  return 'listWeek';
        if (w < 1100) return 'timeGridDay';
        return 'timeGridWeek';
    }
    function shouldAutoSwitch(current, target) {
        // Only auto-switch when crossing into/out of mobile list view,
        // so manual picks (month/day/week) stick on desktop.
        return current === 'listWeek' || target === 'listWeek';
    }
    function headerToolbarForWidth(w) {
        if (w < 640) {
            return { left: 'prev,next', center: 'title', right: 'listWeek,timeGridDay' };
        }
        return {
            left:   'prev,next today',
            center: 'title',
            right:  'dayGridMonth,timeGridWeek,timeGridDay,listWeek'
        };
    }

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

    // ----- Create booking flow -----
    createModal.addEventListener('click', function (e) {
        if (e.target.dataset && e.target.dataset.close === '1') closeCreate();
    });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && !createModal.hasAttribute('hidden')) closeCreate();
    });

    createForm.querySelector('select[name="service_id"]').addEventListener('change', updateStaffOptions);

    createForm.addEventListener('submit', function (e) {
        e.preventDefault();
        if (!pendingStart) return;

        var fd = new FormData(createForm);
        var body = {
            service_id: parseInt(fd.get('service_id'), 10),
            staff_id:   fd.get('staff_id') ? parseInt(fd.get('staff_id'), 10) : 0,
            start:      pendingStart,
            email:      fd.get('email'),
            first_name: fd.get('first_name'),
            last_name:  fd.get('last_name'),
            phone:      fd.get('phone') || '',
            notes:      fd.get('notes') || ''
        };

        var submitBtn = createForm.querySelector('button[type="submit"]');
        submitBtn.disabled = true;

        fetch(restUrl, {
            method: 'POST',
            headers: { 'X-WP-Nonce': nonce, 'Content-Type': 'application/json' },
            body: JSON.stringify(body)
        }).then(parseJson).then(function () {
            closeCreate();
            calendar.refetchEvents();
        }).catch(function (err) {
            alert('Buchung konnte nicht angelegt werden: ' + err.message);
        }).finally(function () {
            submitBtn.disabled = false;
        });
    });

    function onSelect(info) {
        pendingStart = info.start.toISOString();
        loadMeta().then(function () {
            populateServiceOptions();
            updateStaffOptions();
            createForm.reset();
            createForm.querySelector('output[name="start_display"]').textContent = formatRange(info.start, null);
            createModal.removeAttribute('hidden');
            setTimeout(function () {
                createForm.querySelector('input[name="email"]').focus();
            }, 50);
        });
        calendar.unselect();
    }

    function closeCreate() {
        pendingStart = null;
        createModal.setAttribute('hidden', 'hidden');
    }

    function loadMeta() {
        if (meta) return Promise.resolve(meta);
        var metaUrl = restUrl.replace(/\/events$/, '/meta');
        return fetch(metaUrl, { headers: { 'X-WP-Nonce': nonce } })
            .then(parseJson)
            .then(function (m) { meta = m; return m; });
    }

    function populateServiceOptions() {
        var sel = createForm.querySelector('select[name="service_id"]');
        sel.innerHTML = '';
        meta.services.forEach(function (s) {
            var opt = document.createElement('option');
            opt.value = String(s.id);
            opt.textContent = s.name + ' (' + s.duration + ' min)';
            sel.appendChild(opt);
        });
    }

    function updateStaffOptions() {
        var svcSel   = createForm.querySelector('select[name="service_id"]');
        var staffSel = createForm.querySelector('select[name="staff_id"]');
        var svcId    = parseInt(svcSel.value, 10) || 0;
        var allowed  = (meta.service_staff && meta.service_staff[svcId]) || [];

        staffSel.innerHTML = '';
        var auto = document.createElement('option');
        auto.value = '';
        auto.textContent = '— automatisch —';
        staffSel.appendChild(auto);

        meta.staff.forEach(function (s) {
            if (allowed.length && allowed.indexOf(s.id) === -1) return;
            var opt = document.createElement('option');
            opt.value = String(s.id);
            opt.textContent = s.name;
            staffSel.appendChild(opt);
        });
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
