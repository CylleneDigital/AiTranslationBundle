<?php

declare(strict_types=1);

namespace CylleneDigital\AiTranslationBundle\Tests\Unit\Catalogue;

use CylleneDigital\AiTranslationBundle\Catalogue\TranslationCatalogue;
use CylleneDigital\AiTranslationBundle\Catalogue\TranslationFileScanner;
use PHPUnit\Framework\TestCase;

final class TranslationFileScannerTest extends TestCase
{
    private TranslationFileScanner $scanner;

    protected function setUp(): void
    {
        $this->scanner = new TranslationFileScanner(\dirname(__DIR__, 2).'/Fixtures/translations');
    }

    public function testCataloguesAreDiscoveredWithTheThreeNavigationLevels(): void
    {
        $catalogues = [];
        foreach ($this->scanner->getCatalogues() as $catalogue) {
            $catalogues[$catalogue->identifier] = $catalogue;
        }

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
            array_keys($catalogues),
        );

        // Root file → implicit category/domain.
        self::assertSame(TranslationCatalogue::DEFAULT_SEGMENT, $catalogues['messages']->category);
        self::assertSame(TranslationCatalogue::DEFAULT_SEGMENT, $catalogues['messages']->domain);
        self::assertSame('messages', $catalogues['messages']->type);

        // Single folder level → the folder is the category, the domain is implicit.
        self::assertSame('shop', $catalogues['shop/messages']->category);
        self::assertSame(TranslationCatalogue::DEFAULT_SEGMENT, $catalogues['shop/messages']->domain);

        // Two levels → the plain convention.
        self::assertSame('shop', $catalogues['shop/Product/messages']->category);
        self::assertSame('Product', $catalogues['shop/Product/messages']->domain);

        // Deeper nesting → the extra levels stay inside the domain.
        self::assertSame('shop', $catalogues['shop/Product/Review/messages']->category);
        self::assertSame('Product/Review', $catalogues['shop/Product/Review/messages']->domain);
        self::assertSame('Product > Review', $catalogues['shop/Product/Review/messages']->getDomainLabel());

        // The file name type is preserved even when it is not "messages".
        self::assertSame('checkout', $catalogues['shop/Checkout/checkout']->type);
    }

    public function testMessagesMergeTheLanguageChainWithIntlIcuPrecedence(): void
    {
        $messages = $this->scanner->getMessages('messages', 'en_US');

        // en_US wins over en; +intl-icu wins over the plain file of the same locale.
        self::assertSame('A from en_US', $messages['app.a']);
        self::assertSame('B from icu', $messages['app.b']);
        self::assertArrayNotHasKey('app.only_in_french', $messages);

        // Requesting the short locale must not see the more specific en_US values.
        self::assertSame('A from en', $this->scanner->getMessages('messages', 'en')['app.a']);
    }

    public function testNoCrossLanguageFallback(): void
    {
        $messages = $this->scanner->getMessages('messages', 'fr_FR');

        self::assertSame(['app.only_in_french' => 'Seulement en français'], $messages);
    }

    public function testAMissingKeyFollowsTheFormatOfTheLocaleThatDeclaresIt(): void
    {
        // Declared ICU in en only, absent from every fr file: the fr value being
        // written must be ICU too — the runtime serves the en variant through the
        // fallback chain, and refusing an ICU translation for an ICU key is backwards.
        self::assertTrue($this->scanner->usesIntlIcu('app.b', 'messages', 'fr_FR'));

        // Declared in plain files only: stays legacy, missing in fr or not.
        self::assertFalse($this->scanner->usesIntlIcu('app.a', 'messages', 'fr_FR'));

        // Declared in the requested locale itself (plain file): that locale's own
        // format wins over any other locale's variant.
        self::assertFalse($this->scanner->usesIntlIcu('app.only_in_french', 'messages', 'fr_FR'));

        // The locale-matching ICU declaration keeps winning as before.
        self::assertTrue($this->scanner->usesIntlIcu('app.b', 'messages', 'en_US'));
    }

    public function testXliffAndJsonFilesAreInterpreted(): void
    {
        self::assertSame(['checkout.pay' => 'Pay now'], $this->scanner->getMessages('shop/Checkout/checkout', 'en_US'));
        self::assertSame(['dashboard.title' => 'Dashboard'], $this->scanner->getMessages('admin/Dashboard/messages', 'en'));
    }

    public function testUnknownCatalogueYieldsNoMessages(): void
    {
        self::assertNull($this->scanner->getCatalogue('nope/messages'));
        self::assertSame([], $this->scanner->getMessages('nope/messages', 'fr_FR'));
    }

    public function testUninterpretableFilesAreReportedNotSilentlyIgnored(): void
    {
        $ignored = $this->scanner->getIgnoredFiles();

        $paths = array_map(basename(...), array_keys($ignored));
        self::assertContains('notes.txt', $paths);
        self::assertContains('broken.yaml', $paths);

        // A well-named file with broken content is reported when it gets read.
        self::assertSame([], $this->scanner->getMessages('invalid', 'en'));
        $paths = array_map(basename(...), array_keys($this->scanner->getIgnoredFiles()));
        self::assertContains('invalid.en.yaml', $paths);
    }

    public function testMissingDirectoryYieldsNoCatalogues(): void
    {
        $scanner = new TranslationFileScanner('/nonexistent/translations');

        self::assertSame([], $scanner->getCatalogues());
        self::assertSame([], $scanner->getIgnoredFiles());
    }

    public function testAdditionalRootsAreNamespacedWithTheirLabel(): void
    {
        $fixtures = \dirname(__DIR__, 2).'/Fixtures';
        $scanner = new TranslationFileScanner($fixtures.'/translations', [
            'bootstrap' => $fixtures.'/theme-translations',
            // A theme without a translations directory is a normal situation.
            'ghost' => $fixtures.'/does-not-exist',
        ]);

        $catalogues = [];
        foreach ($scanner->getCatalogues() as $catalogue) {
            $catalogues[$catalogue->identifier] = $catalogue;
        }

        // The main root is untouched, the extra root appears under its "@label".
        self::assertArrayHasKey('shop/messages', $catalogues);
        self::assertArrayHasKey('@bootstrap/shop/messages', $catalogues);
        self::assertSame('@bootstrap', $catalogues['@bootstrap/shop/messages']->category);
        self::assertSame('shop', $catalogues['@bootstrap/shop/messages']->domain);
        self::assertSame('messages', $catalogues['@bootstrap/shop/messages']->type);

        self::assertSame(
            ['product.add_to_cart' => 'Ajouter (thème)', 'theme_only' => 'Clé thème'],
            $scanner->getMessages('@bootstrap/shop/messages', 'fr_FR'),
        );

        // A stored identifier round-trips to the same navigation levels.
        $rebuilt = TranslationCatalogue::fromIdentifier('@bootstrap/shop/messages');
        self::assertSame('@bootstrap', $rebuilt->category);
        self::assertSame('shop', $rebuilt->domain);
    }

    public function testMissingAdditionalRootsAreReported(): void
    {
        $fixtures = \dirname(__DIR__, 2).'/Fixtures';
        $scanner = new TranslationFileScanner($fixtures.'/translations', [
            'bootstrap' => $fixtures.'/theme-translations',
            'ghost' => $fixtures.'/does-not-exist',
        ]);

        self::assertSame(['ghost' => $fixtures.'/does-not-exist'], $scanner->getMissingAdditionalPaths());
    }
}
