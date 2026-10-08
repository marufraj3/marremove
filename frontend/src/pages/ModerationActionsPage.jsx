import { useCallback, useEffect, useRef, useState } from 'react';
import { listFacebookPages } from '../api/facebookPageService.js';
import { listModerationActions, queueManualFacebookAction } from '../api/moderationActionService.js';
import { AppShell } from '../components/AppShell.jsx';
import { Badge, Button, ConfirmDialog, EmptyState, ErrorState, LoadingState, PageHeader, Pagination, Panel, emitToast } from '../components/ui.jsx';
import { friendlyError } from '../utils/errors.js';

const PAGE_SIZE = 20;
const statuses = ['pending', 'completed', 'failed', 'hidden', 'deleted'];
const actions = ['hide', 'unhide', 'delete'];

function formatDate(value) {
  if (!value) return '—';
  const date = new Date(value);
  return Number.isNaN(date.getTime()) ? '—' : date.toLocaleString();
}

function statusTone(value) {
  if (value === 'completed' || value === 'hidden' || value === 'deleted') return 'success';
  if (value === 'failed') return 'danger';
  if (value === 'pending' || value === 'processing') return 'warning';
  return 'neutral';
}

function DetailDialog({ item, onClose, onRequestAction, manualNotice, manualBusy }) {
  return (
    <div className="fixed inset-0 z-[70] grid place-items-center bg-slate-950/75 p-4 backdrop-blur-sm" onMouseDown={(event) => { if (event.target === event.currentTarget) onClose(); }}>
      <section role="dialog" aria-modal="true" aria-labelledby="action-detail-title" className="max-h-[90vh] w-full max-w-2xl overflow-y-auto rounded-2xl border border-white/10 bg-[#101827] p-5 shadow-2xl sm:p-6">
        <div className="flex items-start justify-between gap-4"><div><p className="text-[10px] font-semibold uppercase tracking-[0.18em] text-cyan-200">Action log detail</p><h2 id="action-detail-title" className="mt-1 text-lg font-semibold text-white">{item.action?.toUpperCase()} · #{item.id}</h2></div><button type="button" aria-label="Close action detail" onClick={onClose} className="rounded-lg px-2 py-1 text-xl text-slate-400 hover:text-white">×</button></div>
        <div className="mt-4 flex flex-wrap gap-2"><Badge tone={statusTone(item.status)}>{item.status}</Badge>{item.is_manual && <Badge tone="info">Admin requested</Badge>}{item.is_test && <Badge tone="warning">Test mode · simulated</Badge>}</div>
        <div className="mt-5 grid gap-3 sm:grid-cols-2">
          {[
            ['Page', item.comment?.page_name || 'Facebook Page'],
            ['Comment ID', item.facebook_comment_id || '—'],
            ['Created', formatDate(item.created_at)],
            ['Processed', formatDate(item.processed_at)],
            ['Attempt count', item.attempt_count ?? 0],
            ['Processing time', item.processing_time_ms == null ? '—' : `${item.processing_time_ms} ms`],
            ['Response code', item.response_code ?? '—'],
            ['Meta error code', item.meta_error_code ?? '—'],
            ['Facebook state', item.comment?.facebook_action_state || 'unknown'],
            ['Final decision', item.comment?.final_decision || 'not set'],
          ].map(([label, value]) => <div key={label} className="rounded-xl border border-white/[0.07] bg-white/[0.02] p-3"><p className="text-[10px] uppercase tracking-wide text-slate-500">{label}</p><p className="mt-1 break-words text-xs font-medium text-slate-200">{value}</p></div>)}
        </div>
        <div className="mt-4 rounded-xl border border-white/[0.07] p-3"><p className="text-[10px] uppercase tracking-wide text-slate-500">Comment</p><p className="mt-2 whitespace-pre-wrap break-words text-sm leading-6 text-slate-200">{item.comment?.message || 'Comment text unavailable'}</p><p className="mt-2 text-xs text-slate-500">Category: {item.comment?.final_category || '—'}{item.comment?.author_name ? ` · Author: ${item.comment.author_name}` : ''}</p>{item.comment?.id && <a href={`/facebook/comments/${item.comment.id}`} className="mt-3 inline-flex text-xs font-semibold text-cyan-200 hover:text-white">Open moderation timeline →</a>}</div>
        {item.response_message && <div className="mt-3 rounded-xl border border-white/[0.07] p-3"><p className="text-[10px] uppercase tracking-wide text-slate-500">Service response</p><p className="mt-1 text-xs leading-5 text-slate-300">{item.response_message}</p></div>}
        {item.last_error && <div className="mt-3 rounded-xl border border-rose-300/15 bg-rose-300/[0.04] p-3"><p className="text-[10px] uppercase tracking-wide text-rose-200">Last error</p><p className="mt-1 text-xs leading-5 text-rose-100">{item.last_error}</p></div>}
        <div className="mt-4 rounded-xl border border-white/[0.07] bg-white/[0.02] p-3">
          <div className="flex flex-wrap items-center justify-between gap-3"><div><p className="text-xs font-semibold text-slate-200">Queue a manual Page action</p><p className="mt-1 text-[10px] leading-4 text-slate-500">Uses the existing Laravel action service; each choice requires confirmation.</p></div><div className="flex flex-wrap gap-2">{['hide', 'unhide', 'delete'].map((action) => <Button key={action} variant={action === 'delete' ? 'danger' : 'secondary'} onClick={() => onRequestAction(item, action)} disabled={!item.comment?.id || manualBusy} className="min-h-9 px-3 text-[10px] uppercase">{action}</Button>)}</div></div>
          {manualNotice && <p role="status" className="mt-3 text-xs text-emerald-200">{manualNotice}</p>}
        </div>
        <p className="mt-4 text-[10px] leading-5 text-slate-600">Action execution is owned by Laravel’s queue. This detail view does not call Facebook.</p>
      </section>
    </div>
  );
}

export function ModerationActionsPage() {
  const [pages, setPages] = useState([]);
  const [filters, setFilters] = useState({ search: '', page_id: '', status: '', action: '' });
  const [items, setItems] = useState([]);
  const [pagination, setPagination] = useState({ current_page: 1, last_page: 1, total: 0 });
  const [page, setPage] = useState(1);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [refreshKey, setRefreshKey] = useState(0);
  const [selected, setSelected] = useState(null);
  const [pendingManualAction, setPendingManualAction] = useState(null);
  const [manualBusy, setManualBusy] = useState(false);
  const [manualNotice, setManualNotice] = useState('');
  const requestId = useRef(0);

  useEffect(() => {
    let cancelled = false;
    listFacebookPages().then((result) => { if (!cancelled) setPages(Array.isArray(result?.data) ? result.data : []); }).catch(() => { /* Action history is still scoped by Laravel even when the filter list fails. */ });
    return () => { cancelled = true; };
  }, []);

  const loadActions = useCallback(async () => {
    const activeRequest = ++requestId.current;
    setLoading(true);
    setError('');
    try {
      const result = await listModerationActions({ ...filters, page, per_page: PAGE_SIZE });
      if (activeRequest !== requestId.current) return;
      setItems(Array.isArray(result?.data) ? result.data : []);
      setPagination(result?.meta || { current_page: 1, last_page: 1, total: 0 });
    } catch (requestError) {
      if (activeRequest !== requestId.current) return;
      setError(friendlyError(requestError, 'Could not load action logs.'));
      setItems([]);
    } finally {
      if (activeRequest === requestId.current) setLoading(false);
    }
  }, [filters, page]);

  useEffect(() => {
    const timer = window.setTimeout(loadActions, filters.search.trim() ? 250 : 0);
    return () => {
      window.clearTimeout(timer);
      requestId.current += 1;
    };
  }, [filters.search, loadActions, refreshKey]);

  function updateFilter(key, value) {
    setFilters((current) => ({ ...current, [key]: value }));
    setPage(1);
  }

  async function confirmManualAction() {
    if (!pendingManualAction || manualBusy) return;
    setManualBusy(true);
    try {
      const result = await queueManualFacebookAction(pendingManualAction.item.comment.id, pendingManualAction.action);
      const status = result?.data?.status || 'pending';
      const notice = `${pendingManualAction.action.toUpperCase()} queued through Laravel (${status}).`;
      setManualNotice(notice);
      setPendingManualAction(null);
      emitToast(notice);
      await loadActions();
    } catch (requestError) {
      emitToast(friendlyError(requestError, 'Could not queue the manual Page action.'), 'error');
    } finally {
      setManualBusy(false);
    }
  }

  const inputClass = 'mt-1.5 min-h-10 w-full rounded-xl border border-white/10 bg-[#0b1120] px-3 text-xs text-slate-200 outline-none focus:border-cyan-300/50';

  return (
    <AppShell activePage="actions">
      <PageHeader eyebrow="Audit trail" title="Action Logs" description="Paginated history of Facebook moderation actions processed or simulated by the Laravel queue. Select an entry for response, retry, and comment details." actions={<Button onClick={() => setRefreshKey((key) => key + 1)} disabled={loading}>↻ Refresh</Button>} />
      {error && <div className="mb-4"><ErrorState message={error} onRetry={() => setRefreshKey((key) => key + 1)} /></div>}
      <Panel className="mb-4 p-3.5 sm:p-4">
        <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
          <label className="text-[10px] font-semibold uppercase tracking-wide text-slate-500">Search<input aria-label="Search action logs" value={filters.search} onChange={(event) => updateFilter('search', event.target.value)} placeholder="Comment text or ID" className={inputClass} /></label>
          <label className="text-[10px] font-semibold uppercase tracking-wide text-slate-500">Page<select aria-label="Filter action logs by Page" value={filters.page_id} onChange={(event) => updateFilter('page_id', event.target.value)} className={inputClass}><option value="">All Pages</option>{pages.map((pageItem) => <option key={pageItem.id} value={pageItem.id}>{pageItem.page_name}</option>)}</select></label>
          <label className="text-[10px] font-semibold uppercase tracking-wide text-slate-500">Status<select aria-label="Filter action logs by status" value={filters.status} onChange={(event) => updateFilter('status', event.target.value)} className={inputClass}><option value="">All statuses</option>{statuses.map((value) => <option key={value} value={value}>{value}</option>)}</select></label>
          <label className="text-[10px] font-semibold uppercase tracking-wide text-slate-500">Action<select aria-label="Filter action logs by action" value={filters.action} onChange={(event) => updateFilter('action', event.target.value)} className={inputClass}><option value="">All actions</option>{actions.map((value) => <option key={value} value={value}>{value.toUpperCase()}</option>)}</select></label>
        </div>
      </Panel>

      <Panel className="overflow-hidden">
        <div className="flex items-center justify-between gap-3 border-b border-white/[0.07] px-4 py-3.5"><div><h2 className="text-sm font-semibold text-white">Facebook action history</h2><p className="mt-1 text-[10px] text-slate-500">{PAGE_SIZE} records per page · scoped to Pages you own</p></div><Badge>{Number(pagination.total || 0).toLocaleString()} total</Badge></div>
        {loading ? <div className="p-4"><LoadingState label="Loading action logs" rows={4} /></div> : items.length === 0 ? <EmptyState title="No action logs found" description="Actions will appear when an administrator or moderation policy submits work to the Laravel action service." /> : (
          <>
            <div className="divide-y divide-white/[0.06]">
              {items.map((item) => <button key={item.id} type="button" onClick={() => { setSelected(item); setManualNotice(''); }} className="grid w-full gap-3 p-4 text-left transition hover:bg-white/[0.025] sm:grid-cols-[minmax(0,1fr)_140px_170px] sm:items-center sm:p-5">
                <span className="min-w-0"><span className="flex flex-wrap items-center gap-2"><span className="text-xs font-semibold uppercase text-slate-100">{item.action}</span><Badge tone={statusTone(item.status)}>{item.status}</Badge>{item.is_manual && <Badge tone="info">Admin</Badge>}{item.is_test && <Badge tone="warning">Simulated</Badge>}</span><span className="mt-2 block truncate text-xs text-slate-300">{item.comment?.message || 'Comment text unavailable'}</span><span className="mt-1 block truncate text-[10px] text-slate-500">{item.comment?.page_name || 'Facebook Page'} · Comment {item.facebook_comment_id}</span></span>
                <span className="text-[11px] text-slate-400">{item.attempt_count ?? 0} attempt(s){item.processing_time_ms == null ? '' : ` · ${item.processing_time_ms} ms`}</span>
                <span className="flex items-center justify-between gap-3 text-[10px] text-slate-500 sm:justify-end">{formatDate(item.created_at)}<span className="font-semibold text-cyan-200">Details →</span></span>
              </button>)}
            </div>
            <Pagination page={pagination.current_page || page} lastPage={pagination.last_page || 1} total={pagination.total || 0} onChange={setPage} label="action logs" />
          </>
        )}
      </Panel>
      {selected && <DetailDialog item={selected} onClose={() => setSelected(null)} onRequestAction={(item, action) => setPendingManualAction({ item, action })} manualNotice={manualNotice} manualBusy={manualBusy} />}
      {pendingManualAction && <ConfirmDialog title={`Confirm manual ${pendingManualAction.action.toUpperCase()} action`} message={pendingManualAction.action === 'delete' ? 'This permanently deletes the Facebook Page comment if the Laravel action service can reach Meta. This cannot be undone. Test mode and Page ownership checks remain enforced.' : `This queues a manual ${pendingManualAction.action} request for the Page comment through Laravel. Confirm only after reviewing the action details.`} confirmLabel={`Queue ${pendingManualAction.action}`} danger={pendingManualAction.action === 'delete'} busy={manualBusy} onConfirm={confirmManualAction} onCancel={() => { if (!manualBusy) setPendingManualAction(null); }} />}
    </AppShell>
  );
}
