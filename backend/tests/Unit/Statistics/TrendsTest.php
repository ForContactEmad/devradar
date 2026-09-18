<?php

declare(strict_types=1);

use DevRadar\Application\Statistics\TrendsService;
use DevRadar\Domain\Statistics\RankedProject;
use DevRadar\Domain\Statistics\TimeSeries;
use DevRadar\Domain\Statistics\Trend;
use DevRadar\Domain\Statistics\TrendDirection;
use Tests\Fake\InMemoryStatisticsRepository;

function totals(int $projects, float $engagement = 0, float $score = 0, int $withRepo = 0): array
{
    return [
        'projects' => $projects,
        'engagement' => $engagement,
        'average_score' => $score,
        'with_repository' => $withRepo,
    ];
}

/** @return array{0: TrendsService, 1: InMemoryStatisticsRepository} */
function trendsService(int $minimumSample = 8): array
{
    $repo = new InMemoryStatisticsRepository();

    return [new TrendsService($repo, windowDays: 7, minimumSample: $minimumSample), $repo];
}

// ======================================================= TREND COMPARISON

it('reports a rise when the current period is clearly higher', function () {
    $trend = Trend::compare('projects', 47, 32, sampleSize: 47);

    expect($trend->direction)->toBe(TrendDirection::Rising)
        ->and($trend->changePercent)->toBe(46.9)
        ->and($trend->isReliable())->toBeTrue();
});

it('reports a fall when the current period is clearly lower', function () {
    expect(Trend::compare('projects', 20, 40, sampleSize: 40)->direction)->toBe(TrendDirection::Falling);
});

it('treats a small move as steady rather than a trend', function () {
    // Without a dead band the arrow flips on every rescore, which looks like
    // activity and carries no information.
    $trend = Trend::compare('projects', 41, 40, sampleSize: 41);

    expect($trend->direction)->toBe(TrendDirection::Steady)
        ->and($trend->changePercent)->toBe(2.5);
});

it('refuses to call a direction on a tiny sample', function () {
    // Two to six is a 200% rise by arithmetic and noise by any honest
    // reading. This is the guard that separates statistics from decoration.
    $trend = Trend::compare('projects', 6, 2, sampleSize: 6, minimumSample: 8);

    expect($trend->direction)->toBe(TrendDirection::Insufficient)
        ->and($trend->isReliable())->toBeFalse()
        ->and($trend->explanation)->toContain('too few');
});

it('still reports the numbers when the sample is too small', function () {
    // The figures are real even when the direction is not trustworthy;
    // hiding them would be a different kind of dishonesty.
    $trend = Trend::compare('projects', 6, 2, sampleSize: 6);

    expect($trend->current)->toBe(6.0)->and($trend->previous)->toBe(2.0);
});

it('returns null rather than infinity when growing from zero', function () {
    // "+∞%" is how dashboards announce their first observation.
    expect(Trend::changePercent(10, 0))->toBeNull()
        ->and(Trend::changePercent(0, 0))->toBeNull();
});

it('treats no activity in either period as steady, not a decline', function () {
    $trend = Trend::compare('projects', 0, 0, sampleSize: 20);

    expect($trend->direction)->toBe(TrendDirection::Steady)
        ->and($trend->explanation)->toContain('No activity');
});

it('reports a collapse to zero as a fall', function () {
    expect(Trend::compare('projects', 0, 30, sampleSize: 30)->direction)->toBe(TrendDirection::Falling);
});

// ============================================================ TIME SERIES

it('fills missing days with zero rather than omitting them', function () {
    $series = TimeSeries::dense(
        'projects_per_day',
        new DateTimeImmutable('2026-09-01'),
        new DateTimeImmutable('2026-09-05'),
        ['2026-09-01' => 3, '2026-09-04' => 5],
    );

    // A quiet Sunday is a real observation. Omitting it draws a chart whose
    // axis is unevenly spaced while looking perfectly even.
    expect($series->points)->toHaveCount(5)
        ->and($series->points[1]->value)->toBe(0.0)
        ->and($series->points[3]->value)->toBe(5.0);
});

it('covers the full range inclusive of both ends', function () {
    $series = TimeSeries::dense('x', new DateTimeImmutable('2026-09-01'), new DateTimeImmutable('2026-09-14'), []);

    expect($series->points)->toHaveCount(14)
        ->and($series->points[0]->date)->toBe('2026-09-01')
        ->and($series->points[13]->date)->toBe('2026-09-14');
});

it('handles a single-day range', function () {
    $day = new DateTimeImmutable('2026-09-10');

    expect(TimeSeries::dense('x', $day, $day, ['2026-09-10' => 2])->points)->toHaveCount(1);
});

it('reports an empty series without dividing by zero', function () {
    $series = TimeSeries::dense('x', new DateTimeImmutable('2026-09-01'), new DateTimeImmutable('2026-09-07'), []);

    expect($series->isEmpty())->toBeTrue()
        ->and($series->average())->toBe(0.0)
        ->and($series->total())->toBe(0.0)
        ->and($series->peak()->value)->toBe(0.0);
});

it('computes totals, averages and the peak day', function () {
    $series = TimeSeries::dense(
        'x',
        new DateTimeImmutable('2026-09-01'),
        new DateTimeImmutable('2026-09-04'),
        ['2026-09-01' => 2, '2026-09-02' => 8, '2026-09-03' => 4, '2026-09-04' => 2],
    );

    expect($series->total())->toBe(16.0)
        ->and($series->average())->toBe(4.0)
        ->and($series->peak()->date)->toBe('2026-09-02');
});

it('compares the head and tail of a series', function () {
    $series = TimeSeries::dense(
        'x',
        new DateTimeImmutable('2026-09-01'),
        new DateTimeImmutable('2026-09-04'),
        ['2026-09-01' => 2, '2026-09-02' => 2, '2026-09-03' => 6, '2026-09-04' => 6],
    );

    expect($series->headAverage(2))->toBe(2.0)->and($series->tailAverage(2))->toBe(6.0);
});

it('bounds a runaway range instead of allocating without limit', function () {
    $series = TimeSeries::dense('x', new DateTimeImmutable('2000-01-01'), new DateTimeImmutable('2030-01-01'), []);

    expect(count($series->points))->toBeLessThan(500);
});

it('handles a reversed range without looping', function () {
    $series = TimeSeries::dense('x', new DateTimeImmutable('2026-09-10'), new DateTimeImmutable('2026-09-01'), []);

    expect($series->points)->toHaveCount(0);
});

// ============================================================== THE REPORT

it('compares equal-length periods', function () {
    [$service, $repo] = trendsService();
    $repo->totalsByPeriod = ['current' => totals(20, 5000, 72.0, 14), 'previous' => totals(12, 3000, 68.0, 8)];

    $report = $service->report();
    $projects = $report->headlines[0];

    // Comparing a seven-day window against a fourteen-day one would show a
    // permanent decline and cost somebody a morning.
    expect($projects->current)->toBe(20.0)
        ->and($projects->previous)->toBe(12.0)
        ->and($projects->direction)->toBe(TrendDirection::Rising);
});

it('reports every headline metric', function () {
    [$service, $repo] = trendsService();
    $repo->totalsByPeriod = ['current' => totals(20, 5000, 72.0, 14), 'previous' => totals(12, 3000, 68.0, 8)];

    $metrics = array_map(fn ($t) => $t->metric, $service->report()->headlines);

    expect($metrics)->toBe([
        'projects_discovered', 'total_engagement', 'average_score', 'projects_with_repository',
    ]);
});

it('produces a usable report from an empty dataset', function () {
    [$service] = trendsService();
    $report = $service->report();

    // A new install has no data. It must render, not divide by zero.
    expect($report->headlines)->toHaveCount(4)
        ->and($report->headlines[0]->current)->toBe(0.0)
        ->and($report->projectsPerDay->isEmpty())->toBeTrue()
        ->and($report->topProjects)->toHaveCount(0)
        ->and($report->topCategories)->toHaveCount(0);
});

it('uses the larger period as the sample so a collapse is still reported', function () {
    [$service, $repo] = trendsService();
    // Busy week, then almost nothing. Sampling only the current period would
    // suppress exactly the signal most worth seeing.
    $repo->totalsByPeriod = ['current' => totals(1), 'previous' => totals(30)];

    $trend = $service->report()->headlines[0];

    expect($trend->direction)->toBe(TrendDirection::Falling)
        ->and($trend->isReliable())->toBeTrue();
});

it('omits the stars series when there is no history', function () {
    [$service, $repo] = trendsService();
    $repo->stars = [];

    // Absent is honest; a flat line at zero would be a lie about a
    // repository nobody has measured twice.
    expect($service->report()->repositoryStars)->toBeNull();
});

it('includes the stars series once history exists', function () {
    [$service, $repo] = trendsService();
    $repo->stars = ['2026-09-08' => 1200, '2026-09-09' => 1340];

    expect($service->report()->repositoryStars)->not->toBeNull()
        ->and($service->report()->repositoryStars->total())->toBeGreaterThan(0);
});

// ================================================================= FACETS

it('ranks facets by count and computes share of the window', function () {
    [$service, $repo] = trendsService();
    $repo->totalsByPeriod = ['current' => totals(20), 'previous' => totals(18)];
    $repo->taxonomy['category:current'] = [
        'ai' => ['name' => 'Ai', 'count' => 8, 'kind' => null],
        'cli' => ['name' => 'Cli', 'count' => 2, 'kind' => null],
        'saas' => ['name' => 'Saas', 'count' => 5, 'kind' => null],
    ];
    $repo->taxonomy['category:previous'] = [
        'ai' => ['name' => 'Ai', 'count' => 3, 'kind' => null],
    ];

    $categories = $service->report()->topCategories;

    expect($categories[0]->slug)->toBe('ai')
        ->and($categories[0]->count)->toBe(8)
        // Share is what makes a count comparable between a busy week and a
        // quiet one.
        ->and($categories[0]->share)->toBe(0.4)
        ->and($categories[0]->direction)->toBe(TrendDirection::Rising);
});

it('marks a facet absent last period as rising', function () {
    [$service, $repo] = trendsService();
    $repo->totalsByPeriod = ['current' => totals(10), 'previous' => totals(10)];
    $repo->taxonomy['category:current'] = ['rust' => ['name' => 'Rust', 'count' => 2, 'kind' => null]];
    $repo->taxonomy['category:previous'] = [];

    expect($service->report()->topCategories[0]->direction)->toBe(TrendDirection::Rising);
});

it('computes zero share when the window is empty', function () {
    [$service, $repo] = trendsService();
    $repo->totalsByPeriod = ['current' => totals(0), 'previous' => totals(0)];
    $repo->taxonomy['category:current'] = ['ai' => ['name' => 'Ai', 'count' => 0, 'kind' => null]];

    expect($service->report()->topCategories[0]->share)->toBe(0.0);
});

// ========================================================== LARGE DATASETS

it('caps the facet and project lists', function () {
    [$service, $repo] = trendsService();
    $repo->totalsByPeriod = ['current' => totals(5000), 'previous' => totals(4800)];

    $many = [];
    for ($i = 0; $i < 200; $i++) {
        $many["tech-{$i}"] = ['name' => "Tech {$i}", 'count' => 200 - $i, 'kind' => 'language'];
    }
    $repo->taxonomy['technology:current'] = $many;

    $top = [];
    for ($i = 0; $i < 500; $i++) {
        $top[] = new RankedProject("p{$i}", "P{$i}", 'ai', 100 - $i / 10, 500, 10, '2026-09-10T00:00:00Z');
    }
    $repo->top = $top;

    $report = $service->report();

    // The report is a summary. Returning every technology would make it a
    // second copy of the dataset.
    expect($report->topTechnologies)->toHaveCount(10)
        ->and($report->topProjects)->toHaveCount(10)
        ->and($report->topTechnologies[0]->count)->toBe(200);
});

it('handles large counts without losing precision in the change', function () {
    $trend = Trend::compare('engagement', 1_250_000, 1_000_000, sampleSize: 900);

    expect($trend->changePercent)->toBe(25.0)
        ->and($trend->direction)->toBe(TrendDirection::Rising);
});

// ============================================================ SERIALISATION

it('serialises a report for the API', function () {
    [$service, $repo] = trendsService();
    $repo->totalsByPeriod = ['current' => totals(20, 100, 70.0, 9), 'previous' => totals(10, 80, 65.0, 5)];

    $array = $service->report()->toArray();

    expect($array)->toHaveKey('headlines')
        ->and($array)->toHaveKey('projects_per_day')
        ->and($array)->toHaveKey('top_projects')
        ->and($array['headlines'][0])->toHaveKey('direction')
        ->and($array['headlines'][0])->toHaveKey('reliable')
        // The reader should be able to see why, not just what.
        ->and($array['headlines'][0])->toHaveKey('explanation');
});
