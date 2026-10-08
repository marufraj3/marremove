<?php

namespace App\Services;

use App\Exceptions\FacebookPageConnectionException;
use App\Models\FacebookComment;
use App\Models\FacebookPage;
use App\Models\FacebookPost;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

final class FacebookPageService
{
    private const PAGE_FIELDS = 'id,name,username,category,picture';
    private const POST_FIELDS = 'id,message,created_time,status_type';
    private const COMMENT_FIELDS = 'id,message,created_time,from{id,name},parent{id}';
    private const CORE_COMMENT_FIELDS = 'id,message,created_time';

    public function __construct(
        private readonly FacebookGraphApiClient $graphApi,
        private readonly CommentModerationService $commentModerationService,
    ) {
    }

    public function connect(User $user, string $pageAccessToken): FacebookPage
    {
        $pageData = $this->fetchPageInformation($pageAccessToken);

        return DB::transaction(function () use ($user, $pageAccessToken, $pageData): FacebookPage {
            $page = FacebookPage::query()
                ->where('facebook_page_id', $pageData['facebook_page_id'])
                ->lockForUpdate()
                ->first();

            if ($page !== null && (string) $page->user_id !== (string) $user->getKey()) {
                throw new FacebookPageConnectionException(
                    'This Facebook Page is already connected to another account.',
                    409,
                );
            }

            if ($page === null) {
                $page = new FacebookPage();
                $page->user()->associate($user);
                $page->facebook_page_id = $pageData['facebook_page_id'];
            }

            $page->fill([
                'page_name' => $pageData['page_name'],
                'page_username' => $pageData['page_username'],
                'page_category' => $pageData['page_category'],
                'page_picture_url' => $pageData['page_picture_url'],
                'page_access_token' => $pageAccessToken,
                // Meta's Page-info response does not include token expiry metadata.
                'token_expires_at' => null,
                'is_active' => true,
            ]);

            $page->save();

            return $page;
        });
    }

    /** @return list<array<string, mixed>> */
    public function getPagePosts(FacebookPage $page, ?int $maxPosts = null): array
    {
        $limit = $this->boundedLimit(
            $maxPosts ?? (int) config('services.facebook.max_posts_per_sync', 50),
            5000,
        );

        return $this->graphApi->paginate(
            $page->facebook_page_id.'/feed',
            (string) $page->page_access_token,
            self::POST_FIELDS,
            $limit,
            [],
            'posts',
        );
    }

    /** @return list<array<string, mixed>> */
    public function getPostComments(
        FacebookPage $page,
        FacebookPost|string $post,
        ?int $maxComments = null,
    ): array {
        $facebookPostId = $this->graphId($post instanceof FacebookPost ? $post->facebook_post_id : $post);
        if ($facebookPostId === null) {
            throw new FacebookPageConnectionException(
                'The requested Facebook post is not available for this Page.',
                404,
            );
        }

        $limit = $this->boundedLimit(
            $maxComments ?? (int) config('services.facebook.max_comments_per_post', 200),
            10000,
        );
        $path = $facebookPostId.'/comments';
        $token = (string) $page->page_access_token;

        $fieldSets = [self::COMMENT_FIELDS, self::CORE_COMMENT_FIELDS, 'id'];
        $lastException = null;

        foreach ($fieldSets as $index => $fields) {
            try {
                return $this->graphApi->paginate(
                    $path,
                    $token,
                    $fields,
                    $limit,
                    ['filter' => 'stream'],
                    'comments',
                );
            } catch (FacebookPageConnectionException $exception) {
                // Author, parent, message, and time data may be withheld or unavailable in this API version.
                // Retry progressively smaller field sets; a genuine edge/token permission failure still fails.
                if ($exception->metaErrorCode !== 100) {
                    throw $exception;
                }

                $lastException = $exception;
                if ($index < count($fieldSets) - 1) {
                    Log::info('Retrying a Facebook comment read with fewer optional fields.', [
                        'facebook_page_id' => $page->facebook_page_id,
                        'facebook_post_id' => $facebookPostId,
                        'meta_error_code' => $exception->metaErrorCode,
                        'fallback_level' => $index + 1,
                    ]);
                }
            }
        }

        throw $lastException ?? new FacebookPageConnectionException(
            'Facebook could not read comments for this post. Check the Page token and its permissions.',
            422,
        );
    }

    /** @return array<string, mixed> */
    public function getCommentDetails(FacebookPage $page, string $facebookCommentId): array
    {
        $commentId = $this->graphId($facebookCommentId);
        if ($commentId === null) {
            throw new FacebookPageConnectionException('The Facebook comment ID is invalid.', 422);
        }

        $fieldSets = [self::COMMENT_FIELDS, self::CORE_COMMENT_FIELDS, 'id'];
        $token = (string) $page->page_access_token;
        $lastException = null;

        foreach ($fieldSets as $index => $fields) {
            try {
                $comment = $this->graphApi->get(
                    $commentId,
                    $token,
                    ['fields' => $fields],
                    'comment_details',
                );

                if ($this->graphId($comment['id'] ?? null) !== $commentId) {
                    throw new FacebookPageConnectionException(
                        'Facebook returned a different comment than the requested one.',
                        502,
                    );
                }

                return $comment;
            } catch (FacebookPageConnectionException $exception) {
                if ($exception->metaErrorCode !== 100) {
                    throw $exception;
                }

                $lastException = $exception;
                if ($index < count($fieldSets) - 1) {
                    Log::info('Retrying a Facebook comment lookup with fewer optional fields.', [
                        'facebook_page_id' => $page->facebook_page_id,
                        'facebook_comment_id' => $commentId,
                        'meta_error_code' => $exception->metaErrorCode,
                        'fallback_level' => $index + 1,
                    ]);
                }
            }
        }

        throw $lastException ?? new FacebookPageConnectionException(
            'Facebook could not read the requested comment.',
            422,
        );
    }

    public function upsertComment(
        FacebookPage $page,
        string $facebookCommentId,
        ?string $facebookPostId,
        array $comment,
    ): FacebookComment {
        $commentId = $this->graphId($facebookCommentId);
        $returnedId = $this->graphId($comment['id'] ?? $commentId);
        if ($commentId === null || $returnedId !== $commentId) {
            throw new FacebookPageConnectionException(
                'Facebook returned an invalid comment ID.',
                502,
            );
        }

        $postId = $this->graphId($facebookPostId ?? $comment['post_id'] ?? null);
        $postRecord = $postId === null
            ? null
            : FacebookPost::query()
                ->where('page_id', $page->getKey())
                ->where('facebook_page_id', $page->facebook_page_id)
                ->where('facebook_post_id', $postId)
                ->first();
        $author = is_array($comment['from'] ?? null) ? $comment['from'] : [];
        $parent = is_array($comment['parent'] ?? null) ? $comment['parent'] : [];
        $now = now();

        $values = [
            'page_id' => $page->getKey(),
            'post_id' => $postRecord?->getKey(),
            'facebook_page_id' => $page->facebook_page_id,
            'facebook_post_id' => $postId,
            'facebook_comment_id' => $commentId,
            'parent_comment_id' => $this->graphId($parent['id'] ?? null),
            'author_facebook_id' => $this->graphId($author['id'] ?? null),
            'author_name' => is_string($author['name'] ?? null) && trim($author['name']) !== ''
                ? $author['name']
                : null,
            'message' => is_string($comment['message'] ?? null) ? $comment['message'] : null,
            'comment_created_at' => $this->parseGraphDate($comment['created_time'] ?? null),
            // Meta's current v26.0 Comment reference does not document updated_time.
            'comment_updated_at' => $this->parseGraphDate($comment['updated_time'] ?? null),
            'created_at' => $now,
            'updated_at' => $now,
        ];

        $this->persistCommentRows($page, [$values]);

        $savedComment = FacebookComment::query()
            ->where('page_id', $page->getKey())
            ->where('facebook_comment_id', $commentId)
            ->firstOrFail();
        $this->commentModerationService->moderateComment($savedComment);

        return $savedComment->fresh() ?? $savedComment;
    }

    public function syncPagePosts(FacebookPage $page, ?int $maxPosts = null): int
    {
        $posts = $this->getPagePosts($page, $maxPosts);
        $now = now();
        $rowsById = [];
        $skipped = 0;

        foreach ($posts as $post) {
            $facebookPostId = $this->graphId($post['id'] ?? null);
            if ($facebookPostId === null) {
                $skipped++;
                continue;
            }

            $rowsById[$facebookPostId] = [
                'page_id' => $page->getKey(),
                'facebook_page_id' => $page->facebook_page_id,
                'facebook_post_id' => $facebookPostId,
                'post_message' => is_string($post['message'] ?? null) ? $post['message'] : null,
                // v26.0 documents status_type on the Page Feed; it does not document a generic `type` field here.
                'post_type' => is_string($post['status_type'] ?? null) ? $post['status_type'] : null,
                'post_created_at' => $this->parseGraphDate($post['created_time'] ?? null),
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        $rows = array_values($rowsById);
        if ($rows !== []) {
            $this->persistPostRows($page, $rows);
        }

        if ($skipped > 0) {
            Log::warning('Facebook returned Page Feed items without usable IDs.', [
                'facebook_page_id' => $page->facebook_page_id,
                'skipped_count' => $skipped,
            ]);
        }

        return count($rows);
    }

    public function syncPostComments(FacebookPage $page, FacebookPost|string $post): int
    {
        $postRecord = $post instanceof FacebookPost
            ? $post
            : FacebookPost::query()
                ->where('page_id', $page->getKey())
                ->where('facebook_page_id', $page->facebook_page_id)
                ->where('facebook_post_id', $post)
                ->first();

        if ($postRecord === null
            || (string) $postRecord->page_id !== (string) $page->getKey()
            || ! is_string($postRecord->facebook_page_id)
            || ! hash_equals((string) $page->facebook_page_id, $postRecord->facebook_page_id)) {
            throw new FacebookPageConnectionException(
                'The requested Facebook post is not available for this Page.',
                404,
            );
        }

        $comments = $this->getPostComments($page, $postRecord);
        $now = now();
        $rowsById = [];
        $skipped = 0;

        foreach ($comments as $comment) {
            $facebookCommentId = $this->graphId($comment['id'] ?? null);
            if ($facebookCommentId === null) {
                $skipped++;
                continue;
            }

            $author = is_array($comment['from'] ?? null) ? $comment['from'] : [];
            $parent = is_array($comment['parent'] ?? null) ? $comment['parent'] : [];

            $rowsById[$facebookCommentId] = [
                'page_id' => $page->getKey(),
                'post_id' => $postRecord->getKey(),
                'facebook_page_id' => $page->facebook_page_id,
                'facebook_post_id' => $postRecord->facebook_post_id,
                'facebook_comment_id' => $facebookCommentId,
                'parent_comment_id' => $this->graphId($parent['id'] ?? null),
                'author_facebook_id' => $this->graphId($author['id'] ?? null),
                'author_name' => is_string($author['name'] ?? null) && trim($author['name']) !== ''
                    ? $author['name']
                    : null,
                'message' => is_string($comment['message'] ?? null) ? $comment['message'] : null,
                'comment_created_at' => $this->parseGraphDate($comment['created_time'] ?? null),
                // Meta's current v26.0 Comment reference does not document updated_time; keep it nullable.
                'comment_updated_at' => $this->parseGraphDate($comment['updated_time'] ?? null),
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        $rows = array_values($rowsById);
        if ($rows !== []) {
            $this->persistCommentRows($page, $rows);

            $facebookCommentIds = array_column($rows, 'facebook_comment_id');
            foreach (array_chunk($facebookCommentIds, 500) as $commentIdChunk) {
                FacebookComment::query()
                    ->where('page_id', $page->getKey())
                    ->where('facebook_page_id', $page->facebook_page_id)
                    ->whereIn('facebook_comment_id', $commentIdChunk)
                    ->with(['page', 'post'])
                    ->get()
                    ->each(function (FacebookComment $comment): void {
                        $this->commentModerationService->moderateComment($comment);
                    });
            }
        }

        if ($skipped > 0) {
            Log::warning('Facebook returned comments without usable IDs.', [
                'facebook_page_id' => $page->facebook_page_id,
                'facebook_post_id' => $postRecord->facebook_post_id,
                'skipped_count' => $skipped,
            ]);
        }

        return count($rows);
    }

    public function syncPageComments(FacebookPage $page, ?int $maxPosts = null): int
    {
        $postLimit = $this->boundedLimit(
            $maxPosts ?? (int) config('services.facebook.max_posts_per_sync', 50),
            5000,
        );
        $posts = FacebookPost::query()
            ->where('page_id', $page->getKey())
            ->orderByDesc('post_created_at')
            ->orderByDesc('id')
            ->limit($postLimit)
            ->get();

        $syncedComments = 0;
        foreach ($posts as $post) {
            $syncedComments += $this->syncPostComments($page, $post);
        }

        return $syncedComments;
    }

    /** @return array{posts_synced: int, comments_synced: int} */
    public function syncPage(FacebookPage $page): array
    {
        $postsSynced = $this->syncPagePosts($page);
        $commentsSynced = $this->syncPageComments($page);

        return [
            'posts_synced' => $postsSynced,
            'comments_synced' => $commentsSynced,
        ];
    }

    /** @param list<array<string, mixed>> $rows */
    private function persistPostRows(FacebookPage $page, array $rows): void
    {
        DB::transaction(function () use ($page, $rows): void {
            foreach (array_chunk($rows, 100) as $chunk) {
                $postIds = array_column($chunk, 'facebook_post_id');
                FacebookPost::query()->insertOrIgnore($chunk);

                $savedPosts = FacebookPost::query()
                    ->whereIn('facebook_post_id', $postIds)
                    ->lockForUpdate()
                    ->get()
                    ->keyBy('facebook_post_id');

                if ($savedPosts->count() !== count($chunk)) {
                    throw new FacebookPageConnectionException(
                        'Facebook posts could not be saved consistently. Please try the sync again.',
                        502,
                    );
                }

                foreach ($chunk as &$row) {
                    /** @var FacebookPost|null $saved */
                    $saved = $savedPosts->get($row['facebook_post_id']);
                    if ($saved === null
                        || (string) $saved->page_id !== (string) $page->getKey()
                        || ! is_string($saved->facebook_page_id)
                        || ! hash_equals((string) $page->facebook_page_id, $saved->facebook_page_id)) {
                        throw new FacebookPageConnectionException(
                            'The Facebook post is already associated with a different Page.',
                            409,
                        );
                    }

                    foreach (['post_message', 'post_type', 'post_created_at'] as $field) {
                        if (($row[$field] ?? null) === null) {
                            $row[$field] = $saved->getAttribute($field);
                        }
                    }
                    $row['updated_at'] = now();
                }
                unset($row);

                // Ownership columns are intentionally immutable on duplicate IDs.
                FacebookPost::upsert(
                    $chunk,
                    ['facebook_post_id'],
                    ['post_message', 'post_type', 'post_created_at', 'is_active', 'updated_at'],
                );
            }
        });
    }

    /** @param list<array<string, mixed>> $rows */
    private function persistCommentRows(FacebookPage $page, array $rows): void
    {
        DB::transaction(function () use ($page, $rows): void {
            foreach (array_chunk($rows, 100) as $chunk) {
                $commentIds = array_column($chunk, 'facebook_comment_id');
                FacebookComment::query()->insertOrIgnore($chunk);

                $savedComments = FacebookComment::query()
                    ->whereIn('facebook_comment_id', $commentIds)
                    ->lockForUpdate()
                    ->get()
                    ->keyBy('facebook_comment_id');

                if ($savedComments->count() !== count($chunk)) {
                    throw new FacebookPageConnectionException(
                        'Facebook comments could not be saved consistently. Please try the sync again.',
                        502,
                    );
                }

                foreach ($chunk as &$row) {
                    /** @var FacebookComment|null $saved */
                    $saved = $savedComments->get($row['facebook_comment_id']);
                    if ($saved === null
                        || (string) $saved->page_id !== (string) $page->getKey()
                        || ! is_string($saved->facebook_page_id)
                        || ! hash_equals((string) $page->facebook_page_id, $saved->facebook_page_id)) {
                        throw new FacebookPageConnectionException(
                            'The comment is already associated with a different Page.',
                            409,
                        );
                    }

                    foreach ([
                        'post_id',
                        'facebook_post_id',
                        'parent_comment_id',
                        'author_facebook_id',
                        'author_name',
                        'message',
                        'comment_created_at',
                        'comment_updated_at',
                    ] as $field) {
                        if (($row[$field] ?? null) === null) {
                            $row[$field] = $saved->getAttribute($field);
                        }
                    }
                    $row['updated_at'] = now();
                }
                unset($row);

                // Page ownership columns are deliberately omitted from duplicate updates.
                FacebookComment::upsert(
                    $chunk,
                    ['facebook_comment_id'],
                    [
                        'post_id',
                        'facebook_post_id',
                        'parent_comment_id',
                        'author_facebook_id',
                        'author_name',
                        'message',
                        'comment_created_at',
                        'comment_updated_at',
                        'updated_at',
                    ],
                );
            }
        });
    }

    /** @return array<string, string|null> */
    private function fetchPageInformation(string $pageAccessToken): array
    {
        $payload = $this->graphApi->get(
            'me',
            $pageAccessToken,
            ['fields' => self::PAGE_FIELDS],
            'page_connection',
        );

        if (
            ! isset($payload['id'], $payload['name'])
            || ! is_scalar($payload['id'])
            || ! is_string($payload['name'])
            || trim($payload['name']) === ''
            || ! array_key_exists('category', $payload)
        ) {
            throw new FacebookPageConnectionException(
                'The token did not resolve to a Facebook Page. Use a Page Access Token for a Page you manage.',
                422,
            );
        }

        $pictureUrl = data_get($payload, 'picture.data.url');
        if (! is_string($pictureUrl) || ! str_starts_with($pictureUrl, 'https://')) {
            $pictureUrl = null;
        }

        return [
            'facebook_page_id' => (string) $payload['id'],
            'page_name' => $payload['name'],
            'page_username' => is_string($payload['username'] ?? null) ? $payload['username'] : null,
            'page_category' => is_string($payload['category']) ? $payload['category'] : null,
            'page_picture_url' => $pictureUrl,
        ];
    }

    private function boundedLimit(int $limit, int $hardMaximum): int
    {
        return max(1, min($hardMaximum, $limit));
    }

    private function graphId(mixed $value): ?string
    {
        if (! is_string($value) && ! is_int($value)) {
            return null;
        }

        $id = trim((string) $value);

        return $id === '' ? null : $id;
    }

    private function parseGraphDate(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) && ! is_numeric($value)) {
            return null;
        }

        try {
            return is_numeric($value)
                ? CarbonImmutable::createFromTimestamp((int) $value, 'UTC')
                : CarbonImmutable::parse($value);
        } catch (Throwable) {
            return null;
        }
    }
}
