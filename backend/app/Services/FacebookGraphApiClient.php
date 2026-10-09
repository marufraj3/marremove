<?php

namespace App\Services;

use App\Exceptions\FacebookPageConnectionException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Small, token-safe client for the versioned Meta Graph API.
 * Pagination follows cursors only; it never requests the access-token-bearing
 * paging.next URL returned by Meta.
 */
final class FacebookGraphApiClient
{
    private const BASE_URL = 'https://graph.facebook.com';
    private const MAX_EDGE_PAGE_SIZE = 100;

    /** @param array<string, mixed> $query @return array<string, mixed> */
    public function get(
        string $path,
        string $pageAccessToken,
        array $query = [],
        string $context = 'sync',
    ): array {
        if (trim($pageAccessToken) === '') {
            throw new FacebookPageConnectionException(
                'The stored Facebook Page Access Token is unavailable. Reconnect the Page.',
                422,
            );
        }

        $request = Http::acceptJson()
            ->withToken($pageAccessToken)
            ->connectTimeout(5)
            ->timeout(15);
        $appSecret = config('services.facebook.app_secret');
        if (is_string($appSecret) && trim($appSecret) !== '') {
            $query['appsecret_proof'] = hash_hmac('sha256', $pageAccessToken, $appSecret);
        }

        try {
            $response = $request->get($this->endpointUrl($path), $query);
        } catch (ConnectionException $exception) {
            // Do not log the exception message or request URL: either may contain sensitive request data.
            Log::warning('Could not reach the Meta Graph API.', [
                'operation' => $context,
                'exception_type' => $exception::class,
            ]);

            throw new FacebookPageConnectionException(
                'Could not reach Facebook. Please try again.',
                503,
                null,
                true,
            );
        }

        $payload = $response->json();

        if (! $response->successful() || (is_array($payload) && isset($payload['error']))) {
            throw $this->mapGraphError($response, is_array($payload) ? $payload : [], $context);
        }

        if (! is_array($payload)) {
            throw new FacebookPageConnectionException(
                'Facebook returned an incomplete response. Please try again.',
                502,
                null,
                true,
            );
        }

        return $payload;
    }

    /**
     * Read a cursor-paginated Graph edge up to the supplied item and page limits.
     *
     * @param array<string, scalar|null> $query
     * @return list<array<string, mixed>>
     */
    public function paginate(
        string $path,
        string $pageAccessToken,
        string $fields,
        int $maxItems,
        array $query = [],
        string $context = 'sync',
    ): array {
        $maxItems = max(1, $maxItems);
        $maxPages = max(1, min(1000, (int) config('services.facebook.max_pages_per_sync', 100)));
        $items = [];
        $seenCursors = [];
        $after = null;
        $pagesFetched = 0;

        while (count($items) < $maxItems && $pagesFetched < $maxPages) {
            $remaining = $maxItems - count($items);
            $requestQuery = array_merge($query, [
                'fields' => $fields,
                'limit' => min(self::MAX_EDGE_PAGE_SIZE, $remaining),
            ]);

            if ($after !== null) {
                $requestQuery['after'] = $after;
            }

            $payload = $this->get($path, $pageAccessToken, $requestQuery, $context);
            $pagesFetched++;
            $data = $payload['data'] ?? null;

            if (! is_array($data)) {
                throw new FacebookPageConnectionException(
                    'Facebook returned an incomplete page of data. Please try again.',
                    502,
                );
            }

            foreach ($data as $item) {
                if (! is_array($item)) {
                    throw new FacebookPageConnectionException(
                        'Facebook returned an incomplete page of data. Please try again.',
                        502,
                    );
                }

                $items[] = $item;
                if (count($items) >= $maxItems) {
                    break;
                }
            }

            $next = data_get($payload, 'paging.next');
            if (! is_string($next) || $next === '' || count($items) >= $maxItems) {
                break;
            }

            $cursor = $this->extractAfterCursor($payload, $next);
            if ($cursor === null || $cursor === '') {
                throw new FacebookPageConnectionException(
                    'Facebook pagination could not continue safely. Please try again.',
                    502,
                );
            }

            if (isset($seenCursors[$cursor])) {
                throw new FacebookPageConnectionException(
                    'Facebook returned a repeated pagination cursor. Please try again.',
                    502,
                );
            }

            $seenCursors[$cursor] = true;
            $after = $cursor;
        }

        if (
            $pagesFetched >= $maxPages
            && count($items) < $maxItems
            && is_string(data_get($payload ?? [], 'paging.next'))
            && data_get($payload ?? [], 'paging.next') !== ''
        ) {
            throw new FacebookPageConnectionException(
                'The Facebook result exceeded the configured pagination safety limit.',
                422,
            );
        }

        return $items;
    }

    private function endpointUrl(string $path): string
    {
        $version = config('services.facebook.graph_version');

        if (! is_string($version) || preg_match('/^v\d+\.\d+$/D', $version) !== 1) {
            throw new FacebookPageConnectionException(
                'Facebook Graph API is not configured correctly. Contact the administrator.',
                503,
            );
        }

        $segments = array_filter(explode('/', trim($path, '/')), static fn (string $segment): bool => $segment !== '');
        $encodedPath = implode('/', array_map('rawurlencode', $segments));

        if ($encodedPath === '') {
            throw new FacebookPageConnectionException(
                'The Facebook request could not be prepared. Please try again.',
                500,
            );
        }

        return self::BASE_URL.'/'.$version.'/'.$encodedPath;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function extractAfterCursor(array $payload, string $nextUrl): ?string
    {
        $cursor = data_get($payload, 'paging.cursors.after');
        if (is_string($cursor) || is_numeric($cursor)) {
            return (string) $cursor;
        }

        // Meta's next URL can contain the token. Parse only its opaque cursor and never follow or log it.
        $queryString = parse_url($nextUrl, PHP_URL_QUERY);
        if (! is_string($queryString)) {
            return null;
        }

        parse_str($queryString, $query);
        $cursor = $query['after'] ?? null;

        return is_string($cursor) || is_numeric($cursor) ? (string) $cursor : null;
    }

    /** @param array<string, mixed> $payload */
    private function mapGraphError(Response $response, array $payload, string $context): FacebookPageConnectionException
    {
        $error = is_array($payload['error'] ?? null) ? $payload['error'] : [];
        $code = is_numeric($error['code'] ?? null) ? (int) $error['code'] : null;
        $subcode = is_numeric($error['error_subcode'] ?? null) ? (int) $error['error_subcode'] : null;

        // Never record Meta's raw message, request URL, or token.
        Log::warning('Meta Graph API request was rejected.', [
            'operation' => $context,
            'http_status' => $response->status(),
            'meta_error_code' => $code,
            'meta_error_subcode' => $subcode,
        ]);

        if ($response->status() === 429 || in_array($code, [4, 17, 32, 613, 80001], true)) {
            return new FacebookPageConnectionException(
                'Facebook is temporarily rate limiting requests. Please wait and try again.',
                429,
                $code,
                true,
            );
        }

        if ($code === 190 && $subcode === 463) {
            $message = $context === 'page_discovery'
                ? 'This Facebook User Access Token has expired. Generate a new User Access Token.'
                : 'This Facebook Page Access Token has expired. Generate a new Page Access Token.';

            return new FacebookPageConnectionException($message, 422, $code);
        }

        if ($code === 190) {
            $message = $context === 'page_discovery'
                ? 'Invalid Facebook User Access Token.'
                : 'Invalid Facebook Page Access Token.';

            return new FacebookPageConnectionException($message, 422, $code);
        }

        if (in_array($code, [10, 200, 299, 283], true)) {
            if ($context === 'page_discovery') {
                $message = 'The User Access Token cannot list these Pages. Grant pages_show_list and confirm the Facebook account can access them.';
            } elseif ($context === 'page_connection') {
                $message = 'This Page Access Token is missing permissions needed to read Page information.';
            } else {
                $message = 'The Page Access Token lacks permission to read Page posts or comments. Check the Page reading permissions and assigned Page tasks.';
            }

            return new FacebookPageConnectionException($message, 422, $code);
        }

        if ($code === 100) {
            if ($context === 'page_discovery') {
                $message = 'Facebook could not list Pages with this User Access Token. Check pages_show_list and account access.';
            } elseif ($context === 'page_connection') {
                $message = 'The token did not resolve to a Facebook Page. Confirm the Page ID and Page Access Token.';
            } else {
                $message = 'Facebook could not find the requested Page or post.';
            }

            return new FacebookPageConnectionException($message, 422, $code);
        }

        if ($response->status() >= 500
            || in_array($code, [1, 2], true)
            || ($error['is_transient'] ?? false) === true) {
            return new FacebookPageConnectionException(
                'Facebook is temporarily unavailable. Please try again.',
                503,
                $code,
                true,
            );
        }

        return new FacebookPageConnectionException(
            'Facebook could not complete this request. Check the Page token and its permissions, then try again.',
            422,
            $code,
        );
    }
}
