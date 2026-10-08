import { ApiError } from '../api/httpClient.js';

export function friendlyError(error, fallback = 'Something went wrong. Please try again.') {
  if (error instanceof ApiError) {
    if (error.status === 401) return 'Your session has expired. Sign in to the application, then try again.';
    if (error.status === 403) return 'Your account does not have administrator access to this area.';
    if (error.status === 404) return 'This item could not be found or is no longer available.';
    if (error.status === 429) return 'Too many requests. Wait a moment and try again.';
    if (error.status >= 500) return 'The service is having trouble right now. Please try again shortly.';

    const errors = error.payload?.errors;
    if (errors && typeof errors === 'object') {
      const first = Object.values(errors).flat().find((message) => typeof message === 'string');
      if (first) return first;
    }

    const message = error.payload?.message;
    if (typeof message === 'string' && message.trim()) return message;
    return fallback;
  }

  if (error instanceof TypeError || error?.name === 'NetworkError') {
    return 'Could not reach Marremove. Check your connection and try again.';
  }
  return typeof error?.message === 'string' && error.message.trim() ? error.message : fallback;
}
