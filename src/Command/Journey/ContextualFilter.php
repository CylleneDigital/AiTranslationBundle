<?php

declare(strict_types=1);

namespace CylleneDigital\AiTranslationBundle\Command\Journey;

use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * The narrowing question shared by the listing journeys: "Locale?", "Catalogue?",
 * "Scope?" — asked only when the set at hand actually spans several values, so a
 * single-locale queue never wastes a question, and answered with "(all)" by a plain
 * enter.
 *
 * Stateless on purpose: this is a shared service and the hub runs several journeys in
 * one process, so remembering an answer here would leak one journey's narrowing into
 * the next. The narrowed set IS the answer — callers act on the items they got back,
 * never on a filter the service kept aside.
 */
final class ContextualFilter
{
    private const string ALL = '(all)';

    /**
     * @template T
     *
     * @param list<T>                         $items
     * @param callable(T): string             $extract the filterable value of one item
     * @param (callable(string): string)|null $display how a value shows up in the picker (e.g. '' → 'No scope (global)')
     *
     * @return list<T>
     */
    public function apply(SymfonyStyle $io, array $items, string $label, callable $extract, ?callable $display = null): array
    {
        $display ??= static fn (string $value): string => $value;
        $values = [];

        foreach ($items as $item) {
            $values[$extract($item)] = true;
        }

        if (\count($values) < 2) {
            return $items;
        }

        $choices = array_map($display, array_keys($values));
        $choice = $io->choice($label, array_merge([self::ALL], $choices), self::ALL);

        if (!\is_string($choice) || self::ALL === $choice) {
            return $items;
        }

        return array_values(array_filter(
            $items,
            static fn ($item): bool => $display($extract($item)) === $choice,
        ));
    }
}
