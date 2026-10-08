<?php

namespace App\Services;

use App\Models\FacebookComment;
use App\Models\ModerationLog;
use App\Models\PageModerationSetting;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

final class CommentModerationService
{
    private const SAFE_CATEGORIES = ['clean', 'customer_complaint', 'negative_feedback', 'irrelevant', 'suspicious', 'other'];

    private const RISK_CATEGORIES = [
        'profanity',
        'insult',
        'harassment',
        'sexual',
        'hate',
        'threat',
        'spam',
        'scam',
        'competitor_spam',
    ];

    public function __construct(
        private readonly ManualModerationService $manualModerationService,
        private readonly AiModerationQueueService $aiModerationQueueService,
        private readonly GeminiModerationService $geminiModerationService,
        private readonly PageModerationSettingsService $settingsService,
        private readonly FacebookCommentActionService $facebookCommentActionService,
    ) {
    }

    /**
     * Run the central, asynchronous decision pipeline for a stored comment.
     * The Gemini request is queued by AiModerationQueueService and is never made here.
     *
     * @return array<string, mixed>
     */
    public function moderateComment(FacebookComment $comment): array
    {
        $startedAt = microtime(true);
        $stored = FacebookComment::query()->with(['page', 'post'])->find($comment->getKey());
        if ($stored === null || $stored->page === null) {
            return $this->persistUnavailableComment($comment, $startedAt);
        }

        $settings = $this->settingsService->forPage($stored->page);
        $this->evaluateManual($stored, $settings);
        $stored->refresh()->load(['page', 'post']);
        $manualSnapshot = $this->manualSnapshot($stored);
        $aiInputHash = $this->aiInputHash($stored, $manualSnapshot, $settings);

        if ($this->hasCurrentManualOverride($stored, $aiInputHash)) {
            return $this->resultFromComment($stored);
        }

        if ((bool) $stored->manual_override) {
            $stored->forceFill([
                'manual_override' => false,
                'overridden_by' => null,
                'overridden_at' => null,
                'manual_override_hash' => null,
            ])->save();
        }

        // The queue service persists pending/skipped/completed status and dispatches only a local row ID.
        $this->aiModerationQueueService->schedule($stored);
        $stored = $stored->fresh(['page', 'post']) ?? $stored;
        $manualSnapshot = $this->manualSnapshot($stored);
        $aiSnapshot = $this->aiSnapshot($stored);
        $decisionHash = $this->decisionHash($aiInputHash, $settings, $manualSnapshot, $aiSnapshot);

        if ($this->aiModerationQueueService->isManualDecisive($stored)) {
            return $this->saveFinalDecision(
                $stored,
                $manualSnapshot,
                $aiSnapshot,
                $this->manualDecision($stored),
                $decisionHash,
                $aiInputHash,
                $startedAt,
            );
        }

        if ($stored->ai_status === 'completed') {
            return $this->saveFinalDecision(
                $stored,
                $manualSnapshot,
                $aiSnapshot,
                $this->aiDecision($stored, $settings),
                $decisionHash,
                $aiInputHash,
                $startedAt,
            );
        }

        if ($stored->ai_status === 'failed') {
            return $this->saveFinalDecision(
                $stored,
                $manualSnapshot,
                $aiSnapshot,
                $this->fallbackDecision('AI unavailable'),
                $decisionHash,
                $aiInputHash,
                $startedAt,
            );
        }

        if ($stored->ai_status === 'skipped') {
            if ($stored->manual_moderation_status === 'matched' && $stored->manual_action === 'review') {
                return $this->saveFinalDecision(
                    $stored,
                    $manualSnapshot,
                    $aiSnapshot,
                    $this->manualDecision($stored),
                    $decisionHash,
                    $aiInputHash,
                    $startedAt,
                );
            }

            return $this->saveFinalDecision(
                $stored,
                $manualSnapshot,
                $aiSnapshot,
                $this->fallbackDecision('AI unavailable'),
                $decisionHash,
                $aiInputHash,
                $startedAt,
            );
        }

        $this->markPending($stored, $decisionHash, $aiInputHash);

        return [
            'action' => null,
            'method' => null,
            'category' => null,
            'confidence' => null,
            'severity' => null,
            'reason' => 'AI moderation is pending.',
            'rule_id' => null,
            'source' => null,
            'status' => 'pending',
            'threshold_name' => null,
            'threshold_value' => null,
            'manual_override' => false,
        ];
    }

    /** Apply an authenticated administrator's final action without calling Facebook. */
    public function overrideComment(
        FacebookComment $comment,
        string $action,
        User $user,
        ?string $reason = null,
    ): array {
        if (! in_array($action, ['keep', 'review', 'hide', 'delete'], true)) {
            throw new \InvalidArgumentException('Unsupported final moderation action.');
        }

        $stored = FacebookComment::query()->with(['page', 'post'])->findOrFail($comment->getKey());
        $settings = $stored->page === null ? null : $this->settingsService->forPage($stored->page);

        // An override changes only the final recommendation. Keep the manual and AI audit
        // fields untouched so the administrator can still inspect the original evidence.
        $manualSnapshot = $this->manualSnapshot($stored);
        $aiSnapshot = $this->aiSnapshot($stored);
        $aiInputHash = $this->aiInputHash($stored, $manualSnapshot, $settings);
        $actionLabel = strtoupper($action);
        $cleanReason = $reason !== null ? trim($reason) : '';
        $finalReason = $cleanReason !== ''
            ? 'Admin override: '.$cleanReason
            : 'Admin override: final decision set to '.$actionLabel.'.';
        $decision = [
            'action' => $action,
            'method' => 'manual',
            'category' => is_string($stored->ai_category)
                ? $stored->ai_category
                : (is_string($stored->manual_category) ? $stored->manual_category : 'other'),
            'confidence' => null,
            'severity' => is_string($stored->ai_severity)
                ? $stored->ai_severity
                : (is_string($stored->manual_severity) ? $stored->manual_severity : 'low'),
            'reason' => $this->limitText($finalReason, 320),
            'rule_id' => null,
            'source' => 'manual_override',
            'status' => 'completed',
            'threshold_name' => null,
            'threshold_value' => null,
            'manual_override' => true,
            'overridden_by' => (int) $user->getKey(),
            'overridden_at' => now()->toISOString(),
        ];
        $decisionHash = $this->decisionHash($aiInputHash, $settings, $manualSnapshot, $aiSnapshot, $decision);

        $result = DB::transaction(function () use (
            $stored,
            $manualSnapshot,
            $aiSnapshot,
            $decision,
            $decisionHash,
            $aiInputHash,
            $user,
        ): array {
            $locked = FacebookComment::query()->lockForUpdate()->find($stored->getKey());
            if ($locked === null) {
                return $decision;
            }

            $overriddenAt = now();
            $locked->forceFill([
                'final_status' => 'completed',
                'final_action' => $decision['action'],
                'final_method' => $decision['method'],
                'final_source' => $decision['source'],
                'final_category' => $decision['category'],
                'final_confidence' => null,
                'final_severity' => $decision['severity'],
                'final_reason' => $decision['reason'],
                'final_threshold_name' => null,
                'final_threshold_value' => null,
                'final_decision_at' => $overriddenAt,
                'final_input_hash' => $decisionHash,
                'manual_override' => true,
                'overridden_by' => $user->getKey(),
                'overridden_at' => $overriddenAt,
                'manual_override_hash' => $aiInputHash,
            ])->save();

            $decision['overridden_at'] = $overriddenAt->toISOString();
            ModerationLog::query()->create([
                'comment_id' => $locked->getKey(),
                'actor_id' => $user->getKey(),
                'manual_result' => $manualSnapshot,
                'ai_result' => $aiSnapshot,
                'final_result' => $decision,
                'processing_time_ms' => 0,
                'source' => 'manual_override',
                'input_hash' => $decisionHash,
            ]);

            return $this->resultFromComment($locked->fresh() ?? $locked);
        });

        $this->queueFacebookActionSafely((int) $stored->getKey());

        return $result;
    }

    /** @return array<string, mixed> */
    public function manualSnapshot(FacebookComment $comment): array
    {
        return [
            'status' => $comment->manual_moderation_status,
            'matched' => $comment->manual_moderation_status === 'matched',
            'action' => $comment->manual_action,
            'category' => $comment->manual_category,
            'severity' => $comment->manual_severity,
            'reason' => $comment->manual_reason,
            'rule_id' => $comment->manual_rule_id === null ? null : (int) $comment->manual_rule_id,
        ];
    }

    /** @return array<string, mixed> */
    public function aiSnapshot(FacebookComment $comment): array
    {
        return [
            'status' => $comment->ai_status,
            'decision' => $comment->ai_action,
            'category' => $comment->ai_category,
            'confidence' => $comment->ai_confidence === null ? null : (float) $comment->ai_confidence,
            'severity' => $comment->ai_severity,
            'reason' => $comment->ai_reason,
        ];
    }

    /** @return array<string, mixed> */
    public function resultFromComment(FacebookComment $comment): array
    {
        return [
            'action' => $comment->final_action,
            'method' => $comment->final_method,
            'category' => $comment->final_category,
            'confidence' => $comment->final_confidence === null ? null : (float) $comment->final_confidence,
            'severity' => $comment->final_severity,
            'reason' => $comment->final_reason,
            'rule_id' => $comment->final_source === 'manual_rule' && $comment->manual_rule_id !== null
                ? (int) $comment->manual_rule_id
                : null,
            'source' => $comment->final_source,
            'status' => $comment->final_status ?? 'pending',
            'threshold_name' => $comment->final_threshold_name,
            'threshold_value' => $comment->final_threshold_value === null ? null : (float) $comment->final_threshold_value,
            'manual_override' => (bool) $comment->manual_override,
            'overridden_by' => $comment->overridden_by === null ? null : (int) $comment->overridden_by,
            'overridden_at' => $comment->overridden_at?->toISOString(),
        ];
    }

    /** @return array<string, mixed> */
    private function evaluateManual(FacebookComment $comment, PageModerationSetting $settings): array
    {
        if ($settings->manual_enabled) {
            return $this->manualModerationService->evaluateAndPersistComment($comment);
        }

        // Preserve the previous manual detail fields for audit, but make clear they are not active.
        $comment->forceFill([
            'manual_moderation_status' => 'disabled',
            'manual_checked_at' => now(),
        ])->save();

        return [
            'matched' => false,
            'action' => 'none',
            'category' => null,
            'severity' => null,
            'confidence' => 0.0,
            'reason' => null,
            'rule_id' => null,
        ];
    }

    /** @return array<string, mixed> */
    private function manualDecision(FacebookComment $comment): array
    {
        return [
            'action' => in_array($comment->manual_action, ['keep', 'review', 'hide', 'delete'], true)
                ? $comment->manual_action
                : 'review',
            'method' => 'manual',
            'category' => is_string($comment->manual_category) ? $comment->manual_category : 'other',
            'confidence' => 1.0,
            'severity' => is_string($comment->manual_severity) ? $comment->manual_severity : 'low',
            'reason' => is_string($comment->manual_reason) && $comment->manual_reason !== ''
                ? $this->limitText($comment->manual_reason, 320)
                : 'Manual moderation rule matched.',
            'rule_id' => $comment->manual_rule_id === null ? null : (int) $comment->manual_rule_id,
            'source' => 'manual_rule',
            'status' => 'completed',
            'threshold_name' => null,
            'threshold_value' => null,
            'manual_override' => false,
        ];
    }

    /** @return array<string, mixed> */
    private function aiDecision(FacebookComment $comment, PageModerationSetting $settings): array
    {
        $category = is_string($comment->ai_category) ? $comment->ai_category : 'other';
        $confidence = is_numeric($comment->ai_confidence) ? (float) $comment->ai_confidence : 0.0;
        $severity = is_string($comment->ai_severity) ? $comment->ai_severity : 'low';
        $aiAction = is_string($comment->ai_action) && in_array($comment->ai_action, ['keep', 'review', 'hide', 'delete'], true)
            ? $comment->ai_action
            : 'review';
        $reviewThreshold = (float) $settings->auto_review_threshold;
        $hideThreshold = (float) $settings->auto_hide_threshold;
        $deleteThreshold = (float) $settings->auto_delete_threshold;
        $safe = in_array($category, self::SAFE_CATEGORIES, true);
        $risk = in_array($category, self::RISK_CATEGORIES, true);

        // Category and confidence are guards, not substitutes for Gemini's recommendation.
        // In particular, a high-confidence "review" must never be escalated to hide/delete.
        if ($confidence < $reviewThreshold) {
            return $this->aiFinal('review', $category, $confidence, $severity,
                'AI confidence is below the review threshold; human review is required.',
                'auto_review_threshold', $reviewThreshold);
        }

        if ($aiAction === 'keep') {
            return $this->aiFinal('keep', $category, $confidence, $severity,
                'AI recommended keeping the comment visible and confidence met the review threshold.',
                'auto_review_threshold', $reviewThreshold);
        }

        if ($safe) {
            return $this->aiFinal('review', $category, $confidence, $severity,
                'This category is protected from automatic hide or delete and requires human review.',
                'protected_category', null);
        }

        if (! $risk || $aiAction === 'review') {
            return $this->aiFinal('review', $category, $confidence, $severity,
                'AI recommended human review; confidence alone cannot escalate the decision.',
                'auto_review_threshold', $reviewThreshold);
        }

        if ($aiAction === 'delete' && $settings->allow_ai_delete && $confidence >= $deleteThreshold) {
            return $this->aiFinal('delete', $category, $confidence, $severity,
                'AI recommended delete and confidence met or exceeded the enabled delete threshold.',
                'auto_delete_threshold', $deleteThreshold);
        }

        if (in_array($aiAction, ['hide', 'delete'], true)
            && $settings->allow_ai_hide
            && $confidence >= $hideThreshold) {
            return $this->aiFinal('hide', $category, $confidence, $severity,
                'AI recommended hide or delete and confidence met or exceeded the enabled hide threshold.',
                'auto_hide_threshold', $hideThreshold);
        }

        return $this->aiFinal('review', $category, $confidence, $severity,
            'The AI recommendation did not meet an enabled automatic action threshold; human review is required.',
            'auto_review_threshold', $reviewThreshold);
    }

    /** @return array<string, mixed> */
    private function aiFinal(
        string $action,
        string $category,
        float $confidence,
        string $severity,
        string $reason,
        ?string $thresholdName,
        ?float $thresholdValue,
    ): array {
        return [
            'action' => $action,
            'method' => 'ai',
            'category' => $category,
            'confidence' => $confidence,
            'severity' => $severity,
            'reason' => $reason,
            'rule_id' => null,
            'source' => 'gemini',
            'status' => 'completed',
            'threshold_name' => $thresholdName,
            'threshold_value' => $thresholdValue,
            'manual_override' => false,
        ];
    }

    /** @return array<string, mixed> */
    private function fallbackDecision(string $reason): array
    {
        return [
            'action' => 'review',
            'method' => 'fallback',
            'category' => 'other',
            'confidence' => 0.0,
            'severity' => 'low',
            'reason' => $reason,
            'rule_id' => null,
            'source' => 'fallback',
            'status' => 'completed',
            'threshold_name' => null,
            'threshold_value' => null,
            'manual_override' => false,
        ];
    }

    /** @return array<string, mixed> */
    private function saveFinalDecision(
        FacebookComment $comment,
        array $manualResult,
        array $aiResult,
        array $decision,
        string $decisionHash,
        string $aiInputHash,
        float $startedAt,
    ): array {
        $result = DB::transaction(function () use (
            $comment,
            $manualResult,
            $aiResult,
            $decision,
            $decisionHash,
            $aiInputHash,
            $startedAt,
        ): array {
            $locked = FacebookComment::query()->lockForUpdate()->find($comment->getKey());
            if ($locked === null) {
                return $decision;
            }

            if ($this->hasCurrentManualOverride($locked, $aiInputHash)) {
                return $this->resultFromComment($locked);
            }

            if ($locked->final_status === 'completed'
                && is_string($locked->final_input_hash)
                && hash_equals($locked->final_input_hash, $decisionHash)) {
                return $this->resultFromComment($locked);
            }

            $locked->forceFill([
                'final_status' => 'completed',
                'final_action' => $decision['action'],
                'final_method' => $decision['method'],
                'final_source' => $decision['source'],
                'final_category' => $decision['category'],
                'final_confidence' => $decision['confidence'],
                'final_severity' => $decision['severity'],
                'final_reason' => $decision['reason'],
                'final_threshold_name' => $decision['threshold_name'],
                'final_threshold_value' => $decision['threshold_value'],
                'final_decision_at' => now(),
                'final_input_hash' => $decisionHash,
                'manual_override' => false,
                'overridden_by' => null,
                'overridden_at' => null,
                'manual_override_hash' => null,
            ])->save();

            $decision['processing_time_ms'] = max(0, (int) round((microtime(true) - $startedAt) * 1000));
            ModerationLog::query()->create([
                'comment_id' => $locked->getKey(),
                'manual_result' => $manualResult,
                'ai_result' => $aiResult,
                'final_result' => $decision,
                'processing_time_ms' => $decision['processing_time_ms'],
                'source' => $decision['source'],
                'input_hash' => $decisionHash,
            ]);

            return $this->resultFromComment($locked->fresh() ?? $locked);
        });

        $this->queueFacebookActionSafely((int) $comment->getKey());

        return $result;
    }

    private function markPending(FacebookComment $comment, string $decisionHash, string $aiInputHash): void
    {
        DB::transaction(function () use ($comment, $decisionHash, $aiInputHash): void {
            $locked = FacebookComment::query()->lockForUpdate()->find($comment->getKey());
            if ($locked === null || $this->hasCurrentManualOverride($locked, $aiInputHash)) {
                return;
            }

            if ($locked->final_status === 'completed'
                && is_string($locked->final_input_hash)
                && hash_equals($locked->final_input_hash, $decisionHash)) {
                return;
            }

            $locked->forceFill([
                'final_status' => 'pending',
                'final_action' => null,
                'final_method' => null,
                'final_source' => null,
                'final_category' => null,
                'final_confidence' => null,
                'final_severity' => null,
                'final_reason' => 'AI moderation is pending.',
                'final_threshold_name' => null,
                'final_threshold_value' => null,
                'final_decision_at' => null,
                'final_input_hash' => $decisionHash,
                'manual_override' => false,
                'overridden_by' => null,
                'overridden_at' => null,
                'manual_override_hash' => null,
            ])->save();
        });
    }

    private function queueFacebookActionSafely(int $commentId): void
    {
        try {
            $comment = FacebookComment::query()->with('page')->find($commentId);
            if ($comment !== null) {
                $this->facebookCommentActionService->queueAutomaticFinalAction($comment);
            }
        } catch (Throwable $exception) {
            Log::warning('Automatic Facebook moderation action could not be queued.', [
                'comment_row_id' => $commentId,
                'exception_type' => $exception::class,
            ]);
        }
    }

    /** @return array<string, mixed> */
    private function persistUnavailableComment(FacebookComment $comment, float $startedAt): array
    {
        $decision = $this->fallbackDecision('AI unavailable');

        return DB::transaction(function () use ($comment, $decision, $startedAt): array {
            $locked = FacebookComment::query()->with(['page', 'post'])->lockForUpdate()->find($comment->getKey());
            if ($locked === null) {
                return $decision;
            }

            $manualSnapshot = $this->manualSnapshot($locked);
            $aiSnapshot = $this->aiSnapshot($locked);
            $aiInputHash = $this->aiInputHash($locked, $manualSnapshot, null);
            $decisionHash = $this->decisionHash($aiInputHash, null, $manualSnapshot, $aiSnapshot);
            if ($locked->final_status === 'completed'
                && is_string($locked->final_input_hash)
                && hash_equals($locked->final_input_hash, $decisionHash)) {
                return $this->resultFromComment($locked);
            }

            $locked->forceFill([
                'final_status' => 'completed',
                'final_action' => $decision['action'],
                'final_method' => $decision['method'],
                'final_source' => $decision['source'],
                'final_category' => $decision['category'],
                'final_confidence' => $decision['confidence'],
                'final_severity' => $decision['severity'],
                'final_reason' => $decision['reason'],
                'final_threshold_name' => null,
                'final_threshold_value' => null,
                'final_decision_at' => now(),
                'final_input_hash' => $decisionHash,
            ])->save();

            $decision['processing_time_ms'] = max(0, (int) round((microtime(true) - $startedAt) * 1000));
            ModerationLog::query()->create([
                'comment_id' => $locked->getKey(),
                'manual_result' => $manualSnapshot,
                'ai_result' => $aiSnapshot,
                'final_result' => $decision,
                'processing_time_ms' => $decision['processing_time_ms'],
                'source' => 'fallback',
                'input_hash' => $decisionHash,
            ]);

            return $this->resultFromComment($locked->fresh() ?? $locked);
        });
    }

    /** @param array<string, mixed> $manualResult
     *  @return array{matched: bool, action: string, category: ?string, severity: ?string}
     */
    private function aiManualContext(array $manualResult): array
    {
        return [
            'matched' => (bool) ($manualResult['matched'] ?? false),
            'action' => is_string($manualResult['action'] ?? null) ? $manualResult['action'] : 'none',
            'category' => is_string($manualResult['category'] ?? null) ? $manualResult['category'] : null,
            'severity' => is_string($manualResult['severity'] ?? null) ? $manualResult['severity'] : null,
        ];
    }

    private function aiInputHash(
        FacebookComment $comment,
        array $manualSnapshot,
        ?PageModerationSetting $settings,
    ): string {
        $manualContext = $settings?->manual_enabled
            ? $this->aiManualContext($manualSnapshot)
            : ['matched' => false, 'action' => 'none', 'category' => null, 'severity' => null];

        return $this->geminiModerationService->inputHash(
            is_string($comment->message) ? $comment->message : '',
            $comment->page?->page_name,
            $comment->post?->post_message,
            $manualContext,
        );
    }

    private function hasCurrentManualOverride(FacebookComment $comment, string $aiInputHash): bool
    {
        return (bool) $comment->manual_override
            && is_string($comment->manual_override_hash)
            && $comment->manual_override_hash !== ''
            && hash_equals($comment->manual_override_hash, $aiInputHash);
    }

    private function decisionHash(
        string $aiInputHash,
        ?PageModerationSetting $settings,
        array $manualResult,
        array $aiResult,
        ?array $override = null,
    ): string {
        $identity = [
            'ai_input_hash' => $aiInputHash,
            'settings' => $settings === null ? null : [
                'ai_enabled' => (bool) $settings->ai_enabled,
                'manual_enabled' => (bool) $settings->manual_enabled,
                'auto_delete_threshold' => (float) $settings->auto_delete_threshold,
                'auto_hide_threshold' => (float) $settings->auto_hide_threshold,
                'auto_review_threshold' => (float) $settings->auto_review_threshold,
                'allow_ai_delete' => (bool) $settings->allow_ai_delete,
                'allow_ai_hide' => (bool) $settings->allow_ai_hide,
            ],
            'manual_result' => $manualResult,
            'ai_result' => $aiResult,
            'override' => $override,
        ];
        $encoded = json_encode($identity, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);

        return hash_hmac('sha256', $encoded ?: '', (string) config('app.key', ''));
    }

    private function limitText(string $text, int $maximumCharacters): string
    {
        if (function_exists('mb_strlen') && function_exists('mb_substr')) {
            return mb_strlen($text, 'UTF-8') > $maximumCharacters
                ? mb_substr($text, 0, $maximumCharacters, 'UTF-8')
                : $text;
        }

        return strlen($text) > $maximumCharacters * 4
            ? substr($text, 0, $maximumCharacters * 4)
            : $text;
    }
}
