<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        RateLimiter::for('museum-api', fn (Request $r) => Limit::perMinute(240)->by($r->user()?->id ?: $r->ip()));
        RateLimiter::for('museum-ask', fn (Request $r) => [Limit::perMinute(6)->by($r->ip()), Limit::perDay(200)->by($r->ip())]);
        RateLimiter::for('museum-submit', fn (Request $r) => [Limit::perMinute(3)->by($r->ip()), Limit::perDay(50)->by($r->ip())]);
    }
}
