<?php

namespace App\Jobs;

use App\Models\FacebookWebhookEvent;
use App\Services\FacebookWebhookService;
use App\Support\AsyncQueueConnection;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

final class ProcessFacebookWebhookJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries;

    public int $timeout;

    public function __construct(public readonly string $eventKey)
    {
        $this->tries = max(1, min(10, (int) config('services.facebook.webhook_max_attempts', 5)));
        $this->timeout = max(30, (int) config('services.facebook.webhook_job_timeout', 60));
        $this->onConnection(AsyncQueueConnection::resolve(
            (string) config('services.facebook.webhook_queue_connection', 'database'),
        ));
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [10, 30, 60, 120];
    }

    public function handle(FacebookWebhookService $facebookWebhookService): void
    {
        $facebookWebhookService->processEvent($this->eventKey);
    }

    public function failed(?Throwable $exception): void
    {
        $event = FacebookWebhookEvent::query()
            ->where('event_key', $this->eventKey)
            ->first();

        if ($event === null || $event->status === 'processed') {
            return;
        }

        $durationMs = $event->processing_started_at === null
            ? 0
            : max(0, (int) round((microtime(true) - $event->processing_started_at->getTimestamp()) * 1000));

        $event->forceFill([
            'status' => 'failed',
            'error_category' => 'job_failed',
        ])->save();

        Log::error('Facebook webhook job failed outside its handler.', [
            'facebook_page_id' => $event->facebook_page_id,
            'event_type' => $event->event_type,
            'facebook_comment_id' => $event->facebook_comment_id,
            'duration_ms' => $durationMs,
            'error_category' => 'job_failed',
            'exception_type' => $exception === null ? null : $exception::class,
        ]);
    }
}
