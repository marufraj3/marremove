import { useState } from 'react';
import { ApiError } from '../api/httpClient.js';
import {
  connectFacebookPage,
  discoverManagedFacebookPages,
  importSelectedManagedFacebookPages,
} from '../api/facebookPageService.js';
import { AppShell } from '../components/AppShell.jsx';
import { Badge, Panel } from '../components/ui.jsx';
import { friendlyError } from '../utils/errors.js';

export function ConnectFacebookPage() {
  const [connectedPage, setConnectedPage] = useState(null);
  const [message, setMessage] = useState('');
  const [error, setError] = useState('');
  const [isConnecting, setIsConnecting] = useState(false);
  const [discovery, setDiscovery] = useState(null);
  const [selectedPageIds, setSelectedPageIds] = useState(() => new Set());
  const [discoveryError, setDiscoveryError] = useState('');
  const [importResult, setImportResult] = useState(null);
  const [importError, setImportError] = useState('');
  const [isDiscovering, setIsDiscovering] = useState(false);
  const [isImporting, setIsImporting] = useState(false);

  async function handleSubmit(event) {
    event.preventDefault();

    const form = event.currentTarget;
    let tokenForRequest = String(new FormData(form).get('page_access_token') || '').trim();
    if (!tokenForRequest || isConnecting) return;

    form.reset();
    setConnectedPage(null);
    setMessage('');
    setError('');
    setIsConnecting(true);

    try {
      const result = await connectFacebookPage(tokenForRequest);
      setConnectedPage(result.data);
      setMessage(result.message || 'Facebook Page connected successfully.');
    } catch (requestError) {
      const validationMessage = requestError instanceof ApiError
        ? requestError.payload?.errors?.page_access_token?.[0]
        : null;
      setError(validationMessage || friendlyError(requestError, 'Could not connect this Facebook Page. Please try again.'));
    } finally {
      tokenForRequest = '';
      form.reset();
      setIsConnecting(false);
    }
  }

  async function handleDiscoverySubmit(event) {
    event.preventDefault();

    const form = event.currentTarget;
    let tokenForRequest = String(new FormData(form).get('user_access_token') || '').trim();
    if (!tokenForRequest || isDiscovering || isImporting) return;

    form.reset();
    setDiscovery(null);
    setSelectedPageIds(new Set());
    setDiscoveryError('');
    setImportResult(null);
    setImportError('');
    setIsDiscovering(true);

    try {
      const result = await discoverManagedFacebookPages(tokenForRequest);
      const pages = Array.isArray(result.data?.pages) ? result.data.pages : [];
      setDiscovery({
        importId: result.data?.import_id,
        expiresInSeconds: result.data?.expires_in_seconds,
        pages,
      });
      if (pages.length === 0) {
        setDiscoveryError('Meta did not return any connectable Pages. Check pages_show_list and Page access.');
      }
    } catch (requestError) {
      const validationMessage = requestError instanceof ApiError
        ? requestError.payload?.errors?.user_access_token?.[0]
        : null;
      setDiscoveryError(validationMessage || friendlyError(requestError, 'Could not list Pages. Check the User Access Token and pages_show_list permission.'));
    } finally {
      tokenForRequest = '';
      form.reset();
      setIsDiscovering(false);
    }
  }

  async function handleSelectedImport() {
    if (!discovery?.importId || selectedPageIds.size === 0 || isImporting || isDiscovering) return;

    setImportResult(null);
    setImportError('');
    setIsImporting(true);

    try {
      const result = await importSelectedManagedFacebookPages(discovery.importId, [...selectedPageIds]);
      setImportResult({
        message: result.message || 'Page import finished.',
        pages: Array.isArray(result.data?.pages) ? result.data.pages : [],
      });
      setDiscovery(null);
      setSelectedPageIds(new Set());
    } catch (requestError) {
      if (requestError instanceof ApiError && requestError.status === 410) {
        setDiscovery(null);
        setSelectedPageIds(new Set());
        setDiscoveryError('This Page list expired. Enter the User Access Token and find the Pages again.');
      } else {
        setImportError(friendlyError(requestError, 'Could not connect the selected Pages. Please try again.'));
      }
    } finally {
      setIsImporting(false);
    }
  }

  function togglePage(pageId) {
    setSelectedPageIds((current) => {
      const next = new Set(current);
      if (next.has(pageId)) next.delete(pageId);
      else next.add(pageId);
      return next;
    });
  }

  function toggleAllPages() {
    const pageIds = discovery?.pages.map((page) => page.facebook_page_id) || [];
    const allSelected = pageIds.length > 0 && pageIds.every((pageId) => selectedPageIds.has(pageId));
    setSelectedPageIds(allSelected ? new Set() : new Set(pageIds));
  }

  const discoveredPageIds = discovery?.pages.map((page) => page.facebook_page_id) || [];
  const allPagesSelected = discoveredPageIds.length > 0
    && discoveredPageIds.every((pageId) => selectedPageIds.has(pageId));

  return (
    <AppShell activePage="pages">
      <section className="w-full max-w-2xl">
        <a href="/facebook/pages" className="mb-5 inline-flex text-xs font-semibold text-cyan-200 hover:text-white">← Facebook Pages</a>
        <p className="mb-4 text-xs font-semibold uppercase tracking-[0.24em] text-cyan-300">Facebook Page connection</p>
        <h1 className="text-3xl font-semibold tracking-tight text-white sm:text-4xl">Connect Facebook Pages</h1>
        <p className="mt-4 text-base leading-7 text-slate-400">
          Connect one Page with its Page Access Token, or discover Pages using one User Access Token and choose which ones to connect.
        </p>

        <Panel className="mt-8 p-5 sm:p-6">
          <p className="text-[10px] font-semibold uppercase tracking-[0.2em] text-cyan-300">Single Page</p>
          <h2 className="mt-2 text-lg font-semibold text-white">Connect one Page</h2>
          <p className="mt-2 text-sm leading-6 text-slate-400">Enter a Page Access Token. The backend validates it with Meta, saves the Page, and returns Page details only.</p>

          <form onSubmit={handleSubmit} className="mt-5 space-y-4">
            <div>
              <label htmlFor="page-access-token" className="mb-2 block text-sm font-medium text-slate-200">Facebook Page Access Token</label>
              <input
                id="page-access-token"
                name="page_access_token"
                type="password"
                autoComplete="off"
                autoCapitalize="none"
                spellCheck="false"
                required
                maxLength={4096}
                placeholder="Paste this Page's Access Token"
                className="w-full rounded-xl border border-white/15 bg-slate-900 px-4 py-3 text-sm text-white outline-none transition placeholder:text-slate-600 focus:border-cyan-400 focus:ring-2 focus:ring-cyan-400/20 disabled:opacity-60"
                disabled={isConnecting}
              />
              <p className="mt-2 text-xs leading-5 text-slate-500">The token is not saved in browser storage or returned by the API. Browser DevTools can inspect the submitted request; use HTTPS.</p>
            </div>

            <button
              type="submit"
              disabled={isConnecting}
              className="inline-flex min-h-11 items-center justify-center rounded-xl bg-cyan-300 px-5 py-3 text-sm font-semibold text-slate-950 transition hover:bg-cyan-200 disabled:cursor-not-allowed disabled:opacity-50"
            >
              {isConnecting ? 'Connecting…' : 'Connect Page'}
            </button>
          </form>

          {error && <div role="alert" className="mt-5 rounded-xl border border-rose-400/30 bg-rose-400/10 px-4 py-3 text-sm text-rose-200">{error}</div>}

          {connectedPage && (
            <div role="status" className="mt-5 rounded-2xl border border-emerald-300/20 bg-emerald-300/[0.06] p-5">
              <p className="text-sm font-medium text-emerald-200">{message}</p>
              <div className="mt-4 flex items-center gap-4">
                {connectedPage.page_picture_url && <img src={connectedPage.page_picture_url} alt="" referrerPolicy="no-referrer" className="h-12 w-12 rounded-full bg-slate-800 object-cover" />}
                <div className="min-w-0">
                  <h3 className="truncate font-semibold text-white">{connectedPage.page_name}</h3>
                  <p className="mt-1 text-xs text-slate-400">Page ID: <span className="font-mono">{connectedPage.facebook_page_id}</span></p>
                  {(connectedPage.page_username || connectedPage.page_category) && <p className="mt-1 text-xs text-slate-500">{[connectedPage.page_username, connectedPage.page_category].filter(Boolean).join(' · ')}</p>}
                </div>
              </div>
            </div>
          )}
        </Panel>

        <Panel className="mt-5 p-5 sm:p-6">
          <p className="text-[10px] font-semibold uppercase tracking-[0.2em] text-cyan-300">Multiple Pages</p>
          <h2 className="mt-2 text-lg font-semibold text-white">Choose Pages from one User Access Token</h2>
          <p className="mt-2 text-sm leading-6 text-slate-400">
            First, paste a User Access Token and find its Pages. Then select only the Pages you want to connect. Marremove uses each selected Page's token on the server; it never returns Page tokens to the browser. The User Access Token is not stored. The token must have <code className="text-cyan-100">pages_show_list</code> and the account must have access to the Pages.
          </p>

          <form onSubmit={handleDiscoverySubmit} className="mt-5 space-y-4">
            <div>
              <label htmlFor="user-access-token" className="mb-2 block text-sm font-medium text-slate-200">User Access Token</label>
              <input
                id="user-access-token"
                name="user_access_token"
                type="password"
                autoComplete="off"
                autoCapitalize="none"
                spellCheck="false"
                required
                maxLength={4096}
                placeholder="Paste the User Access Token"
                className="w-full rounded-xl border border-white/15 bg-slate-900 px-4 py-3 text-sm text-white outline-none transition placeholder:text-slate-600 focus:border-cyan-400 focus:ring-2 focus:ring-cyan-400/20 disabled:opacity-60"
                disabled={isDiscovering || isImporting}
              />
              <p className="mt-2 text-xs leading-5 text-slate-500">Use HTTPS. Browser DevTools can inspect the request body. The discovery list and encrypted Page credentials expire after five minutes.</p>
            </div>

            <button
              type="submit"
              disabled={isDiscovering || isImporting}
              className="inline-flex min-h-11 items-center justify-center rounded-xl bg-cyan-300 px-5 py-3 text-sm font-semibold text-slate-950 transition hover:bg-cyan-200 disabled:cursor-not-allowed disabled:opacity-50"
            >
              {isDiscovering ? 'Finding Pages…' : 'Find Pages'}
            </button>
          </form>

          <p className="mt-4 rounded-xl border border-amber-300/10 bg-amber-300/[0.025] p-3 text-xs leading-5 text-amber-100/70">
            Connecting a Page does not grant comment-reading access. Comment sync may still require Meta approval for <code>pages_read_engagement</code> and <code>pages_read_user_content</code>.
          </p>

          {discoveryError && <div role="alert" className="mt-4 rounded-xl border border-rose-400/30 bg-rose-400/10 px-4 py-3 text-sm text-rose-200">{discoveryError}</div>}

          {discovery?.pages?.length > 0 && (
            <div className="mt-6">
              <div className="flex flex-wrap items-center justify-between gap-3">
                <div>
                  <h3 className="text-sm font-semibold text-white">Select Pages to connect</h3>
                  <p className="mt-1 text-xs text-slate-500">{selectedPageIds.size} of {discovery.pages.length} selected · expires in about {Math.ceil((discovery.expiresInSeconds || 300) / 60)} minutes</p>
                </div>
                <button
                  type="button"
                  onClick={toggleAllPages}
                  disabled={isImporting}
                  className="text-xs font-semibold text-cyan-200 hover:text-white disabled:opacity-50"
                >
                  {allPagesSelected ? 'Clear selection' : 'Select all'}
                </button>
              </div>

              <ul className="mt-3 space-y-2">
                {discovery.pages.map((page) => (
                  <li key={page.facebook_page_id}>
                    <label className="flex cursor-pointer items-center gap-3 rounded-xl border border-white/[0.08] bg-white/[0.025] p-3 transition hover:border-cyan-300/25 has-[:checked]:border-cyan-300/30 has-[:checked]:bg-cyan-300/[0.04]">
                      <input
                        type="checkbox"
                        checked={selectedPageIds.has(page.facebook_page_id)}
                        onChange={() => togglePage(page.facebook_page_id)}
                        disabled={isImporting}
                        className="h-4 w-4 accent-cyan-300"
                      />
                      <span className="min-w-0 flex-1">
                        <span className="block truncate text-sm font-medium text-slate-200">{page.page_name}</span>
                        <span className="mt-1 block text-xs text-slate-500">Page ID: <span className="font-mono">{page.facebook_page_id}</span></span>
                      </span>
                    </label>
                  </li>
                ))}
              </ul>

              <button
                type="button"
                onClick={handleSelectedImport}
                disabled={selectedPageIds.size === 0 || isImporting || isDiscovering}
                className="mt-4 inline-flex min-h-11 items-center justify-center rounded-xl bg-cyan-300 px-5 py-3 text-sm font-semibold text-slate-950 transition hover:bg-cyan-200 disabled:cursor-not-allowed disabled:opacity-50"
              >
                {isImporting ? 'Connecting selected Pages…' : `Connect selected Pages (${selectedPageIds.size})`}
              </button>
            </div>
          )}

          {importError && <div role="alert" className="mt-4 rounded-xl border border-rose-400/30 bg-rose-400/10 px-4 py-3 text-sm text-rose-200">{importError}</div>}

          {importResult && (
            <div className="mt-5" aria-live="polite">
              <p role="status" className="text-sm font-semibold text-white">{importResult.message}</p>
              <ul className="mt-3 space-y-2">
                {importResult.pages.map((page, index) => (
                  <li key={`${page.facebook_page_id || page.page_name}-${index}`} className="flex flex-col gap-2 rounded-xl border border-white/[0.07] bg-white/[0.025] p-3 sm:flex-row sm:items-center sm:justify-between">
                    <div className="min-w-0">
                      <p className="truncate text-sm font-medium text-slate-200">{page.page_name}</p>
                      {page.message && <p className="mt-1 text-xs leading-5 text-slate-500">{page.message}</p>}
                    </div>
                    <Badge tone={page.status === 'connected' ? 'success' : 'danger'}>{page.status}</Badge>
                  </li>
                ))}
              </ul>
            </div>
          )}
        </Panel>

        <p className="mt-5 rounded-lg border border-white/10 bg-white/[0.03] px-4 py-3 text-xs leading-5 text-slate-400">
          Page connection is scoped to the signed-in Marremove account. The submitted User Access Token is not persisted; encrypted temporary Page credentials are deleted after the selection is processed or expire.
        </p>
      </section>
    </AppShell>
  );
}
