<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\IndexFacebookCommentsRequest;
use App\Http\Resources\FacebookCommentResource;
use App\Models\FacebookComment;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;

final class FacebookCommentController extends Controller
{
    public function index(IndexFacebookCommentsRequest $request): JsonResponse
    {
        $user = $request->user();
        if (! $user instanceof User) {
            return response()->json(['message' => 'Authentication is required.'], 401);
        }

        $filters = $request->validated();
        $query = FacebookComment::query()
            ->with(['page', 'post'])
            ->whereHas('page', static function (Builder $pageQuery) use ($user): void {
                $pageQuery->where('user_id', $user->getKey());
            });

        if (isset($filters['page_id'])) {
            $ownedPage = $user->facebookPages()->whereKey($filters['page_id'])->first();
            if ($ownedPage === null) {
                return response()->json(['message' => 'Page not found.'], 404);
            }

            $query->where('page_id', $ownedPage->getKey());
        }

        if (isset($filters['search']) && trim($filters['search']) !== '') {
            $query->where('message', 'like', '%'.$filters['search'].'%');
        }

        if (isset($filters['from'])) {
            $query->where('comment_created_at', '>=', CarbonImmutable::parse($filters['from'])->startOfDay());
        }

        if (isset($filters['to'])) {
            $query->where('comment_created_at', '<=', CarbonImmutable::parse($filters['to'])->endOfDay());
        }

        if (isset($filters['decision'])) {
            if ($filters['decision'] === 'review') {
                $query->where(static function (Builder $decisionQuery): void {
                    $decisionQuery->where('final_action', 'review')->orWhere('final_status', 'pending');
                });
            } elseif ($filters['decision'] === 'pending') {
                $query->where('final_status', 'pending');
            } else {
                $query->where('final_action', $filters['decision']);
            }
        }

        if (isset($filters['category'])) {
            $query->where('final_category', $filters['category']);
        }

        if (isset($filters['method'])) {
            $query->where('final_method', $filters['method']);
        }

        if (isset($filters['action_status'])) {
            $query->where('action_status', $filters['action_status']);
        }

        if (isset($filters['min_confidence'])) {
            $query->where('final_confidence', '>=', (float) $filters['min_confidence']);
        }

        if (isset($filters['max_confidence'])) {
            $query->where('final_confidence', '<=', (float) $filters['max_confidence']);
        }

        $comments = $query
            ->orderByDesc('comment_created_at')
            ->orderByDesc('id')
            ->paginate((int) ($filters['per_page'] ?? 50))
            ->withQueryString();

        return FacebookCommentResource::collection($comments)->response();
    }

    public function show(\Illuminate\Http\Request $request, FacebookComment $comment): JsonResponse
    {
        $user = $request->user();
        if (! $user instanceof User) {
            return response()->json(['message' => 'Authentication is required.'], 401);
        }

        $comment->load(['page', 'post']);
        if ($comment->page === null || (string) $comment->page->user_id !== (string) $user->getKey()) {
            return response()->json(['message' => 'Comment not found.'], 404);
        }

        return (new FacebookCommentResource($comment))->response();
    }
}
