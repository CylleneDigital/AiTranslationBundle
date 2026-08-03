<?php

declare(strict_types=1);

namespace CylleneDigital\AiTranslationBundle\Tests\Functional;

use CylleneDigital\AiTranslationBundle\Entity\TranslationOverride;
use CylleneDigital\AiTranslationBundle\Entity\TranslationSuggestion;
use CylleneDigital\AiTranslationBundle\Suggestion\SuggestionReviewer;
use CylleneDigital\AiTranslationBundle\Tests\App\ScopedTestKernel;
use CylleneDigital\AiTranslationBundle\Transfer\OverrideImporter;

/**
 * A batch — an import, a batch approval — decides each entry against the state it leaves
 * behind: a parent the batch writes is what a scoped or regional entry inherits, and one
 * it reverts no longer counts. And it reads only the rows of its own keys.
 */
final class BatchBaselineTest extends DatabaseTestCase
{
    protected static function getKernelClass(): string
    {
        return ScopedTestKernel::class;
    }

    /** The scoped line repeats the old global value: once the global one changes, it differs. */
    public function testAScopedLineEqualToTheGlobalValueTheImportReplacesIsWritten(): void
    {
        $this->writer()->save('app.dashboard', 'messages', 'fr', 'A');

        $result = $this->importer()->import("locale,catalogue,translation_key,value,scope\nfr,messages,app.dashboard,B,\nfr,messages,app.dashboard,A,B2B\n", 'csv');

        self::assertSame(2, $result->imported);
        self::assertSame('B', $this->overrides()->getOverrideValue('app.dashboard', 'messages', 'fr'));
        self::assertSame('A', $this->overrides()->getOverrideValue('app.dashboard', 'messages', 'fr', 'B2B'));
    }

    /** The scoped line equals the stored global value, but the import replaces it: not a revert. */
    public function testAScopedOverrideIsNotRemovedForTheGlobalValueTheImportReplaces(): void
    {
        $this->writer()->save('app.dashboard', 'messages', 'fr', 'A');
        $this->writer()->save('app.dashboard', 'messages', 'fr', 'X', scope: 'B2B');

        $result = $this->importer()->import("locale,catalogue,translation_key,value,scope\nfr,messages,app.dashboard,B,\nfr,messages,app.dashboard,A,B2B\n", 'csv');

        self::assertSame(2, $result->imported);
        self::assertSame(0, $result->reverted);
        self::assertSame('A', $this->overrides()->getOverrideValue('app.dashboard', 'messages', 'fr', 'B2B'));
    }

    /** A global override the import reverts no longer counts as what the scoped line inherits. */
    public function testAGlobalOverrideTheImportRevertsIsNoLongerInherited(): void
    {
        $this->writer()->save('app.dashboard', 'messages', 'fr', 'A');

        $result = $this->importer()->import("locale,catalogue,translation_key,value,scope\nfr,messages,app.dashboard,Tableau de bord,\nfr,messages,app.dashboard,A,B2B\n", 'csv');

        self::assertSame(1, $result->reverted);
        self::assertSame(1, $result->imported);
        self::assertNull($this->overrides()->getOverrideValue('app.dashboard', 'messages', 'fr'));
        self::assertSame('A', $this->overrides()->getOverrideValue('app.dashboard', 'messages', 'fr', 'B2B'));
    }

    /** The same between a language and its regional locale, through a batch approval. */
    public function testARegionalSuggestionEqualToTheParentValueTheBatchReplacesIsWritten(): void
    {
        $this->writer()->save('app.dashboard', 'messages', 'fr', 'A');
        $parent = $this->store(new TranslationSuggestion('app.dashboard', 'messages', 'fr', 'B', 'Dashboard', 'en', 'stub', 0.9));
        $regional = $this->store(new TranslationSuggestion('app.dashboard', 'messages', 'fr_FR', 'A', 'Dashboard', 'en', 'stub', 0.9));

        self::assertSame([], $this->reviewer()->approveMany([$regional, $parent]));

        self::assertSame('B', $this->overrides()->getOverrideValue('app.dashboard', 'messages', 'fr'));
        self::assertSame('A', $this->overrides()->getOverrideValue('app.dashboard', 'messages', 'fr_FR'));
    }

    /** A host request importing three lines must not leave the whole locale managed. */
    public function testAnImportLoadsOnlyTheOverridesOfItsKeys(): void
    {
        $this->storeManyOverrides(2000);

        $this->importer()->import("locale,catalogue,translation_key,value,scope\nfr,messages,app.key1,Un,\nfr,messages,app.key2,Deux,B2B\nfr,messages,app.key3,Trois,\n", 'csv');

        self::assertLessThanOrEqual(3, \count($this->entityManager()->getUnitOfWork()->getIdentityMap()[TranslationOverride::class] ?? []));
        self::assertSame('Deux', $this->overrides()->getOverrideValue('app.key2', 'messages', 'fr', 'B2B'));
    }

    public function testABatchApprovalLoadsOnlyTheOverridesOfItsKeys(): void
    {
        $this->storeManyOverrides(2000);
        $suggestions = [];

        foreach ([1, 2, 3] as $number) {
            $suggestions[] = $this->store(new TranslationSuggestion('app.key'.$number, 'messages', 'fr', 'Nouveau '.$number, 'Source', 'en', 'stub', 0.9));
        }

        $this->entityManager()->clear();
        $suggestions = array_map(fn (TranslationSuggestion $suggestion): TranslationSuggestion => $this->entityManager()->find(TranslationSuggestion::class, $suggestion->getId()) ?? throw new \LogicException('Stored above.'), $suggestions);

        self::assertSame([], $this->reviewer()->approveMany($suggestions));
        self::assertLessThanOrEqual(3, \count($this->entityManager()->getUnitOfWork()->getIdentityMap()[TranslationOverride::class] ?? []));
        self::assertSame('Nouveau 2', $this->overrides()->getOverrideValue('app.key2', 'messages', 'fr'));
    }

    private function storeManyOverrides(int $count): void
    {
        $entityManager = $this->entityManager();

        for ($number = 1; $number <= $count; ++$number) {
            $override = new TranslationOverride('app.key'.$number, 'messages', 'fr');
            $override->setValue('Valeur '.$number);
            $entityManager->persist($override);

            if (0 === $number % 500) {
                $entityManager->flush();
                $entityManager->clear();
            }
        }

        $entityManager->flush();
        $entityManager->clear();
    }

    private function store(TranslationSuggestion $suggestion): TranslationSuggestion
    {
        $this->entityManager()->persist($suggestion);
        $this->entityManager()->flush();

        return $suggestion;
    }

    private function importer(): OverrideImporter
    {
        /** @var OverrideImporter $importer */
        $importer = self::getContainer()->get(OverrideImporter::class);

        return $importer;
    }

    private function reviewer(): SuggestionReviewer
    {
        /** @var SuggestionReviewer $reviewer */
        $reviewer = self::getContainer()->get(SuggestionReviewer::class);

        return $reviewer;
    }
}
