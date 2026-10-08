import { useEffect, useState } from 'react';
import {
  createModerationRule,
  listModerationPages,
  deleteModerationRule,
  listModerationRules,
  testModerationRules,
  updateModerationRule,
} from '../api/moderationRuleService.js';
import { AppShell } from '../components/AppShell.jsx';
import { Pagination } from '../components/ui.jsx';
import { friendlyError } from '../utils/errors.js';

const RULE_TYPES = [
  { value: 'keyword', label: 'Keyword' },
  { value: 'phrase', label: 'Phrase' },
  { value: 'regex', label: 'Regular expression' },
  { value: 'url', label: 'Any URL' },
  { value: 'phone', label: 'Phone number' },
  { value: 'repeated_text', label: 'Repeated text' },
];
const ACTIONS = ['keep', 'review', 'hide', 'delete'];
const SEVERITIES = ['low', 'medium', 'high', 'critical'];
const TYPE_DEFAULTS = { keyword: '', phrase: '', regex: '', url: '*', phone: '*', repeated_text: '3' };

function blankRule() {
  return {
    name: '',
    category: '',
    rule_type: 'keyword',
    pattern: '',
    action: 'review',
    severity: 'medium',
    priority: 0,
    facebook_page_id: '',
    is_active: true,
  };
}

function errorMessage(error) {
  return friendlyError(error, 'Something went wrong. Please try again.');
}

function actionClasses(action) {
  return {
    keep: 'border-slate-400/20 bg-slate-400/10 text-slate-200',
    review: 'border-amber-300/25 bg-amber-300/10 text-amber-200',
    hide: 'border-orange-300/25 bg-orange-300/10 text-orange-200',
    delete: 'border-rose-300/25 bg-rose-300/10 text-rose-200',
  }[action] || 'border-white/10 bg-white/5 text-slate-300';
}

export function ModerationRulesPage() {
  const [pages, setPages] = useState([]);
  const [rules, setRules] = useState([]);
  const [meta, setMeta] = useState({ current_page: 1, last_page: 1, per_page: 100, total: 0 });
  const [page, setPage] = useState(1);
  const [search, setSearch] = useState('');
  const [pageFilter, setPageFilter] = useState('all');
  const [actionFilter, setActionFilter] = useState('');
  const [typeFilter, setTypeFilter] = useState('');
  const [sortPriority, setSortPriority] = useState('desc');
  const [reloadKey, setReloadKey] = useState(0);
  const [loading, setLoading] = useState(true);
  const [pageError, setPageError] = useState('');
  const [notice, setNotice] = useState('');

  const [formOpen, setFormOpen] = useState(false);
  const [editingId, setEditingId] = useState(null);
  const [form, setForm] = useState(blankRule());
  const [formBusy, setFormBusy] = useState(false);
  const [formError, setFormError] = useState('');

  const [testComment, setTestComment] = useState('');
  const [testPageId, setTestPageId] = useState('');
  const [testLoading, setTestLoading] = useState(false);
  const [testError, setTestError] = useState('');
  const [testResult, setTestResult] = useState(null);

  useEffect(() => {
    let cancelled = false;
    listModerationPages()
      .then((result) => {
        if (!cancelled) setPages(Array.isArray(result?.data) ? result.data : []);
      })
      .catch((error) => {
        if (!cancelled) setPageError(errorMessage(error));
      });
    return () => { cancelled = true; };
  }, []);

  useEffect(() => {
    setPage(1);
  }, [actionFilter, pageFilter, search, sortPriority, typeFilter]);

  useEffect(() => {
    let cancelled = false;
    const timeoutId = window.setTimeout(async () => {
      setLoading(true);
      try {
        const filters = {
          search: search.trim(),
          facebook_page_id: pageFilter === 'all' ? '' : pageFilter,
          action: actionFilter,
          rule_type: typeFilter,
          sort_priority: sortPriority,
          per_page: 100,
          page,
        };
        const result = await listModerationRules(filters);
        if (cancelled) return;
        setRules(Array.isArray(result?.data) ? result.data : []);
        setMeta(result?.meta || { current_page: 1, last_page: 1, per_page: 100, total: 0 });
        setPageError('');
      } catch (error) {
        if (!cancelled) setPageError(errorMessage(error));
      } finally {
        if (!cancelled) setLoading(false);
      }
    }, search.trim() ? 250 : 0);

    return () => {
      cancelled = true;
      window.clearTimeout(timeoutId);
    };
  }, [actionFilter, page, pageFilter, reloadKey, search, sortPriority, typeFilter]);

  function startCreate() {
    setEditingId(null);
    setForm(blankRule());
    setFormError('');
    setFormOpen(true);
  }

  function startEdit(rule) {
    setEditingId(rule.id);
    setForm({
      name: rule.name || '',
      category: rule.category || '',
      rule_type: rule.rule_type,
      pattern: rule.pattern || '',
      action: rule.action,
      severity: rule.severity || 'medium',
      priority: rule.priority ?? 0,
      facebook_page_id: rule.facebook_page_id || '',
      is_active: Boolean(rule.is_active),
    });
    setFormError('');
    setFormOpen(true);
  }

  async function saveRule(event) {
    event.preventDefault();
    if (formBusy) return;

    setFormBusy(true);
    setFormError('');
    setNotice('');
    const payload = {
      ...form,
      name: form.name.trim(),
      category: form.category.trim() || null,
      pattern: form.pattern.trim(),
      priority: Number(form.priority),
      facebook_page_id: form.facebook_page_id || null,
    };

    try {
      if (editingId) {
        await updateModerationRule(editingId, payload);
        setNotice('Rule updated. Existing comments are not reprocessed automatically.');
      } else {
        await createModerationRule(payload);
        setNotice('Rule created. New and synchronized comments will be evaluated.');
      }
      setFormOpen(false);
      setEditingId(null);
      setForm(blankRule());
      setReloadKey((key) => key + 1);
    } catch (error) {
      setFormError(errorMessage(error));
    } finally {
      setFormBusy(false);
    }
  }

  async function toggleRule(rule) {
    setNotice('');
    setPageError('');
    try {
      await updateModerationRule(rule.id, { is_active: !rule.is_active });
      setNotice(`Rule ${rule.is_active ? 'disabled' : 'enabled'}.`);
      setReloadKey((key) => key + 1);
    } catch (error) {
      setPageError(errorMessage(error));
    }
  }

  async function removeRule(rule) {
    if (!window.confirm(`Delete the rule “${rule.name}”? Stored comment decisions will remain as history.`)) return;
    setNotice('');
    setPageError('');
    try {
      await deleteModerationRule(rule.id);
      setNotice('Rule deleted.');
      if (editingId === rule.id) setFormOpen(false);
      setReloadKey((key) => key + 1);
    } catch (error) {
      setPageError(errorMessage(error));
    }
  }

  async function runRuleTest(event) {
    event.preventDefault();
    if (testLoading || !testComment.trim()) return;

    setTestLoading(true);
    setTestError('');
    setTestResult(null);
    try {
      const result = await testModerationRules(testComment, testPageId || null);
      setTestResult(result?.data || null);
    } catch (error) {
      setTestError(errorMessage(error));
    } finally {
      setTestLoading(false);
    }
  }

  const inputClass = 'w-full rounded-lg border border-white/15 bg-slate-950/60 px-3 py-2.5 text-sm text-white outline-none placeholder:text-slate-600 focus:border-cyan-400';
  const selectClass = 'w-full rounded-lg border border-white/15 bg-slate-950/60 px-3 py-2.5 text-sm text-white outline-none focus:border-cyan-400';

  return (
    <AppShell activePage="rules">
      <section className="w-full">
        <div className="flex flex-col gap-5 border-b border-white/10 pb-7 md:flex-row md:items-end md:justify-between">
          <div>
            <p className="mb-3 text-xs font-semibold uppercase tracking-[0.24em] text-cyan-300">Manual moderation</p>
            <h1 className="text-3xl font-semibold tracking-tight text-white sm:text-4xl">Rule engine</h1>
            <p className="mt-3 max-w-2xl text-sm leading-6 text-slate-400">
              Active rules produce a recommended action only. No Facebook hide or delete action is performed.
            </p>
          </div>
          <button
            type="button"
            onClick={startCreate}
            className="rounded-xl bg-cyan-300 px-4 py-2.5 text-sm font-semibold text-slate-950 transition hover:bg-cyan-200"
          >
            Create rule
          </button>
        </div>

        {notice && <p role="status" className="mt-5 rounded-xl border border-emerald-300/20 bg-emerald-300/[0.06] px-4 py-3 text-sm text-emerald-200">{notice}</p>}
        {pageError && <p role="alert" className="mt-5 rounded-xl border border-rose-400/30 bg-rose-400/10 px-4 py-3 text-sm text-rose-200">{pageError}</p>}

        {formOpen && (
          <form onSubmit={saveRule} className="mt-6 rounded-2xl border border-cyan-300/20 bg-cyan-300/[0.035] p-5 sm:p-6">
            <div className="mb-5 flex items-start justify-between gap-4">
              <div>
                <h2 className="text-lg font-semibold text-white">{editingId ? 'Edit rule' : 'Create rule'}</h2>
                <p className="mt-1 text-xs leading-5 text-slate-400">Rules with a higher priority are evaluated first; conflicts at equal priority use delete &gt; hide &gt; review &gt; keep.</p>
              </div>
              <button type="button" onClick={() => setFormOpen(false)} className="text-sm text-slate-400 hover:text-white">Close</button>
            </div>

            {formError && <p role="alert" className="mb-4 rounded-lg border border-rose-400/30 bg-rose-400/10 px-3 py-2 text-sm text-rose-200">{formError}</p>}

            <div className="grid gap-4 md:grid-cols-2">
              <label className="text-xs font-medium text-slate-300">Name
                <input required maxLength={120} className={`${inputClass} mt-2`} value={form.name} onChange={(event) => setForm({ ...form, name: event.target.value })} placeholder="Bangla abusive words" />
              </label>
              <label className="text-xs font-medium text-slate-300">Category (optional)
                <input maxLength={64} className={`${inputClass} mt-2`} value={form.category} onChange={(event) => setForm({ ...form, category: event.target.value })} placeholder="profanity" />
              </label>
              <label className="text-xs font-medium text-slate-300">Rule type
                <select className={`${selectClass} mt-2`} value={form.rule_type} onChange={(event) => setForm({ ...form, rule_type: event.target.value, pattern: TYPE_DEFAULTS[event.target.value] })}>
                  {RULE_TYPES.map((type) => <option key={type.value} value={type.value}>{type.label}</option>)}
                </select>
              </label>
              <label className="text-xs font-medium text-slate-300">Pattern / detector setting
                <input
                  required
                  maxLength={512}
                  className={`${inputClass} mt-2 disabled:cursor-not-allowed disabled:opacity-50`}
                  value={form.pattern}
                  disabled={form.rule_type === 'url' || form.rule_type === 'phone'}
                  onChange={(event) => setForm({ ...form, pattern: event.target.value })}
                  placeholder={form.rule_type === 'regex' ? '~spam.{0,3}~iu' : form.rule_type === 'repeated_text' ? '3 repeated words/phrases' : 'e.g. চোর'}
                />
                <span className="mt-1 block font-normal text-slate-500">
                  {form.rule_type === 'regex'
                    ? 'Use a delimited PCRE pattern. Recursion/backreferences are blocked and execution is capped.'
                    : form.rule_type === 'url' || form.rule_type === 'phone'
                      ? 'Built-in detector; this stored marker is not used as a match pattern.'
                      : form.rule_type === 'repeated_text'
                        ? 'Minimum consecutive repetitions (2–10); default is 3.'
                        : 'Matching is case-insensitive with whole-word boundaries.'}
                </span>
              </label>
              <label className="text-xs font-medium text-slate-300">Recommended action
                <select className={`${selectClass} mt-2`} value={form.action} onChange={(event) => setForm({ ...form, action: event.target.value })}>
                  {ACTIONS.map((action) => <option key={action} value={action}>{action.toUpperCase()}</option>)}
                </select>
              </label>
              <label className="text-xs font-medium text-slate-300">Severity
                <select className={`${selectClass} mt-2`} value={form.severity} onChange={(event) => setForm({ ...form, severity: event.target.value })}>
                  {SEVERITIES.map((severity) => <option key={severity} value={severity}>{severity}</option>)}
                </select>
              </label>
              <label className="text-xs font-medium text-slate-300">Priority
                <input required type="number" min="-100000" max="100000" className={`${inputClass} mt-2`} value={form.priority} onChange={(event) => setForm({ ...form, priority: event.target.value })} />
              </label>
              <label className="text-xs font-medium text-slate-300">Page scope
                <select className={`${selectClass} mt-2`} value={form.facebook_page_id} onChange={(event) => setForm({ ...form, facebook_page_id: event.target.value })}>
                  <option value="">All Pages (global rule)</option>
                  {pages.map((page) => <option key={page.facebook_page_id} value={page.facebook_page_id}>{page.page_name}</option>)}
                </select>
              </label>
              <label className="flex items-center gap-3 text-sm text-slate-300 md:col-span-2">
                <input type="checkbox" checked={form.is_active} onChange={(event) => setForm({ ...form, is_active: event.target.checked })} className="h-4 w-4 accent-cyan-300" />
                Active
              </label>
            </div>

            <div className="mt-5 flex flex-wrap gap-3">
              <button type="submit" disabled={formBusy} className="rounded-xl bg-cyan-300 px-5 py-2.5 text-sm font-semibold text-slate-950 hover:bg-cyan-200 disabled:opacity-50">
                {formBusy ? 'Saving…' : editingId ? 'Save changes' : 'Create rule'}
              </button>
              <button type="button" onClick={() => setFormOpen(false)} className="rounded-xl border border-white/15 px-4 py-2.5 text-sm text-slate-300 hover:text-white">Cancel</button>
            </div>
          </form>
        )}

        <section className="mt-6 rounded-2xl border border-white/10 bg-white/[0.025] p-5 sm:p-6">
          <div>
            <p className="text-xs font-semibold uppercase tracking-[0.2em] text-cyan-300">Rule tester</p>
            <h2 className="mt-2 text-lg font-semibold text-white">Try active rules on sample text</h2>
            <p className="mt-1 text-sm text-slate-400">Testing is read-only; it will not modify a Facebook comment or call Meta.</p>
          </div>
          <form onSubmit={runRuleTest} className="mt-4 grid gap-3 md:grid-cols-[1fr_220px_auto] md:items-end">
            <label className="text-xs font-medium text-slate-300">Comment text
              <textarea required maxLength={20000} rows={3} className={`${inputClass} mt-2 resize-y`} value={testComment} onChange={(event) => setTestComment(event.target.value)} placeholder="আপনাদের product একদম ফালতু" />
            </label>
            <label className="text-xs font-medium text-slate-300">Evaluate for Page
              <select className={`${selectClass} mt-2`} value={testPageId} onChange={(event) => setTestPageId(event.target.value)}>
                <option value="">Global rules only</option>
                {pages.map((page) => <option key={page.facebook_page_id} value={page.facebook_page_id}>{page.page_name}</option>)}
              </select>
            </label>
            <button type="submit" disabled={testLoading || !testComment.trim()} className="rounded-xl border border-cyan-300/30 px-4 py-2.5 text-sm font-semibold text-cyan-100 hover:bg-cyan-300/10 disabled:cursor-not-allowed disabled:opacity-50">
              {testLoading ? 'Testing…' : 'Test rules'}
            </button>
          </form>
          {testError && <p role="alert" className="mt-3 text-sm text-rose-200">{testError}</p>}
          {testResult && (
            <div role="status" className="mt-4 rounded-xl border border-white/10 bg-slate-950/50 p-4">
              {testResult.matched ? (
                <div className="grid gap-3 sm:grid-cols-3">
                  <div><p className="text-[11px] uppercase tracking-wide text-slate-500">Matched rule</p><p className="mt-1 text-sm font-semibold text-white">{testResult.rule_name}</p></div>
                  <div><p className="text-[11px] uppercase tracking-wide text-slate-500">Recommended action</p><span className={`mt-1 inline-flex rounded-full border px-2.5 py-1 text-xs font-bold ${actionClasses(testResult.action)}`}>{testResult.action.toUpperCase()}</span></div>
                  <div><p className="text-[11px] uppercase tracking-wide text-slate-500">Reason</p><p className="mt-1 text-sm text-slate-200">{testResult.match_reason}</p><p className="mt-1 text-xs text-slate-500">{testResult.reason}</p></div>
                </div>
              ) : (
                <p className="text-sm text-slate-300">No active manual rule matched this text. The comment remains unmodified.</p>
              )}
            </div>
          )}
        </section>

        <section className="mt-6 overflow-hidden rounded-2xl border border-white/10 bg-white/[0.02]">
          <div className="border-b border-white/10 px-5 py-4">
            <div className="flex flex-wrap items-end justify-between gap-3">
              <div><h2 className="font-semibold text-white">Rules</h2><p className="mt-1 text-xs text-slate-500">{Number(meta.total || 0).toLocaleString()} total rules · higher priority first</p></div>
              <button type="button" onClick={() => setReloadKey((key) => key + 1)} className="text-xs text-cyan-200 hover:text-white">Refresh</button>
            </div>
            <div className="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
              <input aria-label="Search rules" type="search" maxLength={200} value={search} onChange={(event) => setSearch(event.target.value)} placeholder="Search name, category, pattern" className={inputClass} />
              <select aria-label="Filter by Page" value={pageFilter} onChange={(event) => setPageFilter(event.target.value)} className={selectClass}>
                <option value="all">All Page scopes</option><option value="global">Global rules</option>
                {pages.map((page) => <option key={page.facebook_page_id} value={page.facebook_page_id}>{page.page_name}</option>)}
              </select>
              <select aria-label="Filter by action" value={actionFilter} onChange={(event) => setActionFilter(event.target.value)} className={selectClass}>
                <option value="">All actions</option>{ACTIONS.map((action) => <option key={action} value={action}>{action}</option>)}
              </select>
              <select aria-label="Filter by rule type" value={typeFilter} onChange={(event) => setTypeFilter(event.target.value)} className={selectClass}>
                <option value="">All rule types</option>{RULE_TYPES.map((type) => <option key={type.value} value={type.value}>{type.label}</option>)}
              </select>
              <select aria-label="Sort priority" value={sortPriority} onChange={(event) => setSortPriority(event.target.value)} className={selectClass}>
                <option value="desc">Priority: high to low</option><option value="asc">Priority: low to high</option>
              </select>
            </div>
          </div>

          {loading ? <p className="px-5 py-10 text-center text-sm text-slate-400">Loading moderation rules…</p> : rules.length === 0 ? (
            <p className="px-5 py-10 text-center text-sm text-slate-400">No rules match these filters.</p>
          ) : (
            <div className="overflow-x-auto">
              <table className="min-w-[980px] w-full border-collapse text-left text-sm">
                <thead className="bg-white/[0.03] text-[11px] uppercase tracking-wide text-slate-500"><tr>
                  <th className="px-4 py-3 font-medium">Rule</th><th className="px-4 py-3 font-medium">Type / pattern</th><th className="px-4 py-3 font-medium">Page</th><th className="px-4 py-3 font-medium">Action</th><th className="px-4 py-3 font-medium">Priority</th><th className="px-4 py-3 font-medium">Status</th><th className="px-4 py-3 font-medium">Manage</th>
                </tr></thead>
                <tbody className="divide-y divide-white/[0.06]">
                  {rules.map((rule) => (
                    <tr key={rule.id} className="align-top text-slate-300">
                      <td className="px-4 py-4"><p className="font-medium text-white">{rule.name}</p><p className="mt-1 text-xs text-slate-500">{rule.category || rule.severity}</p></td>
                      <td className="max-w-xs px-4 py-4"><span className="text-xs capitalize text-slate-400">{rule.rule_type.replace('_', ' ')}</span><code title={rule.pattern} className="mt-1 block truncate text-xs text-cyan-100">{rule.pattern}</code></td>
                      <td className="px-4 py-4 text-xs">{rule.page?.page_name || (rule.facebook_page_id ? `Disconnected Page (${rule.facebook_page_id})` : 'All Pages')}</td>
                      <td className="px-4 py-4"><span className={`inline-flex rounded-full border px-2.5 py-1 text-xs uppercase ${actionClasses(rule.action)}`}>{rule.action}</span></td>
                      <td className="px-4 py-4 font-mono text-sm text-white">{rule.priority}</td>
                      <td className="px-4 py-4"><span className={rule.is_active ? 'text-emerald-300' : 'text-slate-500'}>{rule.is_active ? 'Active' : 'Disabled'}</span></td>
                      <td className="px-4 py-4"><div className="flex flex-wrap gap-2">
                        <button type="button" onClick={() => startEdit(rule)} className="text-xs text-cyan-200 hover:text-white">Edit</button>
                        <button type="button" onClick={() => toggleRule(rule)} className="text-xs text-slate-300 hover:text-white">{rule.is_active ? 'Disable' : 'Enable'}</button>
                        <button type="button" onClick={() => removeRule(rule)} className="text-xs text-rose-300 hover:text-rose-100">Delete</button>
                      </div></td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
          {!loading && Number(meta.last_page || 1) > 1 && (
            <Pagination
              page={meta.current_page || page}
              lastPage={meta.last_page}
              total={meta.total || 0}
              onChange={setPage}
              label="rules"
            />
          )}
        </section>

        <p className="mt-4 text-xs leading-5 text-slate-500">
          “Hide” and “Delete” are saved as manual recommendations only. This step never sends either action to Facebook. Rule changes apply the next time a comment is synchronized or received; already stored comments are not backfilled automatically.
        </p>
      </section>
    </AppShell>
  );
}
