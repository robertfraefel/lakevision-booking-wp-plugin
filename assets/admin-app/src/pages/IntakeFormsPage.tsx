import { useEffect, useState } from 'react';
import { toast } from 'sonner';
import { api, ApiError } from '../api/client';
import { Page } from '../components/Page';
import { Drawer } from '../components/Drawer';
import { formatDateTime } from '../lib/format';
import { Link } from 'react-router-dom';

interface IntakeRow {
  id: number;
  created_at: string;
  name?: string;
  email?: string;
  phone?: string;
  [k: string]: unknown;
}

export function IntakeFormsPage() {
  const [items, setItems] = useState<IntakeRow[]>([]);
  const [loading, setLoading] = useState(true);
  const [viewId, setViewId] = useState<number | null>(null);

  const load = () => {
    setLoading(true);
    api
      .listIntakeForms()
      .then((r) => setItems(r.items as IntakeRow[]))
      .catch((err) => {
        const msg = err instanceof ApiError ? err.message : 'Laden fehlgeschlagen';
        toast.error(msg);
      })
      .finally(() => setLoading(false));
  };

  useEffect(load, []);

  return (
    <Page
      title="Eingegangene Anmeldeformulare"
      actions={
        <Link
          to="/intake-forms/builder"
          className="px-3 py-1.5 text-sm rounded border border-gray-300 text-gray-700 hover:bg-gray-50"
        >
          Formular bearbeiten
        </Link>
      }
    >
      {loading ? (
        <div className="py-6 text-center text-gray-400 text-sm">Lade…</div>
      ) : items.length === 0 ? (
        <div className="py-6 text-center text-gray-400 text-sm">
          Noch keine Anmeldungen eingegangen.
        </div>
      ) : (
        <div className="overflow-x-auto">
          <table className="w-full text-sm">
            <thead className="border-b border-gray-200 text-left text-gray-500 uppercase text-xs">
              <tr>
                <th className="py-2 pr-4">Eingegangen am</th>
                <th className="py-2 pr-4">Name</th>
                <th className="py-2 pr-4">E-Mail</th>
                <th className="py-2 pr-4">Telefon</th>
                <th className="py-2"></th>
              </tr>
            </thead>
            <tbody>
              {items.map((row) => (
                <tr
                  key={row.id}
                  className="border-b border-gray-100 hover:bg-gray-50 cursor-pointer"
                  onClick={() => setViewId(row.id)}
                >
                  <td className="py-2 pr-4 text-gray-700">
                    {row.created_at ? formatDateTime(row.created_at.replace(' ', 'T')) : '–'}
                  </td>
                  <td className="py-2 pr-4 font-medium text-gray-900">{row.name ?? '–'}</td>
                  <td className="py-2 pr-4 text-gray-700">{row.email ?? '–'}</td>
                  <td className="py-2 pr-4 text-gray-500">{row.phone ?? ''}</td>
                  <td className="py-2 text-right">
                    <span className="text-xs text-brand-600">Ansehen →</span>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}

      {viewId !== null && (
        <IntakeDetailDrawer
          id={viewId}
          onClose={() => setViewId(null)}
          onDeleted={() => {
            setViewId(null);
            load();
          }}
        />
      )}
    </Page>
  );
}

function IntakeDetailDrawer({
  id,
  onClose,
  onDeleted,
}: {
  id: number;
  onClose: () => void;
  onDeleted: () => void;
}) {
  const [row, setRow] = useState<Record<string, unknown> | null>(null);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    setLoading(true);
    api
      .getIntakeForm(id)
      .then((r) => setRow(r))
      .catch((err) => {
        const msg = err instanceof ApiError ? err.message : 'Laden fehlgeschlagen';
        toast.error(msg);
      })
      .finally(() => setLoading(false));
  }, [id]);

  const onDelete = async () => {
    if (!confirm('Formular wirklich unwiderruflich löschen?')) return;
    try {
      await api.deleteIntakeForm(id);
      toast.success('Formular gelöscht');
      onDeleted();
    } catch (err) {
      const msg = err instanceof ApiError ? err.message : 'Löschen fehlgeschlagen';
      toast.error(msg);
    }
  };

  return (
    <Drawer open title={`Formular #${id}`} onClose={onClose} width="max-w-2xl">
      {loading || !row ? (
        <div className="text-gray-500 text-sm">Lade…</div>
      ) : (
        <div className="space-y-3 text-sm">
          {Object.entries(row).map(([key, value]) => {
            if (value === '' || value === null || value === undefined) return null;
            let display: string;
            if (key === 'custom_fields' && typeof value === 'string') {
              try {
                display = JSON.stringify(JSON.parse(value), null, 2);
              } catch {
                display = value;
              }
              return (
                <div key={key}>
                  <div className="text-xs uppercase text-gray-500 mb-1">{prettyKey(key)}</div>
                  <pre className="bg-gray-50 border border-gray-200 rounded p-2 text-xs overflow-x-auto">
                    {display}
                  </pre>
                </div>
              );
            }
            display = String(value);
            return (
              <div key={key} className="border-b border-gray-100 pb-2">
                <div className="text-xs uppercase text-gray-500">{prettyKey(key)}</div>
                <div className="text-gray-900 break-words">{display}</div>
              </div>
            );
          })}
          <div className="pt-3 flex items-center justify-between">
            <button
              type="button"
              onClick={onDelete}
              className="text-sm text-red-600 hover:text-red-800"
            >
              Löschen
            </button>
            <button
              type="button"
              onClick={onClose}
              className="px-3 py-1.5 text-sm rounded border border-gray-300 text-gray-700 hover:bg-gray-50"
            >
              Schliessen
            </button>
          </div>
        </div>
      )}
    </Drawer>
  );
}

function prettyKey(key: string): string {
  return key.replace(/_/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase());
}
