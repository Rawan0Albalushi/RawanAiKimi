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
        RateLimiter::for('chat-send', function (Request $request) {
            return Limit::perMinute(8)->by($request->ip() ?? 'local');
        });

        RateLimiter::for('chat-poll', function (Request $request) {
            return Limit::perMinute(45)->by($request->ip() ?? 'local');
        });
    }
}
