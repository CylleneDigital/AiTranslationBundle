<?php

declare(strict_types=1);

namespace CylleneDigital\AiTranslationBundle\Override;

use CylleneDigital\AiTranslationBundle\Entity\TranslationOverride;
use CylleneDigital\AiTranslationBundle\Exception\ExceptionInterface;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

/**
 * A key longer than the override table accepts ({@see TranslationOverride::MAX_KEY_LENGTH}).
 * Raised before the write so the caller gets the key and the limit, instead of a driver
 * exception on a column length nobody sees from the outside.
 */
#[Exclude]
final class TranslationKeyTooLongException extends \InvalidArgumentException implements ExceptionInterface
{
    public function __construct(public readonly string $key)
    {
        parent::__construct(\sprintf(
            'The translation key is %d characters long, the maximum is %d: "%s…".',
            mb_strlen($key),
            TranslationOverride::MAX_KEY_LENGTH,
            mb_substr($key, 0, 60),
        ));
    }
}
