<?php

declare(strict_types=1);

namespace CylleneDigital\AiTranslationBundle\Message;

use CylleneDigital\AiTranslationBundle\Provider\TranslationProviderException;
use CylleneDigital\AiTranslationBundle\Suggestion\SuggestionGenerator;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;

#[AsMessageHandler]
final class GenerateSuggestionsMessageHandler
{
    public function __construct(
        private readonly SuggestionGenerator $generator,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(GenerateSuggestionsMessage $message): void
    {
        try {
            $outcome = $this->generator->generateSuggestions(
                $message->catalogue,
                $message->targetLocale,
                $message->sourceLocale,
                $message->provider,
                $message->onlyMissing,
                $message->scope,
                $message->retryErrors,
            );

            // Reporting a skipped run as a generation of 0 suggestions would claim the
            // work was done: another worker is doing it right now. Not an error — the
            // message is acknowledged, and re-dispatching later stays idempotent.
            if (!$outcome->completed) {
                $this->logger->info('AI translation generation skipped: another run already holds the lock.', [
                    'catalogue' => $message->catalogue,
                    'target_locale' => $message->targetLocale,
                    'scope' => $message->scope,
                ]);

                return;
            }

            $this->logger->info('AI translation suggestions generated.', [
                'catalogue' => $message->catalogue,
                'target_locale' => $message->targetLocale,
                'scope' => $message->scope,
                'created' => $outcome->created,
            ]);
        } catch (\Throwable $e) {
            // Never retried, whatever the transport's retry strategy: the provider may
            // already have been called and billed for this run, so a redelivery would
            // re-call it for the same keys and bill twice. (A run refused before it starts —
            // a locale, a catalogue or a scope the tables cannot hold — would fail the same
            // way again: no point retrying it either.) Re-dispatching later stays idempotent
            // (already-stored keys are pending and skipped), which is a deliberate human
            // decision, not something a worker should do on its own.
            //
            // The failure is still a failure though: wrapping it in
            // UnrecoverableMessageHandlingException sends the message to the failure
            // transport (messenger:failed:show) instead of swallowing it. A provider
            // error additionally left its trace per key — the unfulfilled keys are stored
            // as errored suggestions, surfaced with the other pending ones.
            $this->logger->error('AI translation generation failed — not retried to avoid re-billing the provider.', [
                'catalogue' => $message->catalogue,
                'target_locale' => $message->targetLocale,
                'scope' => $message->scope,
                'exception' => $e::class,
                'provider_failure' => $e instanceof TranslationProviderException,
                'error' => $e->getMessage(),
            ]);

            throw new UnrecoverableMessageHandlingException(\sprintf('AI translation generation failed for catalogue "%s" (%s): %s', $message->catalogue, $message->targetLocale, $e->getMessage()), previous: $e);
        }
    }
}
