<?php

namespace Tests\Feature;

use App\Jobs\ModerateCommentWithGeminiJob;
use App\Models\FacebookComment;
use App\Models\FacebookPage;
use App\Models\ModerationRule;
use App\Models\User;
use App\Services\FacebookPageService;
use App\Services\AiModerationQueueService;
use App\Services\AiModerationResultStore;
use App\Services\CommentModerationService;
use App\Services\GeminiModerationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class GeminiModerationServiceTest extends TestCase
{
    use RefreshDatabase;

    private const ADMIN_EMAIL = 'gemini-admin@example.test';
    private const PAGE_TOKEN = 'must-never-be-sent-to-gemini';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'moderation.admin_emails' => [self::ADMIN_EMAIL],
            'ai_moderation.enabled' => true,
            'ai_moderation.model' => 'gemini-3.8-flash',
            'ai_moderation.timeout' => 2,
            'ai_moderation.max_retries' => 0,
            'ai_moderation.retry_base_delay_ms' => 0,
            'ai_moderation.max_comment_characters' => 20000,
            'ai_moderation.max_post_context_characters' => 5000,
            'ai_moderation.min_action_confidence' => 0.65,
            'services.gemini.api_key' => 'test-gemini-key-never-returned',
        ]);
    }

    public function test_clean_bengali_comment_is_classified_as_clean(): void
    {
        $this->assertClassification(
            'ধন্যবাদ, আপনারা খুব ভালো সার্ভিস দেন।',
            $this->classification('keep', 'clean', 0.98, 'low', 'A polite positive comment.'),
            'clean',
        );
    }

    public function test_bengali_abusive_comment_is_classified_as_insult(): void
    {
        $this->assertClassification(
            'তুই একটা চোর',
            $this->classification('hide', 'insult', 0.94, 'high', 'Direct personal insult.'),
            'insult',
            'hide',
        );
    }

    public function test_banglish_abuse_is_classified_as_insult(): void
    {
        $this->assertClassification(
            'tui ekta chor, tor matha kharap',
            $this->classification('hide', 'insult', 0.90, 'high', 'Banglish personal insult.'),
            'insult',
            'hide',
        );
    }

    public function test_english_profanity_is_classified_without_platform_action(): void
    {
        $this->assertClassification(
            'fuck you',
            $this->classification('hide', 'profanity', 0.96, 'high', 'Directed profanity.'),
            'profanity',
            'hide',
        );
    }

    public function test_customer_complaint_is_not_treated_as_abuse(): void
    {
        $result = $this->assertClassification(
            'আমার order পাইনি, refund চাই',
            $this->classification('keep', 'customer_complaint', 0.93, 'low', 'Customer reports a missing order and requests a refund.'),
            'customer_complaint',
            'keep',
        );

        $this->assertSame('completed', $result->status);
    }

    public function test_negative_feedback_is_not_automatically_hidden_or_deleted(): void
    {
        $this->assertClassification(
            'vai product ta valo na',
            $this->classification('keep', 'negative_feedback', 0.91, 'low', 'The customer expresses product dissatisfaction.'),
            'negative_feedback',
            'keep',
        );
    }

    public function test_unsolicited_promotion_is_classified_as_spam(): void
    {
        $this->assertClassification(
            'Buy this now, click my link for a special offer',
            $this->classification('review', 'spam', 0.87, 'medium', 'Unsolicited commercial promotion.'),
            'spam',
            'review',
        );
    }

    public function test_competitor_whatsapp_solicitation_is_classified_as_competitor_spam(): void
    {
        $this->assertClassification(
            'এই পেজ থেকে কিনবেন না, আমার WhatsApp-এ আসেন',
            $this->classification('hide', 'competitor_spam', 0.90, 'medium', 'Redirects customers to a competing seller.'),
            'competitor_spam',
            'hide',
        );
    }

    public function test_scam_comment_is_classified_separately_from_ordinary_criticism(): void
    {
        $this->assertClassification(
            'Click this fake refund link and enter your card number',
            $this->classification('hide', 'scam', 0.95, 'high', 'The message requests sensitive payment details through a fake link.'),
            'scam',
            'hide',
        );
    }

    public function test_threatening_comment_gets_a_high_severity_recommendation(): void
    {
        $this->assertClassification(
            'I will come to your shop and hurt you',
            $this->classification('hide', 'threat', 0.97, 'critical', 'Direct threat of physical violence.'),
            'threat',
            'hide',
        );
    }

    public function test_malformed_model_json_returns_the_documented_review_fallback(): void
    {
        Http::fake(['*' => Http::response($this->providerResponse('not valid json'), 200)]);

        $result = app(GeminiModerationService::class)->classify('ordinary comment');

        $this->assertSame([
            'decision' => 'review',
            'category' => 'other',
            'confidence' => 0.0,
            'severity' => 'low',
            'reason' => 'Invalid AI response',
        ], $result->classification);
        $this->assertSame('failed', $result->status);
        $this->assertSame('invalid_json', $result->errorCategory);
    }

    public function test_api_timeout_returns_a_safe_review_fallback(): void
    {
        config(['ai_moderation.max_retries' => 1]);
        Http::fake(static function (ClientRequest $request): never {
            throw new ConnectionException('Connection timed out');
        });

        $result = app(GeminiModerationService::class)->classify('ordinary comment');

        $this->assertSame('review', $result->classification['decision']);
        $this->assertSame('other', $result->classification['category']);
        $this->assertSame('timeout', $result->errorCategory);
        Http::assertSentCount(2);
    }

    public function test_rate_limit_retries_with_backoff_then_succeeds(): void
    {
        config(['ai_moderation.max_retries' => 1]);
        $expected = $this->classification('review', 'suspicious', 0.72, 'medium', 'Needs a human look.');
        Http::fake([
            '*' => Http::sequence()
                ->push(['error' => ['status' => 'RESOURCE_EXHAUSTED']], 429)
                ->push($this->providerResponse($expected), 200),
        ]);

        $result = app(GeminiModerationService::class)->classify('possibly suspicious comment');

        $this->assertSame('completed', $result->status);
        $this->assertSame('suspicious', $result->classification['category']);
        $this->assertSame(2, $result->attemptCount);
        Http::assertSentCount(2);
    }

    public function test_invalid_api_key_is_not_retried_and_falls_back_to_review(): void
    {
        config(['ai_moderation.max_retries' => 4]);
        Http::fake(['*' => Http::response([
            'error' => [
                'status' => 'INVALID_ARGUMENT',
                'message' => 'API key not valid. Please provide a valid key.',
            ],
        ], 400)]);

        $result = app(GeminiModerationService::class)->classify('ordinary comment');

        $this->assertSame('review', $result->classification['decision']);
        $this->assertSame('invalid_api_key', $result->errorCategory);
        Http::assertSentCount(1);
    }

    public function test_low_confidence_aggressive_recommendation_is_downgraded_to_review(): void
    {
        $this->fakeClassification($this->classification('hide', 'insult', 0.42, 'medium', 'Possible insult.'));

        $result = app(GeminiModerationService::class)->classify('unclear mixed-language comment');

        $this->assertSame('review', $result->classification['decision']);
        $this->assertSame(0.42, $result->classification['confidence']);
    }

    public function test_high_confidence_risk_category_can_return_delete_for_final_policy_review(): void
    {
        $this->fakeClassification($this->classification('delete', 'threat', 0.99, 'critical', 'Direct threat.'));

        $result = app(GeminiModerationService::class)->classify('threatening comment');

        $this->assertSame('delete', $result->classification['decision']);
        $this->assertSame('threat', $result->classification['category']);
    }

    public function test_complaint_category_cannot_receive_a_hide_recommendation(): void
    {
        $this->fakeClassification($this->classification('hide', 'customer_complaint', 0.99, 'low', 'Customer requests a refund.'));

        $result = app(GeminiModerationService::class)->classify('refund please');

        $this->assertSame('review', $result->classification['decision']);
    }

    public function test_disabled_ai_marks_a_comment_skipped_without_calling_gemini(): void
    {
        config(['ai_moderation.enabled' => false]);
        $page = $this->createPage();
        $comment = $this->createComment($page, 'ordinary text');
        Http::fake();

        app(AiModerationQueueService::class)->schedule($comment);

        $this->assertSame('skipped', $comment->fresh()->ai_status);
        Http::assertNothingSent();
    }

    public function test_page_level_ai_disable_marks_comment_skipped(): void
    {
        $page = $this->createPage(false);
        $comment = $this->createComment($page, 'ordinary text');
        Http::fake();

        app(AiModerationQueueService::class)->schedule($comment);

        $this->assertSame('skipped', $comment->fresh()->ai_status);
        Http::assertNothingSent();
    }

    public function test_unmatched_comment_is_queued_after_manual_evaluation_and_storage(): void
    {
        Queue::fake();
        $page = $this->createPage();

        $comment = app(FacebookPageService::class)->upsertComment(
            $page,
            'unmatched-after-manual-check',
            null,
            [
                'id' => 'unmatched-after-manual-check',
                'message' => 'A normal product question',
            ],
        );

        $this->assertSame('no_manual_match', $comment->manual_moderation_status);
        $this->assertSame('none', $comment->manual_action);
        $this->assertSame('pending', $comment->ai_status);
        Queue::assertPushed(ModerateCommentWithGeminiJob::class, fn (ModerateCommentWithGeminiJob $job): bool => $job->commentId === $comment->getKey());
    }

    public function test_decisive_manual_rule_prevents_gemini_job_dispatch_after_storage(): void
    {
        Queue::fake();
        $page = $this->createPage();
        ModerationRule::query()->create([
            'facebook_page_id' => $page->facebook_page_id,
            'name' => 'Scam word',
            'category' => 'scam',
            'rule_type' => 'keyword',
            'pattern' => 'fraud',
            'action' => 'hide',
            'severity' => 'high',
            'priority' => 50,
            'is_active' => true,
        ]);

        $comment = app(FacebookPageService::class)->upsertComment(
            $page,
            'manual-decisive-comment',
            null,
            [
                'id' => 'manual-decisive-comment',
                'message' => 'This seller is fraud',
            ],
        );

        $this->assertSame('matched', $comment->manual_moderation_status);
        $this->assertSame('hide', $comment->manual_action);
        $this->assertSame('skipped', $comment->ai_status);
        Queue::assertNotPushed(ModerateCommentWithGeminiJob::class);
        Http::assertNothingSent();
    }

    public function test_manual_decisive_action_skips_ai_and_preserves_the_manual_recommendation(): void
    {
        $page = $this->createPage();
        $comment = $this->createComment($page, 'তুই একটা চোর');
        $comment->forceFill([
            'manual_moderation_status' => 'matched',
            'manual_action' => 'hide',
            'manual_category' => 'insult',
            'manual_severity' => 'high',
            'manual_reason' => 'Matched manual rule: insult',
        ])->save();
        Http::fake();

        app(AiModerationQueueService::class)->schedule($comment);

        $comment->refresh();
        $this->assertSame('skipped', $comment->ai_status);
        $this->assertSame('hide', $comment->manual_action);
        $this->assertSame('insult', $comment->manual_category);
        Http::assertNothingSent();
    }

    public function test_duplicate_job_execution_calls_gemini_only_once_and_creates_one_log(): void
    {
        Queue::fake();
        $page = $this->createPage();
        $comment = $this->createComment($page, 'This is a normal comment');
        $this->fakeClassification($this->classification('keep', 'clean', 0.98, 'low', 'A normal comment.'));

        app(AiModerationQueueService::class)->schedule($comment);
        $comment->refresh();
        $this->assertSame('pending', $comment->ai_status);

        $job = new ModerateCommentWithGeminiJob((int) $comment->getKey());
        $job->handle(
            app(GeminiModerationService::class),
            app(AiModerationQueueService::class),
            app(AiModerationResultStore::class),
            app(CommentModerationService::class),
        );
        (new ModerateCommentWithGeminiJob((int) $comment->getKey()))->handle(
            app(GeminiModerationService::class),
            app(AiModerationQueueService::class),
            app(AiModerationResultStore::class),
            app(CommentModerationService::class),
        );

        $this->assertSame('completed', $comment->fresh()->ai_status);
        $this->assertSame('keep', $comment->fresh()->ai_action);
        $this->assertDatabaseCount('ai_moderation_logs', 1);
        Http::assertSentCount(1);
    }

    public function test_valid_structured_response_is_validated_and_saved_without_a_token_in_the_request(): void
    {
        Queue::fake();
        $page = $this->createPage();
        $comment = $this->createComment($page, 'Customer asks for an update');
        $classification = $this->classification('review', 'customer_complaint', 0.94, 'low', 'The customer asks for an order update.');
        $this->fakeClassification($classification);
        app(AiModerationQueueService::class)->schedule($comment);

        (new ModerateCommentWithGeminiJob((int) $comment->getKey()))->handle(
            app(GeminiModerationService::class),
            app(AiModerationQueueService::class),
            app(AiModerationResultStore::class),
            app(CommentModerationService::class),
        );

        $this->assertSame('customer_complaint', $comment->fresh()->ai_category);
        $this->assertSame(0.94, $comment->fresh()->ai_confidence);
        $this->assertSame('completed', $comment->fresh()->ai_status);
        $this->assertDatabaseHas('ai_moderation_logs', [
            'provider' => 'gemini',
            'decision' => 'review',
            'category' => 'customer_complaint',
        ]);
        Http::assertSent(static function (ClientRequest $request): bool {
            $data = $request->data();
            $encoded = json_encode($data);
            $schema = $data['response_format']['schema'] ?? [];
            $systemInstruction = $data['system_instruction'] ?? '';
            $input = $data['input'][0]['content'] ?? '';

            return str_contains($request->url(), '/v1beta/interactions')
                && $request->hasHeader('x-goog-api-key', 'test-gemini-key-never-returned')
                && $request->hasHeader('Api-Revision', '2026-05-20')
                && is_string($encoded)
                && ! str_contains($encoded, self::PAGE_TOKEN)
                && ! str_contains($encoded, 'author_facebook_id')
                && ($data['store'] ?? null) === false
                && ($data['response_format']['type'] ?? null) === 'text'
                && ($data['response_format']['mime_type'] ?? null) === 'application/json'
                && ($schema['additionalProperties'] ?? true) === false
                && in_array('customer_complaint', $schema['properties']['category']['enum'] ?? [], true)
                && is_string($systemInstruction)
                && str_contains($systemInstruction, 'Banglish')
                && is_string($input)
                && str_contains($input, 'comment_text');
        });
    }

    public function test_unknown_category_returns_invalid_response_fallback(): void
    {
        $this->fakeClassification($this->classification('hide', 'politics', 0.9, 'high', 'Unknown category.'));

        $result = app(GeminiModerationService::class)->classify('some comment');

        $this->assertSame('review', $result->classification['decision']);
        $this->assertSame('other', $result->classification['category']);
        $this->assertSame('Invalid AI response', $result->classification['reason']);
        $this->assertSame('invalid_category', $result->errorCategory);
    }

    public function test_empty_provider_response_falls_back_without_crashing(): void
    {
        Http::fake(['*' => Http::response(['status' => 'completed', 'steps' => []], 200)]);

        $result = app(GeminiModerationService::class)->classify('ordinary comment');

        $this->assertSame('review', $result->classification['decision']);
        $this->assertSame('empty_response', $result->errorCategory);
    }

    public function test_non_completed_interaction_cannot_supply_a_moderation_decision(): void
    {
        $body = $this->providerResponse($this->classification('delete', 'threat', 0.999, 'critical', 'Untrusted partial output.'));
        $body['status'] = 'in_progress';
        Http::fake(['*' => Http::response($body, 200)]);

        $result = app(GeminiModerationService::class)->classify('uncertain comment');

        $this->assertSame('failed', $result->status);
        $this->assertSame('interaction_not_completed', $result->errorCategory);
        $this->assertSame('review', $result->classification['decision']);
        $this->assertSame('other', $result->classification['category']);
    }

    public function test_unsupported_model_is_not_retried(): void
    {
        config(['ai_moderation.max_retries' => 3]);
        Http::fake(['*' => Http::response(['error' => ['status' => 'NOT_FOUND']], 404)]);

        $result = app(GeminiModerationService::class)->classify('ordinary comment');

        $this->assertSame('unsupported_model', $result->errorCategory);
        Http::assertSentCount(1);
    }

    public function test_read_only_ai_tester_is_admin_protected_and_does_not_change_a_comment(): void
    {
        $admin = User::factory()->create(['email' => self::ADMIN_EMAIL]);
        $page = $this->createPage();
        $comment = $this->createComment($page, 'Stored original text');
        $this->fakeClassification($this->classification('keep', 'clean', 0.96, 'low', 'A neutral comment.'));

        $this->postJson('/api/moderation/ai/test', ['comment' => 'A separate test comment'])
            ->assertUnauthorized();

        $response = $this->actingAs($admin)->postJson('/api/moderation/ai/test', [
            'comment' => 'A separate test comment',
            'page_name' => 'Context only',
            'post_text' => 'Optional post context',
        ]);
        $response->assertOk()
            ->assertJsonPath('data.category', 'clean')
            ->assertJsonMissingPath('data.api_key');

        $this->assertSame('Stored original text', $comment->fresh()->message);
        $this->assertNull($comment->fresh()->ai_status);
        $this->assertDatabaseCount('ai_moderation_logs', 0);
    }

    public function test_settings_endpoint_exposes_only_safe_configuration_and_page_toggle_is_admin_only(): void
    {
        $admin = User::factory()->create(['email' => self::ADMIN_EMAIL]);
        $page = $this->createPage();

        $this->getJson('/api/moderation/ai/settings')->assertUnauthorized();

        $response = $this->actingAs($admin)->getJson('/api/moderation/ai/settings');
        $response->assertOk()
            ->assertJsonPath('data.model', 'gemini-3.8-flash')
            ->assertJsonPath('data.api_key_configured', true)
            ->assertJsonPath('data.failure_decision', 'review')
            ->assertJsonPath('data.default_action_settings.test_mode', true)
            ->assertJsonMissingPath('data.api_key')
            ->assertJsonMissingPath('data.pages.0.page_access_token');

        $this->patchJson('/api/moderation/ai/pages/'.$page->getKey().'/settings', ['ai_enabled' => false])
            ->assertUnauthorized();
        $this->actingAs($admin)
            ->patchJson('/api/moderation/ai/pages/'.$page->getKey().'/settings', ['ai_enabled' => false])
            ->assertOk()
            ->assertJsonPath('data.ai_enabled', false);
        $this->assertFalse($page->fresh()->ai_enabled);
    }

    public function test_manual_review_match_is_final_and_prevents_gemini_dispatch(): void
    {
        Queue::fake();
        Http::fake();
        $page = $this->createPage();
        ModerationRule::query()->create([
            'facebook_page_id' => $page->facebook_page_id,
            'name' => 'Review complaints',
            'category' => 'customer_complaint',
            'rule_type' => 'keyword',
            'pattern' => 'complaint',
            'action' => 'review',
            'severity' => 'low',
            'priority' => 25,
            'is_active' => true,
        ]);

        $comment = app(FacebookPageService::class)->upsertComment(
            $page,
            'manual-review-is-final',
            null,
            ['id' => 'manual-review-is-final', 'message' => 'This is a complaint'],
        );

        $this->assertSame('matched', $comment->manual_moderation_status);
        $this->assertSame('review', $comment->manual_action);
        $this->assertSame('skipped', $comment->ai_status);
        $this->assertSame('review', $comment->final_action);
        $this->assertSame('manual', $comment->final_method);
        Queue::assertNotPushed(ModerateCommentWithGeminiJob::class);
        Http::assertNothingSent();
    }

    private function assertClassification(
        string $input,
        array $output,
        string $expectedCategory,
        string $expectedDecision = 'keep',
    ): object {
        $this->fakeClassification($output);

        $result = app(GeminiModerationService::class)->classify($input);

        $this->assertSame('completed', $result->status);
        $this->assertSame($expectedCategory, $result->classification['category']);
        $this->assertSame($expectedDecision, $result->classification['decision']);
        $this->assertGreaterThanOrEqual(0, $result->classification['confidence']);
        $this->assertLessThanOrEqual(1, $result->classification['confidence']);

        return $result;
    }

    private function fakeClassification(array $classification): void
    {
        Http::fake(['*' => Http::response($this->providerResponse($classification), 200)]);
    }

    private function providerResponse(string|array $output): array
    {
        $text = is_string($output)
            ? $output
            : json_encode($output, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        return [
            'status' => 'completed',
            'steps' => [[
                'type' => 'model_output',
                'content' => [[
                    'type' => 'text',
                    'text' => $text,
                ]],
            ]],
            'usage' => [
                'total_input_tokens' => 120,
                'total_output_tokens' => 20,
                'total_tokens' => 140,
            ],
        ];
    }

    private function classification(
        string $decision,
        string $category,
        float $confidence,
        string $severity,
        string $reason,
    ): array {
        return compact('decision', 'category', 'confidence', 'severity', 'reason');
    }

    private function createPage(bool $aiEnabled = true): FacebookPage
    {
        return FacebookPage::query()->create([
            'user_id' => User::factory()->create()->getKey(),
            'facebook_page_id' => 'gemini-test-page-'.uniqid(),
            'page_name' => 'Gemini Test Page',
            'page_access_token' => self::PAGE_TOKEN,
            'is_active' => true,
            'ai_enabled' => $aiEnabled,
        ]);
    }

    private function createComment(FacebookPage $page, string $message): FacebookComment
    {
        return FacebookComment::query()->create([
            'page_id' => $page->getKey(),
            'facebook_page_id' => $page->facebook_page_id,
            'facebook_comment_id' => 'gemini-comment-'.uniqid(),
            'message' => $message,
            'manual_moderation_status' => 'no_manual_match',
            'manual_action' => 'none',
        ]);
    }
}
