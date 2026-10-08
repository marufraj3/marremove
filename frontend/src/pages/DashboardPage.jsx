import { useEffect, useMemo, useState } from 'react';
import { getDashboardOverview } from '../api/dashboardService.js';
import { listFacebookPages } from '../api/facebookPageService.js';
import { AppShell } from '../components/AppShell.jsx';
import { Badge, Button, EmptyState, ErrorState, LoadingState, PageHeader, Panel, StatCard } from '../components/ui.jsx';
import { friendlyError } from '../utils/errors.js';

function formatDate(value) {
  if (!value) return '—';
  const date = new Date(value);
  return Number.isNaN(date.getTime()) ? '—' : date.toLocaleString();
}

function CompactChart({ title, subtitle, items = [], tone = 'cyan' }) {
  const highest = Math.max(1, ...items.map((item) => Number(item.value) || 0));
  const colors = {
    cyan: 'bg-cyan-300',
    amber: 'bg-amber-300',
    emerald: 'bg-emerald-300',
  };
  return (
    <Panel className="p-4 sm:p-5">
      <div className="flex items-start justify-between gap-3">
        <div><h2 className="text-sm font-semibold text-white">{title}</h2><p className="mt-1 text-[11px] text-slate-500">{subtitle}</p></div>
        <span className="rounded-lg border border-white/[0.07] px-2 py-1 text-[10px] text-slate-500">30 days</span>
      </div>
      {items.length === 0 ? (
        <div className="py-7 text-center text-xs text-slate-500">No moderation data for this range.</div>
      ) : (
        <div className="mt-5 space-y-3">
          {items.slice(0, 7).map((item) => (
            <div key={item.label} className="grid grid-cols-[minmax(72px,0.8fr)_minmax(50px,2fr)_36px] items-center gap-3">
              <span title={item.label} className="truncate text-xs capitalize text-slate-400">{String(item.label).replaceAll('_', ' ')}</span>
              <div className="h-2 overflow-hidden rounded-full bg-white/[0.05]">
                <div className={`h-full rounded-full ${colors[tone]}`} style={{ width: `${Math.max(2, (Number(item.value) / highest) * 100)}%` }} />
              </div>
              <span className="text-right text-xs font-semibold tabular-nums text-slate-200">{Number(item.value).toLocaleString()}</span>
            </div>
          ))}
        </div>
      )}
    </Panel>
  );
}

function DecisionBadge({ value }) {
  const tone = value === 'keep' ? 'success' : value === 'review' ? 'warning' : value === 'delete' ? 'danger' : 'info';
  return <Badge tone={tone}>{value || 'unknown'}</Badge>;
}

export function DashboardPage() {
  const [pages, setPages] = useState([]);
  const [selectedPageId, setSelectedPageId] = useState('');
  const [pagesLoading, setPagesLoading] = useState(true);
  const [pagesError, setPagesError] = useState('');
  const [overview, setOverview] = useState(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [refreshKey, setRefreshKey] = useState(0);

  const selectedPage = useMemo(() => pages.find((page) => String(page.id) === String(selectedPageId)) || null, [pages, selectedPageId]);

  useEffect(() => {
    let cancelled = false;
    listFacebookPages()
      .then((result) => {
        if (cancelled) return;
        const nextPages = Array.isArray(result?.data) ? result.data : [];
        setPages(nextPages);
        setPagesError('');
        const queryPage = new URLSearchParams(window.location.search).get('page_id');
        if (queryPage && nextPages.some((page) => String(page.id) === queryPage)) setSelectedPageId(queryPage);
      })
      .catch((requestError) => {
        if (!cancelled) setPagesError(friendlyError(requestError, 'Could not load your Facebook Pages.'));
      })
      .finally(() => { if (!cancelled) setPagesLoading(false); });
    return () => { cancelled = true; };
  }, []);

  useEffect(() => {
    let cancelled = false;
    setLoading(true);
    setOverview(null);
    setError('');

    getDashboardOverview({ page_id: selectedPageId })
      .then((result) => {
        if (!cancelled) setOverview(result?.data || null);
      })
      .catch((requestError) => {
        if (cancelled) return;
        setOverview(null);
        setError(friendlyError(requestError, 'Could not load dashboard statistics.'));
      })
      .finally(() => { if (!cancelled) setLoading(false); });

    return () => { cancelled = true; };
  }, [selectedPageId, refreshKey]);

  const stats = overview?.stats;
  const cards = [
    { label: 'Connected Pages', value: stats?.connected_pages, icon: '▣', hint: selectedPage ? 'Selected Page' : 'Pages connected to your account', tone: 'cyan' },
    { label: 'Comments today', value: stats?.comments_today, icon: '☷', hint: 'New comments recorded today', tone: 'emerald' },
    { label: 'Comments this week', value: stats?.comments_this_week, icon: '↗', hint: 'Since the start of this week', tone: 'cyan' },
    { label: 'Clean comments', value: stats?.clean_comments, icon: '✓', hint: 'Final category: clean', tone: 'emerald' },
    { label: 'Manual rule matches', value: stats?.manual_rule_matches, icon: '⌘', hint: 'Comments matched by a manual rule', tone: 'amber' },
    { label: 'AI moderated', value: stats?.ai_moderated, icon: '✳', hint: 'AI evaluation completed', tone: 'cyan' },
    { label: 'Review required', value: stats?.review_required, icon: '◷', hint: 'Waiting for a human decision', tone: 'amber' },
    { label: 'Hidden comments', value: stats?.hidden_comments, icon: '◌', hint: 'Current Facebook state', tone: 'amber' },
    { label: 'Deleted comments', value: stats?.deleted_comments, icon: '×', hint: 'Current Facebook state', tone: 'rose' },
    { label: 'Failed actions', value: stats?.failed_actions, icon: '!', hint: 'Queued actions needing attention', tone: 'rose' },
  ];

  return (
    <AppShell activePage="dashboard">
      <PageHeader
        eyebrow="Operations overview"
        title={selectedPage ? selectedPage.page_name : 'Dashboard'}
        description="Live moderation activity and decision trends from your connected Facebook Pages. Aggregate data is computed server-side and scoped to Pages you own."
        actions={(
          <>
            <label className="sr-only" htmlFor="dashboard-page-filter">Filter dashboard by Facebook Page</label>
            <select id="dashboard-page-filter" aria-label="Filter dashboard by Page" value={selectedPageId} onChange={(event) => setSelectedPageId(event.target.value)} disabled={pagesLoading} className="min-h-10 max-w-[240px] rounded-xl border border-white/10 bg-[#0d1422] px-3 text-sm text-slate-200 outline-none focus:border-cyan-300/50">
              <option value="">All Pages</option>
              {pages.map((page) => <option key={page.id} value={page.id}>{page.page_name}{!page.is_active ? ' · inactive' : ''}</option>)}
            </select>
            {selectedPage && <a href={`/facebook/pages/${selectedPage.id}`} className="inline-flex min-h-10 items-center rounded-xl border border-white/10 px-4 text-sm font-medium text-slate-200 hover:bg-white/5">Page details</a>}
            <Button onClick={() => setRefreshKey((key) => key + 1)} disabled={loading}>↻ <span className="hidden sm:inline">Refresh</span></Button>
          </>
        )}
      />

      {pagesError && <div className="mb-4"><ErrorState message={pagesError} onRetry={() => window.location.reload()} /></div>}
      {error && <div className="mb-4"><ErrorState message={error} onRetry={() => setRefreshKey((key) => key + 1)} /></div>}

      {loading ? (
        <LoadingState label="Loading moderation dashboard" rows={4} />
      ) : overview ? (
        <>
          <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-5">
            {cards.map((card) => <StatCard key={card.label} {...card} value={Number(card.value || 0)} />)}
          </div>

          <section aria-label="Moderation analytics" className="mt-6 grid gap-4 xl:grid-cols-3">
            <CompactChart title="Decision breakdown" subtitle="Final moderator decision" items={overview.charts?.decisions || []} tone="cyan" />
            <CompactChart title="Moderation method" subtitle="How the final decision was reached" items={overview.charts?.methods || []} tone="emerald" />
            <CompactChart title="Comment categories" subtitle="Top final categories" items={overview.charts?.categories || []} tone="amber" />
          </section>

          <section className="mt-6 grid gap-4 xl:grid-cols-[1.5fr_1fr]">
            <Panel className="overflow-hidden">
              <div className="flex items-center justify-between gap-4 border-b border-white/[0.07] px-4 py-4 sm:px-5">
                <div><h2 className="text-sm font-semibold text-white">Recent moderation activity</h2><p className="mt-1 text-[11px] text-slate-500">Latest completed decisions</p></div>
                <a href="/facebook/comments" className="text-xs font-semibold text-cyan-200 hover:text-white">View comments →</a>
              </div>
              {(overview.recent_activity || []).length === 0 ? <EmptyState title="No moderation activity yet" description="Completed decisions will appear here once comments have been analyzed." /> : (
                <div className="overflow-x-auto">
                  <table className="w-full min-w-[760px] text-left text-xs">
                    <thead className="bg-white/[0.02] text-[10px] uppercase tracking-wide text-slate-500">
                      <tr><th className="px-4 py-3 font-semibold">Comment / Page</th><th className="px-3 py-3 font-semibold">Category</th><th className="px-3 py-3 font-semibold">Method</th><th className="px-3 py-3 font-semibold">Decision</th><th className="px-3 py-3 font-semibold">Confidence</th><th className="px-4 py-3 font-semibold">When</th></tr>
                    </thead>
                    <tbody className="divide-y divide-white/[0.05]">
                      {overview.recent_activity.map((item) => (
                        <tr key={item.id} className="hover:bg-white/[0.02]">
                          <td className="max-w-[300px] px-4 py-3">
                            <a href={`/facebook/comments/${item.id}`} className="block truncate font-medium text-slate-200 hover:text-cyan-100">{item.message || 'Comment text unavailable'}</a>
                            <span className="mt-1 block truncate text-[10px] text-slate-500">{item.page_name || 'Facebook Page'}</span>
                          </td>
                          <td className="px-3 py-3 capitalize text-slate-400">{String(item.category || '—').replaceAll('_', ' ')}</td>
                          <td className="px-3 py-3 capitalize text-slate-400">{item.method || '—'}</td>
                          <td className="px-3 py-3"><DecisionBadge value={item.decision} /></td>
                          <td className="px-3 py-3 tabular-nums text-slate-400">{item.confidence === null ? '—' : `${Math.round(Number(item.confidence) * 100)}%`}</td>
                          <td className="whitespace-nowrap px-4 py-3 text-slate-500">{formatDate(item.created_at)}</td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
              )}
            </Panel>

            <Panel className="overflow-hidden">
              <div className="border-b border-white/[0.07] px-4 py-4 sm:px-5"><h2 className="text-sm font-semibold text-white">Action queue</h2><p className="mt-1 text-[11px] text-slate-500">Recent Facebook action service results</p></div>
              {(overview.recent_actions || []).length === 0 ? <EmptyState title="No action logs yet" description="Queued or simulated Page actions will be listed here." /> : (
                <div className="divide-y divide-white/[0.05]">
                  {overview.recent_actions.map((action) => (
                    <a key={action.id} href="/logs/actions" className="block px-4 py-3.5 transition hover:bg-white/[0.025] sm:px-5">
                      <div className="flex items-center justify-between gap-3"><span className="text-xs font-semibold uppercase text-slate-200">{action.action}</span><Badge tone={action.status === 'failed' ? 'danger' : action.status === 'completed' || action.status === 'processed' ? 'success' : 'warning'}>{action.status}</Badge></div>
                      <p className="mt-1.5 line-clamp-2 text-xs leading-5 text-slate-400">{action.message || 'Comment content unavailable'}</p>
                      <p className="mt-2 text-[10px] text-slate-600">{action.page_name || 'Facebook Page'} · {formatDate(action.created_at)}</p>
                    </a>
                  ))}
                </div>
              )}
              {(overview.recent_actions || []).length > 0 && <a href="/logs/actions" className="block border-t border-white/[0.07] px-5 py-3 text-xs font-semibold text-cyan-200 hover:bg-white/[0.02]">Open Action Logs →</a>}
            </Panel>
          </section>
          <p className="mt-4 text-right text-[10px] text-slate-600">Updated {formatDate(overview.range?.generated_at)} · Charts use completed moderation decisions from the last 30 days.</p>
        </>
      ) : (
        <Panel><EmptyState title="Dashboard data is unavailable" description="Refresh to try loading the latest moderation statistics." action={<Button onClick={() => setRefreshKey((key) => key + 1)}>Refresh</Button>} /></Panel>
      )}
    </AppShell>
  );
}
