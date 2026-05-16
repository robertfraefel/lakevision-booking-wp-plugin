import { useEffect, useState } from 'react';
import { BrowserRouter, Routes, Route, Navigate } from 'react-router-dom';
import { Toaster } from 'sonner';
import { api, ApiError, type Capability, type CurrentUser } from './api/client';
import { AuthContext } from './lib/auth';
import { AppShell } from './components/AppShell';
import { CalendarPage } from './pages/CalendarPage';
import { BookingsPage } from './pages/BookingsPage';
import { CustomersPage } from './pages/CustomersPage';
import { PermissionsPage } from './pages/PermissionsPage';
import { NoAccess } from './pages/NoAccess';
import { Placeholder } from './pages/Placeholder';

export function App() {
  const [user, setUser] = useState<CurrentUser | null>(null);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    api
      .me()
      .then(setUser)
      .catch((err: unknown) => {
        if (err instanceof ApiError) setError(`${err.status}: ${err.message}`);
        else if (err instanceof Error) setError(err.message);
        else setError('Unbekannter Fehler');
      });
  }, []);

  if (error) {
    return (
      <div className="m-6 p-4 bg-red-50 border border-red-200 rounded text-red-800 text-sm">
        <strong className="block mb-1">Verbindungsfehler</strong>
        {error}
      </div>
    );
  }

  if (!user) {
    return <div className="m-6 text-gray-500">Lade…</div>;
  }

  const hasCap = (cap: Capability) => user.capabilities.includes(cap);
  const basePath = window.lvbAdmin?.basePath || '';
  const landing = pickLanding(user.capabilities);

  return (
    <AuthContext.Provider value={{ user, hasCap }}>
      <Toaster position="top-right" richColors />
      <BrowserRouter basename={basePath}>
        <Routes>
          <Route element={<AppShell />}>
            <Route index element={<Navigate to={landing} replace />} />
            <Route
              path="calendar"
              element={hasCap('lvb_view_calendar') ? <CalendarPage /> : <NoAccess />}
            />
            <Route
              path="bookings"
              element={hasCap('lvb_edit_all_bookings') ? <BookingsPage /> : <NoAccess />}
            />
            <Route
              path="customers"
              element={hasCap('lvb_manage_customers') ? <CustomersPage /> : <NoAccess />}
            />
            <Route
              path="services"
              element={
                hasCap('lvb_manage_services')
                  ? <Placeholder title="Services" hint="Phase 3 — kommt als nächstes." />
                  : <NoAccess />
              }
            />
            <Route
              path="staff"
              element={
                hasCap('lvb_manage_staff')
                  ? <Placeholder title="Mitarbeiter" hint="Phase 3 — kommt als nächstes." />
                  : <NoAccess />
              }
            />
            <Route
              path="settings"
              element={
                hasCap('lvb_manage_settings')
                  ? <Placeholder title="Einstellungen" hint="Phase 4." />
                  : <NoAccess />
              }
            />
            <Route
              path="permissions"
              element={hasCap('lvb_manage_permissions') ? <PermissionsPage /> : <NoAccess />}
            />
            <Route path="*" element={<NoAccess />} />
          </Route>
        </Routes>
      </BrowserRouter>
    </AuthContext.Provider>
  );
}

/**
 * Pick the first module the user is allowed to see as the landing page.
 * Falls back to /calendar (which will then render <NoAccess>).
 */
function pickLanding(caps: string[]): string {
  const order: Array<[Capability, string]> = [
    ['lvb_view_calendar',      '/calendar'],
    ['lvb_edit_all_bookings',  '/bookings'],
    ['lvb_manage_customers',   '/customers'],
    ['lvb_manage_services',    '/services'],
    ['lvb_manage_staff',       '/staff'],
    ['lvb_manage_settings',    '/settings'],
    ['lvb_manage_permissions', '/permissions'],
  ];
  for (const [cap, path] of order) {
    if (caps.includes(cap)) return path;
  }
  return '/calendar';
}
