import { useEffect, useState } from 'react';
import { getAiModerationSettings } from '../api/aiModerationService.js';
import { getFacebookWebhookStatus } from '../api/facebookWebhookService.js';
import { getApiHealth } from '../api/healthService.js';
import { AppShell } from '../components/AppShell.jsx';
import { Badge, Button, ErrorState, LoadingState, PageHeader, Panel } from '../components/ui.jsx';
import { friendlyError } from '../utils/errors.js';

function SettingLine({ label, value, description }) {
  return <div className="flex flex-col gap-1 border-t border-white/[0.06] py-3 first:border-0 first:pt-0 last:pb-0 sm:flex-row sm:items-center sm:justify-between sm:gap-5"><span className="text-xs text-slate-400">{label}{description && <span className="mt-0.5 block text-[10px] text-slate-600">{description}</span>}</span><span className="text-xs font-semibold text-slate-200">{value}</span></div>;
}

export function SystemSettingsPage() {
  const [health, setHealth] = useState(null);
  const [ai, setAi] = useState(null);
  const [webhook, setWebhook] = useState(null);
  const [errors, setErrors] = useState({});
  const [loading, setLoading] = useState(true);
  const [refreshKey, setRefreshKey] = useState(0);

  useEffect(() => {
    let cancelled = false;
    setLoading(true);
    Promise.allSettled([getApiHealth(), getAiModerationSettings(), getFacebookWebhookStatus()]).then((results) => {
      if (cancelled) return;
      const nextErrors = {};
      if (results[0].status === 'fulfilled') setHealth(results[0].value);
      else nextErrors.health = friendlyError(results[0].reason, 'Could not check API health.');
      if (results[1].status === 'fulfilled') setAi(results[1].value?.data || null);
      else nextErrors.ai = friendlyError(results[1].reason, 'Could not read server moderation settings.');
      if (results[2].status === 'fulfilled') setWebhook(results[2].value?.data || null);
      else nextErrors.webhook = friendlyError(results[2].reason, 'Could not read webhook status.');
      setErrors(nextErrors);
    }).finally(() => { if (!cancelled) setLoading(false); });
    return () => { cancelled = true; };
  }, [refreshKey]);

  const actions = ai?.default_action_settings || {};
  const thresholds = ai?.default_thresholds || {};
  const readiness = webhook?.verification_status || 'unknown';

  return (
    <AppShell activePage="system-settings">
      <PageHeader eyebrow="Platform configuration" title="System Settings" description="Read-only view of safe system status and backend-controlled configuration. Secrets, credentials, and environment values are never returned." actions={<Button onClick={() => setRefreshKey((key) => key + 1)} disabled={loading}>↻ Refresh status</Button>} />
      {loading ? <LoadingState label="Loading system status" rows={4} /> : (
        <>
          {Object.keys(errors).length > 0 && <div className="mb-4 space-y-2">{Object.entries(errors).map(([key, message]) => <ErrorState key={key} message={`${key === 'health' ? 'API health' : key === 'ai' ? 'Moderation configuration' : 'Webhook status'}: ${message}`} />)}</div>}
          <div className="grid gap-4 xl:grid-cols-2">
            <Panel className="p-4 sm:p-5">
              <div className="flex items-center justify-between gap-3"><div><h2 className="text-sm font-semibold text-white">Application health</h2><p className="mt-1 text-[11px] text-slate-500">Public health endpoint; no authentication secrets are returned.</p></div><Badge tone={health?.status === 'ok' ? 'success' : 'danger'}>{health?.status || 'Unavailable'}</Badge></div>
              <div className="mt-5"><SettingLine label="Service" value={health?.service || '—'} /><SettingLine label="API status" value={health?.status || 'Unavailable'} /><SettingLine label="Browser API path" value="Same-origin /api" description="Requests are proxied through the frontend server to Laravel." /></div>
            </Panel>

            <Panel className="p-4 sm:p-5">
              <div className="flex items-center justify-between gap-3"><div><h2 className="text-sm font-semibold text-white">Moderation safeguards</h2><p className="mt-1 text-[11px] text-slate-500">Effective server configuration, safe to display.</p></div><Badge tone={actions.test_mode ? 'warning' : 'neutral'}>{actions.test_mode ? 'Test mode' : 'Live mode configured'}</Badge></div>
              <div className="mt-5"><SettingLine label="AI service" value={ai?.enabled ? 'Enabled' : 'Disabled'} /><SettingLine label="AI credential" value={ai?.api_key_configured ? 'Configured · hidden' : 'Not configured'} /><SettingLine label="Automatic hide" value={actions.auto_hide_enabled ? 'Allowed' : 'Disabled'} /><SettingLine label="Automatic delete" value={actions.auto_delete_enabled ? 'Allowed · dangerous' : 'Disabled'} /><SettingLine label="Automatic execution" value={actions.auto_execute_actions ? 'Enabled' : 'Disabled'} /><SettingLine label="Action queue" value={actions.queue_connection || '—'} /><SettingLine label="Action retry limit" value={actions.max_attempts ?? '—'} /><SettingLine label="Thresholds (review / hide / delete)" value={thresholds ? `${thresholds.auto_review_threshold ?? '—'} / ${thresholds.auto_hide_threshold ?? '—'} / ${thresholds.auto_delete_threshold ?? '—'}` : '—'} /></div>
              <p className="mt-3 rounded-xl border border-amber-300/10 bg-amber-300/[0.025] p-3 text-[11px] leading-5 text-amber-100/70">This view does not change server environment settings. Test mode simulates action success and blocks direct Meta action calls.</p>
            </Panel>

            <Panel className="p-4 sm:p-5">
              <div className="flex items-center justify-between gap-3"><div><h2 className="text-sm font-semibold text-white">Meta webhook</h2><p className="mt-1 text-[11px] text-slate-500">Callback verification and signed event activity.</p></div><Badge tone={readiness === 'verified' ? 'success' : readiness === 'not_configured' ? 'danger' : 'warning'}>{String(readiness).replaceAll('_', ' ')}</Badge></div>
              <div className="mt-5"><SettingLine label="Webhook URL" value={webhook?.webhook_url || '—'} /><SettingLine label="Last received" value={webhook?.last_received_at ? new Date(webhook.last_received_at).toLocaleString() : 'Never'} /><SettingLine label="Events received" value={Number(webhook?.total_events || 0).toLocaleString()} /><SettingLine label="Processed / queued / failed" value={`${webhook?.processed_events ?? 0} / ${webhook?.queued_events ?? 0} / ${webhook?.failed_events ?? 0}`} /><SettingLine label="App secret" value={webhook?.app_secret_configured ? 'Configured · hidden' : 'Not configured'} /></div>
              {webhook?.last_error_category && <p className="mt-3 text-xs text-amber-200">Latest webhook issue: {String(webhook.last_error_category).replaceAll('_', ' ')}</p>}
              <a href="/facebook/webhook" className="mt-4 inline-flex text-xs font-semibold text-cyan-200 hover:text-white">Open webhook diagnostics →</a>
            </Panel>

            <Panel className="p-4 sm:p-5">
              <h2 className="text-sm font-semibold text-white">Configuration management</h2>
              <p className="mt-2 text-xs leading-6 text-slate-400">Sensitive configuration is managed on the backend through protected environment and deployment settings. This dashboard intentionally provides no browser editor for Page Access Tokens, the Meta App Secret, or the Gemini API key.</p>
              <div className="mt-4 rounded-xl border border-cyan-300/10 bg-cyan-300/[0.025] p-3 text-xs leading-5 text-cyan-100/70">Need to rotate a credential or change global behavior? Update the server-side configuration, deploy it securely, then use Refresh to verify the non-secret status.</div>
            </Panel>
          </div>
        </>
      )}
    </AppShell>
  );
}
