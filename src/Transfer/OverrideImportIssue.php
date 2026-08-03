<?php

declare(strict_types=1);

namespace CylleneDigital\AiTranslationBundle\Transfer;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

/**
 * One entry of an imported file that was skipped, and why: where it is in the file
 * ("row 12" of a CSV — the row a spreadsheet shows —, a trans-unit or a <file> of an
 * XLIFF), its key when it has one, and the reason. A bare "3 invalid entry(ies)
 * skipped" left the person fixing a 500-line file searching by hand.
 */
#[Exclude]
final readonly class OverrideImportIssue
{
    public function __construct(
        public string $where,
        public ?string $key,
        public string $reason,
    ) {
    }

    /**
     * The first issues as lines, and how many more there are — what a console shows
     * without flooding the terminal on a file that is wrong throughout.
     *
     * @param list<self> $issues
     *
     * @return list<string>
     */
    public static function summarize(array $issues, int $max = 10): array
    {
        $lines = array_map(static fn (self $issue): string => (string) $issue, \array_slice($issues, 0, $max));

        if (\count($issues) > $max) {
            $lines[] = \sprintf('… and %d more.', \count($issues) - $max);
        }

        return $lines;
    }

    public function __toString(): string
    {
        return null !== $this->key && '' !== $this->key
            ? \sprintf('%s — %s: %s', $this->where, $this->key, $this->reason)
            : \sprintf('%s: %s', $this->where, $this->reason);
    }
}
