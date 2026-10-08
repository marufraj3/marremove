<?php

namespace App\Jobs;

use App\Exceptions\FacebookPageConnectionException;
use App\Models\FacebookPage;
use App\Services\FacebookPageService;
use App\Support\AsyncQueueConnection;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

final class SyncFacebookPageJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries;

    public int $timeout = 900;

    public function __construct(public readonly int $pageId)
    {
        $this->tries = max(1, min(5, (int) config('services.facebook.sync_max_attempts', 3)));
        $this->onConnection(AsyncQueueConnection::resolve(
            (string) config('services.facebook.sync_queue_connection', 'database'),
        ));
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [15, 60, 180, 300];
    }

    public function handle(FacebookPageService $facebookPageService): void
    {
        $page = FacebookPage::query()->find($this->pageId);
        if ($page === null || ! (bool) $page->is_active) {
            return;
        }

        $startedAt = microtime(true);
        $page->forceFill([
            'sync_status' => 'running',
            'sync_error' => null,
            'last_sync_started_at' => now(),
            'posts_synced_count' => 0,
            'comments_synced_count' => 0,
        ])->save();

        try {
            $postsSynced = $facebookPageService->syncPagePosts($page);
            $page->forceFill(['posts_synced_count' => $postsSynced])->save();

            $commentsSynced = $facebookPageService->syncPageComments($page);
            $page->forceFill([
                'sync_status' => 'completed',
                'sync_error' => null,
                'posts_synced_count' => $postsSynced,
                'comments_synced_count' => $commentsSynced,
                'last_synced_at' => now(),
            ])->save();

            Log::info('Facebook Page sync completed.', [
                'facebook_page_id' => $page->facebook_page_id,
                'posts_synced' => $postsSynced,
                'comments_synced' => $commentsSynced,
                'duration_ms' => $this->durationMilliseconds($startedAt),
            ]);
        } catch (FacebookPageConnectionException $exception) {
            $willRetry = $exception->retryable && max(1, $this->attempts()) < $this->tries;
            $page->forceFill([
                'sync_status' => $willRetry ? 'queued' : 'failed',
                'sync_error' => $willRetry ? null : $exception->getMessage(),
            ])->save();

            $context = [
                'facebook_page_id' => $page->facebook_page_id,
                'exception_type' => $exception::class,
                'http_status' => $exception->statusCode,
                'meta_error_code' => $exception->metaErrorCode,
                'will_retry' => $willRetry,
                'duration_ms' => $this->durationMilliseconds($startedAt),
            ];
            if ($willRetry) {
                Log::warning('Facebook Page sync failed temporarily; retry queued.', $context);
                throw $exception;
            }

            Log::warning('Facebook Page sync failed.', $context);
        } catch (Throwable $exception) {
            $willRetry = max(1, $this->attempts()) < $this->tries;
            $page->forceFill([
                'sync_status' => $willRetry ? 'queued' : 'failed',
                'sync_error' => $willRetry
                    ? null
                    : 'Sync failed because of an unexpected server error. Please try again.',
            ])->save();

            $context = [
                'facebook_page_id' => $page->facebook_page_id,
                'exception_type' => $exception::class,
                'will_retry' => $willRetry,
                'duration_ms' => $this->durationMilliseconds($startedAt),
            ];
            if ($willRetry) {
                Log::warning('Facebook Page sync failed unexpectedly; retry queued.', $context);
                throw $exception;
            }

            Log::error('Facebook Page sync failed unexpectedly.', $context);
        }
    }

    public function failed(?Throwable $exception): void
    {
        $page = FacebookPage::query()->find($this->pageId);
        if ($page === null) {
            return;
        }

        $page->forceFill([
            'sync_status' => 'failed',
            'sync_error' => 'Sync failed because of an unexpected server error. Please try again.',
        ])->save();

        Log::error('Facebook Page sync job exhausted or failed outside its handler.', [
            'facebook_page_id' => $page->facebook_page_id,
            'exception_type' => $exception === null ? null : $exception::class,
        ]);
    }

    private function durationMilliseconds(float $startedAt): int
    {
        return max(0, (int) round((microtime(true) - $startedAt) * 1000));
    }
}
