<?php

namespace App\Services;

use App\DTO\GeminiModerationResult;
use App\Jobs\ModerateCommentWithGeminiJob;
use App\Models\FacebookComment;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

final class AiModerationQueueService
{
    private const DECISIVE_MANUAL_ACTIONS = ['keep', 'review', 'hide', 'delete'];

    public function __construct(
        private readonly GeminiModerationService $geminiModerationService,
        private readonly AiModerationResultStore $resultStore,
        private readonly PageModerationSettingsService $settingsService,
    ) {
    }

    /** Schedule AI only after the stored manual decision has been checked. */
    public function schedule(FacebookComment $comment): void
    {
        $commentId = (int) $comment->getKey();
        $shouldDispatch = false;
        $inputHash = '';

        DB::transaction(function () use ($commentId, &$shouldDispatch, &$inputHash): void {
            $stored = FacebookComment::query()
                ->with(['page', 'post'])
                ->lockForUpdate()
                ->find($commentId);
            if ($stored === null) {
                return;
            }

            $manualResult = $this->manualResult($stored);
            $inputHash = $this->geminiModerationService->inputHash(
                is_string($stored->message) ? $stored->message : '',
                $stored->page?->page_name,
                $stored->post?->post_message,
                $manualResult,
            );

            if ($this->isManualDecisive($stored)) {
                $skip = ['ai_processing_started_at' => null];
                if (! in_array($stored->ai_status, ['completed', 'failed'], true)) {
                    $skip['ai_status'] = 'skipped';
                }
                $stored->forceFill($skip)->save();

                return;
            }

            if (! $this->isEnabledFor($stored)) {
                $stored->forceFill($this->skippedFields())->save();

                return;
            }

            $sameInput = is_string($stored->ai_input_hash)
                && hash_equals($stored->ai_input_hash, $inputHash);
            if ($sameInput) {
                if (in_array($stored->ai_status, ['pending', 'processing', 'completed', 'failed'], true)) {
                    return;
                }
            }

            $stored->forceFill([
                'ai_status' => 'pending',
                'ai_action' => null,
                'ai_category' => null,
                'ai_confidence' => null,
                'ai_severity' => null,
                'ai_reason' => null,
                'ai_checked_at' => null,
                'ai_input_hash' => $inputHash,
                'ai_processing_started_at' => null,
            ])->save();
            $shouldDispatch = true;
        });

        if (! $shouldDispatch) {
            return;
        }

        try {
            // The job payload contains only the local comment row ID—never text or tokens.
            ModerateCommentWithGeminiJob::dispatch($commentId);
        } catch (Throwable) {
            $fallback = new GeminiModerationResult(
                [
                    'decision' => 'review',
                    'category' => 'other',
                    'confidence' => 0.0,
                    'severity' => 'low',
                    'reason' => 'AI moderation unavailable',
                ],
                'failed',
                'queue_dispatch_failed',
                (string) config('ai_moderation.model', 'gemini-3.8-flash'),
                0,
                0,
                [],
                [],
            );
            $this->resultStore->persist($commentId, $inputHash, $fallback);
            Log::warning('Gemini moderation job could not be queued.', [
                'comment_row_id' => $commentId,
                'error_category' => 'queue_dispatch_failed',
            ]);
        }
    }

    public function isManualDecisive(FacebookComment $comment): bool
    {
        return $comment->manual_moderation_status === 'matched'
            && in_array($comment->manual_action, self::DECISIVE_MANUAL_ACTIONS, true);
    }

    public function isEnabledFor(FacebookComment $comment): bool
    {
        if (! (bool) config('ai_moderation.enabled', false)
            || $comment->page === null
            || ! (bool) $comment->page->is_active) {
            return false;
        }

        return (bool) $this->settingsService->forPage($comment->page)->ai_enabled;
    }

    /** @return array{matched: bool, action: string, category: ?string, severity: ?string} */
    public function manualResult(FacebookComment $comment): array
    {
        if ($comment->manual_moderation_status !== 'matched') {
            return [
                'matched' => false,
                'action' => 'none',
                'category' => null,
                'severity' => null,
            ];
        }

        return [
            'matched' => true,
            'action' => is_string($comment->manual_action) ? $comment->manual_action : 'none',
            'category' => is_string($comment->manual_category) ? $comment->manual_category : null,
            'severity' => is_string($comment->manual_severity) ? $comment->manual_severity : null,
        ];
    }

    /** @return array<string, mixed> */
    private function skippedFields(): array
    {
        // Keep any prior AI outcome as audit history; skipped means it was not rerun now.
        return [
            'ai_status' => 'skipped',
            'ai_processing_started_at' => null,
        ];
    }
}
