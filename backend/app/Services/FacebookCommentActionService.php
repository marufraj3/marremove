<?php

namespace App\Services;

use App\Exceptions\FacebookCommentActionException;
use App\Jobs\DeleteFacebookCommentJob;
use App\Jobs\HideFacebookCommentJob;
use App\Jobs\UnhideFacebookCommentJob;
use App\Models\FacebookComment;
use App\Models\FacebookPage;
use App\Models\ModerationActionLog;
use App\Models\PageModerationSetting;
use App\Models\User;
use App\Support\AsyncQueueConnection;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

final class FacebookCommentActionService
{
    private const ACTIONS = ['hide', 'unhide', 'delete'];

    private const SAFE_AI_DELETE_CATEGORIES = ['customer_complaint', 'negative_feedback', 'clean', 'other'];

    private const RETRYABLE_META_CODES = [1, 2, 4, 17, 32, 613, 80001];

    public function __construct(
        private readonly PageModerationSettingsService $settingsService,
        private readonly ModerationSafetyMode $safetyMode,
    ) {
    }

    public function effectiveTestMode(): bool
    {
        return $this->safetyMode->enabled();
    }

    /**
     * Queue an action chosen by an authenticated administrator. The Page token is always
     * loaded from the encrypted Page relation; callers cannot supply a token or Page ID.
     *
     * @return array<string, mixed>
     */
    public function queueManualAction(FacebookComment $comment, string $action, User $actor): array
    {
        if (! in_array($action, self::ACTIONS, true)) {
            throw new FacebookCommentActionException('Unsupported Facebook moderation action.', 'invalid_action');
        }

        return $this->queueAction($comment, $action, true, $actor, null, null);
    }

    /**
     * Queue automatic Facebook execution for an already-saved final decision.
     * Keep/review decisions are explicitly logged as skipped; no platform call is made.
     *
     * @return array<string, mixed>
     */
    public function queueAutomaticFinalAction(FacebookComment $comment): array
    {
        $stored = FacebookComment::query()->with('page')->find($comment->getKey());
        if ($stored === null || $stored->page === null || $stored->final_status !== 'completed') {
            return ['status' => 'skipped', 'reason' => 'A completed final decision is required.'];
        }

        $action = is_string($stored->final_action) ? $stored->final_action : 'review';
        if (! in_array($action, ['keep', 'review', 'hide', 'delete'], true)) {
            $action = 'review';
        }

        $settings = $this->settingsService->forPage($stored->page);
        $skipReason = $this->automaticSkipReason($stored, $settings, $action);
        $decisionHash = $this->actionDecisionHash($stored, $settings, $action);

        return $this->queueAction($stored, $action, false, null, $decisionHash, $skipReason);
    }

    /** Execute one queued attempt. Only the job calls this method. */
    public function execute(
        int $commentId,
        int $actionLogId,
        string $action,
        int $attempt,
        int $maxAttempts,
    ): void {
        $startedAt = microtime(true);
        $context = $this->prepareAttempt($commentId, $actionLogId, $action, $attempt);
        if ($context === null) {
            return;
        }

        try {
            if ($context['test_mode']) {
                if (! $this->stillEligibleForMutation($commentId, $actionLogId, $action)) {
                    return;
                }

                $this->completeSuccess(
                    $commentId,
                    $actionLogId,
                    $action,
                    $attempt,
                    $startedAt,
                    null,
                    'Simulated success; MODERATION_TEST_MODE is enabled.',
                    true,
                );

                return;
            }

            if (! $this->stillEligibleForMutation($commentId, $actionLogId, $action)) {
                return;
            }

            // Read the comment through this Page's own token before every mutation. The
            // stored Page association and Graph ID together prevent cross-Page token use.
            $exists = $this->commentStillExists($context['facebook_comment_id'], $context['page_access_token']);
            if (! $exists['exists']) {
                if ($action === 'delete') {
                    $this->completeSuccess(
                        $commentId,
                        $actionLogId,
                        $action,
                        $attempt,
                        $startedAt,
                        $exists['response_code'],
                        'Comment is no longer available on Meta; deletion was not repeated.',
                        false,
                        $exists['meta_error_code'],
                    );
                } else {
                    $this->markActionSkipped(
                        $commentId,
                        $actionLogId,
                        'Comment is no longer available on Meta; hide/unhide was not sent.',
                        $exists['response_code'],
                        $exists['meta_error_code'],
                    );
                }

                return;
            }

            if (! $this->stillEligibleForMutation($commentId, $actionLogId, $action)) {
                return;
            }

            $result = $this->sendAction($action, $context['facebook_comment_id'], $context['page_access_token']);
            $this->completeSuccess(
                $commentId,
                $actionLogId,
                $action,
                $attempt,
                $startedAt,
                $result['response_code'],
                $result['response_message'],
                false,
            );
        } catch (FacebookCommentActionException $exception) {
            $canRetry = $exception->retryable && $attempt < $maxAttempts;
            $this->recordFailure(
                $commentId,
                $actionLogId,
                $action,
                $attempt,
                $startedAt,
                $exception,
                $canRetry,
            );

            if ($canRetry) {
                throw $exception;
            }
        } catch (Throwable $exception) {
            $this->recordUnexpectedFailure($commentId, $actionLogId, $action, $attempt, $startedAt, $exception);
        }
    }

    /** Mark a job failed by Laravel after its bounded retry policy is exhausted. */
    public function markJobFailed(int $actionLogId, ?Throwable $exception = null): void
    {
        $log = ModerationActionLog::query()->with('comment')->find($actionLogId);
        if ($log === null || in_array($log->status, ['completed', 'failed', 'skipped'], true)) {
            return;
        }

        $failure = new FacebookCommentActionException(
            'Facebook action job failed after its configured retry limit.',
            'job_failed',
            false,
            $log->response_code,
        );
        $this->recordFailure(
            (int) $log->comment_id,
            (int) $log->getKey(),
            (string) $log->action,
            max(1, (int) $log->attempt_count),
            microtime(true),
            $failure,
            false,
        );

        Log::error('Facebook comment action job failed.', [
            'action_log_id' => (int) $log->getKey(),
            'exception_type' => $exception === null ? null : $exception::class,
        ]);
    }

    /** @return array<string, mixed> */
    private function queueAction(
        FacebookComment $comment,
        string $action,
        bool $manual,
        ?User $actor,
        ?string $decisionHash,
        ?string $automaticSkipReason,
    ): array {
        $commentId = (int) $comment->getKey();
        $logId = null;
        $shouldDispatch = false;
        $result = DB::transaction(function () use (
            $commentId,
            $action,
            $manual,
            $actor,
            $decisionHash,
            $automaticSkipReason,
            &$logId,
            &$shouldDispatch,
        ): array {
            $stored = FacebookComment::query()->with('page')->lockForUpdate()->find($commentId);
            if ($stored === null) {
                throw new FacebookCommentActionException('Comment not found.', 'comment_not_found');
            }
            $page = $this->assertCommentBelongsToItsPage($stored);
            $settings = $this->settingsService->forPage($page);
            $skipReason = $automaticSkipReason;
            if (! $manual) {
                $skipReason = $this->automaticSkipReason($stored, $settings, $action);
                if ($skipReason === null && $stored->final_action !== $action) {
                    $skipReason = 'The final moderation decision changed before this action was queued.';
                }
                $decisionHash = $this->actionDecisionHash($stored, $settings, $action);
            }

            if (! $manual && is_string($decisionHash)
                && is_string($stored->action_input_hash)
                && hash_equals($stored->action_input_hash, $decisionHash)
                && in_array($stored->action_status, ['pending', 'processing', 'completed', 'failed', 'skipped'], true)) {
                $existing = ModerationActionLog::query()
                    ->where('comment_id', $stored->getKey())
                    ->latest('id')
                    ->first();

                return [
                    'status' => $stored->action_status,
                    'action' => $stored->action_requested,
                    'action_log_id' => $existing?->getKey(),
                    'reason' => $stored->action_error,
                    'duplicate' => true,
                ];
            }

            if (in_array($stored->action_status, ['pending', 'processing'], true)) {
                if ($stored->action_requested === $action) {
                    $existing = ModerationActionLog::query()
                        ->where('comment_id', $stored->getKey())
                        ->where('action', $action)
                        ->whereIn('status', ['pending', 'processing'])
                        ->latest('id')
                        ->first();

                    return [
                        'status' => $stored->action_status,
                        'action' => $action,
                        'action_log_id' => $existing?->getKey(),
                        'reason' => 'An identical action is already queued.',
                        'duplicate' => true,
                    ];
                }

                if ($stored->action_status === 'processing') {
                    if ($manual) {
                        throw new FacebookCommentActionException(
                            'Another Facebook action is currently processing for this comment.',
                            'action_in_progress',
                            false,
                        );
                    }
                    $existing = ModerationActionLog::query()
                        ->where('comment_id', $stored->getKey())
                        ->where('status', 'processing')
                        ->latest('id')
                        ->first();

                    return [
                        'status' => 'processing',
                        'action' => $stored->action_requested,
                        'action_log_id' => $existing?->getKey(),
                        'reason' => 'An earlier action is still processing; the queued worker rechecks the latest decision.',
                        'duplicate' => true,
                    ];
                }

                // Cancel an older action that is still queued. Its job will see the terminal log state and exit.
                $previousLog = ModerationActionLog::query()
                    ->where('comment_id', $stored->getKey())
                    ->where('status', 'pending')
                    ->latest('id')
                    ->first();
                if ($previousLog !== null) {
                    $previousLog->forceFill([
                        'status' => 'skipped',
                        'response_message' => 'Superseded by a newer moderation decision.',
                        'completed_at' => now(),
                    ])->save();
                }
            }

            $stateSkipReason = $this->stateSkipReason($stored, $action);
            $skipReason ??= $stateSkipReason;
            if ($skipReason !== null) {
                if (! $manual && is_string($decisionHash)) {
                    $stored->forceFill([
                        'action_status' => 'skipped',
                        'action_requested' => $action,
                        'action_completed_at' => now(),
                        'action_failed_at' => null,
                        'action_error' => null,
                        'action_input_hash' => $decisionHash,
                    ])->save();
                } else {
                    $stored->forceFill([
                        'action_status' => 'skipped',
                        'action_requested' => $action,
                        'action_completed_at' => now(),
                        'action_failed_at' => null,
                        'action_error' => null,
                    ])->save();
                }

                $log = $this->createActionLog($stored, $action, 'skipped', $manual, $actor);
                $log->forceFill([
                    'response_message' => $this->limitMessage($skipReason),
                    'completed_at' => now(),
                ])->save();
                $logId = (int) $log->getKey();

                return [
                    'status' => 'skipped',
                    'action' => $action,
                    'action_log_id' => $logId,
                    'reason' => $skipReason,
                    'duplicate' => false,
                ];
            }

            $stored->forceFill([
                'action_status' => 'pending',
                'action_requested' => $action,
                'action_attempt_count' => 0,
                'action_attempted_at' => null,
                'action_completed_at' => null,
                'action_failed_at' => null,
                'action_error' => null,
                'action_input_hash' => $manual ? null : $decisionHash,
            ])->save();
            $log = $this->createActionLog($stored, $action, 'pending', $manual, $actor);
            $logId = (int) $log->getKey();
            $shouldDispatch = true;

            return [
                'status' => 'pending',
                'action' => $action,
                'action_log_id' => $logId,
                'reason' => null,
                'duplicate' => false,
            ];
        });

        if ($shouldDispatch && $logId !== null) {
            try {
                $this->dispatchActionJob($commentId, $logId, $action);
            } catch (Throwable $exception) {
                $this->failQueueDispatch($commentId, $logId, $action, $exception);
                $result['status'] = 'failed';
                $result['reason'] = 'The moderation action could not be queued.';
            }
        }

        return $result;
    }

    /** @return array{facebook_comment_id:string,page_access_token:string,test_mode:bool}|null */
    private function prepareAttempt(int $commentId, int $actionLogId, string $action, int $attempt): ?array
    {
        return DB::transaction(function () use ($commentId, $actionLogId, $action, $attempt): ?array {
            $comment = FacebookComment::query()->with('page')->lockForUpdate()->find($commentId);
            $log = ModerationActionLog::query()->lockForUpdate()->find($actionLogId);
            if ($log === null || $comment === null || (int) $log->comment_id !== $commentId || $log->action !== $action) {
                return null;
            }
            if (in_array($log->status, ['completed', 'failed', 'skipped'], true)) {
                return null;
            }
            if ($log->status === 'processing' && $attempt <= (int) $log->attempt_count) {
                // A duplicate delivery must not race the worker that already owns this attempt.
                return null;
            }

            try {
                $page = $this->assertCommentBelongsToItsPage($comment);
            } catch (FacebookCommentActionException $exception) {
                $this->markSkippedWithinTransaction($comment, $log, $exception->getMessage());

                return null;
            }

            if (! (bool) $log->is_manual) {
                $settings = $this->settingsService->forPage($page);
                $reason = $this->automaticSkipReason($comment, $settings, $action);
                if ($reason === null && $comment->final_action !== $action) {
                    $reason = 'The final moderation decision changed before this action ran.';
                }
                if ($reason !== null) {
                    $this->markSkippedWithinTransaction($comment, $log, $reason);

                    return null;
                }
            }

            $stateReason = $this->stateSkipReason($comment, $action);
            if ($stateReason !== null) {
                $this->markSkippedWithinTransaction($comment, $log, $stateReason);

                return null;
            }

            $pageToken = is_string($page->page_access_token) ? trim($page->page_access_token) : '';
            $testMode = $this->effectiveTestMode();
            if (! $testMode && $pageToken === '') {
                $exception = new FacebookCommentActionException(
                    'The stored Page access token is unavailable. Reconnect the Page.',
                    'missing_page_token',
                    false,
                );
                $this->markFailedWithinTransaction($comment, $log, $exception->getMessage(), null, $attempt);

                return null;
            }

            $now = now();
            $log->forceFill([
                'status' => 'processing',
                'attempt_count' => $attempt,
                'started_at' => $log->started_at ?? $now,
                'is_test' => $testMode,
                'error_message' => null,
                'response_message' => null,
            ])->save();
            $comment->forceFill([
                'action_status' => 'processing',
                'action_attempt_count' => $attempt,
                'action_attempted_at' => $now,
                'action_failed_at' => null,
                'action_error' => null,
            ])->save();

            return [
                'facebook_comment_id' => (string) $comment->facebook_comment_id,
                'page_access_token' => $pageToken,
                'test_mode' => $testMode,
            ];
        });
    }

    private function stillEligibleForMutation(int $commentId, int $actionLogId, string $action): bool
    {
        return DB::transaction(function () use ($commentId, $actionLogId, $action): bool {
            $comment = FacebookComment::query()->with('page')->lockForUpdate()->find($commentId);
            $log = ModerationActionLog::query()->lockForUpdate()->find($actionLogId);
            if ($comment === null || $log === null || (int) $log->comment_id !== $commentId
                || $log->action !== $action || $log->status !== 'processing') {
                return false;
            }

            try {
                $page = $this->assertCommentBelongsToItsPage($comment);
            } catch (FacebookCommentActionException $exception) {
                $this->markSkippedWithinTransaction($comment, $log, $exception->getMessage());

                return false;
            }

            $reason = null;
            if (! (bool) $log->is_manual) {
                $settings = $this->settingsService->forPage($page);
                $reason = $this->automaticSkipReason($comment, $settings, $action);
                if ($reason === null && $comment->final_action !== $action) {
                    $reason = 'The final moderation decision changed before this action was sent.';
                }
            }
            $reason ??= $this->stateSkipReason($comment, $action);
            if ($reason !== null) {
                $this->markSkippedWithinTransaction($comment, $log, $reason);

                return false;
            }

            return true;
        });
    }

    /** @return array{exists:bool,response_code:?int,meta_error_code:?int}
     */
    private function commentStillExists(string $facebookCommentId, string $pageAccessToken): array
    {
        $result = $this->graphRequest('GET', $facebookCommentId, $pageAccessToken, ['fields' => 'id'], 'comment_preflight');
        if (($result['payload']['missing'] ?? false) === true) {
            return ['exists' => false, 'response_code' => $result['response_code'], 'meta_error_code' => 100];
        }

        $returnedId = $result['payload']['id'] ?? null;
        if (! is_string($returnedId) || ! hash_equals($facebookCommentId, $returnedId)) {
            throw new FacebookCommentActionException(
                'Meta returned an unexpected comment identity.',
                'comment_identity_mismatch',
                false,
                $result['response_code'],
            );
        }

        return ['exists' => true, 'response_code' => $result['response_code'], 'meta_error_code' => null];
    }

    /** @return array{response_code:int,response_message:string} */
    private function sendAction(string $action, string $facebookCommentId, string $pageAccessToken): array
    {
        if ($action === 'hide' || $action === 'unhide') {
            $result = $this->graphRequest(
                'POST',
                $facebookCommentId,
                $pageAccessToken,
                ['is_hidden' => $action === 'hide'],
                $action,
            );
        } elseif ($action === 'delete') {
            $result = $this->graphRequest('DELETE', $facebookCommentId, $pageAccessToken, [], 'delete');
        } else {
            throw new FacebookCommentActionException('Unsupported Facebook moderation action.', 'invalid_action');
        }

        if (($result['payload']['success'] ?? false) !== true) {
            throw new FacebookCommentActionException(
                'Meta did not confirm the requested comment action.',
                'action_not_confirmed',
                false,
                $result['response_code'],
            );
        }

        return [
            'response_code' => $result['response_code'],
            'response_message' => match ($action) {
                'hide' => 'Meta confirmed that the Page comment is hidden.',
                'unhide' => 'Meta confirmed that the Page comment is visible.',
                'delete' => 'Meta confirmed that the Page comment was deleted.',
            },
        ];
    }

    /** @param array<string, mixed> $parameters
     *  @return array{response_code:int,payload:array<string,mixed>}
     */
    private function graphRequest(
        string $method,
        string $facebookCommentId,
        string $pageAccessToken,
        array $parameters,
        string $operation,
    ): array {
        $version = config('services.facebook.graph_version');
        if (! is_string($version) || preg_match('/^v\d+\.\d+$/D', $version) !== 1) {
            throw new FacebookCommentActionException('The Meta Graph API version is not configured correctly.', 'invalid_graph_config');
        }
        if (trim($pageAccessToken) === '') {
            throw new FacebookCommentActionException('The stored Page access token is unavailable.', 'missing_page_token');
        }

        $url = 'https://graph.facebook.com/'.$version.'/'.rawurlencode($facebookCommentId);
        try {
            $request = Http::acceptJson()
                ->withToken($pageAccessToken)
                ->connectTimeout(5)
                ->timeout(20);
            $appSecret = config('services.facebook.app_secret');
            if (is_string($appSecret) && trim($appSecret) !== '') {
                $request = $request->withQueryParameters([
                    'appsecret_proof' => hash_hmac('sha256', $pageAccessToken, $appSecret),
                ]);
            }
            $response = match ($method) {
                'GET' => $request->get($url, $parameters),
                'POST' => $request->post($url, $parameters),
                'DELETE' => $request->delete($url),
                default => throw new FacebookCommentActionException('Unsupported Meta request method.', 'invalid_graph_method'),
            };
        } catch (ConnectionException) {
            throw new FacebookCommentActionException(
                'A temporary network error occurred while contacting Meta.',
                'network_error',
                true,
            );
        }

        $payload = $response->json();
        $payload = is_array($payload) ? $payload : [];
        $error = is_array($payload['error'] ?? null) ? $payload['error'] : [];
        $metaCode = is_numeric($error['code'] ?? null) ? (int) $error['code'] : null;

        if ($method === 'GET' && $metaCode === 100) {
            // The Comment GET endpoint confirmed the previously stored ID is no longer present.
            return ['response_code' => $response->status(), 'payload' => ['missing' => true]];
        }

        if (! $response->successful() || $error !== []) {
            $permanentFailure = $metaCode === 190
                || $metaCode === 100
                || $metaCode === 10
                || ($metaCode !== null && $metaCode >= 200 && $metaCode <= 299)
                || in_array($response->status(), [401, 403], true);
            $retryable = ! $permanentFailure && (
                $response->status() === 429
                || $response->status() === 408
                || $response->status() === 425
                || $response->status() >= 500
                || in_array($metaCode, self::RETRYABLE_META_CODES, true)
                || ($error['is_transient'] ?? false) === true
            );
            $category = $this->errorCategory($metaCode, $response->status(), $retryable);

            Log::warning('Meta rejected a queued Facebook comment action.', [
                'operation' => $operation,
                'http_status' => $response->status(),
                'meta_error_code' => $metaCode,
                'retryable' => $retryable,
            ]);

            throw new FacebookCommentActionException(
                $this->safeErrorMessage($category),
                $category,
                $retryable,
                $response->status(),
                $metaCode,
            );
        }

        return ['response_code' => $response->status(), 'payload' => $payload];
    }

    private function assertCommentBelongsToItsPage(FacebookComment $comment): FacebookPage
    {
        $page = $comment->page;
        if (! $page instanceof FacebookPage
            || (string) $comment->page_id !== (string) $page->getKey()
            || ! is_string($comment->facebook_page_id)
            || ! hash_equals((string) $page->facebook_page_id, $comment->facebook_page_id)) {
            throw new FacebookCommentActionException(
                'The stored comment is not associated with its connected Facebook Page.',
                'comment_page_mismatch',
                false,
            );
        }
        if (! (bool) $page->is_active) {
            throw new FacebookCommentActionException('The connected Facebook Page is inactive.', 'page_inactive');
        }
        if (! is_string($comment->facebook_comment_id)
            || trim($comment->facebook_comment_id) === ''
            || strlen($comment->facebook_comment_id) > 255
            || preg_match('/[\x00-\x1F\x7F]/', $comment->facebook_comment_id) === 1) {
            throw new FacebookCommentActionException('The Facebook comment ID is invalid.', 'invalid_comment_id');
        }

        return $page;
    }

    private function automaticSkipReason(
        FacebookComment $comment,
        PageModerationSetting $settings,
        string $action,
    ): ?string {
        if ($comment->final_status !== 'completed') {
            return 'No completed final moderation decision is available.';
        }
        if (! in_array($action, ['hide', 'delete'], true)) {
            return $action === 'keep' || $action === 'review'
                ? 'The final decision does not request a Facebook mutation.'
                : 'The final action is not eligible for automatic execution.';
        }
        if (! $settings->auto_execute_actions) {
            return 'Automatic Facebook actions are disabled for this Page.';
        }
        if ($action === 'hide' && ! $settings->auto_hide_enabled) {
            return 'Automatic hide is disabled for this Page.';
        }
        if ($action === 'delete' && ! $settings->auto_delete_enabled) {
            return 'Automatic delete is disabled for this Page.';
        }
        $explicitAdminOverride = (bool) $comment->manual_override;
        if (! $explicitAdminOverride
            && in_array($comment->final_category, ['customer_complaint', 'negative_feedback'], true)) {
            return 'Protected category: customer complaints and negative feedback are not eligible for automatic hide or delete.';
        }
        if ($action === 'delete'
            && ! $explicitAdminOverride
            && in_array($comment->final_category, self::SAFE_AI_DELETE_CATEGORIES, true)) {
            return 'Protected category: automatic deletion is not allowed for this comment.';
        }

        return null;
    }

    private function stateSkipReason(FacebookComment $comment, string $action): ?string
    {
        $state = (string) $comment->facebook_action_state;
        if ($state === 'deleted') {
            return 'The comment is already recorded as deleted; no further Facebook action is possible.';
        }
        if ($action === 'hide' && $state === 'hidden') {
            return 'The comment is already recorded as hidden; duplicate hide was prevented.';
        }
        if ($action === 'unhide' && $state === 'visible') {
            return 'The comment is already recorded as visible; duplicate unhide was prevented.';
        }
        if ($action === 'delete' && $state === 'deleted') {
            return 'The comment is already recorded as deleted; duplicate delete was prevented.';
        }

        return null;
    }

    private function actionDecisionHash(FacebookComment $comment, PageModerationSetting $settings, string $action): string
    {
        $identity = [
            'final_input_hash' => $comment->final_input_hash,
            'final_action' => $comment->final_action,
            'final_category' => $comment->final_category,
            'manual_override' => (bool) $comment->manual_override,
            'action' => $action,
            'page_id' => (int) $comment->page_id,
            'auto_execute_actions' => (bool) $settings->auto_execute_actions,
            'auto_hide_enabled' => (bool) $settings->auto_hide_enabled,
            'auto_delete_enabled' => (bool) $settings->auto_delete_enabled,
            'test_mode' => $this->effectiveTestMode(),
        ];

        return hash_hmac(
            'sha256',
            json_encode($identity, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) ?: '',
            (string) config('app.key', ''),
        );
    }

    private function completeSuccess(
        int $commentId,
        int $actionLogId,
        string $action,
        int $attempt,
        float $startedAt,
        ?int $responseCode,
        ?string $responseMessage,
        bool $isTest,
        ?int $metaErrorCode = null,
    ): void {
        DB::transaction(function () use (
            $commentId,
            $actionLogId,
            $action,
            $attempt,
            $startedAt,
            $responseCode,
            $responseMessage,
            $isTest,
            $metaErrorCode,
        ): void {
            $comment = FacebookComment::query()->lockForUpdate()->find($commentId);
            $log = ModerationActionLog::query()->lockForUpdate()->find($actionLogId);
            if ($comment === null || $log === null || in_array($log->status, ['completed', 'failed', 'skipped'], true)) {
                return;
            }

            $now = now();
            $comment->forceFill([
                'action_status' => 'completed',
                'action_requested' => $action,
                'action_attempt_count' => $attempt,
                'action_completed_at' => $now,
                'action_failed_at' => null,
                'action_error' => null,
                'facebook_action_state' => match ($action) {
                    'hide' => 'hidden',
                    'unhide' => 'visible',
                    'delete' => 'deleted',
                },
                'facebook_action_at' => $now,
            ])->save();
            $log->forceFill([
                'status' => 'completed',
                'attempt_count' => $attempt,
                'response_code' => $responseCode,
                'meta_error_code' => $metaErrorCode,
                'response_message' => $this->limitMessage($responseMessage ?? 'Facebook action completed.'),
                'processing_time_ms' => max(0, (int) round((microtime(true) - $startedAt) * 1000)),
                'error_message' => null,
                'is_test' => $isTest,
                'completed_at' => $now,
            ])->save();
        });
    }

    private function recordFailure(
        int $commentId,
        int $actionLogId,
        string $action,
        int $attempt,
        float $startedAt,
        FacebookCommentActionException $exception,
        bool $willRetry,
    ): void {
        DB::transaction(function () use (
            $commentId,
            $actionLogId,
            $action,
            $attempt,
            $startedAt,
            $exception,
            $willRetry,
        ): void {
            $comment = FacebookComment::query()->lockForUpdate()->find($commentId);
            $log = ModerationActionLog::query()->lockForUpdate()->find($actionLogId);
            if ($comment === null || $log === null || in_array($log->status, ['completed', 'failed', 'skipped'], true)) {
                return;
            }

            $now = now();
            $comment->forceFill([
                'action_status' => $willRetry ? 'pending' : 'failed',
                'action_requested' => $action,
                'action_attempt_count' => $attempt,
                'action_failed_at' => $willRetry ? null : $now,
                'action_error' => $this->limitMessage($exception->getMessage()),
            ])->save();
            $log->forceFill([
                'status' => $willRetry ? 'pending' : 'failed',
                'attempt_count' => $attempt,
                'response_code' => $exception->responseCode,
                'meta_error_code' => $exception->metaErrorCode,
                'response_message' => $willRetry ? 'Temporary error; retry scheduled.' : null,
                'processing_time_ms' => max(0, (int) round((microtime(true) - $startedAt) * 1000)),
                'error_message' => $this->limitMessage($exception->getMessage()),
                'completed_at' => $willRetry ? null : $now,
            ])->save();
        });
    }

    private function recordUnexpectedFailure(
        int $commentId,
        int $actionLogId,
        string $action,
        int $attempt,
        float $startedAt,
        Throwable $exception,
    ): void {
        $failure = new FacebookCommentActionException(
            'The moderation action failed unexpectedly. Check the connection and Page settings.',
            'unexpected_error',
            false,
        );
        $this->recordFailure($commentId, $actionLogId, $action, $attempt, $startedAt, $failure, false);
        Log::warning('Facebook comment action failed unexpectedly.', [
            'action_log_id' => $actionLogId,
            'exception_type' => $exception::class,
        ]);
    }

    private function markActionSkipped(
        int $commentId,
        int $actionLogId,
        string $reason,
        ?int $responseCode,
        ?int $metaErrorCode = null,
    ): void {
        DB::transaction(function () use ($commentId, $actionLogId, $reason, $responseCode, $metaErrorCode): void {
            $comment = FacebookComment::query()->lockForUpdate()->find($commentId);
            $log = ModerationActionLog::query()->lockForUpdate()->find($actionLogId);
            if ($comment === null || $log === null || in_array($log->status, ['completed', 'failed', 'skipped'], true)) {
                return;
            }

            $this->markSkippedWithinTransaction($comment, $log, $reason, $responseCode, $metaErrorCode);
        });
    }

    private function markSkippedWithinTransaction(
        FacebookComment $comment,
        ModerationActionLog $log,
        string $reason,
        ?int $responseCode = null,
        ?int $metaErrorCode = null,
    ): void {
        $now = now();
        $comment->forceFill([
            'action_status' => 'skipped',
            'action_requested' => $log->action,
            'action_completed_at' => $now,
            'action_failed_at' => null,
            'action_error' => null,
        ])->save();
        $log->forceFill([
            'status' => 'skipped',
            'response_code' => $responseCode,
            'meta_error_code' => $metaErrorCode,
            'response_message' => $this->limitMessage($reason),
            'completed_at' => $now,
        ])->save();
    }

    private function markFailedWithinTransaction(
        FacebookComment $comment,
        ModerationActionLog $log,
        string $message,
        ?int $responseCode,
        int $attempt,
    ): void {
        $now = now();
        $comment->forceFill([
            'action_status' => 'failed',
            'action_requested' => $log->action,
            'action_attempt_count' => $attempt,
            'action_failed_at' => $now,
            'action_error' => $this->limitMessage($message),
        ])->save();
        $log->forceFill([
            'status' => 'failed',
            'attempt_count' => $attempt,
            'response_code' => $responseCode,
            'error_message' => $this->limitMessage($message),
            'completed_at' => $now,
        ])->save();
    }

    private function failQueueDispatch(int $commentId, int $actionLogId, string $action, Throwable $exception): void
    {
        $failure = new FacebookCommentActionException(
            'The moderation action could not be queued.',
            'queue_dispatch_failed',
            false,
        );
        $this->recordFailure($commentId, $actionLogId, $action, 0, microtime(true), $failure, false);
        Log::warning('Facebook comment action could not be queued.', [
            'action_log_id' => $actionLogId,
            'exception_type' => $exception::class,
        ]);
    }

    private function dispatchActionJob(int $commentId, int $actionLogId, string $action): void
    {
        $connection = AsyncQueueConnection::resolve(
            (string) config('moderation.action_queue_connection', 'database'),
        );

        $job = match ($action) {
            'hide' => new HideFacebookCommentJob($commentId, $actionLogId),
            'unhide' => new UnhideFacebookCommentJob($commentId, $actionLogId),
            'delete' => new DeleteFacebookCommentJob($commentId, $actionLogId),
            default => throw new FacebookCommentActionException('Unsupported Facebook moderation action.', 'invalid_action'),
        };
        $job->onConnection($connection)->afterCommit();
        dispatch($job);
    }

    private function createActionLog(
        FacebookComment $comment,
        string $action,
        string $status,
        bool $manual,
        ?User $actor,
    ): ModerationActionLog {
        return ModerationActionLog::query()->create([
            'comment_id' => $comment->getKey(),
            'facebook_comment_id' => $comment->facebook_comment_id,
            'action' => $action,
            'status' => $status,
            'attempt_count' => 0,
            'actor_id' => $actor?->getKey(),
            'is_manual' => $manual,
            'is_test' => $this->effectiveTestMode(),
        ]);
    }

    private function errorCategory(?int $metaCode, int $httpStatus, bool $retryable): string
    {
        if ($metaCode === 190) {
            return 'invalid_token';
        }
        if ($metaCode === 10 || ($metaCode !== null && $metaCode >= 200 && $metaCode <= 299)
            || in_array($httpStatus, [401, 403], true)) {
            return 'permission_denied';
        }
        if ($metaCode === 100) {
            return 'comment_not_found';
        }
        if ($httpStatus === 429 || in_array($metaCode, [4, 17, 32, 613, 80001], true)) {
            return 'rate_limited';
        }

        return $retryable ? 'temporary_meta_error' : 'action_rejected';
    }

    private function safeErrorMessage(string $category): string
    {
        return match ($category) {
            'invalid_token' => 'The stored Page access token is invalid or expired. Reconnect the Page.',
            'permission_denied' => 'Meta denied this action. Check pages_manage_engagement and the Page MODERATE task.',
            'comment_not_found' => 'Meta could not find this Page comment.',
            'rate_limited' => 'Meta is rate limiting this Page. A retry will be attempted.',
            'temporary_meta_error' => 'Meta is temporarily unavailable. A retry will be attempted.',
            'network_error' => 'A temporary network error occurred while contacting Meta.',
            default => 'Meta rejected this action. Check the Page token, Page task, and comment status.',
        };
    }

    private function limitMessage(?string $message): string
    {
        $message = trim((string) $message);
        if (function_exists('mb_strlen') && function_exists('mb_substr')) {
            return mb_strlen($message, 'UTF-8') > 250 ? mb_substr($message, 0, 250, 'UTF-8') : $message;
        }

        return strlen($message) > 250 ? substr($message, 0, 250) : $message;
    }
}
