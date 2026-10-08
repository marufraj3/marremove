<?php

namespace App\Jobs;

use App\DTO\GeminiModerationResult;
use App\Models\FacebookComment;
use App\Services\AiModerationQueueService;
use App\Services\AiModerationResultStore;
use App\Services\CommentModerationService;
use App\Services\GeminiModerationService;
use App\Support\AsyncQueueConnection;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

final class ModerateCommentWithGeminiJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 2;

    public int $timeout;

    public function __construct(public readonly int $commentId)
    {
        $requestTimeout = (int) config('ai_moderation.timeout', 20);
        $maxRetries = (int) config('ai_moderation.max_retries', 3);
        // Allow the worst-case capped exponential backoff as well as each HTTP timeout.
        $retryBudgetSeconds = $maxRetries * 5;
        $this->timeout = max(30, ($requestTimeout * ($maxRetries + 1)) + $retryBudgetSeconds + 20);

        // Keep provider calls off the webhook request even if the application's default is `sync`.
        $connection = AsyncQueueConnection::resolve(
            (string) config('ai_moderation.queue_connection', 'database'),
        );
        $this->onConnection($connection);
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [10, 30];
    }

    public function handle(
        GeminiModerationService $geminiModerationService,
        AiModerationQueueService $queueService,
        AiModerationResultStore $resultStore,
        CommentModerationService $commentModerationService,
    ): void {
        $context = $this->claim($geminiModerationService, $queueService);
        if ($context === null) {
            return;
        }

        $result = $geminiModerationService->classify(
            $context['comment_text'],
            $context['page_name'],
            $context['post_text'],
            $context['manual_result'],
        );
        $resultStore->persist($this->commentId, $context['input_hash'], $result);

        $storedComment = FacebookComment::query()->find($this->commentId);
        if ($storedComment !== null) {
            $commentModerationService->moderateComment($storedComment);
        }
    }

    public function failed(?Throwable $exception): void
    {
        $comment = FacebookComment::query()->with(['page', 'post'])->find($this->commentId);
        if ($comment === null || in_array($comment->ai_status, ['completed', 'skipped'], true)) {
            return;
        }

        $manualResult = app(AiModerationQueueService::class)->manualResult($comment);
        $inputHash = is_string($comment->ai_input_hash) && $comment->ai_input_hash !== ''
            ? $comment->ai_input_hash
            : app(GeminiModerationService::class)->inputHash(
                is_string($comment->message) ? $comment->message : '',
                $comment->page?->page_name,
                $comment->post?->post_message,
                $manualResult,
            );
        $result = new GeminiModerationResult(
            [
                'decision' => 'review',
                'category' => 'other',
                'confidence' => 0.0,
                'severity' => 'low',
                'reason' => 'AI moderation unavailable',
            ],
            'failed',
            'job_failed',
            (string) config('ai_moderation.model', 'gemini-3.8-flash'),
            0,
            0,
            [],
            [],
        );
        app(AiModerationResultStore::class)->persist($this->commentId, $inputHash, $result);

        $storedComment = FacebookComment::query()->find($this->commentId);
        if ($storedComment !== null) {
            app(CommentModerationService::class)->moderateComment($storedComment);
        }

        Log::error('Gemini moderation queue job failed safely.', [
            'comment_row_id' => $this->commentId,
            'error_category' => 'job_failed',
            'exception_type' => $exception === null ? null : $exception::class,
        ]);
    }

    /** @return array{comment_text: string, page_name: ?string, post_text: ?string, manual_result: array<string, mixed>, input_hash: string}|null */
    private function claim(
        GeminiModerationService $geminiModerationService,
        AiModerationQueueService $queueService,
    ): ?array {
        return DB::transaction(function () use ($geminiModerationService, $queueService): ?array {
            $comment = FacebookComment::query()
                ->with(['page', 'post'])
                ->lockForUpdate()
                ->find($this->commentId);
            if ($comment === null) {
                return null;
            }

            $manualResult = $queueService->manualResult($comment);
            $inputHash = $geminiModerationService->inputHash(
                is_string($comment->message) ? $comment->message : '',
                $comment->page?->page_name,
                $comment->post?->post_message,
                $manualResult,
            );

            if ($queueService->isManualDecisive($comment)) {
                $skip = ['ai_processing_started_at' => null];
                if (! in_array($comment->ai_status, ['completed', 'failed'], true)) {
                    $skip['ai_status'] = 'skipped';
                }
                $comment->forceFill($skip)->save();

                return null;
            }

            if (! $queueService->isEnabledFor($comment)) {
                $comment->forceFill([
                    'ai_status' => 'skipped',
                    'ai_processing_started_at' => null,
                ])->save();

                return null;
            }

            if ((bool) $comment->manual_override
                && is_string($comment->manual_override_hash)
                && hash_equals($comment->manual_override_hash, $inputHash)) {
                $comment->forceFill([
                    'ai_status' => 'skipped',
                    'ai_processing_started_at' => null,
                ])->save();

                return null;
            }

            $sameInput = is_string($comment->ai_input_hash)
                && hash_equals($comment->ai_input_hash, $inputHash);
            if ($sameInput && in_array($comment->ai_status, ['completed', 'failed'], true)) {
                return null;
            }

            $attempt = max(1, $this->attempts());
            if ($sameInput
                && $comment->ai_status === 'processing'
                && $comment->ai_processing_started_at !== null
                && $comment->ai_processing_started_at->greaterThan(now()->subMinutes(10))
                && $attempt <= 1) {
                // A separately enqueued duplicate must not make a second provider call.
                return null;
            }

            $comment->forceFill([
                'ai_status' => 'processing',
                'ai_action' => null,
                'ai_category' => null,
                'ai_confidence' => null,
                'ai_severity' => null,
                'ai_reason' => null,
                'ai_checked_at' => null,
                'ai_input_hash' => $inputHash,
                'ai_processing_started_at' => now(),
            ])->save();

            return [
                'comment_text' => is_string($comment->message) ? $comment->message : '',
                'page_name' => is_string($comment->page?->page_name) ? $comment->page->page_name : null,
                'post_text' => is_string($comment->post?->post_message) ? $comment->post->post_message : null,
                'manual_result' => $manualResult,
                'input_hash' => $inputHash,
            ];
        });
    }
}
