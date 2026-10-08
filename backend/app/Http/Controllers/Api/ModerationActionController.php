<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\FacebookCommentActionException;
use App\Http\Controllers\Controller;
use App\Models\FacebookComment;
use App\Models\ModerationActionLog;
use App\Models\User;
use App\Services\FacebookCommentActionService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class ModerationActionController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user instanceof User) {
            return response()->json(['message' => 'Authentication is required.'], 401);
        }

        $filters = $request->validate([
            'status' => ['sometimes', Rule::in(['pending', 'completed', 'failed', 'hidden', 'deleted'])],
            'action' => ['sometimes', Rule::in(['hide', 'unhide', 'delete'])],
            'page_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'search' => ['sometimes', 'string', 'max:200'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ]);

        $query = ModerationActionLog::query()
            ->with(['comment.page', 'actor'])
            ->whereHas('comment.page', static function (Builder $pageQuery) use ($user): void {
                $pageQuery->where('user_id', $user->getKey());
            });

        if (isset($filters['page_id'])) {
            $ownedPage = $user->facebookPages()->whereKey($filters['page_id'])->first();
            if ($ownedPage === null) {
                return response()->json(['message' => 'Page not found.'], 404);
            }
            $query->whereHas('comment', static function (Builder $commentQuery) use ($ownedPage): void {
                $commentQuery->where('page_id', $ownedPage->getKey());
            });
        }

        if (isset($filters['status'])) {
            if ($filters['status'] === 'pending') {
                $query->whereIn('status', ['pending', 'processing']);
            } elseif (in_array($filters['status'], ['hidden', 'deleted'], true)) {
                $query->whereHas('comment', static function (Builder $commentQuery) use ($filters): void {
                    $commentQuery->where('facebook_action_state', $filters['status']);
                });
            } else {
                $query->where('status', $filters['status']);
            }
        }
        if (isset($filters['action'])) {
            $query->where('action', $filters['action']);
        }
        if (isset($filters['search']) && trim($filters['search']) !== '') {
            $search = trim($filters['search']);
            $query->whereHas('comment', static function (Builder $commentQuery) use ($search): void {
                $commentQuery->where('message', 'like', '%'.$search.'%')
                    ->orWhere('facebook_comment_id', 'like', '%'.$search.'%');
            });
        }

        $logs = $query->orderByDesc('id')->paginate((int) ($filters['per_page'] ?? 50));
        $data = $logs->getCollection()->map(static function (ModerationActionLog $log): array {
            $comment = $log->comment;
            $page = $comment?->page;

            return [
                'id' => $log->getKey(),
                'facebook_comment_id' => $log->facebook_comment_id,
                'action' => $log->action,
                'status' => $log->status,
                'attempt_count' => (int) $log->attempt_count,
                'response_code' => $log->response_code,
                'meta_error_code' => $log->meta_error_code,
                'response_message' => $log->response_message,
                'last_error' => $log->error_message,
                'processing_time_ms' => $log->processing_time_ms,
                'is_manual' => (bool) $log->is_manual,
                'is_test' => (bool) $log->is_test,
                'created_at' => $log->created_at?->toISOString(),
                'processed_at' => $log->completed_at?->toISOString(),
                'comment' => $comment === null ? null : [
                    'id' => $comment->getKey(),
                    'message' => $comment->message,
                    'page_name' => $page?->page_name,
                    'final_decision' => $comment->final_action,
                    'final_category' => $comment->final_category,
                    'manual_override' => (bool) $comment->manual_override,
                    'facebook_action_state' => $comment->facebook_action_state,
                    'action_status' => $comment->action_status,
                    'author_name' => $comment->author_name,
                ],
            ];
        })->values();

        return response()->json([
            'data' => $data,
            'meta' => [
                'current_page' => $logs->currentPage(),
                'last_page' => $logs->lastPage(),
                'per_page' => $logs->perPage(),
                'total' => $logs->total(),
            ],
        ]);
    }

    public function store(
        Request $request,
        FacebookComment $comment,
        FacebookCommentActionService $actionService,
    ): JsonResponse {
        $user = $request->user();
        if (! $user instanceof User) {
            return response()->json(['message' => 'Authentication is required.'], 401);
        }

        $comment->loadMissing('page');
        if ($comment->page === null || (string) $comment->page->user_id !== (string) $user->getKey()) {
            return response()->json(['message' => 'Comment not found.'], 404);
        }

        $data = $request->validate([
            'action' => ['required', Rule::in(['hide', 'unhide', 'delete'])],
        ]);

        try {
            $result = $actionService->queueManualAction($comment, (string) $data['action'], $user);
        } catch (FacebookCommentActionException $exception) {
            $httpStatus = $exception->errorCategory === 'action_in_progress' ? 409 : 422;

            return response()->json([
                'message' => $exception->getMessage(),
                'error_category' => $exception->errorCategory,
            ], $httpStatus);
        }

        $httpStatus = match ($result['status']) {
            'pending' => 202,
            'failed' => 503,
            default => 200,
        };

        return response()->json(['data' => $result], $httpStatus);
    }
}
