<?php

declare(strict_types=1);

namespace CylleneDigital\AiTranslationBundle\Message;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

/**
 * One generation unit: a single catalogue/locale pair, for one override scope ('' =
 * global). Dispatch one message per catalogue so a failing provider (quota, network)
 * only loses that catalogue. When the transport can be declared (see the bundle's
 * prepended Messenger config — symfony/doctrine-messenger for the default DSN), it is
 * routed to `cyllene_ai_translation`: run `messenger:consume cyllene_ai_translation` to
 * process it. Otherwise it is handled where it is dispatched. `$retryErrors` re-sends
 * the keys whose previous run failed, and only those, each the way that run would have
 * (their errored suggestions are refilled; one whose key the files have filled since is
 * closed instead); `$onlyMissing` does not apply to it.
 */
#[Exclude]
final readonly class GenerateSuggestionsMessage
{
    public function __construct(
        public string $catalogue,
        public string $targetLocale,
        public string $sourceLocale,
        public ?string $provider = null,
        public bool $onlyMissing = true,
        public string $scope = '',
        public bool $retryErrors = false,
    ) {
    }
}
