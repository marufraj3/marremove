<?php

namespace Tests\Feature;

use App\Jobs\ModerateCommentWithGeminiJob;
use App\Models\FacebookComment;
use App\Models\FacebookPage;
use App\Models\ModerationLog;
use App\Models\ModerationRule;
use App\Models\User;
use App\Services\AiModerationQueueService;
use App\Services\AiModerationResultStore;
use App\Services\CommentModerationService;
use App\Services\GeminiModerationService;
use App\Services\PageModerationSettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class CommentModerationServiceTest extends TestCase
{
    use RefreshDatabase;

    private const ADMIN_EMAIL = 'final-moderation-admin@example.test';
    private const PAGE_TOKEN = 'must-not-leave-the-backend';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'moderation.admin_emails' => [self::ADMIN_EMAIL],
            'moderation.auto_delete_threshold' => 0.98,
            'moderation.auto_hide_threshold' => 0.90,
            'moderation.auto_review_threshold' => 0.70,
            'moderation.allow_ai_delete' => false,
            'moderation.allow_ai_hide' => true,
            'ai_moderation.enabled' => true,
            'ai_moderation.model' => 'gemini-3.8-flash',
            'ai_moderation.timeout' => 2,
            'ai_moderation.max_retries' => 0,
            'ai_moderation.retry_base_delay_ms' => 0,
            'ai_moderation.min_action_confidence' => 0.65,
            'services.gemini.api_key' => 'mock-gemini-key',
        ]);
    }

    public function test_decisive_manual_delete_rule_wins_over_a_prior_ai_keep_result(): void
    {
        Queue::fake();
        Http::fake();
        $page = $this->createPage();
        $comment = $this->createComment($page, 'fraud123 seller');
        $inputHash = app(GeminiModerationService::class)->inputHash(
            $comment->message,
            $page->page_name,
            null,
            ['matched' => false, 'action' => 'none', 'category' => null, 'severity' => null],
        );
        $comment->forceFill([
            'ai_status' => 'completed',
            'ai_action' => 'keep',
            'ai_category' => 'clean',
            'ai_confidence' => 0.99,
            'ai_severity' => 'low',
            'ai_reason' => 'Prior AI result said keep.',
            'ai_input_hash' => $inputHash,
        ])->save();
        $rule = $this->createRule($page, 'fraud123', 'delete');

        $result = app(CommentModerationService::class)->moderateComment($comment);

        $this->assertSame('delete', $result['action']);
        $this->assertSame('manual', $result['method']);
        $this->assertSame('manual_rule', $result['source']);
        $this->assertSame($rule->getKey(), $result['rule_id']);
        $this->assertSame('delete', $comment->fresh()->final_action);
        $this->assertSame('completed', $comment->fresh()->ai_status);
        $this->assertSame('keep', $comment->fresh()->ai_action);
        $this->assertSame('clean', $comment->fresh()->ai_category);
        Http::assertNothingSent();
    }

    public function test_ai_hide_threshold_recommends_hide_at_the_boundary(): void
    {
        $page = $this->createPage();
        $comment = $this->createComment($page, 'Repeated unsolicited offers');
        $this->updatePageSettings($page, ['allow_ai_hide' => true, 'allow_ai_delete' => false]);

        $final = $this->runAi($comment, $this->classification('hide', 'spam', 0.90, 'medium', 'Repeated unsolicited promotion.'));

        $this->assertSame('hide', $final->final_action);
        $this->assertSame('auto_hide_threshold', $final->final_threshold_name);
        $this->assertSame(0.90, $final->final_threshold_value);
        $this->assertSame('gemini', $final->final_source);
    }

    public function test_ai_delete_threshold_recommends_delete_only_when_page_allows_it(): void
    {
        $page = $this->createPage();
        $comment = $this->createComment($page, 'High confidence scam solicitation');
        $this->updatePageSettings($page, ['allow_ai_delete' => true]);

        $final = $this->runAi($comment, $this->classification('delete', 'scam', 0.98, 'critical', 'The comment solicits payment through a scam link.'));

        $this->assertSame('delete', $final->final_action);
        $this->assertSame('auto_delete_threshold', $final->final_threshold_name);
        $this->assertSame(0.98, $final->final_threshold_value);
    }

    public function test_customer_complaint_never_becomes_an_ai_delete_decision(): void
    {
        $page = $this->createPage();
        $comment = $this->createComment($page, 'refund chai, order pai nai');
        $this->updatePageSettings($page, ['allow_ai_delete' => true]);

        $final = $this->runAi($comment, $this->classification('delete', 'customer_complaint', 0.99, 'low', 'The customer asks for a refund.'));

        $this->assertSame('review', $final->final_action);
        $this->assertSame('customer_complaint', $final->final_category);
        $this->assertSame('protected_category', $final->final_threshold_name);
    }

    public function test_negative_feedback_never_becomes_an_ai_delete_decision(): void
    {
        $page = $this->createPage();
        $comment = $this->createComment($page, 'product valo na');
        $this->updatePageSettings($page, ['allow_ai_delete' => true]);

        $final = $this->runAi($comment, $this->classification('delete', 'negative_feedback', 0.999, 'low', 'The customer dislikes the product.'));

        $this->assertSame('review', $final->final_action);
        $this->assertSame('negative_feedback', $final->final_category);
    }

    public function test_safe_categories_do_not_escalate_an_ai_delete_recommendation(): void
    {
        $page = $this->createPage();
        $this->updatePageSettings($page, ['allow_ai_delete' => true]);
        $cleanComment = $this->createComment($page, 'A normal thank-you comment');
        $otherComment = $this->createComment($page, 'Unclear general text');

        $clean = $this->runAi($cleanComment, $this->classification('delete', 'clean', 0.999, 'low', 'A polite thank-you.'));
        $other = $this->runAi($otherComment, $this->classification('delete', 'other', 0.999, 'low', 'The content does not match a risky category.'));

        $this->assertSame('review', $clean->ai_action);
        $this->assertSame('review', $clean->final_action);
        $this->assertSame('review', $other->ai_action);
        $this->assertSame('review', $other->final_action);
    }

    public function test_ai_disabled_uses_fallback_review_without_calling_gemini(): void
    {
        Queue::fake();
        Http::fake();
        $page = $this->createPage();
        $this->updatePageSettings($page, ['ai_enabled' => false]);
        $comment = $this->createComment($page, 'ordinary question');

        $result = app(CommentModerationService::class)->moderateComment($comment);

        $this->assertSame('review', $result['action']);
        $this->assertSame('fallback', $result['method']);
        $this->assertSame('AI unavailable', $result['reason']);
        $this->assertSame('skipped', $comment->fresh()->ai_status);
        Http::assertNothingSent();
    }

    public function test_manual_disabled_allows_ai_to_make_the_final_decision(): void
    {
        Queue::fake();
        $page = $this->createPage();
        $comment = $this->createComment($page, 'fraud123');
        $this->createRule($page, 'fraud123', 'delete');
        $this->updatePageSettings($page, ['manual_enabled' => false]);

        $final = $this->runAi($comment, $this->classification('keep', 'clean', 0.97, 'low', 'The text is a clean comment.'));

        $this->assertSame('disabled', $final->manual_moderation_status);
        $this->assertSame('keep', $final->final_action);
        $this->assertSame('gemini', $final->final_source);
    }

    public function test_gemini_failure_uses_review_fallback_and_never_deletes(): void
    {
        Queue::fake();
        Http::fake(['*' => Http::response(['error' => ['status' => 'UNAVAILABLE']], 503)]);
        $page = $this->createPage();
        $comment = $this->createComment($page, 'uncertain content');

        $pending = app(CommentModerationService::class)->moderateComment($comment);
        $this->assertSame('pending', $pending['status']);
        $this->runQueuedJob($comment);

        $final = $comment->fresh();
        $this->assertSame('failed', $final->ai_status);
        $this->assertSame('review', $final->final_action);
        $this->assertSame('fallback', $final->final_method);
        $this->assertSame('fallback', $final->final_source);
        $this->assertSame('AI unavailable', $final->final_reason);
        $this->assertNotSame('delete', $final->final_action);
    }

    public function test_admin_override_saves_final_action_and_audit_log_without_facebook_action(): void
    {
        Queue::fake();
        $admin = User::factory()->create(['email' => self::ADMIN_EMAIL]);
        $page = $this->createPage(owner: $admin);
        $comment = $this->createComment($page, 'Competitor promotion');
        $this->runAi($comment, $this->classification('hide', 'competitor_spam', 0.95, 'high', 'Redirects customers to a competitor.'));

        foreach (['keep', 'review', 'hide', 'delete'] as $action) {
            $response = $this->actingAs($admin)->patchJson('/api/moderation/comments/'.$comment->getKey().'/override', [
                'action' => $action,
                'reason' => 'Reviewed manually; this is an approved partner.',
            ]);

            $response->assertOk()
                ->assertJsonPath('data.final_moderation.action', $action)
                ->assertJsonPath('data.final_moderation.manual_override', true)
                ->assertJsonPath('data.final_moderation.overridden_by', $admin->getKey());
        }

        $this->assertSame('delete', $comment->fresh()->final_action);
        $this->assertSame($admin->getKey(), $comment->fresh()->overridden_by);
        $this->assertNotNull($comment->fresh()->overridden_at);
        $this->assertDatabaseHas('moderation_logs', [
            'comment_id' => $comment->getKey(),
            'actor_id' => $admin->getKey(),
            'source' => 'manual_override',
        ]);
        $this->assertSame(5, ModerationLog::query()->where('comment_id', $comment->getKey())->count());
        $latestLog = ModerationLog::query()->where('comment_id', $comment->getKey())->latest('id')->firstOrFail();
        $this->assertTrue($latestLog->final_result['manual_override']);
        Http::assertSentCount(1); // The earlier mocked Gemini call only; overrides send no Facebook request.
        Http::assertSent(static fn (ClientRequest $request): bool => str_contains($request->url(), '/v1beta/interactions'));
    }

    public function test_non_admin_cannot_override_a_final_decision(): void
    {
        $page = $this->createPage();
        $comment = $this->createComment($page, 'ordinary text');
        $regularUser = User::factory()->create(['email' => 'regular-user@example.test']);

        $this->patchJson('/api/moderation/comments/'.$comment->getKey().'/override', ['action' => 'delete'])
            ->assertUnauthorized();
        $this->actingAs($regularUser)
            ->patchJson('/api/moderation/comments/'.$comment->getKey().'/override', ['action' => 'delete'])
            ->assertForbidden();
        $this->assertFalse((bool) $comment->fresh()->manual_override);
    }

    public function test_page_settings_are_persisted_independently_and_mirror_legacy_ai_flag(): void
    {
        $admin = User::factory()->create(['email' => self::ADMIN_EMAIL]);
        $pageA = $this->createPage('Settings Page A', $admin);
        $pageB = $this->createPage('Settings Page B', $admin);

        $this->actingAs($admin)->patchJson('/api/moderation/ai/pages/'.$pageA->getKey().'/settings', [
            'ai_enabled' => false,
            'manual_enabled' => true,
            'auto_review_threshold' => 0.65,
            'auto_hide_threshold' => 0.85,
            'auto_delete_threshold' => 0.97,
            'allow_ai_hide' => true,
            'allow_ai_delete' => false,
        ])->assertOk()->assertJsonPath('data.ai_enabled', false)
            ->assertJsonPath('data.auto_hide_threshold', 0.85);

        $settings = $this->actingAs($admin)->getJson('/api/moderation/ai/settings');
        $settings->assertOk()
            ->assertJsonPath('data.pages.0.ai_enabled', false)
            ->assertJsonPath('data.pages.0.manual_enabled', true);
        $this->assertFalse($pageA->fresh()->ai_enabled);
        $this->assertTrue($pageB->fresh()->ai_enabled);
        $this->assertDatabaseHas('page_moderation_settings', [
            'page_id' => $pageA->getKey(),
            'facebook_page_id' => $pageA->facebook_page_id,
            'auto_hide_threshold' => 0.850,
        ]);
        $this->assertDatabaseHas('page_moderation_settings', [
            'page_id' => $pageB->getKey(),
            'ai_enabled' => true,
        ]);
    }

    public function test_threshold_order_is_validated_per_page(): void
    {
        $admin = User::factory()->create(['email' => self::ADMIN_EMAIL]);
        $page = $this->createPage(owner: $admin);

        $this->actingAs($admin)->patchJson('/api/moderation/ai/pages/'.$page->getKey().'/settings', [
            'auto_review_threshold' => 0.92,
            'auto_hide_threshold' => 0.90,
            'auto_delete_threshold' => 0.98,
        ])->assertUnprocessable()->assertJsonValidationErrors('auto_hide_threshold');
    }

    public function test_threshold_precision_is_validated_to_match_database_storage(): void
    {
        $admin = User::factory()->create(['email' => self::ADMIN_EMAIL]);
        $page = $this->createPage(owner: $admin);

        $this->actingAs($admin)->patchJson('/api/moderation/ai/pages/'.$page->getKey().'/settings', [
            'auto_review_threshold' => 0.7001,
        ])->assertUnprocessable()->assertJsonValidationErrors('auto_review_threshold');
    }

    public function test_different_pages_apply_different_thresholds_to_the_same_confidence(): void
    {
        Queue::fake();
        $pageA = $this->createPage('Low Hide Threshold');
        $pageB = $this->createPage('High Hide Threshold');
        $this->updatePageSettings($pageA, ['auto_hide_threshold' => 0.85]);
        $this->updatePageSettings($pageB, ['auto_hide_threshold' => 0.95]);
        $commentA = $this->createComment($pageA, 'spam content A');
        $commentB = $this->createComment($pageB, 'spam content B');

        $finalA = $this->runAi($commentA, $this->classification('hide', 'spam', 0.92, 'medium', 'Unsolicited sales content.'));
        $finalB = $this->runAi($commentB, $this->classification('hide', 'spam', 0.92, 'medium', 'Unsolicited sales content.'));

        $this->assertSame('hide', $finalA->final_action);
        $this->assertSame('review', $finalB->final_action);
        $this->assertSame(0.85, $finalA->final_threshold_value);
        $this->assertSame(0.70, $finalB->final_threshold_value);
    }

    public function test_high_confidence_spam_recommends_hide_but_not_delete_by_default(): void
    {
        $page = $this->createPage();
        $comment = $this->createComment($page, 'Repeated spam');

        $final = $this->runAi($comment, $this->classification('hide', 'spam', 0.96, 'high', 'Repeated spam promotion.'));

        $this->assertSame('hide', $final->final_action);
        $this->assertFalse((bool) $final->moderationSettings?->allow_ai_delete);
        $this->assertSame('auto_hide_threshold', $final->final_threshold_name);
    }

    public function test_low_confidence_spam_stays_in_review(): void
    {
        $page = $this->createPage();
        $comment = $this->createComment($page, 'Possibly promotional text');

        $final = $this->runAi($comment, $this->classification('review', 'spam', 0.55, 'low', 'The intent is uncertain.'));

        $this->assertSame('review', $final->final_action);
        $this->assertSame('auto_review_threshold', $final->final_threshold_name);
        $this->assertSame(0.70, $final->final_threshold_value);
    }

    public function test_high_confidence_review_recommendation_is_not_escalated_to_hide_or_delete(): void
    {
        $page = $this->createPage();
        $this->updatePageSettings($page, ['allow_ai_delete' => true, 'allow_ai_hide' => true]);
        $comment = $this->createComment($page, 'Ambiguous promotional content');

        $final = $this->runAi($comment, $this->classification('review', 'spam', 0.99, 'high', 'The model requests human review.'));

        $this->assertSame('review', $final->ai_action);
        $this->assertSame('review', $final->final_action);
        $this->assertStringContainsString('confidence alone cannot escalate', $final->final_reason);
    }

    public function test_clean_comment_is_kept_when_confidence_meets_review_threshold(): void
    {
        $page = $this->createPage();
        $comment = $this->createComment($page, 'ধন্যবাদ, ভালো সার্ভিস।');

        $final = $this->runAi($comment, $this->classification('keep', 'clean', 0.95, 'low', 'A polite positive comment.'));

        $this->assertSame('keep', $final->final_action);
        $this->assertSame('clean', $final->final_category);
        $this->assertSame('ai', $final->final_method);
    }

    public function test_hide_recommendation_is_not_escalated_to_delete_even_when_delete_is_enabled(): void
    {
        $page = $this->createPage();
        $this->updatePageSettings($page, ['allow_ai_delete' => true]);
        $comment = $this->createComment($page, 'Direct threat text');

        $final = $this->runAi($comment, $this->classification('hide', 'threat', 0.99, 'critical', 'Credible threat of harm.'));

        $this->assertSame('hide', $final->final_action);
        $this->assertSame('auto_hide_threshold', $final->final_threshold_name);
    }

    public function test_scam_category_reaches_hide_threshold(): void
    {
        $page = $this->createPage();
        $comment = $this->createComment($page, 'Click this suspicious payment link');

        $final = $this->runAi($comment, $this->classification('hide', 'scam', 0.94, 'high', 'Requests payment through a suspicious link.'));

        $this->assertSame('hide', $final->final_action);
        $this->assertSame('scam', $final->final_category);
    }

    public function test_profanity_category_reaches_hide_threshold(): void
    {
        $page = $this->createPage();
        $comment = $this->createComment($page, 'fuck you');

        $final = $this->runAi($comment, $this->classification('hide', 'profanity', 0.91, 'high', 'Directed profanity.'));

        $this->assertSame('hide', $final->final_action);
        $this->assertSame('profanity', $final->final_category);
    }

    public function test_equal_auto_review_threshold_boundary_is_inclusive_for_clean_content(): void
    {
        $page = $this->createPage();
        $comment = $this->createComment($page, 'Looks fine');

        $final = $this->runAi($comment, $this->classification('keep', 'clean', 0.70, 'low', 'Clean content.'));

        $this->assertSame('keep', $final->final_action);
        $this->assertSame('auto_review_threshold', $final->final_threshold_name);
    }

    public function test_decision_explainer_resource_includes_manual_ai_threshold_and_final_fields(): void
    {
        $owner = User::factory()->create(['email' => self::ADMIN_EMAIL]);
        $page = $this->createPage(owner: $owner);
        $comment = $this->createComment($page, 'Spam-like promotion');
        $final = $this->runAi($comment, $this->classification('hide', 'spam', 0.93, 'medium', 'Unsolicited promotion.'));

        $this->actingAs($owner)->getJson('/api/facebook/comments?page_id='.$page->getKey())
            ->assertOk()
            ->assertJsonPath('data.0.decision_explanation.manual_match', false)
            ->assertJsonPath('data.0.decision_explanation.ai_category', 'spam')
            ->assertJsonPath('data.0.decision_explanation.threshold_name', 'auto_hide_threshold')
            ->assertJsonPath('data.0.decision_explanation.final_decision', 'hide')
            ->assertJsonPath('data.0.final_moderation.reason', 'AI recommended hide or delete and confidence met or exceeded the enabled hide threshold.')
            ->assertJsonPath('data.0.final_moderation.action', $final->final_action);
    }

    public function test_final_decision_creates_a_safe_moderation_log(): void
    {
        $page = $this->createPage();
        $comment = $this->createComment($page, 'High confidence spam');

        $final = $this->runAi($comment, $this->classification('hide', 'spam', 0.95, 'high', 'Unsolicited promotion.'));

        $log = ModerationLog::query()->where('comment_id', $comment->getKey())->latest('id')->firstOrFail();
        $this->assertSame('gemini', $log->source);
        $this->assertSame('hide', $log->final_result['action']);
        $this->assertSame('spam', $log->ai_result['category']);
        $this->assertSame('no_manual_match', $log->manual_result['status']);
        $this->assertGreaterThanOrEqual(0, $log->processing_time_ms);
        $this->assertArrayNotHasKey('message', $log->final_result);
        $this->assertNotSame(self::PAGE_TOKEN, $log->final_result['reason']);
        $this->assertSame('hide', $final->final_action);
    }

    public function test_manual_override_is_preserved_for_the_same_comment_on_later_moderation_passes(): void
    {
        Queue::fake();
        $page = $this->createPage();
        $comment = $this->createComment($page, 'Needs manual handling');
        $admin = User::factory()->create(['email' => self::ADMIN_EMAIL]);
        app(CommentModerationService::class)->overrideComment($comment, 'keep', $admin, 'Reviewed by admin');

        $result = app(CommentModerationService::class)->moderateComment($comment->fresh());

        $this->assertSame('keep', $result['action']);
        $this->assertTrue($comment->fresh()->manual_override);
        Queue::assertNotPushed(ModerateCommentWithGeminiJob::class);
    }

    private function runAi(FacebookComment $comment, array $classification): FacebookComment
    {
        Queue::fake();
        Http::fake(['*' => Http::response($this->providerResponse($classification), 200)]);

        $pending = app(CommentModerationService::class)->moderateComment($comment);
        $this->assertSame('pending', $pending['status']);
        $this->runQueuedJob($comment);

        return $comment->fresh(['page', 'post']);
    }

    private function runQueuedJob(FacebookComment $comment): void
    {
        (new ModerateCommentWithGeminiJob((int) $comment->getKey()))->handle(
            app(GeminiModerationService::class),
            app(AiModerationQueueService::class),
            app(AiModerationResultStore::class),
            app(CommentModerationService::class),
        );
    }

    /** @return array<string, mixed> */
    private function providerResponse(array $classification): array
    {
        return [
            'status' => 'completed',
            'steps' => [[
                'type' => 'model_output',
                'content' => [[
                    'type' => 'text',
                    'text' => json_encode($classification, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                ]],
            ]],
            'usage' => [
                'total_input_tokens' => 80,
                'total_output_tokens' => 25,
                'total_tokens' => 105,
            ],
        ];
    }

    /** @return array{decision: string, category: string, confidence: float, severity: string, reason: string} */
    private function classification(string $decision, string $category, float $confidence, string $severity, string $reason): array
    {
        return compact('decision', 'category', 'confidence', 'severity', 'reason');
    }

    private function createPage(string $name = 'Decision Test Page', ?User $owner = null): FacebookPage
    {
        $owner ??= User::factory()->create();

        return FacebookPage::query()->create([
            'user_id' => $owner->getKey(),
            'facebook_page_id' => 'decision-page-'.uniqid(),
            'page_name' => $name,
            'page_access_token' => self::PAGE_TOKEN,
            'is_active' => true,
            'ai_enabled' => true,
        ]);
    }

    private function createComment(FacebookPage $page, string $message): FacebookComment
    {
        return FacebookComment::query()->create([
            'page_id' => $page->getKey(),
            'facebook_page_id' => $page->facebook_page_id,
            'facebook_comment_id' => 'decision-comment-'.uniqid(),
            'message' => $message,
            'manual_moderation_status' => 'no_manual_match',
            'manual_action' => 'none',
        ]);
    }

    private function createRule(FacebookPage $page, string $pattern, string $action): ModerationRule
    {
        return ModerationRule::query()->create([
            'facebook_page_id' => $page->facebook_page_id,
            'name' => 'Test '.$pattern,
            'category' => 'scam',
            'rule_type' => 'keyword',
            'pattern' => $pattern,
            'action' => $action,
            'severity' => 'high',
            'priority' => 50,
            'is_active' => true,
        ]);
    }

    /** @param array<string, mixed> $attributes */
    private function updatePageSettings(FacebookPage $page, array $attributes): void
    {
        app(PageModerationSettingsService::class)->update($page, $attributes);
    }
}
