import { apiRequest } from './httpClient.js';

export function getFacebookWebhookStatus(options = {}) {
  return apiRequest('/facebook/webhook/status', options);
}
