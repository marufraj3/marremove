import { useState } from 'react';
import { signOut } from '../api/authService.js';
import { emitToast } from './ui.jsx';
import { friendlyError } from '../utils/errors.js';

const groups = [
  {
    label: 'Workspace',
    links: [
      { href: '/dashboard', label: 'Dashboard', key: 'dashboard', icon: '⌂' },
      { href: '/facebook/pages', label: 'Facebook Pages', key: 'pages', icon: '▣' },
      { href: '/facebook/comments', label: 'Comments', key: 'comments', icon: '☷' },
      { href: '/moderation/review', label: 'Review Queue', key: 'review', icon: '◷' },
    ],
  },
  {
    label: 'Moderation',
    links: [
      { href: '/moderation/rules', label: 'Moderation Rules', key: 'rules', icon: '⌘' },
      { href: '/settings/ai', label: 'AI Settings', key: 'ai', icon: '✳' },
      { href: '/settings/moderation', label: 'Moderation Settings', key: 'moderation-settings', icon: '⚙' },
      { href: '/logs/actions', label: 'Action Logs', key: 'actions', icon: '≋' },
    ],
  },
  {
    label: 'System',
    links: [
      { href: '/facebook/webhook', label: 'Webhook Status', key: 'webhooks', icon: '⌁' },
      { href: '/settings/system', label: 'System Settings', key: 'system-settings', icon: '◈' },
    ],
  },
];

function Navigation({ activePage, onNavigate }) {
  return (
    <nav aria-label="Admin navigation" className="space-y-7">
      {groups.map((group) => (
        <div key={group.label}>
          <p className="mb-2 px-3 text-[10px] font-semibold uppercase tracking-[0.2em] text-slate-500">
            {group.label}
          </p>
          <div className="space-y-1">
            {group.links.map((link) => {
              const active = activePage === link.key;
              return (
                <a
                  key={link.key}
                  href={link.href}
                  aria-current={active ? 'page' : undefined}
                  onClick={onNavigate}
                  className={`group flex min-h-10 items-center gap-3 rounded-xl px-3 py-2 text-sm font-medium transition ${
                    active
                      ? 'bg-cyan-300/[0.12] text-cyan-100 ring-1 ring-inset ring-cyan-300/15'
                      : 'text-slate-400 hover:bg-white/[0.045] hover:text-slate-100'
                  }`}
                >
                  <span aria-hidden="true" className={`grid h-7 w-7 place-items-center rounded-lg text-base ${active ? 'bg-cyan-300/10 text-cyan-200' : 'bg-white/[0.035] text-slate-500 group-hover:text-slate-300'}`}>
                    {link.icon}
                  </span>
                  <span>{link.label}</span>
                  {active && <span aria-hidden="true" className="ml-auto h-1.5 w-1.5 rounded-full bg-cyan-300" />}
                </a>
              );
            })}
          </div>
        </div>
      ))}
    </nav>
  );
}

function Brand() {
  return (
    <a href="/dashboard" className="flex items-center gap-3 rounded-lg focus:outline-none focus-visible:ring-2 focus-visible:ring-cyan-300">
      <span className="grid h-10 w-10 place-items-center rounded-xl bg-cyan-300 text-sm font-black tracking-tight text-slate-950">M</span>
      <span>
        <span className="block text-sm font-bold tracking-[0.16em] text-white">MARREMOVE</span>
        <span className="mt-0.5 block text-[10px] font-medium uppercase tracking-[0.18em] text-slate-500">Moderation console</span>
      </span>
    </a>
  );
}

function SignOutButton({ compact = false }) {
  const [isSigningOut, setIsSigningOut] = useState(false);

  async function handleSignOut() {
    if (isSigningOut) return;
    setIsSigningOut(true);
    try {
      await signOut();
      window.location.reload();
    } catch (error) {
      if (error?.status === 401) {
        window.location.reload();
        return;
      }
      emitToast(friendlyError(error, 'Could not sign out. Please try again.'), 'error');
      setIsSigningOut(false);
    }
  }

  return (
    <button
      type="button"
      onClick={handleSignOut}
      disabled={isSigningOut}
      className={`inline-flex min-h-9 items-center justify-center rounded-xl border border-white/10 px-3 text-xs font-semibold text-slate-300 transition hover:border-white/20 hover:bg-white/[0.06] hover:text-white disabled:cursor-not-allowed disabled:opacity-50 ${compact ? '' : 'w-full'}`}
    >
      {isSigningOut ? 'Signing out…' : 'Sign out'}
    </button>
  );
}

export function AppShell({ children, activePage = 'dashboard' }) {
  const [mobileOpen, setMobileOpen] = useState(false);

  return (
    <div className="min-h-screen bg-[#080d19] text-slate-100">
      <aside className="fixed inset-y-0 left-0 z-30 hidden w-[264px] flex-col border-r border-white/[0.07] bg-[#0b1120] px-4 py-6 lg:flex">
        <div className="px-2"><Brand /></div>
        <div className="mt-9 min-h-0 flex-1 overflow-y-auto px-1 pb-4">
          <Navigation activePage={activePage} />
        </div>
        <div className="rounded-2xl border border-white/[0.07] bg-white/[0.025] p-3.5">
          <p className="text-xs font-semibold text-slate-200">Secure by design</p>
          <p className="mt-1.5 text-[11px] leading-5 text-slate-500">Page credentials stay server-side. Facebook actions are queued by Laravel.</p>
        </div>
        <div className="mt-3"><SignOutButton /></div>
      </aside>

      <div className="min-h-screen lg:pl-[264px]">
        <header className="sticky top-0 z-20 flex min-h-[64px] items-center justify-between border-b border-white/[0.07] bg-[#080d19]/95 px-4 backdrop-blur sm:px-6 lg:hidden">
          <Brand />
          <div className="flex items-center gap-2">
            <SignOutButton compact />
            <button
              type="button"
              aria-label={mobileOpen ? 'Close navigation' : 'Open navigation'}
              aria-expanded={mobileOpen}
              onClick={() => setMobileOpen((open) => !open)}
              className="grid h-10 w-10 place-items-center rounded-xl border border-white/10 text-lg text-slate-200 hover:bg-white/5"
            >
              {mobileOpen ? '×' : '☰'}
            </button>
          </div>
        </header>

        {mobileOpen && (
          <div className="fixed inset-0 z-40 bg-slate-950/80 lg:hidden" onClick={() => setMobileOpen(false)}>
            <aside className="h-full w-[min(88vw,320px)] overflow-y-auto border-r border-white/10 bg-[#0b1120] px-4 py-6 shadow-2xl" onClick={(event) => event.stopPropagation()}>
              <div className="mb-8 flex items-center justify-between px-2">
                <Brand />
                <button type="button" aria-label="Close navigation" onClick={() => setMobileOpen(false)} className="rounded-lg px-2 py-1 text-xl text-slate-400 hover:text-white">×</button>
              </div>
              <Navigation activePage={activePage} onNavigate={() => setMobileOpen(false)} />
            </aside>
          </div>
        )}

        <main className="mx-auto min-h-[calc(100vh-64px)] w-full max-w-[1580px] px-4 py-6 sm:px-6 sm:py-8 lg:px-8 lg:py-10 xl:px-10">
          {children}
        </main>
        <footer className="border-t border-white/[0.06] px-4 py-4 text-center text-[11px] leading-5 text-slate-600 sm:px-6 lg:px-8">
          Marremove Admin · Credentials and moderation decisions remain protected by the Laravel API.
        </footer>
      </div>
    </div>
  );
}
