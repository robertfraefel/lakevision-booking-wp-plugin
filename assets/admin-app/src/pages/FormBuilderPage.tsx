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
import { Page } from '../components/Page';
import { Link } from 'react-router-dom';

type FieldType =
  | 'text'
  | 'email'
  | 'tel'
  | 'textarea'
  | 'select'
  | 'checkbox-group'
  | 'single-checkbox'
  | 'radio'
  | 'date'
  | 'number';

interface Field {
  id: string;
  label: string;
  type: FieldType;
  required: boolean;
  enabled: boolean;
  options?: string[];
  checkbox_text?: string;
}

const TYPE_LABEL: Record<FieldType, string> = {
  text:            'Text',
  email:           'E-Mail',
  tel:             'Telefon',
  textarea:        'Textfeld (mehrzeilig)',
  select:          'Auswahlliste',
  'checkbox-group':'Checkbox-Gruppe',
  'single-checkbox':'Einzel-Checkbox',
  radio:           'Radio-Buttons',
  date:            'Datum',
  number:          'Zahl',
};

function newField(): Field {
  return {
    id: 'field_' + Math.random().toString(36).slice(2, 8),
    label: 'Neues Feld',
    type: 'text',
    required: false,
    enabled: true,
  };
}

export function FormBuilderPage() {
  const [fields, setFields] = useState<Field[]>([]);
  const [enabled, setEnabled] = useState(false);
  const [disclaimer, setDisclaimer] = useState('');
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [dirty, setDirty] = useState(false);

  const sensors = useSensors(
    useSensor(PointerSensor, { activationConstraint: { distance: 5 } }),
    useSensor(KeyboardSensor, { coordinateGetter: sortableKeyboardCoordinates })
  );

  const load = () => {
    setLoading(true);
    api
      .getIntakeConfig()
      .then((r) => {
        setFields(
          r.fields.map((f) => ({
            id: String(f.id),
            label: String(f.label ?? ''),
            type: (f.type as FieldType) || 'text',
            required: Boolean(f.required),
            enabled: Boolean(f.enabled),
            options: Array.isArray(f.options) ? f.options.map(String) : undefined,
            checkbox_text:
              typeof f.checkbox_text === 'string' ? f.checkbox_text : undefined,
          }))
        );
        setEnabled(r.enabled === 1);
        setDisclaimer(r.disclaimer ?? '');
        setDirty(false);
      })
      .catch((err) => {
        const msg = err instanceof ApiError ? err.message : 'Laden fehlgeschlagen';
        toast.error(msg);
      })
      .finally(() => setLoading(false));
  };

  useEffect(load, []);

  const save = async () => {
    setSaving(true);
    try {
      const payload = fields.map((f, i) => ({
        ...f,
        sort_order: i,
        options: f.type === 'select' || f.type === 'checkbox-group' ? f.options ?? [] : undefined,
      }));
      const r = await api.putIntakeConfig({
        fields: payload as unknown as Array<Record<string, unknown>>,
        enabled: enabled ? 1 : 0,
        disclaimer,
      });
      setDirty(false);
      toast.success('Formular gespeichert');
      setFields(r.fields as unknown as Field[]);
    } catch (err) {
      const msg = err instanceof ApiError ? err.message : 'Speichern fehlgeschlagen';
      toast.error(msg);
    } finally {
      setSaving(false);
    }
  };

  const updateField = (idx: number, patch: Partial<Field>) => {
    setFields((fs) => fs.map((f, i) => (i === idx ? { ...f, ...patch } : f)));
    setDirty(true);
  };

  const removeField = (idx: number) => {
    setFields((fs) => fs.filter((_, i) => i !== idx));
    setDirty(true);
  };

  const addField = () => {
    setFields((fs) => [...fs, newField()]);
    setDirty(true);
  };

  const onDragEnd = (event: DragEndEvent) => {
    const { active, over } = event;
    if (!over || active.id === over.id) return;
    setFields((fs) => {
      const oldIdx = fs.findIndex((f) => f.id === active.id);
      const newIdx = fs.findIndex((f) => f.id === over.id);
      if (oldIdx < 0 || newIdx < 0) return fs;
      return arrayMove(fs, oldIdx, newIdx);
    });
    setDirty(true);
  };

  return (
    <Page
      title="Anmeldeformular bearbeiten"
      actions={
        <>
          <Link
            to="/intake-forms"
            className="px-3 py-1.5 text-sm rounded border border-gray-300 text-gray-700 hover:bg-gray-50"
          >
            ← Eingegangene Formulare
          </Link>
          <button
            type="button"
            onClick={save}
            disabled={!dirty || saving || loading}
            className="px-3 py-1.5 text-sm rounded bg-brand-600 text-white hover:bg-brand-700 disabled:opacity-50"
          >
            {saving ? 'Speichert…' : 'Speichern'}
          </button>
        </>
      }
    >
      {loading ? (
        <div className="py-6 text-center text-gray-400 text-sm">Lade…</div>
      ) : (
        <div className="space-y-4">
          <div className="p-3 bg-gray-50 border border-gray-200 rounded space-y-2">
            <label className="flex items-center gap-2 text-sm">
              <input
                type="checkbox"
                checked={enabled}
                onChange={(e) => {
                  setEnabled(e.target.checked);
                  setDirty(true);
                }}
                className="h-4 w-4"
              />
              <span className="font-medium text-gray-800">Anmeldeformular aktiviert</span>
            </label>
            <label className="block">
              <span className="text-xs text-gray-600">Disclaimer-Text (optional)</span>
              <textarea
                rows={2}
                value={disclaimer}
                onChange={(e) => {
                  setDisclaimer(e.target.value);
                  setDirty(true);
                }}
                className="mt-1 w-full px-2 py-1.5 border border-gray-300 rounded text-sm"
              />
            </label>
          </div>

          <DndContext sensors={sensors} collisionDetection={closestCenter} onDragEnd={onDragEnd}>
            <SortableContext
              items={fields.map((f) => f.id)}
              strategy={verticalListSortingStrategy}
            >
              <ul className="space-y-2">
                {fields.map((f, i) => (
                  <SortableFieldRow
                    key={f.id}
                    field={f}
                    onChange={(patch) => updateField(i, patch)}
                    onRemove={() => removeField(i)}
                  />
                ))}
              </ul>
            </SortableContext>
          </DndContext>

          <button
            type="button"
            onClick={addField}
            className="px-3 py-1.5 text-sm rounded border border-dashed border-gray-300 text-gray-600 hover:bg-gray-50 w-full"
          >
            + Feld hinzufügen
          </button>
        </div>
      )}
    </Page>
  );
}

function SortableFieldRow({
  field,
  onChange,
  onRemove,
}: {
  field: Field;
  onChange: (patch: Partial<Field>) => void;
  onRemove: () => void;
}) {
  const { attributes, listeners, setNodeRef, transform, transition, isDragging } = useSortable({
    id: field.id,
  });
  const style: React.CSSProperties = {
    transform: CSS.Transform.toString(transform),
    transition,
    opacity: isDragging ? 0.5 : 1,
  };
  const isOptioned = field.type === 'select' || field.type === 'checkbox-group';

  return (
    <li ref={setNodeRef} style={style} className="bg-white border border-gray-200 rounded">
      <div className="flex items-start gap-2 p-3">
        <button
          type="button"
          {...attributes}
          {...listeners}
          className="cursor-grab text-gray-400 hover:text-gray-600 text-lg px-1 mt-1"
          aria-label="Sortieren"
        >
          ⋮⋮
        </button>
        <div className="flex-1 space-y-2">
          <div className="grid grid-cols-3 gap-2">
            <input
              type="text"
              value={field.label}
              onChange={(e) => onChange({ label: e.target.value })}
              placeholder="Label"
              className="px-2 py-1.5 border border-gray-300 rounded text-sm col-span-2"
            />
            <select
              value={field.type}
              onChange={(e) => onChange({ type: e.target.value as FieldType })}
              className="px-2 py-1.5 border border-gray-300 rounded text-sm"
            >
              {(Object.keys(TYPE_LABEL) as FieldType[]).map((t) => (
                <option key={t} value={t}>
                  {TYPE_LABEL[t]}
                </option>
              ))}
            </select>
          </div>
          <div className="grid grid-cols-3 gap-2">
            <input
              type="text"
              value={field.id}
              onChange={(e) => onChange({ id: e.target.value.replace(/[^a-z0-9_]/gi, '_') })}
              placeholder="Feld-ID (kein Leerzeichen)"
              className="px-2 py-1.5 border border-gray-300 rounded text-sm font-mono col-span-2"
            />
            <div className="flex items-center gap-3 text-xs">
              <label className="flex items-center gap-1">
                <input
                  type="checkbox"
                  checked={field.enabled}
                  onChange={(e) => onChange({ enabled: e.target.checked })}
                />
                aktiv
              </label>
              <label className="flex items-center gap-1">
                <input
                  type="checkbox"
                  checked={field.required}
                  onChange={(e) => onChange({ required: e.target.checked })}
                />
                Pflicht
              </label>
            </div>
          </div>
          {isOptioned && (
            <label className="block">
              <span className="text-xs text-gray-600">Optionen (eine pro Zeile)</span>
              <textarea
                rows={3}
                value={(field.options ?? []).join('\n')}
                onChange={(e) =>
                  onChange({
                    options: e.target.value
                      .split('\n')
                      .map((s) => s.trim())
                      .filter(Boolean),
                  })
                }
                className="mt-1 w-full px-2 py-1.5 border border-gray-300 rounded text-sm font-mono"
              />
            </label>
          )}
          {field.type === 'single-checkbox' && (
            <label className="block">
              <span className="text-xs text-gray-600">Checkbox-Text</span>
              <input
                type="text"
                value={field.checkbox_text ?? ''}
                onChange={(e) => onChange({ checkbox_text: e.target.value })}
                className="mt-1 w-full px-2 py-1.5 border border-gray-300 rounded text-sm"
              />
            </label>
          )}
        </div>
        <button
          type="button"
          onClick={onRemove}
          className="text-gray-400 hover:text-red-600 text-lg px-1"
          aria-label="Feld entfernen"
        >
          ×
        </button>
      </div>
    </li>
  );
}
