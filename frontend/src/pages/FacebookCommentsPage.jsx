import { useEffect, useState } from 'react';
import { listFacebookComments, listFacebookPages } from '../api/facebookPageService.js';
import { AppShell } from '../components/AppShell.jsx';
import { Badge, Button, EmptyState, ErrorState, LoadingState, PageHeader, Pagination, Panel } from '../components/ui.jsx';
import { friendlyError } from '../utils/errors.js';

const PAGE_SIZE = 25;
const decisions = ['keep', 'review', 'hide', 'delete', 'pending'];
const methods = ['manual', 'ai', 'fallback'];
const actionStatuses = ['pending', 'processing', 'completed', 'failed', 'skipped'];

function formatDate(value) {
  if (!value) return '—';
  const date = new Date(value);
  return Number.isNaN(date.getTime()) ? '—' : date.toLocaleString();
}

function decisionTone(action) {
  if (action === 'keep') return 'success';
  if (action === 'review' || action === 'pending') return 'warning';
  if (action === 'delete') return 'danger';
  if (action === 'hide') return 'info';
  return 'neutral';
}

function CommentSummary({ comment }) {
  const final = comment.final_moderation || {};
  const fb = comment.facebook_action || {};
  return (
    <>
      <div className="flex flex-wrap items-center gap-2"><Badge tone={decisionTone(final.action)}>{final.action || 'pending'}</Badge><Badge>{final.method || 'unclassified'}</Badge>{final.manual_override && <Badge tone="info">Override</Badge>}</div>
      <p className="mt-2 line-clamp-3 whitespace-pre-wrap break-words text-sm leading-6 text-slate-200">{comment.message || 'Comment text unavailable'}</p>
      <p className="mt-2 text-[11px] text-slate-500">{comment.author?.name || 'Unknown author'} · {comment.page?.page_name || 'Facebook Page'}</p>
      <div className="mt-3 flex flex-wrap gap-x-4 gap-y-1 text-[10px] text-slate-500"><span>Category <span className="capitalize text-slate-300">{String(final.category || '—').replaceAll('_', ' ')}</span></span><span>Confidence <span className="text-slate-300">{final.confidence == null ? '—' : `${Math.round(Number(final.confidence) * 100)}%`}</span></span><span>Action <span className="capitalize text-slate-300">{fb.status || 'not queued'}</span></span></div>
    </>
  );
}

export function FacebookCommentsPage() {
  const [pages, setPages] = useState([]);
  const [selectedPageId, setSelectedPageId] = useState('');
  const [pagesLoading, setPagesLoading] = useState(true);
  const [pagesError, setPagesError] = useState('');
  const [comments, setComments] = useState([]);
  const [pagination, setPagination] = useState({ current_page: 1, last_page: 1, total: 0 });
  const [page, setPage] = useState(1);
  const [filters, setFilters] = useState({ search: '', decision: '', category: '', method: '', action_status: '', from: '', to: '', min_confidence: '', max_confidence: '' });
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState('');
  const [refreshKey, setRefreshKey] = useState(0);

  useEffect(() => {
    let cancelled = false;
    listFacebookPages()
      .then((result) => {
        if (cancelled) return;
        const nextPages = Array.isArray(result?.data) ? result.data : [];
        setPages(nextPages);
        const queryPage = new URLSearchParams(window.location.search).get('page_id');
        if (queryPage && nextPages.some((item) => String(item.id) === queryPage)) setSelectedPageId(queryPage);
        setPagesError('');
      })
      .catch((requestError) => { if (!cancelled) setPagesError(friendlyError(requestError, 'Could not load Facebook Pages.')); })
      .finally(() => { if (!cancelled) setPagesLoading(false); });
    return () => { cancelled = true; };
  }, []);

  useEffect(() => {
    if (pagesLoading) return undefined;
    if (pages.length === 0) {
      setComments([]);
      setPagination({ current_page: 1, last_page: 1, total: 0 });
      setLoading(false);
      return undefined;
    }

    let cancelled = false;
    const timeout = window.setTimeout(async () => {
      setLoading(true);
      setError('');
      try {
        const result = await listFacebookComments({
          page_id: selectedPageId,
          page,
          per_page: PAGE_SIZE,
          search: filters.search.trim(),
          decision: filters.decision,
          category: filters.category.trim(),
          method: filters.method,
          action_status: filters.action_status,
          from: filters.from,
          to: filters.to,
          min_confidence: filters.min_confidence,
          max_confidence: filters.max_confidence,
        });
        if (cancelled) return;
        setComments(Array.isArray(result?.data) ? result.data : []);
        setPagination(result?.meta || { current_page: 1, last_page: 1, total: 0 });
      } catch (requestError) {
        if (cancelled) return;
        setError(friendlyError(requestError, 'Could not load comments.'));
        setComments([]);
      } finally {
        if (!cancelled) setLoading(false);
      }
    }, filters.search.trim() ? 300 : 0);
    return () => { cancelled = true; window.clearTimeout(timeout); };
  }, [filters, page, pages.length, pagesLoading, refreshKey, selectedPageId]);

  function updateFilter(key, value) {
    setFilters((current) => ({ ...current, [key]: value }));
    setPage(1);
  }

  function clearFilters() {
    setFilters({ search: '', decision: '', category: '', method: '', action_status: '', from: '', to: '', min_confidence: '', max_confidence: '' });
    setPage(1);
    setSelectedPageId('');
  }

  const selectClass = 'mt-1.5 min-h-10 w-full rounded-xl border border-white/10 bg-[#0b1120] px-3 text-xs text-slate-200 outline-none focus:border-cyan-300/50';
  const inputClass = 'mt-1.5 min-h-10 w-full rounded-xl border border-white/10 bg-[#0b1120] px-3 text-xs text-slate-200 outline-none placeholder:text-slate-600 focus:border-cyan-300/50';

  return (
    <AppShell activePage="comments">
      <PageHeader eyebrow="Comment moderation" title="Comments" description="Search and filter synchronized comments using server-side pagination. Open any comment to inspect the decision timeline or submit a confirmed admin override." actions={<><a href="/moderation/review" className="inline-flex min-h-10 items-center rounded-xl border border-white/10 px-4 text-sm font-semibold text-slate-200 hover:bg-white/5">Review Queue</a><Button onClick={() => setRefreshKey((key) => key + 1)} disabled={loading}>↻ Refresh</Button></>} />
      {pagesError && <div className="mb-4"><ErrorState message={pagesError} /></div>}
      {error && <div className="mb-4"><ErrorState message={error} onRetry={() => setRefreshKey((key) => key + 1)} /></div>}

      <Panel className="mb-4 p-3.5 sm:p-4">
        <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-5">
          <label className="text-[10px] font-semibold uppercase tracking-wide text-slate-500">Search comments<input aria-label="Search comments" value={filters.search} onChange={(event) => updateFilter('search', event.target.value)} className={inputClass} placeholder="Text or keyword" /></label>
          <label className="text-[10px] font-semibold uppercase tracking-wide text-slate-500">Facebook Page<select aria-label="Filter comments by Page" value={selectedPageId} onChange={(event) => { setSelectedPageId(event.target.value); setPage(1); }} className={selectClass}><option value="">All Pages</option>{pages.map((item) => <option key={item.id} value={item.id}>{item.page_name}</option>)}</select></label>
          <label className="text-[10px] font-semibold uppercase tracking-wide text-slate-500">Decision<select aria-label="Filter by decision" value={filters.decision} onChange={(event) => updateFilter('decision', event.target.value)} className={selectClass}><option value="">All decisions</option>{decisions.map((item) => <option key={item} value={item}>{item.toUpperCase()}</option>)}</select></label>
          <label className="text-[10px] font-semibold uppercase tracking-wide text-slate-500">Method<select aria-label="Filter by method" value={filters.method} onChange={(event) => updateFilter('method', event.target.value)} className={selectClass}><option value="">All methods</option>{methods.map((item) => <option key={item} value={item}>{item}</option>)}</select></label>
          <label className="text-[10px] font-semibold uppercase tracking-wide text-slate-500">Action status<select aria-label="Filter by action status" value={filters.action_status} onChange={(event) => updateFilter('action_status', event.target.value)} className={selectClass}><option value="">All statuses</option>{actionStatuses.map((item) => <option key={item} value={item}>{item}</option>)}</select></label>
        </div>
        <div className="mt-3 grid gap-3 sm:grid-cols-2 xl:grid-cols-[1fr_1fr_1.2fr_1.2fr_1fr_auto]">
          <label className="text-[10px] font-semibold uppercase tracking-wide text-slate-500">Category<input aria-label="Filter by category" value={filters.category} onChange={(event) => updateFilter('category', event.target.value)} className={inputClass} placeholder="Any category" /></label>
          <label className="text-[10px] font-semibold uppercase tracking-wide text-slate-500">From<input aria-label="Comments from date" type="date" value={filters.from} onChange={(event) => updateFilter('from', event.target.value)} className={inputClass} /></label>
          <label className="text-[10px] font-semibold uppercase tracking-wide text-slate-500">To<input aria-label="Comments to date" type="date" value={filters.to} onChange={(event) => updateFilter('to', event.target.value)} className={inputClass} /></label>
          <label className="text-[10px] font-semibold uppercase tracking-wide text-slate-500">Min confidence<input aria-label="Minimum confidence" type="number" min="0" max="1" step="0.01" value={filters.min_confidence} onChange={(event) => updateFilter('min_confidence', event.target.value)} className={inputClass} placeholder="0–1" /></label>
          <label className="text-[10px] font-semibold uppercase tracking-wide text-slate-500">Max confidence<input aria-label="Maximum confidence" type="number" min="0" max="1" step="0.01" value={filters.max_confidence} onChange={(event) => updateFilter('max_confidence', event.target.value)} className={inputClass} placeholder="0–1" /></label>
          <div className="flex items-end"><Button onClick={clearFilters} className="w-full min-h-10 text-xs">Clear filters</Button></div>
        </div>
      </Panel>

      <Panel className="overflow-hidden">
        <div className="flex flex-wrap items-center justify-between gap-2 border-b border-white/[0.07] px-4 py-3.5"><div><h2 className="text-sm font-semibold text-white">Comment records</h2><p className="mt-1 text-[10px] text-slate-500">Showing at most {PAGE_SIZE} per page · all filters run in Laravel</p></div><Badge>{Number(pagination.total || 0).toLocaleString()} total</Badge></div>
        {loading ? <div className="p-4"><LoadingState label="Loading filtered comments" rows={5} /></div> : comments.length === 0 ? <EmptyState title="No comments found" description="Try clearing a filter or sync an active Page to load recent comments." action={<a href="/facebook/pages" className="text-xs font-semibold text-cyan-200">View Pages →</a>} /> : (
          <>
            <div className="hidden overflow-x-auto md:block">
              <table className="w-full min-w-[970px] text-left text-xs">
                <thead className="bg-white/[0.02] text-[10px] uppercase tracking-wide text-slate-500"><tr><th className="px-4 py-3">Comment</th><th className="px-3 py-3">Page</th><th className="px-3 py-3">Decision</th><th className="px-3 py-3">Method / category</th><th className="px-3 py-3">Action status</th><th className="px-3 py-3">Confidence</th><th className="px-3 py-3">Created</th><th className="px-4 py-3">Details</th></tr></thead>
                <tbody className="divide-y divide-white/[0.05]">
                  {comments.map((item) => <tr key={item.id} className="hover:bg-white/[0.02]"><td className="max-w-[320px] px-4 py-3"><p className="line-clamp-2 text-slate-200">{item.message || 'Comment text unavailable'}</p><p className="mt-1 truncate text-[10px] text-slate-500">{item.author?.name || 'Unknown author'}</p></td><td className="px-3 py-3 text-slate-400">{item.page?.page_name || '—'}</td><td className="px-3 py-3"><Badge tone={decisionTone(item.final_moderation?.action)}>{item.final_moderation?.action || 'pending'}</Badge></td><td className="px-3 py-3"><span className="capitalize text-slate-300">{item.final_moderation?.method || '—'}</span><span className="mt-1 block capitalize text-[10px] text-slate-500">{String(item.final_moderation?.category || '—').replaceAll('_', ' ')}</span></td><td className="px-3 py-3 capitalize text-slate-400">{String(item.facebook_action?.status || 'not queued').replaceAll('_', ' ')}</td><td className="px-3 py-3 tabular-nums text-slate-400">{item.final_moderation?.confidence == null ? '—' : `${Math.round(Number(item.final_moderation.confidence) * 100)}%`}</td><td className="whitespace-nowrap px-3 py-3 text-slate-500">{formatDate(item.created_at)}</td><td className="px-4 py-3"><a href={`/facebook/comments/${item.id}`} className="whitespace-nowrap font-semibold text-cyan-200 hover:text-white">View details →</a></td></tr>)}
                </tbody>
              </table>
            </div>
            <div className="divide-y divide-white/[0.05] md:hidden">{comments.map((item) => <article key={item.id} className="p-4"><CommentSummary comment={item} /><div className="mt-3 flex items-center justify-between gap-3"><time className="text-[10px] text-slate-500">{formatDate(item.created_at)}</time><a href={`/facebook/comments/${item.id}`} className="text-xs font-semibold text-cyan-200">View details →</a></div></article>)}</div>
            <Pagination page={pagination.current_page || page} lastPage={pagination.last_page || 1} total={pagination.total || 0} onChange={setPage} label="comments" />
          </>
        )}
      </Panel>
    </AppShell>
  );
}
