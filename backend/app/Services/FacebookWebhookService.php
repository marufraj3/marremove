<?php

namespace App\Services;

use App\Exceptions\FacebookPageConnectionException;
use App\Exceptions\FacebookWebhookPayloadException;
use App\Exceptions\FacebookWebhookQueueException;
use App\Jobs\ProcessFacebookWebhookJob;
use App\Models\FacebookPage;
use App\Models\FacebookWebhookEvent;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use JsonException;
use Throwable;

final class FacebookWebhookService
{
    public function __construct(private readonly FacebookPageService $facebookPageService)
    {
    }

    public function verificationTokenConfigured(): bool
    {
        $token = config('services.facebook.webhook_verify_token');

        return is_string($token) && trim($token) !== '';
    }

    public function verificationMatches(?string $mode, ?string $providedToken): bool
    {
        $expectedToken = config('services.facebook.webhook_verify_token');

        return $mode === 'subscribe'
            && is_string($expectedToken)
            && trim($expectedToken) !== ''
            && is_string($providedToken)
            && hash_equals($expectedToken, $providedToken);
    }

    public function recordVerificationSuccess(string $verifyToken): void
    {
        $now = now();
        DB::table('facebook_webhook_statuses')->updateOrInsert(
            ['status_key' => 'pages'],
            [
                'verify_token_fingerprint' => hash('sha256', $verifyToken),
                'verified_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        );
    }

    public function verifySignature(string $rawBody, ?string $signature): bool
    {
        $secret = config('services.facebook.app_secret');
        if (! is_string($secret) || trim($secret) === '') {
            Log::error('Facebook webhook signature could not be validated.', [
                'error_category' => 'app_secret_not_configured',
            ]);

            return false;
        }

        if (! is_string($signature) || preg_match('/^sha256=[a-f0-9]{64}$/iD', $signature) !== 1) {
            Log::warning('Facebook webhook request was rejected.', [
                'error_category' => 'missing_or_malformed_signature',
            ]);

            return false;
        }

        $expected = 'sha256='.hash_hmac('sha256', $rawBody, $secret);
        if (! hash_equals($expected, strtolower($signature))) {
            Log::warning('Facebook webhook request was rejected.', [
                'error_category' => 'invalid_signature',
            ]);

            return false;
        }

        return true;
    }

    /**
     * Validate a signed delivery, reserve deterministic event records, and enqueue processing.
     * No Graph API calls are made during request handling.
     *
     * @return array{queued: int, duplicates: int, ignored: int}
     *
     * @throws FacebookWebhookPayloadException
     * @throws FacebookWebhookQueueException
     */
    public function receive(string $rawBody): array
    {
        $maxBytes = max(1, (int) config('services.facebook.webhook_max_payload_bytes', 1_048_576));
        if (strlen($rawBody) > $maxBytes) {
            Log::warning('Facebook webhook payload was rejected.', [
                'error_category' => 'payload_too_large',
                'payload_bytes' => strlen($rawBody),
                'max_payload_bytes' => $maxBytes,
            ]);
            throw new FacebookWebhookPayloadException('The webhook payload is too large.');
        }

        try {
            $payload = json_decode($rawBody, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            Log::warning('Facebook webhook payload was rejected.', [
                'error_category' => 'malformed_json',
            ]);
            throw new FacebookWebhookPayloadException('The webhook payload must be valid JSON.');
        }

        if (! is_array($payload)) {
            Log::info('Unknown Facebook webhook payload shape was acknowledged and ignored.', [
                'error_category' => 'unknown_payload_shape',
            ]);

            return ['queued' => 0, 'duplicates' => 0, 'ignored' => 1];
        }

        if (! is_string($payload['object'] ?? null)) {
            Log::info('Unknown Facebook webhook payload shape was acknowledged and ignored.', [
                'error_category' => 'unknown_payload_shape',
            ]);

            return ['queued' => 0, 'duplicates' => 0, 'ignored' => 1];
        }

        if ($payload['object'] !== 'page') {
            $entryCount = is_array($payload['entry'] ?? null) ? count($payload['entry']) : 0;
            Log::info('Unsupported Facebook webhook object was ignored.', [
                'error_category' => 'unsupported_object',
            ]);

            return ['queued' => 0, 'duplicates' => 0, 'ignored' => max(1, $entryCount)];
        }

        if (! is_array($payload['entry'] ?? null)) {
            Log::info('Unknown Facebook Page webhook payload shape was acknowledged and ignored.', [
                'error_category' => 'unknown_payload_shape',
            ]);

            return ['queued' => 0, 'duplicates' => 0, 'ignored' => 1];
        }

        $counts = ['queued' => 0, 'duplicates' => 0, 'ignored' => 0];
        $queueFailed = false;
        $receivedAt = now();

        foreach ($payload['entry'] as $entry) {
            if (! is_array($entry)) {
                $counts['ignored']++;
                continue;
            }

            $facebookPageId = $this->graphId($entry['id'] ?? null);
            if ($facebookPageId === null) {
                $counts['ignored']++;
                Log::info('Facebook webhook entry without a valid Page ID was ignored.', [
                    'error_category' => 'missing_page_id',
                ]);
                continue;
            }

            $page = FacebookPage::query()
                ->where('facebook_page_id', $facebookPageId)
                ->where('is_active', true)
                ->first();

            if ($page === null) {
                $counts['ignored']++;
                Log::info('Facebook webhook for an unconnected Page was ignored.', [
                    'facebook_page_id' => $facebookPageId,
                    'event_type' => 'page.feed',
                    'facebook_comment_id' => null,
                    'duration_ms' => 0,
                    'error_category' => 'page_not_connected',
                ]);
                continue;
            }

            $page->forceFill(['webhook_last_received_at' => $receivedAt])->save();
            $changes = $entry['changes'] ?? null;
            if (! is_array($changes)) {
                $counts['ignored']++;
                Log::info('Facebook Page webhook entry did not contain feed changes.', [
                    'facebook_page_id' => $facebookPageId,
                    'event_type' => 'page.feed',
                    'facebook_comment_id' => null,
                    'duration_ms' => 0,
                    'error_category' => 'missing_changes',
                ]);
                continue;
            }

            foreach ($changes as $change) {
                if (! is_array($change)) {
                    $counts['ignored']++;
                    continue;
                }

                $field = is_string($change['field'] ?? null) ? $change['field'] : 'unknown';
                $value = $change['value'] ?? null;
                if ($field !== 'feed' || ! is_array($value) || ($value['item'] ?? null) !== 'comment') {
                    $counts['ignored']++;
                    continue;
                }

                $verb = is_string($value['verb'] ?? null) ? strtolower($value['verb']) : '';
                $eventType = match ($verb) {
                    'add' => 'comment.created',
                    'edit', 'edited', 'update' => 'comment.updated',
                    default => null,
                };
                $commentId = $this->graphId($value['comment_id'] ?? null);

                if ($eventType === null) {
                    $counts['ignored']++;
                    Log::info('Unsupported Facebook comment webhook event was ignored.', [
                        'facebook_page_id' => $facebookPageId,
                        'event_type' => 'comment.'.($verb !== '' ? $verb : 'unknown'),
                        'facebook_comment_id' => $commentId,
                        'duration_ms' => 0,
                        'error_category' => 'unsupported_event',
                    ]);
                    continue;
                }

                if ($commentId === null) {
                    $counts['ignored']++;
                    Log::warning('Facebook comment webhook event without a comment ID was ignored.', [
                        'facebook_page_id' => $facebookPageId,
                        'event_type' => $eventType,
                        'facebook_comment_id' => null,
                        'duration_ms' => 0,
                        'error_category' => 'missing_comment_id',
                    ]);
                    continue;
                }

                $postId = $this->graphId($value['post_id'] ?? null);
                $eventKey = $this->makeEventKey($facebookPageId, $change);
                $shouldDispatch = $this->reserveEvent(
                    $page,
                    $facebookPageId,
                    $commentId,
                    $postId,
                    $eventType,
                    $eventKey,
                    $receivedAt,
                );

                if (! $shouldDispatch) {
                    $counts['duplicates']++;
                    Log::info('Duplicate Facebook webhook delivery was ignored.', [
                        'facebook_page_id' => $facebookPageId,
                        'event_type' => $eventType,
                        'facebook_comment_id' => $commentId,
                        'duration_ms' => 0,
                        'error_category' => null,
                    ]);
                    continue;
                }

                try {
                    ProcessFacebookWebhookJob::dispatch($eventKey);
                    FacebookWebhookEvent::query()
                        ->where('event_key', $eventKey)
                        ->where('status', 'queued')
                        ->update([
                            'dispatched_at' => now(),
                            'updated_at' => now(),
                        ]);
                    $counts['queued']++;
                } catch (Throwable $exception) {
                    FacebookWebhookEvent::query()
                        ->where('event_key', $eventKey)
                        ->whereIn('status', ['queued', 'processing'])
                        ->update([
                            'status' => 'dispatch_failed',
                            'error_category' => 'queue_dispatch_failed',
                            'updated_at' => now(),
                        ]);
                    $queueFailed = true;
                    Log::error('Facebook webhook event could not be queued.', [
                        'facebook_page_id' => $facebookPageId,
                        'event_type' => $eventType,
                        'facebook_comment_id' => $commentId,
                        'duration_ms' => 0,
                        'error_category' => 'queue_dispatch_failed',
                        'exception_type' => $exception::class,
                    ]);
                }
            }
        }

        if ($queueFailed) {
            throw new FacebookWebhookQueueException('One or more Facebook webhook events could not be queued.');
        }

        return $counts;
    }

    public function processEvent(string $eventKey): void
    {
        $startedAt = microtime(true);
        $jobTimeout = max(30, (int) config('services.facebook.webhook_job_timeout', 60));
        $leaseSeconds = max(
            $jobTimeout + 10,
            (int) config('services.facebook.webhook_processing_lease_seconds', $jobTimeout + 15),
        );
        $staleBefore = now()->subSeconds($leaseSeconds);
        $event = DB::transaction(function () use ($eventKey, $staleBefore): ?FacebookWebhookEvent {
            $event = FacebookWebhookEvent::query()
                ->where('event_key', $eventKey)
                ->lockForUpdate()
                ->first();

            if ($event === null || $event->status === 'processed') {
                return null;
            }

            $staleProcessing = $event->status === 'processing'
                && ($event->processing_started_at === null
                    || $event->processing_started_at->lessThan($staleBefore));
            if ($event->status === 'processing' && ! $staleProcessing) {
                return null;
            }

            if (! in_array($event->status, ['queued', 'dispatch_failed', 'failed'], true) && ! $staleProcessing) {
                return null;
            }

            $event->forceFill([
                'status' => 'processing',
                'error_category' => null,
                'processing_started_at' => now(),
                'attempts' => ((int) $event->attempts) + 1,
            ])->save();

            return $event;
        });

        if ($event === null) {
            return;
        }

        $page = FacebookPage::query()->whereKey($event->page_id)->where('is_active', true)->first();
        if ($page === null) {
            $this->markProcessingFailed($event, 'page_not_connected', $startedAt);

            return;
        }

        try {
            $comment = $this->facebookPageService->getCommentDetails($page, $event->facebook_comment_id);
            $this->facebookPageService->upsertComment(
                $page,
                $event->facebook_comment_id,
                $event->facebook_post_id,
                $comment,
            );

            DB::transaction(function () use ($event, $page): void {
                $event->forceFill([
                    'status' => 'processed',
                    'error_category' => null,
                    'processed_at' => now(),
                ])->save();
                $page->forceFill(['webhook_last_processed_at' => now()])->save();
            });

            Log::info('Facebook webhook comment event processed.', [
                'facebook_page_id' => $event->facebook_page_id,
                'event_type' => $event->event_type,
                'facebook_comment_id' => $event->facebook_comment_id,
                'duration_ms' => $this->elapsedMilliseconds($startedAt),
                'error_category' => null,
            ]);
        } catch (FacebookPageConnectionException $exception) {
            $retry = $exception->retryable
                && (int) $event->attempts < max(1, (int) config('services.facebook.webhook_max_attempts', 5));
            $this->markProcessingFailed(
                $event,
                $this->graphErrorCategory($exception),
                $startedAt,
                null,
                $retry,
            );

            if ($retry) {
                throw $exception;
            }
        } catch (Throwable $exception) {
            $retry = (int) $event->attempts < max(1, (int) config('services.facebook.webhook_max_attempts', 5));
            $this->markProcessingFailed(
                $event,
                'internal_error',
                $startedAt,
                $exception::class,
                $retry,
            );

            if ($retry) {
                throw $exception;
            }
        }
    }

    /** @return array<string, mixed> */
    public function statusFor(User $user): array
    {
        $verifyToken = config('services.facebook.webhook_verify_token');
        $appSecret = config('services.facebook.app_secret');
        $row = DB::table('facebook_webhook_statuses')->where('status_key', 'pages')->first();
        $verifiedAt = null;
        $isVerified = false;

        if (is_string($verifyToken)
            && trim($verifyToken) !== ''
            && $row !== null
            && is_string($row->verify_token_fingerprint)
            && hash_equals($row->verify_token_fingerprint, hash('sha256', $verifyToken))) {
            $isVerified = true;
            $verifiedAt = $row->verified_at;
        }

        $pages = FacebookPage::query()->where('user_id', $user->getKey());
        $pageIds = (clone $pages)->select('id');
        $lastReceivedAt = (clone $pages)->max('webhook_last_received_at');
        $lastProcessedAt = (clone $pages)->max('webhook_last_processed_at');
        $events = DB::table('facebook_webhook_events')->whereIn('page_id', $pageIds);
        $lastError = (clone $events)->whereNotNull('error_category')->orderByDesc('received_at')->value('error_category');
        $appUrl = rtrim((string) config('app.url', ''), '/');

        return [
            'webhook_url' => $appUrl.'/api/facebook/webhook',
            'verification_status' => ! is_string($verifyToken) || trim($verifyToken) === ''
                ? 'not_configured'
                : ($isVerified ? 'verified' : 'pending'),
            'verified_at' => $verifiedAt,
            'app_secret_configured' => is_string($appSecret) && trim($appSecret) !== '',
            'verify_token_configured' => is_string($verifyToken) && trim($verifyToken) !== '',
            'last_received_at' => $lastReceivedAt,
            'last_processed_at' => $lastProcessedAt,
            'total_events' => (clone $events)->count(),
            'processed_events' => (clone $events)->where('status', 'processed')->count(),
            'queued_events' => (clone $events)->whereIn('status', ['queued', 'processing'])->count(),
            'failed_events' => (clone $events)->whereIn('status', ['failed', 'dispatch_failed'])->count(),
            'last_error_category' => $lastError,
        ];
    }

    private function reserveEvent(
        FacebookPage $page,
        string $facebookPageId,
        string $commentId,
        ?string $postId,
        string $eventType,
        string $eventKey,
        mixed $receivedAt,
    ): bool {
        return DB::transaction(function () use (
            $page,
            $facebookPageId,
            $commentId,
            $postId,
            $eventType,
            $eventKey,
            $receivedAt,
        ): bool {
            $inserted = DB::table('facebook_webhook_events')->insertOrIgnore([
                'event_key' => $eventKey,
                'page_id' => $page->getKey(),
                'facebook_page_id' => $facebookPageId,
                'facebook_comment_id' => $commentId,
                'facebook_post_id' => $postId,
                'event_type' => $eventType,
                'status' => 'queued',
                'error_category' => null,
                'attempts' => 0,
                'received_at' => $receivedAt,
                'created_at' => $receivedAt,
                'updated_at' => $receivedAt,
            ]);

            $event = FacebookWebhookEvent::query()
                ->where('event_key', $eventKey)
                ->lockForUpdate()
                ->first();

            if ($event === null) {
                throw new FacebookWebhookQueueException('Unable to reserve the Facebook webhook event.');
            }

            if ($inserted > 0) {
                return true;
            }

            if (in_array($event->status, ['failed', 'dispatch_failed'], true)
                || ($event->status === 'queued' && $event->dispatched_at === null)) {
                $event->forceFill([
                    'status' => 'queued',
                    'error_category' => null,
                    'dispatched_at' => null,
                    'processing_started_at' => null,
                    'processed_at' => null,
                ])->save();

                return true;
            }

            return false;
        });
    }

    /** @param array<string, mixed> $change */
    private function makeEventKey(string $facebookPageId, array $change): string
    {
        $identity = $this->sortRecursively([
            'page_id' => $facebookPageId,
            'change' => $change,
        ]);

        try {
            $encoded = json_encode(
                $identity,
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
            );
        } catch (JsonException) {
            // A decoded JSON value should always be re-encodable, but fail closed if it is not.
            $encoded = serialize($identity);
        }

        return hash('sha256', $encoded);
    }

    private function sortRecursively(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (! array_is_list($value)) {
            ksort($value);
        }

        foreach ($value as $key => $child) {
            $value[$key] = $this->sortRecursively($child);
        }

        return $value;
    }

    private function graphId(mixed $value): ?string
    {
        if (! is_string($value) && ! is_int($value)) {
            return null;
        }

        $id = trim((string) $value);

        return $id !== '' && strlen($id) <= 191 ? $id : null;
    }

    private function markProcessingFailed(
        FacebookWebhookEvent $event,
        string $category,
        float $startedAt,
        ?string $exceptionType = null,
        bool $willRetry = false,
    ): void {
        $event->forceFill([
            'status' => $willRetry ? 'queued' : 'failed',
            'error_category' => $category,
        ])->save();

        $context = [
            'facebook_page_id' => $event->facebook_page_id,
            'event_type' => $event->event_type,
            'facebook_comment_id' => $event->facebook_comment_id,
            'duration_ms' => $this->elapsedMilliseconds($startedAt),
            'error_category' => $category,
            'exception_type' => $exceptionType,
        ];

        if ($willRetry) {
            Log::warning('Facebook webhook comment event failed temporarily; retry queued.', $context);
        } else {
            Log::error('Facebook webhook comment event processing failed.', $context);
        }
    }

    private function graphErrorCategory(FacebookPageConnectionException $exception): string
    {
        if ($exception->metaErrorCode === 190) {
            return str_contains(strtolower($exception->getMessage()), 'expired')
                ? 'expired_token'
                : 'invalid_token';
        }

        if ($exception->statusCode === 429) {
            return 'rate_limited';
        }

        if (in_array($exception->metaErrorCode, [10, 200, 283, 299], true)) {
            return 'permission_error';
        }

        if ($exception->retryable) {
            return 'provider_unavailable';
        }

        return 'graph_api_error';
    }

    private function elapsedMilliseconds(float $startedAt): int
    {
        return (int) round((microtime(true) - $startedAt) * 1000);
    }
}
