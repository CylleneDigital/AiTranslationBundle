<?php

declare(strict_types=1);

namespace CylleneDigital\AiTranslationBundle\Coverage;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

/**
 * Translation coverage of every available locale against the default locale.
 */
#[Exclude]
final readonly class CoverageReport
{
    /**
     * @param list<LocaleCoverage> $locales every available locale except the default locale
     */
    public function __construct(
        public string $defaultLocale,
        public array $locales,
    ) {
    }

    public function getLocale(string $locale): ?LocaleCoverage
    {
        foreach ($this->locales as $coverage) {
            if ($coverage->locale === $locale) {
                return $coverage;
            }
        }

        return null;
    }
}
