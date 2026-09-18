<?php

declare(strict_types=1);

namespace DevRadar\Application\Extraction;

use DevRadar\Domain\Classification\LlmException;
use DevRadar\Domain\Classification\PromptBuilder;
use DevRadar\Domain\Classification\ClassificationRequest;
use DevRadar\Domain\Extraction\ExtractionResponseParser;
use DevRadar\Domain\Extraction\ExtractionValidator;
use DevRadar\Domain\Extraction\ValidationResult;
use DevRadar\Domain\Port\ExtractionCandidate;
use DevRadar\Domain\Port\LlmProviderInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Extracts structured project data from one classified post.
 *
 * A SECOND PASS, not an extension of classification. Two reasons:
 *
 *   Cost. Extraction runs only on posts already accepted as launches, which
 *   is a small fraction of what is classified. Asking every classified post
 *   for a full extraction would pay for structure on posts that are about to
 *   be discarded.
 *
 *   Separation. The classifier answers a yes/no question and its parser
 *   deliberately drops invented fields. Bolting extraction onto it would mean
 *   the component that must ignore hallucinated fields is also the component
 *   that must read them.
 *
 * Nothing throws out of here. Every failure becomes a ValidationResult with a
 * reason, because the post has already been paid for twice by this point.
 */
final readonly class ProjectExtractor
{
    public function __construct(
        private LlmProviderInterface $provider,
        private PromptBuilder $prompts,
        private ExtractionResponseParser $parser,
        private ExtractionValidator $validator,
        private LoggerInterface $logger,
    ) {}

    public function extract(ExtractionCandidate $candidate): ValidationResult
    {
        $request = new ClassificationRequest(
            tweetId: $candidate->tweetId,
            text: $candidate->text,
            knownUrls: $candidate->knownUrls,
            lang: $candidate->lang,
        );

        try {
            $response = $this->provider->complete($this->prompts->build($request));
        } catch (LlmException $e) {
            $this->logger->warning('extraction.provider_failed', [
                'tweet_id' => $candidate->tweetId,
                'error_class' => $e->errorClass,
                'error' => $e->getMessage(),
            ]);

            return ValidationResult::rejected('provider-failure');
        } catch (Throwable $e) {
            $this->logger->error('extraction.unexpected_failure', [
                'tweet_id' => $candidate->tweetId,
                'error' => $e->getMessage(),
            ]);

            return ValidationResult::rejected('provider-failure');
        }

        $parsed = $this->parser->parse($response->text);

        if ($parsed['ok'] === false) {
            $this->logger->warning('extraction.unparseable_response', [
                'tweet_id' => $candidate->tweetId,
                'error' => $parsed['error'],
                'response_head' => mb_substr($response->text, 0, 200),
            ]);

            return ValidationResult::rejected('unparseable-response');
        }

        $result = $this->validator->validate(
            tweetId: $candidate->tweetId,
            raw: $parsed['data'],
            postText: $candidate->text,
            postUrls: $candidate->knownUrls,
            authorHandle: $candidate->authorHandle,
            sourcePostUrl: $candidate->sourcePostUrl,
            publishedAt: $candidate->postedAt,
        );

        if ($result->hasCorrections()) {
            // A project published with silent corrections looks identical to
            // a clean one. Logging them is what surfaces a prompt that needs
            // work before the corrections become wrong answers.
            $this->logger->info('extraction.corrections_applied', [
                'tweet_id' => $candidate->tweetId,
                'corrections' => $result->corrections,
            ]);
        }

        return $result;
    }
}
