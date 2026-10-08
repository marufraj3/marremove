import { apiRequest } from './httpClient.js';

export function connectFacebookPage(pageAccessToken) {
  return apiRequest('/facebook-pages/connect', {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
    },
    body: JSON.stringify({ page_access_token: pageAccessToken }),
  });
}

export function listFacebookPages(options = {}) {
  return apiRequest('/facebook/pages', options);
}

export function syncFacebookPage(pageRecordId) {
  return apiRequest(`/facebook/pages/${encodeURIComponent(pageRecordId)}/sync`, {
    method: 'POST',
  });
}

export function disconnectFacebookPage(pageRecordId) {
  return apiRequest(`/facebook/pages/${encodeURIComponent(pageRecordId)}`, { method: 'DELETE' });
}

export function getFacebookComment(commentRecordId, options = {}) {
  return apiRequest(`/facebook/comments/${encodeURIComponent(commentRecordId)}`, options);
}

export function listFacebookComments(filters = {}) {
  const query = new URLSearchParams();
  Object.entries(filters).forEach(([key, value]) => {
    if (value !== undefined && value !== null && String(value) !== '') {
      query.set(key, String(value));
    }
  });
  const suffix = query.toString();
  return apiRequest(`/facebook/comments${suffix ? `?${suffix}` : ''}`);
}
