import { useCallback, useEffect, useMemo, useState } from 'react';
import { getAiModerationSettings, updatePageModerationSettings } from '../api/aiModerationService.js';
import { AppShell } from '../components/AppShell.jsx';
import { Badge, Button, ConfirmDialog, EmptyState, ErrorState, LoadingState, PageHeader, Panel } from '../components/ui.jsx';
import { friendlyError } from '../utils/errors.js';
import { emitToast } from '../components/ui.jsx';

const thresholdFields = [
  { key: 'auto_review_threshold', label: 'Review threshold', help: 'Lower confidence decisions remain in the human review queue.' },
  { key: 'auto_hide_threshold', label: 'Hide threshold', help: 'Minimum confidence required for eligible hide recommendations.' },
  { key: 'auto_delete_threshold', label: 'Delete threshold', help: 'Minimum confidence required for eligible delete recommendations.' },
];

function draftFor(page) {
  return {
    manual_enabled: Boolean(page.manual_enabled),
    ai_enabled: Boolean(page.ai_enabled),
    auto_review_threshold: Number(page.auto_review_threshold ?? 0.7),
    auto_hide_threshold: Number(page.auto_hide_threshold ?? 0.9),
    auto_delete_threshold: Number(page.auto_delete_threshold ?? 0.98),
    allow_ai_hide: Boolean(page.allow_ai_hide),
    allow_ai_delete: Boolean(page.allow_ai_delete),
    auto_hide_enabled: Boolean(page.auto_hide_enabled),
    auto_delete_enabled: Boolean(page.auto_delete_enabled),
    auto_execute_actions: Boolean(page.auto_execute_actions),
  };
}

export function ModerationSettingsPage() {
  const [settings, setSettings] = useState(null);
  const [drafts, setDrafts] = useState({});
  const [selectedPageId, setSelectedPageId] = useState('');
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [saving, setSaving] = useState(false);
  const [confirmSave, setConfirmSave] = useState(false);
  const [refreshKey, setRefreshKey] = useState(0);

  const loadSettings = useCallback(async (signal) => {
    setLoading(true);
    try {
      const result = await getAiModerationSettings({ signal });
      if (signal?.aborted) return;
      const data = result?.data || null;
      setSettings(data);
      setDrafts(Object.fromEntries((data?.pages || []).map((page) => [page.id, draftFor(page)])));
      setSelectedPageId((current) => {
        const queryPageId = new URLSearchParams(window.location.search).get('page_id');
        const requested = current || queryPageId;
        return data?.pages?.some((page) => String(page.id) === String(requested)) ? String(requested) : String(data?.pages?.[0]?.id || '');
      });
      setError('');
    } catch (requestError) {
      if (signal?.aborted) return;
      setError(friendlyError(requestError, 'Could not load moderation settings.'));
      setSettings(null);
    } finally {
      if (!signal?.aborted) setLoading(false);
    }
  }, []);

  useEffect(() => {
    const controller = new AbortController();
    loadSettings(controller.signal);
    return () => controller.abort();
  }, [loadSettings, refreshKey]);

  const page = useMemo(() => settings?.pages?.find((item) => String(item.id) === String(selectedPageId)) || null, [settings, selectedPageId]);
  const draft = page ? drafts[page.id] || draftFor(page) : null;
  const inputClass = 'mt-2 w-full rounded-xl border border-white/10 bg-[#0b1120] px-3 py-2.5 text-sm text-white outline-none focus:border-cyan-300/50';

  function change(key, value) {
    if (!page) return;
    setDrafts((current) => ({ ...current, [page.id]: { ...current[page.id], [key]: value } }));
  }

  const thresholdError = draft && (
    Number(draft.auto_review_threshold) > Number(draft.auto_hide_threshold)
      ? 'The hide threshold must be at least as high as the review threshold.'
      : Number(draft.auto_hide_threshold) > Number(draft.auto_delete_threshold)
        ? 'The delete threshold must be at least as high as the hide threshold.'
        : ''
  );
  const hasDangerousEnable = page && draft && (
    (draft.allow_ai_delete && !page.allow_ai_delete)
    || (draft.auto_hide_enabled && !page.auto_hide_enabled)
    || (draft.auto_delete_enabled && !page.auto_delete_enabled)
    || (draft.auto_execute_actions && !page.auto_execute_actions)
  );

  async function save() {
    if (!page || !draft || saving || thresholdError) return;
    setSaving(true);
    try {
      const payload = {
        ...draft,
        auto_review_threshold: Number(draft.auto_review_threshold),
        auto_hide_threshold: Number(draft.auto_hide_threshold),
        auto_delete_threshold: Number(draft.auto_delete_threshold),
      };
      const result = await updatePageModerationSettings(page.id, payload);
      const updated = result?.data;
      setSettings((current) => current && ({ ...current, pages: current.pages.map((item) => String(item.id) === String(page.id) ? { ...item, ...updated } : item) }));
      setDrafts((current) => ({ ...current, [page.id]: draftFor(updated) }));
      emitToast(`${page.page_name}: settings saved.`);
      setConfirmSave(false);
    } catch (requestError) {
      emitToast(friendlyError(requestError, 'Could not save moderation settings.'), 'error');
    } finally {
      setSaving(false);
    }
  }

  return (
    <AppShell activePage="moderation-settings">
      <PageHeader eyebrow="Per-Page controls" title="Moderation Settings" description="Tune manual and AI decision thresholds and the safeguards around Facebook actions. Dangerous automatic-action settings require an explicit confirmation before saving." actions={<Button onClick={() => setRefreshKey((key) => key + 1)} disabled={loading}>↻ Refresh</Button>} />
      {error && <div className="mb-5"><ErrorState message={error} onRetry={() => setRefreshKey((key) => key + 1)} /></div>}
      {loading ? <LoadingState label="Loading moderation settings" rows={4} /> : settings ? (
        <>
          <Panel className="mb-4 flex flex-col gap-4 p-4 sm:flex-row sm:items-center sm:justify-between sm:p-5">
            <div><p className="text-xs font-semibold text-slate-200">Select a connected Page</p><p className="mt-1 text-[11px] text-slate-500">Settings are read and written only for Pages owned by your authenticated account.</p></div>
            <select aria-label="Select Facebook Page" value={selectedPageId} onChange={(event) => setSelectedPageId(event.target.value)} className="min-h-10 w-full rounded-xl border border-white/10 bg-[#0b1120] px-3 text-sm text-white outline-none sm:max-w-xs">
              {(settings.pages || []).map((item) => <option key={item.id} value={item.id}>{item.page_name}{!item.is_active ? ' · inactive' : ''}</option>)}
            </select>
          </Panel>
          {!page || !draft ? <Panel><EmptyState title="No Facebook Page available" description="Connect a Page to view and configure its moderation settings." action={<a href="/facebook/pages/connect" className="text-xs font-semibold text-cyan-200">Connect a Page →</a>} /></Panel> : (
            <>
              <Panel className="p-4 sm:p-5">
                <div className="flex flex-wrap items-start justify-between gap-3"><div><h2 className="text-base font-semibold text-white">{page.page_name}</h2><p className="mt-1 text-xs text-slate-500">{page.is_active ? 'Connected and active' : 'Inactive Page'} · Global AI {settings.enabled ? 'enabled' : 'disabled'}</p></div><Badge tone={settings.default_action_settings?.test_mode ? 'warning' : 'neutral'}>{settings.default_action_settings?.test_mode ? 'Test mode on' : 'Test mode off'}</Badge></div>

                <div className="mt-5 grid gap-3 sm:grid-cols-2">
                  <label className="flex items-start gap-3 rounded-xl border border-white/[0.07] bg-white/[0.015] p-3.5 text-sm text-slate-200"><input type="checkbox" checked={draft.manual_enabled} onChange={(event) => change('manual_enabled', event.target.checked)} className="mt-0.5 h-4 w-4 accent-cyan-300" /><span><span className="block font-medium">Manual rules enabled</span><span className="mt-1 block text-[11px] leading-5 text-slate-500">Apply existing moderation rules during evaluation.</span></span></label>
                  <label className="flex items-start gap-3 rounded-xl border border-white/[0.07] bg-white/[0.015] p-3.5 text-sm text-slate-200"><input type="checkbox" checked={draft.ai_enabled} onChange={(event) => change('ai_enabled', event.target.checked)} className="mt-0.5 h-4 w-4 accent-cyan-300" /><span><span className="block font-medium">AI moderation enabled</span><span className="mt-1 block text-[11px] leading-5 text-slate-500">Requires the global AI service to be enabled and configured.</span></span></label>
                </div>

                <div className="mt-6"><h3 className="text-sm font-semibold text-white">Confidence thresholds</h3><p className="mt-1 text-[11px] leading-5 text-slate-500">Required ordering: review ≤ hide ≤ delete. Keep the thresholds aligned with the risk level of your Page.</p>
                  <div className="mt-3 grid gap-3 md:grid-cols-3">
                    {thresholdFields.map((field) => <label key={field.key} className="rounded-xl border border-white/[0.07] bg-white/[0.015] p-3 text-xs font-medium text-slate-300">{field.label}<div className="mt-2 flex items-center gap-2"><input type="number" aria-label={field.label} min="0" max="1" step="0.01" value={draft[field.key]} onChange={(event) => change(field.key, event.target.value === '' ? '' : Number(event.target.value))} className="w-full rounded-lg border border-white/10 bg-[#0b1120] px-3 py-2 text-sm text-white outline-none focus:border-cyan-300/50" /><span className="shrink-0 text-slate-500">0–1</span></div><span className="mt-2 block font-normal leading-5 text-slate-500">{field.help}</span></label>)}
                  </div>
                  {thresholdError && <p role="alert" className="mt-3 text-xs text-rose-200">{thresholdError}</p>}
                </div>

                <div className="mt-6"><h3 className="text-sm font-semibold text-white">Action recommendations and execution</h3><p className="mt-1 text-[11px] leading-5 text-slate-500">Recommendation switches do not themselves call Facebook. Automatic execution also requires the global test mode to be off and the action queue safeguards to allow it.</p>
                  <div className="mt-3 grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                    <label className="flex items-start gap-3 rounded-xl border border-white/[0.07] bg-white/[0.015] p-3.5 text-sm text-slate-200"><input type="checkbox" checked={draft.allow_ai_hide} onChange={(event) => change('allow_ai_hide', event.target.checked)} className="mt-0.5 h-4 w-4 accent-amber-300" /><span><span className="block font-medium">Allow AI hide recommendation</span><span className="mt-1 block text-[11px] leading-5 text-slate-500">Eligible high-risk cases only.</span></span></label>
                    <label className="flex items-start gap-3 rounded-xl border border-rose-300/15 bg-rose-300/[0.025] p-3.5 text-sm text-slate-200"><input type="checkbox" checked={draft.allow_ai_delete} onChange={(event) => change('allow_ai_delete', event.target.checked)} className="mt-0.5 h-4 w-4 accent-rose-300" /><span><span className="block font-medium text-rose-100">Allow AI delete recommendation</span><span className="mt-1 block text-[11px] leading-5 text-slate-500">High risk: AI can recommend an irreversible action; protected categories remain server-enforced.</span></span></label>
                    <label className="flex items-start gap-3 rounded-xl border border-amber-300/15 bg-amber-300/[0.025] p-3.5 text-sm text-slate-200"><input type="checkbox" checked={draft.auto_hide_enabled} onChange={(event) => change('auto_hide_enabled', event.target.checked)} className="mt-0.5 h-4 w-4 accent-amber-300" /><span><span className="block font-medium">Allow automatic Facebook hide</span><span className="mt-1 block text-[11px] leading-5 text-slate-500">Requires the separate automatic-execution switch.</span></span></label>
                    <label className="flex items-start gap-3 rounded-xl border border-rose-300/15 bg-rose-300/[0.025] p-3.5 text-sm text-slate-200"><input type="checkbox" checked={draft.auto_delete_enabled} onChange={(event) => change('auto_delete_enabled', event.target.checked)} className="mt-0.5 h-4 w-4 accent-rose-300" /><span><span className="block font-medium text-rose-100">Allow automatic Facebook delete</span><span className="mt-1 block text-[11px] leading-5 text-slate-500">Dangerous and irreversible. Confirm before enabling.</span></span></label>
                    <label className="flex items-start gap-3 rounded-xl border border-rose-300/15 bg-rose-300/[0.025] p-3.5 text-sm text-slate-200"><input type="checkbox" checked={draft.auto_execute_actions} onChange={(event) => change('auto_execute_actions', event.target.checked)} className="mt-0.5 h-4 w-4 accent-rose-300" /><span><span className="block font-medium text-rose-100">Execute eligible actions automatically</span><span className="mt-1 block text-[11px] leading-5 text-slate-500">Enabling this may trigger queued Page actions; test mode remains server-controlled.</span></span></label>
                  </div>
                </div>

                <div className="mt-5 rounded-xl border border-amber-300/15 bg-amber-300/[0.035] p-3.5 text-xs leading-5 text-amber-100/80"><strong className="font-semibold text-amber-100">Safety safeguards:</strong> manual KEEP takes precedence; clean/complaint/negative feedback and other protected categories are not automatically deleted; test mode simulates success and never calls Meta. The backend owns all enforcement and queues every Facebook action.</div>
                <div className="mt-5 flex flex-wrap items-center justify-between gap-3 border-t border-white/[0.07] pt-4"><p className="text-[11px] text-slate-500">Page ID {page.facebook_page_id} · Changes affect future moderation.</p><Button variant="primary" onClick={() => setConfirmSave(true)} disabled={saving || Boolean(thresholdError) || !page.is_active}>Save moderation settings</Button></div>
              </Panel>
            </>
          )}
        </>
      ) : null}
      {confirmSave && page && <ConfirmDialog title="Save moderation settings?" message={hasDangerousEnable ? 'This change enables a dangerous automatic action. Verify that your thresholds, test mode, and protected-category safeguards are correct before applying it.' : 'Save these moderation settings for this Page? They will apply to future evaluations.'} confirmLabel={hasDangerousEnable ? 'Confirm and save' : 'Save settings'} danger={Boolean(hasDangerousEnable)} busy={saving} onConfirm={save} onCancel={() => { if (!saving) setConfirmSave(false); }} />}
    </AppShell>
  );
}
