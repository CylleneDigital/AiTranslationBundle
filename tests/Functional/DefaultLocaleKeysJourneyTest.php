<?php

declare(strict_types=1);

namespace CylleneDigital\AiTranslationBundle\Tests\Functional;

use Symfony\Component\Console\Command\Command;

/**
 * The default-locale-keys journey through the hub (default locale: en): the translatable keys per
 * catalogue, the catalogue narrowing, and the drift perspective.
 */
final class DefaultLocaleKeysJourneyTest extends HubTestCase
{
    private const string MENU = 'Inspect the default locale keys and drift';

    public function testListsTheDefaultLocaleKeysPerCatalogue(): void
    {
        $tester = $this->runHub([
            self::MENU,
            '', // Default locale: resolved default (en)
            '', // What do you want to see? the keys (default)
            '', // Catalogue: (all catalogues)
        ]);
        $display = $tester->getDisplay();

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('default locale en', $display);

        foreach (['app.dashboard', 'app.welcome', 'dashboard.saved', 'product.add_to_cart', 'product.out_of_stock'] as $key) {
            self::assertStringContainsString($key, $display);
        }

        self::assertStringContainsString('5 key(s) in 3 catalogue(s).', $display);
    }

    public function testTheCatalogueFilterNarrowsTheListing(): void
    {
        $display = $this->runHub([
            self::MENU,
            '',
            '',
            'messages', // Catalogue
        ])->getDisplay();

        self::assertStringContainsString('app.welcome', $display);
        self::assertStringNotContainsString('product.add_to_cart', $display);
        self::assertStringContainsString('2 key(s) in 1 catalogue(s).', $display);
    }

    public function testTheDriftPerspectiveNamesTheKeysTheDefaultLocaleLacks(): void
    {
        $tester = $this->runHub([
            self::MENU,
            '',
            'The drift: keys another locale carries beyond the default locale',
            '', // Locale to compare: fr (the only other one)
            '', // Catalogue: (all catalogues)
        ]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('No key in fr beyond the default locale', $tester->getDisplay());

        // A stray override — a key the default locale does not know — is exactly what this
        // perspective exists to surface.
        $this->writer()->save('app.only_fr', 'messages', 'fr', 'Clé fantôme');

        $display = $this->runHub([
            self::MENU,
            '',
            'The drift: keys another locale carries beyond the default locale',
            '',
            '',
        ])->getDisplay();

        self::assertStringContainsString('Keys in fr beyond the default locale en', $display);
        self::assertStringContainsString('app.only_fr', $display);
        self::assertStringContainsString('1 key(s) in 1 catalogue(s).', $display);
    }
}
