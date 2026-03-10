# LakeVision Booking – WordPress Plugin

A flexible, self-contained booking plugin for WordPress with Google Calendar integration, time-slot management, and HTML email notifications.

## Features

- **Multi-step booking widget** via `[lvb_booking]` shortcode
- **Google Calendar integration** – reads availability from Google Calendar, writes confirmed bookings as events
- **Buffer-aware slot generation** – automatically excludes booked slots including configurable buffer time
- **Staff management** – assign staff to services, each with their own Google Calendar
- **HTML email notifications** – booking confirmations to customer and admin, cancellation emails
- **Configurable branding** – logo, labels, confirmation text, WhatsApp channel URL
- **Admin dashboard** – manage bookings, customers, services, and staff
- **Water temperature widget** – optional AJAX proxy for Swiss BAFU water temperature API

---

## Requirements

- WordPress 6.0+
- PHP 8.0+
- A Google Cloud project with OAuth 2.0 credentials (for Google Calendar integration)

---

## Installation

1. Download or clone this repository into your WordPress plugins directory:
   ```
   wp-content/plugins/lakevision-booking/
   ```
2. Activate the plugin in **WordPress Admin → Plugins**.
3. A new **LV Booking** menu appears in the WordPress sidebar.

---

## Google Calendar Setup

1. Go to [Google Cloud Console](https://console.cloud.google.com/) → **APIs & Services → Credentials**.
2. Create an **OAuth 2.0 Client ID** (type: Web application).
3. Add the plugin's redirect URI as an **Authorised Redirect URI** (found in **LV Booking → Settings → OAuth Redirect URI**).
4. In **LV Booking → Settings**, enter your **Client ID** and **Client Secret**, then click **Save Credentials**.
5. Click **Connect Google Calendar** and complete the OAuth flow.
6. Set the **Default Calendar ID** (use `primary` or a specific calendar address).

---

## Configuration

All settings are in **LV Booking → Settings**.

### Google Calendar Integration
| Option | Description |
|---|---|
| Client ID | OAuth 2.0 Client ID from Google Cloud Console |
| Client Secret | OAuth 2.0 Client Secret |
| Default Calendar ID | Calendar to read availability from (`primary` or `name@group.calendar.google.com`) |

### General Settings
| Option | Description |
|---|---|
| Currency Symbol | Prepended to prices (default: `$`) |
| Admin Notification Email | Where new booking alerts are sent |

### Email Settings
| Option | Description |
|---|---|
| From Name | Display name in outgoing emails |
| From Email Address | Sender address for outgoing emails |

### Branding & Customization
| Option | Description |
|---|---|
| Email Logo URL | Logo shown in booking confirmation emails |
| Staff Label | Label for staff in emails, e.g. `Instructor`, `Coach`, `Guide` |
| Service Label | Label for the service selector, e.g. `Service`, `Activity`, `Sportart` |
| Confirmation Email Text | Body text in the customer confirmation email |
| WhatsApp Channel URL | If set, a WhatsApp follow button is shown on the booking confirmation screen |

---

## Shortcode Usage

Place the booking widget anywhere on your site:

```
[lvb_booking]
```

Optional attributes to pre-select a service or staff member:

```
[lvb_booking service_id="1" staff_id="2"]
```

---

## Staff & Services

1. **Services** – Go to **LV Booking → Services** to add bookable services (name, duration, buffer time, price).
2. **Staff** – Go to **LV Booking → Staff** to add staff members. Each staff member can have their own Google Calendar ID.
3. Link staff to services in the Staff edit form.

> **Slot generation:** Each slot occupies `duration + buffer` minutes. For example, a 15-minute session with a 5-minute buffer generates slots every 20 minutes (10:00, 10:20, 10:40, …).

---

## Architecture

| Class | Responsibility |
|---|---|
| `LVB_Database` | DB install, generic CRUD helpers, booking queries |
| `LVB_Google_Calendar` | OAuth flow, Calendar API wrapper, availability slots |
| `LVB_Booking_Manager` | Booking/service/staff business logic |
| `LVB_Notifications` | HTML email generation and delivery |
| `LVB_Shortcode` | Frontend shortcode rendering and AJAX endpoints |
| `LVB_Water_Temp` | Water-temperature fetch, cache, and AJAX proxy |
| `LVB_Admin` | Admin menu, asset enqueueing, form handling |

---

## Changelog

### 1.1.0
- Configurable branding: logo URL, staff label, service label, confirmation text, WhatsApp URL
- Buffer-aware slot generation (step = duration + buffer)
- Auto-assign staff when only one staff member is linked to a service
- Admin booking list shows time range (start – end)
- Twint / Bar payment hint on confirmation screen
- Real logo in HTML emails

### 1.0.0
- Initial release

---

## License

GPL-2.0+
