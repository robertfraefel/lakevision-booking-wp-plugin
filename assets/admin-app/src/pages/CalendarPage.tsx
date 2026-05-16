import { useCallback, useRef, useState } from 'react';
import FullCalendar from '@fullcalendar/react';
import dayGridPlugin from '@fullcalendar/daygrid';
import timeGridPlugin from '@fullcalendar/timegrid';
import listPlugin from '@fullcalendar/list';
import interactionPlugin from '@fullcalendar/interaction';
import type {
  DatesSetArg,
  EventChangeArg,
  EventClickArg,
  EventDropArg,
  EventInput,
} from '@fullcalendar/core';
import type { EventResizeDoneArg } from '@fullcalendar/interaction';
import { toast } from 'sonner';
import { api, ApiError } from '../api/client';
import { Page } from '../components/Page';
import { BookingDrawer } from './BookingDrawer';

export function CalendarPage() {
  const calendarRef = useRef<FullCalendar | null>(null);
  const [editingId, setEditingId] = useState<number | 'new' | null>(null);
  const [newDefaults, setNewDefaults] = useState<{ start?: string; end?: string }>({});

  // FullCalendar's event source — re-fetched whenever the visible date range
  // changes or after a successful mutation.
  const eventSource = useCallback(
    async (
      info: { startStr: string; endStr: string },
      success: (events: EventInput[]) => void,
      failure: (err: Error) => void
    ) => {
      try {
        const events = await api.calendarEvents({ from: info.startStr, to: info.endStr });
        success(events as EventInput[]);
      } catch (err) {
        failure(err as Error);
      }
    },
    []
  );

  const refresh = () => calendarRef.current?.getApi().refetchEvents();

  // Drag a confirmed booking → PATCH new start/end.
  const onEventChange = async (
    arg: EventDropArg | EventResizeDoneArg | EventChangeArg
  ) => {
    const id = Number(arg.event.id);
    if (!id || arg.event.extendedProps?.is_buffer) {
      arg.revert();
      return;
    }
    const start = arg.event.startStr;
    const end = arg.event.endStr;
    try {
      const r = await api.calendarPatch(id, { start, end });
      toast.success('Termin verschoben');
      if (r.gcal_warning) toast.warning('Google Calendar: ' + r.gcal_warning);
    } catch (err) {
      arg.revert();
      const msg = err instanceof ApiError ? err.message : 'Verschieben fehlgeschlagen';
      toast.error(msg);
    }
  };

  // Click on a booking → open edit drawer.
  const onEventClick = (arg: EventClickArg) => {
    if (arg.event.extendedProps?.is_buffer) return;
    const id = Number(arg.event.id);
    if (id) setEditingId(id);
  };

  // Click on an empty slot → open "new booking" drawer pre-filled with the slot.
  const onDateSelect = (info: { startStr: string; endStr: string }) => {
    setNewDefaults({ start: info.startStr, end: info.endStr });
    setEditingId('new');
  };

  const onDatesSet = (_: DatesSetArg) => {
    // No-op for now — could persist the current view to localStorage later.
  };

  return (
    <Page
      title="Kalender"
      actions={
        <button
          type="button"
          onClick={() => {
            setNewDefaults({});
            setEditingId('new');
          }}
          className="px-3 py-1.5 text-sm rounded bg-brand-600 text-white hover:bg-brand-700"
        >
          + Neuer Termin
        </button>
      }
    >
      <div className="lvb-calendar">
        <FullCalendar
          ref={(el) => {
            calendarRef.current = el;
          }}
          plugins={[dayGridPlugin, timeGridPlugin, listPlugin, interactionPlugin]}
          initialView="timeGridWeek"
          locale="de"
          firstDay={1}
          slotMinTime="07:00:00"
          slotMaxTime="22:00:00"
          allDaySlot={false}
          height="auto"
          nowIndicator
          editable
          eventResizableFromStart
          selectable
          selectMirror
          select={onDateSelect}
          events={eventSource}
          eventClick={onEventClick}
          eventDrop={onEventChange}
          eventResize={onEventChange}
          datesSet={onDatesSet}
          headerToolbar={{
            left: 'prev,next today',
            center: 'title',
            right: 'timeGridWeek,timeGridDay,listWeek',
          }}
          buttonText={{
            today: 'Heute',
            week: 'Woche',
            day: 'Tag',
            list: 'Liste',
          }}
        />
      </div>

      {editingId !== null && (
        <BookingDrawer
          bookingId={editingId === 'new' ? null : editingId}
          defaults={editingId === 'new' ? newDefaults : undefined}
          onClose={() => setEditingId(null)}
          onSaved={() => {
            setEditingId(null);
            refresh();
          }}
        />
      )}
    </Page>
  );
}
