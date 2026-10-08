<?php

namespace App\Jobs;

use App\Services\FacebookCommentActionService;
use App\Support\AsyncQueueConnection;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

abstract class AbstractFacebookCommentActionJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries;

    public int $timeout = 45;

    public int $commentId;

    public int $actionLogId;

    public function __construct(int $commentId, int $actionLogId)
    {
        $this->commentId = $commentId;
        $this->actionLogId = $actionLogId;
        $this->tries = max(1, min(5, (int) config('moderation.action_max_attempts', 3)));

        $connection = AsyncQueueConnection::resolve(
            (string) config('moderation.action_queue_connection', 'database'),
        );
        $this->onConnection($connection);
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [10, 60, 180];
    }

    final public function handle(FacebookCommentActionService $actionService): void
    {
        $actionService->execute(
            $this->commentId,
            $this->actionLogId,
            $this->actionName(),
            max(1, $this->attempts()),
            $this->tries,
        );
    }

    public function failed(?Throwable $exception): void
    {
        app(FacebookCommentActionService::class)->markJobFailed($this->actionLogId, $exception);
    }

    abstract protected function actionName(): string;
}
