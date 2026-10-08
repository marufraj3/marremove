<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\FacebookComment;
use App\Models\FacebookPage;
use App\Models\ModerationActionLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class AdminDashboardController extends Controller
{
    public function access(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user instanceof User) {
            return response()->json(['message' => 'Authentication is required.'], 401);
        }

        return response()->json([
            'data' => [
                'id' => $user->getKey(),
                'name' => $user->name,
                'email' => $user->email,
            ],
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user instanceof User) {
            return response()->json(['message' => 'Authentication is required.'], 401);
        }

        $filters = $request->validate([
            'page_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
        ]);
        $selectedPage = null;
        if (! empty($filters['page_id'])) {
            $selectedPage = $user->facebookPages()->whereKey($filters['page_id'])->first();
            if ($selectedPage === null) {
                return response()->json(['message' => 'Page not found.'], 404);
            }
        }

        $userId = (int) $user->getKey();
        $comments = FacebookComment::query()->whereHas('page', static function (Builder $pageQuery) use ($userId): void {
            $pageQuery->where('user_id', $userId);
        });
        if ($selectedPage !== null) {
            $comments->where('page_id', $selectedPage->getKey());
        }

        $actions = ModerationActionLog::query()->whereHas('comment.page', static function (Builder $pageQuery) use ($userId): void {
            $pageQuery->where('user_id', $userId);
        });
        if ($selectedPage !== null) {
            $actions->whereHas('comment', static function (Builder $commentQuery) use ($selectedPage): void {
                $commentQuery->where('page_id', $selectedPage->getKey());
            });
        }

        $now = now();
        $today = $now->copy()->startOfDay();
        $week = $now->copy()->startOfWeek();
        $chartsStart = $now->copy()->subDays(30);
        $summary = (clone $comments)
            ->selectRaw('SUM(CASE WHEN comment_created_at >= ? AND comment_created_at <= ? THEN 1 ELSE 0 END) as comments_today', [$today, $now])
            ->selectRaw('SUM(CASE WHEN comment_created_at >= ? AND comment_created_at <= ? THEN 1 ELSE 0 END) as comments_this_week', [$week, $now])
            ->selectRaw("SUM(CASE WHEN final_status = 'completed' AND final_category = 'clean' THEN 1 ELSE 0 END) as clean_comments")
            ->selectRaw("SUM(CASE WHEN manual_moderation_status = 'matched' THEN 1 ELSE 0 END) as manual_rule_matches")
            ->selectRaw("SUM(CASE WHEN ai_status = 'completed' THEN 1 ELSE 0 END) as ai_moderated")
            ->selectRaw("SUM(CASE WHEN final_action = 'review' OR final_status = 'pending' THEN 1 ELSE 0 END) as review_required")
            ->selectRaw("SUM(CASE WHEN facebook_action_state = 'hidden' THEN 1 ELSE 0 END) as hidden_comments")
            ->selectRaw("SUM(CASE WHEN facebook_action_state = 'deleted' THEN 1 ELSE 0 END) as deleted_comments")
            ->first();
        $completedRecent = (clone $comments)
            ->where('final_status', 'completed')
            ->where('comment_created_at', '>=', $chartsStart);

        $chart = static fn (Builder $query, string $column): array => $query
            ->select($column.' as label')
            ->selectRaw('COUNT(*) as value')
            ->groupBy($column)
            ->orderByDesc('value')
            ->get()
            ->map(static fn ($row): array => [
                'label' => is_string($row->label) && $row->label !== '' ? $row->label : 'unknown',
                'value' => (int) $row->value,
            ])
            ->all();

        $pageQuery = FacebookPage::query()->where('user_id', $userId);
        if ($selectedPage !== null) {
            $pageQuery->whereKey($selectedPage->getKey());
        }

        $recent = (clone $comments)
            ->with(['page:id,page_name'])
            ->where('final_status', 'completed')
            ->orderByDesc('final_decision_at')
            ->limit(10)
            ->get([
                'id', 'page_id', 'message', 'final_category', 'final_method', 'final_action',
                'final_confidence', 'final_decision_at', 'facebook_action_state', 'action_status',
            ])
            ->map(static fn (FacebookComment $comment): array => [
                'id' => (int) $comment->getKey(),
                'message' => $comment->message,
                'page_name' => $comment->page?->page_name,
                'category' => $comment->final_category,
                'method' => $comment->final_method,
                'decision' => $comment->final_action,
                'confidence' => $comment->final_confidence === null ? null : (float) $comment->final_confidence,
                'facebook_action' => $comment->facebook_action_state,
                'status' => $comment->action_status,
                'created_at' => $comment->final_decision_at?->toISOString(),
            ])
            ->values();

        $recentActions = (clone $actions)
            ->with(['comment.page:id,page_name'])
            ->orderByDesc('id')
            ->limit(8)
            ->get()
            ->map(static fn (ModerationActionLog $log): array => [
                'id' => (int) $log->getKey(),
                'action' => $log->action,
                'status' => $log->status,
                'created_at' => $log->created_at?->toISOString(),
                'page_name' => $log->comment?->page?->page_name,
                'comment_id' => $log->comment?->getKey(),
                'message' => $log->comment?->message,
            ])
            ->values();

        $stats = [
            'connected_pages' => (clone $pageQuery)->where('is_active', true)->count(),
            'comments_today' => (int) ($summary?->comments_today ?? 0),
            'comments_this_week' => (int) ($summary?->comments_this_week ?? 0),
            'clean_comments' => (int) ($summary?->clean_comments ?? 0),
            'manual_rule_matches' => (int) ($summary?->manual_rule_matches ?? 0),
            'ai_moderated' => (int) ($summary?->ai_moderated ?? 0),
            'review_required' => (int) ($summary?->review_required ?? 0),
            'hidden_comments' => (int) ($summary?->hidden_comments ?? 0),
            'deleted_comments' => (int) ($summary?->deleted_comments ?? 0),
            'failed_actions' => (clone $actions)->where('status', 'failed')->count(),
        ];

        return response()->json([
            'data' => [
                'page' => $selectedPage === null ? null : [
                    'id' => (int) $selectedPage->getKey(),
                    'page_name' => $selectedPage->page_name,
                    'facebook_page_id' => $selectedPage->facebook_page_id,
                    'is_active' => (bool) $selectedPage->is_active,
                ],
                'stats' => $stats,
                'charts' => [
                    'decisions' => $chart((clone $completedRecent), 'final_action'),
                    'methods' => $chart((clone $completedRecent), 'final_method'),
                    'categories' => $chart((clone $completedRecent), 'final_category'),
                ],
                'recent_activity' => $recent,
                'recent_actions' => $recentActions,
                'range' => [
                    'charts_from' => $chartsStart->toISOString(),
                    'generated_at' => now()->toISOString(),
                ],
            ],
        ]);
    }
}
