import { NavLink, Outlet } from 'react-router-dom';
import { useAuth } from '../lib/auth';
import type { Capability } from '../api/client';

interface NavItem {
  to: string;
  label: string;
  cap: Capability;
  icon: string;
}

const NAV: NavItem[] = [
  { to: '/calendar',     label: 'Kalender',            cap: 'lvb_view_calendar',       icon: '📅' },
  { to: '/bookings',     label: 'Buchungen',           cap: 'lvb_edit_all_bookings',   icon: '📋' },
  { to: '/customers',    label: 'Kunden',              cap: 'lvb_manage_customers',    icon: '👥' },
  { to: '/services',     label: 'Services',            cap: 'lvb_manage_services',     icon: '🧾' },
  { to: '/staff',        label: 'Mitarbeiter',         cap: 'lvb_manage_staff',        icon: '🧑‍💼' },
  { to: '/intake-forms', label: 'Anmeldeformulare',    cap: 'lvb_manage_intake_forms', icon: '📝' },
  { to: '/settings',     label: 'Einstellungen',       cap: 'lvb_manage_settings',     icon: '⚙️' },
  { to: '/permissions',  label: 'Berechtigungen',      cap: 'lvb_manage_permissions',  icon: '🔐' },
];

export function AppShell() {
  const { user, hasCap } = useAuth();
  const visibleNav = NAV.filter((item) => hasCap(item.cap));

  return (
    <div className="lvb-shell flex flex-col md:flex-row min-h-[80vh] gap-4">
      {/* Sidebar (collapses to topbar on mobile) */}
      <aside className="md:w-56 md:flex-shrink-0">
        <div className="bg-white border border-gray-200 rounded-lg shadow-sm overflow-hidden">
          <div className="px-4 py-3 border-b border-gray-200">
            <div className="text-xs uppercase tracking-wider text-gray-400">Angemeldet</div>
            <div className="font-medium text-gray-900 truncate">{user.display_name}</div>
          </div>

          <nav className="py-2">
            {visibleNav.length === 0 ? (
              <div className="px-4 py-3 text-xs text-gray-500 italic">
                Keine Module verfügbar — Berechtigungen prüfen.
              </div>
            ) : (
              <ul>
                {visibleNav.map((item) => (
                  <li key={item.to}>
                    <NavLink
                      to={item.to}
                      className={({ isActive }) =>
                        [
                          'flex items-center gap-2 px-4 py-2 text-sm transition-colors',
                          isActive
                            ? 'bg-brand-50 text-brand-700 border-l-2 border-brand-500 font-medium'
                            : 'text-gray-700 hover:bg-gray-50',
                        ].join(' ')
                      }
                    >
                      <span aria-hidden>{item.icon}</span>
                      <span>{item.label}</span>
                    </NavLink>
                  </li>
                ))}
              </ul>
            )}
          </nav>
        </div>
      </aside>

      {/* Main content */}
      <main className="flex-1 min-w-0">
        <Outlet />
      </main>
    </div>
  );
}
