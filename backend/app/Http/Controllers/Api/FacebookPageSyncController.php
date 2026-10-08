<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\SyncFacebookPageJob;
use App\Models\FacebookPage;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

final class FacebookPageSyncController extends Controller
{
    public function store(Request $request, FacebookPage $page): JsonResponse
    {
        $user = $request->user();
        if (! $user instanceof User) {
            return response()->json(['message' => 'Authentication is required.'], 401);
        }

        if ((string) $page->user_id !== (string) $user->getKey()) {
            // Use 404 rather than disclosing whether another account owns this Page.
            return response()->json(['message' => 'Page not found.'], 404);
        }

        $decision = DB::transaction(function () use ($page, $user): array {
            $lockedPage = FacebookPage::query()->lockForUpdate()->find($page->getKey());
            if ($lockedPage === null || (string) $lockedPage->user_id !== (string) $user->getKey()) {
                return ['status' => 404];
            }

            if (! (bool) $lockedPage->is_active) {
                return ['status' => 409, 'message' => 'This Facebook Page is inactive. Reconnect it before syncing.'];
            }

            if (in_array($lockedPage->sync_status, ['queued', 'running'], true)) {
                return [
                    'status' => 202,
                    'already_queued' => true,
                    'data' => [
                        'facebook_page_id' => $lockedPage->facebook_page_id,
                        'sync_status' => $lockedPage->sync_status,
                        'posts_synced' => (int) $lockedPage->posts_synced_count,
                        'comments_synced' => (int) $lockedPage->comments_synced_count,
                    ],
                ];
            }

            $lockedPage->forceFill([
                'sync_status' => 'queued',
                'sync_error' => null,
                'last_sync_started_at' => now(),
                'posts_synced_count' => 0,
                'comments_synced_count' => 0,
            ])->save();

            return ['status' => 202, 'dispatch' => true];
        });

        if ($decision['status'] === 404) {
            return response()->json(['message' => 'Page not found.'], 404);
        }
        if ($decision['status'] === 409) {
            return response()->json(['message' => $decision['message']], 409);
        }
        if (! ($decision['dispatch'] ?? false)) {
            return response()->json([
                'message' => 'A sync is already in progress for this Page.',
                'data' => $decision['data'],
            ], 202);
        }

        try {
            $job = (new SyncFacebookPageJob((int) $page->getKey()))->afterCommit();
            dispatch($job);
        } catch (Throwable $exception) {
            DB::transaction(function () use ($page): void {
                $lockedPage = FacebookPage::query()->lockForUpdate()->find($page->getKey());
                if ($lockedPage !== null && $lockedPage->sync_status === 'queued') {
                    $lockedPage->forceFill([
                        'sync_status' => 'failed',
                        'sync_error' => 'Could not start the sync queue. Please try again.',
                    ])->save();
                }
            });

            Log::error('Could not queue a Facebook Page sync.', [
                'facebook_page_id' => $page->facebook_page_id,
                'exception_type' => $exception::class,
            ]);

            return response()->json(['message' => 'Could not start the sync queue. Please try again.'], 503);
        }

        return response()->json([
            'message' => 'Facebook Page sync queued.',
            'data' => [
                'facebook_page_id' => $page->facebook_page_id,
                'sync_status' => 'queued',
                'posts_synced' => 0,
                'comments_synced' => 0,
            ],
        ], 202);
    }
}
