import { apiRequest } from './httpClient.js';

function queryString(filters = {}) {
  const query = new URLSearchParams();
  Object.entries(filters).forEach(([key, value]) => {
    if (value !== undefined && value !== null && String(value) !== '') {
      query.set(key, String(value));
    }
  });
  const suffix = query.toString();
  return suffix ? `?${suffix}` : '';
}

export function listModerationPages() {
  return apiRequest('/moderation/pages');
}

export function listModerationRules(filters = {}) {
  return apiRequest(`/moderation/rules${queryString(filters)}`);
}

export function createModerationRule(rule) {
  return apiRequest('/moderation/rules', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(rule),
  });
}

export function updateModerationRule(ruleId, updates) {
  return apiRequest(`/moderation/rules/${encodeURIComponent(ruleId)}`, {
    method: 'PATCH',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(updates),
  });
}

export function deleteModerationRule(ruleId) {
  return apiRequest(`/moderation/rules/${encodeURIComponent(ruleId)}`, {
    method: 'DELETE',
  });
}

export function testModerationRules(comment, facebookPageId = null) {
  return apiRequest('/moderation/rules/test', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ comment, facebook_page_id: facebookPageId || null }),
  });
}
