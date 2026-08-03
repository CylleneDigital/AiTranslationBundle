<?php

declare(strict_types=1);

namespace CylleneDigital\AiTranslationBundle\Override;

/**
 * Identifies who is editing translations — stamped on overrides (updated_by) and on
 * suggestion reviews (reviewed_by). The bundle default ({@see NullAuthorProvider})
 * leaves the author unknown; an integration package re-aliases this interface to its
 * authentication layer (e.g. the logged-in admin user).
 */
interface AuthorProviderInterface
{
    /** A stable identifier of the current editor (username, email…), or null when unknown. */
    public function getAuthorIdentifier(): ?string;
}
