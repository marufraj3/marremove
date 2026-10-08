import { useEffect, useState } from 'react';

export function PageHeader({ eyebrow, title, description, actions }) {
  return (
    <div className="mb-7 flex flex-col gap-5 border-b border-white/[0.08] pb-6 md:flex-row md:items-end md:justify-between">
      <div className="min-w-0">
        {eyebrow && <p className="mb-2.5 text-[10px] font-semibold uppercase tracking-[0.22em] text-cyan-300">{eyebrow}</p>}
        <h1 className="text-2xl font-semibold tracking-tight text-white sm:text-3xl">{title}</h1>
        {description && <p className="mt-2 max-w-3xl text-sm leading-6 text-slate-400">{description}</p>}
      </div>
      {actions && <div className="flex shrink-0 flex-wrap items-center gap-2">{actions}</div>}
    </div>
  );
}

export function Panel({ children, className = '', ...props }) {
  return <section className={`rounded-2xl border border-white/[0.08] bg-white/[0.025] ${className}`} {...props}>{children}</section>;
}

export function Button({ children, variant = 'secondary', className = '', type = 'button', ...props }) {
  const variants = {
    primary: 'border border-cyan-300/20 bg-cyan-300 text-slate-950 hover:bg-cyan-200',
    secondary: 'border border-white/10 bg-white/[0.035] text-slate-200 hover:border-white/20 hover:bg-white/[0.07]',
    danger: 'border border-rose-300/20 bg-rose-300/[0.08] text-rose-200 hover:bg-rose-300/[0.15]',
    subtle: 'border border-transparent text-slate-300 hover:bg-white/[0.06] hover:text-white',
  };
  return (
    <button type={type} className={`inline-flex min-h-10 items-center justify-center gap-2 rounded-xl px-4 py-2 text-sm font-semibold transition disabled:cursor-not-allowed disabled:opacity-50 ${variants[variant] || variants.secondary} ${className}`} {...props}>
      {children}
    </button>
  );
}

const badgeStyles = {
  success: 'border-emerald-300/20 bg-emerald-300/[0.08] text-emerald-200',
  warning: 'border-amber-300/20 bg-amber-300/[0.08] text-amber-200',
  danger: 'border-rose-300/20 bg-rose-300/[0.08] text-rose-200',
  info: 'border-cyan-300/20 bg-cyan-300/[0.08] text-cyan-200',
  neutral: 'border-white/10 bg-white/[0.04] text-slate-300',
};

export function Badge({ children, tone = 'neutral', className = '' }) {
  return <span className={`inline-flex items-center rounded-full border px-2.5 py-1 text-[11px] font-semibold capitalize ${badgeStyles[tone] || badgeStyles.neutral} ${className}`}>{children}</span>;
}

export function LoadingState({ label = 'Loading…', rows = 1 }) {
  return (
    <div role="status" aria-label={label} className="space-y-3 py-4">
      <span className="sr-only">{label}</span>
      {Array.from({ length: rows }, (_, index) => <div key={index} className="h-12 animate-pulse rounded-xl bg-white/[0.045]" />)}
    </div>
  );
}

export function EmptyState({ title, description, action }) {
  return (
    <div className="px-5 py-12 text-center">
      <span aria-hidden="true" className="mx-auto grid h-11 w-11 place-items-center rounded-2xl border border-white/10 bg-white/[0.035] text-lg text-slate-500">⌕</span>
      <h3 className="mt-3 text-sm font-semibold text-slate-200">{title}</h3>
      {description && <p className="mx-auto mt-1.5 max-w-md text-xs leading-5 text-slate-500">{description}</p>}
      {action && <div className="mt-4">{action}</div>}
    </div>
  );
}

export function ErrorState({ message, onRetry }) {
  return (
    <div role="alert" className="rounded-xl border border-rose-300/15 bg-rose-300/[0.06] px-4 py-3 text-sm text-rose-100">
      <p>{message}</p>
      {onRetry && <button type="button" onClick={onRetry} className="mt-2 text-xs font-semibold text-rose-200 underline decoration-rose-200/40 underline-offset-4 hover:text-white">Try again</button>}
    </div>
  );
}

export function StatCard({ label, value, hint, icon = '·', tone = 'cyan' }) {
  const iconStyles = {
    cyan: 'bg-cyan-300/[0.09] text-cyan-200',
    amber: 'bg-amber-300/[0.09] text-amber-200',
    rose: 'bg-rose-300/[0.09] text-rose-200',
    emerald: 'bg-emerald-300/[0.09] text-emerald-200',
  };
  return (
    <article className="rounded-2xl border border-white/[0.08] bg-white/[0.025] p-4 sm:p-5">
      <div className="flex items-start justify-between gap-3">
        <div className="min-w-0">
          <p className="text-xs font-medium text-slate-400">{label}</p>
          <p className="mt-2 text-2xl font-semibold tracking-tight text-white sm:text-3xl">{typeof value === 'number' ? value.toLocaleString() : (value ?? '—')}</p>
        </div>
        <span aria-hidden="true" className={`grid h-9 w-9 shrink-0 place-items-center rounded-xl text-base ${iconStyles[tone] || iconStyles.cyan}`}>{icon}</span>
      </div>
      {hint && <p className="mt-3 text-[11px] leading-5 text-slate-500">{hint}</p>}
    </article>
  );
}

export function Pagination({ page, lastPage, total, onChange, label = 'records' }) {
  const current = Math.max(1, Number(page) || 1);
  const last = Math.max(1, Number(lastPage) || 1);
  return (
    <div className="flex flex-wrap items-center justify-between gap-3 border-t border-white/[0.07] px-4 py-3">
      <p className="text-xs text-slate-500"><span className="font-medium text-slate-300">{Number(total || 0).toLocaleString()}</span> {label} · Page {current} of {last}</p>
      <div className="flex items-center gap-2">
        <Button onClick={() => onChange(Math.max(1, current - 1))} disabled={current <= 1} className="min-h-9 px-3 text-xs">Previous</Button>
        <Button onClick={() => onChange(Math.min(last, current + 1))} disabled={current >= last} className="min-h-9 px-3 text-xs">Next</Button>
      </div>
    </div>
  );
}

export function ConfirmDialog({ title, message, confirmLabel = 'Confirm', cancelLabel = 'Cancel', danger = false, busy = false, onConfirm, onCancel }) {
  useEffect(() => {
    function onKeyDown(event) {
      if (event.key === 'Escape' && !busy) onCancel();
    }
    window.addEventListener('keydown', onKeyDown);
    return () => window.removeEventListener('keydown', onKeyDown);
  }, [busy, onCancel]);

  return (
    <div className="fixed inset-0 z-[70] grid place-items-center bg-slate-950/75 p-4 backdrop-blur-sm" onMouseDown={(event) => { if (event.target === event.currentTarget && !busy) onCancel(); }}>
      <section role="dialog" aria-modal="true" aria-labelledby="confirm-title" className="w-full max-w-md rounded-2xl border border-white/10 bg-[#101827] p-5 shadow-2xl sm:p-6">
        <div className="flex gap-3">
          <span aria-hidden="true" className={`grid h-9 w-9 shrink-0 place-items-center rounded-xl ${danger ? 'bg-rose-300/10 text-rose-200' : 'bg-amber-300/10 text-amber-200'}`}>!</span>
          <div className="min-w-0">
            <h2 id="confirm-title" className="font-semibold text-white">{title}</h2>
            <p className="mt-2 text-sm leading-6 text-slate-400">{message}</p>
          </div>
        </div>
        <div className="mt-6 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
          <Button type="button" onClick={onCancel} disabled={busy}> {cancelLabel} </Button>
          <Button type="button" variant={danger ? 'danger' : 'primary'} onClick={onConfirm} disabled={busy}>{busy ? 'Working…' : confirmLabel}</Button>
        </div>
      </section>
    </div>
  );
}

export function ToastHost() {
  const [toasts, setToasts] = useState([]);

  useEffect(() => {
    function addToast(event) {
      const toast = { id: `${Date.now()}-${Math.random()}`, type: event.detail?.type || 'success', message: event.detail?.message || '' };
      setToasts((current) => [...current.slice(-2), toast]);
      window.setTimeout(() => setToasts((current) => current.filter((item) => item.id !== toast.id)), 4200);
    }
    window.addEventListener('marremove:toast', addToast);
    return () => window.removeEventListener('marremove:toast', addToast);
  }, []);

  return (
    <div aria-live="polite" className="pointer-events-none fixed right-4 top-4 z-[80] flex w-[min(92vw,380px)] flex-col gap-2">
      {toasts.map((toast) => (
        <div key={toast.id} role="status" className={`pointer-events-auto rounded-xl border px-4 py-3 text-sm shadow-xl backdrop-blur ${toast.type === 'error' ? 'border-rose-300/20 bg-[#26151b]/95 text-rose-100' : 'border-emerald-300/20 bg-[#11231e]/95 text-emerald-100'}`}>
          {toast.message}
        </div>
      ))}
    </div>
  );
}

export function emitToast(message, type = 'success') {
  if (typeof window !== 'undefined') window.dispatchEvent(new CustomEvent('marremove:toast', { detail: { message, type } }));
}
