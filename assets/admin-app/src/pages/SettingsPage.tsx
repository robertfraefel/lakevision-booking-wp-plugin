import { useEffect, useState } from 'react';
import { toast } from 'sonner';
import { api, ApiError } from '../api/client';
import { Page } from '../components/Page';

type SettingsValues = Record<string, string | number>;

type FieldType = 'text' | 'textarea' | 'color' | 'int' | 'bool' | 'email' | 'time';

interface FieldDef {
  key: string;
  label: string;
  hint?: string;
  type: FieldType;
}

interface TabDef {
  id: string;
  label: string;
  fields: FieldDef[];
}

const TABS: TabDef[] = [
  {
    id: 'general',
    label: 'Allgemein',
    fields: [
      { key: 'lvb_booking_title',     type: 'text', label: 'Titel über Buchungs-Widget' },
      { key: 'lvb_booking_subtitle',  type: 'text', label: 'Untertitel' },
      { key: 'lvb_currency_symbol',   type: 'text', label: 'Währungssymbol' },
      { key: 'lvb_payment_title',     type: 'text', label: 'Zahlungs-Titel' },
      { key: 'lvb_payment_methods',   type: 'text', label: 'Zahlungsarten',
        hint: 'Mit Semikolon trennen. Unterstützt: Twint, Bar, Debit, Credit (mit Icon).' },
      { key: 'lvb_whatsapp_url',      type: 'text', label: 'WhatsApp Gruppen-URL' },
      { key: 'lvb_min_advance_hours', type: 'text', label: 'Min. Vorlauf (Stunden)' },
      { key: 'lvb_cutoff_grid',       type: 'text', label: 'Cutoff-Raster' },
      { key: 'lvb_slot_realign_grid', type: 'text', label: 'Slot-Realign-Raster' },
      { key: 'lvb_calendar_time_min', type: 'time', label: 'Kalender Start' },
      { key: 'lvb_calendar_time_max', type: 'time', label: 'Kalender Ende' },
    ],
  },
  {
    id: 'email',
    label: 'E-Mail',
    fields: [
      { key: 'lvb_email_from',              type: 'text',    label: 'Absender-Name' },
      { key: 'lvb_email_from_address',      type: 'email',   label: 'Absender-Adresse' },
      { key: 'lvb_admin_notification_email',type: 'email',   label: 'Admin-Benachrichtigungs-Adresse' },
      { key: 'lvb_email_logo_url',          type: 'text',    label: 'Logo-URL für E-Mails' },
      { key: 'lvb_email_confirmation_text', type: 'textarea',label: 'Bestätigungs-Text' },
      { key: 'lvb_email_reschedule_text',   type: 'textarea',label: 'Umbuchungs-Text' },
      { key: 'lvb_email_cancellation_note', type: 'textarea',label: 'Stornierungs-Hinweis' },
    ],
  },
  {
    id: 'labels',
    label: 'Labels',
    fields: [
      { key: 'lvb_staff_label',   type: 'text', label: 'Mitarbeiter-Label' },
      { key: 'lvb_service_label', type: 'text', label: 'Service-Label' },
      { key: 'lvb_slot_label',    type: 'text', label: 'Slot-Label' },
    ],
  },
  {
    id: 'reminder',
    label: 'Reminder',
    fields: [
      { key: 'lvb_reminder_enabled', type: 'bool', label: 'Reminder aktiv' },
      { key: 'lvb_reminder_hours',   type: 'int',  label: 'Stunden vorher senden' },
    ],
  },
  {
    id: 'design',
    label: 'Design',
    fields: [
      { key: 'lvb_theme_inherit',    type: 'bool',  label: 'Theme-Farben vom Theme erben' },
      { key: 'lvb_accent_color',     type: 'color', label: 'Akzent 1' },
      { key: 'lvb_accent2_color',    type: 'color', label: 'Akzent 2' },
      { key: 'lvb_dark_color',       type: 'color', label: 'Dunkel' },
      { key: 'lvb_bg_color',         type: 'color', label: 'Hintergrund' },
      { key: 'lvb_footer_bg_color',  type: 'color', label: 'Footer-Hintergrund' },
      { key: 'lvb_text_color',       type: 'color', label: 'Text' },
    ],
  },
  {
    id: 'disclaimer',
    label: 'Disclaimer',
    fields: [
      { key: 'lvb_disclaimer_enabled', type: 'bool',     label: 'Disclaimer anzeigen' },
      { key: 'lvb_disclaimer_text',    type: 'textarea', label: 'Disclaimer-Text' },
    ],
  },
];

export function SettingsPage() {
  const [values, setValues] = useState<SettingsValues>({});
  const [dirty, setDirty] = useState(false);
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [tab, setTab] = useState('general');

  // Google OAuth panel state
  const [google, setGoogle] = useState<{ connected: boolean; callback_url: string } | null>(null);

  const loadAll = () => {
    setLoading(true);
    Promise.all([api.getSettings(), api.googleStatus()])
      .then(([s, g]) => {
        setValues(s.values);
        setGoogle({ connected: g.connected, callback_url: g.callback_url });
        setDirty(false);
      })
      .catch((err) => {
        const msg = err instanceof ApiError ? err.message : 'Laden fehlgeschlagen';
        toast.error(msg);
      })
      .finally(() => setLoading(false));
  };

  useEffect(loadAll, []);

  const set = (key: string, value: string | number | boolean) => {
    setValues((v) => ({ ...v, [key]: typeof value === 'boolean' ? (value ? 1 : 0) : value }));
    setDirty(true);
  };

  const save = async () => {
    setSaving(true);
    try {
      const r = await api.putSettings(values);
      setValues(r.values);
      setDirty(false);
      toast.success('Einstellungen gespeichert');
    } catch (err) {
      const msg = err instanceof ApiError ? err.message : 'Speichern fehlgeschlagen';
      toast.error(msg);
    } finally {
      setSaving(false);
    }
  };

  const connectGoogle = async () => {
    try {
      const r = await api.googleAuthUrl();
      const popup = window.open(r.url, 'lvb-google-oauth', 'width=480,height=640');
      if (!popup) {
        toast.error('Popup wurde blockiert. Bitte Pop-ups erlauben.');
        return;
      }
      // Poll the WP popup window: once it returns to our domain (the WP admin
      // page after the OAuth callback completes), it'll either close itself or
      // become same-origin so we can detect completion.
      const timer = setInterval(async () => {
        if (popup.closed) {
          clearInterval(timer);
          const g = await api.googleStatus();
          setGoogle({ connected: g.connected, callback_url: g.callback_url });
          if (g.connected) toast.success('Google Calendar verbunden');
        }
      }, 800);
    } catch (err) {
      const msg = err instanceof ApiError ? err.message : 'OAuth-Start fehlgeschlagen';
      toast.error(msg);
    }
  };

  const disconnectGoogle = async () => {
    if (!confirm('Google-Verbindung wirklich trennen? Bestehende Buchungen bleiben erhalten.'))
      return;
    try {
      await api.googleDisconnect();
      toast.success('Google getrennt');
      setGoogle({ ...(google ?? { callback_url: '' }), connected: false });
    } catch (err) {
      const msg = err instanceof ApiError ? err.message : 'Trennen fehlgeschlagen';
      toast.error(msg);
    }
  };

  return (
    <Page
      title="Einstellungen"
      actions={
        <button
          type="button"
          onClick={save}
          disabled={!dirty || saving || loading}
          className="px-3 py-1.5 text-sm rounded bg-brand-600 text-white hover:bg-brand-700 disabled:opacity-50"
        >
          {saving ? 'Speichert…' : 'Speichern'}
        </button>
      }
    >
      <div className="flex flex-wrap gap-1 mb-4 border-b border-gray-200">
        {TABS.map((t) => (
          <button
            key={t.id}
            type="button"
            onClick={() => setTab(t.id)}
            className={[
              'px-3 py-1.5 text-sm rounded-t border-b-2 -mb-px',
              tab === t.id
                ? 'border-brand-500 text-brand-700 font-medium'
                : 'border-transparent text-gray-600 hover:text-gray-900',
            ].join(' ')}
          >
            {t.label}
          </button>
        ))}
        <button
          type="button"
          onClick={() => setTab('google')}
          className={[
            'px-3 py-1.5 text-sm rounded-t border-b-2 -mb-px',
            tab === 'google'
              ? 'border-brand-500 text-brand-700 font-medium'
              : 'border-transparent text-gray-600 hover:text-gray-900',
          ].join(' ')}
        >
          Google Calendar
        </button>
      </div>

      {loading ? (
        <div className="py-6 text-center text-gray-400 text-sm">Lade…</div>
      ) : tab === 'google' ? (
        <GoogleSection
          values={values}
          set={set}
          google={google}
          onConnect={connectGoogle}
          onDisconnect={disconnectGoogle}
        />
      ) : (
        <div className="space-y-3 max-w-2xl">
          {TABS.find((t) => t.id === tab)?.fields.map((f) => (
            <Field key={f.key} def={f} value={values[f.key]} onChange={(v) => set(f.key, v)} />
          ))}
        </div>
      )}
    </Page>
  );
}

function Field({
  def,
  value,
  onChange,
}: {
  def: FieldDef;
  value: string | number | undefined;
  onChange: (v: string | number | boolean) => void;
}) {
  const v = value ?? '';

  if (def.type === 'bool') {
    return (
      <label className="flex items-start gap-2 py-1 cursor-pointer">
        <input
          type="checkbox"
          checked={Number(v) === 1}
          onChange={(e) => onChange(e.target.checked)}
          className="mt-0.5 h-4 w-4"
        />
        <span>
          <span className="text-sm font-medium text-gray-800">{def.label}</span>
          {def.hint && <span className="block text-xs text-gray-500">{def.hint}</span>}
        </span>
      </label>
    );
  }

  return (
    <label className="block">
      <span className="text-xs text-gray-600">{def.label}</span>
      {def.type === 'textarea' ? (
        <textarea
          rows={3}
          value={String(v)}
          onChange={(e) => onChange(e.target.value)}
          className="mt-1 w-full px-2 py-1.5 border border-gray-300 rounded text-sm"
        />
      ) : def.type === 'color' ? (
        <div className="mt-1 flex items-center gap-2">
          <input
            type="color"
            value={String(v) || '#000000'}
            onChange={(e) => onChange(e.target.value)}
            className="h-8 w-12 border border-gray-300 rounded"
          />
          <input
            type="text"
            value={String(v)}
            onChange={(e) => onChange(e.target.value)}
            className="flex-1 px-2 py-1.5 border border-gray-300 rounded text-sm font-mono"
            placeholder="#RRGGBB"
          />
        </div>
      ) : def.type === 'int' ? (
        <input
          type="number"
          value={Number(v) || 0}
          onChange={(e) => onChange(Number(e.target.value))}
          className="mt-1 w-32 px-2 py-1.5 border border-gray-300 rounded text-sm"
        />
      ) : (
        <input
          type={def.type === 'email' ? 'email' : def.type === 'time' ? 'time' : 'text'}
          value={String(v)}
          onChange={(e) => onChange(e.target.value)}
          className="mt-1 w-full px-2 py-1.5 border border-gray-300 rounded text-sm"
        />
      )}
      {def.hint && <span className="block text-xs text-gray-500 mt-0.5">{def.hint}</span>}
    </label>
  );
}

function GoogleSection({
  values,
  set,
  google,
  onConnect,
  onDisconnect,
}: {
  values: SettingsValues;
  set: (key: string, value: string | number | boolean) => void;
  google: { connected: boolean; callback_url: string } | null;
  onConnect: () => void;
  onDisconnect: () => void;
}) {
  return (
    <div className="space-y-4 max-w-2xl">
      <div className="p-3 bg-gray-50 border border-gray-200 rounded">
        <div className="flex items-center justify-between gap-3">
          <div>
            <div className="text-sm font-medium text-gray-900">
              Status: {google?.connected ? '✅ Verbunden' : '❌ Nicht verbunden'}
            </div>
            <div className="text-xs text-gray-500 break-all mt-1">
              Redirect-URI:{' '}
              <code className="font-mono">{google?.callback_url}</code>
            </div>
          </div>
          <div className="flex-shrink-0">
            {google?.connected ? (
              <button
                type="button"
                onClick={onDisconnect}
                className="px-3 py-1.5 text-sm rounded border border-red-300 text-red-700 hover:bg-red-50"
              >
                Trennen
              </button>
            ) : (
              <button
                type="button"
                onClick={onConnect}
                className="px-3 py-1.5 text-sm rounded bg-brand-600 text-white hover:bg-brand-700"
              >
                Mit Google verbinden
              </button>
            )}
          </div>
        </div>
      </div>

      <Field
        def={{ key: 'lvb_google_client_id', type: 'text', label: 'OAuth Client ID' }}
        value={values['lvb_google_client_id']}
        onChange={(v) => set('lvb_google_client_id', v)}
      />
      <Field
        def={{ key: 'lvb_google_client_secret', type: 'text', label: 'OAuth Client Secret' }}
        value={values['lvb_google_client_secret']}
        onChange={(v) => set('lvb_google_client_secret', v)}
      />
      <Field
        def={{
          key: 'lvb_google_default_calendar_id',
          type: 'text',
          label: 'Standard-Kalender-ID',
          hint: 'Wird verwendet, wenn ein Mitarbeiter keinen eigenen Kalender hat.',
        }}
        value={values['lvb_google_default_calendar_id']}
        onChange={(v) => set('lvb_google_default_calendar_id', v)}
      />
    </div>
  );
}
