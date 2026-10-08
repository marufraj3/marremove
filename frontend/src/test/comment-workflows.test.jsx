import { beforeEach, describe, expect, it, vi } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

const mocks = vi.hoisted(() => ({
  listFacebookPages: vi.fn(),
  listFacebookComments: vi.fn(),
  getFacebookComment: vi.fn(),
  overrideCommentModeration: vi.fn(),
}));

vi.mock('../api/facebookPageService.js', () => ({
  listFacebookPages: mocks.listFacebookPages,
  listFacebookComments: mocks.listFacebookComments,
  getFacebookComment: mocks.getFacebookComment,
}));
vi.mock('../api/aiModerationService.js', () => ({ overrideCommentModeration: mocks.overrideCommentModeration }));

import { FacebookCommentsPage } from '../pages/FacebookCommentsPage.jsx';
import { CommentDetailPage } from '../pages/CommentDetailPage.jsx';
import { ReviewQueuePage } from '../pages/ReviewQueuePage.jsx';

const comment = {
  id: 41,
  facebook_comment_id: '554433_12',
  facebook_page_id: '99887766',
  facebook_post_id: '99887766_4',
  page: { id: 9, facebook_page_id: '99887766', page_name: 'Northwind' },
  author: { name: 'Customer' },
  message: 'Please review this comment',
  created_at: '2026-10-08T08:15:00Z',
  manual_moderation: { status: 'no_match', action: null, checked_at: '2026-10-08T08:16:00Z', reason: null },
  ai_moderation: { status: 'completed', decision: 'review', category: 'spam', confidence: 0.77, severity: 'medium', reason: 'Needs human review', checked_at: '2026-10-08T08:17:00Z' },
  final_moderation: { status: 'completed', action: 'review', method: 'ai', source: 'gemini', category: 'spam', confidence: 0.77, reason: 'Needs human review', decision_at: '2026-10-08T08:17:00Z', manual_override: false },
  facebook_action: { status: 'skipped', requested_action: null, state: 'visible', attempt_count: 0, error: null },
  can_override: true,
};

beforeEach(() => {
  mocks.listFacebookPages.mockReset().mockResolvedValue({ data: [{ id: 9, page_name: 'Northwind', is_active: true }] });
  mocks.listFacebookComments.mockReset().mockResolvedValue({ data: [comment], meta: { current_page: 1, last_page: 2, total: 26 } });
  mocks.getFacebookComment.mockReset().mockResolvedValue({ data: comment });
  mocks.overrideCommentModeration.mockReset().mockResolvedValue({ data: { ...comment, final_moderation: { ...comment.final_moderation, action: 'delete', manual_override: true } } });
});

describe('comment review workflows', () => {
  it('sends search and decision filters to the paginated comments API', async () => {
    const user = userEvent.setup();
    render(<FacebookCommentsPage />);
    await screen.findAllByText('Please review this comment');
    await user.selectOptions(screen.getByRole('combobox', { name: 'Filter by decision' }), 'hide');
    await waitFor(() => expect(mocks.listFacebookComments).toHaveBeenLastCalledWith(expect.objectContaining({ decision: 'hide', page: 1, per_page: 25 })));
    await user.click(screen.getByRole('button', { name: 'Next' }));
    await waitFor(() => expect(mocks.listFacebookComments).toHaveBeenLastCalledWith(expect.objectContaining({ decision: 'hide', page: 2 })));
  });

  it('requires confirmation before submitting a comment override', async () => {
    const user = userEvent.setup();
    render(<CommentDetailPage commentId="41" />);
    expect(await screen.findByRole('heading', { name: 'Decision explanation timeline' })).toBeInTheDocument();
    await user.selectOptions(screen.getByRole('combobox', { name: 'Final decision' }), 'delete');
    await user.click(screen.getByRole('button', { name: 'Review and confirm' }));
    expect(screen.getByRole('dialog')).toHaveTextContent('irreversible delete action');
    expect(mocks.overrideCommentModeration).not.toHaveBeenCalled();
    await user.click(screen.getByRole('button', { name: 'Confirm delete' }));
    await waitFor(() => expect(mocks.overrideCommentModeration).toHaveBeenCalledWith(41, 'delete', ''));
  });

  it('shows decision, manual, AI, and Facebook-action timeline information', async () => {
    render(<CommentDetailPage commentId="41" />);
    expect(await screen.findByText('Needs human review')).toBeInTheDocument();
    expect(screen.getByText('Facebook action service')).toBeInTheDocument();
    expect(screen.getByText('Customer')).toBeInTheDocument();
  });

  it('requires human confirmation before a Review Queue delete recommendation', async () => {
    const user = userEvent.setup();
    mocks.listFacebookComments.mockResolvedValue({ data: [comment], meta: { current_page: 1, last_page: 1, total: 1 } });
    render(<ReviewQueuePage />);
    expect(await screen.findByText('Please review this comment')).toBeInTheDocument();
    await user.click(screen.getByRole('button', { name: 'Recommend delete' }));
    expect(screen.getByRole('dialog')).toHaveTextContent('Deletion is irreversible');
    expect(mocks.overrideCommentModeration).not.toHaveBeenCalled();
    await user.click(screen.getByRole('button', { name: 'Confirm delete' }));
    await waitFor(() => expect(mocks.overrideCommentModeration).toHaveBeenCalledWith(41, 'delete', 'Review queue decision: delete'));
  });
});
