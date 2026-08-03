<?php

declare(strict_types=1);

namespace CylleneDigital\AiTranslationBundle\Override;

/**
 * Default author source: anonymous. CLI runs and hosts without an authentication
 * layer fall back to this; audit columns stay null (or "system" for reviews).
 */
final class NullAuthorProvider implements AuthorProviderInterface
{
    public function getAuthorIdentifier(): ?string
    {
        return null;
    }
}
