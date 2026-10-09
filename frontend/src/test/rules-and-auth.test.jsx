import { beforeEach, describe, expect, it, vi } from 'vitest';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

const mocks = vi.hoisted(() => ({
  listModerationPages: vi.fn(),
  listModerationRules: vi.fn(),
  createModerationRule: vi.fn(),
  updateModerationRule: vi.fn(),
  deleteModerationRule: vi.fn(),
  testModerationRules: vi.fn(),
  getAdminAccess: vi.fn(),
  prepareAuthSession: vi.fn(),
  signIn: vi.fn(),
  signOut: vi.fn(),
  connectFacebookPage: vi.fn(),
  getDashboardOverview: vi.fn(),
}));

vi.mock('../api/moderationRuleService.js', () => ({
  listModerationPages: mocks.listModerationPages,
  listModerationRules: mocks.listModerationRules,
  createModerationRule: mocks.createModerationRule,
  updateModerationRule: mocks.updateModerationRule,
  deleteModerationRule: mocks.deleteModerationRule,
  testModerationRules: mocks.testModerationRules,
}));
vi.mock('../api/adminService.js', () => ({ getAdminAccess: mocks.getAdminAccess }));
vi.mock('../api/authService.js', () => ({
  prepareAuthSession: mocks.prepareAuthSession,
  signIn: mocks.signIn,
  signOut: mocks.signOut,
}));
vi.mock('../api/facebookPageService.js', async (importOriginal) => ({ ...(await importOriginal()), connectFacebookPage: mocks.connectFacebookPage }));
vi.mock('../api/dashboardService.js', () => ({ getDashboardOverview: mocks.getDashboardOverview }));

import { ApiError } from '../api/httpClient.js';
import { ModerationRulesPage } from '../pages/ModerationRulesPage.jsx';
import { ConnectFacebookPage } from '../pages/ConnectFacebookPage.jsx';
import App from '../App.jsx';

beforeEach(() => {
  mocks.listModerationPages.mockReset().mockResolvedValue({ data: [{ facebook_page_id: '99887766', page_name: 'Northwind' }] });
  mocks.listModerationRules.mockReset().mockResolvedValue({ data: [], meta: { total: 0 } });
  mocks.createModerationRule.mockReset().mockResolvedValue({ data: { id: 2 } });
  mocks.updateModerationRule.mockReset().mockResolvedValue({ data: { id: 1 } });
  mocks.deleteModerationRule.mockReset().mockResolvedValue({ message: 'deleted' });
  mocks.testModerationRules.mockReset().mockResolvedValue({ data: { matched: true, rule_name: 'Spam guard', action: 'review', match_reason: 'Matched keyword', reason: 'Review recommended.' } });
  mocks.getAdminAccess.mockReset().mockResolvedValue({ data: { id: 1, name: 'Administrator' } });
  mocks.prepareAuthSession.mockReset().mockResolvedValue('');
  mocks.signIn.mockReset().mockResolvedValue({ data: { id: 1, name: 'Administrator', email: 'admin@example.test' } });
  mocks.signOut.mockReset().mockResolvedValue({ message: 'Signed out successfully.' });
  mocks.connectFacebookPage.mockReset().mockResolvedValue({ data: { id: 9, page_name: 'Northwind', facebook_page_id: '99887766' }, message: 'Connected.' });
  mocks.getDashboardOverview.mockReset().mockResolvedValue({ data: { stats: {}, charts: {}, recent_activity: [], recent_actions: [], range: {} } });
});

describe('moderation rules', () => {
  it('tests sample text without creating or changing a moderation rule', async () => {
    const user = userEvent.setup();
    render(<ModerationRulesPage />);
    await screen.findByText('Rule engine');
    await user.type(screen.getByLabelText('Comment text'), 'sample suspicious text');
    await user.click(screen.getByRole('button', { name: 'Test rules' }));
    expect(await screen.findByText('Spam guard')).toBeInTheDocument();
    expect(mocks.testModerationRules).toHaveBeenCalledWith('sample suspicious text', null);
    expect(mocks.createModerationRule).not.toHaveBeenCalled();
    expect(mocks.updateModerationRule).not.toHaveBeenCalled();
  });

  it('creates a manual moderation rule through the existing API', async () => {
    const user = userEvent.setup();
    render(<ModerationRulesPage />);
    await screen.findByText('Rule engine');
    await user.click(screen.getByRole('button', { name: 'Create rule' }));
    await user.type(screen.getByLabelText('Name'), 'Review suspicious text');
    await user.type(screen.getByLabelText(/Pattern \/ detector setting/), 'suspicious');
    const submitButtons = screen.getAllByRole('button', { name: 'Create rule' });
    await user.click(submitButtons.at(-1));
    await waitFor(() => expect(mocks.createModerationRule).toHaveBeenCalledWith(expect.objectContaining({ name: 'Review suspicious text', pattern: 'suspicious', facebook_page_id: null })));
  });
});

describe('admin access and Page connection security', () => {
  it('does not render admin navigation or dashboard data when Laravel denies access', async () => {
    mocks.getAdminAccess.mockRejectedValue(new ApiError('Forbidden', { status: 403, payload: { message: 'Forbidden' } }));
    window.history.replaceState({}, '', '/dashboard');
    render(<App />);
    expect(await screen.findByRole('heading', { name: 'Admin dashboard unavailable' })).toBeInTheDocument();
    expect(screen.getByText('Your account does not have administrator access to this area.')).toBeInTheDocument();
    expect(screen.queryByRole('navigation', { name: 'Admin navigation' })).not.toBeInTheDocument();
    expect(mocks.getDashboardOverview).not.toHaveBeenCalled();
  });

  it('offers local sign-in for a guest and rechecks admin authorization after login', async () => {
    const user = userEvent.setup();
    mocks.getAdminAccess.mockRejectedValueOnce(new ApiError('Authentication is required.', { status: 401, payload: { message: 'Authentication is required.' } }));
    window.history.replaceState({}, '', '/dashboard');
    render(<App />);

    expect(await screen.findByRole('heading', { name: 'Sign in' })).toBeInTheDocument();
    await user.type(screen.getByLabelText('Email'), 'admin@example.test');
    await user.type(screen.getByLabelText('Password'), 'a-private-test-password');
    await user.click(screen.getByRole('button', { name: 'Sign in securely' }));

    await waitFor(() => expect(mocks.prepareAuthSession).toHaveBeenCalledTimes(1));
    await waitFor(() => expect(mocks.signIn).toHaveBeenCalledWith('admin@example.test', 'a-private-test-password'));
    expect(await screen.findByRole('navigation', { name: 'Admin navigation' })).toBeInTheDocument();
    expect(mocks.getAdminAccess).toHaveBeenCalledTimes(2);
    expect(screen.queryByDisplayValue('a-private-test-password')).not.toBeInTheDocument();
  });

  it('clears the submitted Page token from the form and never writes it to local storage', async () => {
    const user = userEvent.setup();
    render(<ConnectFacebookPage />);
    const field = screen.getByLabelText('Facebook Page Access Token');
    await user.type(field, 'page-token-secret-test');
    await user.click(screen.getByRole('button', { name: 'Connect Page' }));
    await waitFor(() => expect(mocks.connectFacebookPage).toHaveBeenCalledWith('page-token-secret-test'));
    expect(field).toHaveValue('');
    expect(window.localStorage.getItem('page_access_token')).toBeNull();
    expect(screen.queryByText('page-token-secret-test')).not.toBeInTheDocument();
  });
});
