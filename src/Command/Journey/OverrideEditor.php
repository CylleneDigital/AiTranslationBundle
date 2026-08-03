<?php

declare(strict_types=1);

namespace CylleneDigital\AiTranslationBundle\Command\Journey;

use CylleneDigital\AiTranslationBundle\Catalogue\CatalogueRegistry;
use CylleneDigital\AiTranslationBundle\Override\OverrideChange;
use CylleneDigital\AiTranslationBundle\Override\OverrideReader;
use CylleneDigital\AiTranslationBundle\Override\OverrideWriter;
use CylleneDigital\AiTranslationBundle\Override\TranslationValueValidator;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * The action surface of one translation, on the exact (key, catalogue, locale, scope)
 * combination: edit or remove when an override exists there, write the first one
 * otherwise. Everything that precedes — finding that combination, by key or by
 * browsing — belongs to the journeys; this is what they both end in.
 */
final class OverrideEditor
{
    private const string ACTION_EDIT = 'Edit the value';
    private const string ACTION_REMOVE = 'Remove it';
    private const string ACTION_NOTHING = 'Nothing';
    private const string ACTION_QUIT = 'Quit';

    public function __construct(
        private readonly CatalogueRegistry $catalogues,
        private readonly OverrideReader $overrides,
        private readonly OverrideWriter $writer,
        private readonly TranslationValueValidator $valueValidator,
        private readonly ValueBlock $valueBlock,
        private readonly ValuePrompt $valuePrompt,
    ) {
    }

    /**
     * @param bool $recap     whether the cascade must be displayed here — false when the
     *                        caller already put that exact combination on screen
     * @param bool $quittable adds a "Quit" action, for a caller walking a batch
     */
    public function edit(SymfonyStyle $io, string $key, string $catalogue, string $locale, string $scope, bool $recap = true, bool $quittable = false): OverrideEditOutcome
    {
        $originalValue = $this->catalogues->getOriginalValue($key, $catalogue, $locale);
        $currentValue = $this->overrides->getOverrideValue($key, $catalogue, $locale, $scope);

        if ($recap) {
            $this->valueBlock->render($io, 'File value', $originalValue, missing: '(missing — no value for this locale)');
        }

        // A single key without override: the user came to give it a value, a menu first
        // would only be in the way. Walking a batch, the row needs the same ways past it
        // and out of it as the others: the value prompt used to be the only answer, so a
        // "Nothing" typed there became the translation.
        if (null === $currentValue && !$quittable) {
            return $this->saveOverride($io, $key, $catalogue, $locale, $scope, $originalValue, null);
        }

        if ($recap && null !== $currentValue) {
            $this->valueBlock->render($io, 'Current override', $currentValue);
        }

        $actions = array_values(array_filter([
            self::ACTION_EDIT,
            null !== $currentValue ? self::ACTION_REMOVE : null,
            self::ACTION_NOTHING,
            $quittable ? self::ACTION_QUIT : null,
        ]));

        $action = $io->choice('What do you want to do?', $actions, self::ACTION_EDIT);

        return match ($action) {
            self::ACTION_REMOVE => $this->removeOverride($io, $key, $catalogue, $locale, $scope),
            self::ACTION_NOTHING => $this->nothing($io),
            self::ACTION_QUIT => OverrideEditOutcome::Quit,
            default => $this->saveOverride($io, $key, $catalogue, $locale, $scope, $originalValue, $currentValue),
        };
    }

    private function saveOverride(SymfonyStyle $io, string $key, string $catalogue, string $locale, string $scope, ?string $originalValue, ?string $previousValue): OverrideEditOutcome
    {
        // What "the current one" is: the entry's own override, else what it shows without
        // one — in a scope, the inherited global override, which the file value used to be
        // written over.
        $fallback = $previousValue ?? $this->overrides->getBaselineValue($key, $catalogue, $locale, $scope);

        $value = $this->valuePrompt->ask($io, 'New value', $fallback);

        return $this->applyValue($io, $key, $catalogue, $locale, $scope, $originalValue, $previousValue, $value);
    }

    private function applyValue(SymfonyStyle $io, string $key, string $catalogue, string $locale, string $scope, ?string $originalValue, ?string $previousValue, string $value): OverrideEditOutcome
    {
        // The syntax refusal is a question here, not a --force flag: the issue is on
        // screen, saving anyway is a deliberate yes.
        $issues = $this->valueValidator->validate($value, $key, $catalogue, $locale);

        if ([] !== $issues) {
            $io->warning(\sprintf('The value does not match the catalogue syntax (%s).', implode(', ', $issues)));

            if (!$io->confirm('Save it anyway?', false)) {
                $io->note('Nothing saved.');

                return OverrideEditOutcome::Untouched;
            }
        }

        // A plain enter on a key without override used to store a copy of the file value,
        // which then shadowed every later correction of the file.
        // Inherited when it is not the file's: a scope's global override, or the parent
        // language's for a regional locale.
        $inherited = $this->overrides->getInheritedValue($key, $catalogue, $locale, $scope);
        $baseline = $inherited ?? $originalValue;
        $baselineName = null !== $inherited ? 'the inherited value' : 'the file value';

        switch (OverrideChange::decide($value, $baseline, $previousValue)) {
            case OverrideChange::None:
                $io->note($value === $previousValue ? 'Same as the current override — nothing saved.' : \sprintf('Same as %s — nothing saved.', $baselineName));

                return OverrideEditOutcome::Untouched;

            case OverrideChange::Revert:
                $this->writer->remove($key, $catalogue, $locale, $scope);
                $io->success(\sprintf('Same as %s — override removed for "%s" (%s, %s).', $baselineName, $key, $catalogue, $locale));

                return OverrideEditOutcome::Removed;

            case OverrideChange::Write:
                break;
        }

        $this->writer->save($key, $catalogue, $locale, $value, $originalValue, $scope);

        // The file value and previous override were on screen before the prompt —
        // in the landscape, or in the recap — only the new value needs a block here.
        $this->valueBlock->render($io, 'New value', $value);

        if ('' !== $scope) {
            $io->text(\sprintf('Scope: %s', $scope));
        }

        $io->success(\sprintf(
            '%s override %s for "%s" (%s, %s) — caches invalidated.',
            '' === $scope ? 'Global' : 'Scoped',
            null !== $previousValue ? 'updated' : 'created',
            $key,
            $catalogue,
            $locale,
        ));

        return OverrideEditOutcome::Saved;
    }

    private function removeOverride(SymfonyStyle $io, string $key, string $catalogue, string $locale, string $scope): OverrideEditOutcome
    {
        // The value is on screen — the landscape's cascade, or the recap block above.
        // The question does not repeat it.
        if (!$io->confirm('Remove this override?', true)) {
            $io->note('Nothing removed.');

            return OverrideEditOutcome::Untouched;
        }

        $this->writer->remove($key, $catalogue, $locale, $scope);
        $io->success(\sprintf('Override removed for "%s" (%s, %s).', $key, $catalogue, $locale));

        return OverrideEditOutcome::Removed;
    }

    private function nothing(SymfonyStyle $io): OverrideEditOutcome
    {
        $io->note('Nothing changed.');

        return OverrideEditOutcome::Untouched;
    }
}
