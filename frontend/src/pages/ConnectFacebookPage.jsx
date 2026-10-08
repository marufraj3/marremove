import { useState } from 'react';
import { ApiError } from '../api/httpClient.js';
import { connectFacebookPage } from '../api/facebookPageService.js';
import { AppShell } from '../components/AppShell.jsx';
import { friendlyError } from '../utils/errors.js';

export function ConnectFacebookPage() {
  const [connectedPage, setConnectedPage] = useState(null);
  const [message, setMessage] = useState('');
  const [error, setError] = useState('');
  const [isConnecting, setIsConnecting] = useState(false);

  async function handleSubmit(event) {
    event.preventDefault();

    const form = event.currentTarget;
    let tokenForRequest = String(new FormData(form).get('page_access_token') || '').trim();
    if (!tokenForRequest || isConnecting) return;

    // Do not keep the secret in React state; clear the input before starting the request.
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
      const validationMessage =
        requestError instanceof ApiError
          ? requestError.payload?.errors?.page_access_token?.[0]
          : null;
      setError(validationMessage || friendlyError(requestError, 'Could not connect this Facebook Page. Please try again.'));
    } finally {
      tokenForRequest = '';
      form.reset();
      setIsConnecting(false);
    }
  }

  return (
    <AppShell activePage="pages">
      <section className="w-full max-w-xl">
        <a href="/facebook/pages" className="mb-5 inline-flex text-xs font-semibold text-cyan-200 hover:text-white">← Facebook Pages</a>
        <p className="mb-4 text-xs font-semibold uppercase tracking-[0.24em] text-cyan-300">
          Facebook Page connection
        </p>
        <h1 className="text-3xl font-semibold tracking-tight text-white sm:text-4xl">
          Connect a Facebook Page
        </h1>
        <p className="mt-4 text-base leading-7 text-slate-400">
          Enter a Page Access Token. The backend validates it with Meta, saves the Page,
          and returns Page details only.
        </p>

        <form onSubmit={handleSubmit} className="mt-8 space-y-5">
          <div>
            <label
              htmlFor="page-access-token"
              className="mb-2 block text-sm font-medium text-slate-200"
            >
              Facebook Page Access Token
            </label>
            <input
              id="page-access-token"
              name="page_access_token"
              type="password"
              autoComplete="off"
              autoCapitalize="none"
              spellCheck="false"
              required
              maxLength={4096}
              placeholder="Paste the Page Access Token"
              className="w-full rounded-xl border border-white/15 bg-slate-900 px-4 py-3 text-sm text-white outline-none transition placeholder:text-slate-600 focus:border-cyan-400 focus:ring-2 focus:ring-cyan-400/20 disabled:opacity-60"
              disabled={isConnecting}
            />
            <p className="mt-2 text-xs leading-5 text-slate-500">
              It is not saved to browser storage or returned by the API. Browser DevTools
              can still inspect the submitted network request; use HTTPS outside local development.
            </p>
          </div>

          <button
            type="submit"
            disabled={isConnecting}
            className="inline-flex min-h-11 items-center justify-center rounded-xl bg-cyan-300 px-5 py-3 text-sm font-semibold text-slate-950 transition hover:bg-cyan-200 disabled:cursor-not-allowed disabled:opacity-50"
          >
            {isConnecting ? 'Connecting…' : 'Connect Page'}
          </button>
        </form>

        <p className="mt-6 rounded-lg border border-white/10 bg-white/[0.03] px-4 py-3 text-xs leading-5 text-slate-400">
          This connection is scoped to the signed-in app user. It does not use Facebook
          login. The API requires an authenticated app session.
        </p>

        {error && (
          <div
            role="alert"
            className="mt-5 rounded-xl border border-rose-400/30 bg-rose-400/10 px-4 py-3 text-sm text-rose-200"
          >
            {error}
          </div>
        )}

        {connectedPage && (
          <div
            role="status"
            className="mt-5 rounded-2xl border border-emerald-300/20 bg-emerald-300/[0.06] p-5"
          >
            <p className="text-sm font-medium text-emerald-200">{message}</p>
            <div className="mt-4 flex items-center gap-4">
              {connectedPage.page_picture_url && (
                <img
                  src={connectedPage.page_picture_url}
                  alt=""
                  referrerPolicy="no-referrer"
                  className="h-12 w-12 rounded-full bg-slate-800 object-cover"
                />
              )}
              <div className="min-w-0">
                <h2 className="truncate font-semibold text-white">
                  {connectedPage.page_name}
                </h2>
                <p className="mt-1 text-xs text-slate-400">
                  Page ID: <span className="font-mono">{connectedPage.facebook_page_id}</span>
                </p>
                {(connectedPage.page_username || connectedPage.page_category) && (
                  <p className="mt-1 text-xs text-slate-500">
                    {[connectedPage.page_username, connectedPage.page_category]
                      .filter(Boolean)
                      .join(' · ')}
                  </p>
                )}
              </div>
            </div>
          </div>
        )}
      </section>
    </AppShell>
  );
}
