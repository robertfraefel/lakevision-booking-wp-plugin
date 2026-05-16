import { useEffect, useMemo, useState } from 'react';
import { toast } from 'sonner';
import { api, ApiError } from '../api/client';
import type { Customer } from '../api/types';
import { Page } from '../components/Page';
import { Drawer } from '../components/Drawer';

export function CustomersPage() {
  const [items, setItems] = useState<Customer[]>([]);
  const [loading, setLoading] = useState(true);
  const [search, setSearch] = useState('');
  const [editing, setEditing] = useState<Customer | 'new' | null>(null);

  const load = () => {
    setLoading(true);
    api
      .customers()
      .then((r) => setItems(r.items))
      .catch((err) => {
        const msg = err instanceof ApiError ? err.message : 'Laden fehlgeschlagen';
        toast.error(msg);
      })
      .finally(() => setLoading(false));
  };

  useEffect(load, []);

  const filtered = useMemo(() => {
    const q = search.trim().toLowerCase();
    if (!q) return items;
    return items.filter((c) =>
      [c.first_name, c.last_name, c.email, c.phone].some((v) => v?.toLowerCase().includes(q))
    );
  }, [items, search]);

  return (
    <Page
      title="Kunden"
      actions={
        <button
          type="button"
          onClick={() => setEditing('new')}
          className="px-3 py-1.5 text-sm rounded bg-brand-600 text-white hover:bg-brand-700"
        >
          + Neuer Kunde
        </button>
      }
    >
      <input
        type="search"
        placeholder="Suche (Name / E-Mail / Telefon)…"
        value={search}
        onChange={(e) => setSearch(e.target.value)}
        className="w-full mb-3 px-2 py-1.5 border border-gray-300 rounded text-sm"
      />

      <div className="overflow-x-auto">
        <table className="w-full text-sm">
          <thead className="border-b border-gray-200 text-left text-gray-500 uppercase text-xs">
            <tr>
              <th className="py-2 pr-4">Name</th>
              <th className="py-2 pr-4">E-Mail</th>
              <th className="py-2 pr-4">Telefon</th>
              <th className="py-2"></th>
            </tr>
          </thead>
          <tbody>
            {loading && (
              <tr>
                <td colSpan={4} className="py-6 text-center text-gray-400">Lade…</td>
              </tr>
            )}
            {!loading && filtered.length === 0 && (
              <tr>
                <td colSpan={4} className="py-6 text-center text-gray-400">Keine Kunden gefunden.</td>
              </tr>
            )}
            {filtered.map((c) => (
              <tr
                key={c.id}
                className="border-b border-gray-100 hover:bg-gray-50 cursor-pointer"
                onClick={() => setEditing(c)}
              >
                <td className="py-2 pr-4 font-medium text-gray-900">
                  {c.first_name} {c.last_name}
                </td>
                <td className="py-2 pr-4 text-gray-700">{c.email}</td>
                <td className="py-2 pr-4 text-gray-500">{c.phone}</td>
                <td className="py-2 text-right">
                  <span className="text-xs text-brand-600">Bearbeiten →</span>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>

      {editing !== null && (
        <CustomerDrawer
          customer={editing === 'new' ? null : editing}
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

interface CustomerDrawerProps {
  customer: Customer | null;
  onClose: () => void;
  onSaved: () => void;
}

function CustomerDrawer({ customer, onClose, onSaved }: CustomerDrawerProps) {
  const isNew = customer === null;
  const [form, setForm] = useState<Partial<Customer>>(customer ?? {
    first_name: '',
    last_name:  '',
    email:      '',
    phone:      '',
  });
  const [saving, setSaving] = useState(false);

  const submit = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!form.email) {
      toast.error('E-Mail ist Pflicht.');
      return;
    }
    setSaving(true);
    try {
      if (isNew) {
        await api.createCustomer(form);
        toast.success('Kunde angelegt');
      } else {
        await api.updateCustomer(customer!.id, form);
        toast.success('Kunde gespeichert');
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
    if (!customer) return;
    if (!confirm('Diesen Kunden wirklich löschen? Geht nur, wenn er keine Buchungen mehr hat.'))
      return;
    setSaving(true);
    try {
      await api.deleteCustomer(customer.id);
      toast.success('Kunde gelöscht');
      onSaved();
    } catch (err) {
      const msg = err instanceof ApiError ? err.message : 'Löschen fehlgeschlagen';
      toast.error(msg);
    } finally {
      setSaving(false);
    }
  };

  return (
    <Drawer open title={isNew ? 'Neuer Kunde' : `Kunde #${customer!.id}`} onClose={onClose}>
      <form onSubmit={submit} className="space-y-3">
        <div className="grid grid-cols-2 gap-2">
          <input
            type="text"
            placeholder="Vorname"
            value={form.first_name ?? ''}
            onChange={(e) => setForm((f) => ({ ...f, first_name: e.target.value }))}
            className="px-2 py-1.5 border border-gray-300 rounded text-sm"
          />
          <input
            type="text"
            placeholder="Nachname"
            value={form.last_name ?? ''}
            onChange={(e) => setForm((f) => ({ ...f, last_name: e.target.value }))}
            className="px-2 py-1.5 border border-gray-300 rounded text-sm"
          />
        </div>
        <input
          type="email"
          required
          placeholder="E-Mail (Pflicht)"
          value={form.email ?? ''}
          onChange={(e) => setForm((f) => ({ ...f, email: e.target.value }))}
          className="w-full px-2 py-1.5 border border-gray-300 rounded text-sm"
        />
        <input
          type="tel"
          placeholder="Telefon"
          value={form.phone ?? ''}
          onChange={(e) => setForm((f) => ({ ...f, phone: e.target.value }))}
          className="w-full px-2 py-1.5 border border-gray-300 rounded text-sm"
        />

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
