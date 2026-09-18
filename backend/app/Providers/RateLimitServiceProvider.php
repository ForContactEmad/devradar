<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

/**
 * Request rate limits.
 *
 * THERE WERE NONE. Every endpoint was unbounded, including search, which runs
 * a full-text query per call. One client could saturate the database or
 * scrape the entire dataset at whatever speed their connection allowed.
 *
 * Three tiers, because the endpoints cost different amounts:
 *
 *   public - cached, cheap reads. Generous: a person browsing the feed with
 *            a warm cache should never see a 429.
 *   search - a real query per request, and the cache cannot absorb arbitrary
 *            search terms. Tighter.
 *   admin  - one operator. Anything near a normal request rate is somebody
 *            else, so this is deliberately low.
 *
 * Keyed by IP. Behind a proxy this requires TrustProxies to be configured, or
 * every request keys to the proxy and the limit becomes global -- which is a
 * denial of service rather than a protection against one.
 */
final class RateLimitServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        RateLimiter::for('public', fn (Request $request) => Limit::perMinute(
            (int) config('devradar.rate_limits.public', 120),
        )->by($request->ip()));

        RateLimiter::for('search', fn (Request $request) => Limit::perMinute(
            (int) config('devradar.rate_limits.search', 30),
        )->by($request->ip()));

        RateLimiter::for('admin', fn (Request $request) => Limit::perMinute(
            (int) config('devradar.rate_limits.admin', 20),
        )->by($request->ip()));
    }
}
