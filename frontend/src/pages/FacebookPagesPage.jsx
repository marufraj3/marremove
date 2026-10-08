import { useEffect, useState } from 'react';
import { disconnectFacebookPage, listFacebookPages, syncFacebookPage } from '../api/facebookPageService.js';
import { AppShell } from '../components/AppShell.jsx';
import { Badge, Button, ConfirmDialog, EmptyState, ErrorState, LoadingState, PageHeader, Panel } from '../components/ui.jsx';
import { friendlyError } from '../utils/errors.js';
import { emitToast } from '../components/ui.jsx';

const ACTIVE_SYNC_STATES = new Set(['queued', 'running']);

function formatDate(value) {
  if (!value) return 'Never';
  const date = new Date(value);
  return Number.isNaN(date.getTime()) ? 'Unavailable' : date.toLocaleString();
}

function pageSyncTone(status) {
  if (status === 'completed') return 'success';
  if (status === 'failed') return 'danger';
  if (ACTIVE_SYNC_STATES.has(status)) return 'warning';
  return 'neutral';
}

function tokenLabel(page) {
  if (page.token_status === 'connected') return ['Connected', 'success'];
  if (page.token_status === 'disconnected') return ['Disconnected', 'neutral'];
  return ['Expired / invalid', 'danger'];
}

export function FacebookPagesPage() {
  const [pages, setPages] = useState([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [refreshKey, setRefreshKey] = useState(0);
  const [busy, setBusy] = useState({});
  const [confirmPage, setConfirmPage] = useState(null);
  const [disconnectBusy, setDisconnectBusy] = useState(false);

  useEffect(() => {
    let cancelled = false;
    setLoading(true);
    listFacebookPages()
      .then((result) => {
        if (cancelled) return;
        setPages(Array.isArray(result?.data) ? result.data : []);
        setError('');
      })
      .catch((requestError) => {
        if (!cancelled) setError(friendlyError(requestError, 'Could not load connected Facebook Pages.'));
      })
      .finally(() => { if (!cancelled) setLoading(false); });

    return () => { cancelled = true; };
  }, [refreshKey]);

  useEffect(() => {
    if (!pages.some((page) => ACTIVE_SYNC_STATES.has(page.sync_status))) return undefined;
    const timer = window.setInterval(() => setRefreshKey((key) => key + 1), 4000);
    return () => window.clearInterval(timer);
  }, [pages]);

  async function runSync(page) {
    if (busy[page.id] || !page.is_active) return;
    setBusy((current) => ({ ...current, [page.id]: 'sync' }));
    try {
      const result = await syncFacebookPage(page.id);
      emitToast(result?.message || `Sync queued for ${page.page_name}.`);
      setPages((current) => current.map((item) => String(item.id) === String(page.id) ? { ...item, sync_status: result?.data?.sync_status || 'queued' } : item));
    } catch (requestError) {
      emitToast(friendlyError(requestError, 'Could not start the Page sync.'), 'error');
    } finally {
      setBusy((current) => ({ ...current, [page.id]: '' }));
    }
  }

  async function runDisconnect() {
    if (!confirmPage || disconnectBusy) return;
    setDisconnectBusy(true);
    try {
      const result = await disconnectFacebookPage(confirmPage.id);
      setPages((current) => current.map((item) => String(item.id) === String(confirmPage.id) ? { ...item, ...result?.data, is_active: false, token_status: 'disconnected', sync_status: 'idle' } : item));
      emitToast(result?.message || 'Page disconnected. Moderation history was retained.');
      setConfirmPage(null);
    } catch (requestError) {
      emitToast(friendlyError(requestError, 'Could not disconnect this Page.'), 'error');
    } finally {
      setDisconnectBusy(false);
    }
  }

  return (
    <AppShell activePage="pages">
      <PageHeader
        eyebrow="Connected assets"
        title="Facebook Pages"
        description="Connect, monitor, synchronize, and manage your Pages. Page Access Tokens remain encrypted on the server and are never displayed here."
        actions={<><Button onClick={() => setRefreshKey((key) => key + 1)} disabled={loading}>↻ Refresh</Button><a href="/facebook/pages/connect" className="inline-flex min-h-10 items-center rounded-xl border border-cyan-300/20 bg-cyan-300 px-4 text-sm font-semibold text-slate-950 hover:bg-cyan-200">Connect Page +</a></>}
      />

      {error && <div className="mb-5"><ErrorState message={error} onRetry={() => setRefreshKey((key) => key + 1)} /></div>}

      {loading ? <LoadingState label="Loading Facebook Pages" rows={3} /> : pages.length === 0 ? (
        <Panel><EmptyState title="No Facebook Pages connected" description="Connect a Page using the existing secure Laravel connection flow. Credentials are submitted to the server and never stored in browser storage." action={<a href="/facebook/pages/connect" className="inline-flex min-h-10 items-center rounded-xl bg-cyan-300 px-4 text-sm font-semibold text-slate-950 hover:bg-cyan-200">Connect a Facebook Page</a>} /></Panel>
      ) : (
        <div className="grid gap-4 xl:grid-cols-2">
          {pages.map((page) => {
            const [tokenText, tokenTone] = tokenLabel(page);
            const isSyncActive = ACTIVE_SYNC_STATES.has(page.sync_status);
            return (
              <Panel key={page.id} className="overflow-hidden">
                <div className="p-4 sm:p-5">
                  <div className="flex items-start gap-3">
                    {page.page_picture_url ? <img src={page.page_picture_url} alt="" referrerPolicy="no-referrer" className="h-12 w-12 shrink-0 rounded-xl border border-white/10 bg-white/5 object-cover" /> : <div aria-hidden="true" className="grid h-12 w-12 shrink-0 place-items-center rounded-xl border border-white/10 bg-white/[0.04] text-lg text-cyan-200">▣</div>}
                    <div className="min-w-0 flex-1">
                      <div className="flex flex-wrap items-center gap-2"><h2 className="truncate font-semibold text-white">{page.page_name}</h2><Badge tone={page.is_active ? 'success' : 'neutral'}>{page.is_active ? 'Active' : 'Inactive'}</Badge></div>
                      <p className="mt-1 truncate text-xs text-slate-500">{[page.page_username, page.page_category].filter(Boolean).join(' · ') || 'Facebook Page'} · Graph ID <span className="font-mono">{page.facebook_page_id}</span></p>
                    </div>
                  </div>

                  <div className="mt-4 grid grid-cols-2 gap-2 sm:grid-cols-4">
                    <div className="rounded-xl border border-white/[0.06] bg-white/[0.02] px-3 py-2.5"><p className="text-[10px] uppercase tracking-wide text-slate-500">Page token</p><div className="mt-1"><Badge tone={tokenTone}>{tokenText}</Badge></div></div>
                    <div className="rounded-xl border border-white/[0.06] bg-white/[0.02] px-3 py-2.5"><p className="text-[10px] uppercase tracking-wide text-slate-500">Sync</p><div className="mt-1"><Badge tone={pageSyncTone(page.sync_status)}>{page.sync_status || 'idle'}</Badge></div></div>
                    <div className="rounded-xl border border-white/[0.06] bg-white/[0.02] px-3 py-2.5"><p className="text-[10px] uppercase tracking-wide text-slate-500">Comments</p><p className="mt-1 text-sm font-semibold tabular-nums text-slate-200">{Number(page.comments_count || 0).toLocaleString()}</p></div>
                    <div className="rounded-xl border border-white/[0.06] bg-white/[0.02] px-3 py-2.5"><p className="text-[10px] uppercase tracking-wide text-slate-500">Webhook</p><div className="mt-1"><Badge tone={page.webhook_status === 'received' ? 'success' : 'warning'}>{page.webhook_status === 'received' ? 'Events received' : 'Waiting'}</Badge></div></div>
                  </div>

                  <div className="mt-4 grid gap-1.5 text-[11px] text-slate-500 sm:grid-cols-2">
                    <p>Last sync <span className="text-slate-300">{formatDate(page.last_synced_at)}</span></p>
                    <p>Last webhook <span className="text-slate-300">{formatDate(page.webhook_last_received_at)}</span></p>
                    {page.sync_error && page.sync_status === 'failed' && <p className="sm:col-span-2 text-rose-200">Sync issue: {page.sync_error}</p>}
                  </div>
                </div>

                <div className="flex flex-wrap gap-2 border-t border-white/[0.07] bg-white/[0.012] px-4 py-3 sm:px-5">
                  <a href={`/facebook/pages/${page.id}`} className="inline-flex min-h-9 items-center rounded-lg border border-white/10 px-3 text-xs font-semibold text-slate-200 hover:bg-white/5">Details</a>
                  <a href={`/facebook/comments?page_id=${page.id}`} className="inline-flex min-h-9 items-center rounded-lg border border-white/10 px-3 text-xs font-semibold text-slate-200 hover:bg-white/5">Comments</a>
                  <a href={`/settings/moderation?page_id=${page.id}`} className="inline-flex min-h-9 items-center rounded-lg border border-white/10 px-3 text-xs font-semibold text-slate-200 hover:bg-white/5">Settings</a>
                  <Button onClick={() => runSync(page)} disabled={!page.is_active || busy[page.id] === 'sync' || isSyncActive} className="min-h-9 px-3 text-xs">{busy[page.id] === 'sync' ? 'Queueing…' : isSyncActive ? 'Syncing…' : 'Sync now'}</Button>
                  <Button variant="danger" onClick={() => setConfirmPage(page)} disabled={!page.is_active} className="min-h-9 px-3 text-xs">Disconnect</Button>
                </div>
              </Panel>
            );
          })}
        </div>
      )}

      {confirmPage && <ConfirmDialog title={`Disconnect ${confirmPage.page_name}?`} message="This clears the saved Page credential and disables future sync and moderation actions. Existing comments, decisions, and audit history will be kept. You can reconnect this Page later." confirmLabel="Disconnect Page" danger busy={disconnectBusy} onConfirm={runDisconnect} onCancel={() => { if (!disconnectBusy) setConfirmPage(null); }} />}
    </AppShell>
  );
}
