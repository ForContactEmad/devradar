<?php

declare(strict_types=1);

use DevRadar\Application\Extraction\ExtractionRunner;
use DevRadar\Application\Extraction\ProjectExtractor;
use DevRadar\Domain\Classification\LlmException;
use DevRadar\Domain\Classification\PromptBuilder;
use DevRadar\Domain\Extraction\CategoryRegistry;
use DevRadar\Domain\Extraction\ExtractionResponseParser;
use DevRadar\Domain\Extraction\ExtractionValidator;
use DevRadar\Domain\Extraction\TechnologyDetector;
use DevRadar\Domain\Extraction\UrlClassifier;
use DevRadar\Domain\Filtering\KeywordMatcher;
use DevRadar\Domain\Port\ExtractionCandidate;
use Tests\Fake\FakeBudgetGuard;
use Tests\Fake\InMemoryProjectRepository;
use Tests\Fake\MockLlmProvider;
use Tests\Fake\RecordingLogger;

function extractionValidator(): ExtractionValidator
{
    return new ExtractionValidator(
        categories: new CategoryRegistry(['ai' => [], 'developer-tools' => ['devtools'], 'other' => []]),
        urls: new UrlClassifier(),
        technologies: new TechnologyDetector(new KeywordMatcher(), techCatalog()),
        projectTypes: ['application', 'library', 'cli', 'service', 'other'],
        minimumConfidence: 0.6,
    );
}

/** @return array{0: ExtractionRunner, 1: InMemoryProjectRepository, 2: RecordingLogger} */
function extractionRunner(MockLlmProvider $provider, InMemoryProjectRepository $repo, ?FakeBudgetGuard $budget = null): array
{
    $logger = new RecordingLogger();

    $extractor = new ProjectExtractor(
        provider: $provider,
        prompts: new PromptBuilder('extract projects', 'x1'),
        parser: new ExtractionResponseParser(),
        validator: extractionValidator(),
        logger: $logger,
    );

    return [
        new ExtractionRunner($repo, $extractor, $budget ?? new FakeBudgetGuard(), $logger),
        $repo,
        $logger,
    ];
}

function candidateRepo(int $count = 1, array $urls = ['https://github.com/acme/pgplan']): InMemoryProjectRepository
{
    $repo = new InMemoryProjectRepository();

    for ($i = 1; $i <= $count; $i++) {
        $repo->pending[] = new ExtractionCandidate(
            tweetId: $i,
            text: 'Just launched Pgplan, a query plan viewer built with Laravel.',
            knownUrls: $urls,
            authorHandle: 'acmedev',
            sourcePostUrl: "https://x.com/acmedev/status/180000000000000000{$i}",
            postedAt: new DateTimeImmutable('2026-09-08 10:00:00', new DateTimeZone('UTC')),
        );
    }

    return $repo;
}

function extractionJson(array $overrides = []): string
{
    return json_encode(array_merge([
        'name' => 'Pgplan',
        'description' => 'A Postgres query plan viewer.',
        'category' => 'devtools',
        'project_type' => 'cli',
        'technologies' => ['Laravel'],
        'repository_url' => 'https://github.com/acme/pgplan',
        'confidence' => 0.9,
    ], $overrides), JSON_THROW_ON_ERROR);
}

it('publishes a valid extraction', function () {
    $provider = (new MockLlmProvider())->queueRaw(extractionJson());
    [$runner, $repo] = extractionRunner($provider, candidateRepo());

    $stats = $runner->run();

    expect($stats['published'])->toBe(1)
        ->and($repo->published[0]->name)->toBe('Pgplan')
        ->and($repo->published[0]->technologySlugs())->toBe(['laravel']);
});

it('records a rejection reason instead of publishing', function () {
    $provider = (new MockLlmProvider())->queueRaw(extractionJson(['name' => null]));
    [$runner, $repo] = extractionRunner($provider, candidateRepo());

    $stats = $runner->run();

    expect($stats['published'])->toBe(0)
        ->and($stats['rejected'])->toBe(1)
        ->and($repo->failures[1])->toBe('no-project-name')
        ->and($stats['reject_reasons']['no-project-name'])->toBe(1);
});

it('counts corrections so a drifting prompt is visible', function () {
    $provider = (new MockLlmProvider())->queueRaw(extractionJson([
        'category' => 'Quantum Blockchain',
        'technologies' => ['Laravel', 'Kubernetes'],
    ]));
    [$runner, , $logger] = extractionRunner($provider, candidateRepo());

    $stats = $runner->run();

    expect($stats['published'])->toBe(1)
        ->and($stats['corrected'])->toBe(1)
        ->and($logger->withMessage('extraction.corrections_applied'))->toHaveCount(1);
});

it('records a provider failure without losing the rest of the batch', function () {
    $provider = (new MockLlmProvider())
        ->queue(new LlmException('provider down', 'transient', 503))
        ->queueRaw(extractionJson());

    [$runner, $repo] = extractionRunner($provider, candidateRepo(2));
    $stats = $runner->run();

    expect($stats['rejected'])->toBe(1)
        ->and($stats['published'])->toBe(1)
        ->and($repo->failures[1])->toBe('provider-failure');
});

it('records an unparseable response', function () {
    $provider = (new MockLlmProvider())->queueRaw('I think it is called Pgplan?');
    [$runner, $repo] = extractionRunner($provider, candidateRepo());

    $runner->run();

    expect($repo->failures[1])->toBe('unparseable-response');
});

it('stops when the budget guard refuses', function () {
    $provider = (new MockLlmProvider())->queueRaw(extractionJson())->queueRaw(extractionJson());
    [$runner, $repo] = extractionRunner($provider, candidateRepo(5), new FakeBudgetGuard(allowance: 2));

    $stats = $runner->run();

    expect($stats['budget_halted'])->toBeTrue()
        ->and($provider->callCount())->toBe(2)
        ->and($repo->published)->toHaveCount(2);
});

it('reports a persistence failure loudly', function () {
    $repo = candidateRepo();
    $repo->failOnPublish = true;

    $provider = (new MockLlmProvider())->queueRaw(extractionJson());
    [$runner, , $logger] = extractionRunner($provider, $repo);

    $stats = $runner->run();

    // Two paid model calls have been spent on this post by now.
    expect($stats['failed'])->toBe(1)
        ->and($logger->withMessage('extraction.persistence_failed'))->toHaveCount(1);
});

it('does nothing on an empty queue', function () {
    $provider = new MockLlmProvider();
    [$runner] = extractionRunner($provider, new InMemoryProjectRepository());

    expect($runner->run()['claimed'])->toBe(0)
        ->and($provider->callCount())->toBe(0);
});

it('never lets a fabricated link reach the repository', function () {
    $provider = (new MockLlmProvider())->queueRaw(extractionJson([
        'repository_url' => 'https://github.com/totally/invented',
    ]));

    [$runner, $repo] = extractionRunner($provider, candidateRepo(1, ['https://pgplan.dev']));
    $runner->run();

    expect($repo->published[0]->repositoryUrl)->toBeNull()
        ->and($repo->published[0]->websiteUrl)->toBe('https://pgplan.dev');
});
