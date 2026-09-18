<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\HealthController;
use App\Http\Controllers\Api\V1\ProjectController;
use App\Http\Controllers\Api\V1\SearchRunController;
use App\Http\Controllers\Api\V1\StatisticsController;
use App\Http\Controllers\Api\V1\TrendsController;
use App\Http\Middleware\RequireAdminToken;
use App\Http\Controllers\Api\V1\TaxonomyController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API v1
|--------------------------------------------------------------------------
|
| VERSIONING IS IN THE URI, not a header. Header versioning is arguably purer
| REST, but a URI version is visible in a browser, in a log line, in a curl
| command someone pastes into an issue, and it is cacheable by any
| intermediary without Vary gymnastics. For an API whose consumers are mostly
| a dashboard and the occasional script, that legibility is worth more than
| the purity.
|
| ROUTE DESIGN. Trending, latest and search are NOT separate resources: they
| are orderings and filters of one collection, so they are query parameters
| on /projects. A /projects/trending endpoint would be a second URL for the
| same set of things, and consumers would then have to learn which filters
| worked on which one.
|
|   GET /projects?sort=trending
|   GET /projects?sort=latest
|   GET /projects?q=postgres&sort=relevance
|
| Everything on the public side is cacheable and free: the read path queries
| the serving tables and cannot reach a provider, a model, or the pipeline.
|
*/

/*
| Every public route is throttled.
|
| Previously nothing was: one client could run unbounded full-text searches,
| each of which is a real query against the database. Read endpoints are
| cheap and cacheable, so the general limit is generous; search and trends
| are tighter because each one does work a cache cannot always absorb.
*/
/*
| Health probes sit OUTSIDE the throttle group.
|
| An orchestrator polls these every few seconds. Inside the public throttle
| they would eventually be rate-limited into reporting a false outage, and the
| restart that followed would be entirely self-inflicted.
*/
Route::prefix('v1')->group(function () {
    Route::get('health', [HealthController::class, 'live'])->name('api.v1.health');
    Route::get('health/ready', [HealthController::class, 'ready'])->name('api.v1.health.ready');
});

Route::prefix('v1')->middleware('throttle:public')->group(function () {

    Route::get('projects', [ProjectController::class, 'index'])
        ->middleware('throttle:search')
        ->name('api.v1.projects.index');
    Route::get('projects/{slug}', [ProjectController::class, 'show'])
        ->where('slug', '[A-Za-z0-9\-]+')
        ->name('api.v1.projects.show');

    Route::get('categories', [TaxonomyController::class, 'categories'])->name('api.v1.categories');
    Route::get('technologies', [TaxonomyController::class, 'technologies'])->name('api.v1.technologies');

    Route::get('stats', [StatisticsController::class, 'show'])->name('api.v1.stats');

    /*
    | The full statistics report: headline trends, daily series, facet
    | movement and the top projects. One request rather than one per metric,
    | so the dashboard is not as slow as its slowest query.
    */
    Route::get('trends', [TrendsController::class, 'show'])->name('api.v1.trends');

    /*
    | Operational endpoints.
    |
    | Guarded by an implemented, fail-closed token check. The previous
    | `auth:sanctum` was a placeholder for a package that was never installed
    | and a guard that was never defined -- it denied requests only because
    | Laravel threw on the missing guard, which is protection by accident.
    |
    | Throttled harder than the public API: these are used by one operator, so
    | anything approaching a normal request rate is someone else.
    */
    Route::prefix('admin')
        ->middleware([RequireAdminToken::class, 'throttle:admin'])
        ->group(function () {
        Route::get('search-runs', [SearchRunController::class, 'index'])->name('api.v1.admin.search-runs');
        Route::get('health', [SearchRunController::class, 'health'])->name('api.v1.admin.health');
    });
});
