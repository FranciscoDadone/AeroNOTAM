<?php

namespace App\Providers;

use App\Contracts\WhatsappSender;
use App\Services\TwilioWhatsappSender;
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
        $this->app->bind(WhatsappSender::class, TwilioWhatsappSender::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Every uncached NOTAM request can fan out into one paid LLM call per
        // NOTAM, so an unthrottled public endpoint is a trivial way for anyone
        // to burn through the OpenRouter budget. Keep the cap well below what
        // a legitimate client would ever need.
        RateLimiter::for('notams', fn (Request $request) => Limit::perMinute(60)->by($request->ip()));
    }
}
