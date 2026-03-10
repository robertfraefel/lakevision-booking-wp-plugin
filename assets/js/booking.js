/**
 * LakeVision Booking – Frontend booking widget JavaScript
 *
 * Flow:
 *  Step 1 → Pick a date from the calendar
 *  Step 2 → Choose sport → pick a sub-slot (based on service duration)
 *  Step 3 → Fill in personal details & submit
 *  Step 4 → Success confirmation
 */

( function ( $ ) {
    'use strict';

    /* ===================================================================
       State
    =================================================================== */
    var state = {
        currentYear   : 0,
        currentMonth  : 0,       // 0-based JS month
        selectedDate  : null,    // 'YYYY-MM-DD'
        selectedSlot  : null,    // generated sub-slot object
        slotsByDate   : {},      // availability windows keyed by 'YYYY-MM-DD'
        bookedByDate  : {},      // booked slots keyed by 'YYYY-MM-DD'
        allSlots      : [],
        serviceId     : 0,
        serviceDuration : 0,     // minutes, from selected option's data-duration
        staffId       : 0,
        slotsLoaded   : false,
        loadingSlots  : false,
    };

    /* ===================================================================
       DOM shortcuts (resolved once on init)
    =================================================================== */
    var $app, $steps, $panels, $calGrid, $monthLabel, $slotsContainer,
        $serviceSelect, $staffWrap, $staffSelect, $bookingForm,
        $selectedDateLabel, $submitBtn, $bookingSummary, $formError,
        $confirmationDetails;

    /* ===================================================================
       Initialise
    =================================================================== */
    function init() {
        $app = $( '#lvb-booking-app' );
        if ( ! $app.length ) return;

        $steps              = $app.find( '.lvb-step' );
        $panels             = $app.find( '.lvb-panel' );
        $calGrid            = $app.find( '#lvb-calendar-grid' );
        $monthLabel         = $app.find( '#lvb-month-label' );
        $slotsContainer     = $app.find( '#lvb-slots-container' );
        $serviceSelect      = $app.find( '#lvb-service-select' );
        $staffWrap          = $app.find( '#lvb-staff-wrap' );
        $staffSelect        = $app.find( '#lvb-staff-select' );
        $bookingForm        = $app.find( '#lvb-booking-form' );
        $selectedDateLabel  = $app.find( '#lvb-selected-date-label' );
        $submitBtn          = $app.find( '#lvb-submit-btn' );
        $bookingSummary     = $app.find( '#lvb-booking-summary' );
        $formError          = $app.find( '#lvb-form-error' );
        $confirmationDetails = $app.find( '#lvb-confirmation-details' );

        // Pre-select service/staff from shortcode atts
        var preService = parseInt( $app.data( 'service' ), 10 ) || 0;
        var preStaff   = parseInt( $app.data( 'staff' ),   10 ) || 0;
        if ( preService ) {
            $serviceSelect.val( preService );
            state.serviceId = preService;
            state.serviceDuration = parseInt( $serviceSelect.find( 'option:selected' ).data( 'duration' ), 10 ) || 0;
        }
        if ( preStaff ) $staffSelect.val( preStaff );

        // Today's date
        var now = new Date();
        state.currentYear  = now.getFullYear();
        state.currentMonth = now.getMonth();

        // Bind events
        $app.on( 'click', '#lvb-prev-month',        onPrevMonth );
        $app.on( 'click', '#lvb-next-month',        onNextMonth );
        $app.on( 'click', '.lvb-cal-day.available', onDayClick );
        $app.on( 'click', '.lvb-back',              onBack );
        $app.on( 'click', '.lvb-slot-btn',          onSlotClick );
        $app.on( 'change', '#lvb-service-select',   onServiceChangeStep2 );
        $app.on( 'click', '#lvb-book-another',      onBookAnother );
        $bookingForm.on( 'submit', onFormSubmit );

        // Load initial slot data then render calendar
        fetchSlotsForMonth();
    }

    /* ===================================================================
       Calendar navigation
    =================================================================== */
    function onPrevMonth() {
        state.currentMonth--;
        if ( state.currentMonth < 0 ) {
            state.currentMonth = 11;
            state.currentYear--;
        }
        state.slotsLoaded = false;
        fetchSlotsForMonth();
    }

    function onNextMonth() {
        state.currentMonth++;
        if ( state.currentMonth > 11 ) {
            state.currentMonth = 0;
            state.currentYear++;
        }
        state.slotsLoaded = false;
        fetchSlotsForMonth();
    }

    /* ===================================================================
       Fetch slots from the server (AJAX)
    =================================================================== */
    function fetchSlotsForMonth() {
        if ( state.loadingSlots ) return;

        var year  = state.currentYear;
        var month = state.currentMonth;   // 0-based

        var dateFrom = padDate( year, month + 1, 1 );
        var lastDay  = new Date( year, month + 1, 0 ).getDate();
        var dateTo   = padDate( year, month + 1, lastDay );

        $calGrid.html( '<div class="lvb-cal-loading">Verfügbarkeit wird geladen…</div>' );
        state.loadingSlots = true;

        $.post( lvbData.ajaxUrl, {
            action     : 'lvb_get_slots',
            nonce      : lvbData.nonce,
            date_from  : dateFrom,
            date_to    : dateTo,
            service_id : state.serviceId,
            staff_id   : state.staffId,
        } )
        .done( function ( res ) {
            if ( res.success ) {
                state.slotsByDate  = res.data.by_date        || {};
                state.bookedByDate = res.data.booked_by_date || {};
                state.allSlots     = res.data.slots          || [];
            } else {
                state.slotsByDate  = {};
                state.bookedByDate = {};
                state.allSlots     = [];
            }
        } )
        .fail( function () {
            state.slotsByDate  = {};
            state.bookedByDate = {};
            state.allSlots     = [];
        } )
        .always( function () {
            state.loadingSlots = false;
            state.slotsLoaded  = true;
            renderCalendar();
        } );
    }

    /* ===================================================================
       Render calendar grid
    =================================================================== */
    function renderCalendar() {
        var year  = state.currentYear;
        var month = state.currentMonth;
        var today = todayStr();

        // Month label
        var monthNames = [ 'Januar','Februar','März','April','Mai','Juni',
                           'Juli','August','September','Oktober','November','Dezember' ];
        $monthLabel.text( monthNames[ month ] + ' ' + year );

        var html = '';

        // Day headers (Mo first for CH/DE)
        var days = [ 'Mo','Di','Mi','Do','Fr','Sa','So' ];
        days.forEach( function ( d ) {
            html += '<div class="lvb-cal-day-header">' + d + '</div>';
        } );

        // First day of month – shift so Monday = 0
        var firstDow = ( new Date( year, month, 1 ).getDay() + 6 ) % 7;
        // Blanks
        for ( var b = 0; b < firstDow; b++ ) {
            html += '<div class="lvb-cal-day empty"></div>';
        }

        var daysInMonth = new Date( year, month + 1, 0 ).getDate();
        for ( var d = 1; d <= daysInMonth; d++ ) {
            var dateStr   = padDate( year, month + 1, d );
            var hasSlots  = !! ( state.slotsByDate[ dateStr ] && state.slotsByDate[ dateStr ].length );
            var isPast    = dateStr < today;
            var isToday   = dateStr === today;
            var isSelected = dateStr === state.selectedDate;

            var cls = 'lvb-cal-day';
            if ( isPast )         cls += ' past';
            else if ( hasSlots )  cls += ' available';
            else                  cls += ' unavailable';
            if ( isToday )        cls += ' today';
            if ( isSelected )     cls += ' selected';

            html += '<div class="' + cls + '" data-date="' + dateStr + '">' + d + '</div>';
        }

        $calGrid.html( html );
    }

    /* ===================================================================
       Day click → go to step 2
    =================================================================== */
    function onDayClick() {
        var dateStr = $( this ).data( 'date' );
        state.selectedDate = dateStr;

        // Visually mark selected
        $calGrid.find( '.lvb-cal-day' ).removeClass( 'selected' );
        $( this ).addClass( 'selected' );

        // Update date label + available time ranges
        var d     = new Date( dateStr + 'T00:00:00' );
        var label = d.toLocaleDateString( 'de-CH', { weekday:'long', year:'numeric', month:'long', day:'numeric' } );

        var windows   = state.slotsByDate[ dateStr ] || [];
        var timeRanges = windows.map( function ( w ) {
            return w.start_time + ' – ' + w.end_time;
        } ).join( ', ' );

        var labelHtml = escHtml( label );
        if ( timeRanges ) {
            labelHtml += ' <span class="lvb-date-times">' + escHtml( timeRanges ) + '</span>';
        }
        $selectedDateLabel.html( labelHtml );

        goToStep( 2 );
        renderSlots();
    }

    /* ===================================================================
       Service change in Step 2 → regenerate sub-slots
    =================================================================== */
    function onServiceChangeStep2() {
        state.serviceId      = parseInt( $serviceSelect.val(), 10 ) || 0;
        state.serviceDuration = parseInt( $serviceSelect.find( 'option:selected' ).data( 'duration' ), 10 ) || 0;
        state.selectedSlot   = null;   // reset previously selected sub-slot
        renderSlots();
    }

    /* ===================================================================
       Generate sub-slots from availability windows
       Each window (e.g. 18:00–22:00) is split into chunks of durationMin.
       Already-booked time ranges are excluded.
    =================================================================== */
    function generateSubSlots( windows, durationMin, bufferMin, bookedWindows ) {
        var subSlots = [];
        var stepMs   = ( durationMin + bufferMin ) * 60000;
        var bufferMs = bufferMin * 60000;

        // Pre-parse booked ranges once
        var bookedRanges = [];
        $.each( bookedWindows || [], function ( i, b ) {
            bookedRanges.push( {
                start : new Date( b.start.replace( ' ', 'T' ) ).getTime(),
                end   : new Date( b.end.replace( ' ', 'T' ) ).getTime(),
            } );
        } );

        $.each( windows, function ( i, win ) {
            // Parse as local time (server returns 'YYYY-MM-DD HH:MM:SS')
            var start = new Date( win.start.replace( ' ', 'T' ) );
            var end   = new Date( win.end.replace( ' ', 'T' ) );
            var cur   = new Date( start.getTime() );

            while ( cur.getTime() + durationMin * 60000 <= end.getTime() ) {
                var slotEnd    = new Date( cur.getTime() + durationMin * 60000 );
                var curMs      = cur.getTime();
                var slotEndMs  = slotEnd.getTime();

                // Block if this slot (incl. its own buffer) overlaps any booked range
                var blocked = false;
                for ( var r = 0; r < bookedRanges.length; r++ ) {
                    if ( curMs < bookedRanges[ r ].end && slotEndMs + bufferMs > bookedRanges[ r ].start ) {
                        blocked = true;
                        break;
                    }
                }

                if ( ! blocked ) {
                    subSlots.push( {
                        id         : win.id,
                        start      : fmtDateTime( cur ),
                        end        : fmtDateTime( slotEnd ),
                        win_end    : fmtDateTime( end ),
                        start_time : padTwo( cur.getHours() ) + ':' + padTwo( cur.getMinutes() ),
                        end_time   : padTwo( slotEnd.getHours() ) + ':' + padTwo( slotEnd.getMinutes() ),
                        start_date : win.start_date,
                        duration   : durationMin,
                    } );
                    cur = new Date( curMs + stepMs );
                } else {
                    // Jump past the end of the blocking booked range
                    var jumpMs = curMs + stepMs;
                    for ( var j = 0; j < bookedRanges.length; j++ ) {
                        if ( curMs < bookedRanges[ j ].end && slotEndMs + bufferMs > bookedRanges[ j ].start ) {
                            if ( bookedRanges[ j ].end > jumpMs ) {
                                jumpMs = bookedRanges[ j ].end;
                            }
                        }
                    }
                    cur = new Date( jumpMs );
                }
            }
        } );
        return subSlots;
    }

    /* ===================================================================
       Render sub-slot buttons for the selected date
    =================================================================== */
    function renderSlots() {
        if ( ! state.selectedDate ) return;

        if ( ! state.slotsLoaded ) {
            $slotsContainer.html( '<div class="lvb-slots-loading">Slots werden geladen…</div>' );
            return;
        }

        // Require sport selection first
        if ( ! state.serviceId || ! state.serviceDuration ) {
            $slotsContainer.html( '<p class="lvb-no-slots">Bitte wähle zuerst eine Sportart aus.</p>' );
            return;
        }

        var windows = state.slotsByDate[ state.selectedDate ] || [];

        if ( ! windows.length ) {
            $slotsContainer.html( '<div class="lvb-slots-grid"><p class="lvb-no-slots">Keine freien Slots an diesem Tag. Bitte wähle einen anderen Tag.</p></div>' );
            return;
        }

        var bookedWindows = state.bookedByDate[ state.selectedDate ] || [];
        var bufferMin     = parseInt( $serviceSelect.find( 'option:selected' ).data( 'buffer' ), 10 ) || 0;
        var subSlots      = generateSubSlots( windows, state.serviceDuration, bufferMin, bookedWindows );

        // Filter out past slots when the selected date is today
        if ( state.selectedDate === todayStr() ) {
            var now = new Date();
            subSlots = subSlots.filter( function ( slot ) {
                return new Date( slot.start.replace( ' ', 'T' ) ) > now;
            } );
        }

        if ( ! subSlots.length ) {
            $slotsContainer.html( '<div class="lvb-slots-grid"><p class="lvb-no-slots">Keine passenden Zeitfenster für diese Sportart verfügbar.</p></div>' );
            return;
        }

        var html = '<div class="lvb-slots-grid">';
        $.each( subSlots, function ( i, slot ) {
            var isSelected = state.selectedSlot
                && state.selectedSlot.start === slot.start
                && state.selectedSlot.id    === slot.id;
            html += '<button type="button" class="lvb-slot-btn' + ( isSelected ? ' selected' : '' ) + '" '
                  + 'data-slot=\'' + JSON.stringify( slot ).replace( /'/g, '&#39;' ) + '\'>'
                  + escHtml( slot.start_time ) + ' – ' + escHtml( slot.end_time )
                  + '</button>';
        } );
        html += '</div>';
        if ( bufferMin > 0 ) {
            html += '<p class="lvb-buffer-note">Zwischen zwei Buchungen wird automatisch eine Bufferzeit von ' + bufferMin + ' Min. eingerechnet.</p>';
        }
        $slotsContainer.html( html );
    }

    /* ===================================================================
       Slot click → go to step 3
    =================================================================== */
    function onSlotClick() {
        // Mark selected
        $slotsContainer.find( '.lvb-slot-btn' ).removeClass( 'selected' );
        $( this ).addClass( 'selected' );

        state.selectedSlot = $( this ).data( 'slot' );
        if ( typeof state.selectedSlot === 'string' ) {
            state.selectedSlot = JSON.parse( state.selectedSlot );
        }

        // Populate hidden inputs
        $app.find( '#lvb-slot-event-id' ).val( state.selectedSlot.id );
        $app.find( '#lvb-start-datetime' ).val( state.selectedSlot.start );
        $app.find( '#lvb-slot-win-end' ).val( state.selectedSlot.win_end );
        $app.find( '#lvb-service-id-input' ).val( state.serviceId );
        $app.find( '#lvb-staff-id-input' ).val( state.staffId );

        // Booking summary
        var d = new Date( state.selectedDate + 'T00:00:00' );
        var dateLabel = d.toLocaleDateString( 'de-CH', { weekday:'long', year:'numeric', month:'long', day:'numeric' } );
        var serviceName = escHtml( $serviceSelect.find( 'option:selected' ).text() );

        $bookingSummary.html(
            escHtml( dateLabel )
            + ' &nbsp;&bull;&nbsp; <strong>' + escHtml( state.selectedSlot.start_time ) + ' – ' + escHtml( state.selectedSlot.end_time ) + '</strong>'
            + ' &nbsp;&bull;&nbsp; ' + serviceName
        );

        goToStep( 3 );
    }

    /* ===================================================================
       Back button
    =================================================================== */
    function onBack() {
        var target = parseInt( $( this ).data( 'target' ), 10 );
        goToStep( target );
    }

    /* ===================================================================
       Form submit
    =================================================================== */
    function onFormSubmit( e ) {
        e.preventDefault();
        $formError.hide();

        // Basic client-side validation
        var firstName = $.trim( $app.find( '#lvb-first-name' ).val() );
        var lastName  = $.trim( $app.find( '#lvb-last-name'  ).val() );
        var email     = $.trim( $app.find( '#lvb-email'      ).val() );

        if ( ! firstName || ! lastName ) {
            showError( 'Bitte gib deinen Vor- und Nachnamen ein.' );
            return;
        }
        if ( ! email || ! /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test( email ) ) {
            showError( 'Bitte gib eine gültige E-Mail-Adresse ein.' );
            return;
        }

        var phone = $.trim( $app.find( '#lvb-phone' ).val() );
        if ( ! phone ) {
            showError( 'Bitte gib eine Telefonnummer ein.' );
            return;
        }

        if ( ! $app.find( '#lvb-service-id-input' ).val() ) {
            showError( 'Bitte wähle zuerst eine Sportart aus.' );
            return;
        }

        if ( ! $app.find( '#lvb-disclaimer-check' ).is( ':checked' ) ) {
            showError( 'Bitte akzeptiere den Disclaimer, um fortzufahren.' );
            return;
        }

        $submitBtn.addClass( 'loading' ).prop( 'disabled', true ).text( '' );

        var data = {
            action         : 'lvb_submit_booking',
            nonce          : lvbData.nonce,
            lvb_nonce      : $app.find( '#lvb_nonce' ).val(),
            first_name     : firstName,
            last_name      : lastName,
            email          : email,
            phone          : $app.find( '#lvb-phone' ).val(),
            notes          : $app.find( '#lvb-notes' ).val(),
            start_datetime : $app.find( '#lvb-start-datetime' ).val(),
            service_id     : $app.find( '#lvb-service-id-input' ).val(),
            staff_id       : $app.find( '#lvb-staff-id-input' ).val(),
            slot_event_id  : $app.find( '#lvb-slot-event-id' ).val(),
            slot_win_end   : $app.find( '#lvb-slot-win-end' ).val(),
        };

        $.post( lvbData.ajaxUrl, data )
            .done( function ( res ) {
                if ( res.success ) {
                    showConfirmation( res.data );
                } else {
                    showError( ( res.data && res.data.message ) ? res.data.message : 'Ein Fehler ist aufgetreten. Bitte versuche es erneut.' );
                    resetSubmitBtn();
                }
            } )
            .fail( function () {
                showError( 'Netzwerkfehler. Bitte überprüfe deine Verbindung und versuche es erneut.' );
                resetSubmitBtn();
            } );
    }

    function showError( msg ) {
        $formError.text( msg ).show();
        $formError[ 0 ].scrollIntoView( { behavior: 'smooth', block: 'nearest' } );
    }

    function resetSubmitBtn() {
        $submitBtn.removeClass( 'loading' ).prop( 'disabled', false ).text( 'Jetzt buchen' );
    }

    /* ===================================================================
       Confirmation screen
    =================================================================== */
    function showConfirmation( data ) {
        var html = '<dl>'
            + '<dt>Sportart</dt><dd>'    + escHtml( data.service_name ) + '</dd>'
            + '<dt>Datum</dt><dd>'       + escHtml( data.date )         + '</dd>'
            + '<dt>Uhrzeit</dt><dd>'     + escHtml( data.time )         + '</dd>'
            + '<dt>Buchungs-Nr.</dt><dd>' + data.booking_id             + '</dd>'
            + '</dl>';
        $confirmationDetails.html( html );
        goToStep( 4 );
    }

    /* ===================================================================
       "Book Another" button
    =================================================================== */
    function onBookAnother() {
        // Reset state
        state.selectedDate    = null;
        state.selectedSlot    = null;
        state.serviceId       = 0;
        state.serviceDuration = 0;
        state.staffId         = 0;
        state.bookedByDate    = {};
        state.slotsLoaded     = false;

        $serviceSelect.val( '' );
        $bookingForm[ 0 ].reset();
        $formError.hide();
        resetSubmitBtn();

        goToStep( 1 );

        // Re-fetch slots so the just-made booking is excluded
        fetchSlotsForMonth();
    }

    /* ===================================================================
       Step navigation
    =================================================================== */
    function goToStep( step ) {
        $panels.removeClass( 'active' );
        $app.find( '#lvb-step-' + step ).addClass( 'active' );

        $steps.each( function () {
            var s = parseInt( $( this ).data( 'step' ), 10 );
            $( this ).removeClass( 'active completed' );
            if ( s === step ) {
                $( this ).addClass( 'active' );
            } else if ( s < step ) {
                $( this ).addClass( 'completed' );
            }
        } );

        // Scroll to top of widget
        $( 'html, body' ).animate( { scrollTop: $app.offset().top - 60 }, 300 );
    }

    /* ===================================================================
       Utility
    =================================================================== */
    function padDate( y, m, d ) {
        return y + '-' + String( m ).padStart( 2, '0' ) + '-' + String( d ).padStart( 2, '0' );
    }

    function padTwo( n ) {
        return String( n ).padStart( 2, '0' );
    }

    function fmtDateTime( date ) {
        return date.getFullYear() + '-'
            + padTwo( date.getMonth() + 1 ) + '-'
            + padTwo( date.getDate() ) + ' '
            + padTwo( date.getHours() ) + ':'
            + padTwo( date.getMinutes() ) + ':00';
    }

    function todayStr() {
        var t = new Date();
        return padDate( t.getFullYear(), t.getMonth() + 1, t.getDate() );
    }

    function escHtml( str ) {
        return String( str )
            .replace( /&/g, '&amp;' )
            .replace( /</g, '&lt;' )
            .replace( />/g, '&gt;' )
            .replace( /"/g, '&quot;' )
            .replace( /'/g, '&#039;' );
    }

    /* ===================================================================
       Boot on DOM ready
    =================================================================== */
    $( document ).ready( init );

} )( jQuery );
