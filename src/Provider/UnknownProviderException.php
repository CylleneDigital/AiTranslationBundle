<?php

declare(strict_types=1);

namespace CylleneDigital\AiTranslationBundle\Provider;

use CylleneDigital\AiTranslationBundle\Exception\ExceptionInterface;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

/**
 * No provider answers to the name asked for — or none was asked for and there is no
 * single default to fall back to. The message lists the available providers.
 */
#[Exclude]
final class UnknownProviderException extends \InvalidArgumentException implements ExceptionInterface
{
}
