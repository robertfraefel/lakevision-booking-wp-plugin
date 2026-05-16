import { useEffect, useState } from 'react';
import {
  DndContext,
  PointerSensor,
  KeyboardSensor,
  useSensor,
  useSensors,
  closestCenter,
  type DragEndEvent,
} from '@dnd-kit/core';
import {
  SortableContext,
  arrayMove,
  useSortable,
  verticalListSortingStrategy,
  sortableKeyboardCoordinates,
} from '@dnd-kit/sortable';
import { CSS } from '@dnd-kit/utilities';
import { toast } from 'sonner';
import { api, ApiError } from '../api/client';
import type { Service } from '../api/types';
import { Page } from '../components/Page';
import { Drawer } from '../components/Drawer';
import { formatPrice } from '../lib/format';

export function ServicesPage() {
  const [items, setItems] = useState<Service[]>([]);
  const [loading, setLoading] = useState(true);
  const [editing, setEditing] = useState<Service | 'new' | null>(null);

  const sensors = useSensors(
    useSensor(PointerSensor, { activationConstraint: { distance: 5 } }),
    useSensor(KeyboardSensor, { coordinateGetter: sortableKeyboardCoordinates })
  );

  const load = () => {
    setLoading(true);
    api
      .services()
      .then((r) => setItems(r.items))
      .catch((err) => {
        const msg = err instanceof ApiError ? err.message : 'Laden fehlgeschlagen';
        toast.error(msg);
      })
      .finally(() => setLoading(false));
  };

  useEffect(load, []);

  const onDragEnd = async (event: DragEndEvent) => {
    const { active, over } = event;
    if (!over || active.id === over.id) return;
    const oldIndex = items.findIndex((s) => s.id === active.id);
    const newIndex = items.findIndex((s) => s.id === over.id);
    if (oldIndex < 0 || newIndex < 0) return;
    const next = arrayMove(items, oldIndex, newIndex);
    setItems(next); // Optimistic
    try {
      await api.reorderServices(next.map((s) => s.id));
      toast.success('Reihenfolge gespeichert');
    } catch (err) {
      const msg = err instanceof ApiError ? err.message : 'Sortieren fehlgeschlagen';
      toast.error(msg);
      load();
    }
  };

  return (
    <Page
      title="Services"
      actions={
        <button
          type="button"
          onClick={() => setEditing('new')}
          className="px-3 py-1.5 text-sm rounded bg-brand-600 text-white hover:bg-brand-700"
        >
          + Neuer Service
        </button>
      }
    >
      <p className="text-xs text-gray-500 mb-3">
        Reihenfolge per Drag &amp; Drop ändern — sie steuert die Sortierung im öffentlichen
        Buchungs-Widget.
      </p>

      {loading ? (
        <div className="py-6 text-center text-gray-400 text-sm">Lade…</div>
      ) : items.length === 0 ? (
        <div className="py-6 text-center text-gray-400 text-sm">Noch keine Services angelegt.</div>
      ) : (
        <DndContext sensors={sensors} collisionDetection={closestCenter} onDragEnd={onDragEnd}>
          <SortableContext items={items.map((s) => s.id)} strategy={verticalListSortingStrategy}>
            <ul className="divide-y divide-gray-100 border border-gray-200 rounded">
              {items.map((s) => (
                <SortableServiceRow key={s.id} service={s} onEdit={() => setEditing(s)} />
              ))}
            </ul>
          </SortableContext>
        </DndContext>
      )}

      {editing !== null && (
        <ServiceDrawer
          service={editing === 'new' ? null : editing}
          onClose={() => setEditing(null)}
          onSaved={() => {
            setEditing(null);
            load();
          }}
        />
      )}
    </Page>
  );
}

function SortableServiceRow({ service, onEdit }: { service: Service; onEdit: () => void }) {
  const { attributes, listeners, setNodeRef, transform, transition, isDragging } = useSortable({
    id: service.id,
  });
  const style: React.CSSProperties = {
    transform: CSS.Transform.toString(transform),
    transition,
    opacity: isDragging ? 0.5 : 1,
  };

  return (
    <li ref={setNodeRef} style={style} className="flex items-center gap-3 px-3 py-2 bg-white">
      <button
        type="button"
        {...attributes}
        {...listeners}
        className="cursor-grab text-gray-400 hover:text-gray-600 text-lg px-1"
        aria-label="Sortieren"
      >
        ⋮⋮
      </button>
      <div className="flex-1 min-w-0">
        <div className="font-medium text-gray-900 truncate">{service.name}</div>
        <div className="text-xs text-gray-500">
          {service.duration} min · {formatPrice(service.price)}
          {service.buffer_time ? ` · +${service.buffer_time} min Puffer` : ''}
          {service.status === 'inactive' && ' · inaktiv'}
        </div>
      </div>
      <button
        type="button"
        onClick={onEdit}
        className="text-xs text-brand-600 hover:text-brand-700"
      >
        Bearbeiten →
      </button>
    </li>
  );
}

interface ServiceDrawerProps {
  service: Service | null;
  onClose: () => void;
  onSaved: () => void;
}

function ServiceDrawer({ service, onClose, onSaved }: ServiceDrawerProps) {
  const isNew = service === null;
  const [form, setForm] = useState<Partial<Service>>(
    service ?? { name: '', duration: 60, price: '', buffer_time: 0, status: 'active' }
  );
  const [saving, setSaving] = useState(false);

  const submit = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!form.name || !form.duration) {
      toast.error('Name und Dauer sind Pflicht.');
      return;
    }
    setSaving(true);
    try {
      if (isNew) {
        await api.createService(form);
        toast.success('Service angelegt');
      } else {
        await api.updateService(service!.id, form);
        toast.success('Service gespeichert');
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
    if (!service) return;
    if (!confirm('Service wirklich löschen? Bestehende Buchungen bleiben erhalten.')) return;
    setSaving(true);
    try {
      await api.deleteService(service.id);
      toast.success('Service gelöscht');
      onSaved();
    } catch (err) {
      const msg = err instanceof ApiError ? err.message : 'Löschen fehlgeschlagen';
      toast.error(msg);
    } finally {
      setSaving(false);
    }
  };

  return (
    <Drawer open title={isNew ? 'Neuer Service' : `Service #${service!.id}`} onClose={onClose}>
      <form onSubmit={submit} className="space-y-3">
        <label className="block">
          <span className="text-xs text-gray-600">Name *</span>
          <input
            type="text"
            required
            value={form.name ?? ''}
            onChange={(e) => setForm((f) => ({ ...f, name: e.target.value }))}
            className="mt-1 w-full px-2 py-1.5 border border-gray-300 rounded text-sm"
          />
        </label>

        <label className="block">
          <span className="text-xs text-gray-600">Beschreibung</span>
          <textarea
            rows={2}
            value={form.description ?? ''}
            onChange={(e) => setForm((f) => ({ ...f, description: e.target.value }))}
            className="mt-1 w-full px-2 py-1.5 border border-gray-300 rounded text-sm"
          />
        </label>

        <div className="grid grid-cols-3 gap-2">
          <label className="block">
            <span className="text-xs text-gray-600">Dauer (min) *</span>
            <input
              type="number"
              required
              min={5}
              step={5}
              value={form.duration ?? 60}
              onChange={(e) => setForm((f) => ({ ...f, duration: Number(e.target.value) }))}
              className="mt-1 w-full px-2 py-1.5 border border-gray-300 rounded text-sm"
            />
          </label>
          <label className="block">
            <span className="text-xs text-gray-600">Preis</span>
            <input
              type="text"
              value={String(form.price ?? '')}
              onChange={(e) => setForm((f) => ({ ...f, price: e.target.value }))}
              className="mt-1 w-full px-2 py-1.5 border border-gray-300 rounded text-sm"
            />
          </label>
          <label className="block">
            <span className="text-xs text-gray-600">Puffer (min)</span>
            <input
              type="number"
              min={0}
              step={5}
              value={form.buffer_time ?? 0}
              onChange={(e) =>
                setForm((f) => ({ ...f, buffer_time: Number(e.target.value) || 0 }))
              }
              className="mt-1 w-full px-2 py-1.5 border border-gray-300 rounded text-sm"
            />
          </label>
        </div>

        <label className="block">
          <span className="text-xs text-gray-600">Status</span>
          <select
            value={form.status ?? 'active'}
            onChange={(e) =>
              setForm((f) => ({ ...f, status: e.target.value as Service['status'] }))
            }
            className="mt-1 w-full px-2 py-1.5 border border-gray-300 rounded text-sm"
          >
            <option value="active">Aktiv</option>
            <option value="inactive">Inaktiv</option>
          </select>
        </label>

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
    </Drawer>
  );
}
