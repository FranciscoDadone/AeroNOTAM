<?php

namespace App\Providers;

use App\Contracts\WhatsappSender;
use App\Services\AerometService;
use App\Services\MetarService;
use App\Services\NoaaMetarSource;
use App\Services\NoaaTafSource;
use App\Services\OgimetAerometSource;
use App\Services\SmnMetarSource;
use App\Services\SmnTafSource;
use App\Services\TafService;
use App\Services\TwilioWhatsappSender;
use App\Support\AdminMetrics;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(WhatsappSender::class, TwilioWhatsappSender::class);

        // Order is the failover order, and it is deliberate: the SMN is the
        // authority for Argentine aerodromes, so it is asked first and NOAA
        // only relays the same reports when the SMN cannot be reached.
        $this->app->singleton(MetarService::class, fn ($app) => new MetarService([
            $app->make(SmnMetarSource::class),
            $app->make(NoaaMetarSource::class),
        ]));

        $this->app->singleton(TafService::class, fn ($app) => new TafService([
            $app->make(SmnTafSource::class),
            $app->make(NoaaTafSource::class),
        ]));

        // OGIMET only: AEROMET has no NOAA-relayed equivalent, and the SMN
        // itself, tried here first for a while, was dropped once it became
        // clear it was never going to answer (see OgimetAerometSource's own
        // docblock) — a source that cannot be reached was only adding a
        // wasted round of retries ahead of the one that can.
        $this->app->singleton(AerometService::class, fn ($app) => new AerometService([
            $app->make(OgimetAerometSource::class),
        ]));

        $this->app->bind(AdminMetrics::class, fn () => new AdminMetrics(
            (string) config('app.display_timezone'),
        ));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // In production nginx terminates TLS and proxies plain http to the
        // container, so generated URLs would come out as http:// and get
        // downgraded on the way back to the browser.
        if ($this->app->environment('production')) {
            URL::forceScheme('https');
        }

        // Every uncached NOTAM request can fan out into one paid LLM call per
        // NOTAM, so an unthrottled public endpoint is a trivial way for anyone
        // to burn through the OpenRouter budget. Keep the cap well below what
        // a legitimate client would ever need.
        RateLimiter::for('notams', fn (Request $request) => Limit::perMinute(60)->by($request->ip()));
    }
}
