<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\FacebookPageConnectionException;
use App\Http\Controllers\Controller;
use App\Http\Requests\ConnectFacebookPageRequest;
use App\Http\Resources\FacebookPageResource;
use App\Models\User;
use App\Services\FacebookPageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class FacebookPageController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection|JsonResponse
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return response()->json(['message' => 'Authentication is required.'], 401);
        }

        return FacebookPageResource::collection(
            $user->facebookPages()->withCount('comments')->orderBy('page_name')->get(),
        );
    }

    public function store(
        ConnectFacebookPageRequest $request,
        FacebookPageService $facebookPageService,
    ): JsonResponse {
        $user = $request->user();

        if (! $user instanceof User) {
            return response()->json(['message' => 'Authentication is required.'], 401);
        }

        try {
            $page = $facebookPageService->connect(
                $user,
                $request->validated('page_access_token'),
            );
        } catch (FacebookPageConnectionException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], $exception->statusCode);
        }

        return (new FacebookPageResource($page))
            ->additional(['message' => 'Facebook Page connected successfully.'])
            ->response();
    }

    public function disconnect(Request $request, \App\Models\FacebookPage $page): JsonResponse
    {
        $user = $request->user();
        if (! $user instanceof User || ! $user->isModerationAdmin()) {
            return response()->json(['message' => 'You are not authorized to disconnect this Page.'], 403);
        }

        if ((string) $page->user_id !== (string) $user->getKey()) {
            return response()->json(['message' => 'Page not found.'], 404);
        }

        // Retain moderation history while clearing the encrypted credential and disabling future sync/actions.
        $page->forceFill([
            'page_access_token' => '',
            'is_active' => false,
            'sync_status' => 'idle',
            'sync_error' => null,
        ])->save();

        return response()->json([
            'message' => 'Page disconnected. Its moderation history has been retained.',
            'data' => (new FacebookPageResource($page->refresh()))->resolve(),
        ]);
    }
}
