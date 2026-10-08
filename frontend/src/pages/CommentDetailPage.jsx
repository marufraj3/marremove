import { useCallback, useEffect, useState } from 'react';
import { getFacebookComment } from '../api/facebookPageService.js';
import { overrideCommentModeration } from '../api/aiModerationService.js';
import { AppShell } from '../components/AppShell.jsx';
import { Badge, Button, ConfirmDialog, ErrorState, LoadingState, PageHeader, Panel } from '../components/ui.jsx';
import { friendlyError } from '../utils/errors.js';
import { emitToast } from '../components/ui.jsx';

function formatDate(value) {
  if (!value) return 'Not recorded';
  const date = new Date(value);
  return Number.isNaN(date.getTime()) ? 'Unavailable' : date.toLocaleString();
}

function TimelineItem({ title, time, children, tone = 'neutral' }) {
  const dot = tone === 'success' ? 'bg-emerald-300' : tone === 'warning' ? 'bg-amber-300' : tone === 'danger' ? 'bg-rose-300' : 'bg-cyan-300';
  return <li className="relative border-l border-white/[0.08] pb-6 pl-6 last:border-transparent last:pb-0"><span aria-hidden="true" className={`absolute -left-[5px] top-1.5 h-2.5 w-2.5 rounded-full ring-4 ring-[#101827] ${dot}`} /><div className="flex flex-wrap items-baseline justify-between gap-2"><h3 className="text-sm font-semibold text-slate-100">{title}</h3><time className="text-[10px] text-slate-500">{formatDate(time)}</time></div><div className="mt-1.5 text-xs leading-5 text-slate-400">{children}</div></li>;
}

function actionTone(action) {
  if (action === 'keep') return 'success';
  if (action === 'review') return 'warning';
  return action === 'delete' ? 'danger' : 'info';
}

export function CommentDetailPage({ commentId }) {
  const [comment, setComment] = useState(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [refreshKey, setRefreshKey] = useState(0);
  const [action, setAction] = useState('review');
  const [reason, setReason] = useState('');
  const [pendingAction, setPendingAction] = useState('');
  const [saving, setSaving] = useState(false);

  const loadComment = useCallback(async (signal) => {
    setLoading(true);
    try {
      const result = await getFacebookComment(commentId, { signal });
      if (signal?.aborted) return;
      const data = result?.data || null;
      setComment(data);
      setAction(data?.final_moderation?.action || 'review');
      setError('');
    } catch (requestError) {
      if (signal?.aborted) return;
      setComment(null);
      setError(friendlyError(requestError, 'Could not load this comment.'));
    } finally {
      if (!signal?.aborted) setLoading(false);
    }
  }, [commentId]);

  useEffect(() => {
    const controller = new AbortController();
    loadComment(controller.signal);
    return () => controller.abort();
  }, [loadComment, refreshKey]);

  async function confirmOverride() {
    if (!comment || saving || !pendingAction) return;
    setSaving(true);
    try {
      const result = await overrideCommentModeration(comment.id, pendingAction, reason);
      const updated = result?.data;
      if (updated) setComment(updated);
      setAction(pendingAction);
      setPendingAction('');
      setReason('');
      emitToast(`Final decision updated to ${pendingAction.toUpperCase()}. The Laravel action service applies Page safeguards.`);
    } catch (requestError) {
      emitToast(friendlyError(requestError, 'Could not save the moderation override.'), 'error');
    } finally {
      setSaving(false);
    }
  }

  const final = comment?.final_moderation || {};
  const manual = comment?.manual_moderation || {};
  const ai = comment?.ai_moderation || {};
  const fb = comment?.facebook_action || {};

  return (
    <AppShell activePage="comments">
      <PageHeader eyebrow="Comment decision details" title={comment?.page?.page_name || 'Comment'} description="Trace the moderation inputs, final decision, and any Page action status. All overrides run through Laravel—React never calls Facebook." actions={<><a href="/facebook/comments" className="inline-flex min-h-10 items-center rounded-xl border border-white/10 px-4 text-sm font-semibold text-slate-200 hover:bg-white/5">← Comments</a><a href="/moderation/review" className="inline-flex min-h-10 items-center rounded-xl border border-white/10 px-4 text-sm font-semibold text-slate-200 hover:bg-white/5">Review Queue</a><Button onClick={() => setRefreshKey((key) => key + 1)} disabled={loading}>↻ Refresh</Button></>} />
      {error && <div className="mb-5"><ErrorState message={error} onRetry={() => setRefreshKey((key) => key + 1)} /></div>}
      {loading ? <LoadingState label="Loading comment decision history" rows={4} /> : comment ? (
        <>
          <div className="grid gap-4 xl:grid-cols-[minmax(0,1.4fr)_minmax(280px,0.8fr)]">
            <Panel className="p-4 sm:p-5">
              <div className="flex flex-wrap items-center gap-2"><Badge>{comment.page?.page_name || 'Facebook Page'}</Badge><Badge tone={actionTone(final.action)}>{final.action || 'No final decision'}</Badge><Badge>{final.method || 'method unknown'}</Badge>{final.manual_override && <Badge tone="info">Manual override</Badge>}</div>
              <blockquote className="mt-4 whitespace-pre-wrap break-words text-sm leading-7 text-slate-200">{comment.message || 'Comment text is unavailable.'}</blockquote>
              <div className="mt-4 grid gap-2 border-t border-white/[0.07] pt-4 text-xs text-slate-500 sm:grid-cols-2"><p>Author <span className="text-slate-300">{comment.author?.name || 'Unknown'}</span></p><p>Commented <span className="text-slate-300">{formatDate(comment.created_at)}</span></p><p>Comment ID <span className="break-all font-mono text-slate-300">{comment.facebook_comment_id}</span></p><p>Post ID <span className="break-all font-mono text-slate-300">{comment.facebook_post_id || '—'}</span></p></div>
            </Panel>

            <Panel className="p-4 sm:p-5">
              <div className="flex items-start justify-between gap-3"><div><h2 className="text-sm font-semibold text-white">Final decision</h2><p className="mt-1 text-[11px] text-slate-500">Current recorded outcome</p></div><Badge tone={actionTone(final.action)}>{final.action || 'pending'}</Badge></div>
              <p className="mt-4 text-sm leading-6 text-slate-300">{final.reason || 'No explanation recorded.'}</p>
              <div className="mt-4 space-y-2 border-t border-white/[0.07] pt-3 text-xs"><p className="text-slate-500">Category <span className="ml-1 capitalize text-slate-200">{String(final.category || 'unknown').replaceAll('_', ' ')}</span></p><p className="text-slate-500">Confidence <span className="ml-1 text-slate-200">{final.confidence == null ? '—' : `${Math.round(Number(final.confidence) * 100)}%`}</span></p><p className="text-slate-500">Source <span className="ml-1 capitalize text-slate-200">{String(final.source || 'unknown').replaceAll('_', ' ')}</span></p><p className="text-slate-500">Facebook state <span className="ml-1 capitalize text-slate-200">{String(fb.state || 'unknown').replaceAll('_', ' ')}</span></p><p className="text-slate-500">Action status <span className="ml-1 capitalize text-slate-200">{String(fb.status || 'not queued').replaceAll('_', ' ')}</span></p></div>
              {fb.error && <p className="mt-3 rounded-lg border border-rose-300/10 bg-rose-300/[0.04] p-2 text-xs leading-5 text-rose-100">Action issue: {fb.error}</p>}
            </Panel>
          </div>

          <div className="mt-4 grid gap-4 xl:grid-cols-[minmax(0,1fr)_minmax(300px,0.8fr)]">
            <Panel className="p-4 sm:p-5">
              <h2 className="text-sm font-semibold text-white">Decision explanation timeline</h2>
              <p className="mt-1 text-[11px] text-slate-500">An auditable summary of moderation evidence and action state.</p>
              <ol className="mt-5">
                <TimelineItem title="Comment synchronized" time={comment.created_at} tone="neutral">Received for <span className="text-slate-200">{comment.page?.page_name || 'the connected Page'}</span>. Page ownership is verified by the backend.</TimelineItem>
                <TimelineItem title="Manual rules evaluated" time={manual.checked_at} tone={manual.status === 'matched' ? 'warning' : 'neutral'}><span className="capitalize">{manual.status || 'not evaluated'}</span>{manual.action ? ` · suggested ${manual.action}` : ''}{manual.category ? ` · ${manual.category}` : ''}{manual.reason ? ` · ${manual.reason}` : ''}</TimelineItem>
                <TimelineItem title="AI classification" time={ai.checked_at} tone={ai.status === 'completed' ? 'info' : 'neutral'}><span className="capitalize">{ai.status || 'not evaluated'}</span>{ai.category ? ` · ${ai.category}` : ''}{ai.decision ? ` · ${ai.decision}` : ''}{ai.confidence != null ? ` · ${Math.round(Number(ai.confidence) * 100)}% confidence` : ''}{ai.reason ? ` · ${ai.reason}` : ''}</TimelineItem>
                <TimelineItem title={final.manual_override ? 'Administrator override' : 'Final decision recorded'} time={final.decision_at || final.overridden_at} tone={actionTone(final.action)}><span className="capitalize">{final.action || 'pending'}</span>{final.method ? ` · ${final.method}` : ''}{final.reason ? ` · ${final.reason}` : ''}{final.overridden_at ? ` · override at ${formatDate(final.overridden_at)}` : ''}</TimelineItem>
                <TimelineItem title="Facebook action service" time={fb.completed_at || fb.failed_at || fb.attempted_at} tone={fb.status === 'failed' ? 'danger' : fb.status === 'completed' ? 'success' : 'neutral'}>{fb.requested_action ? `Requested ${fb.requested_action}` : 'No Facebook action requested'}{fb.status ? ` · ${fb.status}` : ''}{fb.attempt_count ? ` · ${fb.attempt_count} attempt(s)` : ''}</TimelineItem>
              </ol>
            </Panel>

            <Panel className="p-4 sm:p-5">
              <h2 className="text-sm font-semibold text-white">Administrator override</h2>
              <p className="mt-1 text-xs leading-5 text-slate-500">Choose a final recommendation. The existing Laravel service audits it and applies configured queue safeguards; this form never contacts Meta directly.</p>
              {comment.can_override ? (
                <form onSubmit={(event) => { event.preventDefault(); setPendingAction(action); }} className="mt-4 space-y-3">
                  <label className="block text-xs font-medium text-slate-300">Final decision<select aria-label="Final decision" value={action} onChange={(event) => setAction(event.target.value)} className="mt-2 w-full rounded-xl border border-white/10 bg-[#0b1120] px-3 py-2.5 text-sm text-white outline-none focus:border-cyan-300/50">{['keep', 'review', 'hide', 'delete'].map((value) => <option key={value} value={value}>{value.toUpperCase()}</option>)}</select></label>
                  <label className="block text-xs font-medium text-slate-300">Reason (optional)<textarea maxLength={280} value={reason} onChange={(event) => setReason(event.target.value)} rows={3} className="mt-2 w-full resize-y rounded-xl border border-white/10 bg-[#0b1120] px-3 py-2.5 text-sm text-white outline-none focus:border-cyan-300/50" placeholder="Add a concise audit reason" /></label>
                  {action === 'delete' && <p className="rounded-lg border border-rose-300/15 bg-rose-300/[0.04] p-3 text-xs leading-5 text-rose-100">Delete is irreversible if the existing Page settings allow automatic execution. The action queue and test mode remain enforced by Laravel.</p>}
                  <Button type="submit" variant={action === 'delete' ? 'danger' : 'primary'}>Review and confirm</Button>
                </form>
              ) : <p className="mt-4 text-xs text-slate-500">This account cannot override this comment.</p>}
              <p className="mt-4 border-t border-white/[0.07] pt-3 text-[10px] leading-5 text-slate-600">KEEP has precedence over automatic moderation. Explicit overrides are recorded without changing the original manual-rule or AI evidence.</p>
            </Panel>
          </div>
        </>
      ) : null}
      {pendingAction && <ConfirmDialog title={`Confirm ${pendingAction.toUpperCase()} override`} message={pendingAction === 'delete' ? 'This records an administrator decision. If Page settings permit, Laravel may enqueue the irreversible delete action. Continue only if you have reviewed the comment and the Page safeguards.' : 'This records an administrator decision. Any eligible Facebook action is handled through the Laravel queue, never from the browser.'} confirmLabel={`Confirm ${pendingAction}`} danger={pendingAction === 'delete'} busy={saving} onConfirm={confirmOverride} onCancel={() => { if (!saving) setPendingAction(''); }} />}
    </AppShell>
  );
}
