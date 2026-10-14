<?php

declare(strict_types=1);

namespace CylleneDigital\AiTranslationBundle\Tests\Functional;

use Symfony\Bridge\Doctrine\Middleware\Debug\DebugDataHolder;

/**
 * A view of several catalogues read at once: the same entries as one read per catalogue,
 * for two queries instead of two per catalogue.
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
}
