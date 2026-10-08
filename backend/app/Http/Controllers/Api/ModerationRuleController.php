<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ModerationRuleRequest;
use App\Http\Resources\ModerationRuleResource;
use App\Models\FacebookPage;
use App\Models\ModerationRule;
use App\Models\User;
use App\Services\ManualModerationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class ModerationRuleController extends Controller
{
    public function pages(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user instanceof User) {
            return response()->json(['message' => 'Authentication is required.'], 401);
        }

        $pages = FacebookPage::query()
            ->where('user_id', $user->getKey())
            ->where('is_active', true)
            ->orderBy('page_name')
            ->get(['facebook_page_id', 'page_name'])
            ->map(static fn (FacebookPage $page): array => [
                'facebook_page_id' => $page->facebook_page_id,
                'page_name' => $page->page_name,
            ])
            ->values();

        return response()->json(['data' => $pages]);
    }

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user instanceof User) {
            return response()->json(['message' => 'Authentication is required.'], 401);
        }

        $filters = $request->validate([
            'search' => ['sometimes', 'nullable', 'string', 'max:200'],
            'facebook_page_id' => ['sometimes', 'nullable', 'string', 'max:64'],
            'action' => ['sometimes', 'nullable', Rule::in(['keep', 'review', 'hide', 'delete'])],
            'rule_type' => ['sometimes', 'nullable', Rule::in(['keyword', 'phrase', 'regex', 'url', 'phone', 'repeated_text'])],
            'sort_priority' => ['sometimes', Rule::in(['asc', 'desc'])],
            'per_page' => ['sometimes', 'integer', 'between:5,100'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ]);

        $ownedPageIds = $user->facebookPages()->pluck('facebook_page_id');
        $query = ModerationRule::query()
            ->with('page')
            ->where(static function ($ruleQuery) use ($ownedPageIds): void {
                $ruleQuery->whereNull('facebook_page_id')->orWhereIn('facebook_page_id', $ownedPageIds);
            });
        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            $escapedSearch = addcslashes($search, '\\%_');
            $query->where(static function ($ruleQuery) use ($escapedSearch): void {
                $ruleQuery
                    ->where('name', 'like', '%'.$escapedSearch.'%')
                    ->orWhere('pattern', 'like', '%'.$escapedSearch.'%')
                    ->orWhere('category', 'like', '%'.$escapedSearch.'%');
            });
        }

        if (($filters['facebook_page_id'] ?? null) === 'global') {
            $query->whereNull('facebook_page_id');
        } elseif (! empty($filters['facebook_page_id'])) {
            if (! $ownedPageIds->contains($filters['facebook_page_id'])) {
                return response()->json(['message' => 'Page not found.'], 404);
            }
            $query->where('facebook_page_id', $filters['facebook_page_id']);
        }

        if (! empty($filters['action'])) {
            $query->where('action', $filters['action']);
        }
        if (! empty($filters['rule_type'])) {
            $query->where('rule_type', $filters['rule_type']);
        }

        $rules = $query
            ->orderBy('priority', $filters['sort_priority'] ?? 'desc')
            ->orderBy('id')
            ->paginate((int) ($filters['per_page'] ?? 25))
            ->withQueryString();

        return ModerationRuleResource::collection($rules)->response();
    }

    public function store(ModerationRuleRequest $request): JsonResponse
    {
        $data = $request->validated();
        $data['category'] = $data['category'] ?? null;
        $data['facebook_page_id'] = $data['facebook_page_id'] ?? null;
        $data['is_active'] = $data['is_active'] ?? true;

        $rule = ModerationRule::query()->create($data)->load('page');

        return (new ModerationRuleResource($rule))->response()->setStatusCode(201);
    }

    public function show(Request $request, ModerationRule $rule): JsonResponse
    {
        $user = $request->user();
        if (! $user instanceof User || ! $this->canManageRule($user, $rule)) {
            return response()->json(['message' => 'Rule not found.'], 404);
        }

        return (new ModerationRuleResource($rule->load('page')))->response();
    }

    public function update(ModerationRuleRequest $request, ModerationRule $rule): JsonResponse
    {
        $user = $request->user();
        if (! $user instanceof User || ! $this->canManageRule($user, $rule)) {
            return response()->json(['message' => 'Rule not found.'], 404);
        }

        $rule->fill($request->validated())->save();

        return (new ModerationRuleResource($rule->fresh()->load('page')))->response();
    }

    public function destroy(Request $request, ModerationRule $rule): JsonResponse
    {
        $user = $request->user();
        if (! $user instanceof User || ! $this->canManageRule($user, $rule)) {
            return response()->json(['message' => 'Rule not found.'], 404);
        }

        $rule->delete();

        return response()->json(['message' => 'Moderation rule deleted.']);
    }

    public function test(Request $request, ManualModerationService $manualModerationService): JsonResponse
    {
        $data = $request->validate([
            'comment' => ['required', 'string', 'max:'.(int) config('moderation.max_comment_length', 20000)],
            'facebook_page_id' => ['sometimes', 'nullable', 'string', 'max:64'],
        ]);

        $user = $request->user();
        if (! $user instanceof User) {
            return response()->json(['message' => 'Authentication is required.'], 401);
        }

        $facebookPageId = $data['facebook_page_id'] ?? null;
        if ($facebookPageId !== null
            && $facebookPageId !== ''
            && ! FacebookPage::query()
                ->where('facebook_page_id', $facebookPageId)
                ->where('user_id', $user->getKey())
                ->where('is_active', true)
                ->exists()) {
            return response()->json([
                'message' => 'Choose an active connected Facebook Page or test global rules only.',
                'errors' => ['facebook_page_id' => ['The selected Page is unavailable.']],
            ], 422);
        }

        // This is a pure evaluation: it never looks up or mutates a FacebookComment row.
        return response()->json([
            'data' => $manualModerationService->evaluateText(
                $data['comment'],
                is_string($facebookPageId) && $facebookPageId !== '' ? $facebookPageId : null,
            ),
        ]);
    }

    private function canManageRule(User $user, ModerationRule $rule): bool
    {
        return $rule->facebook_page_id === null
            || $user->facebookPages()->where('facebook_page_id', $rule->facebook_page_id)->exists();
    }
}
