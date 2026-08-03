<?php

declare(strict_types=1);

namespace CylleneDigital\AiTranslationBundle\Command\Journey;

use CylleneDigital\AiTranslationBundle\Catalogue\CatalogueRegistry;
use CylleneDigital\AiTranslationBundle\Entity\TranslationOverride;
use CylleneDigital\AiTranslationBundle\Override\OverrideReader;
use CylleneDigital\AiTranslationBundle\Override\OverrideWriter;
use CylleneDigital\AiTranslationBundle\Repository\TranslationOverrideRepository;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * The "I know the key" door: one translation, from its key. It opens on the whole
 * landscape of that key — per catalogue and per locale, the resolution cascade itself:
 * the file value (shown even when missing, so a gap reads as a gap), the global
 * override when one exists, then each scoped override. Untruncated values, author and
 * date. The answers then narrow it (locale, scope, catalogue) — the locale answer
 * recapping its own slice — and {@see OverrideEditor} acts last. Deciding comes after
 * seeing, not before; the action displays no value the landscape already put on screen.
 */
final class KeyLandscape
{
    public function __construct(
        private readonly OverrideReader $overrides,
        private readonly CatalogueRegistry $catalogues,
        private readonly OverrideWriter $writer,
        private readonly TranslationOverrideRepository $overrideRepository,
        private readonly ValueBlock $valueBlock,
        private readonly OverrideEditor $editor,
        private readonly ConsolePicker $picker,
    ) {
    }

    public function lookUp(SymfonyStyle $io): int
    {
        $locales = $this->catalogues->getAvailableLocales();

        if ([] === $locales) {
            $io->error('No locale available — the scanned translations/ directory is empty.');

            return Command::FAILURE;
        }

        $key = $this->askKey($io);
        $stored = $this->overrideRepository->findBy(['key' => $key], ['locale' => 'ASC', 'catalogue' => 'ASC', 'scope' => 'ASC']);
        $catalogues = $this->overrides->findCataloguesForKey($key);

        $this->showLandscape($io, $key, $catalogues, $stored, $locales);

        $locale = $this->askLocale($io, $locales, $stored);

        // A per-locale recap after the answer — the landscape above spanned every
        // locale, this is the slice the next questions and the action operate on.
        if (\count($locales) > 1 && [] !== $catalogues) {
            $this->showSlice($io, $key, $catalogues, $stored, $locale);
        }

        $scope = $this->picker->scope($io);
        $catalogue = $this->resolveCatalogue($io, $key, $catalogues);

        if (null === $catalogue) {
            return Command::FAILURE;
        }

        // The landscape and its recap showed that cascade whole — file value, global
        // override, every scope. The action only displays it again when it was never
        // on screen (a brand-new key) or when a scope narrows it to one of its lines.
        $shown = \in_array($catalogue, $catalogues, true) && '' === $scope;

        $this->editor->edit($io, $key, $catalogue, $locale, $scope, recap: !$shown);

        return Command::SUCCESS;
    }

    /**
     * The whole landscape of the key, per catalogue then per locale: the resolution
     * cascade — file value (always shown, a missing one reads as missing), global
     * override, scoped overrides — untruncated, with author and date. Overrides
     * stored under a locale the host no longer exposes are appended and flagged
     * rather than silently hidden. $locales is the host's list, so what it does not
     * contain is genuinely gone — never merely a locale this display leaves out.
     *
     * @param list<string>              $catalogues
     * @param list<TranslationOverride> $stored
     * @param list<string>              $locales
     */
    private function showLandscape(SymfonyStyle $io, string $key, array $catalogues, array $stored, array $locales): void
    {
        if ([] === $catalogues) {
            return;
        }

        $byCatalogueAndLocale = $this->groupByCatalogueAndLocale($stored);

        foreach ($catalogues as $catalogue) {
            $io->title($catalogue);

            foreach ($locales as $locale) {
                $this->showCascade($io, $key, $catalogue, $locale, $byCatalogueAndLocale[$catalogue][$locale] ?? []);
            }

            foreach ($byCatalogueAndLocale[$catalogue] ?? [] as $locale => $overrides) {
                if (!\in_array($locale, $locales, true)) {
                    $this->showCascade($io, $key, $catalogue, $locale, $overrides, available: false);
                }
            }
        }
    }

    /**
     * One locale of the landscape, per catalogue: the cascade of the locale that was
     * just answered, and nothing else — no other locale, available or not.
     *
     * @param list<string>              $catalogues
     * @param list<TranslationOverride> $stored
     */
    private function showSlice(SymfonyStyle $io, string $key, array $catalogues, array $stored, string $locale): void
    {
        $byCatalogueAndLocale = $this->groupByCatalogueAndLocale($stored);

        foreach ($catalogues as $catalogue) {
            $io->title($catalogue);
            $this->showCascade($io, $key, $catalogue, $locale, $byCatalogueAndLocale[$catalogue][$locale] ?? []);
        }
    }

    /**
     * [catalogue][locale] => the overrides stored there, '' scope first thanks to the
     * query order.
     *
     * @param list<TranslationOverride> $stored
     *
     * @return array<string, array<string, list<TranslationOverride>>>
     */
    private function groupByCatalogueAndLocale(array $stored): array
    {
        $byCatalogueAndLocale = [];

        foreach ($stored as $override) {
            $byCatalogueAndLocale[$override->getCatalogue()][$override->getLocale()][] = $override;
        }

        return $byCatalogueAndLocale;
    }

    /**
     * One (catalogue, locale) cascade: the file value first — even when missing —
     * then the global override, then each scoped override.
     *
     * @param list<TranslationOverride> $overrides
     */
    private function showCascade(SymfonyStyle $io, string $key, string $catalogue, string $locale, array $overrides, bool $available = true): void
    {
        // The locale is the level below the catalogue: section() under title(), so the
        // nesting reads off the underlines instead of a bare word floating in the cascade.
        $io->section($available ? $locale : \sprintf('%s (locale no longer available)', $locale));

        $fileValue = $this->catalogues->getOriginalValue($key, $catalogue, $locale);
        $this->valueBlock->render($io, 'File value', $fileValue, missing: '(missing — no value for this locale)');

        foreach ($overrides as $override) {
            $updated = array_filter([
                $override->getUpdatedAt()->format('Y-m-d H:i'),
                null !== $override->getUpdatedBy() ? 'by '.$override->getUpdatedBy() : null,
            ]);

            $this->valueBlock->render(
                $io,
                // The scope dimension named on the label — a bare code would read as a
                // random word here, and the parentheses belong to the metadata.
                'Override — '.('' === $override->getScope() ? 'no scope (global)' : 'scope '.$override->getScope()),
                $override->getValue(),
                [] !== $updated ? 'updated '.implode(' ', $updated) : null,
            );
        }
    }

    /**
     * The locale coordinate — asked only when there is an actual choice; the cards
     * above already show which locales carry an override, so the choices stay plain.
     *
     * @param list<string>              $locales
     * @param list<TranslationOverride> $stored
     */
    private function askLocale(SymfonyStyle $io, array $locales, array $stored): string
    {
        if (1 === \count($locales)) {
            return $locales[0];
        }

        // A single stored locale is the most likely target — propose it, don't impose it.
        $storedLocales = array_values(array_unique(array_map(static fn (TranslationOverride $o): string => $o->getLocale(), $stored)));
        $default = 1 === \count($storedLocales) && \in_array($storedLocales[0], $locales, true) ? $storedLocales[0] : $locales[0];

        $choice = $io->choice('Locale to edit', $locales, $default);

        return \is_string($choice) ? $choice : $default;
    }

    /**
     * The catalogue the combination points to: inferred silently when a single
     * catalogue knows the key — the landscape printed exactly one section, bearing
     * that name — picked when several do, and picked among every catalogue when the
     * key is brand-new. The candidates are the ones the landscape was built from —
     * looking them up again would walk every catalogue of every locale a second time.
     *
     * @param list<string> $candidates
     */
    private function resolveCatalogue(SymfonyStyle $io, string $key, array $candidates): ?string
    {
        if (\count($candidates) > 1) {
            $choice = $io->choice('Catalogue', $candidates, $candidates[0]);

            return \is_string($choice) ? $choice : $candidates[0];
        }

        if ([] === $candidates) {
            $identifiers = $this->catalogues->getCatalogueIdentifiers();

            if ([] === $identifiers) {
                $io->error('No catalogue available.');

                return null;
            }

            $io->text(\sprintf('The key "%s" is new — pick its catalogue.', $key));
            $choice = $io->choice('Catalogue', $identifiers);

            return \is_string($choice) ? $choice : $identifiers[0];
        }

        return $candidates[0];
    }

    private function askKey(SymfonyStyle $io): string
    {
        $writer = $this->writer;
        $question = new Question('Translation key');
        $question->setAutocompleterValues($this->getKnownKeys());
        $question->setValidator(static function (mixed $answer) use ($writer): string {
            $answer = \is_string($answer) ? trim($answer) : '';

            if ('' === $answer) {
                throw new \InvalidArgumentException('A key is required.');
            }

            // Refused here rather than at the save: the answer is still on the prompt.
            if (!$writer->acceptsKey($answer)) {
                throw new \InvalidArgumentException(\sprintf('The key is too long (%d characters, maximum %d).', mb_strlen($answer), TranslationOverride::MAX_KEY_LENGTH));
            }

            return $answer;
        });

        $key = $io->askQuestion($question);

        return \is_string($key) ? $key : '';
    }

    /**
     * Every key the bundle can see — file entries and stored overrides of every
     * catalogue and locale (orphans included), for the interactive autocompletion.
     *
     * The file side comes from the scanner (memoised for the run) and the stored side
     * from a single query: walking getTranslationsForCatalogue() instead would merge
     * the two per (catalogue, locale) pair and issue one query per pair, for a list
     * that only feeds a prompt.
     *
     * @return list<string>
     */
    private function getKnownKeys(): array
    {
        $keys = [];

        foreach ($this->catalogues->getCatalogueIdentifiers() as $catalogue) {
            foreach ($this->catalogues->getAvailableLocales() as $locale) {
                foreach ($this->catalogues->getOriginalValues($catalogue, $locale) as $key => $value) {
                    $keys[$key] = true;
                }
            }
        }

        foreach ($this->overrideRepository->findDistinctKeys() as $key) {
            $keys[$key] = true;
        }

        return array_map(strval(...), array_keys($keys));
    }
}
