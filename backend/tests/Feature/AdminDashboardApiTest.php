<?php

namespace Tests\Feature;

use App\Models\FacebookComment;
use App\Models\FacebookPage;
use App\Models\ModerationActionLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminDashboardApiTest extends TestCase
{
    use RefreshDatabase;

    private const ADMIN_EMAIL = 'dashboard-admin@example.test';

    private const PAGE_TOKEN = 'dashboard-page-token-never-return-this';

    protected function setUp(): void
    {
        parent::setUp();
        config(['moderation.admin_emails' => [self::ADMIN_EMAIL]]);
    }

    public function test_dashboard_access_requires_an_allowlisted_administrator(): void
    {
        $admin = User::factory()->create(['email' => self::ADMIN_EMAIL]);
        $regularUser = User::factory()->create();

        $this->actingAs($admin)->getJson('/api/moderation/access')
            ->assertOk()
            ->assertJsonPath('data.id', $admin->getKey())
            ->assertJsonPath('data.email', self::ADMIN_EMAIL);

        $this->actingAs($regularUser)->getJson('/api/moderation/access')
            ->assertForbidden()
            ->assertJsonPath('message', 'Administrator access is required.');
        $this->actingAs($regularUser)->getJson('/api/facebook/webhook/status')
            ->assertForbidden()
            ->assertJsonPath('message', 'Administrator access is required.');
    }

    public function test_dashboard_returns_aggregated_metrics_scoped_to_owned_pages(): void
    {
        $admin = User::factory()->create(['email' => self::ADMIN_EMAIL]);
        $otherUser = User::factory()->create();
        $page = $this->createPage($admin, 'dashboard-owned-page');
        $otherPage = $this->createPage($otherUser, 'dashboard-other-page');

        $clean = $this->createComment($page, 'dashboard-clean-comment', [
            'comment_created_at' => now(),
            'final_status' => 'completed',
            'final_action' => 'keep',
            'final_method' => 'ai',
            'final_category' => 'clean',
            'final_confidence' => 0.93,
            'final_decision_at' => now(),
            'ai_status' => 'completed',
            'manual_moderation_status' => 'matched',
            'facebook_action_state' => 'hidden',
        ]);
        $review = $this->createComment($page, 'dashboard-review-comment', [
            'comment_created_at' => now(),
            'final_status' => 'completed',
            'final_action' => 'review',
            'final_method' => 'manual',
            'final_category' => 'spam',
            'final_decision_at' => now(),
        ]);
        $this->createComment($otherPage, 'dashboard-private-comment', [
            'comment_created_at' => now(),
            'final_status' => 'completed',
            'final_action' => 'delete',
            'final_method' => 'ai',
            'final_category' => 'spam',
            'final_decision_at' => now(),
            'facebook_action_state' => 'deleted',
        ]);
        ModerationActionLog::query()->create([
            'comment_id' => $review->getKey(),
            'facebook_comment_id' => $review->facebook_comment_id,
            'action' => 'hide',
            'status' => 'failed',
            'attempt_count' => 1,
            'error_message' => 'Safe test failure',
        ]);

        $response = $this->actingAs($admin)->getJson('/api/moderation/dashboard');
        $response->assertOk()
            ->assertJsonPath('data.stats.connected_pages', 1)
            ->assertJsonPath('data.stats.comments_today', 2)
            ->assertJsonPath('data.stats.comments_this_week', 2)
            ->assertJsonPath('data.stats.clean_comments', 1)
            ->assertJsonPath('data.stats.manual_rule_matches', 1)
            ->assertJsonPath('data.stats.ai_moderated', 1)
            ->assertJsonPath('data.stats.review_required', 1)
            ->assertJsonPath('data.stats.hidden_comments', 1)
            ->assertJsonPath('data.stats.deleted_comments', 0)
            ->assertJsonPath('data.stats.failed_actions', 1)
            ->assertJsonCount(2, 'data.recent_activity')
            ->assertJsonMissingPath('data.recent_activity.2');

        $this->actingAs($admin)->getJson('/api/moderation/dashboard?page_id='.$otherPage->getKey())
            ->assertNotFound()
            ->assertExactJson(['message' => 'Page not found.']);
    }

    public function test_comment_date_filters_use_indexable_inclusive_day_ranges(): void
    {
        $admin = User::factory()->create(['email' => self::ADMIN_EMAIL]);
        $page = $this->createPage($admin, 'dashboard-date-filter-page');
        $this->createComment($page, 'dashboard-date-before', ['comment_created_at' => '2026-03-13 23:59:59']);
        $this->createComment($page, 'dashboard-date-start', ['comment_created_at' => '2026-03-14 00:00:00']);
        $this->createComment($page, 'dashboard-date-end', ['comment_created_at' => '2026-03-14 23:59:59']);
        $this->createComment($page, 'dashboard-date-after', ['comment_created_at' => '2026-03-15 00:00:00']);

        $response = $this->actingAs($admin)->getJson('/api/facebook/comments?from=2026-03-14&to=2026-03-14');

        $response->assertOk()
            ->assertJsonPath('meta.total', 2)
            ->assertJsonFragment(['facebook_comment_id' => 'dashboard-date-start'])
            ->assertJsonFragment(['facebook_comment_id' => 'dashboard-date-end']);
    }

    public function test_page_disconnect_clears_credentials_but_keeps_moderation_history(): void
    {
        $admin = User::factory()->create(['email' => self::ADMIN_EMAIL]);
        $page = $this->createPage($admin, 'dashboard-disconnect-page');
        $this->createComment($page, 'dashboard-retained-comment');

        $this->actingAs($admin)->deleteJson('/api/facebook/pages/'.$page->getKey())
            ->assertOk()
            ->assertJsonPath('data.token_status', 'disconnected')
            ->assertJsonPath('data.is_active', false)
            ->assertDontSee(self::PAGE_TOKEN);

        $page->refresh();
        $this->assertFalse((bool) $page->is_active);
        $this->assertSame('', $page->page_access_token);
        $this->assertDatabaseHas('facebook_comments', [
            'facebook_comment_id' => 'dashboard-retained-comment',
            'page_id' => $page->getKey(),
        ]);
    }

    public function test_comment_filters_are_paginated_and_page_access_is_enforced(): void
    {
        $admin = User::factory()->create(['email' => self::ADMIN_EMAIL]);
        $otherUser = User::factory()->create();
        $page = $this->createPage($admin, 'dashboard-filter-page');
        $otherPage = $this->createPage($otherUser, 'dashboard-filter-other-page');
        $this->createComment($page, 'dashboard-filter-review', [
            'final_status' => 'completed',
            'final_action' => 'review',
            'final_category' => 'spam',
            'final_method' => 'ai',
            'final_confidence' => 0.75,
        ]);
        $this->createComment($page, 'dashboard-filter-keep', [
            'final_status' => 'completed',
            'final_action' => 'keep',
            'final_category' => 'clean',
            'final_method' => 'manual',
        ]);
        $this->createComment($otherPage, 'dashboard-filter-private');

        $this->actingAs($admin)->getJson('/api/facebook/comments?decision=review&category=spam&min_confidence=0.7&per_page=10')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.facebook_comment_id', 'dashboard-filter-review');

        $this->actingAs($admin)->getJson('/api/facebook/comments?page_id='.$otherPage->getKey())
            ->assertNotFound()
            ->assertExactJson(['message' => 'Page not found.']);
    }

    private function createPage(User $owner, string $graphPageId): FacebookPage
    {
        return FacebookPage::query()->create([
            'user_id' => $owner->getKey(),
            'facebook_page_id' => $graphPageId,
            'page_name' => 'Dashboard test Page',
            'page_access_token' => self::PAGE_TOKEN,
            'is_active' => true,
        ]);
    }

    /** @param array<string, mixed> $overrides */
    private function createComment(FacebookPage $page, string $facebookCommentId, array $overrides = []): FacebookComment
    {
        return FacebookComment::query()->create(array_merge([
            'page_id' => $page->getKey(),
            'facebook_page_id' => $page->facebook_page_id,
            'facebook_comment_id' => $facebookCommentId,
            'message' => 'Dashboard test comment',
            'comment_created_at' => now(),
        ], $overrides));
    }
}
