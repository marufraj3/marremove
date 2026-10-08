import { apiRequest } from './httpClient.js';

export function getAiModerationSettings(options = {}) {
  return apiRequest('/moderation/ai/settings', options);
}

export function updatePageModerationSettings(pageId, settings) {
  return apiRequest(`/moderation/ai/pages/${encodeURIComponent(pageId)}/settings`, {
    method: 'PATCH',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(settings),
  });
}

export function updatePageAiSettings(pageId, aiEnabled) {
  return updatePageModerationSettings(pageId, { ai_enabled: aiEnabled });
}

export function testGeminiModeration({ comment, pageName = '', postText = '' }) {
  return apiRequest('/moderation/ai/test', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({
      comment,
      page_name: pageName || null,
      post_text: postText || null,
    }),
  });
}

export function overrideCommentModeration(commentId, action, reason = '') {
  return apiRequest(`/moderation/comments/${encodeURIComponent(commentId)}/override`, {
    method: 'PATCH',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ action, reason: reason || null }),
  });
}
