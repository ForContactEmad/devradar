<?php

declare(strict_types=1);

namespace DevRadar\Application\Classification;

use DevRadar\Domain\Classification\ClassificationOutcome;
use DevRadar\Domain\Classification\ClassificationRequest;
use DevRadar\Domain\Classification\ClassificationResponseParser;
use DevRadar\Domain\Classification\ClassificationResult;
use DevRadar\Domain\Classification\PromptBuilder;
use DevRadar\Domain\Port\LlmProviderInterface;
use DevRadar\Domain\Classification\LlmException;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Classifies one post: build, send, parse, judge.
 *
 * Owns WHAT is asked and how the answer is validated. It does not know which
 * provider is behind the port, does not perform HTTP, and does not retry --
 * retry is a decorator's job, because it is a transport concern that has
 * nothing to do with what a launch is.
 *
 * NOTHING THROWS OUT OF HERE. Every failure becomes a ClassificationResult
 * with an outcome. A post has already been paid for by the time it reaches
 * classification, so the caller needs a record of what happened to it, not an
 * exception that unwinds the batch and loses the other posts with it.
 *
 * LOW CONFIDENCE IS NOT REJECTION. The model said yes but was unsure. That is
 * the population you sample when deciding where the threshold belongs, and
 * collapsing it into "rejected" destroys the only evidence about whether the
 * threshold is right.
 */
final readonly class AiClassifier
{
    public function __construct(
        private LlmProviderInterface $provider,
        private PromptBuilder $prompts,
        private ClassificationResponseParser $parser,
        private LoggerInterface $logger,
        /**
         * Below this, a positive verdict is held back.
         *
         * Precision is preferred over recall HERE, unlike in the pre-filter:
         * a missed project is invisible, but a junk project on the front page
         * is what users judge the product on.
         */
        private float $minimumConfidence = 0.75,
        private float $inputTokenPriceUsd = 0.0,
        private float $outputTokenPriceUsd = 0.0,
    ) {}

    public function classify(ClassificationRequest $request): ClassificationResult
    {
        $identity = $this->provider->identity()->withPromptVersion($this->prompts->promptVersion());

        try {
            $response = $this->provider->complete($this->prompts->build($request));
        } catch (LlmException $e) {
            $this->logger->warning('classification.provider_failed', [
                'tweet_id' => $request->tweetId,
                'model' => $identity->label(),
                'error_class' => $e->errorClass,
                'error' => $e->getMessage(),
            ]);

            return ClassificationResult::failed($request->tweetId, $identity, $e->getMessage());
        } catch (Throwable $e) {
            $this->logger->error('classification.unexpected_failure', [
                'tweet_id' => $request->tweetId,
                'error' => $e->getMessage(),
            ]);

            return ClassificationResult::failed($request->tweetId, $identity, $e->getMessage());
        }

        $cost = ($response->inputTokens * $this->inputTokenPriceUsd)
            + ($response->outputTokens * $this->outputTokenPriceUsd);

        $parsed = $this->parser->parse($response->text);

        if ($parsed['ok'] === false) {
            // The call was paid for regardless, so the cost is still recorded.
            $this->logger->warning('classification.unparseable_response', [
                'tweet_id' => $request->tweetId,
                'model' => $identity->label(),
                'error' => $parsed['error'],
                // Bounded, and only the head: model output can be long, and a
                // post's own text may be echoed back inside it.
                'response_head' => mb_substr($response->text, 0, 200),
            ]);

            return new ClassificationResult(
                tweetId: $request->tweetId,
                outcome: ClassificationOutcome::Unparseable,
                isProject: false,
                isNew: null,
                confidence: null,
                reason: null,
                identity: $identity,
                inputTokens: $response->inputTokens,
                outputTokens: $response->outputTokens,
                costUsd: $cost,
                errorMessage: $parsed['error'],
            );
        }

        $data = $parsed['data'];
        $outcome = $this->outcomeFor($data);

        return new ClassificationResult(
            tweetId: $request->tweetId,
            outcome: $outcome,
            isProject: (bool) $data['is_project'],
            isNew: $data['is_new'],
            confidence: $data['confidence'],
            reason: $data['reason'],
            identity: $identity,
            inputTokens: $response->inputTokens,
            outputTokens: $response->outputTokens,
            costUsd: $response->fromCache ? 0.0 : $cost,
            rawResponse: $data,
        );
    }

    /** @param array<string, mixed> $data */
    private function outcomeFor(array $data): ClassificationOutcome
    {
        if ($data['is_project'] !== true) {
            return ClassificationOutcome::Rejected;
        }

        // A project announced long ago is a real project but not news, and
        // DevRadar is a seven-day product.
        if ($data['is_new'] !== true) {
            return ClassificationOutcome::Rejected;
        }

        $confidence = $data['confidence'];

        if ($confidence === null || $confidence < $this->minimumConfidence) {
            return ClassificationOutcome::LowConfidence;
        }

        return ClassificationOutcome::Accepted;
    }
}
