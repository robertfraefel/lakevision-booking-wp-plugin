import { lazy, Suspense, useEffect, useState } from 'react';
import { BrowserRouter, Routes, Route, Navigate } from 'react-router-dom';
import { Toaster } from 'sonner';
import { api, ApiError, type Capability, type CurrentUser } from './api/client';
import { AuthContext } from './lib/auth';
import { AppShell } from './components/AppShell';
import { NoAccess } from './pages/NoAccess';

// Lazy-loaded pages — each becomes its own chunk so the initial bundle
// stays small. The biggest wins are CalendarPage (FullCalendar ~200 KB)
// and the form-builder/services pages (dnd-kit ~30 KB).
const CalendarPage     = lazy(() => import('./pages/CalendarPage').then((m) => ({ default: m.CalendarPage })));
const BookingsPage     = lazy(() => import('./pages/BookingsPage').then((m) => ({ default: m.BookingsPage })));
const CustomersPage    = lazy(() => import('./pages/CustomersPage').then((m) => ({ default: m.CustomersPage })));
const ServicesPage     = lazy(() => import('./pages/ServicesPage').then((m) => ({ default: m.ServicesPage })));
const StaffPage        = lazy(() => import('./pages/StaffPage').then((m) => ({ default: m.StaffPage })));
const SettingsPage     = lazy(() => import('./pages/SettingsPage').then((m) => ({ default: m.SettingsPage })));
const IntakeFormsPage  = lazy(() => import('./pages/IntakeFormsPage').then((m) => ({ default: m.IntakeFormsPage })));
const FormBuilderPage  = lazy(() => import('./pages/FormBuilderPage').then((m) => ({ default: m.FormBuilderPage })));
const PermissionsPage  = lazy(() => import('./pages/PermissionsPage').then((m) => ({ default: m.PermissionsPage })));

function RouteSuspense({ children }: { children: React.ReactNode }) {
  return (
    <Suspense
      fallback={
        <div className="bg-white border border-gray-200 rounded-lg p-8 text-center text-gray-400 text-sm">
          Lade Modul…
        </div>
      }
    >
      {children}
    </Suspense>
  );
}

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
              element={hasCap('lvb_view_calendar') ? <RouteSuspense><CalendarPage /></RouteSuspense> : <NoAccess />}
            />
            <Route
              path="bookings"
              element={hasCap('lvb_edit_all_bookings') ? <RouteSuspense><BookingsPage /></RouteSuspense> : <NoAccess />}
            />
            <Route
              path="customers"
              element={hasCap('lvb_manage_customers') ? <RouteSuspense><CustomersPage /></RouteSuspense> : <NoAccess />}
            />
            <Route
              path="services"
              element={hasCap('lvb_manage_services') ? <RouteSuspense><ServicesPage /></RouteSuspense> : <NoAccess />}
            />
            <Route
              path="staff"
              element={hasCap('lvb_manage_staff') ? <RouteSuspense><StaffPage /></RouteSuspense> : <NoAccess />}
            />
            <Route
              path="settings"
              element={hasCap('lvb_manage_settings') ? <RouteSuspense><SettingsPage /></RouteSuspense> : <NoAccess />}
            />
            <Route
              path="intake-forms"
              element={hasCap('lvb_manage_intake_forms') ? <RouteSuspense><IntakeFormsPage /></RouteSuspense> : <NoAccess />}
            />
            <Route
              path="intake-forms/builder"
              element={hasCap('lvb_manage_intake_forms') ? <RouteSuspense><FormBuilderPage /></RouteSuspense> : <NoAccess />}
            />
            <Route
              path="permissions"
              element={hasCap('lvb_manage_permissions') ? <RouteSuspense><PermissionsPage /></RouteSuspense> : <NoAccess />}
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
    ['lvb_view_calendar',       '/calendar'],
    ['lvb_edit_all_bookings',   '/bookings'],
    ['lvb_manage_customers',    '/customers'],
    ['lvb_manage_services',     '/services'],
    ['lvb_manage_staff',        '/staff'],
    ['lvb_manage_intake_forms', '/intake-forms'],
    ['lvb_manage_settings',     '/settings'],
    ['lvb_manage_permissions',  '/permissions'],
  ];
  for (const [cap, path] of order) {
    if (caps.includes(cap)) return path;
  }
  return '/calendar';
}
