import { useCallback, useEffect, useRef, useState } from 'react';
import { listFacebookComments, listFacebookPages } from '../api/facebookPageService.js';
import { overrideCommentModeration } from '../api/aiModerationService.js';
import { AppShell } from '../components/AppShell.jsx';
import { Badge, Button, ConfirmDialog, EmptyState, ErrorState, LoadingState, PageHeader, Pagination, Panel } from '../components/ui.jsx';
import { friendlyError } from '../utils/errors.js';
import { emitToast } from '../components/ui.jsx';

const PAGE_SIZE = 20;

function formatDate(value) {
  if (!value) return '—';
  const date = new Date(value);
  return Number.isNaN(date.getTime()) ? '—' : date.toLocaleString();
}

function actionText(action) {
  if (action === 'keep') return 'Keep this comment visible';
  if (action === 'hide') return 'Recommend hiding this comment';
  if (action === 'delete') return 'Recommend deleting this comment';
  return 'Keep the comment in the review queue';
}

export function ReviewQueuePage() {
  const [pages, setPages] = useState([]);
  const [selectedPageId, setSelectedPageId] = useState('');
  const [pagesLoading, setPagesLoading] = useState(true);
  const [items, setItems] = useState([]);
  const [pagination, setPagination] = useState({ current_page: 1, last_page: 1, total: 0 });
  const [page, setPage] = useState(1);
  const [filters, setFilters] = useState({ search: '', category: '', min_confidence: '', max_confidence: '' });
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState('');
  const [refreshKey, setRefreshKey] = useState(0);
  const [pending, setPending] = useState(null);
  const [acting, setActing] = useState(false);
  const requestId = useRef(0);

  useEffect(() => {
    let cancelled = false;
    listFacebookPages().then((result) => {
      if (cancelled) return;
      const next = Array.isArray(result?.data) ? result.data : [];
      setPages(next);
      const requestedPage = new URLSearchParams(window.location.search).get('page_id');
      if (requestedPage && next.some((item) => String(item.id) === requestedPage)) setSelectedPageId(requestedPage);
    }).catch((requestError) => { if (!cancelled) setError(friendlyError(requestError, 'Could not load Pages.')); }).finally(() => { if (!cancelled) setPagesLoading(false); });
    return () => { cancelled = true; };
  }, []);

  const loadQueue = useCallback(async () => {
    const activeRequest = ++requestId.current;
    if (pagesLoading) return;
    if (!pages.length) {
      setItems([]);
      setPagination({ current_page: 1, last_page: 1, total: 0 });
      setLoading(false);
      return;
    }
    setLoading(true);
    setError('');
    try {
      const result = await listFacebookComments({
        page_id: selectedPageId,
        decision: 'review',
        search: filters.search.trim(),
        category: filters.category.trim(),
        min_confidence: filters.min_confidence,
        max_confidence: filters.max_confidence,
        page,
        per_page: PAGE_SIZE,
      });
      if (activeRequest !== requestId.current) return;
      setItems(Array.isArray(result?.data) ? result.data : []);
      setPagination(result?.meta || { current_page: 1, last_page: 1, total: 0 });
    } catch (requestError) {
      if (activeRequest !== requestId.current) return;
      setError(friendlyError(requestError, 'Could not load the review queue.'));
      setItems([]);
    } finally {
      if (activeRequest === requestId.current) setLoading(false);
    }
  }, [filters, page, pages.length, pagesLoading, selectedPageId]);

  useEffect(() => {
    const timer = window.setTimeout(() => loadQueue(), filters.search.trim() ? 250 : 0);
    return () => {
      window.clearTimeout(timer);
      requestId.current += 1;
    };
  }, [filters.search, loadQueue, refreshKey]);

  function changeFilter(key, value) {
    setFilters((current) => ({ ...current, [key]: value }));
    setPage(1);
  }

  async function confirmAction() {
    if (!pending || acting) return;
    setActing(true);
    try {
      await overrideCommentModeration(pending.comment.id, pending.action, `Review queue decision: ${pending.action}`);
      emitToast(`${pending.action.toUpperCase()} decision recorded. Laravel applied the Page action safeguards.`);
      setPending(null);
      await loadQueue();
    } catch (requestError) {
      emitToast(friendlyError(requestError, 'Could not record this review decision.'), 'error');
    } finally {
      setActing(false);
    }
  }

  const controlClass = 'mt-1.5 min-h-10 w-full rounded-xl border border-white/10 bg-[#0b1120] px-3 text-xs text-slate-200 outline-none focus:border-cyan-300/50';

  return (
    <AppShell activePage="review">
      <PageHeader eyebrow="Human review" title="Review Queue" description="Comments that need a person’s judgment. Choose a final decision only after reviewing the text and explanation; Laravel records the override and queues any eligible Facebook action." actions={<Button onClick={() => setRefreshKey((key) => key + 1)} disabled={loading}>↻ Refresh</Button>} />
      {error && <div className="mb-4"><ErrorState message={error} onRetry={() => setRefreshKey((key) => key + 1)} /></div>}

      <Panel className="mb-4 p-3.5 sm:p-4">
        <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-5">
          <label className="text-[10px] font-semibold uppercase tracking-wide text-slate-500">Search<input aria-label="Search review queue" value={filters.search} onChange={(event) => changeFilter('search', event.target.value)} className={controlClass} placeholder="Comment text" /></label>
          <label className="text-[10px] font-semibold uppercase tracking-wide text-slate-500">Page<select aria-label="Filter review queue by Page" value={selectedPageId} onChange={(event) => { setSelectedPageId(event.target.value); setPage(1); }} disabled={pagesLoading} className={controlClass}><option value="">All Pages</option>{pages.map((item) => <option key={item.id} value={item.id}>{item.page_name}</option>)}</select></label>
          <label className="text-[10px] font-semibold uppercase tracking-wide text-slate-500">Category<input aria-label="Filter review queue by category" value={filters.category} onChange={(event) => changeFilter('category', event.target.value)} className={controlClass} placeholder="Any category" /></label>
          <label className="text-[10px] font-semibold uppercase tracking-wide text-slate-500">Min confidence<input aria-label="Minimum review confidence" type="number" min="0" max="1" step="0.01" value={filters.min_confidence} onChange={(event) => changeFilter('min_confidence', event.target.value)} className={controlClass} placeholder="0–1" /></label>
          <label className="text-[10px] font-semibold uppercase tracking-wide text-slate-500">Max confidence<input aria-label="Maximum review confidence" type="number" min="0" max="1" step="0.01" value={filters.max_confidence} onChange={(event) => changeFilter('max_confidence', event.target.value)} className={controlClass} placeholder="0–1" /></label>
        </div>
      </Panel>

      <Panel className="overflow-hidden">
        <div className="flex items-center justify-between gap-3 border-b border-white/[0.07] px-4 py-3.5"><div><h2 className="text-sm font-semibold text-white">Comments requiring attention</h2><p className="mt-1 text-[10px] text-slate-500">Server-side queue · {PAGE_SIZE} records per page</p></div><Badge tone="warning">{Number(pagination.total || 0).toLocaleString()} waiting</Badge></div>
        {loading ? <div className="p-4"><LoadingState label="Loading review queue" rows={4} /></div> : items.length === 0 ? <EmptyState title="The review queue is clear" description="No comments match these filters. Check back after the next sync or adjust your search." action={<a href="/facebook/comments" className="text-xs font-semibold text-cyan-200">Browse comments →</a>} /> : (
          <>
            <div className="divide-y divide-white/[0.06]">
              {items.map((item) => {
                const final = item.final_moderation || {};
                const manual = item.manual_moderation || {};
                const ai = item.ai_moderation || {};
                return <article key={item.id} className="grid gap-4 p-4 lg:grid-cols-[minmax(0,1fr)_250px] lg:p-5">
                  <div className="min-w-0">
                    <div className="flex flex-wrap items-center gap-2"><Badge tone="warning">Human review</Badge><Badge>{item.page?.page_name || 'Facebook Page'}</Badge>{final.category && <Badge>{String(final.category).replaceAll('_', ' ')}</Badge>}</div>
                    <p className="mt-3 whitespace-pre-wrap break-words text-sm leading-6 text-slate-200">{item.message || 'Comment text unavailable'}</p>
                    <p className="mt-2 text-[11px] text-slate-500">{item.author?.name || 'Unknown author'} · {formatDate(item.created_at)}</p>
                    <div className="mt-3 rounded-xl border border-white/[0.06] bg-white/[0.015] p-3 text-xs leading-5 text-slate-400"><p><span className="font-semibold text-slate-300">Why review?</span> {final.reason || 'A human decision is required.'}</p><p className="mt-1">Manual rules: <span className="capitalize text-slate-300">{manual.status || 'not matched'}</span>{manual.action ? ` · ${manual.action}` : ''} · AI: <span className="capitalize text-slate-300">{ai.status || 'not evaluated'}</span>{ai.category ? ` · ${String(ai.category).replaceAll('_', ' ')}` : ''}{ai.confidence != null ? ` · ${Math.round(Number(ai.confidence) * 100)}%` : ''}</p></div>
                    <a href={`/facebook/comments/${item.id}`} className="mt-3 inline-flex text-xs font-semibold text-cyan-200 hover:text-white">Decision timeline and details →</a>
                  </div>
                  <div className="flex flex-col gap-2 rounded-xl border border-white/[0.07] bg-[#0b1120]/75 p-3">
                    <p className="text-[10px] font-semibold uppercase tracking-wide text-slate-500">Human decision</p>
                    <Button variant="primary" onClick={() => setPending({ comment: item, action: 'keep' })}>Keep comment</Button>
                    <Button onClick={() => setPending({ comment: item, action: 'hide' })}>Recommend hide</Button>
                    <Button variant="danger" onClick={() => setPending({ comment: item, action: 'delete' })}>Recommend delete</Button>
                    <p className="mt-1 text-[10px] leading-4 text-slate-600">Every choice opens a confirmation. Hide/delete are handled only by Laravel’s queued action service and settings.</p>
                  </div>
                </article>;
              })}
            </div>
            <Pagination page={pagination.current_page || page} lastPage={pagination.last_page || 1} total={pagination.total || 0} onChange={setPage} label="comments" />
          </>
        )}
      </Panel>

      {pending && <ConfirmDialog title={pending.action === 'delete' ? 'Confirm delete recommendation' : pending.action === 'hide' ? 'Confirm hide recommendation' : 'Confirm keep decision'} message={`${actionText(pending.action)} for this comment? This saves a final admin override; if Page settings allow, Laravel may enqueue the corresponding action. ${pending.action === 'delete' ? 'Deletion is irreversible.' : 'Test mode, manual KEEP precedence, and protected-category safeguards remain server-enforced.'}`} confirmLabel={pending.action === 'delete' ? 'Confirm delete' : pending.action === 'hide' ? 'Confirm hide' : 'Confirm keep'} danger={pending.action === 'delete'} busy={acting} onConfirm={confirmAction} onCancel={() => { if (!acting) setPending(null); }} />}
    </AppShell>
  );
}
