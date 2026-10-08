import { useCallback, useEffect, useState } from 'react';
import { getDashboardOverview } from '../api/dashboardService.js';
import { disconnectFacebookPage, listFacebookPages, syncFacebookPage } from '../api/facebookPageService.js';
import { AppShell } from '../components/AppShell.jsx';
import { Badge, Button, ConfirmDialog, EmptyState, ErrorState, LoadingState, PageHeader, Panel, StatCard } from '../components/ui.jsx';
import { friendlyError } from '../utils/errors.js';
import { emitToast } from '../components/ui.jsx';

function formatDate(value) {
  if (!value) return 'Never';
  const date = new Date(value);
  return Number.isNaN(date.getTime()) ? 'Unavailable' : date.toLocaleString();
}

export function PageDetailPage({ pageId }) {
  const [page, setPage] = useState(null);
  const [overview, setOverview] = useState(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [refreshKey, setRefreshKey] = useState(0);
  const [syncing, setSyncing] = useState(false);
  const [confirmDisconnect, setConfirmDisconnect] = useState(false);
  const [disconnecting, setDisconnecting] = useState(false);

  const loadDetails = useCallback(async (signal) => {
    setLoading(true);
    try {
      const [pageResult, dashboardResult] = await Promise.all([
        listFacebookPages({ signal }),
        getDashboardOverview({ page_id: pageId }, { signal }),
      ]);
      if (signal?.aborted) return;
      const pageItems = Array.isArray(pageResult?.data) ? pageResult.data : [];
      const selected = pageItems.find((item) => String(item.id) === String(pageId)) || null;
      if (!selected) throw new Error('This Page could not be found in your connected Pages.');
      setPage(selected);
      setOverview(dashboardResult?.data || null);
      setError('');
    } catch (requestError) {
      if (signal?.aborted) return;
      setError(friendlyError(requestError, 'Could not load Page details.'));
      setPage(null);
      setOverview(null);
    } finally {
      if (!signal?.aborted) setLoading(false);
    }
  }, [pageId]);

  useEffect(() => {
    const controller = new AbortController();
    loadDetails(controller.signal);
    return () => controller.abort();
  }, [loadDetails, refreshKey]);

  async function runSync() {
    if (!page || syncing || !page.is_active) return;
    setSyncing(true);
    try {
      const result = await syncFacebookPage(page.id);
      emitToast(result?.message || 'Page sync queued.');
      setPage((current) => current && ({ ...current, sync_status: result?.data?.sync_status || 'queued' }));
    } catch (requestError) {
      emitToast(friendlyError(requestError, 'Could not start the Page sync.'), 'error');
    } finally {
      setSyncing(false);
    }
  }

  async function disconnect() {
    if (!page || disconnecting) return;
    setDisconnecting(true);
    try {
      const result = await disconnectFacebookPage(page.id);
      setPage((current) => current && ({ ...current, ...result?.data, is_active: false, token_status: 'disconnected' }));
      setConfirmDisconnect(false);
      emitToast(result?.message || 'Page disconnected. Moderation history was retained.');
    } catch (requestError) {
      emitToast(friendlyError(requestError, 'Could not disconnect this Page.'), 'error');
    } finally {
      setDisconnecting(false);
    }
  }

  const stats = overview?.stats || {};

  return (
    <AppShell activePage="pages">
      <PageHeader eyebrow="Page overview" title={page?.page_name || 'Page details'} description="Page-scoped moderation and synchronization overview. The Page Access Token is never returned or rendered." actions={<><a href="/facebook/pages" className="inline-flex min-h-10 items-center rounded-xl border border-white/10 px-4 text-sm font-semibold text-slate-200 hover:bg-white/5">← Pages</a>{page?.is_active && <Button onClick={runSync} disabled={syncing}>{syncing ? 'Queueing…' : '↻ Sync now'}</Button>}{page?.is_active && <Button variant="danger" onClick={() => setConfirmDisconnect(true)}>Disconnect</Button>}<Button onClick={() => setRefreshKey((key) => key + 1)} disabled={loading}>Refresh</Button></>} />
      {error && <div className="mb-5"><ErrorState message={error} onRetry={() => setRefreshKey((key) => key + 1)} /></div>}
      {loading ? <LoadingState label="Loading Page dashboard" rows={4} /> : page && overview ? (
        <>
          <Panel className="mb-4 flex flex-col gap-4 p-4 sm:flex-row sm:items-center sm:justify-between sm:p-5">
            <div className="flex min-w-0 items-center gap-3">{page.page_picture_url ? <img src={page.page_picture_url} alt="" referrerPolicy="no-referrer" className="h-12 w-12 rounded-xl object-cover" /> : <span aria-hidden="true" className="grid h-12 w-12 place-items-center rounded-xl bg-white/5 text-cyan-200">▣</span>}<div className="min-w-0"><div className="flex flex-wrap items-center gap-2"><h2 className="truncate font-semibold text-white">{page.page_name}</h2><Badge tone={page.is_active ? 'success' : 'neutral'}>{page.is_active ? 'Active' : 'Inactive'}</Badge></div><p className="mt-1 text-xs text-slate-500">Graph ID <span className="font-mono text-slate-300">{page.facebook_page_id}</span>{page.page_category ? ` · ${page.page_category}` : ''}</p></div></div>
            <div className="flex flex-wrap gap-2"><Badge tone={page.token_status === 'connected' ? 'success' : 'warning'}>Page token: {page.token_status === 'connected' ? 'connected' : page.token_status === 'disconnected' ? 'disconnected' : 'expired / invalid'}</Badge><Badge tone={page.webhook_status === 'received' ? 'success' : 'warning'}>Webhook: {page.webhook_status === 'received' ? 'events received' : 'waiting'}</Badge><Badge>Sync: {page.sync_status || 'idle'}</Badge></div>
          </Panel>

          <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-5"><StatCard label="Comments stored" value={Number(page.comments_count || 0)} icon="☷" hint="Available for search and review" /><StatCard label="Comments today" value={Number(stats.comments_today || 0)} icon="↗" /><StatCard label="Review required" value={Number(stats.review_required || 0)} icon="◷" tone="amber" /><StatCard label="Hidden" value={Number(stats.hidden_comments || 0)} icon="◌" tone="amber" /><StatCard label="Failed actions" value={Number(stats.failed_actions || 0)} icon="!" tone="rose" /></div>

          <div className="mt-4 grid gap-4 xl:grid-cols-[1.3fr_0.7fr]">
            <Panel className="overflow-hidden">
              <div className="flex items-center justify-between border-b border-white/[0.07] px-4 py-4"><div><h2 className="text-sm font-semibold text-white">Recent decisions</h2><p className="mt-1 text-[11px] text-slate-500">Latest completed moderation outcomes for this Page</p></div><a href={`/facebook/comments?page_id=${page.id}`} className="text-xs font-semibold text-cyan-200">All comments →</a></div>
              {(overview.recent_activity || []).length === 0 ? <EmptyState title="No decisions recorded" description="Sync comments and moderation activity will appear here." /> : <div className="divide-y divide-white/[0.05]">{overview.recent_activity.map((item) => <a key={item.id} href={`/facebook/comments/${item.id}`} className="block p-4 hover:bg-white/[0.02]"><div className="flex flex-wrap items-center justify-between gap-2"><span className="text-xs font-semibold text-slate-200">{item.decision || 'pending'} · {item.category || 'other'}</span><time className="text-[10px] text-slate-500">{formatDate(item.created_at)}</time></div><p className="mt-2 line-clamp-2 text-xs leading-5 text-slate-400">{item.message || 'Comment text unavailable'}</p><p className="mt-1 text-[10px] text-slate-600">{item.method || 'unknown method'}{item.confidence == null ? '' : ` · ${Math.round(Number(item.confidence) * 100)}% confidence`}</p></a>)}</div>}
            </Panel>
            <div className="space-y-4">
              <Panel className="p-4 sm:p-5"><h2 className="text-sm font-semibold text-white">Page status</h2><div className="mt-4 space-y-3 text-xs"><p className="text-slate-500">Last sync <span className="mt-1 block text-slate-200">{formatDate(page.last_synced_at)}</span></p><p className="text-slate-500">Webhook last received <span className="mt-1 block text-slate-200">{formatDate(page.webhook_last_received_at)}</span></p><p className="text-slate-500">Posts synchronized <span className="mt-1 block text-slate-200">{Number(page.posts_synced_count || 0).toLocaleString()}</span></p><p className="text-slate-500">Comments synchronized <span className="mt-1 block text-slate-200">{Number(page.comments_synced_count || 0).toLocaleString()}</span></p></div>{page.sync_error && page.sync_status === 'failed' && <p className="mt-4 rounded-lg border border-rose-300/10 bg-rose-300/[0.04] p-3 text-xs text-rose-100">Sync issue: {page.sync_error}</p>}</Panel>
              <Panel className="p-4 sm:p-5"><h2 className="text-sm font-semibold text-white">Page controls</h2><div className="mt-3 grid gap-2"><a href={`/settings/moderation?page_id=${page.id}`} className="rounded-xl border border-white/[0.08] px-3 py-2.5 text-xs font-semibold text-slate-200 hover:bg-white/[0.04]">Moderation Settings →</a><a href="/moderation/rules" className="rounded-xl border border-white/[0.08] px-3 py-2.5 text-xs font-semibold text-slate-200 hover:bg-white/[0.04]">Moderation Rules →</a><a href="/facebook/webhook" className="rounded-xl border border-white/[0.08] px-3 py-2.5 text-xs font-semibold text-slate-200 hover:bg-white/[0.04]">Webhook Status →</a></div><p className="mt-3 text-[10px] leading-5 text-slate-600">Disconnect clears the encrypted credential and retains existing moderation history.</p></Panel>
            </div>
          </div>
        </>
      ) : null}
      {confirmDisconnect && <ConfirmDialog title={`Disconnect ${page?.page_name}?`} message="The saved Page Access Token will be cleared and future sync/actions disabled. Existing moderation data and audit history remain available." confirmLabel="Disconnect Page" danger busy={disconnecting} onConfirm={disconnect} onCancel={() => { if (!disconnecting) setConfirmDisconnect(false); }} />}
    </AppShell>
  );
}
