import { apiRequest } from './httpClient.js';

export function listModerationActions(filters = {}) {
  const parameters = new URLSearchParams();
  Object.entries(filters).forEach(([key, value]) => {
    if (value !== undefined && value !== null && value !== '') parameters.set(key, String(value));
  });
  const query = parameters.toString();
  return apiRequest(`/moderation/actions${query ? `?${query}` : ''}`);
}

export function queueManualFacebookAction(commentId, action) {
  return apiRequest(`/moderation/comments/${encodeURIComponent(commentId)}/actions`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ action }),
  });
}
