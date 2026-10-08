import { apiRequest } from './httpClient.js';

export function getDashboardOverview(filters = {}, options = {}) {
  const query = new URLSearchParams();
  Object.entries(filters).forEach(([key, value]) => {
    if (value !== undefined && value !== null && String(value) !== '') query.set(key, String(value));
  });
  const suffix = query.toString();
  return apiRequest(`/moderation/dashboard${suffix ? `?${suffix}` : ''}`, options);
}
