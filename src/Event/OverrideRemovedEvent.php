<?php

declare(strict_types=1);

namespace CylleneDigital\AiTranslationBundle\Event;

use Symfony\Component\DependencyInjection\Attribute\Exclude;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * Dispatched after an override is removed (the catalogue value applies again).
 */
#[Exclude]
final class OverrideRemovedEvent extends Event
{
    public function __construct(
        public readonly string $key,
        public readonly string $catalogue,
        public readonly string $locale,
        public readonly string $scope = '',
    ) {
    }
}
