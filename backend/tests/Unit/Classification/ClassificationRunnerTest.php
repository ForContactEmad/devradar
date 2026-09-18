<?php

declare(strict_types=1);

use DevRadar\Application\Classification\AiClassifier;
use DevRadar\Application\Classification\ClassificationRunner;
use DevRadar\Domain\Classification\ClassificationOutcome;
use DevRadar\Domain\Classification\ClassificationRequest;
use DevRadar\Domain\Classification\ClassificationResponseParser;
use DevRadar\Domain\Classification\LlmException;
use DevRadar\Domain\Classification\PromptBuilder;
use Tests\Fake\FakeBudgetGuard;
use Tests\Fake\InMemoryClassificationRepository;
use Tests\Fake\MockLlmProvider;
use Tests\Fake\RecordingLogger;

/** @return array{0: ClassificationRunner, 1: InMemoryClassificationRepository, 2: RecordingLogger} */
function runner(
    MockLlmProvider $provider,
    InMemoryClassificationRepository $repo,
    ?FakeBudgetGuard $budget = null,
): array {
    $logger = new RecordingLogger();

    $classifier = new AiClassifier(
        provider: $provider,
        prompts: new PromptBuilder('sys', 'v1'),
        parser: new ClassificationResponseParser(),
        logger: $logger,
        minimumConfidence: 0.75,
    );

    return [
        new ClassificationRunner($repo, $classifier, $budget ?? new FakeBudgetGuard(), $logger),
        $repo,
        $logger,
    ];
}

function pending(int $count): InMemoryClassificationRepository
{
    $repo = new InMemoryClassificationRepository();

    for ($i = 1; $i <= $count; $i++) {
        $repo->pending[] = new ClassificationRequest($i, "post number {$i}");
    }

    return $repo;
}

it('classifies a batch and records every verdict', function () {
    $provider = (new MockLlmProvider())
        ->queueVerdict(true, true, 0.95)
        ->queueVerdict(false)
        ->queueVerdict(true, true, 0.4);

    [$runner, $repo] = runner($provider, pending(3));
    $stats = $runner->run();

    expect($stats['accepted'])->toBe(1)
        ->and($stats['rejected'])->toBe(1)
        ->and($stats['low_confidence'])->toBe(1)
        ->and($repo->saved)->toHaveCount(3);
});

it('sends one request per post', function () {
    $provider = (new MockLlmProvider())->queueVerdict(false)->queueVerdict(false);

    [$runner] = runner($provider, pending(2));
    $runner->run();

    // Batching several posts into one prompt risks misaligned verdicts, and
    // that failure is silent.
    expect($provider->callCount())->toBe(2);
});

it('does nothing on an empty queue', function () {
    $provider = new MockLlmProvider();
    [$runner] = runner($provider, pending(0));

    expect($runner->run()['claimed'])->toBe(0)
        ->and($provider->callCount())->toBe(0);
});

it('continues the batch when one post fails', function () {
    $provider = (new MockLlmProvider())
        ->queueVerdict(true, true, 0.95)
        ->queue(new LlmException('provider down', 'transient', 503))
        ->queueVerdict(false);

    [$runner, $repo] = runner($provider, pending(3));
    $stats = $runner->run();

    // One failure must not lose the other posts, which were also paid for.
    expect($stats['failed'])->toBe(1)
        ->and($stats['accepted'])->toBe(1)
        ->and($repo->saved)->toHaveCount(3);
});

it('tracks unparseable responses separately from failures', function () {
    $provider = (new MockLlmProvider())->queueRaw('not json at all')->queueVerdict(false);

    [$runner] = runner($provider, pending(2));
    $stats = $runner->run();

    // A rising unparseable rate is the earliest signal of model drift or a
    // prompt regression, and it looks nothing like a provider outage.
    expect($stats['unparseable'])->toBe(1)
        ->and($stats['failed'])->toBe(0);
});

it('stops when the budget guard refuses', function () {
    $provider = (new MockLlmProvider())->queueVerdict(false)->queueVerdict(false)->queueVerdict(false);
    $budget = new FakeBudgetGuard(allowance: 2);

    [$runner, $repo, $logger] = runner($provider, pending(5), $budget);
    $stats = $runner->run();

    // Model spend and post-retrieval spend share one ceiling.
    expect($stats['budget_halted'])->toBeTrue()
        ->and($provider->callCount())->toBe(2)
        ->and($repo->saved)->toHaveCount(2)
        ->and($logger->withMessage('classification.budget_halted'))->toHaveCount(1);
});

it('does not charge the budget for a post it failed to persist', function () {
    $repo = pending(1);
    $repo->failOnSave = true;

    $provider = (new MockLlmProvider())->queueVerdict(true, true, 0.9);
    [$runner, , $logger] = runner($provider, $repo);

    $stats = $runner->run();

    expect($stats['failed'])->toBe(1)
        ->and($logger->withMessage('classification.persistence_failed'))->toHaveCount(1);
});

it('reports the acceptance rate', function () {
    $provider = (new MockLlmProvider())
        ->queueVerdict(true, true, 0.9)->queueVerdict(false)
        ->queueVerdict(false)->queueVerdict(false);

    [$runner] = runner($provider, pending(4));

    expect($runner->run()['acceptance_rate'])->toBe(0.25);
});

it('aggregates cost across the batch', function () {
    $provider = (new MockLlmProvider())->queueVerdict(false)->queueVerdict(false);
    $repo = pending(2);

    $classifier = new AiClassifier(
        provider: $provider,
        prompts: new PromptBuilder('sys', 'v1'),
        parser: new ClassificationResponseParser(),
        logger: new RecordingLogger(),
        inputTokenPriceUsd: 0.00001,
        outputTokenPriceUsd: 0.00001,
    );

    $stats = (new ClassificationRunner($repo, $classifier, new FakeBudgetGuard(), new RecordingLogger()))->run();

    // 2 calls * (120 + 30) tokens * 0.00001
    expect($stats['cost_usd'])->toBe(0.003);
});
