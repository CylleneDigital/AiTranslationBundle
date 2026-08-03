<?php

declare(strict_types=1);

namespace CylleneDigital\AiTranslationBundle\Transfer;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

/**
 * Outcome of one override import: how many entries were applied (or would be, on a
 * dry run); how many were skipped — a malformed row, an unknown locale, catalogue or
 * scope, a key too long, a value breaking its catalogue's syntax — each named in
 * `$issues`; how many were ignored because they would not change anything (a value
 * equal to the entry's baseline or to its stored override, or empty — the untranslated
 * rows of a view snapshot); and how many stored overrides were removed because the
 * file sets the entry back to its baseline.
 */
#[Exclude]
final readonly class OverrideImportResult
{
    public function __construct(
        public int $imported,
        public int $skipped,
        public int $unchanged = 0,
        /** Stored overrides removed because the file sets the entry back to its baseline. */
        public int $reverted = 0,
        /** @var list<OverrideImportIssue> each skipped entry, where it is and why — `$skipped` of them */
        public array $issues = [],
    ) {
    }
}
