import { useEffect, useState } from 'react';
import { api, ApiError, CurrentUser } from './api/client';

/**
 * Phase-1 skeleton: identity probe + connectivity check.
 *
 * Renders a small status panel that fetches /admin/me and surfaces the
 * resolved user + their lvb_* capabilities. This is the proof that the
 * REST API, nonce, and capability layer are all wired up correctly.
 *
 * Real navigation and module screens land in Phase 2.
 */
export function App() {
  const [me, setMe] = useState<CurrentUser | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    api.me()
      .then(setMe)
      .catch((err: unknown) => {
        if (err instanceof ApiError) {
          setError(`${err.status}: ${err.message}`);
        } else if (err instanceof Error) {
          setError(err.message);
        } else {
          setError('Unbekannter Fehler');
        }
      })
      .finally(() => setLoading(false));
  }, []);

  return (
    <div className="min-h-[400px] p-6 bg-white rounded-lg shadow-sm border border-gray-200">
      <header className="flex items-center justify-between border-b border-gray-200 pb-4 mb-6">
        <h1 className="text-2xl font-semibold text-gray-900">Verwaltung</h1>
        <span className="text-xs uppercase tracking-wider text-gray-500">
          LakeVision Booking · v2.0.0-alpha
        </span>
      </header>

      {loading && <p className="text-gray-500">Lade…</p>}

      {error && (
        <div className="p-4 bg-red-50 border border-red-200 rounded text-red-800 text-sm">
          <strong className="block mb-1">Verbindungsfehler</strong>
          {error}
        </div>
      )}

      {me && (
        <div className="space-y-4">
          <div>
            <div className="text-sm text-gray-500">Angemeldet als</div>
            <div className="text-lg font-medium">{me.display_name}</div>
            <div className="text-sm text-gray-600">{me.email}</div>
          </div>

          <div>
            <div className="text-sm text-gray-500 mb-1">Deine Berechtigungen</div>
            {me.capabilities.length === 0 ? (
              <div className="text-sm text-gray-500 italic">Keine Berechtigungen zugewiesen.</div>
            ) : (
              <ul className="flex flex-wrap gap-2">
                {me.capabilities.map((cap) => (
                  <li
                    key={cap}
                    className="px-2 py-1 bg-brand-50 text-brand-700 text-xs rounded font-mono"
                  >
                    {cap}
                  </li>
                ))}
              </ul>
            )}
          </div>

          <div className="pt-4 border-t border-gray-200 text-xs text-gray-400">
            Phase 1 Foundation läuft. Module folgen in Phase 2 (Kalender, Buchungen, Kunden).
          </div>
        </div>
      )}
    </div>
  );
}
