<?php

namespace Tests\Feature;

use App\Models\FacebookComment;
use App\Models\FacebookPage;
use App\Models\FacebookPost;
use App\Models\ModerationRule;
use App\Models\User;
use App\Services\FacebookPageService;
use App\Services\ManualModerationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ManualModerationServiceTest extends TestCase
{
    use RefreshDatabase;

    private const ADMIN_EMAIL = 'moderator@example.test';
    private const PAGE_TOKEN = 'manual-moderation-test-page-token';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'moderation.admin_emails' => [self::ADMIN_EMAIL],
            'moderation.regex_backtrack_limit' => 10000,
            'moderation.regex_recursion_limit' => 1000,
            'moderation.max_comment_length' => 20000,
            'services.facebook.graph_version' => 'v26.0',
        ]);
    }

    public function test_exact_keyword_matches_and_keeps_whole_word_boundaries(): void
    {
        $this->createRule(['rule_type' => 'keyword', 'pattern' => 'fraud']);
        $service = app(ManualModerationService::class);

        $this->assertTrue($service->evaluateText('fraud')['matched']);
        $this->assertFalse($service->evaluateText('fraudulent')['matched']);
    }

    public function test_english_keyword_matching_is_case_insensitive_and_ignores_trailing_punctuation(): void
    {
        $this->createRule(['rule_type' => 'keyword', 'pattern' => 'stupid']);
        $result = app(ManualModerationService::class)->evaluateText('  STUPID!!!  ');

        $this->assertTrue($result['matched']);
        $this->assertSame('review', $result['action']);
        $this->assertSame('Matched keyword', $result['match_reason']);
    }

    public function test_bengali_keyword_matches_with_context(): void
    {
        $this->createRule(['rule_type' => 'keyword', 'pattern' => 'চোর']);
        $result = app(ManualModerationService::class)->evaluateText('আপনারা চোর');

        $this->assertTrue($result['matched']);
        $this->assertSame('keyword', $result['category']);
    }

    public function test_phrase_matches_multiple_words_with_extra_spaces_and_punctuation(): void
    {
        $this->createRule(['rule_type' => 'phrase', 'pattern' => 'ফালতু মাল', 'action' => 'hide']);
        $service = app(ManualModerationService::class);

        $this->assertSame('hide', $service->evaluateText('এই ফালতু,   মাল!!!')['action']);
        $this->assertFalse($service->evaluateText('ফালতু মালয়েশিয়া')['matched']);
    }

    public function test_phrase_normalizes_multiple_spaces_on_both_rule_and_comment(): void
    {
        $this->createRule(['rule_type' => 'phrase', 'pattern' => 'bad   product']);
        $result = app(ManualModerationService::class)->evaluateText('This is a BAD     PRODUCT.');

        $this->assertTrue($result['matched']);
        $this->assertSame('phrase', $result['rule_type']);
    }

    public function test_keyword_matches_when_common_punctuation_separates_the_word(): void
    {
        $this->createRule(['rule_type' => 'keyword', 'pattern' => 'chor']);
        $service = app(ManualModerationService::class);

        $this->assertTrue($service->evaluateText('CHOR!!!')['matched']);
        $this->assertTrue($service->evaluateText('c-h-o-r')['matched']);
        $this->assertFalse($service->evaluateText('choreography')['matched']);
    }

    public function test_url_rule_detects_common_urls_but_not_email_addresses(): void
    {
        $this->createRule(['rule_type' => 'url', 'pattern' => '*', 'action' => 'review']);
        $service = app(ManualModerationService::class);

        $this->assertSame('review', $service->evaluateText('Visit https://example.com/path?q=1')['action']);
        $this->assertTrue($service->evaluateText('Open www.example.com')['matched']);
        $this->assertTrue($service->evaluateText('Visit example.com.bd')['matched']);
        $this->assertFalse($service->evaluateText('Contact support@example.com')['matched']);
        $this->assertFalse($service->evaluateText('The product name is example.comedy')['matched']);
        $this->assertFalse($service->evaluateText('The file is report.jpg')['matched']);
    }

    public function test_phone_rule_detects_bangladesh_local_and_international_numbers_without_matching_random_digits(): void
    {
        $this->createRule(['rule_type' => 'phone', 'pattern' => '*', 'action' => 'review']);
        $service = app(ManualModerationService::class);

        $this->assertTrue($service->evaluateText('Call 01712345678')['matched']);
        $this->assertTrue($service->evaluateText('Call +880 17-1234-5678')['matched']);
        $this->assertTrue($service->evaluateText('Call +1 (212) 555-0199')['matched']);
        $this->assertFalse($service->evaluateText('Order number 123456789')['matched']);
    }

    public function test_regex_rule_matches_normalized_text(): void
    {
        $this->createRule(['rule_type' => 'regex', 'pattern' => '~\b(?:spam|scam)\b~iu']);
        $result = app(ManualModerationService::class)->evaluateText('This is a SCAM!!!');

        $this->assertTrue($result['matched']);
        $this->assertSame('regex', $result['rule_type']);
        $this->assertSame('Matched regex', $result['match_reason']);
    }

    public function test_invalid_regex_is_rejected_when_a_rule_is_saved(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->postJson('/api/moderation/rules', [
            'name' => 'Broken expression',
            'rule_type' => 'regex',
            'pattern' => '~(broken~iu',
            'action' => 'review',
            'severity' => 'medium',
            'priority' => 1,
        ])->assertUnprocessable()->assertJsonValidationErrors('pattern');

        $this->assertDatabaseCount('moderation_rules', 0);
    }

    public function test_unsafe_regex_backreferences_are_rejected(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->postJson('/api/moderation/rules', [
            'name' => 'Backreference expression',
            'rule_type' => 'regex',
            'pattern' => '~(spam)\\1~iu',
            'action' => 'review',
            'severity' => 'medium',
            'priority' => 1,
        ])->assertUnprocessable()->assertJsonValidationErrors('pattern');

        $this->assertDatabaseCount('moderation_rules', 0);
    }

    public function test_backtracking_heavy_regex_is_limited_and_fails_safely(): void
    {
        $this->createRule(['rule_type' => 'regex', 'pattern' => '~^(a+)+$~']);
        $comment = str_repeat('a', 1000).' b';

        $this->assertFalse(app(ManualModerationService::class)->evaluateText($comment)['matched']);
    }

    public function test_global_rule_applies_to_comments_from_any_connected_page(): void
    {
        $pageA = $this->createPage('global-test-page-a');
        $pageB = $this->createPage('global-test-page-b');
        $this->createRule(['pattern' => 'refund', 'facebook_page_id' => null]);
        $service = app(ManualModerationService::class);

        $this->assertTrue($service->evaluateText('refund requested', $pageA->facebook_page_id)['matched']);
        $this->assertTrue($service->evaluateText('refund requested', $pageB->facebook_page_id)['matched']);
    }

    public function test_page_specific_rule_does_not_apply_to_a_different_page(): void
    {
        $pageA = $this->createPage('specific-test-page-a');
        $pageB = $this->createPage('specific-test-page-b');
        $this->createRule(['pattern' => 'local issue', 'facebook_page_id' => $pageA->facebook_page_id]);
        $service = app(ManualModerationService::class);

        $this->assertTrue($service->evaluateText('local issue', $pageA->facebook_page_id)['matched']);
        $this->assertFalse($service->evaluateText('local issue', $pageB->facebook_page_id)['matched']);
    }

    public function test_higher_priority_wins_when_multiple_rules_match(): void
    {
        $this->createRule(['name' => 'Review refund', 'pattern' => 'refund', 'action' => 'review', 'priority' => 10]);
        $winner = $this->createRule(['name' => 'Hide scammer', 'pattern' => 'scammer', 'action' => 'hide', 'priority' => 100]);
        $result = app(ManualModerationService::class)->evaluateText('Refund from a scammer');

        $this->assertSame('hide', $result['action']);
        $this->assertSame($winner->getKey(), $result['rule_id']);
        $this->assertSame(100, $result['priority']);
    }

    public function test_equal_priority_conflict_uses_delete_then_hide_then_review_then_keep(): void
    {
        $this->createRule(['name' => 'Review spam', 'pattern' => 'spam', 'action' => 'review', 'priority' => 40]);
        $winner = $this->createRule(['name' => 'Delete spam recommendation', 'pattern' => 'spam', 'action' => 'delete', 'priority' => 40]);
        $result = app(ManualModerationService::class)->evaluateText('This is spam');

        $this->assertSame('delete', $result['action']);
        $this->assertSame($winner->getKey(), $result['rule_id']);
    }

    public function test_disabled_rule_is_not_evaluated(): void
    {
        $this->createRule(['pattern' => 'blocked phrase', 'is_active' => false]);
        $result = app(ManualModerationService::class)->evaluateText('blocked phrase');

        $this->assertFalse($result['matched']);
        $this->assertSame('none', $result['action']);
    }

    public function test_no_match_returns_the_documented_manual_result_shape(): void
    {
        $result = app(ManualModerationService::class)->evaluateText('An ordinary customer question.');

        $this->assertSame([
            'matched' => false,
            'action' => 'none',
            'method' => 'manual',
            'category' => null,
            'severity' => null,
            'confidence' => 0.0,
            'reason' => null,
            'match_reason' => null,
            'rule_id' => null,
            'rule_name' => null,
            'rule_type' => null,
            'priority' => null,
        ], $result);
    }

    public function test_rule_tester_is_read_only_and_returns_the_winning_rule(): void
    {
        $admin = $this->admin();
        $page = $this->createPage('tester-page');
        $rule = $this->createRule([
            'name' => 'Bangla abusive words',
            'category' => 'profanity',
            'pattern' => 'ফালতু',
            'rule_type' => 'keyword',
            'action' => 'hide',
            'facebook_page_id' => $page->facebook_page_id,
        ]);
        $comment = $this->createComment($page, 'ordinary text');

        $response = $this->actingAs($admin)->postJson('/api/moderation/rules/test', [
            'comment' => 'আপনাদের product একদম ফালতু',
            'facebook_page_id' => $page->facebook_page_id,
        ]);

        $response->assertOk()
            ->assertJsonPath('data.matched', true)
            ->assertJsonPath('data.action', 'hide')
            ->assertJsonPath('data.rule_name', 'Bangla abusive words')
            ->assertJsonPath('data.match_reason', 'Matched keyword');
        $this->assertSame('ordinary text', $comment->fresh()->message);
        $this->assertNull($comment->fresh()->manual_moderation_status);
        $this->assertSame('none', $comment->fresh()->manual_action);
        $this->assertSame($rule->getKey(), $response->json('data.rule_id'));
    }

    public function test_unicode_normalization_handles_bengali_elongation_and_spaced_or_hyphenated_text(): void
    {
        $this->createRule(['rule_type' => 'keyword', 'pattern' => 'চোর']);
        $service = app(ManualModerationService::class);

        $this->assertTrue($service->evaluateText('চোওর')['matched']);
        $this->assertTrue($service->evaluateText('চো-র')['matched']);

        $this->createRule(['rule_type' => 'keyword', 'pattern' => 'chor']);
        $this->assertTrue(app(ManualModerationService::class)->evaluateText('c h o r')['matched']);
    }

    public function test_keyword_does_not_match_innocent_words_that_only_contain_the_letters(): void
    {
        $this->createRule(['rule_type' => 'keyword', 'pattern' => 'ass']);
        $service = app(ManualModerationService::class);

        $this->assertFalse($service->evaluateText('The class is passing')['matched']);
        $this->assertFalse($service->evaluateText('The assistant arrived')['matched']);
        $this->assertTrue($service->evaluateText('You are an ass!')['matched']);
    }

    public function test_multiple_matching_rules_choose_the_explicit_priority_winner(): void
    {
        $this->createRule(['name' => 'Low priority keep', 'pattern' => 'bad', 'action' => 'keep', 'priority' => 1]);
        $winner = $this->createRule(['name' => 'High priority review', 'pattern' => 'bad', 'action' => 'review', 'priority' => 5]);
        $this->createRule(['name' => 'Low priority delete', 'pattern' => 'bad', 'action' => 'delete', 'priority' => 1]);
        $result = app(ManualModerationService::class)->evaluateText('bad');

        $this->assertSame($winner->getKey(), $result['rule_id']);
        $this->assertSame('review', $result['action']);
    }

    public function test_repeated_text_rule_detects_repeated_phrase_but_not_two_normal_repetitions(): void
    {
        $this->createRule(['rule_type' => 'repeated_text', 'pattern' => '3', 'action' => 'review']);
        $service = app(ManualModerationService::class);

        $this->assertTrue($service->evaluateText('buy now, buy now; buy now')['matched']);
        $this->assertFalse($service->evaluateText('buy now, buy now')['matched']);
    }

    public function test_webhook_comment_upsert_runs_and_persists_the_manual_decision(): void
    {
        $page = $this->createPage('pipeline-page');
        $rule = $this->createRule([
            'name' => 'Scam recommendation',
            'pattern' => 'scam',
            'action' => 'hide',
            'category' => 'fraud',
        ]);

        $comment = app(FacebookPageService::class)->upsertComment(
            $page,
            'pipeline-comment',
            'pipeline-post',
            [
                'id' => 'pipeline-comment',
                'message' => 'This is a SCAM!!!',
                'created_time' => '2026-10-08T10:00:00+0000',
            ],
        );

        $this->assertSame('matched', $comment->manual_moderation_status);
        $this->assertSame('hide', $comment->manual_action);
        $this->assertSame('fraud', $comment->manual_category);
        $this->assertSame($rule->getKey(), $comment->manual_rule_id);
        $this->assertNotNull($comment->manual_checked_at);
        $this->assertSame('Matched manual rule: Scam recommendation', $comment->manual_reason);
    }

    public function test_page_sync_comment_upsert_runs_and_persists_the_manual_decision(): void
    {
        $page = $this->createPage('sync-pipeline-page');
        $post = FacebookPost::query()->create([
            'page_id' => $page->getKey(),
            'facebook_page_id' => $page->facebook_page_id,
            'facebook_post_id' => 'sync-pipeline-post',
            'post_message' => 'A test post',
            'is_active' => true,
        ]);
        $rule = $this->createRule([
            'name' => 'Spam recommendation',
            'pattern' => 'spam',
            'action' => 'review',
        ]);
        Http::fake(['*' => Http::response([
            'data' => [[
                'id' => 'sync-pipeline-comment',
                'message' => 'This is SPAM!!!',
                'created_time' => '2026-10-08T10:00:00+0000',
            ]],
        ], 200)]);

        $this->assertSame(1, app(FacebookPageService::class)->syncPostComments($page, $post));

        $comment = FacebookComment::query()
            ->where('facebook_comment_id', 'sync-pipeline-comment')
            ->firstOrFail();
        $this->assertSame('matched', $comment->manual_moderation_status);
        $this->assertSame('review', $comment->manual_action);
        $this->assertSame($rule->getKey(), $comment->manual_rule_id);
    }

    public function test_authenticated_non_admin_cannot_view_or_manage_rules(): void
    {
        $this->getJson('/api/moderation/rules')->assertUnauthorized();
        $this->postJson('/api/moderation/rules/test', ['comment' => 'anything'])->assertUnauthorized();

        $regularUser = User::factory()->create(['email' => 'regular@example.test']);

        $this->actingAs($regularUser)
            ->getJson('/api/moderation/rules')
            ->assertForbidden();

        $this->actingAs($regularUser)
            ->postJson('/api/moderation/rules/test', ['comment' => 'anything'])
            ->assertForbidden();
    }

    public function test_admin_can_create_filter_update_disable_and_delete_rules(): void
    {
        $admin = $this->admin();
        $page = $this->createPage('crud-page');
        $this->actingAs($admin)->getJson('/api/moderation/pages')
            ->assertOk()
            ->assertJsonPath('data.0.facebook_page_id', $page->facebook_page_id)
            ->assertJsonMissingPath('data.0.page_access_token');

        $data = [
            'name' => 'Bangla abusive words',
            'category' => 'profanity',
            'rule_type' => 'keyword',
            'pattern' => 'চোর',
            'action' => 'hide',
            'severity' => 'high',
            'priority' => 100,
            'facebook_page_id' => $page->facebook_page_id,
            'is_active' => true,
        ];

        $created = $this->actingAs($admin)->postJson('/api/moderation/rules', $data)
            ->assertCreated()
            ->assertJsonPath('data.name', 'Bangla abusive words')
            ->assertJsonPath('data.page.page_name', 'Manual moderation page');
        $ruleId = $created->json('data.id');

        $this->getJson('/api/moderation/rules?search=abusive&action=hide&rule_type=keyword&sort_priority=desc')
            ->assertOk()
            ->assertJsonPath('data.0.id', $ruleId);

        $this->patchJson('/api/moderation/rules/'.$ruleId, ['is_active' => false])
            ->assertOk()
            ->assertJsonPath('data.is_active', false);
        $this->putJson('/api/moderation/rules/'.$ruleId, array_merge($data, ['name' => 'Updated word rule']))
            ->assertOk()
            ->assertJsonPath('data.name', 'Updated word rule');
        $this->deleteJson('/api/moderation/rules/'.$ruleId)->assertOk();
        $this->assertDatabaseMissing('moderation_rules', ['id' => $ruleId]);
    }

    private function createRule(array $attributes = []): ModerationRule
    {
        return ModerationRule::query()->create(array_merge([
            'facebook_page_id' => null,
            'name' => 'Test manual rule',
            'category' => null,
            'rule_type' => 'keyword',
            'pattern' => 'ordinary',
            'action' => 'review',
            'severity' => 'medium',
            'priority' => 0,
            'is_active' => true,
        ], $attributes));
    }

    private function createPage(string $facebookPageId): FacebookPage
    {
        return FacebookPage::query()->create([
            'user_id' => User::factory()->create()->getKey(),
            'facebook_page_id' => $facebookPageId,
            'page_name' => 'Manual moderation page',
            'page_access_token' => self::PAGE_TOKEN,
            'is_active' => true,
        ]);
    }

    private function createComment(FacebookPage $page, string $message): FacebookComment
    {
        return FacebookComment::query()->create([
            'page_id' => $page->getKey(),
            'facebook_page_id' => $page->facebook_page_id,
            'facebook_comment_id' => 'tester-comment-'.$page->getKey(),
            'message' => $message,
        ]);
    }

    private function admin(): User
    {
        return User::factory()->create(['email' => self::ADMIN_EMAIL]);
    }
}
