# LakeVision Booking – WordPress Plugin

A flexible, self-contained booking plugin for WordPress with Google Calendar integration, time-slot management, and HTML email notifications.

## Features

- **Multi-step booking widget** via `[lvb_booking]` shortcode
- **Google Calendar integration** – reads availability from Google Calendar, writes confirmed bookings as events
- **Google Calendar health check** – daily cron verifies the connection and sends email alerts on failure
- **Buffer-aware slot generation** – automatically excludes booked slots including configurable buffer time
- **Minimum advance booking** – configurable minimum hours before a slot can be booked (default: 24h)
- **Staff management** – assign staff to services, each with their own Google Calendar
- **HTML email notifications** – booking confirmations to customer and admin, cancellation emails, reminder emails
- **Intake form** – pre-session questionnaire via `[lvb_intake_form]` shortcode with dynamic field builder
- **Configurable branding** – logo, labels, confirmation text, WhatsApp channel URL, accent colors
- **Admin dashboard** – manage bookings, customers, services, staff, and intake forms
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
5. **Important:** In **APIs & Services → OAuth consent screen → Audience**, set the publishing status to **Production** (not "Testing"). In Testing mode, refresh tokens expire after 7 days.
6. Click **Connect Google Calendar** and complete the OAuth flow. You may see a "Google hasn't verified this app" warning — click **Advanced → Go to [App name]** to proceed.
7. Set the **Default Calendar ID** (use `primary` or a specific calendar address like `abc123@group.calendar.google.com`).

---

## Configuration

All settings are in **LV Booking → Settings**.

### Google Calendar Integration
| Option | Description |
|---|---|
| Client ID | OAuth 2.0 Client ID from Google Cloud Console |
| Client Secret | OAuth 2.0 Client Secret |
| Default Calendar ID | Calendar to read availability from (`primary` or `name@group.calendar.google.com`) |

### Booking Page
| Option | Description |
|---|---|
| Booking Page Title | Main heading above the booking widget |
| Booking Page Subtitle | Subtitle shown below the main heading |
| Minimum Advance Booking | Minimum hours before a slot can be booked (default: `24`). Cutoff is rounded to 15-minute increments. Set to `0` for no restriction |

### General Settings
| Option | Description |
|---|---|
| Currency Symbol | Prepended to prices (default: `$`) |
| Admin Notification Email | Where new booking alerts are sent |
| Disclaimer | Enable/disable disclaimer checkbox on booking form |
| Disclaimer Text | Disclaimer text shown to the customer |

### Email Settings
| Option | Description |
|---|---|
| From Name | Display name in outgoing emails |
| From Email Address | Sender address for outgoing emails |

### Reminders
| Option | Description |
|---|---|
| Reminder Enabled | Enable/disable automatic reminder emails |
| Reminder Hours | Hours before the appointment to send reminder |

### Branding & Customization
| Option | Description |
|---|---|
| Email Logo URL | Logo shown in booking confirmation emails |
| Staff Label | Label for staff in emails, e.g. `Instructor`, `Coach`, `Guide` |
| Service Label | Label for the service selector, e.g. `Service`, `Activity`, `Sitzung` |
| Slot Label | Label for the time slot step, e.g. `Session`, `Sitzung` |
| Payment Title | Title for the payment section on confirmation screen |
| Payment Methods | Semicolon-separated payment methods, e.g. `Twint;Bar;Debit;Credit` |
| Accent Color | Primary accent color for the booking widget |
| Accent Color 2 | Secondary accent color |
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

## Intake Form

The intake form allows customers to fill out a pre-session questionnaire before their first appointment.

### Setup

1. Go to **LV Booking → Settings** and enable the Intake Form.
2. Configure the disclaimer text in Settings.
3. Use **LV Booking → Intake Form Builder** to customize fields (drag & drop reordering, enable/disable, add custom fields).

### Shortcode

```
[lvb_intake_form]
```

### Features

- Dynamic field configuration via Form Builder admin page
- Supported field types: `text`, `email`, `tel`, `date`, `textarea`, `select`, `checkbox-group`, `single-checkbox`
- "Sonstiges" (Other) option with free-text input for checkbox groups
- Honeypot spam protection (compatible with Cloudflare page caching)
- Admin email notification with all form data
- Customer confirmation email
- Automatic customer linking by email address
- Birthday field synced to customer profile
- Submitted forms viewable in **LV Booking → Intake Forms**

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
| `LVB_Intake_Form` | Intake form shortcode, rendering, and AJAX submission |
| `LVB_Water_Temp` | Water-temperature fetch, cache, and AJAX proxy |
| `LVB_Admin` | Admin menu, asset enqueueing, form handling |

---

## Google Calendar Health Check

The plugin includes a daily cron job (`lvb_calendar_health_check`) that verifies the Google Calendar connection is working. If the access token refresh fails, an email alert is sent to the admin notification address (maximum once per 24 hours).

**Important:** The Google Cloud project must be set to **Production** (not "Testing") in the OAuth consent screen. In Testing mode, refresh tokens expire after 7 days, breaking the calendar connection.

---

## Database Tables

| Table | Description |
|---|---|
| `wp_lvb_bookings` | All bookings with status, notes, Google Calendar event ID |
| `wp_lvb_customers` | Customer profiles with birthday field |
| `wp_lvb_services` | Bookable services with duration, buffer, price |
| `wp_lvb_staff` | Staff members with individual calendar IDs |
| `wp_lvb_staff_services` | Staff-to-service assignments |
| `wp_lvb_intake_forms` | Submitted intake forms with custom_fields JSON column |

---

## Changelog

### 1.3.0
- Google Calendar health check with daily cron and email alerts
- Minimum advance booking setting (default 24h, rounded to 15-min increments)
- Intake form: merged disclaimer/confirmation/privacy into single checkbox
- Intake form: reordered disclaimer (checkbox first, then text)
- Intake form v2 CSS
- Plugin deactivation cleans up health check cron

### 1.2.0
- Intake form system with `[lvb_intake_form]` shortcode
- Intake Form Builder admin page (drag & drop, enable/disable fields)
- Dynamic field types: text, email, tel, date, textarea, select, checkbox-group, single-checkbox
- Admin and customer email notifications for intake form submissions
- Intake Forms admin list with detail view
- Customer birthday field synced from intake form
- First-booking logic: intake form link if no form submitted
- Honeypot spam protection (Cloudflare compatible)
- Booking notes column in admin
- Reminder email cron system

### 1.1.0
- Configurable branding: logo URL, staff label, service label, confirmation text, WhatsApp URL
- Buffer-aware slot generation (step = duration + buffer)
- Auto-assign staff when only one staff member is linked to a service
- Admin booking list shows time range (start – end)
- Twint / Bar / Debit / Credit payment hint on confirmation screen
- Real logo in HTML emails
- Accent color customization

### 1.0.0
- Initial release

---

## License

GPL-2.0+
