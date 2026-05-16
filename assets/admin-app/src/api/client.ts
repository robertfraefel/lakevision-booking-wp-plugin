/**
 * Thin wrapper around the WordPress REST API, scoped to the LakeVision
 * Booking admin endpoints. Reads its config from the `window.lvbAdmin`
 * global emitted by [lvb_admin] (see class-admin-shortcode.php).
 */

import type {
  Booking,
  CalendarEvent,
  CalendarMeta,
  Customer,
  Service,
  Staff,
} from './types';

declare global {
  interface Window {
    lvbAdmin?: {
      restRoot: string;
      nonce: string;
      basePath: string;
      siteName: string;
      locale: string;
    };
  }
}

export interface CurrentUser {
  id: number;
  display_name: string;
  email: string;
  capabilities: string[];
  staff_id: number | null;
}

export type Capability =
  | 'lvb_view_calendar'
  | 'lvb_edit_own_bookings'
  | 'lvb_edit_all_bookings'
  | 'lvb_manage_customers'
  | 'lvb_manage_services'
  | 'lvb_manage_staff'
  | 'lvb_manage_settings'
  | 'lvb_manage_intake_forms'
  | 'lvb_manage_permissions';

const cfg = () => {
  if (!window.lvbAdmin) {
    throw new Error('lvbAdmin global is missing — shortcode did not enqueue assets.');
  }
  return window.lvbAdmin;
};

export class ApiError extends Error {
  constructor(public status: number, public detail: unknown) {
    super(
      typeof detail === 'object' && detail && 'message' in detail
        ? String((detail as { message: unknown }).message)
        : `HTTP ${status}`
    );
  }
}

async function request<T>(method: string, path: string, body?: unknown): Promise<T> {
  const { restRoot, nonce } = cfg();
  const url = restRoot.replace(/\/$/, '') + '/' + path.replace(/^\//, '');

  const res = await fetch(url, {
    method,
    credentials: 'same-origin',
    headers: {
      'Content-Type': 'application/json',
      'X-WP-Nonce': nonce,
    },
    body: body !== undefined ? JSON.stringify(body) : undefined,
  });

  if (!res.ok) {
    let detail: unknown = null;
    try { detail = await res.json(); } catch { /* ignore */ }
    throw new ApiError(res.status, detail);
  }

  if (res.status === 204) return undefined as T;
  return res.json() as Promise<T>;
}

const qs = (params: Record<string, unknown>): string => {
  const entries = Object.entries(params).filter(
    ([, v]) => v !== undefined && v !== null && v !== ''
  );
  if (!entries.length) return '';
  return '?' + new URLSearchParams(entries.map(([k, v]) => [k, String(v)])).toString();
};

export const api = {
  // Identity
  me: () => request<CurrentUser>('GET', 'admin/me'),

  // Bookings
  bookings: (q: Record<string, unknown> = {}) =>
    request<{ items: Booking[]; total: number; page: number; per_page: number }>(
      'GET',
      'admin/bookings' + qs(q)
    ),
  getBooking: (id: number) => request<Booking>('GET', `admin/bookings/${id}`),
  createBooking: (data: Partial<Booking> & Record<string, unknown>) =>
    request<{ ok: true; id: number }>('POST', 'admin/bookings', data),
  updateBooking: (id: number, data: Partial<Booking> & Record<string, unknown>) =>
    request<{ ok: true; id: number }>('PATCH', `admin/bookings/${id}`, data),
  cancelBooking: (id: number) =>
    request<{ ok: true; id: number }>('POST', `admin/bookings/${id}/cancel`),
  deleteBooking: (id: number) =>
    request<{ ok: true; id: number }>('DELETE', `admin/bookings/${id}`),

  // Customers
  customers: () => request<{ items: Customer[]; total: number }>('GET', 'admin/customers'),
  searchCustomers: (q: string) =>
    request<{ items: Customer[] }>('GET', 'admin/customers/search' + qs({ q })),
  createCustomer: (data: Partial<Customer>) =>
    request<{ ok: true; id: number }>('POST', 'admin/customers', data),
  updateCustomer: (id: number, data: Partial<Customer>) =>
    request<{ ok: true; id: number }>('PATCH', `admin/customers/${id}`, data),
  deleteCustomer: (id: number) =>
    request<{ ok: true; id: number }>('DELETE', `admin/customers/${id}`),

  // Services CRUD
  services: () => request<{ items: Service[]; total: number }>('GET', 'admin/services'),
  createService: (data: Partial<Service>) =>
    request<{ ok: true; id: number }>('POST', 'admin/services', data),
  updateService: (id: number, data: Partial<Service>) =>
    request<{ ok: true; id: number }>('PATCH', `admin/services/${id}`, data),
  deleteService: (id: number) =>
    request<{ ok: true; id: number }>('DELETE', `admin/services/${id}`),
  reorderServices: (ids: number[]) =>
    request<{ ok: true }>('POST', 'admin/services/reorder', { ids }),

  // Staff CRUD
  staff: () => request<{ items: Staff[]; total: number }>('GET', 'admin/staff'),
  getStaff: (id: number) =>
    request<Staff & { service_ids: number[] }>('GET', `admin/staff/${id}`),
  createStaff: (data: Record<string, unknown>) =>
    request<{ ok: true; id: number }>('POST', 'admin/staff', data),
  updateStaff: (id: number, data: Record<string, unknown>) =>
    request<{ ok: true; id: number }>('PATCH', `admin/staff/${id}`, data),
  deleteStaff: (id: number) =>
    request<{ ok: true; id: number }>('DELETE', `admin/staff/${id}`),

  meta: () => request<CalendarMeta>('GET', 'admin/meta'),

  // Settings
  getSettings: () =>
    request<{ values: Record<string, string | number> }>('GET', 'admin/settings'),
  putSettings: (values: Record<string, string | number | boolean>) =>
    request<{ values: Record<string, string | number> }>('PUT', 'admin/settings', values),

  // Google OAuth
  googleStatus: () =>
    request<{ connected: boolean; callback_url: string; default_calendar_id: string }>(
      'GET',
      'admin/settings/google/status'
    ),
  googleAuthUrl: () => request<{ url: string }>('GET', 'admin/settings/google/auth-url'),
  googleDisconnect: () =>
    request<{ ok: true; connected: false }>('POST', 'admin/settings/google/disconnect'),

  // Intake forms
  listIntakeForms: () =>
    request<{ items: Array<Record<string, unknown> & { id: number; created_at: string }> }>(
      'GET',
      'admin/intake-forms'
    ),
  getIntakeForm: (id: number) =>
    request<Record<string, unknown> & { id: number }>('GET', `admin/intake-forms/${id}`),
  deleteIntakeForm: (id: number) =>
    request<{ ok: true; id: number }>('DELETE', `admin/intake-forms/${id}`),
  getIntakeConfig: () =>
    request<{
      fields: Array<Record<string, unknown> & { id: string; type: string; label: string }>;
      enabled: 0 | 1;
      disclaimer: string;
    }>('GET', 'admin/intake-forms/config'),
  putIntakeConfig: (body: {
    fields: Array<Record<string, unknown>>;
    enabled?: 0 | 1 | boolean;
    disclaimer?: string;
  }) =>
    request<{
      fields: Array<Record<string, unknown> & { id: string; type: string; label: string }>;
      enabled: 0 | 1;
      disclaimer: string;
    }>('PUT', 'admin/intake-forms/config', body),

  // Calendar events (proxies to LVB_Calendar_API — unchanged)
  calendarEvents: (q: { from: string; to: string; staff_id?: number }) =>
    request<CalendarEvent[]>('GET', 'calendar/events' + qs(q)),
  calendarPatch: (id: number, body: { start: string; end: string }) =>
    request<{ ok: true; id: number; gcal_warning?: string }>('PATCH', `calendar/events/${id}`, body),

  // Permissions
  listUsersWithCaps: () =>
    request<{
      items: Array<{
        id: number;
        display_name: string;
        email: string;
        overrides: string[];
        effective: string[];
      }>;
      caps: Record<string, string>;
    }>('GET', 'admin/permissions/users'),
  setUserCaps: (id: number, capabilities: string[]) =>
    request<{ ok: true; id: number; overrides: string[] }>(
      'PUT',
      `admin/permissions/users/${id}`,
      { capabilities }
    ),
};
