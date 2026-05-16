import { useEffect, useState } from 'react';
import { toast } from 'sonner';
import { api, ApiError } from '../api/client';
import { Page } from '../components/Page';

interface UserRow {
  id: number;
  display_name: string;
  email: string;
  overrides: string[];
  effective: string[];
}

export function PermissionsPage() {
  const [users, setUsers] = useState<UserRow[]>([]);
  const [caps, setCaps] = useState<Record<string, string>>({});
  const [loading, setLoading] = useState(true);
  const [savingId, setSavingId] = useState<number | null>(null);

  const load = () => {
    setLoading(true);
    api
      .listUsersWithCaps()
      .then((r) => {
        setUsers(r.items);
        setCaps(r.caps);
      })
      .catch((err) => {
        const msg = err instanceof ApiError ? err.message : 'Laden fehlgeschlagen';
        toast.error(msg);
      })
      .finally(() => setLoading(false));
  };

  useEffect(load, []);

  const toggleCap = async (user: UserRow, cap: string) => {
    const next = user.overrides.includes(cap)
      ? user.overrides.filter((c) => c !== cap)
      : [...user.overrides, cap];

    // Optimistic update.
    setUsers((us) =>
      us.map((u) => (u.id === user.id ? { ...u, overrides: next } : u))
    );
    setSavingId(user.id);
    try {
      const r = await api.setUserCaps(user.id, next);
      // Re-fetch to update `effective` (may differ from overrides if role-based
      // caps mask it).
      setUsers((us) =>
        us.map((u) =>
          u.id === user.id
            ? { ...u, overrides: r.overrides, effective: r.overrides }
            : u
        )
      );
    } catch (err) {
      // Roll back.
      setUsers((us) =>
        us.map((u) => (u.id === user.id ? { ...u, overrides: user.overrides } : u))
      );
      const msg = err instanceof ApiError ? err.message : 'Speichern fehlgeschlagen';
      toast.error(msg);
    } finally {
      setSavingId(null);
    }
  };

  const capKeys = Object.keys(caps);

  return (
    <Page title="Berechtigungen">
      <p className="text-sm text-gray-600 mb-4">
        Pro Benutzer kannst du genau steuern, welche Module sichtbar und bedienbar sind. Wenn
        keine Häkchen gesetzt sind, gelten die Standard-Rechte der WordPress-Rolle.
      </p>

      {loading ? (
        <div className="py-6 text-center text-gray-400 text-sm">Lade…</div>
      ) : (
        <div className="overflow-x-auto">
          <table className="w-full text-xs">
            <thead className="border-b border-gray-200 text-left text-gray-500 uppercase">
              <tr>
                <th className="py-2 pr-4 sticky left-0 bg-white">Benutzer</th>
                {capKeys.map((cap) => (
                  <th
                    key={cap}
                    className="py-2 px-2 text-center font-medium"
                    title={cap}
                  >
                    {caps[cap]}
                  </th>
                ))}
              </tr>
            </thead>
            <tbody>
              {users.map((u) => (
                <tr key={u.id} className="border-b border-gray-100 hover:bg-gray-50">
                  <td className="py-2 pr-4 sticky left-0 bg-white">
                    <div className="font-medium text-gray-900">{u.display_name}</div>
                    <div className="text-gray-500">{u.email}</div>
                  </td>
                  {capKeys.map((cap) => (
                    <td key={cap} className="py-2 px-2 text-center">
                      <input
                        type="checkbox"
                        checked={u.overrides.includes(cap)}
                        disabled={savingId === u.id}
                        onChange={() => toggleCap(u, cap)}
                        className="h-4 w-4"
                      />
                    </td>
                  ))}
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </Page>
  );
}
