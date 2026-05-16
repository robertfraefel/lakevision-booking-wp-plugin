/**
 * Shared shape definitions for things the REST API returns. Kept loose on
 * purpose — the PHP layer doesn't have a generated schema, so any field
 * that's optional should be `| undefined` here.
 */

export interface Booking {
  id: number;
  service_id: number;
  staff_id: number | null;
  customer_id: number;
  start_datetime: string;
  end_datetime: string;
  status: 'pending' | 'confirmed' | 'cancelled';
  price: string | number;
  notes: string;
  google_event_id?: string;
  buffer_event_id?: string;
  reminder_sent?: 0 | 1;
  service_name?: string;
  staff_name?: string;
  customer_first_name?: string;
  customer_last_name?: string;
  customer_email?: string;
}

export interface Customer {
  id: number;
  first_name: string;
  last_name: string;
  email: string;
  phone: string;
  notes?: string;
  created_at?: string;
}

export interface Service {
  id: number;
  name: string;
  description?: string;
  duration: number;
  price: string | number;
  buffer_time?: number;
  status: 'active' | 'inactive';
  sort_order?: number;
}

export interface Staff {
  id: number;
  name: string;
  email?: string;
  phone?: string;
  calendar_id?: string;
  working_hours?: string;
  time_off?: string;
  color_id?: number | string;
  status: 'active' | 'inactive';
}

export interface CalendarMeta {
  services: Service[];
  staff: Staff[];
  service_staff: Record<number, number[]>;
  staff_label: string;
  service_label: string;
}

export interface CalendarEvent {
  id: number | string;
  title: string;
  start: string;
  end: string;
  color?: string;
  backgroundColor?: string;
  borderColor?: string;
  extendedProps?: {
    booking_id?: number;
    is_buffer?: boolean;
    customer_name?: string;
    service_name?: string;
    staff_name?: string;
    status?: string;
  };
}
