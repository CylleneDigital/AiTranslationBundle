<?php

declare(strict_types=1);

namespace CylleneDigital\AiTranslationBundle\Transfer;

use CylleneDigital\AiTranslationBundle\Exception\ExceptionInterface;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

/**
 * A whole import file is unusable: not UTF-8, no header row, too large, not parseable.
 * The message is meant for the person who supplied the file. A single broken entry is
 * no reason to throw: it is counted with the skipped ones.
 */
#[Exclude]
final class InvalidImportFileException extends \InvalidArgumentException implements ExceptionInterface
{
}
