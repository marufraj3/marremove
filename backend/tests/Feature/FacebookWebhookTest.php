<?php

namespace Tests\Feature;

use App\Jobs\ProcessFacebookWebhookJob;
use App\Models\FacebookComment;
use App\Models\FacebookPage;
use App\Models\FacebookPost;
use App\Models\FacebookWebhookEvent;
use App\Models\User;
use App\Services\FacebookWebhookService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class FacebookWebhookTest extends TestCase
{
    use RefreshDatabase;

    private const APP_SECRET = 'test-only-facebook-app-secret';
    private const VERIFY_TOKEN = 'test-only-webhook-verify-token';
    private const PAGE_TOKEN = 'test-only-encrypted-page-token';
    private const PAGE_ID = '123456789012345';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'app.url' => 'https://app.example.test',
            'services.facebook.graph_version' => 'v26.0',
            'services.facebook.app_secret' => self::APP_SECRET,
            'services.facebook.webhook_verify_token' => self::VERIFY_TOKEN,
        ]);
    }

    public function test_meta_verification_echoes_challenge_and_records_verification_without_saving_token(): void
    {
        $response = $this->get('/api/facebook/webhook?hub.mode=subscribe&hub.verify_token='.urlencode(self::VERIFY_TOKEN).'&hub.challenge=challenge-123');

        $response->assertOk();
        $this->assertSame('challenge-123', $response->getContent());
        $this->assertDatabaseHas('facebook_webhook_statuses', [
            'status_key' => 'pages',
            'verify_token_fingerprint' => hash('sha256', self::VERIFY_TOKEN),
        ]);
        $this->assertDatabaseMissing('facebook_webhook_statuses', [
            'verify_token_fingerprint' => self::VERIFY_TOKEN,
        ]);
    }

    public function test_meta_verification_rejects_an_incorrect_verify_token(): void
    {
        $response = $this->get('/api/facebook/webhook?hub.mode=subscribe&hub.verify_token=wrong-token&hub.challenge=private-challenge');

        $response->assertForbidden();
        $this->assertStringNotContainsString('private-challenge', $response->getContent());
        $this->assertDatabaseCount('facebook_webhook_statuses', 0);
    }

    public function test_valid_signed_page_comment_delivery_is_acknowledged_and_queued(): void
    {
        Bus::fake();
        $this->createPage(User::factory()->create());

        $response = $this->postSignedPayload($this->commentPayload('add', 'comment-new'));

        $response->assertOk()->assertSeeText('EVENT_RECEIVED');
        $this->assertDatabaseCount('facebook_webhook_events', 1);
        $event = FacebookWebhookEvent::query()->firstOrFail();
        $this->assertSame('comment.created', $event->event_type);
        $this->assertSame('queued', $event->status);
        $this->assertSame('comment-new', $event->facebook_comment_id);
        $this->assertNotNull($event->dispatched_at);
        Bus::assertDispatched(ProcessFacebookWebhookJob::class, fn (ProcessFacebookWebhookJob $job): bool => $job->eventKey === $event->event_key);
        $this->assertStringNotContainsString(self::APP_SECRET, $response->getContent());
        $this->assertStringNotContainsString(self::PAGE_TOKEN, $response->getContent());
    }

    public function test_invalid_signature_is_rejected_before_parsing_or_queueing(): void
    {
        $this->createPage(User::factory()->create());
        $rawBody = json_encode($this->commentPayload('add', 'comment-invalid-signature'), JSON_THROW_ON_ERROR);
        $badSignature = 'sha256='.str_repeat('0', 64);

        $response = $this->postRawBody($rawBody, $badSignature);

        $response->assertUnauthorized();
        $this->assertDatabaseCount('facebook_webhook_events', 0);
    }

    public function test_duplicate_webhook_delivery_creates_and_dispatches_only_one_event(): void
    {
        Bus::fake();
        $this->createPage(User::factory()->create());
        $rawBody = json_encode($this->commentPayload('add', 'comment-duplicate'), JSON_THROW_ON_ERROR);

        $this->postSignedRawBody($rawBody)->assertOk();
        $this->postSignedRawBody($rawBody)->assertOk();

        $this->assertDatabaseCount('facebook_webhook_events', 1);
        Bus::assertDispatchedTimes(ProcessFacebookWebhookJob::class, 1);
    }

    public function test_signed_comment_update_fetches_latest_graph_details_and_upserts_existing_comment(): void
    {
        Bus::fake();
        $page = $this->createPage(User::factory()->create());
        $post = $this->createPost($page, self::PAGE_ID.'_post-1');
        FacebookComment::query()->create([
            'page_id' => $page->getKey(),
            'post_id' => $post->getKey(),
            'facebook_page_id' => self::PAGE_ID,
            'facebook_post_id' => self::PAGE_ID.'_post-1',
            'facebook_comment_id' => 'comment-edited',
            'author_facebook_id' => 'author-1',
            'author_name' => 'Rina',
            'message' => 'Original comment text',
            'comment_created_at' => '2026-10-01 10:00:00',
        ]);

        $this->postSignedPayload($this->commentPayload('edited', 'comment-edited'))->assertOk();
        $event = FacebookWebhookEvent::query()->firstOrFail();
        Http::fake([
            '*' => Http::response([
                'id' => 'comment-edited',
                'message' => 'Updated comment text',
                'created_time' => '2026-10-01T10:00:00+0000',
                'from' => ['id' => 'author-1', 'name' => 'Rina'],
                'parent' => ['id' => 'comment-parent'],
            ], 200),
        ]);

        app(FacebookWebhookService::class)->processEvent($event->event_key);

        $event->refresh();
        $this->assertSame('comment.updated', $event->event_type);
        $this->assertSame('processed', $event->status);
        $this->assertNotNull($event->processed_at);
        $this->assertDatabaseCount('facebook_comments', 1);
        $this->assertDatabaseHas('facebook_comments', [
            'facebook_comment_id' => 'comment-edited',
            'message' => 'Updated comment text',
            'parent_comment_id' => 'comment-parent',
            'facebook_post_id' => self::PAGE_ID.'_post-1',
        ]);
        $this->assertNotNull($page->fresh()->webhook_last_processed_at);
    }

    public function test_malformed_json_is_rejected_without_creating_an_event(): void
    {
        Bus::fake();
        $this->createPage(User::factory()->create());

        $response = $this->postSignedRawBody('{"object":"page","entry":[', self::APP_SECRET);

        $response->assertBadRequest();
        $this->assertDatabaseCount('facebook_webhook_events', 0);
        Bus::assertNotDispatched(ProcessFacebookWebhookJob::class);
    }

    public function test_comment_event_is_dispatched_with_only_the_event_key(): void
    {
        Bus::fake();
        $this->createPage(User::factory()->create());

        $this->postSignedPayload($this->commentPayload('add', 'comment-queue-check'))->assertOk();

        $event = FacebookWebhookEvent::query()->firstOrFail();
        Bus::assertDispatched(ProcessFacebookWebhookJob::class, function (ProcessFacebookWebhookJob $job) use ($event): bool {
            return $job->eventKey === $event->event_key
                && ! str_contains($job->eventKey, self::PAGE_TOKEN)
                && ! str_contains($job->eventKey, self::APP_SECRET);
        });
    }

    public function test_expired_page_token_marks_event_failed_and_never_saves_comment(): void
    {
        Bus::fake();
        $page = $this->createPage(User::factory()->create());
        $this->postSignedPayload($this->commentPayload('add', 'comment-expired-token'))->assertOk();
        $event = FacebookWebhookEvent::query()->firstOrFail();
        Http::fake([
            '*' => Http::response([
                'error' => [
                    'message' => 'This Page access token has expired.',
                    'type' => 'OAuthException',
                    'code' => 190,
                    'error_subcode' => 463,
                ],
            ], 400),
        ]);

        app(FacebookWebhookService::class)->processEvent($event->event_key);

        $event->refresh();
        $this->assertSame('failed', $event->status);
        $this->assertSame('expired_token', $event->error_category);
        $this->assertDatabaseCount('facebook_comments', 0);
        $this->assertNull($page->fresh()->webhook_last_processed_at);
    }

    public function test_unknown_signed_payload_shape_is_acknowledged_and_ignored(): void
    {
        Bus::fake();

        $response = $this->postSignedPayload(['unrecognized' => ['payload' => 'shape']]);

        $response->assertOk()->assertSeeText('EVENT_RECEIVED');
        $this->assertDatabaseCount('facebook_webhook_events', 0);
        Bus::assertNotDispatched(ProcessFacebookWebhookJob::class);
    }

    public function test_authenticated_status_page_api_does_not_expose_any_secrets(): void
    {
        $owner = User::factory()->create();
        $page = $this->createPage($owner);
        $page->forceFill(['webhook_last_received_at' => now()])->save();

        $response = $this->actingAs($owner)->getJson('/api/facebook/webhook/status');

        $response->assertOk()
            ->assertJsonPath('data.webhook_url', 'https://app.example.test/api/facebook/webhook')
            ->assertJsonPath('data.verification_status', 'pending')
            ->assertJsonPath('data.app_secret_configured', true)
            ->assertJsonPath('data.verify_token_configured', true);
        $this->assertStringNotContainsString(self::APP_SECRET, $response->getContent());
        $this->assertStringNotContainsString(self::VERIFY_TOKEN, $response->getContent());
        $this->assertStringNotContainsString(self::PAGE_TOKEN, $response->getContent());
    }

    /** @return array<string, mixed> */
    private function commentPayload(string $verb, string $commentId): array
    {
        return [
            'object' => 'page',
            'entry' => [[
                'id' => self::PAGE_ID,
                'time' => 1791460800,
                'changes' => [[
                    'field' => 'feed',
                    'value' => [
                        'item' => 'comment',
                        'verb' => $verb,
                        'comment_id' => $commentId,
                        'post_id' => self::PAGE_ID.'_post-1',
                    ],
                ]],
            ]],
        ];
    }

    private function postSignedPayload(array $payload): \Illuminate\Testing\TestResponse
    {
        return $this->postSignedRawBody(json_encode($payload, JSON_THROW_ON_ERROR));
    }

    private function postSignedRawBody(string $rawBody, string $secret = self::APP_SECRET): \Illuminate\Testing\TestResponse
    {
        $signature = 'sha256='.hash_hmac('sha256', $rawBody, $secret);

        return $this->postRawBody($rawBody, $signature);
    }

    private function postRawBody(string $rawBody, string $signature): \Illuminate\Testing\TestResponse
    {
        return $this->call(
            'POST',
            '/api/facebook/webhook',
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_ACCEPT' => 'application/json',
                'HTTP_X_HUB_SIGNATURE_256' => $signature,
            ],
            $rawBody,
        );
    }

    private function createPage(User $owner): FacebookPage
    {
        return FacebookPage::query()->create([
            'user_id' => $owner->getKey(),
            'facebook_page_id' => self::PAGE_ID,
            'page_name' => 'Webhook test Page',
            'page_username' => 'webhook-page',
            'page_access_token' => self::PAGE_TOKEN,
            'is_active' => true,
        ]);
    }

    private function createPost(FacebookPage $page, string $facebookPostId): FacebookPost
    {
        return FacebookPost::query()->create([
            'page_id' => $page->getKey(),
            'facebook_page_id' => self::PAGE_ID,
            'facebook_post_id' => $facebookPostId,
            'post_message' => 'A test post',
            'is_active' => true,
        ]);
    }
}
