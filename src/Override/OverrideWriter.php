<?php

declare(strict_types=1);

namespace CylleneDigital\AiTranslationBundle\Override;

use CylleneDigital\AiTranslationBundle\Catalogue\LocaleProviderInterface;
use CylleneDigital\AiTranslationBundle\Entity\TranslationOverride;
use CylleneDigital\AiTranslationBundle\Event\OverrideRemovedEvent;
use CylleneDigital\AiTranslationBundle\Event\OverrideSavedEvent;
use CylleneDigital\AiTranslationBundle\Event\SuggestionRejectedEvent;
use CylleneDigital\AiTranslationBundle\Locale\LocaleFallback;
use CylleneDigital\AiTranslationBundle\Repository\TranslationOverrideRepository;
use CylleneDigital\AiTranslationBundle\Repository\TranslationSuggestionRepository;
use CylleneDigital\AiTranslationBundle\Scope\ScopeRegistry;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Every write of an override, and the three side effects none of them may skip: stamping
 * the author, invalidating the caches the value is served from, and dispatching the event
 * the host hangs its own invalidations on (HTTP cache, audit, webhook).
 *
 * A write also closes the pending suggestions of the key it sets (same catalogue, locale
 * and scope), errored ones included: someone chose the value, so the proposal is
 * rejected — by that author, with a {@see SuggestionRejectedEvent} flagged as superseded.
 * Left pending, it would still be counted as awaiting review, approving it later would
 * overwrite the chosen value, and a retry of an errored one would bill a key already
 * translated. Done here rather than in each door for the same reason as the effects: the
 * edit, the import and the host's own code would each have to remember. The approval of
 * a suggestion writes through here too: the suggestion it approves is already approved
 * in memory by then, and is left alone.
 *
 * Grouping them here is the point: a write that forgets one of the three produces a value
 * that is stored but not served, and the four writing paths of the bundle (CLI, review
 * approval, import, host integration) would each have to remember. The batch variants exist for
 * the same reason at scale — one flush, one invalidation per touched locale, one event per
 * resulting row.
 *
 * What is deliberately NOT here: {@see TranslationValueValidator}. A writer that refused a
 * value could only throw, where the surfaces that ask for it — the console, the import,
 * the review approval — each turn a refusal into a message their own user understands.
 * Code writing through this class directly is expected to validate first if it wants that
 * behaviour; the doc says so ({@see docs/concepts/manual-override.md}).
 *
 * Every write ends on an EntityManager::flush(), which ORM 3 no longer lets us restrict to
 * one entity: it commits whatever else the caller had pending. The batch variants exist
 * partly for that reason — one flush for a whole import instead of one per row.
 *
 * Reading and browsing belong to {@see OverrideReader}; {@see TranslationManager} exposes
 * both sides to the integration packages.
 */
final class OverrideWriter
{
    /**
     * The cache invalidations and events held back by {@see withEffectsAfter()}, null
     * when writes apply them right away.
     *
     * @var list<\Closure(): void>|null
     */
    private ?array $deferredEffects = null;

    public function __construct(
        private readonly TranslationOverrideRepository $repository,
        private readonly TranslationCacheManager $cacheManager,
        private readonly AuthorProviderInterface $authorProvider,
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly TranslationSuggestionRepository $suggestionRepository,
        private readonly LocaleProviderInterface $locales,
    ) {
    }

    /** Whether a key fits the override table — the caller's chance to refuse it with a message of its own. */
    public function acceptsKey(string $key): bool
    {
        return mb_strlen($key) <= TranslationOverride::MAX_KEY_LENGTH;
    }

    /**
     * @throws TranslationKeyTooLongException when the key exceeds what the table stores
     * @throws InvalidOverrideException       when the locale, the catalogue or the scope cannot be stored
     */
    public function save(string $key, string $catalogue, string $locale, string $value, ?string $originalValue = null, string $scope = ''): TranslationOverride
    {
        $this->assertFits($key, $catalogue, $locale, $scope);

        // One transaction: the superseded suggestions are locked, then removed by the
        // override's flush — and the effects wait for its commit.
        return $this->withEffectsAfter(fn (): TranslationOverride => $this->suggestionRepository->transactional(function () use ($key, $catalogue, $locale, $value, $originalValue, $scope): TranslationOverride {
            $override = $this->repository->findOneByKey($key, $catalogue, $locale, $scope);

            if (null === $override) {
                $override = new TranslationOverride($key, $catalogue, $locale, $scope);
                $override->setOriginalValue($originalValue);
            }

            $override->setValue($value);
            $override->setUpdatedBy($this->authorProvider->getAuthorIdentifier());

            $this->closeSupersededSuggestions([$override]);
            $this->repository->save($override);
            $this->applyEffect(function () use ($locale, $override): void {
                $this->cacheManager->invalidate($locale);
                $this->eventDispatcher->dispatch(new OverrideSavedEvent($override));
            });

            return $override;
        }));
    }

    /**
     * Runs $operation — typically a database transaction the writes take part in — and
     * applies their cache invalidations and events only once it has returned. Inside a
     * transaction, a write's flush is not yet committed: an event dispatched then would
     * have the host purge, notify or audit a value a rollback may still discard. When
     * $operation throws, the effects are dropped with it.
     *
     * @template T
     *
     * @param callable(): T $operation
     *
     * @return T
     */
    public function withEffectsAfter(callable $operation): mixed
    {
        if (null !== $this->deferredEffects) {
            return $operation();
        }

        $this->deferredEffects = [];

        try {
            $result = $operation();
            $effects = $this->takeDeferredEffects();
        } finally {
            $this->deferredEffects = null;
        }

        foreach ($effects as $effect) {
            $effect();
        }

        return $result;
    }

    /**
     * Batch variant of {@see save()} for imports: every entry is staged and written in a
     * SINGLE flush, each touched locale's cache is invalidated exactly once (instead of
     * once per entry), and an {@see OverrideSavedEvent} is dispatched per resulting
     * override. Entries repeated within the batch collapse onto the same row.
     *
     * @param list<array{key: string, catalogue: string, locale: string, value: string, scope?: string, original?: ?string}> $entries ('' or no scope = global)
     *
     * @return int number of distinct overrides written
     *
     * @throws TranslationKeyTooLongException when one key exceeds what the table stores —
     *                                        raised before any write, so the batch is all
     *                                        or nothing
     * @throws InvalidOverrideException       likewise, for a locale, a catalogue or a scope
     */
    public function saveMany(array $entries): int
    {
        return $this->saveAndRemoveMany($entries, [])[0];
    }

    /**
     * {@see saveMany()} and {@see removeMany()} in the same single flush — one transaction:
     * an import writes its new values and drops the overrides set back to their baseline
     * together, or not at all.
     *
     * @param list<array{key: string, catalogue: string, locale: string, value: string, scope?: string, original?: ?string}> $entries  the overrides to write ('' or no scope = global)
     * @param list<TranslationOverride>                                                                                      $removals the stored overrides to delete
     *
     * @return array{int, int} the number of distinct overrides written, and removed
     *
     * @throws TranslationKeyTooLongException when one key exceeds what the table stores
     * @throws InvalidOverrideException       when a locale, a catalogue or a scope cannot be stored
     */
    public function saveAndRemoveMany(array $entries, array $removals): array
    {
        $checked = [];

        foreach ($entries as $entry) {
            if (!$this->acceptsKey($entry['key'])) {
                throw new TranslationKeyTooLongException($entry['key']);
            }

            // Once per (catalogue, locale, scope): the locale check reads the available locales.
            $target = $entry['catalogue']."\0".$entry['locale']."\0".($entry['scope'] ?? '');

            if (!isset($checked[$target])) {
                $this->assertStorable($entry['catalogue'], $entry['locale'], $entry['scope'] ?? '');
                $checked[$target] = true;
            }
        }

        if ([] === $entries && [] === $removals) {
            return [0, 0];
        }

        // One transaction, as in save(): the superseded suggestions are locked, then
        // removed by the same flush as the overrides, and the effects wait for its commit.
        return $this->withEffectsAfter(fn (): array => $this->suggestionRepository->transactional(fn (): array => $this->writeMany($entries, $removals)));
    }

    /**
     * @param list<array{key: string, catalogue: string, locale: string, value: string, scope?: string, original?: ?string}> $entries
     * @param list<TranslationOverride>                                                                                      $removals
     *
     * @return array{int, int}
     */
    private function writeMany(array $entries, array $removals): array
    {
        $author = $this->authorProvider->getAuthorIdentifier();

        /** @var array<string, TranslationOverride> $pending locale|catalogue|key|scope => override */
        $pending = [];
        $locales = [];

        // The stored overrides of the keys at hand, one query per touched (locale, scope):
        // a lookup per entry meant one query per line of an imported file, and loading the
        // whole locale left all of it managed by the host's entity manager.
        /** @var array<string, list<string>> $keys locale|scope => keys */
        $keys = [];

        foreach ($entries as $entry) {
            $keys[$entry['locale'].'|'.($entry['scope'] ?? '')][] = $entry['key'];
        }

        /** @var array<string, array<string, TranslationOverride>> $stored locale|scope => catalogue|key => override */
        $stored = [];

        foreach ($entries as $entry) {
            ['key' => $key, 'catalogue' => $catalogue, 'locale' => $locale, 'value' => $value] = $entry;
            $scope = $entry['scope'] ?? '';
            $mapKey = $locale.'|'.$catalogue.'|'.$key.'|'.$scope;

            $stored[$locale.'|'.$scope] ??= $this->repository->findIndexedByKeys($locale, $scope, $keys[$locale.'|'.$scope]);

            $override = $pending[$mapKey] ?? $stored[$locale.'|'.$scope][$catalogue.'|'.$key] ?? null;

            // Like save(): the original is the file value the override was created over,
            // recorded once, never rewritten by a later change.
            if (null === $override) {
                $override = new TranslationOverride($key, $catalogue, $locale, $scope);
                $override->setOriginalValue($entry['original'] ?? null);
            }

            $override->setValue($value);
            $override->setUpdatedBy($author);

            $this->repository->saveDeferred($override);

            $pending[$mapKey] = $override;
            $locales[$locale] = true;
        }

        foreach ($removals as $override) {
            $this->repository->removeDeferred($override);
            $locales[$override->getLocale()] = true;
        }

        $this->closeSupersededSuggestions(array_values($pending));
        $this->repository->flush();

        $this->applyEffect(function () use ($locales, $pending, $removals): void {
            foreach (array_keys($locales) as $locale) {
                $this->cacheManager->invalidate($locale);
            }

            foreach ($pending as $override) {
                $this->eventDispatcher->dispatch(new OverrideSavedEvent($override));
            }

            foreach ($removals as $override) {
                $this->eventDispatcher->dispatch(new OverrideRemovedEvent(
                    $override->getKey(),
                    $override->getCatalogue(),
                    $override->getLocale(),
                    $override->getScope(),
                ));
            }
        });

        return [\count($pending), \count($removals)];
    }

    public function remove(string $key, string $catalogue, string $locale, string $scope = ''): void
    {
        $override = $this->repository->findOneByKey($key, $catalogue, $locale, $scope);

        if (null !== $override) {
            $this->repository->remove($override);
            $this->applyEffect(function () use ($key, $catalogue, $locale, $scope): void {
                $this->cacheManager->invalidate($locale);
                $this->eventDispatcher->dispatch(new OverrideRemovedEvent($key, $catalogue, $locale, $scope));
            });
        }
    }

    /**
     * Deletes the given overrides in one pass: a single flush, one cache invalidation per
     * touched locale, and the same OverrideRemovedEvent per override as a unit removal.
     *
     * @param list<TranslationOverride> $overrides
     *
     * @return int the number of removed overrides
     */
    public function removeMany(array $overrides): int
    {
        return $this->saveAndRemoveMany([], $overrides)[1];
    }

    /**
     * Stages the removal of the pending suggestions these overrides supersede; the caller's
     * flush commits it with the overrides, in the transaction that holds the row locks. The
     * event is dispatched right away, like a reviewer's rejection, so the listeners still
     * see the full row — before the commit, then.
     *
     * @param list<TranslationOverride> $overrides
     */
    private function closeSupersededSuggestions(array $overrides): void
    {
        /** @var array<string, array{locale: string, scope: string, catalogues: array<string, array<string, true>>}> $written */
        $written = [];

        foreach ($overrides as $override) {
            $group = &$written[$override->getLocale()."\0".$override->getScope()];
            $group ??= ['locale' => $override->getLocale(), 'scope' => $override->getScope(), 'catalogues' => []];
            $group['catalogues'][$override->getCatalogue()][$override->getKey()] = true;
            unset($group);
        }

        $author = null;

        foreach ($written as ['locale' => $locale, 'scope' => $scope, 'catalogues' => $catalogues]) {
            $keys = array_merge(...array_map(array_keys(...), array_values($catalogues)));

            foreach ($this->suggestionRepository->lockPendingByKeys($locale, $scope, array_map(strval(...), $keys)) as $suggestion) {
                // Pending in the database, approved in memory: the suggestion this very
                // transaction approves (the identity map hands back the same entity) — the
                // source of the write, not superseded by it.
                if (!$suggestion->isPending() || !isset($catalogues[$suggestion->getCatalogue()][$suggestion->getKey()])) {
                    continue;
                }

                $author ??= $this->authorProvider->getAuthorIdentifier() ?? 'system';
                $suggestion->reject($author);
                $this->eventDispatcher->dispatch(new SuggestionRejectedEvent($suggestion, SuggestionRejectedEvent::SUPERSEDED_BY_OVERRIDE));
                $this->suggestionRepository->removeDeferred($suggestion);
            }
        }
    }

    /** @return list<\Closure(): void> */
    private function takeDeferredEffects(): array
    {
        return $this->deferredEffects ?? [];
    }

    /** @param \Closure(): void $effect */
    private function applyEffect(\Closure $effect): void
    {
        if (null === $this->deferredEffects) {
            $effect();

            return;
        }

        $this->deferredEffects[] = $effect;
    }

    /**
     * @throws TranslationKeyTooLongException
     * @throws InvalidOverrideException
     */
    private function assertFits(string $key, string $catalogue, string $locale, string $scope): void
    {
        if (!$this->acceptsKey($key)) {
            throw new TranslationKeyTooLongException($key);
        }

        $this->assertStorable($catalogue, $locale, $scope);
    }

    /**
     * The checks a write makes on everything but the key — for a caller that has no key
     * yet: the generation runs them before it pays the provider, so that it never bills
     * translations the tables cannot hold, or that no approval could ever apply.
     *
     * @throws InvalidOverrideException when the locale, the catalogue or the scope cannot be stored
     */
    public function assertStorable(string $catalogue, string $locale, string $scope = ''): void
    {
        // The cache key and the runtime lookup are built from the locale: one they reject
        // would store an override nothing ever reads, then fail its cache invalidation.
        if (\strlen($locale) > TranslationOverride::MAX_LOCALE_LENGTH || 1 !== preg_match(LocaleFallback::PATTERN, $locale)) {
            throw new InvalidOverrideException(\sprintf('"%s" is not a valid locale code.', $locale));
        }

        // Well formed is not enough: the runtime turns a requested "pt-BR" into "pt_BR" and
        // then looks overrides up byte for byte, so one stored as "pt-BR" — and, from a
        // generation, paid for — would never be served. Refused with the spelling to use
        // rather than rewritten silently: the caller has a typo to fix.
        if (str_contains($locale, '-')) {
            throw new InvalidOverrideException(\sprintf('"%s" is not written the way the runtime looks it up: write "%s".', $locale, str_replace('-', '_', $locale)));
        }

        // The casing is the host's own convention ("en_US", or "en_us"), kept as written. Only
        // a variant of an available locale is a typo: "FR_fr" where "fr_FR" exists would
        // never be served to the visitors of "fr_FR". A locale with no files yet — a new
        // language — has nothing to differ from.
        foreach ($this->locales->getAvailableLocales() as $available) {
            if ($available !== $locale && 0 === strcasecmp($available, $locale)) {
                throw new InvalidOverrideException(\sprintf('"%s" differs from the available locale "%s" only by its case: write "%s".', $locale, $available, $available));
            }
        }

        if ('' === $catalogue || \strlen($catalogue) > TranslationOverride::MAX_CATALOGUE_LENGTH) {
            throw new InvalidOverrideException(\sprintf('The catalogue identifier must hold 1 to %d characters, "%s" does not.', TranslationOverride::MAX_CATALOGUE_LENGTH, mb_substr($catalogue, 0, 60)));
        }

        if (\strlen($scope) > ScopeRegistry::MAX_SCOPE_LENGTH) {
            throw new InvalidOverrideException(\sprintf('The scope "%s…" exceeds %d characters.', mb_substr($scope, 0, 60), ScopeRegistry::MAX_SCOPE_LENGTH));
        }
    }
}
