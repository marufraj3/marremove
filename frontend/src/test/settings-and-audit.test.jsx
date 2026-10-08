import { beforeEach, describe, expect, it, vi } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

const mocks = vi.hoisted(() => ({
  getAiModerationSettings: vi.fn(),
  testGeminiModeration: vi.fn(),
  updatePageModerationSettings: vi.fn(),
  getFacebookWebhookStatus: vi.fn(),
  getApiHealth: vi.fn(),
  listFacebookPages: vi.fn(),
  listModerationActions: vi.fn(),
  queueManualFacebookAction: vi.fn(),
}));

vi.mock('../api/aiModerationService.js', () => ({
  getAiModerationSettings: mocks.getAiModerationSettings,
  testGeminiModeration: mocks.testGeminiModeration,
  updatePageModerationSettings: mocks.updatePageModerationSettings,
}));
vi.mock('../api/facebookWebhookService.js', () => ({ getFacebookWebhookStatus: mocks.getFacebookWebhookStatus }));
vi.mock('../api/healthService.js', () => ({ getApiHealth: mocks.getApiHealth }));
vi.mock('../api/facebookPageService.js', () => ({ listFacebookPages: mocks.listFacebookPages }));
vi.mock('../api/moderationActionService.js', () => ({ listModerationActions: mocks.listModerationActions, queueManualFacebookAction: mocks.queueManualFacebookAction }));

import { AiModerationSettingsPage } from '../pages/AiModerationSettingsPage.jsx';
import { ModerationSettingsPage } from '../pages/ModerationSettingsPage.jsx';
import { ModerationActionsPage } from '../pages/ModerationActionsPage.jsx';
import { FacebookWebhookStatusPage } from '../pages/FacebookWebhookStatusPage.jsx';
import { SystemSettingsPage } from '../pages/SystemSettingsPage.jsx';

const pageSettings = {
  id: 9,
  facebook_page_id: '99887766',
  page_name: 'Northwind',
  is_active: true,
  ai_enabled: true,
  manual_enabled: true,
  auto_review_threshold: 0.7,
  auto_hide_threshold: 0.9,
  auto_delete_threshold: 0.98,
  allow_ai_hide: true,
  allow_ai_delete: false,
  auto_hide_enabled: false,
  auto_delete_enabled: false,
  auto_execute_actions: false,
};
const safeSettings = {
  enabled: true,
  api_key_configured: true,
  model: 'gemini-safe-model',
  timeout_seconds: 20,
  max_retries: 2,
  failure_decision: 'review',
  default_thresholds: { auto_review_threshold: 0.7, auto_hide_threshold: 0.9, auto_delete_threshold: 0.98, allow_ai_hide: true, allow_ai_delete: false },
  default_action_settings: { auto_hide_enabled: false, auto_delete_enabled: false, auto_execute_actions: false, test_mode: true },
  pages: [pageSettings],
};

beforeEach(() => {
  mocks.getAiModerationSettings.mockReset().mockResolvedValue({ data: safeSettings });
  mocks.testGeminiModeration.mockReset().mockResolvedValue({ data: { action: 'review', category: 'spam', confidence: 0.79, severity: 'medium', reason: 'Human review is appropriate.' }, meta: { status: 'completed', processing_time_ms: 321 } });
  mocks.updatePageModerationSettings.mockReset().mockResolvedValue({ data: { ...pageSettings, auto_delete_enabled: true, auto_execute_actions: true } });
  mocks.getFacebookWebhookStatus.mockReset().mockResolvedValue({ data: { verification_status: 'verified', webhook_url: 'https://example.test/api/facebook/webhook', app_secret_configured: true, verify_token_configured: true, total_events: 12, processed_events: 10, queued_events: 1, failed_events: 1, last_error_category: 'queue_dispatch_failed', last_received_at: '2026-10-08T08:00:00Z' } });
  mocks.getApiHealth.mockReset().mockResolvedValue({ status: 'ok', service: 'Marremove' });
  mocks.listFacebookPages.mockReset().mockResolvedValue({ data: [{ id: 9, page_name: 'Northwind', is_active: true }] });
  mocks.listModerationActions.mockReset().mockResolvedValue({ data: [{ id: 51, facebook_comment_id: '99887766_5', action: 'hide', status: 'completed', attempt_count: 1, response_code: 200, meta_error_code: null, response_message: 'Simulated successfully.', last_error: null, processing_time_ms: 45, is_manual: true, is_test: true, created_at: '2026-10-08T08:00:00Z', processed_at: '2026-10-08T08:00:01Z', comment: { id: 41, message: 'Comment needs moderation', page_name: 'Northwind', final_decision: 'hide', final_category: 'spam', facebook_action_state: 'hidden', author_name: 'Customer' } }], meta: { current_page: 1, last_page: 1, total: 1 } });
  mocks.queueManualFacebookAction.mockReset().mockResolvedValue({ data: { status: 'pending' } });
});

describe('AI and moderation configuration', () => {
  it('runs a stateless AI test and does not render credential values', async () => {
    const user = userEvent.setup();
    render(<AiModerationSettingsPage />);
    expect(await screen.findByText('Configured · value hidden')).toBeInTheDocument();
    await user.type(screen.getByLabelText('Comment text'), 'Test this review please');
    await user.click(screen.getByRole('button', { name: 'Run AI test' }));
    expect(await screen.findByText('Human review is appropriate.')).toBeInTheDocument();
    expect(mocks.testGeminiModeration).toHaveBeenCalledWith(expect.objectContaining({ comment: 'Test this review please', pageName: 'Northwind' }));
    expect(screen.queryByText(/AIza|api[_ -]?key\s*[:=]|secret-value/i)).not.toBeInTheDocument();
  });

  it('requires a warning confirmation before enabling dangerous automatic settings', async () => {
    const user = userEvent.setup();
    render(<ModerationSettingsPage />);
    expect(await screen.findByRole('heading', { name: 'Northwind' })).toBeInTheDocument();
    await user.click(screen.getByRole('checkbox', { name: /Allow automatic Facebook delete/ }));
    await user.click(screen.getByRole('button', { name: 'Save moderation settings' }));
    expect(screen.getByRole('dialog')).toHaveTextContent('dangerous automatic action');
    expect(mocks.updatePageModerationSettings).not.toHaveBeenCalled();
    await user.click(screen.getByRole('button', { name: 'Confirm and save' }));
    await waitFor(() => expect(mocks.updatePageModerationSettings).toHaveBeenCalledWith(9, expect.objectContaining({ auto_delete_enabled: true })));
  });
});

describe('action and webhook diagnostics', () => {
  it('filters and expands an action log detail record', async () => {
    const user = userEvent.setup();
    render(<ModerationActionsPage />);
    expect(await screen.findByText('Comment needs moderation')).toBeInTheDocument();
    await user.click(screen.getByRole('button', { name: /Details/ }));
    expect(screen.getByRole('dialog')).toHaveTextContent('Action log detail');
    expect(screen.getByText('200')).toBeInTheDocument();
    await user.click(screen.getByRole('button', { name: 'Close action detail' }));
    await user.selectOptions(screen.getByRole('combobox', { name: 'Filter action logs by status' }), 'failed');
    await waitFor(() => expect(mocks.listModerationActions).toHaveBeenLastCalledWith(expect.objectContaining({ status: 'failed', page: 1, per_page: 20 })));
  });

  it('requires confirmation before queueing a manual Facebook action', async () => {
    const user = userEvent.setup();
    render(<ModerationActionsPage />);
    await screen.findByText('Comment needs moderation');
    await user.click(screen.getByRole('button', { name: /Details/ }));
    await user.click(screen.getByRole('button', { name: 'hide' }));
    expect(screen.getByRole('heading', { name: 'Confirm manual HIDE action' })).toBeInTheDocument();
    expect(mocks.queueManualFacebookAction).not.toHaveBeenCalled();
    await user.click(screen.getByRole('button', { name: 'Queue hide' }));
    await waitFor(() => expect(mocks.queueManualFacebookAction).toHaveBeenCalledWith(41, 'hide'));
  });

  it('shows webhook event counts and safe latest error category', async () => {
    render(<FacebookWebhookStatusPage />);
    expect(await screen.findByText('12')).toBeInTheDocument();
    expect(screen.getByText('Latest event issue: queue dispatch failed')).toBeInTheDocument();
    expect(screen.getByText('Verified')).toBeInTheDocument();
  });

  it('shows read-only safe system status without credentials', async () => {
    render(<SystemSettingsPage />);
    expect(await screen.findByRole('heading', { name: 'System Settings' })).toBeInTheDocument();
    expect(screen.getAllByText('Configured · hidden')).toHaveLength(2);
    expect(screen.getByText('10 / 1 / 1')).toBeInTheDocument();
    expect(screen.queryByText(/secret-value|AIza/)).not.toBeInTheDocument();
  });
});
