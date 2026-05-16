import { useEffect, useMemo, useState } from 'react';
import { toast } from 'sonner';
import { api, ApiError } from '../api/client';
import type { CalendarMeta, Customer } from '../api/types';
import { Drawer } from '../components/Drawer';
import { fromDateTimeLocal, toDateTimeLocal } from '../lib/format';
import { CustomerPicker } from './CustomerPicker';

interface BookingDrawerProps {
  bookingId: number | null;
  defaults?: { start?: string; end?: string };
  onClose: () => void;
  onSaved: () => void;
}

type FormState = {
  service_id: number | '';
  staff_id: number | '';
  start_datetime: string;
  end_datetime: string;
  status: 'pending' | 'confirmed' | 'cancelled';
  price: string;
  notes: string;
  notify: boolean;

  // Customer fields
  customer_id: number | 0;
  first_name: string;
  last_name: string;
  email: string;
  phone: string;
};

const empty: FormState = {
  service_id: '',
  staff_id: '',
  start_datetime: '',
  end_datetime: '',
  status: 'confirmed',
  price: '',
  notes: '',
  notify: true,
  customer_id: 0,
  first_name: '',
  last_name: '',
  email: '',
  phone: '',
};

export function BookingDrawer({ bookingId, defaults, onClose, onSaved }: BookingDrawerProps) {
  const [meta, setMeta] = useState<CalendarMeta | null>(null);
  const [form, setForm] = useState<FormState>(empty);
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const isNew = bookingId === null;

  // Load meta + (if editing) the booking row
  useEffect(() => {
    let alive = true;
    setLoading(true);
    Promise.all([
      api.meta(),
      bookingId != null ? api.getBooking(bookingId) : Promise.resolve(null),
      bookingId != null ? api.customers() : Promise.resolve(null),
    ])
      .then(([m, booking, customers]) => {
        if (!alive) return;
        setMeta(m);

        if (booking) {
          const customer = customers?.items.find((c) => c.id === booking.customer_id);
          setForm({
            service_id: booking.service_id,
            staff_id: booking.staff_id ?? '',
            start_datetime: toDateTimeLocal(booking.start_datetime.replace(' ', 'T')),
            end_datetime: toDateTimeLocal(booking.end_datetime.replace(' ', 'T')),
            status: booking.status,
            price: String(booking.price ?? ''),
            notes: booking.notes ?? '',
            notify: false,
            customer_id: booking.customer_id,
            first_name: customer?.first_name ?? booking.customer_first_name ?? '',
            last_name: customer?.last_name ?? booking.customer_last_name ?? '',
            email: customer?.email ?? booking.customer_email ?? '',
            phone: customer?.phone ?? '',
          });
        } else {
          setForm({
            ...empty,
            start_datetime: defaults?.start ? toDateTimeLocal(defaults.start) : '',
            end_datetime: defaults?.end ? toDateTimeLocal(defaults.end) : '',
          });
        }
      })
      .catch((err) => {
        const msg = err instanceof ApiError ? err.message : 'Laden fehlgeschlagen';
        toast.error(msg);
      })
      .finally(() => {
        if (alive) setLoading(false);
      });
    return () => {
      alive = false;
    };
  }, [bookingId, defaults?.start, defaults?.end]);

  // Cascading staff filter — only show staff assigned to the chosen service.
  const eligibleStaff = useMemo(() => {
    if (!meta || form.service_id === '') return meta?.staff ?? [];
    const ids = meta.service_staff[form.service_id as number] || [];
    return meta.staff.filter((s) => ids.includes(s.id));
  }, [meta, form.service_id]);

  // When the service changes, auto-fill end-time based on the duration if not set.
  const onServiceChange = (serviceId: number) => {
    setForm((f) => {
      const svc = meta?.services.find((s) => s.id === serviceId);
      let end = f.end_datetime;
      if (svc && f.start_datetime) {
        const start = new Date(f.start_datetime);
        const computed = new Date(start.getTime() + svc.duration * 60_000);
        end = toDateTimeLocal(computed);
      }
      return {
        ...f,
        service_id: serviceId,
        end_datetime: end,
        // If currently-selected staff isn't assigned to the new service, clear.
        staff_id:
          meta && f.staff_id !== '' && !meta.service_staff[serviceId]?.includes(f.staff_id as number)
            ? ''
            : f.staff_id,
        price:
          (f.price === '' || f.price === '0' || f.price === '0.00') && svc
            ? String(svc.price)
            : f.price,
      };
    });
  };

  const onCustomerPicked = (c: Customer) => {
    setForm((f) => ({
      ...f,
      customer_id: c.id,
      first_name: c.first_name,
      last_name: c.last_name,
      email: c.email,
      phone: c.phone,
    }));
  };

  const onCustomerCleared = () => {
    setForm((f) => ({
      ...f,
      customer_id: 0,
      first_name: '',
      last_name: '',
      email: '',
      phone: '',
    }));
  };

  const submit = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!form.service_id || !form.start_datetime || !form.end_datetime || !form.email) {
      toast.error('Bitte alle Pflichtfelder ausfüllen.');
      return;
    }
    setSaving(true);
    try {
      const payload = {
        service_id: form.service_id,
        staff_id: form.staff_id || null,
        start_datetime: fromDateTimeLocal(form.start_datetime),
        end_datetime: fromDateTimeLocal(form.end_datetime),
        status: form.status,
        price: form.price,
        notes: form.notes,
        first_name: form.first_name,
        last_name: form.last_name,
        email: form.email,
        phone: form.phone,
        lvb_send_notification: form.notify ? 1 : 0,
      };
      if (isNew) {
        await api.createBooking(payload);
        toast.success('Buchung angelegt');
      } else {
        await api.updateBooking(bookingId!, payload);
        toast.success('Buchung gespeichert');
      }
      onSaved();
    } catch (err) {
      const msg = err instanceof ApiError ? err.message : 'Speichern fehlgeschlagen';
      toast.error(msg);
    } finally {
      setSaving(false);
    }
  };

  const onCancelBooking = async () => {
    if (!bookingId) return;
    if (!confirm('Diese Buchung wirklich absagen? Der Kunde erhält eine Stornierungs-Mail.'))
      return;
    setSaving(true);
    try {
      await api.cancelBooking(bookingId);
      toast.success('Buchung storniert');
      onSaved();
    } catch (err) {
      const msg = err instanceof ApiError ? err.message : 'Stornierung fehlgeschlagen';
      toast.error(msg);
    } finally {
      setSaving(false);
    }
  };

  return (
    <Drawer
      open
      title={isNew ? 'Neuer Termin' : `Termin #${bookingId}`}
      onClose={onClose}
      width="max-w-xl"
    >
      {loading ? (
        <div className="text-gray-500 text-sm">Lade…</div>
      ) : (
        <form onSubmit={submit} className="space-y-4">
          {/* Customer */}
          <fieldset className="border border-gray-200 rounded p-3">
            <legend className="px-1 text-xs font-medium text-gray-500 uppercase tracking-wide">
              Kunde
            </legend>
            <CustomerPicker
              currentEmail={form.email}
              onPick={onCustomerPicked}
              onClear={onCustomerCleared}
            />
            <div className="grid grid-cols-2 gap-2 mt-3">
              <input
                type="text"
                placeholder="Vorname"
                value={form.first_name}
                onChange={(e) => setForm((f) => ({ ...f, first_name: e.target.value }))}
                className="px-2 py-1.5 border border-gray-300 rounded text-sm"
              />
              <input
                type="text"
                placeholder="Nachname"
                value={form.last_name}
                onChange={(e) => setForm((f) => ({ ...f, last_name: e.target.value }))}
                className="px-2 py-1.5 border border-gray-300 rounded text-sm"
              />
            </div>
            <div className="grid grid-cols-2 gap-2 mt-2">
              <input
                type="email"
                required
                placeholder="E-Mail (Pflicht)"
                value={form.email}
                onChange={(e) => setForm((f) => ({ ...f, email: e.target.value }))}
                className="px-2 py-1.5 border border-gray-300 rounded text-sm"
              />
              <input
                type="tel"
                placeholder="Telefon"
                value={form.phone}
                onChange={(e) => setForm((f) => ({ ...f, phone: e.target.value }))}
                className="px-2 py-1.5 border border-gray-300 rounded text-sm"
              />
            </div>
          </fieldset>

          {/* Service + Staff */}
          <div className="grid grid-cols-2 gap-2">
            <label className="block">
              <span className="text-xs text-gray-600">{meta?.service_label ?? 'Sitzung'} *</span>
              <select
                required
                value={form.service_id}
                onChange={(e) => onServiceChange(Number(e.target.value))}
                className="mt-1 w-full px-2 py-1.5 border border-gray-300 rounded text-sm"
              >
                <option value="">Wählen…</option>
                {meta?.services.map((s) => (
                  <option key={s.id} value={s.id}>
                    {s.name} ({s.duration} min)
                  </option>
                ))}
              </select>
            </label>

            <label className="block">
              <span className="text-xs text-gray-600">{meta?.staff_label ?? 'Mitarbeiter'}</span>
              <select
                value={form.staff_id}
                onChange={(e) =>
                  setForm((f) => ({ ...f, staff_id: e.target.value ? Number(e.target.value) : '' }))
                }
                className="mt-1 w-full px-2 py-1.5 border border-gray-300 rounded text-sm"
              >
                <option value="">Nicht zugewiesen</option>
                {eligibleStaff.map((s) => (
                  <option key={s.id} value={s.id}>
                    {s.name}
                  </option>
                ))}
              </select>
            </label>
          </div>

          {/* Dates */}
          <div className="grid grid-cols-2 gap-2">
            <label className="block">
              <span className="text-xs text-gray-600">Start *</span>
              <input
                type="datetime-local"
                required
                value={form.start_datetime}
                onChange={(e) => setForm((f) => ({ ...f, start_datetime: e.target.value }))}
                className="mt-1 w-full px-2 py-1.5 border border-gray-300 rounded text-sm"
              />
            </label>
            <label className="block">
              <span className="text-xs text-gray-600">Ende *</span>
              <input
                type="datetime-local"
                required
                value={form.end_datetime}
                onChange={(e) => setForm((f) => ({ ...f, end_datetime: e.target.value }))}
                className="mt-1 w-full px-2 py-1.5 border border-gray-300 rounded text-sm"
              />
            </label>
          </div>

          {/* Status + price */}
          <div className="grid grid-cols-2 gap-2">
            <label className="block">
              <span className="text-xs text-gray-600">Status</span>
              <select
                value={form.status}
                onChange={(e) =>
                  setForm((f) => ({ ...f, status: e.target.value as FormState['status'] }))
                }
                className="mt-1 w-full px-2 py-1.5 border border-gray-300 rounded text-sm"
              >
                <option value="confirmed">Bestätigt</option>
                <option value="pending">Offen</option>
                <option value="cancelled">Abgesagt</option>
              </select>
            </label>
            <label className="block">
              <span className="text-xs text-gray-600">Preis</span>
              <input
                type="text"
                value={form.price}
                onChange={(e) => setForm((f) => ({ ...f, price: e.target.value }))}
                className="mt-1 w-full px-2 py-1.5 border border-gray-300 rounded text-sm"
              />
            </label>
          </div>

          {/* Notes */}
          <label className="block">
            <span className="text-xs text-gray-600">Notizen</span>
            <textarea
              rows={3}
              value={form.notes}
              onChange={(e) => setForm((f) => ({ ...f, notes: e.target.value }))}
              className="mt-1 w-full px-2 py-1.5 border border-gray-300 rounded text-sm"
            />
          </label>

          {/* Notify */}
          <label className="flex items-start gap-2 text-sm">
            <input
              type="checkbox"
              checked={form.notify}
              onChange={(e) => setForm((f) => ({ ...f, notify: e.target.checked }))}
              className="mt-0.5"
            />
            <span className="text-gray-700">
              {isNew
                ? 'Kunde per Email bestätigen (mit ICS-Anhang)'
                : 'Kunde per Email über die Änderungen informieren'}
            </span>
          </label>

          <div className="flex items-center justify-between pt-3 border-t border-gray-200">
            <div>
              {!isNew && form.status !== 'cancelled' && (
                <button
                  type="button"
                  onClick={onCancelBooking}
                  disabled={saving}
                  className="text-sm text-red-600 hover:text-red-800 disabled:opacity-50"
                >
                  Absagen
                </button>
              )}
            </div>
            <div className="flex items-center gap-2">
              <button
                type="button"
                onClick={onClose}
                disabled={saving}
                className="px-3 py-1.5 text-sm rounded border border-gray-300 text-gray-700 hover:bg-gray-50 disabled:opacity-50"
              >
                Abbrechen
              </button>
              <button
                type="submit"
                disabled={saving}
                className="px-3 py-1.5 text-sm rounded bg-brand-600 text-white hover:bg-brand-700 disabled:opacity-50"
              >
                {saving ? 'Speichert…' : isNew ? 'Anlegen' : 'Speichern'}
              </button>
            </div>
          </div>
        </form>
      )}
    </Drawer>
  );
}
