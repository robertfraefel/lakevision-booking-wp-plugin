/**
 * Thin wrapper around the WordPress REST API, scoped to the LakeVision
 * Booking admin endpoints. Reads its config from the `window.lvbAdmin`
 * global emitted by [lvb_admin] (see class-admin-shortcode.php).
 */

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

const cfg = () => {
  if (!window.lvbAdmin) {
    throw new Error('lvbAdmin global is missing — shortcode did not enqueue assets.');
  }
  return window.lvbAdmin;
};

async function request<T>(method: string, path: string, body?: unknown): Promise<T> {
  const { restRoot, nonce } = cfg();
  const url = restRoot.replace(/\/$/, '') + '/admin/' + path.replace(/^\//, '');

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

export class ApiError extends Error {
  constructor(public status: number, public detail: unknown) {
    super(typeof detail === 'object' && detail && 'message' in detail
      ? String((detail as { message: unknown }).message)
      : `HTTP ${status}`);
  }
}

export const api = {
  me:        ()                       => request<CurrentUser>('GET', 'me'),
  bookings:  (q: Record<string, unknown> = {}) => {
    const qs = new URLSearchParams(
      Object.entries(q)
        .filter(([, v]) => v !== undefined && v !== null && v !== '')
        .map(([k, v]) => [k, String(v)])
    ).toString();
    return request<{ items: unknown[]; total: number }>('GET', 'bookings' + (qs ? '?' + qs : ''));
  },
  customers: ()                       => request<{ items: unknown[]; total: number }>('GET', 'customers'),
  services:  ()                       => request<{ items: unknown[]; total: number }>('GET', 'services'),
  staff:     ()                       => request<{ items: unknown[]; total: number }>('GET', 'staff'),
};
