<?php

declare(strict_types=1);

namespace CylleneDigital\AiTranslationBundle\Coverage;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

/**
 * Coverage of one target locale against the default locale: how many of its
 * translatable keys have a value (file or override) in this locale.
 */
#[Exclude]
final readonly class LocaleCoverage
{
    public function __construct(
        public string $locale,
        public int $translated,
        public int $missing,
        /** Pending AI suggestions for this locale (any key, awaiting review). */
        public int $pendingSuggestions,
        /**
         * The missing keys themselves, only collected on demand
         * ({@see CoverageCalculator::compute()} with $collectMissingKeys) — empty in the
         * counting-only reports, the cached ones included.
         *
         * @var array<string, list<string>> catalogue identifier => sorted keys
         */
        public array $missingKeys = [],
    ) {
    }

    public function getTotal(): int
    {
        return $this->translated + $this->missing;
    }

    /**
     * 0.0 to 100.0, rounded DOWN to one decimal — 2499 keys of 2500 show 99.9, never a
     * 100.0 the locale has not reached. An empty default locale counts as fully covered.
     */
    public function getPercent(): float
    {
        return floor($this->getExactPercent() * 10) / 10;
    }

    /** Whether the locale falls short of $min percent — compared unrounded, for a CI gate. */
    public function isBelow(float $min): bool
    {
        return $this->getExactPercent() < $min;
    }

    private function getExactPercent(): float
    {
        $total = $this->getTotal();

        return 0 === $total ? 100.0 : $this->translated * 100 / $total;
    }

    public function isComplete(): bool
    {
        return 0 === $this->missing;
    }
}
