import { useEffect, useState } from 'react';
import { getAdminAccess } from './api/adminService.js';
import { AppShell } from './components/AppShell.jsx';
import { Button, LoadingState, PageHeader, Panel, ToastHost } from './components/ui.jsx';
import { friendlyError } from './utils/errors.js';
import { AiModerationSettingsPage } from './pages/AiModerationSettingsPage.jsx';
import { ConnectFacebookPage } from './pages/ConnectFacebookPage.jsx';
import { DashboardPage } from './pages/DashboardPage.jsx';
import { FacebookCommentsPage } from './pages/FacebookCommentsPage.jsx';
import { FacebookPagesPage } from './pages/FacebookPagesPage.jsx';
import { FacebookWebhookStatusPage } from './pages/FacebookWebhookStatusPage.jsx';
import { ModerationActionsPage } from './pages/ModerationActionsPage.jsx';
import { ModerationRulesPage } from './pages/ModerationRulesPage.jsx';
import { ModerationSettingsPage } from './pages/ModerationSettingsPage.jsx';
import { ReviewQueuePage } from './pages/ReviewQueuePage.jsx';
import { SystemSettingsPage } from './pages/SystemSettingsPage.jsx';
import { PageDetailPage } from './pages/PageDetailPage.jsx';
import { CommentDetailPage } from './pages/CommentDetailPage.jsx';

function AdminGate({ children }) {
  const [access, setAccess] = useState({ loading: true, user: null, error: '' });
  const [retry, setRetry] = useState(0);

  useEffect(() => {
    let cancelled = false;
    setAccess({ loading: true, user: null, error: '' });
    getAdminAccess()
      .then((result) => {
        if (!cancelled) setAccess({ loading: false, user: result?.data || null, error: '' });
      })
      .catch((error) => {
        if (!cancelled) setAccess({ loading: false, user: null, error: friendlyError(error, 'Could not verify administrator access.') });
      });
    return () => { cancelled = true; };
  }, [retry]);

  if (access.loading) {
    return (
      <div className="mx-auto max-w-2xl px-5 py-16">
        <Panel className="p-6 sm:p-8">
          <p className="mb-2 text-[10px] font-semibold uppercase tracking-[0.2em] text-cyan-300">Marremove Admin</p>
          <h1 className="text-xl font-semibold text-white">Verifying secure access</h1>
          <LoadingState label="Checking your admin session" rows={3} />
        </Panel>
      </div>
    );
  }

  if (access.error || !access.user) {
    return (
      <div className="mx-auto max-w-xl px-5 py-16">
        <Panel className="p-6 sm:p-8">
          <p className="text-[10px] font-semibold uppercase tracking-[0.2em] text-rose-200">Access check required</p>
          <h1 className="mt-2 text-xl font-semibold text-white">Admin dashboard unavailable</h1>
          <p role="alert" className="mt-3 text-sm leading-6 text-slate-400">{access.error || 'Your sign-in session could not be verified.'}</p>
          <p className="mt-3 text-xs leading-5 text-slate-500">Sign in through the application’s existing authentication flow, then retry. No dashboard data is loaded until the Laravel API confirms administrator access.</p>
          <Button variant="primary" onClick={() => setRetry((value) => value + 1)} className="mt-6">Check access again</Button>
        </Panel>
      </div>
    );
  }

  return children;
}

function NotFoundPage() {
  return (
    <AppShell activePage="dashboard">
      <PageHeader title="Page not found" description="This dashboard route is not available." actions={<Button variant="primary" onClick={() => { window.location.href = '/dashboard'; }}>Go to dashboard</Button>} />
    </AppShell>
  );
}

function routeForPath(path) {
  if (path === '/' || path === '/dashboard') return <DashboardPage />;
  if (path === '/facebook/pages') return <FacebookPagesPage />;
  if (path === '/facebook/pages/connect') return <ConnectFacebookPage />;
  if (path === '/facebook/comments') return <FacebookCommentsPage />;
  if (path === '/moderation/review') return <ReviewQueuePage />;
  if (path === '/moderation/rules') return <ModerationRulesPage />;
  if (path === '/settings/ai' || path === '/moderation/ai') return <AiModerationSettingsPage />;
  if (path === '/settings/moderation') return <ModerationSettingsPage />;
  if (path === '/logs/actions' || path === '/moderation/actions') return <ModerationActionsPage />;
  if (path === '/facebook/webhook' || path === '/facebook/webhooks') return <FacebookWebhookStatusPage />;
  if (path === '/settings/system') return <SystemSettingsPage />;

  const pageMatch = path.match(/^\/facebook\/pages\/(\d+)$/);
  if (pageMatch) return <PageDetailPage key={pageMatch[1]} pageId={pageMatch[1]} />;
  const commentMatch = path.match(/^\/facebook\/comments\/(\d+)$/);
  if (commentMatch) return <CommentDetailPage key={commentMatch[1]} commentId={commentMatch[1]} />;
  return <NotFoundPage />;
}

export default function App() {
  const path = window.location.pathname.replace(/\/+$/, '') || '/';
  const page = routeForPath(path);
  return (
    <AdminGate>
      {page}
      <ToastHost />
    </AdminGate>
  );
}
