import { afterEach, describe, expect, it, vi } from 'vitest';
import { apiRequest } from '../api/httpClient.js';

afterEach(() => {
  vi.unstubAllGlobals();
  vi.unstubAllEnvs();
  vi.resetModules();
  document.cookie = 'XSRF-TOKEN=; expires=Thu, 01 Jan 1970 00:00:00 GMT; path=/';
});

function jsonResponse(payload = { data: true }) {
  return {
    ok: true,
    headers: new Headers({ 'content-type': 'application/json' }),
    json: async () => payload,
  };
}

describe('same-origin API client', () => {
  it('sends Laravel’s XSRF cookie on state-changing requests without changing the same-origin session behavior', async () => {
    const fetchMock = vi.fn().mockResolvedValue(jsonResponse());
    vi.stubGlobal('fetch', fetchMock);
    document.cookie = `XSRF-TOKEN=${encodeURIComponent('csrf token value')}; path=/`;

    await apiRequest('/moderation/comments/7/actions', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ action: 'hide' }),
    });

    expect(fetchMock).toHaveBeenCalledOnce();
    const [url, options] = fetchMock.mock.calls[0];
    expect(url).toBe('/api/moderation/comments/7/actions');
    expect(options.credentials).toBe('same-origin');
    expect(options.headers.get('X-XSRF-TOKEN')).toBe('csrf token value');
    expect(options.headers.get('Content-Type')).toBe('application/json');
  });

  it('does not send the XSRF cookie to a cross-origin API base', async () => {
    vi.stubEnv('VITE_API_BASE_URL', 'https://api.example.test/api');
    vi.resetModules();
    const { apiRequest: requestWithExternalBase } = await import('../api/httpClient.js');
    const fetchMock = vi.fn().mockResolvedValue(jsonResponse());
    vi.stubGlobal('fetch', fetchMock);
    document.cookie = `XSRF-TOKEN=${encodeURIComponent('csrf token value')}; path=/`;

    await requestWithExternalBase('/moderation/comments/7/actions', {
      method: 'POST',
      headers: { 'X-XSRF-TOKEN': 'manually-supplied-secret' },
    });

    const [url, options] = fetchMock.mock.calls[0];
    expect(url).toBe('https://api.example.test/api/moderation/comments/7/actions');
    expect(options.headers.has('X-XSRF-TOKEN')).toBe(false);
    expect(options.credentials).toBe('same-origin');
  });

  it('does not send an XSRF header on read-only requests', async () => {
    const fetchMock = vi.fn().mockResolvedValue(jsonResponse());
    vi.stubGlobal('fetch', fetchMock);
    document.cookie = `XSRF-TOKEN=${encodeURIComponent('csrf token value')}; path=/`;

    await apiRequest('/moderation/access');

    const [, options] = fetchMock.mock.calls[0];
    expect(options.method).toBe('GET');
    expect(options.headers.has('X-XSRF-TOKEN')).toBe(false);
  });
});
