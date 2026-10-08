<?php

namespace Tests\Feature;

use App\Exceptions\FacebookPageConnectionException;
use App\Jobs\SyncFacebookPageJob;
use App\Models\FacebookComment;
use App\Models\FacebookPage;
use App\Models\FacebookPost;
use App\Models\User;
use App\Services\FacebookPageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class FacebookSyncTest extends TestCase
{
    use RefreshDatabase;

    private const PAGE_TOKEN = 'mock-page-token-for-sync-tests';
    private const GRAPH_PAGE_ID = '123456789012345';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.facebook.graph_version' => 'v26.0',
            'services.facebook.max_posts_per_sync' => 50,
            'services.facebook.max_comments_per_post' => 100,
            'services.facebook.max_pages_per_sync' => 100,
        ]);
    }

    public function test_owner_can_queue_a_sync_and_response_contains_only_safe_summary(): void
    {
        Bus::fake();
        $owner = User::factory()->create();
        $page = $this->createPage($owner);

        $response = $this->actingAs($owner)->postJson("/api/facebook/pages/{$page->id}/sync");

        $response
            ->assertStatus(202)
            ->assertJsonPath('data.facebook_page_id', self::GRAPH_PAGE_ID)
            ->assertJsonPath('data.sync_status', 'queued')
            ->assertJsonPath('data.posts_synced', 0)
            ->assertJsonPath('data.comments_synced', 0);
        $this->assertStringNotContainsString(self::PAGE_TOKEN, $response->getContent());
        $response->assertJsonMissingPath('data.page_access_token');
        $this->assertDatabaseHas('facebook_pages', [
            'id' => $page->id,
            'sync_status' => 'queued',
        ]);
        Bus::assertDispatched(SyncFacebookPageJob::class, fn (SyncFacebookPageJob $job): bool => $job->pageId === $page->id);
    }

    public function test_user_cannot_queue_a_sync_for_another_users_page(): void
    {
        Bus::fake();
        $owner = User::factory()->create();
        $otherUser = User::factory()->create();
        $page = $this->createPage($owner);

        $this->actingAs($otherUser)
            ->postJson("/api/facebook/pages/{$page->id}/sync")
            ->assertNotFound()
            ->assertExactJson(['message' => 'Page not found.']);

        $this->assertDatabaseHas('facebook_pages', [
            'id' => $page->id,
            'sync_status' => 'idle',
        ]);
        Bus::assertNotDispatched(SyncFacebookPageJob::class);
    }

    public function test_graph_requests_include_appsecretproof_without_exposing_app_secret(): void
    {
        $owner = User::factory()->create();
        $page = $this->createPage($owner);
        $appSecret = 'app-secret-used-only-in-test';
        config(['services.facebook.app_secret' => $appSecret]);

        Http::fake([
            '*' => Http::response(['data' => []], 200),
        ]);

        app(FacebookPageService::class)->getPagePosts($page);

        $expectedProof = hash_hmac('sha256', self::PAGE_TOKEN, $appSecret);
        Http::assertSent(function (Request $request) use ($expectedProof, $appSecret): bool {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            return str_contains($request->url(), '/v26.0/'.self::GRAPH_PAGE_ID.'/feed')
                && ($query['appsecret_proof'] ?? null) === $expectedProof
                && ! str_contains($request->url(), $appSecret);
        });
    }

    public function test_post_and_comment_sync_reject_cross_page_facebook_id_collisions(): void
    {
        $owner = User::factory()->create();
        $firstPage = $this->createPage($owner, self::GRAPH_PAGE_ID, 'First Page');
        $secondPage = $this->createPage($owner, '987654321098765', 'Second Page');
        $existingPost = $this->createPost($firstPage, 'shared-post-id');
        $existingComment = $this->createComment($firstPage, 'shared-comment-id', 'Keep this saved comment', '2026-10-01 12:00:00');

        Http::fake([
            '*' => Http::response([
                'data' => [
                    [
                        'id' => 'shared-post-id',
                        'message' => 'Changed by another Page',
                        'created_time' => '2026-10-08T12:00:00+0000',
                        'status_type' => 'published_story',
                    ],
                ],
            ], 200),
        ]);

        try {
            app(FacebookPageService::class)->syncPagePosts($secondPage);
            $this->fail('A post ID owned by another Page must be rejected.');
        } catch (FacebookPageConnectionException $exception) {
            $this->assertSame(409, $exception->statusCode);
        }

        Http::fake([
            '*' => Http::response(['data' => [['id' => 'shared-comment-id']]], 200),
        ]);

        try {
            app(FacebookPageService::class)->syncPostComments($secondPage, $this->createPost($secondPage, 'second-page-post'));
            $this->fail('A comment ID owned by another Page must be rejected.');
        } catch (FacebookPageConnectionException $exception) {
            $this->assertSame(409, $exception->statusCode);
        }

        $this->assertSame($firstPage->getKey(), $existingPost->fresh()->page_id);
        $this->assertSame(self::GRAPH_PAGE_ID, $existingPost->fresh()->facebook_page_id);
        $this->assertSame($firstPage->getKey(), $existingComment->fresh()->page_id);
        $this->assertSame('Keep this saved comment', $existingComment->fresh()->message);
    }

    public function test_comment_sync_preserves_saved_fields_when_graph_omits_optional_fields(): void
    {
        $owner = User::factory()->create();
        $page = $this->createPage($owner);
        $post = $this->createPost($page, 'saved-post-id');
        $comment = $this->createComment($page, 'saved-comment-id', 'Original message', '2026-10-02 09:30:00');
        $comment->update([
            'post_id' => $post->getKey(),
            'author_name' => 'Saved author',
            'author_facebook_id' => 'saved-author-id',
            'comment_updated_at' => '2026-10-03 10:00:00',
        ]);

        Http::fake([
            '*' => Http::response(['data' => [['id' => 'saved-comment-id']]], 200),
        ]);

        app(FacebookPageService::class)->syncPostComments($page, $post);

        $comment->refresh();
        $this->assertSame('Original message', $comment->message);
        $this->assertSame('Saved author', $comment->author_name);
        $this->assertSame('saved-author-id', $comment->author_facebook_id);
        $this->assertSame('2026-10-02 09:30:00', $comment->comment_created_at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-10-03 10:00:00', $comment->comment_updated_at->format('Y-m-d H:i:s'));
    }

    public function test_posts_use_cursor_pagination_and_upsert_by_facebook_id(): void
    {
        config(['services.facebook.max_posts_per_sync' => 250]);
        $page = $this->createPage(User::factory()->create());
        Http::fake([
            '*' => Http::sequence()
                ->push([
                    'data' => [
                        [
                            'id' => self::GRAPH_PAGE_ID.'_post-1',
                            'message' => 'First post',
                            'status_type' => 'mobile_status_update',
                            'created_time' => '2026-10-01T12:00:00+0000',
                        ],
                        [
                            'id' => self::GRAPH_PAGE_ID.'_post-2',
                            'message' => 'Second post',
                            'status_type' => 'published_story',
                            'created_time' => '2026-10-02T12:00:00+0000',
                        ],
                    ],
                    'paging' => [
                        'cursors' => ['after' => 'posts-cursor-1'],
                        'next' => 'https://graph.facebook.com/v26.0/'.self::GRAPH_PAGE_ID.'/feed?access_token=provider-secret&after=posts-cursor-1',
                    ],
                ], 200)
                ->push([
                    'data' => [[
                        'id' => self::GRAPH_PAGE_ID.'_post-3',
                        'message' => 'Third post',
                        'created_time' => '2026-10-03T12:00:00+0000',
                    ]],
                ], 200)
                ->push([
                    'data' => [[
                        'id' => self::GRAPH_PAGE_ID.'_post-1',
                        'message' => 'Updated first post',
                        'status_type' => 'mobile_status_update',
                        'created_time' => '2026-10-01T12:00:00+0000',
                    ]],
                ], 200),
        ]);

        $service = app(FacebookPageService::class);
        $this->assertSame(3, $service->syncPagePosts($page));
        $this->assertDatabaseCount('facebook_posts', 3);
        $this->assertSame(1, $service->syncPagePosts($page));
        $this->assertDatabaseCount('facebook_posts', 3);
        $this->assertDatabaseHas('facebook_posts', [
            'facebook_post_id' => self::GRAPH_PAGE_ID.'_post-1',
            'post_message' => 'Updated first post',
            'post_type' => 'mobile_status_update',
        ]);

        Http::assertSent(function (ClientRequest $request): bool {
            $query = [];
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            return str_contains($request->url(), '/v26.0/'.self::GRAPH_PAGE_ID.'/feed')
                && ($query['after'] ?? null) === 'posts-cursor-1'
                && ($query['limit'] ?? null) <= 100
                && ($query['fields'] ?? null) === 'id,message,created_time,status_type'
                && $request->hasHeader('Authorization', 'Bearer '.self::PAGE_TOKEN)
                && ! array_key_exists('access_token', $query);
        });
    }

    public function test_comments_include_reply_metadata_and_upsert_duplicate_ids(): void
    {
        $page = $this->createPage(User::factory()->create());
        $post = $this->createPost($page, self::GRAPH_PAGE_ID.'_post-1');
        Http::fake([
            '*' => Http::sequence()
                ->push([
                    'data' => [[
                        'id' => 'comment-1',
                        'message' => 'Root comment',
                        'created_time' => '2026-10-04T10:00:00+0000',
                        'from' => ['id' => 'user-1', 'name' => 'Samira'],
                    ]],
                    'paging' => [
                        'cursors' => ['after' => 'comments-cursor-1'],
                        'next' => 'https://graph.facebook.com/v26.0/post-1/comments?after=comments-cursor-1',
                    ],
                ], 200)
                ->push([
                    'data' => [[
                        'id' => 'comment-2',
                        'message' => 'Reply comment',
                        'created_time' => '2026-10-04T10:05:00+0000',
                        'from' => ['id' => 'user-2', 'name' => 'Imran'],
                        'parent' => ['id' => 'comment-1'],
                    ]],
                ], 200)
                ->push([
                    'data' => [[
                        'id' => 'comment-1',
                        'message' => 'Edited root comment',
                        'created_time' => '2026-10-04T10:00:00+0000',
                        'from' => ['id' => 'user-1', 'name' => 'Samira'],
                    ]],
                ], 200),
        ]);

        $service = app(FacebookPageService::class);
        $this->assertSame(2, $service->syncPostComments($page, $post));
        $this->assertDatabaseCount('facebook_comments', 2);
        $this->assertSame(1, $service->syncPostComments($page, $post));
        $this->assertDatabaseCount('facebook_comments', 2);
        $this->assertDatabaseHas('facebook_comments', [
            'facebook_comment_id' => 'comment-1',
            'author_name' => 'Samira',
            'message' => 'Edited root comment',
        ]);
        $this->assertDatabaseHas('facebook_comments', [
            'facebook_comment_id' => 'comment-2',
            'parent_comment_id' => 'comment-1',
            'facebook_post_id' => self::GRAPH_PAGE_ID.'_post-1',
        ]);

        Http::assertSent(function (ClientRequest $request): bool {
            $query = [];
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            return str_contains($request->url(), '/v26.0/'.self::GRAPH_PAGE_ID.'_post-1/comments')
                && ($query['filter'] ?? null) === 'stream'
                && ($query['limit'] ?? null) <= 100
                && ($query['after'] ?? null) === 'comments-cursor-1';
        });
    }

    public function test_unavailable_optional_comment_fields_fall_back_without_failing_sync(): void
    {
        $page = $this->createPage(User::factory()->create());
        Http::fake([
            '*' => Http::sequence()
                ->push([
                    'error' => [
                        'message' => 'Raw provider field diagnostic.',
                        'code' => 100,
                    ],
                ], 400)
                ->push([
                    'data' => [[
                        'id' => 'comment-core-only',
                        'message' => 'Core fields are available',
                        'created_time' => '2026-10-04T10:00:00+0000',
                    ]],
                ], 200),
        ]);

        $comments = app(FacebookPageService::class)->getPostComments($page, 'post-1');

        $this->assertCount(1, $comments);
        $this->assertSame('comment-core-only', $comments[0]['id']);
        $this->assertCount(2, Http::recorded());
    }

    public function test_queued_job_syncs_posts_and_comments_and_records_safe_summary(): void
    {
        $page = $this->createPage(User::factory()->create());
        Http::fake([
            '*' => Http::sequence()
                ->push([
                    'data' => [[
                        'id' => self::GRAPH_PAGE_ID.'_job-post',
                        'message' => 'Job post',
                        'status_type' => 'published_story',
                        'created_time' => '2026-10-05T12:00:00+0000',
                    ]],
                ], 200)
                ->push([
                    'data' => [[
                        'id' => 'job-comment',
                        'message' => 'Job comment',
                        'created_time' => '2026-10-05T13:00:00+0000',
                        'from' => ['id' => 'job-user', 'name' => 'Nila'],
                    ]],
                ], 200),
        ]);

        (new SyncFacebookPageJob((int) $page->id))->handle(app(FacebookPageService::class));

        $page->refresh();
        $this->assertSame('completed', $page->sync_status);
        $this->assertSame(1, $page->posts_synced_count);
        $this->assertSame(1, $page->comments_synced_count);
        $this->assertNotNull($page->last_synced_at);
        $this->assertDatabaseCount('facebook_posts', 1);
        $this->assertDatabaseCount('facebook_comments', 1);
    }

    public function test_invalid_token_marks_sync_failed_without_exposing_provider_error_or_token(): void
    {
        $owner = User::factory()->create();
        $page = $this->createPage($owner);
        Http::fake([
            '*' => Http::response([
                'error' => [
                    'message' => 'Raw provider token diagnostics.',
                    'type' => 'OAuthException',
                    'code' => 190,
                ],
            ], 400),
        ]);

        (new SyncFacebookPageJob((int) $page->id))->handle(app(FacebookPageService::class));
        $page->refresh();

        $this->assertSame('failed', $page->sync_status);
        $this->assertSame('Invalid Facebook Page Access Token.', $page->sync_error);
        $this->assertStringNotContainsString(self::PAGE_TOKEN, $page->sync_error);
        $this->assertStringNotContainsString('Raw provider token diagnostics', $page->sync_error);

        $response = $this->actingAs($owner)->getJson('/api/facebook/pages');
        $response->assertOk();
        $this->assertStringNotContainsString(self::PAGE_TOKEN, $response->getContent());
        $this->assertStringNotContainsString('Raw provider token diagnostics', $response->getContent());
    }

    public function test_permission_api_error_is_translated_to_a_safe_sync_exception(): void
    {
        $page = $this->createPage(User::factory()->create());
        Http::fake([
            '*' => Http::response([
                'error' => [
                    'message' => 'Secret Meta permission diagnostic.',
                    'type' => 'OAuthException',
                    'code' => 200,
                ],
            ], 403),
        ]);

        try {
            app(FacebookPageService::class)->syncPagePosts($page);
            $this->fail('Expected a permission error.');
        } catch (FacebookPageConnectionException $exception) {
            $this->assertSame(422, $exception->statusCode);
            $this->assertSame(200, $exception->metaErrorCode);
            $this->assertStringContainsString('Page Access Token lacks permission', $exception->getMessage());
            $this->assertStringNotContainsString('Secret Meta permission diagnostic', $exception->getMessage());
        }
    }

    public function test_repeated_graph_cursor_fails_safely_instead_of_looping(): void
    {
        config(['services.facebook.max_posts_per_sync' => 250]);
        $page = $this->createPage(User::factory()->create());
        Http::fake([
            '*' => Http::sequence()
                ->push([
                    'data' => [['id' => 'post-1']],
                    'paging' => [
                        'cursors' => ['after' => 'same-cursor'],
                        'next' => 'https://graph.facebook.com/v26.0/feed?after=same-cursor',
                    ],
                ], 200)
                ->push([
                    'data' => [['id' => 'post-2']],
                    'paging' => [
                        'cursors' => ['after' => 'same-cursor'],
                        'next' => 'https://graph.facebook.com/v26.0/feed?after=same-cursor',
                    ],
                ], 200),
        ]);

        try {
            app(FacebookPageService::class)->getPagePosts($page);
            $this->fail('Expected repeated cursor pagination to fail safely.');
        } catch (FacebookPageConnectionException $exception) {
            $this->assertSame(502, $exception->statusCode);
            $this->assertStringContainsString('repeated pagination cursor', $exception->getMessage());
        }

        $this->assertCount(2, Http::recorded());
    }

    public function test_comments_api_is_owned_filtered_searchable_paginated_and_token_free(): void
    {
        $owner = User::factory()->create();
        $otherOwner = User::factory()->create();
        $page = $this->createPage($owner);
        $otherPage = $this->createPage($otherOwner, '987654321098765', 'Other Page');

        $this->createComment($page, 'owner-comment-1', 'great book', '2026-10-07 10:00:00');
        $this->createComment($page, 'owner-comment-2', 'great service', '2026-10-08 10:00:00');
        $this->createComment($otherPage, 'other-owner-comment', 'great book', '2026-10-08 12:00:00');

        $response = $this->actingAs($owner)->getJson(
            "/api/facebook/comments?page_id={$page->id}&search=great&per_page=1&page=1",
        );

        $response
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('data.0.page.page_name', 'Harbor Books')
            ->assertJsonPath('data.0.sync_status', 'idle');
        $this->assertStringNotContainsString(self::PAGE_TOKEN, $response->getContent());
        $this->assertStringNotContainsString('page_access_token', $response->getContent());

        $this->actingAs($owner)
            ->getJson('/api/facebook/comments?page_id='.$otherPage->id)
            ->assertNotFound()
            ->assertExactJson(['message' => 'Page not found.']);

        $unfilteredOwnerView = $this->actingAs($owner)
            ->getJson('/api/facebook/comments?search=great&per_page=100');
        $unfilteredOwnerView->assertJsonPath('meta.total', 2);
    }

    private function createPage(User $owner, string $graphPageId = self::GRAPH_PAGE_ID, string $name = 'Harbor Books'): FacebookPage
    {
        return FacebookPage::query()->create([
            'user_id' => $owner->getKey(),
            'facebook_page_id' => $graphPageId,
            'page_name' => $name,
            'page_username' => null,
            'page_category' => 'Book shop',
            'page_picture_url' => null,
            'page_access_token' => self::PAGE_TOKEN,
            'token_expires_at' => null,
            'is_active' => true,
        ]);
    }

    private function createPost(FacebookPage $page, string $facebookPostId): FacebookPost
    {
        return FacebookPost::query()->create([
            'page_id' => $page->getKey(),
            'facebook_page_id' => $page->facebook_page_id,
            'facebook_post_id' => $facebookPostId,
            'post_message' => 'A saved test post',
            'post_type' => 'published_story',
            'post_created_at' => '2026-10-01 12:00:00',
            'is_active' => true,
        ]);
    }

    private function createComment(
        FacebookPage $page,
        string $facebookCommentId,
        string $message,
        string $createdAt,
    ): FacebookComment {
        return FacebookComment::query()->create([
            'page_id' => $page->getKey(),
            'post_id' => null,
            'facebook_page_id' => $page->facebook_page_id,
            'facebook_post_id' => self::GRAPH_PAGE_ID.'_post',
            'facebook_comment_id' => $facebookCommentId,
            'parent_comment_id' => null,
            'author_facebook_id' => null,
            'author_name' => null,
            'message' => $message,
            'comment_created_at' => $createdAt,
            'comment_updated_at' => null,
        ]);
    }
}
