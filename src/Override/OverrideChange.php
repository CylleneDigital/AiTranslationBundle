<?php

declare(strict_types=1);

namespace CylleneDigital\AiTranslationBundle\Override;

/**
 * What writing a value for one entry actually calls for, given what it would show
 * without an override (its baseline) and the override stored for it.
 *
 * An override equal to its baseline changes nothing a visitor sees, but it shadows the
 * file: the next correction of the file would no longer show. And rewriting an override
 * with its own value moves its updated_at and dispatches OverrideSavedEvent — a purge, a
 * webhook — for nothing. The bundle's doors that write a chosen value (the console's
 * editor, an approval, an import) go through {@see decide()}; a host writing through
 * TranslationManagerInterface or OverrideWriter writes what it gives. Backed by a string: an approved
 * suggestion records the change its approval made in `metadata.override_change`.
 */
enum OverrideChange: string
{
    /** The value differs from both: store it. */
    case Write = 'write';

    /** The value is the baseline and an override is stored: remove it, back to the baseline. */
    case Revert = 'revert';

    /** The value is already what the entry shows: nothing to write. */
    case None = 'none';

    /**
     * @param string|null $baseline what the entry shows without its own override — the file value
     *                              for a global entry, the global override else the file value
     *                              for a scoped one ({@see OverrideReader::getBaselineValue()})
     * @param string|null $stored   the override stored for the entry itself, if any
     */
    public static function decide(string $value, ?string $baseline, ?string $stored): self
    {
        if (null !== $stored && $value === $stored) {
            return self::None;
        }

        if (null !== $baseline && $value === $baseline) {
            return null !== $stored ? self::Revert : self::None;
        }

        return self::Write;
    }
}
