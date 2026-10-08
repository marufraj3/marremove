import { useCallback, useEffect, useRef, useState } from 'react';
import { getFacebookWebhookStatus } from '../api/facebookWebhookService.js';
import { AppShell } from '../components/AppShell.jsx';
import { friendlyError } from '../utils/errors.js';

function formatDate(value) {
  if (!value) return 'Never';
  const date = new Date(value);
  return Number.isNaN(date.getTime()) ? 'Unavailable' : date.toLocaleString();
}

function StatusBadge({ status }) {
  const display = {
    verified: { label: 'Verified', classes: 'border-emerald-300/30 bg-emerald-300/10 text-emerald-200' },
    pending: { label: 'Waiting for Meta verification', classes: 'border-amber-300/30 bg-amber-300/10 text-amber-200' },
    not_configured: { label: 'Not configured', classes: 'border-rose-300/30 bg-rose-300/10 text-rose-200' },
  }[status] || { label: 'Unknown', classes: 'border-white/10 bg-white/5 text-slate-300' };

  return (
    <span className={`inline-flex rounded-full border px-3 py-1.5 text-xs font-medium ${display.classes}`}>
      {display.label}
    </span>
  );
}

function ReadinessRow({ label, ready }) {
  return (
    <div className="flex items-center justify-between gap-4 border-t border-white/10 py-3 first:border-0 first:pt-0 last:pb-0">
      <span className="text-sm text-slate-300">{label}</span>
      <span className={`text-xs font-medium ${ready ? 'text-emerald-300' : 'text-rose-300'}`}>
        {ready ? 'Configured' : 'Missing'}
      </span>
    </div>
  );
}

export function FacebookWebhookStatusPage() {
  const [status, setStatus] = useState(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [lastUpdated, setLastUpdated] = useState(null);
  const requestId = useRef(0);

  const loadStatus = useCallback(async (silent = false, signal) => {
    const activeRequest = ++requestId.current;
    if (!silent) setLoading(true);
    try {
      const result = await getFacebookWebhookStatus({ signal });
      if (activeRequest !== requestId.current) return;
      setStatus(result?.data || null);
      setLastUpdated(new Date());
      setError('');
    } catch (requestError) {
      if (activeRequest !== requestId.current) return;
      setError(friendlyError(requestError, 'Could not load webhook status.'));
    } finally {
      if (activeRequest === requestId.current && !silent) setLoading(false);
    }
  }, []);

  useEffect(() => {
    const controller = new AbortController();
    loadStatus(false, controller.signal);
    const intervalId = window.setInterval(() => loadStatus(true), 15000);
    return () => {
      window.clearInterval(intervalId);
      controller.abort();
      requestId.current += 1;
    };
  }, [loadStatus]);

  return (
    <AppShell activePage="webhooks">
      <section className="w-full">
        <div className="flex flex-col gap-5 border-b border-white/10 pb-7 md:flex-row md:items-end md:justify-between">
          <div>
            <p className="mb-3 text-xs font-semibold uppercase tracking-[0.24em] text-cyan-300">
              Meta Page webhooks
            </p>
            <h1 className="text-3xl font-semibold tracking-tight text-white sm:text-4xl">
              Webhook status
            </h1>
            <p className="mt-3 max-w-2xl text-sm leading-6 text-slate-400">
              Monitor Meta callback verification and the most recent comment events for your connected Pages.
            </p>
          </div>
          <button
            type="button"
            onClick={() => loadStatus()}
            disabled={loading}
            className="rounded-xl border border-white/15 px-4 py-2.5 text-sm font-medium text-slate-200 transition hover:border-cyan-300/50 hover:text-white disabled:cursor-wait disabled:opacity-50"
          >
            {loading ? 'Refreshing…' : 'Refresh status'}
          </button>
        </div>

        {error && (
          <div role="alert" className="mt-6 rounded-xl border border-rose-400/25 bg-rose-400/10 px-4 py-3 text-sm text-rose-200">
            {error}
            {error.toLowerCase().includes('unauthenticated') && (
              <span className="mt-1 block text-xs text-rose-200/75">
                Sign in to view status for your connected Pages.
              </span>
            )}
          </div>
        )}

        {status && (
          <div className="mt-7 grid gap-5 lg:grid-cols-[1.2fr_0.8fr]">
            <div className="space-y-5">
              <article className="rounded-2xl border border-white/10 bg-white/[0.035] p-5 sm:p-6">
                <div className="flex flex-wrap items-center justify-between gap-3">
                  <h2 className="text-base font-semibold text-white">Callback verification</h2>
                  <StatusBadge status={status.verification_status} />
                </div>
                <p className="mt-2 text-sm leading-6 text-slate-400">
                  Meta verifies this public callback URL using the verify token configured on the server.
                </p>
                <div className="mt-5">
                  <p className="mb-2 text-xs font-medium uppercase tracking-wide text-slate-500">Webhook URL</p>
                  <code className="block overflow-x-auto rounded-xl border border-white/10 bg-slate-950/70 px-4 py-3 text-sm text-cyan-100">
                    {status.webhook_url}
                  </code>
                </div>
                <p className="mt-4 text-xs text-slate-500">
                  {status.verified_at ? `Last verified ${formatDate(status.verified_at)}` : 'No successful Meta verification has been recorded.'}
                </p>
              </article>

              <article className="rounded-2xl border border-white/10 bg-white/[0.035] p-5 sm:p-6">
                <h2 className="text-base font-semibold text-white">Comment event activity</h2>
                  <div className="mt-4 grid gap-3 sm:grid-cols-2">
                    <div className="rounded-xl border border-white/10 bg-slate-950/40 p-4">
                      <p className="text-xs font-medium uppercase tracking-wide text-slate-500">Last received</p>
                      <p className="mt-2 text-sm font-medium text-slate-100">{formatDate(status.last_received_at)}</p>
                      <p className="mt-1 text-xs text-slate-500">Signed delivery accepted for a connected Page</p>
                    </div>
                    <div className="rounded-xl border border-white/10 bg-slate-950/40 p-4">
                      <p className="text-xs font-medium uppercase tracking-wide text-slate-500">Last processed</p>
                      <p className="mt-2 text-sm font-medium text-slate-100">{formatDate(status.last_processed_at)}</p>
                      <p className="mt-1 text-xs text-slate-500">Comment details refreshed from Meta</p>
                    </div>
                  </div>
                  <div className="mt-3 grid grid-cols-2 gap-3 sm:grid-cols-4">
                    {[
                      ['Total events', status.total_events],
                      ['Processed', status.processed_events],
                      ['Queued', status.queued_events],
                      ['Failed', status.failed_events],
                    ].map(([label, value]) => (
                      <div key={label} className="rounded-xl border border-white/10 bg-slate-950/40 p-3">
                        <p className="text-[10px] font-medium uppercase tracking-wide text-slate-500">{label}</p>
                        <p className="mt-1.5 text-lg font-semibold tabular-nums text-slate-100">{Number(value || 0).toLocaleString()}</p>
                      </div>
                    ))}
                  </div>
                  {status.last_error_category && (
                    <p className="mt-3 rounded-lg border border-amber-300/15 bg-amber-300/[0.04] px-3 py-2 text-xs text-amber-100">
                      Latest event issue: {String(status.last_error_category).replaceAll('_', ' ')}
                    </p>
                  )}
              </article>
            </div>

            <aside className="space-y-5">
              <article className="rounded-2xl border border-white/10 bg-white/[0.035] p-5 sm:p-6">
                <h2 className="text-base font-semibold text-white">Server configuration</h2>
                <p className="mb-5 mt-2 text-sm leading-6 text-slate-400">
                  Values stay on the backend; this page only reports whether they are present.
                </p>
                <ReadinessRow label="Meta App Secret" ready={status.app_secret_configured} />
                <ReadinessRow label="Webhook verify token" ready={status.verify_token_configured} />
              </article>

              <article className="rounded-2xl border border-cyan-300/15 bg-cyan-300/[0.04] p-5 sm:p-6">
                <p className="text-sm font-semibold text-cyan-100">Meta setup checklist</p>
                <ul className="mt-3 space-y-2 text-sm leading-6 text-slate-400">
                  <li>Use a publicly reachable HTTPS callback URL.</li>
                  <li>Subscribe the app to the Page <code className="text-cyan-200">feed</code> field with webhook values enabled.</li>
                  <li>Subscribe each connected Page to the app.</li>
                </ul>
                <p className="mt-4 border-t border-white/10 pt-4 text-xs leading-5 text-slate-500">
                  This status page does not expose tokens or provide moderation controls.
                </p>
              </article>
            </aside>
          </div>
        )}

        {!status && loading && (
          <div className="mt-8 rounded-2xl border border-white/10 bg-white/[0.035] p-6 text-sm text-slate-400">
            Loading webhook status…
          </div>
        )}
        {lastUpdated && (
          <p className="mt-5 text-right text-xs text-slate-600">Updated {lastUpdated.toLocaleTimeString()}</p>
        )}
      </section>
    </AppShell>
  );
}
