<?php

namespace App\Services;

use App\DTO\GeminiModerationResult;
use App\Models\AiModerationLog;
use App\Models\FacebookComment;
use Illuminate\Support\Facades\DB;

final class AiModerationResultStore
{
    public function persist(int $commentId, string $inputHash, GeminiModerationResult $result): void
    {
        DB::transaction(function () use ($commentId, $inputHash, $result): void {
            $comment = FacebookComment::query()->lockForUpdate()->find($commentId);
            if ($comment === null) {
                return;
            }

            AiModerationLog::query()->create([
                'comment_id' => $comment->getKey(),
                'provider' => 'gemini',
                'model' => $result->model,
                'status' => $result->status,
                'decision' => $result->classification['decision'],
                'category' => $result->classification['category'],
                'confidence' => $result->classification['confidence'],
                'severity' => $result->classification['severity'],
                'reason' => $result->classification['reason'],
                'request_metadata' => $result->requestMetadata,
                'response_metadata' => $result->responseMetadata,
                'error_message' => $result->errorCategory,
                'processing_time_ms' => $result->processingTimeMs,
                'attempt_count' => $result->attemptCount,
                'input_hash' => $inputHash,
            ]);

            // Do not let an older queued job overwrite the result for an edited comment.
            if (! is_string($comment->ai_input_hash) || ! hash_equals($comment->ai_input_hash, $inputHash)) {
                return;
            }

            $comment->forceFill([
                'ai_status' => $result->status,
                'ai_action' => $result->classification['decision'],
                'ai_category' => $result->classification['category'],
                'ai_confidence' => $result->classification['confidence'],
                'ai_severity' => $result->classification['severity'],
                'ai_reason' => $result->classification['reason'],
                'ai_checked_at' => now(),
                'ai_processing_started_at' => null,
            ])->save();
        });
    }
}
