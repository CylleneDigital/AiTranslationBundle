<?php

declare(strict_types=1);

namespace CylleneDigital\AiTranslationBundle\Catalogue;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

/**
 * One catalogue of the host project's translations/ directory, identified by its
 * relative path without locale nor extension ("shop/Product/messages"). The identifier
 * is what gets persisted with the overrides; the three navigation levels are derived
 * from it:
 *
 *   translations/shop/Product/messages.fr.yaml      → category "shop", domain "Product"
 *   translations/shop/Product/Review/messages.fr.yaml → category "shop", domain "Product/Review"
 *   translations/shop/messages.fr.yaml              → category "shop", domain "default"
 *   translations/messages.fr.yaml                   → category "default", domain "default"
 */
#[Exclude]
final readonly class TranslationCatalogue
{
    /** Implicit category/domain of the files living above the 3-level convention. */
    public const string DEFAULT_SEGMENT = 'default';

    public function __construct(
        public string $identifier,
        public string $category,
        public string $domain,
        public string $type,
    ) {
    }

    /**
     * Rebuilds the navigation levels from a stored identifier ("shop/Product/messages")
     * — e.g. for an override whose catalogue no longer exists as files.
     */
    public static function fromIdentifier(string $identifier): self
    {
        $separator = strrpos($identifier, '/');

        if (false === $separator) {
            return self::fromRelativeDir('', $identifier);
        }

        return self::fromRelativeDir(substr($identifier, 0, $separator), substr($identifier, $separator + 1));
    }

    public static function fromRelativeDir(string $relativeDir, string $type): self
    {
        $segments = '' === $relativeDir ? [] : explode('/', $relativeDir);

        return new self(
            identifier: ('' === $relativeDir ? '' : $relativeDir.'/').$type,
            category: $segments[0] ?? self::DEFAULT_SEGMENT,
            domain: \count($segments) > 1 ? implode('/', \array_slice($segments, 1)) : self::DEFAULT_SEGMENT,
            type: $type,
        );
    }

    /**
     * Nested domains as a breadcrumb: "Product/Review" → "Product > Review". Unused by the
     * bundle itself: it is for the integration packages that list catalogues.
     */
    public function getDomainLabel(): string
    {
        return str_replace('/', ' > ', $this->domain);
    }
}
