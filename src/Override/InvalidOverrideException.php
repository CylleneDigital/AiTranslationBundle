<?php

declare(strict_types=1);

namespace CylleneDigital\AiTranslationBundle\Override;

use CylleneDigital\AiTranslationBundle\Exception\ExceptionInterface;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

/**
 * An override whose locale, catalogue or scope the tables cannot hold — or that no runtime
 * lookup would ever read (a malformed locale). Raised before anything is written: a driver
 * error at flush time would close the host's entity manager.
 */
#[Exclude]
final class InvalidOverrideException extends \InvalidArgumentException implements ExceptionInterface
{
}
