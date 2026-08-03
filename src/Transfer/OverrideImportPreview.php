<?php

declare(strict_types=1);

namespace CylleneDigital\AiTranslationBundle\Transfer;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

/**
 * What an import WOULD do, without writing anything — what a caller shows before
 * asking for a confirmation: how many entries are importable, how many are skipped (the
 * same reasons as {@see OverrideImportResult}, each named in `$issues`), and how the
 * importable ones spread across locales.
 */
#[Exclude]
final readonly class OverrideImportPreview
{
    /**
     * @param array<string, int> $byLocale locale => importable entry count
     */
    public function __construct(
        public int $importable,
        public int $skipped,
        public array $byLocale,
        public int $unchanged = 0,
        /** Stored overrides the import would remove (entry set back to its baseline). */
        public int $reverted = 0,
        /** @var list<OverrideImportIssue> each skipped entry, where it is and why — `$skipped` of them */
        public array $issues = [],
    ) {
    }
}
