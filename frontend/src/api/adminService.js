import { apiRequest } from './httpClient.js';

export function getAdminAccess() {
  return apiRequest('/moderation/access');
}
