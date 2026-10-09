import { useState } from 'react';
import { prepareAuthSession, signIn } from '../api/authService.js';
import { ApiError } from '../api/httpClient.js';
import { Button, Panel } from '../components/ui.jsx';
import { friendlyError } from '../utils/errors.js';

export function SignInPage({ onSignedIn }) {
  const [isSubmitting, setIsSubmitting] = useState(false);
  const [error, setError] = useState('');

  async function handleSubmit(event) {
    event.preventDefault();
    if (isSubmitting) return;

    const form = event.currentTarget;
    const formData = new FormData(form);
    const email = String(formData.get('email') || '').trim();
    let password = String(formData.get('password') || '');

    setError('');
    setIsSubmitting(true);

    try {
      await prepareAuthSession();
      await signIn(email, password);
      form.reset();
      onSignedIn?.();
    } catch (requestError) {
      const validationMessage = requestError instanceof ApiError
        ? requestError.payload?.errors?.email?.[0] || requestError.payload?.errors?.password?.[0]
        : null;
      setError(validationMessage || friendlyError(requestError, 'Could not sign in. Check your credentials and try again.'));
    } finally {
      password = '';
      const passwordField = form.elements.namedItem('password');
      if (passwordField) passwordField.value = '';
      setIsSubmitting(false);
    }
  }

  return (
    <main className="grid min-h-screen place-items-center bg-[#080d19] px-4 py-10 text-slate-100">
      <div className="w-full max-w-md">
        <div className="mb-5 flex items-center gap-3 px-1">
          <span aria-hidden="true" className="grid h-10 w-10 place-items-center rounded-xl bg-cyan-300 text-sm font-black tracking-tight text-slate-950">M</span>
          <div>
            <p className="text-sm font-bold tracking-[0.16em] text-white">MARREMOVE</p>
            <p className="mt-0.5 text-[10px] font-medium uppercase tracking-[0.18em] text-slate-500">Moderation console</p>
          </div>
        </div>

        <Panel className="p-6 shadow-2xl shadow-black/20 sm:p-8">
          <p className="text-[10px] font-semibold uppercase tracking-[0.2em] text-cyan-300">Administrator access</p>
          <h1 className="mt-2 text-2xl font-semibold tracking-tight text-white">Sign in</h1>
          <p className="mt-2 text-sm leading-6 text-slate-400">Use the administrator email and password configured for this application.</p>

          <form onSubmit={handleSubmit} className="mt-6 space-y-4">
            <div>
              <label htmlFor="signin-email" className="mb-2 block text-sm font-medium text-slate-200">Email</label>
              <input
                id="signin-email"
                name="email"
                type="email"
                autoComplete="username"
                autoCapitalize="none"
                spellCheck="false"
                maxLength={255}
                required
                disabled={isSubmitting}
                className="w-full rounded-xl border border-white/15 bg-slate-900 px-4 py-3 text-sm text-white outline-none transition placeholder:text-slate-600 focus:border-cyan-400 focus:ring-2 focus:ring-cyan-400/20 disabled:opacity-60"
              />
            </div>

            <div>
              <label htmlFor="signin-password" className="mb-2 block text-sm font-medium text-slate-200">Password</label>
              <input
                id="signin-password"
                name="password"
                type="password"
                autoComplete="current-password"
                required
                maxLength={1024}
                disabled={isSubmitting}
                className="w-full rounded-xl border border-white/15 bg-slate-900 px-4 py-3 text-sm text-white outline-none transition placeholder:text-slate-600 focus:border-cyan-400 focus:ring-2 focus:ring-cyan-400/20 disabled:opacity-60"
              />
            </div>

            {error && <p role="alert" className="rounded-xl border border-rose-300/15 bg-rose-300/[0.06] px-4 py-3 text-sm leading-5 text-rose-100">{error}</p>}

            <Button type="submit" variant="primary" disabled={isSubmitting} className="w-full">
              {isSubmitting ? 'Signing in…' : 'Sign in securely'}
            </Button>
          </form>

          <p className="mt-5 rounded-xl border border-white/[0.07] bg-white/[0.025] p-3 text-xs leading-5 text-slate-500">
            Only server-allowlisted administrator accounts can sign in. Account creation and password resets are handled securely from the server terminal; public registration is disabled.
          </p>
        </Panel>
      </div>
    </main>
  );
}
