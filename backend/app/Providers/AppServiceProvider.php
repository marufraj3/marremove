<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $keyFor = static function (Request $request): string {
            $userId = $request->user()?->getAuthIdentifier();

            return $userId !== null ? 'user:'.$userId : 'ip:'.$request->ip();
        };

        RateLimiter::for('api', static fn (Request $request): Limit => Limit::perMinute(600)->by($keyFor($request)));
        RateLimiter::for('facebook-connect', static fn (Request $request): Limit => Limit::perMinute(10)->by($keyFor($request)));
        RateLimiter::for('facebook-sync', static fn (Request $request): Limit => Limit::perMinute(6)->by($keyFor($request)));
        RateLimiter::for('moderation-test', static fn (Request $request): Limit => Limit::perMinute(60)->by($keyFor($request)));
        RateLimiter::for('gemini-test', static fn (Request $request): Limit => Limit::perMinute(10)->by($keyFor($request)));
        RateLimiter::for('moderation-action', static fn (Request $request): Limit => Limit::perMinute(30)->by($keyFor($request)));
    }
}
