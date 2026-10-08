<?php

use App\Http\Controllers\Api\AdminDashboardController;
use App\Http\Controllers\Api\AiModerationController;
use App\Http\Controllers\Api\CommentModerationController;
use App\Http\Controllers\Api\FacebookCommentController;
use App\Http\Controllers\Api\FacebookPageController;
use App\Http\Controllers\Api\FacebookPageSyncController;
use App\Http\Controllers\Api\FacebookWebhookController;
use App\Http\Controllers\Api\HealthController;
use App\Http\Controllers\Api\ModerationActionController;
use App\Http\Controllers\Api\ModerationRuleController;
use App\Http\Middleware\EnsureModerationAdmin;
use Illuminate\Support\Facades\Route;

Route::get('/health', HealthController::class)->name('api.health');

// Meta must reach the webhook endpoints without application login or browser CSRF.
Route::get('/facebook/webhook', [FacebookWebhookController::class, 'verify'])
    ->name('facebook.webhook.verify');
Route::post('/facebook/webhook', [FacebookWebhookController::class, 'receive'])
    ->name('facebook.webhook.receive');

// The React dashboard uses Laravel's existing web-session guard. Keep the API
// same-origin, start the session, and retain Laravel's CSRF middleware.
Route::middleware('web')->group(function (): void {
    Route::get('/facebook/webhook/status', [FacebookWebhookController::class, 'status'])
        ->middleware(['auth', EnsureModerationAdmin::class])
        ->name('facebook.webhook.status');

    Route::post('/facebook-pages/connect', [FacebookPageController::class, 'store'])
        ->middleware(['auth', 'throttle:facebook-connect'])
        ->name('facebook-pages.connect');

    Route::middleware('auth')->prefix('facebook')->group(function (): void {
        Route::get('/pages', [FacebookPageController::class, 'index'])->name('facebook.pages.index');
        Route::post('/pages/{page}/sync', [FacebookPageSyncController::class, 'store'])
            ->middleware('throttle:facebook-sync')
            ->name('facebook.pages.sync');
        Route::delete('/pages/{page}', [FacebookPageController::class, 'disconnect'])
            ->middleware(EnsureModerationAdmin::class)
            ->name('facebook.pages.disconnect');
        Route::get('/comments', [FacebookCommentController::class, 'index'])
            ->middleware(EnsureModerationAdmin::class)
            ->name('facebook.comments.index');
        Route::get('/comments/{comment}', [FacebookCommentController::class, 'show'])
            ->middleware(EnsureModerationAdmin::class)
            ->name('facebook.comments.show');
    });

    Route::middleware(['auth', EnsureModerationAdmin::class])->prefix('moderation')->group(function (): void {
        Route::get('/access', [AdminDashboardController::class, 'access'])->name('moderation.access');
        Route::get('/dashboard', [AdminDashboardController::class, 'index'])->name('moderation.dashboard');
        Route::get('/pages', [ModerationRuleController::class, 'pages'])->name('moderation.pages.index');
        Route::get('/rules', [ModerationRuleController::class, 'index'])->name('moderation.rules.index');
        Route::post('/rules', [ModerationRuleController::class, 'store'])->name('moderation.rules.store');
        Route::post('/rules/test', [ModerationRuleController::class, 'test'])
            ->middleware('throttle:moderation-test')
            ->name('moderation.rules.test');
        Route::get('/rules/{rule}', [ModerationRuleController::class, 'show'])->name('moderation.rules.show');
        Route::put('/rules/{rule}', [ModerationRuleController::class, 'update'])->name('moderation.rules.update');
        Route::patch('/rules/{rule}', [ModerationRuleController::class, 'update'])->name('moderation.rules.patch');
        Route::delete('/rules/{rule}', [ModerationRuleController::class, 'destroy'])->name('moderation.rules.destroy');

        Route::get('/ai/settings', [AiModerationController::class, 'settings'])->name('moderation.ai.settings');
        Route::patch('/ai/pages/{page}/settings', [AiModerationController::class, 'updatePageSettings'])
            ->name('moderation.ai.pages.settings');
        Route::post('/ai/test', [AiModerationController::class, 'test'])
            ->middleware('throttle:gemini-test')
            ->name('moderation.ai.test');
        Route::patch('/comments/{comment}/override', [CommentModerationController::class, 'override'])
            ->name('moderation.comments.override');
        Route::get('/actions', [ModerationActionController::class, 'index'])->name('moderation.actions.index');
        Route::post('/comments/{comment}/actions', [ModerationActionController::class, 'store'])
            ->middleware('throttle:moderation-action')
            ->name('moderation.comments.actions.store');
    });
});
