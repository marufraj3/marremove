<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\FacebookCommentResource;
use App\Models\FacebookComment;
use App\Models\User;
use App\Services\CommentModerationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class CommentModerationController extends Controller
{
    public function override(
        Request $request,
        FacebookComment $comment,
        CommentModerationService $commentModerationService,
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
            'action' => ['required', Rule::in(['keep', 'review', 'hide', 'delete'])],
            'reason' => ['sometimes', 'nullable', 'string', 'max:280'],
        ]);

        $commentModerationService->overrideComment(
            $comment,
            (string) $data['action'],
            $user,
            isset($data['reason']) ? (string) $data['reason'] : null,
        );

        $fresh = $comment->fresh(['page', 'post']);
        if ($fresh === null) {
            return response()->json(['message' => 'Comment not found.'], 404);
        }

        return FacebookCommentResource::make($fresh)->response();
    }
}
