import { useEffect, useState } from 'react';
import { toast } from 'sonner';
import { api, ApiError } from '../api/client';
import type { Service, Staff } from '../api/types';
import { Page } from '../components/Page';
import { Drawer } from '../components/Drawer';

type DayKey = 'mon' | 'tue' | 'wed' | 'thu' | 'fri' | 'sat' | 'sun';

interface WorkWindow { s: string; e: string }
interface TimeOff   { from: string; to: string; reason?: string }

type WorkingHours = Record<DayKey, WorkWindow[]>;

const DAY_KEYS: DayKey[] = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];
const DAY_LABEL: Record<DayKey, string> = {
  mon: 'Mo', tue: 'Di', wed: 'Mi', thu: 'Do', fri: 'Fr', sat: 'Sa', sun: 'So',
};

const emptyHours = (): WorkingHours =>
  DAY_KEYS.reduce((acc, k) => ({ ...acc, [k]: [] }), {} as WorkingHours);

export function StaffPage() {
  const [items, setItems] = useState<Staff[]>([]);
  const [loading, setLoading] = useState(true);
  const [editingId, setEditingId] = useState<number | 'new' | null>(null);

  const load = () => {
    setLoading(true);
    api
      .staff()
      .then((r) => setItems(r.items))
      .catch((err) => {
        const msg = err instanceof ApiError ? err.message : 'Laden fehlgeschlagen';
        toast.error(msg);
      })
      .finally(() => setLoading(false));
  };

  useEffect(load, []);

  return (
    <Page
      title="Mitarbeiter"
      actions={
        <button
          type="button"
          onClick={() => setEditingId('new')}
          className="px-3 py-1.5 text-sm rounded bg-brand-600 text-white hover:bg-brand-700"
        >
          + Neuer Mitarbeiter
        </button>
      }
    >
      {loading ? (
        <div className="py-6 text-center text-gray-400 text-sm">Lade…</div>
      ) : items.length === 0 ? (
        <div className="py-6 text-center text-gray-400 text-sm">Noch keine Mitarbeiter angelegt.</div>
      ) : (
        <ul className="divide-y divide-gray-100 border border-gray-200 rounded">
          {items.map((s) => (
            <li
              key={s.id}
              className="flex items-center gap-3 px-3 py-2 bg-white hover:bg-gray-50 cursor-pointer"
              onClick={() => setEditingId(s.id)}
            >
              <div className="flex-1 min-w-0">
                <div className="font-medium text-gray-900 truncate">{s.name}</div>
                <div className="text-xs text-gray-500 truncate">
                  {s.email}{s.phone && ` · ${s.phone}`}
                  {s.status === 'inactive' && ' · inaktiv'}
                </div>
              </div>
              <span className="text-xs text-brand-600">Bearbeiten →</span>
            </li>
          ))}
        </ul>
      )}

      {editingId !== null && (
        <StaffDrawer
          staffId={editingId === 'new' ? null : editingId}
          onClose={() => setEditingId(null)}
          onSaved={() => {
            setEditingId(null);
            load();
          }}
        />
      )}
    </Page>
  );
}

interface StaffDrawerProps {
  staffId: number | null;
  onClose: () => void;
  onSaved: () => void;
}

interface StaffForm {
  name: string;
  email: string;
  phone: string;
  calendar_id: string;
  color_id: string;
  status: 'active' | 'inactive';
  working_hours: WorkingHours;
  time_off: TimeOff[];
  service_ids: number[];
}

const emptyForm: StaffForm = {
  name: '',
  email: '',
  phone: '',
  calendar_id: '',
  color_id: '',
  status: 'active',
  working_hours: emptyHours(),
  time_off: [],
  service_ids: [],
};

function StaffDrawer({ staffId, onClose, onSaved }: StaffDrawerProps) {
  const isNew = staffId === null;
  const [form, setForm] = useState<StaffForm>(emptyForm);
  const [services, setServices] = useState<Service[]>([]);
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);

  useEffect(() => {
    let alive = true;
    setLoading(true);
    Promise.all([
      api.services(),
      staffId != null ? api.getStaff(staffId) : Promise.resolve(null),
    ])
      .then(([svc, staff]) => {
        if (!alive) return;
        setServices(svc.items);

        if (staff) {
          let wh: WorkingHours = emptyHours();
          let to: TimeOff[]   = [];
          try {
            if (staff.working_hours) {
              const parsed = JSON.parse(staff.working_hours);
              if (parsed && typeof parsed === 'object') {
                wh = { ...emptyHours(), ...parsed };
                // Ensure every day's value is an array
                for (const k of DAY_KEYS) if (!Array.isArray(wh[k])) wh[k] = [];
              }
            }
          } catch { /* ignore */ }
          try {
            if (staff.time_off) {
              const parsed = JSON.parse(staff.time_off);
              if (Array.isArray(parsed)) to = parsed;
            }
          } catch { /* ignore */ }

          setForm({
            name: staff.name ?? '',
            email: staff.email ?? '',
            phone: staff.phone ?? '',
            calendar_id: staff.calendar_id ?? '',
            color_id: staff.color_id != null ? String(staff.color_id) : '',
            status: staff.status,
            working_hours: wh,
            time_off: to,
            service_ids: staff.service_ids ?? [],
          });
        } else {
          setForm(emptyForm);
        }
      })
      .catch((err) => {
        const msg = err instanceof ApiError ? err.message : 'Laden fehlgeschlagen';
        toast.error(msg);
      })
      .finally(() => {
        if (alive) setLoading(false);
      });
    return () => { alive = false; };
  }, [staffId]);

  const submit = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!form.name) {
      toast.error('Name ist Pflicht.');
      return;
    }
    setSaving(true);
    try {
      const payload = {
        ...form,
        color_id: form.color_id === '' ? null : Number(form.color_id),
      };
      if (isNew) {
        await api.createStaff(payload);
        toast.success('Mitarbeiter angelegt');
      } else {
        await api.updateStaff(staffId!, payload);
        toast.success('Mitarbeiter gespeichert');
      }
      onSaved();
    } catch (err) {
      const msg = err instanceof ApiError ? err.message : 'Speichern fehlgeschlagen';
      toast.error(msg);
    } finally {
      setSaving(false);
    }
  };

  const onDelete = async () => {
    if (!staffId) return;
    if (!confirm('Mitarbeiter wirklich löschen? Bestehende Buchungen behalten den Eintrag in der Historie.'))
      return;
    setSaving(true);
    try {
      await api.deleteStaff(staffId);
      toast.success('Mitarbeiter gelöscht');
      onSaved();
    } catch (err) {
      const msg = err instanceof ApiError ? err.message : 'Löschen fehlgeschlagen';
      toast.error(msg);
    } finally {
      setSaving(false);
    }
  };

  const setDayWindow = (day: DayKey, i: number, key: keyof WorkWindow, value: string) => {
    setForm((f) => {
      const list = [...(f.working_hours[day] ?? [])];
      list[i] = { ...list[i], [key]: value };
      return { ...f, working_hours: { ...f.working_hours, [day]: list } };
    });
  };
  const addDayWindow = (day: DayKey) =>
    setForm((f) => ({
      ...f,
      working_hours: {
        ...f.working_hours,
        [day]: [...(f.working_hours[day] ?? []), { s: '', e: '' }],
      },
    }));
  const removeDayWindow = (day: DayKey, i: number) =>
    setForm((f) => ({
      ...f,
      working_hours: {
        ...f.working_hours,
        [day]: f.working_hours[day].filter((_, idx) => idx !== i),
      },
    }));

  const addTimeOff = () =>
    setForm((f) => ({ ...f, time_off: [...f.time_off, { from: '', to: '', reason: '' }] }));
  const setTimeOff = (i: number, key: keyof TimeOff, value: string) =>
    setForm((f) => {
      const list = [...f.time_off];
      list[i] = { ...list[i], [key]: value };
      return { ...f, time_off: list };
    });
  const removeTimeOff = (i: number) =>
    setForm((f) => ({ ...f, time_off: f.time_off.filter((_, idx) => idx !== i) }));

  const toggleService = (id: number) =>
    setForm((f) => ({
      ...f,
      service_ids: f.service_ids.includes(id)
        ? f.service_ids.filter((x) => x !== id)
        : [...f.service_ids, id],
    }));

  return (
    <Drawer
      open
      title={isNew ? 'Neuer Mitarbeiter' : `Mitarbeiter #${staffId}`}
      onClose={onClose}
      width="max-w-2xl"
    >
      {loading ? (
        <div className="text-gray-500 text-sm">Lade…</div>
      ) : (
        <form onSubmit={submit} className="space-y-4">
          {/* Stammdaten */}
          <fieldset className="border border-gray-200 rounded p-3">
            <legend className="px-1 text-xs font-medium text-gray-500 uppercase tracking-wide">
              Stammdaten
            </legend>
            <div className="grid grid-cols-2 gap-2">
              <input
                type="text"
                required
                placeholder="Name *"
                value={form.name}
                onChange={(e) => setForm((f) => ({ ...f, name: e.target.value }))}
                className="px-2 py-1.5 border border-gray-300 rounded text-sm"
              />
              <select
                value={form.status}
                onChange={(e) => setForm((f) => ({ ...f, status: e.target.value as 'active' | 'inactive' }))}
                className="px-2 py-1.5 border border-gray-300 rounded text-sm"
              >
                <option value="active">Aktiv</option>
                <option value="inactive">Inaktiv</option>
              </select>
            </div>
            <div className="grid grid-cols-2 gap-2 mt-2">
              <input
                type="email"
                placeholder="E-Mail"
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
            <div className="grid grid-cols-2 gap-2 mt-2">
              <input
                type="text"
                placeholder="Google Calendar ID (optional)"
                value={form.calendar_id}
                onChange={(e) => setForm((f) => ({ ...f, calendar_id: e.target.value }))}
                className="px-2 py-1.5 border border-gray-300 rounded text-sm"
              />
              <input
                type="number"
                min={1}
                max={11}
                placeholder="GCal Color-ID (1–11)"
                value={form.color_id}
                onChange={(e) => setForm((f) => ({ ...f, color_id: e.target.value }))}
                className="px-2 py-1.5 border border-gray-300 rounded text-sm"
              />
            </div>
          </fieldset>

          {/* Services */}
          <fieldset className="border border-gray-200 rounded p-3">
            <legend className="px-1 text-xs font-medium text-gray-500 uppercase tracking-wide">
              Services
            </legend>
            {services.length === 0 ? (
              <div className="text-xs text-gray-500 italic">Noch keine Services angelegt.</div>
            ) : (
              <div className="grid grid-cols-2 gap-1">
                {services.map((s) => (
                  <label key={s.id} className="flex items-center gap-2 text-sm py-1">
                    <input
                      type="checkbox"
                      checked={form.service_ids.includes(s.id)}
                      onChange={() => toggleService(s.id)}
                      className="h-4 w-4"
                    />
                    <span>{s.name}</span>
                  </label>
                ))}
              </div>
            )}
          </fieldset>

          {/* Arbeitszeiten */}
          <fieldset className="border border-gray-200 rounded p-3">
            <legend className="px-1 text-xs font-medium text-gray-500 uppercase tracking-wide">
              Arbeitszeiten
            </legend>
            <div className="space-y-2">
              {DAY_KEYS.map((day) => (
                <div key={day} className="flex items-start gap-2">
                  <div className="w-10 pt-1.5 text-sm font-medium text-gray-700">
                    {DAY_LABEL[day]}
                  </div>
                  <div className="flex-1 space-y-1">
                    {form.working_hours[day]?.length === 0 ? (
                      <div className="text-xs text-gray-400 italic py-1">frei</div>
                    ) : (
                      form.working_hours[day]?.map((win, i) => (
                        <div key={i} className="flex items-center gap-1">
                          <input
                            type="time"
                            value={win.s}
                            step={900}
                            onChange={(e) => setDayWindow(day, i, 's', e.target.value)}
                            className="px-2 py-1 border border-gray-300 rounded text-sm"
                          />
                          <span className="text-gray-400">–</span>
                          <input
                            type="time"
                            value={win.e}
                            step={900}
                            onChange={(e) => setDayWindow(day, i, 'e', e.target.value)}
                            className="px-2 py-1 border border-gray-300 rounded text-sm"
                          />
                          <button
                            type="button"
                            onClick={() => removeDayWindow(day, i)}
                            className="text-gray-400 hover:text-red-600 text-sm px-1"
                            aria-label="Entfernen"
                          >
                            ×
                          </button>
                        </div>
                      ))
                    )}
                    <button
                      type="button"
                      onClick={() => addDayWindow(day)}
                      className="text-xs text-brand-600 hover:text-brand-700"
                    >
                      + Zeitfenster
                    </button>
                  </div>
                </div>
              ))}
            </div>
          </fieldset>

          {/* Abwesenheiten */}
          <fieldset className="border border-gray-200 rounded p-3">
            <legend className="px-1 text-xs font-medium text-gray-500 uppercase tracking-wide">
              Abwesenheiten / Ferien
            </legend>
            <div className="space-y-2">
              {form.time_off.length === 0 && (
                <div className="text-xs text-gray-400 italic">Keine Abwesenheiten eingetragen.</div>
              )}
              {form.time_off.map((row, i) => (
                <div key={i} className="flex flex-wrap items-center gap-1">
                  <input
                    type="date"
                    value={row.from}
                    onChange={(e) => setTimeOff(i, 'from', e.target.value)}
                    className="px-2 py-1 border border-gray-300 rounded text-sm"
                  />
                  <span className="text-gray-400">–</span>
                  <input
                    type="date"
                    value={row.to}
                    onChange={(e) => setTimeOff(i, 'to', e.target.value)}
                    className="px-2 py-1 border border-gray-300 rounded text-sm"
                  />
                  <input
                    type="text"
                    placeholder="Grund (optional)"
                    value={row.reason ?? ''}
                    onChange={(e) => setTimeOff(i, 'reason', e.target.value)}
                    className="flex-1 min-w-[100px] px-2 py-1 border border-gray-300 rounded text-sm"
                  />
                  <button
                    type="button"
                    onClick={() => removeTimeOff(i)}
                    className="text-gray-400 hover:text-red-600 text-sm px-1"
                    aria-label="Entfernen"
                  >
                    ×
                  </button>
                </div>
              ))}
              <button
                type="button"
                onClick={addTimeOff}
                className="text-xs text-brand-600 hover:text-brand-700"
              >
                + Eintrag hinzufügen
              </button>
            </div>
          </fieldset>

          <div className="flex items-center justify-between pt-3 border-t border-gray-200">
            <div>
              {!isNew && (
                <button
                  type="button"
                  onClick={onDelete}
                  disabled={saving}
                  className="text-sm text-red-600 hover:text-red-800 disabled:opacity-50"
                >
                  Löschen
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
