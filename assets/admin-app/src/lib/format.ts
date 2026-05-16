/**
 * Tiny formatting helpers. Wraps Intl + date-fns so the rest of the app
 * doesn't need to know about either. All formats are locale-aware via
 * window.lvbAdmin.locale (default 'de-CH').
 */

import { format, parseISO } from 'date-fns';

const locale = () => window.lvbAdmin?.locale || 'de-CH';

export function formatDate(value: string | Date): string {
  const d = typeof value === 'string' ? parseISO(value) : value;
  return d.toLocaleDateString(locale(), { day: '2-digit', month: 'short', year: 'numeric' });
}

export function formatTime(value: string | Date): string {
  const d = typeof value === 'string' ? parseISO(value) : value;
  return d.toLocaleTimeString(locale(), { hour: '2-digit', minute: '2-digit' });
}

export function formatDateTime(value: string | Date): string {
  return `${formatDate(value)} · ${formatTime(value)}`;
}

export function formatRange(start: string | Date, end: string | Date): string {
  return `${formatTime(start)} – ${formatTime(end)}`;
}

export function formatPrice(value: string | number, currency = 'CHF'): string {
  const num = typeof value === 'string' ? parseFloat(value) : value;
  if (!Number.isFinite(num)) return '';
  return `${currency} ${num.toFixed(2)}`;
}

/**
 * Returns a YYYY-MM-DDTHH:MM string suitable for <input type="datetime-local">
 * — the browser's preferred format. Mirrors `date-fns` format() but keeps
 * the function inline for clarity.
 */
export function toDateTimeLocal(value: string | Date): string {
  const d = typeof value === 'string' ? parseISO(value) : value;
  return format(d, "yyyy-MM-dd'T'HH:mm");
}

export function fromDateTimeLocal(value: string): string {
  // Parse a YYYY-MM-DDTHH:MM string back to "Y-m-d H:i:s" for the API.
  if (!value) return '';
  const [date, time] = value.split('T');
  return `${date} ${time}:00`;
}
