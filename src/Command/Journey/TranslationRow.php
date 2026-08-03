<?php

declare(strict_types=1);

namespace CylleneDigital\AiTranslationBundle\Command\Journey;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

/**
 * One line of the browsing table, whichever source it came from: a stored override or
 * a catalogue's file entry. The four coordinates of a translation, its current value,
 * and whether an override is what produces it — plus, when the keys were listed from a
 * reference locale, what that locale says: the two are read side by side.
 */
#[Exclude]
final readonly class TranslationRow
{
    public function __construct(
        public string $key,
        public string $catalogue,
        public string $locale,
        public string $scope,
        /** What that combination resolves to today: the override when there is one, the file value otherwise. */
        public ?string $value,
        public bool $hasOverride,
        /** The reference locale the key was listed from, null when the rows are stored overrides. */
        public ?string $sourceLocale = null,
        /** What that reference locale says for this key — the text a translation is made from. */
        public ?string $sourceValue = null,
    ) {
    }
}
