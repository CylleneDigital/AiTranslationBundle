<?php

declare(strict_types=1);

namespace CylleneDigital\AiTranslationBundle\Tests\Functional;

use CylleneDigital\AiTranslationBundle\Catalogue\CatalogueRegistry;
use CylleneDigital\AiTranslationBundle\Override\TranslationManager;
use CylleneDigital\AiTranslationBundle\Scope\ScopeRegistry;
use Symfony\Bridge\Doctrine\Middleware\Debug\DebugDataHolder;

/**
 * A view of several catalogues read at once: the same entries as one read per catalogue,
 * for two queries instead of two per catalogue. The facade reads the same, so that a host
 * decorating it sees these reads too.
 */
final class CataloguesBatchReadTest extends DatabaseTestCase
{
    public function testItReturnsWhatEachCatalogueReadsAlone(): void
    {
        $this->writer()->save('app.dashboard', 'messages', 'fr_FR', 'Tableau de bord (FR)');
        $this->writer()->save('product.add_to_cart', 'shop/Product/messages', 'fr', 'Ajouter (fr)');

        $batch = $this->overrides()->getTranslationsForCatalogues(['messages', 'shop/Product/messages'], 'fr_FR');

        self::assertSame(['messages', 'shop/Product/messages'], array_keys($batch));
        self::assertSame($this->overrides()->getTranslationsForCatalogue('messages', 'fr_FR'), $batch['messages']);
        self::assertSame($this->overrides()->getTranslationsForCatalogue('shop/Product/messages', 'fr_FR'), $batch['shop/Product/messages']);
        self::assertSame('Tableau de bord (FR)', $batch['messages']['app.dashboard']['override']);
        self::assertSame('Ajouter (fr)', $batch['shop/Product/messages']['product.add_to_cart']['inherited']);
        self::assertSame(['locale' => 'fr', 'scope' => ''], $batch['shop/Product/messages']['product.add_to_cart']['inheritedFrom']);
    }

    public function testEveryCatalogueAskedForIsInTheResult(): void
    {
        $batch = $this->overrides()->getTranslationsForCatalogues(['messages', 'no/such/catalogue', 'messages'], 'fr');

        self::assertSame(['messages', 'no/such/catalogue'], array_keys($batch));
        self::assertSame([], $batch['no/such/catalogue']);
        self::assertSame([], $this->overrides()->getTranslationsForCatalogues([], 'fr'));
    }

    public function testAViewCostsTwoQueriesWhateverItsNumberOfCatalogues(): void
    {
        $queries = self::getContainer()->get('doctrine.debug_data_holder');
        self::assertInstanceOf(DebugDataHolder::class, $queries);
        $queries->reset();

        $this->overrides()->getTranslationsForCatalogues(['messages', 'shop/Product/messages'], 'fr_FR');

        $executed = $queries->getData()['default'] ?? null;
        self::assertIsArray($executed);
        self::assertCount(2, $executed);
    }

    public function testTheFacadeReadsWhatTheReaderReads(): void
    {
        $this->writer()->save('product.add_to_cart', 'shop/Product/messages', 'fr', 'Ajouter (fr)');
        $catalogues = self::getContainer()->get(CatalogueRegistry::class);
        $scopes = self::getContainer()->get(ScopeRegistry::class);
        self::assertInstanceOf(CatalogueRegistry::class, $catalogues);
        self::assertInstanceOf(ScopeRegistry::class, $scopes);
        $manager = new TranslationManager($catalogues, $this->overrides(), $this->writer(), $scopes);

        self::assertSame(
            $this->overrides()->getTranslationsForCatalogues(['messages', 'shop/Product/messages'], 'fr_FR'),
            $manager->getTranslationsForCatalogues(['messages', 'shop/Product/messages'], 'fr_FR'),
        );
        self::assertSame('Ajouter (fr)', $manager->getInheritedValue('product.add_to_cart', 'shop/Product/messages', 'fr_FR'));
        self::assertSame('Ajouter (fr)', $manager->getBaselineValue('product.add_to_cart', 'shop/Product/messages', 'fr_FR'));
        self::assertNull($manager->getInheritedValue('app.dashboard', 'messages', 'fr_FR'));
        self::assertSame($this->overrides()->getFileValue('app.dashboard', 'messages', 'fr_FR'), $manager->getBaselineValue('app.dashboard', 'messages', 'fr_FR'));
    }
}
