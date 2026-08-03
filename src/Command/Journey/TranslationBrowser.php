<?php

declare(strict_types=1);

namespace CylleneDigital\AiTranslationBundle\Command\Journey;

use CylleneDigital\AiTranslationBundle\Catalogue\CatalogueRegistry;
use CylleneDigital\AiTranslationBundle\Coverage\CoverageCalculator;
use CylleneDigital\AiTranslationBundle\Override\OverrideReader;
use CylleneDigital\AiTranslationBundle\Repository\TranslationOverrideRepository;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * The "let me look around" door: a set of translations, narrowed question by question,
 * then acted upon. The narrowing is the same {@see ContextualFilter} the review uses —
 * a question only shows up when the set actually spans several values — and "does it
 * carry an override" is one filter among the others, silently skipped when every row
 * answers the same.
 *
 * The source is asked first and is not a filter: "the stored overrides" is a small set
 * one query brings back whole, while "every translation" is a catalogue x locale walk
 * whose size has no upper bound. Answering the coordinates before loading is what keeps
 * the second branch affordable.
 *
 * That second branch splits the locale in two, the way the coverage report does: a
 * reference locale says which keys exist — including the ones the target has never
 * translated, which are precisely the ones worth opening — and a target locale carries
 * what is displayed and edited. Reading them side by side is the point.
 */
final class TranslationBrowser
{
    private const string SOURCE_OVERRIDES = 'The stored overrides';
    private const string SOURCE_ALL = 'Every translation of one catalogue';
    private const string GLOBAL_SCOPE = 'No scope (global)';
    private const string WITH_OVERRIDE = 'with an override';
    private const string WITHOUT_OVERRIDE = 'file value only';
    private const string ACTION_ALL = 'Show or edit them all';
    private const string ACTION_ONE = 'Edit one key';
    private const string ACTION_NONE = 'Nothing';

    /** Rows printed before the table gives up and asks for a narrower selection. */
    private const int MAX_ROWS = 50;

    public function __construct(
        private readonly TranslationOverrideRepository $repository,
        private readonly CatalogueRegistry $catalogues,
        private readonly CoverageCalculator $coverage,
        private readonly OverrideReader $overrides,
        private readonly ContextualFilter $filter,
        private readonly ConsolePicker $picker,
        private readonly OverrideEditor $editor,
    ) {
    }

    public function browse(SymfonyStyle $io): int
    {
        $source = $io->choice('What do you want to browse?', [self::SOURCE_OVERRIDES, self::SOURCE_ALL], self::SOURCE_OVERRIDES);
        $rows = self::SOURCE_ALL === $source ? $this->everyTranslation($io) : $this->storedOverrides();

        if ([] === $rows) {
            $io->info(self::SOURCE_ALL === $source ? 'This catalogue has no key for that locale.' : 'No translation overrides stored.');

            return Command::SUCCESS;
        }

        $rows = $this->narrow($io, $rows);

        if ([] === $rows) {
            $io->info('No translation matches these filters.');

            return Command::SUCCESS;
        }

        $this->table($io, $rows);

        return $this->act($io, $rows);
    }

    /**
     * Every stored override, all catalogues and locales at once — the set is made of
     * hand-written exceptions, so one query brings it back whole.
     *
     * @return list<TranslationRow>
     */
    private function storedOverrides(): array
    {
        $rows = [];

        foreach ($this->repository->findAllRows() as $override) {
            $rows[] = new TranslationRow(
                $override['key'],
                $override['catalogue'],
                $override['locale'],
                $override['scope'],
                $override['value'],
                hasOverride: true,
            );
        }

        return $rows;
    }

    /**
     * Every key of one catalogue for one locale, overrides merged in. The three
     * coordinates are answered before anything is loaded: a catalogue x locale walk of
     * a real application is thousands of rows, and there is no point building one to
     * throw it away at the first filter.
     *
     * @return list<TranslationRow>
     */
    private function everyTranslation(SymfonyStyle $io): array
    {
        $catalogue = $this->askCatalogue($io);

        if (null === $catalogue) {
            return [];
        }

        $locales = $this->catalogues->getAvailableLocales();

        if ([] === $locales) {
            $io->error('No locale available — the scanned translations/ directory is empty.');

            return [];
        }

        $source = $this->askLocale($io, 'Default locale (the keys to work through)', $locales, $this->coverage->getDefaultLocale());
        $target = $this->askLocale($io, 'Locale to edit', $locales, $this->firstOther($locales, $source));
        $scope = $this->picker->scope($io);

        // Two reads, one job each: the reference decides which keys exist, the target
        // carries what is displayed and edited. A key the target holds and the
        // reference does not is left out on purpose — that is drift, and "Inspect the
        // default locale keys and drift" is the journey that shows it.
        $reference = $this->catalogues->getOriginalValues($catalogue, $source);
        $entries = $this->overrides->getTranslationsForCatalogue($catalogue, $target, $scope);

        $rows = [];

        foreach ($reference as $key => $sourceValue) {
            $entry = $entries[$key] ?? null;

            $rows[] = new TranslationRow(
                (string) $key,
                $catalogue,
                $target,
                $scope,
                null !== $entry ? $entry['override'] ?? $entry['inherited'] ?? $entry['original'] : null,
                $entry['hasOverride'] ?? false,
                sourceLocale: $source,
                sourceValue: $sourceValue,
            );
        }

        return $rows;
    }

    /**
     * One locale, silently taken when the host exposes a single one.
     *
     * @param list<string> $locales
     */
    private function askLocale(SymfonyStyle $io, string $label, array $locales, string $preferred): string
    {
        if (1 === \count($locales)) {
            return $locales[0];
        }

        $default = \in_array($preferred, $locales, true) ? $preferred : $locales[0];
        $choice = $io->choice($label, $locales, $default);

        return \is_string($choice) ? $choice : $default;
    }

    /**
     * The locale to propose as the target: translating means going somewhere else, so
     * anything but the reference — unless it is the only one there is.
     *
     * @param list<string> $locales
     */
    private function firstOther(array $locales, string $source): string
    {
        foreach ($locales as $locale) {
            if ($locale !== $source) {
                return $locale;
            }
        }

        return $source;
    }

    /**
     * The catalogue as an autocompleted input — the identifiers can be long paths, and
     * typing beats scrolling a numbered list of every catalogue an application has.
     */
    private function askCatalogue(SymfonyStyle $io): ?string
    {
        $identifiers = $this->catalogues->getCatalogueIdentifiers();

        if ([] === $identifiers) {
            $io->error('No catalogue available.');

            return null;
        }

        $known = array_fill_keys($identifiers, true);
        $question = new Question('Catalogue');
        $question->setAutocompleterValues($identifiers);
        $question->setValidator(static function (mixed $answer) use ($known): string {
            $answer = \is_string($answer) ? trim($answer) : '';

            if (!isset($known[$answer])) {
                throw new \InvalidArgumentException(\sprintf('No catalogue named "%s".', $answer));
            }

            return $answer;
        });

        $catalogue = $io->askQuestion($question);

        return \is_string($catalogue) ? $catalogue : null;
    }

    /**
     * Locale, then override presence, then catalogue and scope. The presence filter
     * sits second because it is the one that changes what the rest of the questions are
     * about; on the overrides branch every row answers "with an override", so
     * {@see ContextualFilter} skips it without a word.
     *
     * @param list<TranslationRow> $rows
     *
     * @return list<TranslationRow>
     */
    private function narrow(SymfonyStyle $io, array $rows): array
    {
        $rows = $this->filter->apply($io, $rows, 'Locale', static fn (TranslationRow $r): string => $r->locale);
        $rows = $this->filter->apply(
            $io,
            $rows,
            'Overrides',
            static fn (TranslationRow $r): string => $r->hasOverride ? self::WITH_OVERRIDE : self::WITHOUT_OVERRIDE,
        );
        $rows = $this->filter->apply(
            $io,
            $rows,
            'Missing',
            // An empty value is missing, as for the coverage report and the generation.
            static fn (TranslationRow $r): string => null === $r->value || '' === $r->value ? \sprintf('missing in %s', $r->locale) : 'translated',
        );
        $rows = $this->filterOnCatalogue($io, $rows);

        return $this->filter->apply(
            $io,
            $rows,
            'Scope',
            static fn (TranslationRow $r): string => $r->scope,
            static fn (string $scope): string => '' === $scope ? self::GLOBAL_SCOPE : $scope,
        );
    }

    /**
     * The catalogue filter as an autocompleted input, for the same reason as the source
     * question. Asked only when the set actually spans several catalogues.
     *
     * @param list<TranslationRow> $rows
     *
     * @return list<TranslationRow>
     */
    private function filterOnCatalogue(SymfonyStyle $io, array $rows): array
    {
        $catalogues = [];

        foreach ($rows as $row) {
            $catalogues[$row->catalogue] = true;
        }

        if (\count($catalogues) < 2) {
            return $rows;
        }

        $question = new Question('Catalogue (enter for all)');
        $question->setAutocompleterValues(array_keys($catalogues));
        $question->setValidator(static function (mixed $answer) use ($catalogues): string {
            $answer = \is_string($answer) ? trim($answer) : '';

            if ('' !== $answer && !isset($catalogues[$answer])) {
                throw new \InvalidArgumentException(\sprintf('No row in a catalogue named "%s".', $answer));
            }

            return $answer;
        });

        $catalogue = $io->askQuestion($question);

        if (!\is_string($catalogue) || '' === $catalogue) {
            return $rows;
        }

        return array_values(array_filter($rows, static fn (TranslationRow $r): bool => $r->catalogue === $catalogue));
    }

    /**
     * The table is a menu, not the working surface: past {@see MAX_ROWS} it shows the
     * head of the selection and says how much it is hiding, rather than flooding the
     * terminal with a listing nobody can read back. Keys are shown whole — they are
     * what "Edit one key" is answered with — and only values are shortened.
     *
     * @param list<TranslationRow> $rows
     */
    private function table(SymfonyStyle $io, array $rows): void
    {
        $io->title('Translations');

        $shown = \array_slice($rows, 0, self::MAX_ROWS);
        $reference = $shown[0]->sourceLocale;
        $table = [];

        foreach ($shown as $row) {
            // The key is never truncated: it is what the actions below are answered
            // with, and a "…" would make the row unreachable by name.
            $table[] = null !== $reference
                ? [
                    $row->key,
                    $this->cell($row->sourceValue, '(empty)'),
                    $this->cell($row->value),
                    $row->hasOverride ? 'yes' : '-',
                    '' === $row->scope ? '-' : $row->scope,
                ]
                : [
                    $row->locale,
                    $row->catalogue,
                    $row->key,
                    $this->cell($row->value),
                    $row->hasOverride ? 'yes' : '-',
                    '' === $row->scope ? '-' : $row->scope,
                ];
        }

        // Catalogue and locale are constants of the reference view — they were answered,
        // not discovered — so the columns go to what actually differs from row to row.
        $io->table(
            null !== $reference
                ? ['Key', $reference, $shown[0]->locale, 'Override', 'Scope']
                : ['Locale', 'Catalogue', 'Key', 'Value', 'Override', 'Scope'],
            $table,
        );

        $hidden = \count($rows) - \count($shown);

        if ($hidden > 0) {
            $io->note(\sprintf('%d more row(s) not shown — narrow further, or pick one key by name. The actions below still cover all %d.', $hidden, \count($rows)));
        }

        $io->success(\sprintf('%d translation(s) selected.', \count($rows)));
    }

    /**
     * What to do with the selection — the actions cover every row it holds, not only
     * the ones the table had room for.
     *
     * @param non-empty-list<TranslationRow> $rows
     */
    private function act(SymfonyStyle $io, array $rows): int
    {
        $choice = $io->choice('What do you want to do?', [self::ACTION_ALL, self::ACTION_ONE, self::ACTION_NONE], self::ACTION_NONE);

        if (self::ACTION_ONE === $choice) {
            $row = $this->askOneRow($io, $rows);
            $this->header($io, $row, 1, 1);
            $this->editor->edit($io, $row->key, $row->catalogue, $row->locale, $row->scope);

            return Command::SUCCESS;
        }

        if (self::ACTION_ALL === $choice) {
            return $this->editAll($io, $rows);
        }

        return Command::SUCCESS;
    }

    /**
     * One key of the selection, autocompleted over it — the table may have shown only
     * the head, so the completion is the way to reach a row that scrolled past.
     *
     * A key is not a row: across locales, catalogues or scopes the selection can hold it
     * several times. Then a second question picks the row — keeping the first one made
     * the others unreachable by name.
     *
     * @param non-empty-list<TranslationRow> $rows
     */
    private function askOneRow(SymfonyStyle $io, array $rows): TranslationRow
    {
        /** @var array<string, non-empty-list<TranslationRow>> $byKey */
        $byKey = [];

        foreach ($rows as $row) {
            $byKey[$row->key][] = $row;
        }

        $question = new Question('Translation key');
        $question->setAutocompleterValues(array_keys($byKey));
        $question->setValidator(static function (mixed $answer) use ($byKey): string {
            $answer = \is_string($answer) ? trim($answer) : '';

            if (!isset($byKey[$answer])) {
                throw new \InvalidArgumentException(\sprintf('No key "%s" in the current selection.', $answer));
            }

            return $answer;
        });

        $key = $io->askQuestion($question);
        $matches = \is_string($key) && isset($byKey[$key]) ? $byKey[$key] : [$rows[0]];

        if (1 === \count($matches)) {
            return $matches[0];
        }

        $labels = [];

        foreach ($matches as $row) {
            $labels[\sprintf('%s (%s%s)', $row->locale, $row->catalogue, '' === $row->scope ? '' : ', scope '.$row->scope)] = $row;
        }

        $label = $io->choice('Which one?', array_keys($labels));

        return $labels[\is_string($label) ? $label : array_key_first($labels)];
    }

    /**
     * The whole selection, one translation at a time — with the way out the review loop
     * has: quitting stops the walk and the tally says what was left behind.
     *
     * @param list<TranslationRow> $rows
     */
    private function editAll(SymfonyStyle $io, array $rows): int
    {
        $total = \count($rows);
        $saved = 0;
        $removed = 0;
        $seen = 0;

        foreach ($rows as $index => $row) {
            ++$seen;
            $this->header($io, $row, $index + 1, $total);
            $outcome = $this->editor->edit($io, $row->key, $row->catalogue, $row->locale, $row->scope, quittable: true);

            if (OverrideEditOutcome::Quit === $outcome) {
                --$seen;

                break;
            }

            $saved += OverrideEditOutcome::Saved === $outcome ? 1 : 0;
            $removed += OverrideEditOutcome::Removed === $outcome ? 1 : 0;
        }

        $left = $total - $seen;
        $io->success(\sprintf(
            'Done: %d saved, %d removed, %d untouched%s.',
            $saved,
            $removed,
            $seen - $saved - $removed,
            $left > 0 ? \sprintf(', %d left for later', $left) : '',
        ));

        return Command::SUCCESS;
    }

    /** Which translation the editor below is about — a batch without it is unreadable. */
    private function header(SymfonyStyle $io, TranslationRow $row, int $position, int $total): void
    {
        $io->section(\sprintf(
            '%d/%d — %s (%s, %s%s)',
            $position,
            $total,
            $row->key,
            $row->catalogue,
            $row->locale,
            '' === $row->scope ? '' : ', scope '.$row->scope,
        ));
    }

    /**
     * One value cell: shortened, and a gap that reads as a gap. An empty translation is
     * a blank label on the site and a missing key for the coverage report: printed as is,
     * it looked like a translated row. The reference's own empty value is no key to
     * translate, so it is only said to be empty.
     */
    private function cell(?string $value, string $empty = '(empty — counted as missing)'): string
    {
        return match ($value) {
            null => '(missing)',
            '' => $empty,
            default => $this->truncate($value, 40),
        };
    }

    private function truncate(string $value, int $length): string
    {
        return mb_strlen($value) > $length ? mb_substr($value, 0, $length - 1).'…' : $value;
    }
}
