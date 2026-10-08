<?php

namespace Tests\Feature;

use App\Exceptions\FacebookCommentActionException;
use App\Jobs\DeleteFacebookCommentJob;
use App\Jobs\HideFacebookCommentJob;
use App\Jobs\UnhideFacebookCommentJob;
use App\Models\FacebookComment;
use App\Models\FacebookPage;
use App\Models\ModerationActionLog;
use App\Models\User;
use App\Services\CommentModerationService;
use App\Services\FacebookCommentActionService;
use App\Services\PageModerationSettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class FacebookCommentActionServiceTest extends TestCase
{
    use RefreshDatabase;

    private const ADMIN_EMAIL = 'action-pipeline-admin@example.test';

    private const PAGE_TOKEN = 'encrypted-page-token-never-log-this';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'moderation.admin_emails' => [self::ADMIN_EMAIL],
            'moderation.test_mode' => false,
            'moderation.action_queue_connection' => 'database',
            'moderation.action_max_attempts' => 3,
            'queue.connections.database.driver' => 'database',
            'services.facebook.graph_version' => 'v26.0',
        ]);
        Queue::fake();
        Http::fake();
    }

    public function test_hide_uses_post_and_the_page_token_then_persists_success(): void
    {
        $page = $this->createPage();
        $comment = $this->createComment($page);
        Http::fakeSequence()
            ->push(['id' => $comment->facebook_comment_id], 200)
            ->push(['success' => true], 200);

        $queued = app(FacebookCommentActionService::class)->queueManualAction($comment, 'hide', $this->admin());
        app(FacebookCommentActionService::class)->execute($comment->getKey(), $queued['action_log_id'], 'hide', 1, 3);

        Http::assertSentCount(2);
        Http::assertSent(static fn (ClientRequest $request): bool => $request->method() === 'GET'
            && str_contains($request->url(), '/v26.0/'.$comment->facebook_comment_id)
            && $request->hasHeader('Authorization', 'Bearer '.self::PAGE_TOKEN));
        Http::assertSent(static fn (ClientRequest $request): bool => $request->method() === 'POST'
            && str_contains($request->url(), '/v26.0/'.$comment->facebook_comment_id)
            && $request->data()['is_hidden'] === true
            && $request->hasHeader('Authorization', 'Bearer '.self::PAGE_TOKEN));
        $this->assertSame('hidden', $comment->fresh()->facebook_action_state);
        $this->assertSame('completed', $comment->fresh()->action_status);
        $this->assertSame('completed', ModerationActionLog::query()->findOrFail($queued['action_log_id'])->status);
    }

    public function test_delete_verifies_comment_identity_then_uses_delete(): void
    {
        $page = $this->createPage();
        $comment = $this->createComment($page);
        Http::fakeSequence()
            ->push(['id' => $comment->facebook_comment_id], 200)
            ->push(['success' => true], 200);

        $queued = app(FacebookCommentActionService::class)->queueManualAction($comment, 'delete', $this->admin());
        app(FacebookCommentActionService::class)->execute($comment->getKey(), $queued['action_log_id'], 'delete', 1, 3);

        Http::assertSentCount(2);
        Http::assertSent(static fn (ClientRequest $request): bool => $request->method() === 'GET'
            && str_contains($request->url(), '/'.$comment->facebook_comment_id)
            && $request->hasHeader('Authorization', 'Bearer '.self::PAGE_TOKEN));
        Http::assertSent(static fn (ClientRequest $request): bool => $request->method() === 'DELETE'
            && str_contains($request->url(), '/'.$comment->facebook_comment_id)
            && $request->hasHeader('Authorization', 'Bearer '.self::PAGE_TOKEN));
        $this->assertSame('deleted', $comment->fresh()->facebook_action_state);
    }

    public function test_unhide_posts_is_hidden_false(): void
    {
        $page = $this->createPage();
        $comment = $this->createComment($page, ['facebook_action_state' => 'hidden']);
        Http::fakeSequence()
            ->push(['id' => $comment->facebook_comment_id], 200)
            ->push(['success' => true], 200);

        $queued = app(FacebookCommentActionService::class)->queueManualAction($comment, 'unhide', $this->admin());
        app(FacebookCommentActionService::class)->execute($comment->getKey(), $queued['action_log_id'], 'unhide', 1, 3);

        Http::assertSent(static fn (ClientRequest $request): bool => $request->method() === 'POST'
            && $request->data()['is_hidden'] === false);
        $this->assertSame('visible', $comment->fresh()->facebook_action_state);
    }

    public function test_invalid_token_fails_without_retrying(): void
    {
        $page = $this->createPage();
        $comment = $this->createComment($page);
        Http::fake(['*graph.facebook.com/*' => Http::response([
            'error' => ['message' => 'redacted token error', 'type' => 'OAuthException', 'code' => 190],
        ], 500)]);

        $queued = app(FacebookCommentActionService::class)->queueManualAction($comment, 'hide', $this->admin());
        app(FacebookCommentActionService::class)->execute($comment->getKey(), $queued['action_log_id'], 'hide', 1, 3);

        Http::assertSentCount(1);
        $log = ModerationActionLog::query()->findOrFail($queued['action_log_id']);
        $this->assertSame('failed', $log->status);
        $this->assertSame(1, $log->attempt_count);
        $this->assertSame(190, $log->meta_error_code);
        $this->assertStringNotContainsString(self::PAGE_TOKEN, (string) $log->error_message);
        $this->assertStringNotContainsString('redacted token error', (string) $log->error_message);
    }

    public function test_permission_denial_fails_without_retrying(): void
    {
        $page = $this->createPage();
        $comment = $this->createComment($page);
        Http::fake(['*graph.facebook.com/*' => Http::response([
            'error' => ['message' => 'permission denied', 'code' => 200],
        ], 500)]);

        $queued = app(FacebookCommentActionService::class)->queueManualAction($comment, 'hide', $this->admin());
        app(FacebookCommentActionService::class)->execute($comment->getKey(), $queued['action_log_id'], 'hide', 1, 3);

        Http::assertSentCount(1);
        $this->assertSame('failed', $comment->fresh()->action_status);
        $permissionLog = ModerationActionLog::query()->findOrFail($queued['action_log_id']);
        $this->assertSame(200, $permissionLog->meta_error_code);
        $this->assertSame(
            'Meta denied this action. Check pages_manage_engagement and the Page MODERATE task.',
            $permissionLog->error_message,
        );
    }

    public function test_temporary_meta_error_is_retried_with_a_finite_attempt_limit(): void
    {
        $page = $this->createPage();
        $comment = $this->createComment($page);
        Http::fakeSequence()
            ->push(['error' => ['message' => 'temporary', 'code' => 2, 'is_transient' => true]], 503)
            ->push(['id' => $comment->facebook_comment_id], 200)
            ->push(['success' => true], 200);

        $queued = app(FacebookCommentActionService::class)->queueManualAction($comment, 'hide', $this->admin());
        try {
            app(FacebookCommentActionService::class)->execute($comment->getKey(), $queued['action_log_id'], 'hide', 1, 3);
            $this->fail('The first transient attempt must be rethrown for Laravel to retry it.');
        } catch (FacebookCommentActionException $exception) {
            $this->assertTrue($exception->retryable);
        }
        $this->assertSame('pending', $comment->fresh()->action_status);
        app(FacebookCommentActionService::class)->execute($comment->getKey(), $queued['action_log_id'], 'hide', 2, 3);

        Http::assertSentCount(3);
        $log = ModerationActionLog::query()->findOrFail($queued['action_log_id']);
        $this->assertSame('completed', $log->status);
        $this->assertSame(2, $log->attempt_count);
    }

    public function test_non_retryable_action_rejection_is_recorded_as_failure(): void
    {
        $page = $this->createPage();
        $comment = $this->createComment($page);
        Http::fakeSequence()
            ->push(['id' => $comment->facebook_comment_id], 200)
            ->push(['error' => ['message' => 'Rejected by Meta', 'code' => 100]], 400);

        $queued = app(FacebookCommentActionService::class)->queueManualAction($comment, 'hide', $this->admin());
        app(FacebookCommentActionService::class)->execute($comment->getKey(), $queued['action_log_id'], 'hide', 1, 3);

        Http::assertSentCount(2);
        $this->assertSame('failed', $comment->fresh()->action_status);
        $this->assertSame('failed', ModerationActionLog::query()->findOrFail($queued['action_log_id'])->status);
    }

    public function test_duplicate_automatic_decision_does_not_dispatch_a_second_job(): void
    {
        $page = $this->createPage();
        $this->enableAutomaticActions($page, true, false);
        $comment = $this->createComment($page, [
            'final_status' => 'completed',
            'final_action' => 'hide',
            'final_category' => 'spam',
            'final_input_hash' => str_repeat('a', 64),
        ]);
        $service = app(FacebookCommentActionService::class);

        $first = $service->queueAutomaticFinalAction($comment);
        $second = $service->queueAutomaticFinalAction($comment->fresh());

        $this->assertSame('pending', $first['status']);
        $this->assertTrue($second['duplicate']);
        Queue::assertPushedTimes(HideFacebookCommentJob::class, 1);
        $this->assertSame(1, ModerationActionLog::query()->where('comment_id', $comment->getKey())->count());
    }

    public function test_final_hide_override_dispatches_through_the_automatic_queue_pipeline(): void
    {
        $page = $this->createPage();
        $this->enableAutomaticActions($page, true, false);
        $comment = $this->createComment($page);

        app(CommentModerationService::class)->overrideComment(
            $comment,
            'hide',
            $this->admin(),
            'Reviewed and confirmed spam.',
        );

        $this->assertSame('pending', $comment->fresh()->action_status);
        Queue::assertPushedTimes(HideFacebookCommentJob::class, 1);
        Http::assertNothingSent();
    }

    public function test_final_keep_override_prevents_automatic_hide_dispatch(): void
    {
        $page = $this->createPage();
        $this->enableAutomaticActions($page, true, false);
        $comment = $this->createComment($page, [
            'final_status' => 'completed',
            'final_action' => 'hide',
            'final_category' => 'spam',
        ]);
        $result = app(CommentModerationService::class)->overrideComment(
            $comment,
            'keep',
            $this->admin(),
            'Reviewed and approved manually.',
        );

        $this->assertSame('keep', $comment->fresh()->final_action);
        $this->assertTrue($comment->fresh()->manual_override);
        $this->assertSame('skipped', $comment->fresh()->action_status);
        $this->assertSame('skipped', ModerationActionLog::query()->latest('id')->firstOrFail()->status);
        $this->assertSame('keep', $result['action']);
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    public function test_keep_override_during_preflight_stops_a_queued_hide_before_post(): void
    {
        $page = $this->createPage();
        $this->enableAutomaticActions($page, true, false);
        $comment = $this->createComment($page, [
            'final_status' => 'completed',
            'final_action' => 'hide',
            'final_category' => 'spam',
        ]);
        $queued = app(FacebookCommentActionService::class)->queueAutomaticFinalAction($comment);
        Http::fake(static function (ClientRequest $request, array $options = []) use ($comment) {
            $comment->forceFill([
                'final_action' => 'keep',
                'manual_override' => true,
            ])->save();

            return Http::response(['id' => $comment->facebook_comment_id], 200);
        });

        app(FacebookCommentActionService::class)->execute($comment->getKey(), $queued['action_log_id'], 'hide', 1, 3);

        Http::assertSentCount(1);
        $this->assertSame('keep', $comment->fresh()->final_action);
        $this->assertSame('skipped', $comment->fresh()->action_status);
        $this->assertSame('skipped', ModerationActionLog::query()->findOrFail($queued['action_log_id'])->status);
    }

    public function test_test_mode_simulates_success_without_a_meta_request(): void
    {
        config(['moderation.test_mode' => true]);
        $page = $this->createPage(['page_access_token' => '']);
        $comment = $this->createComment($page);
        Http::fake();
        $queued = app(FacebookCommentActionService::class)->queueManualAction($comment, 'hide', $this->admin());

        app(FacebookCommentActionService::class)->execute($comment->getKey(), $queued['action_log_id'], 'hide', 1, 3);

        Http::assertNothingSent();
        $log = ModerationActionLog::query()->findOrFail($queued['action_log_id']);
        $this->assertTrue($log->is_test);
        $this->assertSame('completed', $log->status);
        $this->assertSame('hidden', $comment->fresh()->facebook_action_state);
        $this->assertStringContainsString('Simulated success', $log->response_message);
    }

    public function test_auto_execute_disabled_saves_decision_without_dispatching_a_job(): void
    {
        $page = $this->createPage();
        $this->enableAutomaticActions($page, false, false);
        $comment = $this->createComment($page, [
            'final_status' => 'completed',
            'final_action' => 'hide',
            'final_category' => 'spam',
        ]);

        $result = app(FacebookCommentActionService::class)->queueAutomaticFinalAction($comment);

        $this->assertSame('skipped', $result['status']);
        $this->assertSame('skipped', $comment->fresh()->action_status);
        $this->assertDatabaseHas('moderation_action_logs', [
            'comment_id' => $comment->getKey(),
            'action' => 'hide',
            'status' => 'skipped',
        ]);
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    public function test_manual_hide_delete_and_unhide_are_dispatched_as_queued_jobs(): void
    {
        $page = $this->createPage();
        $hide = $this->createComment($page);
        $unhide = $this->createComment($page, ['facebook_action_state' => 'hidden']);
        $delete = $this->createComment($page);
        $service = app(FacebookCommentActionService::class);

        $service->queueManualAction($hide, 'hide', $this->admin());
        $service->queueManualAction($unhide, 'unhide', $this->admin());
        $service->queueManualAction($delete, 'delete', $this->admin());

        Queue::assertPushedTimes(HideFacebookCommentJob::class, 1);
        Queue::assertPushedTimes(UnhideFacebookCommentJob::class, 1);
        Queue::assertPushedTimes(DeleteFacebookCommentJob::class, 1);
        Http::assertNothingSent();
    }

    public function test_action_logs_capture_safe_response_and_processing_metadata(): void
    {
        $page = $this->createPage();
        $comment = $this->createComment($page);
        Http::fakeSequence()
            ->push(['id' => $comment->facebook_comment_id], 200)
            ->push(['success' => true], 200);
        $queued = app(FacebookCommentActionService::class)->queueManualAction($comment, 'hide', $this->admin());

        app(FacebookCommentActionService::class)->execute($comment->getKey(), $queued['action_log_id'], 'hide', 1, 3);

        $log = ModerationActionLog::query()->findOrFail($queued['action_log_id']);
        $this->assertSame(200, $log->response_code);
        $this->assertSame('Meta confirmed that the Page comment is hidden.', $log->response_message);
        $this->assertGreaterThanOrEqual(0, $log->processing_time_ms);
        $this->assertNotNull($log->started_at);
        $this->assertNotNull($log->completed_at);
        $this->assertNull($log->error_message);
        $this->assertStringNotContainsString(self::PAGE_TOKEN, json_encode($log->toArray(), JSON_THROW_ON_ERROR));
        $response = $this->actingAs($this->admin())->getJson('/api/moderation/actions')->assertOk();
        $this->assertStringNotContainsString(self::PAGE_TOKEN, $response->getContent());
    }

    public function test_page_mismatch_is_rejected_before_a_job_or_graph_request(): void
    {
        $pageA = $this->createPage();
        $pageB = $this->createPage();
        $comment = $this->createComment($pageA, ['facebook_page_id' => $pageB->facebook_page_id]);
        Http::fake();

        try {
            app(FacebookCommentActionService::class)->queueManualAction($comment, 'hide', $this->admin());
            $this->fail('A comment stored under a different Facebook Page ID must be rejected.');
        } catch (FacebookCommentActionException $exception) {
            $this->assertSame('comment_page_mismatch', $exception->errorCategory);
        }

        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    public function test_customer_complaint_is_never_automatically_deleted(): void
    {
        $page = $this->createPage();
        $this->enableAutomaticActions($page, true, true);
        $comment = $this->createComment($page, [
            'final_status' => 'completed',
            'final_action' => 'delete',
            'final_category' => 'customer_complaint',
        ]);

        $result = app(FacebookCommentActionService::class)->queueAutomaticFinalAction($comment);

        $this->assertSame('skipped', $result['status']);
        $this->assertStringContainsString('Protected category', $result['reason']);
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    public function test_complaint_remains_available_for_explicit_admin_delete(): void
    {
        $page = $this->createPage();
        $this->enableAutomaticActions($page, true, true);
        $comment = $this->createComment($page, [
            'final_status' => 'completed',
            'final_action' => 'delete',
            'final_category' => 'negative_feedback',
        ]);
        $service = app(FacebookCommentActionService::class);
        $automatic = $service->queueAutomaticFinalAction($comment);
        $manual = $service->queueManualAction($comment->fresh(), 'delete', $this->admin());

        $this->assertSame('skipped', $automatic['status']);
        $this->assertSame('pending', $manual['status']);
        Queue::assertPushedTimes(DeleteFacebookCommentJob::class, 1);
        Http::assertNothingSent();
    }

    public function test_delete_treats_meta_comment_not_found_as_already_deleted(): void
    {
        $page = $this->createPage();
        $comment = $this->createComment($page);
        Http::fake(['*graph.facebook.com/*' => Http::response([
            'error' => ['message' => 'Object does not exist', 'code' => 100],
        ], 400)]);
        $queued = app(FacebookCommentActionService::class)->queueManualAction($comment, 'delete', $this->admin());

        app(FacebookCommentActionService::class)->execute($comment->getKey(), $queued['action_log_id'], 'delete', 1, 3);

        Http::assertSentCount(1);
        $this->assertSame('completed', $comment->fresh()->action_status);
        $this->assertSame('deleted', $comment->fresh()->facebook_action_state);
        $log = ModerationActionLog::query()->findOrFail($queued['action_log_id']);
        $this->assertSame(100, $log->meta_error_code);
        $this->assertSame('Comment is no longer available on Meta; deletion was not repeated.', $log->response_message);
    }

    public function test_duplicate_hide_is_skipped_when_local_state_already_records_hidden(): void
    {
        $page = $this->createPage();
        $comment = $this->createComment($page, ['facebook_action_state' => 'hidden']);
        Http::fake();

        $result = app(FacebookCommentActionService::class)->queueManualAction($comment, 'hide', $this->admin());

        $this->assertSame('skipped', $result['status']);
        $this->assertStringContainsString('already recorded as hidden', $result['reason']);
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    public function test_moderation_action_routes_are_admin_only(): void
    {
        $page = $this->createPage();
        $comment = $this->createComment($page);
        $nonAdmin = User::factory()->create();
        $admin = $this->admin();

        $this->actingAs($nonAdmin)->getJson('/api/moderation/actions')->assertForbidden();
        $this->actingAs($nonAdmin)->postJson('/api/moderation/comments/'.$comment->getKey().'/actions', [
            'action' => 'hide',
        ])->assertForbidden();
        $this->actingAs($admin)->getJson('/api/moderation/actions')->assertOk();
    }

    private function createPage(array $attributes = []): FacebookPage
    {
        return FacebookPage::query()->create(array_merge([
            'user_id' => User::factory()->create()->getKey(),
            'facebook_page_id' => 'action-page-'.uniqid(),
            'page_name' => 'Action Pipeline Page',
            'page_access_token' => self::PAGE_TOKEN,
            'is_active' => true,
            'ai_enabled' => true,
        ], $attributes));
    }

    private function createComment(FacebookPage $page, array $attributes = []): FacebookComment
    {
        return FacebookComment::query()->create(array_merge([
            'page_id' => $page->getKey(),
            'facebook_page_id' => $page->facebook_page_id,
            'facebook_comment_id' => 'action-comment-'.uniqid(),
            'message' => 'A moderation test comment',
            'manual_moderation_status' => 'no_manual_match',
            'manual_action' => 'none',
        ], $attributes));
    }

    private function admin(): User
    {
        return User::factory()->create(['email' => self::ADMIN_EMAIL]);
    }

    private function enableAutomaticActions(FacebookPage $page, bool $hide, bool $delete): void
    {
        app(PageModerationSettingsService::class)->update($page, [
            'auto_hide_enabled' => $hide,
            'auto_delete_enabled' => $delete,
            'auto_execute_actions' => true,
        ]);
    }
}
