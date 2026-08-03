<?php

declare(strict_types=1);

namespace CylleneDigital\AiTranslationBundle\Tests\Unit\Override;

use CylleneDigital\AiTranslationBundle\Catalogue\CatalogueRegistry;
use CylleneDigital\AiTranslationBundle\Catalogue\LocaleProviderInterface;
use CylleneDigital\AiTranslationBundle\Catalogue\TranslationFileScanner;
use CylleneDigital\AiTranslationBundle\Entity\TranslationOverride;
use CylleneDigital\AiTranslationBundle\Override\AuthorProviderInterface;
use CylleneDigital\AiTranslationBundle\Override\OverrideReader;
use CylleneDigital\AiTranslationBundle\Override\OverrideWriter;
use CylleneDigital\AiTranslationBundle\Override\TranslationCacheManager;
use CylleneDigital\AiTranslationBundle\Override\TranslationKeyTooLongException;
use CylleneDigital\AiTranslationBundle\Override\TranslationManager;
use CylleneDigital\AiTranslationBundle\Repository\TranslationOverrideRepository;
use CylleneDigital\AiTranslationBundle\Repository\TranslationSuggestionRepository;
use CylleneDigital\AiTranslationBundle\Scope\NullScopeProvider;
use CylleneDigital\AiTranslationBundle\Scope\ScopeRegistry;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\EventDispatcher\EventDispatcher;

#[AllowMockObjectsWithoutExpectations]
final class TranslationManagerTest extends TestCase
{
    private TranslationOverrideRepository&MockObject $repository;

    protected function setUp(): void
    {
        $this->repository = $this->createMock(TranslationOverrideRepository::class);
    }

    public function testAKeyTooLongForTheTablesIsRefusedWithItsOwnMessage(): void
    {
        $manager = $this->createManager();
        $key = str_repeat('a', TranslationOverride::MAX_KEY_LENGTH + 1);

        $this->expectException(TranslationKeyTooLongException::class);
        $this->expectExceptionMessageMatches('/is 401 characters long, the maximum is 400/');

        $manager->saveOverride($key, 'messages', 'fr_FR', 'Valeur');
    }

    public function testTheBatchRefusesAnOversizedKeyBeforeWritingAnything(): void
    {
        // All or nothing: the acceptable entry of the batch must not be written either.
        $this->repository->expects(self::never())->method('saveDeferred');
        $this->repository->expects(self::never())->method('flush');

        $this->expectException(TranslationKeyTooLongException::class);

        $this->createManager()->saveOverrides([
            ['key' => 'app.ok', 'catalogue' => 'messages', 'locale' => 'fr_FR', 'value' => 'Valeur'],
            ['key' => str_repeat('a', TranslationOverride::MAX_KEY_LENGTH + 1), 'catalogue' => 'messages', 'locale' => 'fr_FR', 'value' => 'Valeur'],
        ]);
    }

    public function testCataloguesComeFromTheHostTranslationFiles(): void
    {
        $manager = $this->createManager();

        self::assertSame(
            [
                'admin/Dashboard/messages',
                'invalid',
                'messages',
                'shop/Checkout/checkout',
                'shop/Product/Review/messages',
                'shop/Product/messages',
                'shop/messages',
            ],
            $manager->getCatalogueIdentifiers(),
        );

        self::assertSame('shop', $manager->getCatalogue('shop/Product/messages')?->category);
        self::assertNull($manager->getCatalogue('unknown/messages'));
    }

    public function testTranslationsMergeFileEntriesWithOverrides(): void
    {
        $override = new TranslationOverride('product.add_to_cart', 'shop/Product/messages', 'fr_FR');
        $override->setValue('Ajouter au panier (modifié)');

        $orphan = new TranslationOverride('gone.key', 'shop/Product/messages', 'fr_FR');
        $orphan->setValue('Orpheline');

        $this->repository
            ->method('findByLocaleAndCatalogue')
            ->with('fr_FR', 'shop/Product/messages')
            ->willReturn([$override, $orphan]);

        $manager = $this->createManager();
        $translations = $manager->getTranslationsForCatalogue('shop/Product/messages', 'fr_FR');

        // The "fr" file is seen from fr_FR (language fallback chain).
        self::assertSame(
            ['original' => 'Ajouter au panier', 'override' => 'Ajouter au panier (modifié)', 'hasOverride' => true, 'inherited' => null, 'inheritedFrom' => null],
            $translations['product.add_to_cart'],
        );
        // An override whose key vanished from the file is still listed (cleanup candidate).
        self::assertSame(
            ['original' => null, 'override' => 'Orpheline', 'hasOverride' => true, 'inherited' => null, 'inheritedFrom' => null],
            $translations['gone.key'],
        );
    }

    public function testAKeyOnlyTranslatedInAnotherLanguageIsMissing(): void
    {
        $this->repository->method('findByLocaleAndCatalogue')->willReturn([]);

        $manager = $this->createManager();

        // "shop/Product/messages" only has a French file: nothing to show in English.
        self::assertSame([], $manager->getTranslationsForCatalogue('shop/Product/messages', 'en_US'));
    }

    public function testSaveOverrideCreatesTheOverrideWithItsOriginalValue(): void
    {
        $this->repository->method('findOneByKey')->willReturn(null);

        $savedOverride = null;
        $this->repository
            ->expects(self::once())
            ->method('save')
            ->willReturnCallback(static function (TranslationOverride $override) use (&$savedOverride): void {
                $savedOverride = $override;
            });

        $manager = $this->createManager();
        $result = $manager->saveOverride('app.key', 'shop/Product/messages', 'fr_FR', 'Nouvelle valeur', 'Ancienne');

        self::assertSame($result, $savedOverride);
        self::assertSame('Nouvelle valeur', $result->getValue());
        self::assertSame('Ancienne', $result->getOriginalValue());
        self::assertSame('shop/Product/messages', $result->getCatalogue());
    }

    public function testRemoveOverrideIsANoOpWhenNothingIsStored(): void
    {
        $this->repository->method('findOneByKey')->willReturn(null);
        $this->repository->expects(self::never())->method('remove');

        $manager = $this->createManager();
        $manager->removeOverride('app.key', 'messages', 'fr_FR');
    }

    public function testIsKnownScopeWithoutADeclaredList(): void
    {
        // No scope provider: nothing to validate against — any opaque code passes,
        // except an absurdly long one.
        $manager = $this->createManager();

        self::assertTrue($manager->isKnownScope(''));
        self::assertTrue($manager->isKnownScope('ANY_OPAQUE_CODE'));
        self::assertFalse($manager->isKnownScope(str_repeat('x', 65)));
    }

    public function testFindCataloguesForKeyScansFilesAndOverrides(): void
    {
        // A stray override in a catalogue the files no longer know counts as a
        // candidate too — the remove path must be able to reach it.
        $this->repository->method('findCataloguesOfKey')->willReturnCallback(
            static fn (string $key): array => 'product.add_to_cart' === $key ? ['gone/messages'] : [],
        );

        $manager = $this->createManager();

        self::assertSame(['shop/Product/messages', 'gone/messages'], $manager->findCataloguesForKey('product.add_to_cart'));
        self::assertSame([], $manager->findCataloguesForKey('nope.key'));
    }

    private function createWriter(AuthorProviderInterface $author): OverrideWriter
    {
        return new OverrideWriter(
            $this->repository,
            new TranslationCacheManager(new ArrayAdapter()),
            $author,
            new EventDispatcher(),
            $this->suggestionRepository(),
            $this->createStub(LocaleProviderInterface::class),
        );
    }

    private function createManager(?string $translationsDir = null): TranslationManager
    {
        $localeProvider = $this->createStub(LocaleProviderInterface::class);
        $localeProvider->method('getAvailableLocales')->willReturn(['fr_FR', 'en_US']);

        $author = $this->createStub(AuthorProviderInterface::class);
        $author->method('getAuthorIdentifier')->willReturn(null);

        $catalogues = new CatalogueRegistry(
            new TranslationFileScanner($translationsDir ?? \dirname(__DIR__, 2).'/Fixtures/translations'),
            $localeProvider,
        );

        return new TranslationManager(
            $catalogues,
            new OverrideReader($this->repository, $catalogues),
            $this->createWriter($author),
            new ScopeRegistry(new NullScopeProvider()),
        );
    }

    public function testAScopedBrowseShowsTheGlobalOverrideAsInherited(): void
    {
        $global = new TranslationOverride('product.add_to_cart', 'shop/Product/messages', 'fr_FR');
        $global->setValue('Ajouter au panier (global)');

        $scoped = new TranslationOverride('product.add_to_cart', 'shop/Product/messages', 'fr_FR', 'b2b');
        $scoped->setValue('Ajouter au panier (B2B)');

        $this->repository
            ->method('findByLocaleAndCatalogue')
            ->willReturnCallback(static fn (string $locale, string $catalogue, string $scope = ''): array => match ($scope) {
                '' => [$global],
                'b2b' => [$scoped],
                default => [],
            });
        // What the entry can inherit from — its own b2b row included, set apart by the reader.
        $this->repository
            ->method('findInheritable')
            ->willReturn([
                ['key' => 'product.add_to_cart', 'catalogue' => 'shop/Product/messages', 'locale' => 'fr_FR', 'scope' => '', 'value' => 'Ajouter au panier (global)'],
                ['key' => 'product.add_to_cart', 'catalogue' => 'shop/Product/messages', 'locale' => 'fr_FR', 'scope' => 'b2b', 'value' => 'Ajouter au panier (B2B)'],
            ]);

        $translations = $this->createManager()->getTranslationsForCatalogue('shop/Product/messages', 'fr_FR', 'b2b');

        self::assertSame(
            [
                'original' => 'Ajouter au panier',
                'override' => 'Ajouter au panier (B2B)',
                'hasOverride' => true,
                'inherited' => 'Ajouter au panier (global)',
                'inheritedFrom' => ['locale' => 'fr_FR', 'scope' => ''],
            ],
            $translations['product.add_to_cart'],
        );
    }

    /** The orphan criterion is SQL (see OverrideCountsTest): here, what it is asked. */
    public function testOrphansAreLookedUpOutsideTheScannedCatalogues(): void
    {
        $orphan = (new TranslationOverride('old.key', 'shop/Removed/messages', 'fr_FR'))->setValue('Disparu');
        $known = null;

        $this->repository->method('findOutsideCatalogues')->willReturnCallback(static function (array $catalogues) use (&$known, $orphan): array {
            $known = $catalogues;

            return [$orphan];
        });
        $this->repository->method('countOutsideCatalogues')->willReturn(1);

        $manager = $this->createManager();

        self::assertSame([$orphan], $manager->findOrphanOverrides());
        self::assertSame(1, $manager->countOrphanOverrides());
        self::assertSame($manager->getCatalogueIdentifiers(), $known);
    }

    public function testPurgeRemovesEveryOrphanInOneFlush(): void
    {
        $orphan = (new TranslationOverride('old.key', 'shop/Removed/messages', 'fr_FR'))->setValue('Disparu');
        $scopedOrphan = (new TranslationOverride('old.key', '@GoneTheme/messages', 'en_US', 'b2b'))->setValue('Gone');

        $this->repository->method('findOutsideCatalogues')->willReturn([$orphan, $scopedOrphan]);
        $this->repository->expects(self::exactly(2))->method('removeDeferred');
        $this->repository->expects(self::once())->method('flush');

        self::assertSame(2, $this->createManager()->purgeOrphanOverrides());
    }

    public function testPurgeIsANoOpWithoutOrphans(): void
    {
        $this->repository->method('findOutsideCatalogues')->willReturn([]);
        $this->repository->expects(self::never())->method('flush');

        self::assertSame(0, $this->createManager()->purgeOrphanOverrides());
    }

    /** An empty scan makes every override an orphan: far likelier a misconfiguration. */
    public function testPurgeDeletesNothingWhenNoCatalogueIsFound(): void
    {
        $this->repository->expects(self::never())->method('findOutsideCatalogues');
        $this->repository->expects(self::never())->method('flush');

        self::assertSame(0, $this->createManager(__DIR__.'/does-not-exist')->purgeOrphanOverrides());
    }

    public function testOverridesPerLocaleAreForwardedForTheGivenScope(): void
    {
        $this->repository->method('countPerLocale')->willReturnCallback(
            static fn (string $scope): array => 'b2b' === $scope ? ['fr_FR' => 2] : ['en_US' => 1, 'fr_FR' => 3],
        );

        $manager = $this->createManager();

        self::assertSame(['en_US' => 1, 'fr_FR' => 3], $manager->countOverridesPerLocale());
        self::assertSame(['fr_FR' => 2], $manager->countOverridesPerLocale('b2b'));
    }

    /** Transactions run their operation: the writer wraps every write in one. */
    private function suggestionRepository(): TranslationSuggestionRepository
    {
        $repository = $this->createStub(TranslationSuggestionRepository::class);
        $repository->method('transactional')->willReturnCallback(static fn (callable $operation): mixed => $operation());

        return $repository;
    }
}
