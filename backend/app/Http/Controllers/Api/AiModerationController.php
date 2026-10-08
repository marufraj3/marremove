<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\FacebookPage;
use App\Models\User;
use App\Services\GeminiModerationService;
use App\Services\ModerationSafetyMode;
use App\Services\PageModerationSettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class AiModerationController extends Controller
{
    public function settings(
        Request $request,
        PageModerationSettingsService $settingsService,
        ModerationSafetyMode $safetyMode,
    ): JsonResponse
    {
        $user = $request->user();
        if (! $user instanceof User) {
            return response()->json(['message' => 'Authentication is required.'], 401);
        }

        $pages = FacebookPage::query()
            ->where('user_id', $user->getKey())
            ->orderBy('page_name')
            ->get(['id', 'facebook_page_id', 'page_name', 'is_active', 'ai_enabled'])
            ->map(function (FacebookPage $page) use ($settingsService): array {
                $settings = $settingsService->forPage($page);

                return [
                    'id' => $page->getKey(),
                    'facebook_page_id' => $page->facebook_page_id,
                    'page_name' => $page->page_name,
                    'is_active' => (bool) $page->is_active,
                    ...$settingsService->toArray($settings),
                ];
            })
            ->values();

        return response()->json([
            'data' => [
                'enabled' => (bool) config('ai_moderation.enabled', false),
                'api_key_configured' => is_string(config('services.gemini.api_key'))
                    && trim((string) config('services.gemini.api_key')) !== '',
                'model' => (string) config('ai_moderation.model', 'gemini-3.8-flash'),
                'timeout_seconds' => (int) config('ai_moderation.timeout', 20),
                'max_retries' => (int) config('ai_moderation.max_retries', 3),
                'failure_decision' => 'review',
                'default_thresholds' => [
                    'auto_delete_threshold' => (float) config('moderation.auto_delete_threshold', 0.98),
                    'auto_hide_threshold' => (float) config('moderation.auto_hide_threshold', 0.90),
                    'auto_review_threshold' => (float) config('moderation.auto_review_threshold', 0.70),
                    'allow_ai_delete' => (bool) config('moderation.allow_ai_delete', false),
                    'allow_ai_hide' => (bool) config('moderation.allow_ai_hide', true),
                ],
                'default_action_settings' => [
                    'auto_hide_enabled' => (bool) config('moderation.auto_hide_enabled', true),
                    'auto_delete_enabled' => (bool) config('moderation.auto_delete_enabled', false),
                    'auto_execute_actions' => (bool) config('moderation.auto_execute_actions', false),
                    'test_mode' => $safetyMode->enabled(),
                    'queue_connection' => (string) config('moderation.action_queue_connection', 'database'),
                    'max_attempts' => (int) config('moderation.action_max_attempts', 3),
                ],
                'pages' => $pages,
            ],
        ]);
    }

    public function updatePageSettings(
        Request $request,
        FacebookPage $page,
        PageModerationSettingsService $settingsService,
    ): JsonResponse {
        $user = $request->user();
        if (! $user instanceof User) {
            return response()->json(['message' => 'Authentication is required.'], 401);
        }
        if ((string) $page->user_id !== (string) $user->getKey()) {
            return response()->json(['message' => 'Page not found.'], 404);
        }

        $data = $request->validate([
            'ai_enabled' => ['sometimes', 'boolean'],
            'manual_enabled' => ['sometimes', 'boolean'],
            'auto_delete_threshold' => ['sometimes', 'numeric', 'decimal:0,3', 'between:0,1'],
            'auto_hide_threshold' => ['sometimes', 'numeric', 'decimal:0,3', 'between:0,1'],
            'auto_review_threshold' => ['sometimes', 'numeric', 'decimal:0,3', 'between:0,1'],
            'allow_ai_delete' => ['sometimes', 'boolean'],
            'allow_ai_hide' => ['sometimes', 'boolean'],
            'auto_hide_enabled' => ['sometimes', 'boolean'],
            'auto_delete_enabled' => ['sometimes', 'boolean'],
            'auto_execute_actions' => ['sometimes', 'boolean'],
        ]);

        $current = $settingsService->toArray($settingsService->forPage($page));
        $merged = array_merge($current, $data);
        if ($merged['auto_review_threshold'] > $merged['auto_hide_threshold']) {
            throw ValidationException::withMessages([
                'auto_hide_threshold' => 'The hide threshold must be greater than or equal to the review threshold.',
            ]);
        }
        if ($merged['auto_hide_threshold'] > $merged['auto_delete_threshold']) {
            throw ValidationException::withMessages([
                'auto_delete_threshold' => 'The delete threshold must be greater than or equal to the hide threshold.',
            ]);
        }

        $settings = $settingsService->update($page, $data);

        return response()->json([
            'data' => [
                'id' => $page->getKey(),
                'facebook_page_id' => $page->facebook_page_id,
                'page_name' => $page->page_name,
                'is_active' => (bool) $page->is_active,
                ...$settingsService->toArray($settings),
            ],
        ]);
    }

    public function test(Request $request, GeminiModerationService $geminiModerationService): JsonResponse
    {
        $data = $request->validate([
            'comment' => ['required', 'string', 'max:'.(int) config('ai_moderation.max_comment_characters', 20000)],
            'page_name' => ['sometimes', 'nullable', 'string', 'max:200'],
            'post_text' => ['sometimes', 'nullable', 'string', 'max:'.(int) config('ai_moderation.max_post_context_characters', 5000)],
            'manual_result' => ['sometimes', 'nullable', 'array'],
            'manual_result.matched' => ['sometimes', 'boolean'],
            'manual_result.action' => ['sometimes', Rule::in(['none', 'keep', 'review', 'hide', 'delete'])],
            'manual_result.category' => ['sometimes', 'nullable', 'string', 'max:64'],
            'manual_result.severity' => ['sometimes', 'nullable', Rule::in(['low', 'medium', 'high', 'critical'])],
        ]);

        $result = $geminiModerationService->classify(
            $data['comment'],
            isset($data['page_name']) ? (string) $data['page_name'] : null,
            isset($data['post_text']) ? (string) $data['post_text'] : null,
            isset($data['manual_result']) && is_array($data['manual_result']) ? $data['manual_result'] : null,
        );

        // The tester is deliberately stateless: it does not load, update, or log a FacebookComment.
        return response()->json([
            'data' => $result->classification,
            'meta' => [
                'status' => $result->status,
                'error_category' => $result->errorCategory,
                'model' => $result->model,
                'processing_time_ms' => $result->processingTimeMs,
                'attempt_count' => $result->attemptCount,
            ],
        ]);
    }
}
