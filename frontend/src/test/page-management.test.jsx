import { beforeEach, describe, expect, it, vi } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

const mocks = vi.hoisted(() => ({
  listFacebookPages: vi.fn(),
  syncFacebookPage: vi.fn(),
  disconnectFacebookPage: vi.fn(),
  getDashboardOverview: vi.fn(),
}));

vi.mock('../api/facebookPageService.js', () => ({
  listFacebookPages: mocks.listFacebookPages,
  syncFacebookPage: mocks.syncFacebookPage,
  disconnectFacebookPage: mocks.disconnectFacebookPage,
}));
vi.mock('../api/dashboardService.js', () => ({ getDashboardOverview: mocks.getDashboardOverview }));

import { FacebookPagesPage } from '../pages/FacebookPagesPage.jsx';
import { PageDetailPage } from '../pages/PageDetailPage.jsx';

const page = {
  id: 9,
  facebook_page_id: '99887766',
  page_name: 'Northwind Market',
  page_username: 'northwind',
  page_category: 'Retail',
  is_active: true,
  token_status: 'connected',
  page_access_token: 'DO_NOT_RENDER_PAGE_TOKEN',
  comments_count: 26,
  webhook_status: 'received',
  webhook_last_received_at: '2026-10-08T08:30:00Z',
  sync_status: 'idle',
  last_synced_at: null,
  posts_synced_count: 3,
  comments_synced_count: 26,
};

beforeEach(() => {
  mocks.listFacebookPages.mockReset().mockResolvedValue({ data: [page] });
  mocks.syncFacebookPage.mockReset().mockResolvedValue({ message: 'Sync queued.', data: { sync_status: 'queued' } });
  mocks.disconnectFacebookPage.mockReset().mockResolvedValue({ message: 'Disconnected.', data: { ...page, is_active: false, token_status: 'disconnected' } });
  mocks.getDashboardOverview.mockReset().mockResolvedValue({ data: { page: { id: 9, page_name: page.page_name, facebook_page_id: page.facebook_page_id, is_active: true }, stats: { comments_today: 3, review_required: 2, hidden_comments: 1, failed_actions: 0 }, recent_activity: [], recent_actions: [] } });
});

describe('Facebook Page management', () => {
  it('shows Page status without rendering its Page Access Token', async () => {
    render(<FacebookPagesPage />);
    expect(await screen.findByRole('heading', { name: 'Northwind Market' })).toBeInTheDocument();
    expect(screen.getByText('Connected')).toBeInTheDocument();
    expect(screen.getByText('26')).toBeInTheDocument();
    expect(screen.queryByText('DO_NOT_RENDER_PAGE_TOKEN')).not.toBeInTheDocument();
    expect(screen.getByRole('link', { name: /Connect Page/ })).toHaveAttribute('href', '/facebook/pages/connect');
  });

  it('queues a Page sync through the Laravel API', async () => {
    const user = userEvent.setup();
    render(<FacebookPagesPage />);
    await screen.findByRole('heading', { name: 'Northwind Market' });
    await user.click(screen.getByRole('button', { name: 'Sync now' }));
    await waitFor(() => expect(mocks.syncFacebookPage).toHaveBeenCalledWith(9));
  });

  it('requires confirmation before disconnecting and retains Page history', async () => {
    const user = userEvent.setup();
    render(<FacebookPagesPage />);
    await screen.findByRole('heading', { name: 'Northwind Market' });
    await user.click(screen.getByRole('button', { name: 'Disconnect' }));
    expect(screen.getByRole('dialog')).toHaveTextContent('Existing comments, decisions, and audit history will be kept');
    expect(mocks.disconnectFacebookPage).not.toHaveBeenCalled();
    await user.click(screen.getByRole('button', { name: 'Disconnect Page' }));
    await waitFor(() => expect(mocks.disconnectFacebookPage).toHaveBeenCalledWith(9));
  });

  it('loads a Page-specific dashboard using the local Page record id', async () => {
    render(<PageDetailPage pageId="9" />);
    expect((await screen.findAllByRole('heading', { name: 'Northwind Market' })).length).toBeGreaterThan(0);
    expect(mocks.getDashboardOverview).toHaveBeenCalledWith(
      { page_id: '9' },
      expect.objectContaining({ signal: expect.any(AbortSignal) }),
    );
    expect(screen.getByText('Page token: connected')).toBeInTheDocument();
  });
});
