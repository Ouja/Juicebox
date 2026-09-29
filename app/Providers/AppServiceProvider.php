<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
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
        Model::preventLazyLoading(! $this->app->isProduction());

        RateLimiter::for('auth', fn (Request $request) => Limit::perMinute(5)->by($request->ip()));
        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(60)->by((string) ($request->user()?->id ?? $request->ip())));
    }
}
