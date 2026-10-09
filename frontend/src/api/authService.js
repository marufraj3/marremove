import { apiRequest } from './httpClient.js';

export function prepareAuthSession() {
  return apiRequest('/auth/csrf');
}

export function signIn(email, password) {
  return apiRequest('/auth/login', {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
    },
    body: JSON.stringify({ email, password }),
  });
}

export function signOut() {
  return apiRequest('/auth/logout', { method: 'POST' });
}
