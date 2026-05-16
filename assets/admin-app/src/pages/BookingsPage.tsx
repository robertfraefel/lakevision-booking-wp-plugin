import { useEffect, useState } from 'react';
import { toast } from 'sonner';
import { api, ApiError } from '../api/client';
import type { Booking } from '../api/types';
import { Page } from '../components/Page';
import { BookingDrawer } from './BookingDrawer';
import { formatDate, formatPrice, formatRange } from '../lib/format';

const STATUS_LABEL: Record<Booking['status'], string> = {
  confirmed: 'Bestätigt',
  pending:   'Offen',
  cancelled: 'Abgesagt',
};

const STATUS_CLASS: Record<Booking['status'], string> = {
  confirmed: 'bg-emerald-100 text-emerald-800',
  pending:   'bg-amber-100 text-amber-800',
  cancelled: 'bg-gray-200 text-gray-600 line-through',
};

export function BookingsPage() {
  const [items, setItems] = useState<Booking[]>([]);
  const [loading, setLoading] = useState(true);
  const [status, setStatus] = useState<'' | Booking['status']>('');
  const [search, setSearch] = useState('');
  const [editingId, setEditingId] = useState<number | 'new' | null>(null);

  const load = () => {
    setLoading(true);
    api
      .bookings({ status, search, per_page: 100 })
      .then((r) => setItems(r.items))
      .catch((err) => {
        const msg = err instanceof ApiError ? err.message : 'Laden fehlgeschlagen';
        toast.error(msg);
      })
      .finally(() => setLoading(false));
  };

  useEffect(() => {
    const t = setTimeout(load, 200);
    return () => clearTimeout(t);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [status, search]);

  return (
    <Page
      title="Buchungen"
      actions={
        <button
          type="button"
          onClick={() => setEditingId('new')}
          className="px-3 py-1.5 text-sm rounded bg-brand-600 text-white hover:bg-brand-700"
        >
          + Neue Buchung
        </button>
      }
    >
      <div className="flex flex-col sm:flex-row gap-2 mb-3">
        <select
          value={status}
          onChange={(e) => setStatus(e.target.value as typeof status)}
          className="px-2 py-1.5 border border-gray-300 rounded text-sm"
        >
          <option value="">Alle Status</option>
          <option value="confirmed">Bestätigt</option>
          <option value="pending">Offen</option>
          <option value="cancelled">Abgesagt</option>
        </select>
        <input
          type="search"
          placeholder="Suche (Name / E-Mail / Service)"
          value={search}
          onChange={(e) => setSearch(e.target.value)}
          className="flex-1 px-2 py-1.5 border border-gray-300 rounded text-sm"
        />
      </div>

      <div className="overflow-x-auto">
        <table className="w-full text-sm">
          <thead className="border-b border-gray-200 text-left text-gray-500 uppercase text-xs">
            <tr>
              <th className="py-2 pr-4">Datum / Zeit</th>
              <th className="py-2 pr-4">Sitzung</th>
              <th className="py-2 pr-4">Kunde</th>
              <th className="py-2 pr-4">Status</th>
              <th className="py-2 pr-4">Preis</th>
              <th className="py-2"></th>
            </tr>
          </thead>
          <tbody>
            {loading && (
              <tr>
                <td colSpan={6} className="py-6 text-center text-gray-400">
                  Lade…
                </td>
              </tr>
            )}
            {!loading && items.length === 0 && (
              <tr>
                <td colSpan={6} className="py-6 text-center text-gray-400">
                  Keine Buchungen gefunden.
                </td>
              </tr>
            )}
            {items.map((b) => (
              <tr
                key={b.id}
                className="border-b border-gray-100 hover:bg-gray-50 cursor-pointer"
                onClick={() => setEditingId(b.id)}
              >
                <td className="py-2 pr-4">
                  <div className="font-medium text-gray-900">
                    {formatDate(b.start_datetime.replace(' ', 'T'))}
                  </div>
                  <div className="text-xs text-gray-500">
                    {formatRange(
                      b.start_datetime.replace(' ', 'T'),
                      b.end_datetime.replace(' ', 'T')
                    )}
                  </div>
                </td>
                <td className="py-2 pr-4">
                  {b.service_name || '–'}
                  {b.staff_name && <div className="text-xs text-gray-500">{b.staff_name}</div>}
                </td>
                <td className="py-2 pr-4">
                  {(b.customer_first_name || '') + ' ' + (b.customer_last_name || '')}
                  <div className="text-xs text-gray-500">{b.customer_email}</div>
                </td>
                <td className="py-2 pr-4">
                  <span className={`px-2 py-0.5 rounded text-xs ${STATUS_CLASS[b.status]}`}>
                    {STATUS_LABEL[b.status]}
                  </span>
                </td>
                <td className="py-2 pr-4 text-gray-700">{formatPrice(b.price)}</td>
                <td className="py-2 text-right">
                  <span className="text-xs text-brand-600">Bearbeiten →</span>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>

      {editingId !== null && (
        <BookingDrawer
          bookingId={editingId === 'new' ? null : editingId}
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
