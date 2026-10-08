import { beforeEach, describe, expect, it, vi } from 'vitest';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

const mocks = vi.hoisted(() => ({
  getDashboardOverview: vi.fn(),
  listFacebookPages: vi.fn(),
}));

vi.mock('../api/dashboardService.js', () => ({ getDashboardOverview: mocks.getDashboardOverview }));
vi.mock('../api/facebookPageService.js', () => ({ listFacebookPages: mocks.listFacebookPages }));

import { ApiError } from '../api/httpClient.js';
import { DashboardPage } from '../pages/DashboardPage.jsx';

const dashboard = {
  page: null,
  stats: { connected_pages: 2, comments_today: 15, comments_this_week: 84, clean_comments: 51, manual_rule_matches: 12, ai_moderated: 34, review_required: 7, hidden_comments: 4, deleted_comments: 1, failed_actions: 2 },
  charts: { decisions: [{ label: 'review', value: 10 }, { label: 'keep', value: 6 }], methods: [{ label: 'ai', value: 13 }], categories: [{ label: 'spam', value: 8 }] },
  recent_activity: [{ id: 10, message: 'Recent comment text', page_name: 'Northwind', category: 'spam', method: 'ai', decision: 'review', confidence: 0.87, created_at: '2026-10-08T08:00:00Z' }],
  recent_actions: [],
  range: { generated_at: '2026-10-08T08:00:00Z' },
};

beforeEach(() => {
  mocks.getDashboardOverview.mockReset().mockResolvedValue({ data: dashboard });
  mocks.listFacebookPages.mockReset().mockResolvedValue({ data: [{ id: 7, page_name: 'Northwind', is_active: true }] });
});

describe('dashboard overview', () => {
  it('renders server-provided statistics, charts, and recent activity', async () => {
    render(<DashboardPage />);
    expect(await screen.findByRole('heading', { name: 'Dashboard' })).toBeInTheDocument();
    expect(await screen.findByText('Recent comment text')).toBeInTheDocument();
    expect(screen.getByText('Comments today')).toBeInTheDocument();
    expect(screen.getByText('15')).toBeInTheDocument();
    expect(screen.getByText('Decision breakdown')).toBeInTheDocument();
    expect(mocks.getDashboardOverview).toHaveBeenCalledWith({ page_id: '' });
  });

  it('requests a Page-scoped dashboard when the filter changes', async () => {
    const user = userEvent.setup();
    render(<DashboardPage />);
    await screen.findByRole('heading', { name: 'Dashboard' });
    await user.selectOptions(screen.getByRole('combobox', { name: 'Filter dashboard by Page' }), '7');
    await waitFor(() => expect(mocks.getDashboardOverview).toHaveBeenLastCalledWith({ page_id: '7' }));
  });

  it('replaces server errors with a friendly retry message', async () => {
    mocks.getDashboardOverview.mockRejectedValue(new ApiError('Internal Server Error: stack trace', { status: 500, payload: { message: 'Traceback secret' } }));
    render(<DashboardPage />);
    expect(await screen.findByRole('alert')).toHaveTextContent('service is having trouble');
    expect(screen.queryByText(/Traceback secret|stack trace/)).not.toBeInTheDocument();
  });
});
