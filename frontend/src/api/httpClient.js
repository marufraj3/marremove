const API_BASE_URL = (import.meta.env.VITE_API_BASE_URL || '/api').replace(/\/+$/, '');
const SAFE_METHODS = new Set(['GET', 'HEAD', 'OPTIONS']);

export class ApiError extends Error {
  constructor(message, { status, payload } = {}) {
    super(message);
    this.name = 'ApiError';
    this.status = status;
    this.payload = payload;
  }
}

function xsrfTokenFromCookie() {
  if (typeof document === 'undefined' || typeof document.cookie !== 'string') return null;

  const entry = document.cookie
    .split(';')
    .map((cookie) => cookie.trim())
    .find((cookie) => cookie.startsWith('XSRF-TOKEN='));
  if (!entry) return null;

  const value = entry.slice('XSRF-TOKEN='.length);
  try {
    return decodeURIComponent(value);
  } catch {
    return value;
  }
}

export async function apiRequest(path, options = {}) {
  const endpoint = String(path).replace(/^\/+/, '');
  const method = String(options.method || 'GET').toUpperCase();
  const headers = new Headers(options.headers || {});
  if (!headers.has('Accept')) headers.set('Accept', 'application/json');

  const requestUrl = `${API_BASE_URL}/${endpoint}`;
  let isSameOrigin = false;
  if (typeof window !== 'undefined') {
    try {
      isSameOrigin = new URL(requestUrl, window.location.origin).origin === window.location.origin;
    } catch {
      isSameOrigin = false;
    }
  }

  if (!isSameOrigin) {
    headers.delete('X-XSRF-TOKEN');
  } else if (!SAFE_METHODS.has(method) && !headers.has('X-XSRF-TOKEN')) {
    const xsrfToken = xsrfTokenFromCookie();
    if (xsrfToken) headers.set('X-XSRF-TOKEN', xsrfToken);
  }

  const response = await fetch(requestUrl, {
    ...options,
    method,
    credentials: 'same-origin',
    headers,
  });

  const contentType = response.headers.get('content-type') || '';
  const payload = contentType.includes('application/json')
    ? await response.json()
    : await response.text();

  if (!response.ok) {
    const message =
      payload && typeof payload === 'object' && 'message' in payload
        ? payload.message
        : `API request failed with status ${response.status}`;

    throw new ApiError(message, { status: response.status, payload });
  }

  return payload;
}
