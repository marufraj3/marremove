import { useEffect, useMemo, useState } from 'react';
import { getAiModerationSettings, testGeminiModeration, updatePageModerationSettings } from '../api/aiModerationService.js';
import { AppShell } from '../components/AppShell.jsx';
import { Badge, Button, EmptyState, ErrorState, LoadingState, PageHeader, Panel } from '../components/ui.jsx';
import { friendlyError } from '../utils/errors.js';
import { emitToast } from '../components/ui.jsx';

function formatDuration(value) {
  const ms = Number(value);
  return Number.isFinite(ms) ? `${ms.toLocaleString()} ms` : '—';
}

export function AiModerationSettingsPage() {
  const [settings, setSettings] = useState(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [refreshKey, setRefreshKey] = useState(0);
  const [busyPage, setBusyPage] = useState('');
  const [drafts, setDrafts] = useState({});
  const [selectedPageId, setSelectedPageId] = useState('');
  const [comment, setComment] = useState('');
  const [postText, setPostText] = useState('');
  const [testing, setTesting] = useState(false);
  const [testError, setTestError] = useState('');
  const [testResult, setTestResult] = useState(null);

  useEffect(() => {
    let cancelled = false;
    setLoading(true);
    getAiModerationSettings()
      .then((result) => {
        if (cancelled) return;
        const data = result?.data || null;
        setSettings(data);
        setDrafts(Object.fromEntries((data?.pages || []).map((page) => [page.id, Boolean(page.ai_enabled)])));
        setSelectedPageId((current) => current || String(data?.pages?.[0]?.id || ''));
        setError('');
      })
      .catch((requestError) => { if (!cancelled) setError(friendlyError(requestError, 'Could not load AI settings.')); })
      .finally(() => { if (!cancelled) setLoading(false); });
    return () => { cancelled = true; };
  }, [refreshKey]);

  const selectedPage = useMemo(() => settings?.pages?.find((page) => String(page.id) === String(selectedPageId)) || null, [settings, selectedPageId]);

  async function savePage(page) {
    setBusyPage(String(page.id));
    try {
      const result = await updatePageModerationSettings(page.id, { ai_enabled: Boolean(drafts[page.id]) });
      const updated = result?.data;
      setSettings((current) => current && ({ ...current, pages: current.pages.map((item) => String(item.id) === String(page.id) ? { ...item, ...updated } : item) }));
      emitToast(`${page.page_name}: AI setting saved.`);
    } catch (requestError) {
      emitToast(friendlyError(requestError, 'Could not save the Page AI setting.'), 'error');
    } finally {
      setBusyPage('');
    }
  }

  async function runTest(event) {
    event.preventDefault();
    if (testing || !comment.trim()) return;
    setTesting(true);
    setTestError('');
    setTestResult(null);
    try {
      const result = await testGeminiModeration({ comment, pageName: selectedPage?.page_name || '', postText });
      setTestResult(result || null);
    } catch (requestError) {
      setTestError(friendlyError(requestError, 'The AI moderation test failed.'));
    } finally {
      setTesting(false);
    }
  }

  const config = settings || {};
  const textInput = 'mt-2 w-full rounded-xl border border-white/10 bg-[#0b1120] px-3.5 py-3 text-sm text-white outline-none placeholder:text-slate-600 focus:border-cyan-300/50';

  return (
    <AppShell activePage="ai">
      <PageHeader eyebrow="AI configuration" title="AI Settings" description="Inspect server-controlled Gemini availability and run a stateless classifier test. API keys and provider credentials are never returned to the browser." actions={<Button onClick={() => setRefreshKey((key) => key + 1)} disabled={loading}>↻ Refresh</Button>} />

      {error && <div className="mb-5"><ErrorState message={error} onRetry={() => setRefreshKey((key) => key + 1)} /></div>}
      {loading ? <LoadingState label="Loading AI settings" rows={3} /> : settings ? (
        <>
          <Panel className="p-4 sm:p-5">
            <div className="flex flex-wrap items-center justify-between gap-3">
              <div><h2 className="text-sm font-semibold text-white">Gemini service</h2><p className="mt-1 text-xs text-slate-500">Configuration is read from the backend environment; secret values are not exposed.</p></div>
              <Badge tone={config.enabled && config.api_key_configured ? 'success' : 'warning'}>{config.enabled && config.api_key_configured ? 'Ready' : 'Not fully configured'}</Badge>
            </div>
            <div className="mt-5 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
              <div className="rounded-xl border border-white/[0.06] bg-white/[0.02] p-3"><p className="text-[10px] uppercase tracking-wide text-slate-500">AI service</p><p className="mt-1.5 text-sm font-semibold text-slate-100">{config.enabled ? 'Enabled' : 'Disabled'}</p></div>
              <div className="rounded-xl border border-white/[0.06] bg-white/[0.02] p-3"><p className="text-[10px] uppercase tracking-wide text-slate-500">API key</p><p className="mt-1.5 text-sm font-semibold text-slate-100">{config.api_key_configured ? 'Configured · value hidden' : 'Not configured'}</p></div>
              <div className="rounded-xl border border-white/[0.06] bg-white/[0.02] p-3"><p className="text-[10px] uppercase tracking-wide text-slate-500">Model</p><p className="mt-1.5 truncate text-sm font-semibold text-slate-100">{config.model || 'Not reported'}</p></div>
              <div className="rounded-xl border border-white/[0.06] bg-white/[0.02] p-3"><p className="text-[10px] uppercase tracking-wide text-slate-500">Timeout / retries</p><p className="mt-1.5 text-sm font-semibold text-slate-100">{config.timeout_seconds ?? '—'}s · {config.max_retries ?? '—'} retries</p></div>
            </div>
            <p className="mt-4 text-xs leading-5 text-slate-500">Failure decision: <span className="capitalize text-slate-300">{String(config.failure_decision || 'review').replaceAll('_', ' ')}</span>. Update API credentials only on the server; this dashboard cannot reveal or change secret keys.</p>
          </Panel>

          <Panel className="mt-4 p-4 sm:p-5">
            <div><h2 className="text-sm font-semibold text-white">Per-Page AI switch</h2><p className="mt-1 text-xs leading-5 text-slate-500">This switch controls whether AI classification is enabled for a Page. Thresholds and action safeguards live under Moderation Settings.</p></div>
            {config.pages?.length ? <div className="mt-4 divide-y divide-white/[0.06]">
              {config.pages.map((page) => (
                <div key={page.id} className="flex flex-wrap items-center justify-between gap-4 py-3 first:pt-0 last:pb-0">
                  <div className="min-w-0"><p className="truncate text-sm font-medium text-slate-200">{page.page_name}</p><p className="mt-1 text-[11px] text-slate-500">{page.is_active ? 'Connected Page' : 'Inactive Page'} · AI {page.ai_enabled ? 'on' : 'off'}</p></div>
                  <div className="flex items-center gap-3">
                    <label className="inline-flex items-center gap-2 text-xs text-slate-300"><input type="checkbox" aria-label={`Enable AI for ${page.page_name}`} checked={Boolean(drafts[page.id])} onChange={(event) => setDrafts((current) => ({ ...current, [page.id]: event.target.checked }))} className="h-4 w-4 accent-cyan-300" /> Enable AI</label>
                    <Button onClick={() => savePage(page)} disabled={busyPage === String(page.id) || Boolean(drafts[page.id]) === Boolean(page.ai_enabled)} className="min-h-9 px-3 text-xs">{busyPage === String(page.id) ? 'Saving…' : 'Save'}</Button>
                  </div>
                </div>
              ))}
            </div> : <EmptyState title="No connected Pages" description="Connect a Page to configure Page-level AI moderation." action={<a href="/facebook/pages/connect" className="text-xs font-semibold text-cyan-200">Connect a Page →</a>} />}
          </Panel>

          <Panel className="mt-4 p-4 sm:p-5">
            <div><p className="text-[10px] font-semibold uppercase tracking-[0.18em] text-cyan-200">Read-only test</p><h2 className="mt-1 text-sm font-semibold text-white">Test an AI classification</h2><p className="mt-1 text-xs leading-5 text-slate-500">The tester submits sample text to the backend classifier. It does not create or change a comment, action log, or Facebook state.</p></div>
            <form onSubmit={runTest} className="mt-4 grid gap-4 lg:grid-cols-2">
              <label className="text-xs font-medium text-slate-300">Comment text<textarea required maxLength={20000} rows={5} value={comment} onChange={(event) => setComment(event.target.value)} className={`${textInput} resize-y`} placeholder="Enter a sample comment" /></label>
              <div className="space-y-3">
                <label className="block text-xs font-medium text-slate-300">Page context (optional)<select value={selectedPageId} onChange={(event) => setSelectedPageId(event.target.value)} className={textInput}><option value="">No Page context</option>{(config.pages || []).map((page) => <option key={page.id} value={page.id}>{page.page_name}</option>)}</select></label>
                <label className="block text-xs font-medium text-slate-300">Post context (optional)<textarea maxLength={5000} rows={2} value={postText} onChange={(event) => setPostText(event.target.value)} className={`${textInput} resize-y`} placeholder="Short post text" /></label>
                <Button type="submit" variant="primary" disabled={testing || !comment.trim()}>{testing ? 'Testing…' : 'Run AI test'}</Button>
              </div>
            </form>
            {testError && <div className="mt-4"><ErrorState message={testError} /></div>}
            {testResult && (
              <div role="status" className="mt-4 rounded-xl border border-cyan-300/15 bg-cyan-300/[0.04] p-4">
                <div className="flex flex-wrap items-center gap-2"><h3 className="text-sm font-semibold text-white">Classification result</h3><Badge tone={testResult.data?.action === 'review' ? 'warning' : 'info'}>{testResult.data?.action || 'unknown'}</Badge><Badge>{testResult.data?.category || 'unknown'}</Badge><Badge>{testResult.meta?.status || 'completed'}</Badge></div>
                <p className="mt-2 text-sm leading-6 text-slate-300">{testResult.data?.reason || 'No explanation was returned.'}</p>
                <div className="mt-3 grid gap-2 text-xs text-slate-500 sm:grid-cols-3"><p>Confidence <span className="text-slate-200">{testResult.data?.confidence == null ? '—' : `${Math.round(Number(testResult.data.confidence) * 100)}%`}</span></p><p>Severity <span className="capitalize text-slate-200">{testResult.data?.severity || '—'}</span></p><p>Processing <span className="text-slate-200">{formatDuration(testResult.meta?.processing_time_ms)}</span></p></div>
                {testResult.meta?.error_category && <p className="mt-2 text-xs text-amber-200">Provider result: {String(testResult.meta.error_category).replaceAll('_', ' ')}</p>}
              </div>
            )}
          </Panel>
        </>
      ) : null}
    </AppShell>
  );
}
