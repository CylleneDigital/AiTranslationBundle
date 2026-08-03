<?php

declare(strict_types=1);

namespace CylleneDigital\AiTranslationBundle\Tests\Unit\Command;

use CylleneDigital\AiTranslationBundle\Command\CoverageDisplay;
use CylleneDigital\AiTranslationBundle\Coverage\LocaleCoverage;
use CylleneDigital\AiTranslationBundle\Entity\TranslationSuggestion;
use CylleneDigital\AiTranslationBundle\Repository\TranslationSuggestionRepository;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * The rendering shared by the CI gate command and the interactive journey — the reason
 * it is a service rather than two private methods is that the two displays must not
 * drift apart, so it is worth pinning what it prints.
 */
final class CoverageDisplayTest extends TestCase
{
    public function testTheTableShowsOneRowPerLocaleWithItsPercentage(): void
    {
        $output = new BufferedOutput();

        $this->display()->table($this->io($output), [
            new LocaleCoverage('fr', 90, 10, 3),
            new LocaleCoverage('de', 0, 50, 0),
        ]);

        $rendered = $output->fetch();

        self::assertStringContainsString('90.0 %', $rendered);
        self::assertStringContainsString('0.0 %', $rendered);
        self::assertStringContainsString('Pending review', $rendered);
        // The pending count is what tells a reader the gap is already being worked on.
        self::assertMatchesRegularExpression('/\bfr\s+90\s+10\s+3\s+90\.0 %/', $rendered);
    }

    /** sprintf()'s %f follows LC_NUMERIC: on a French server it would print "90,0 %". */
    public function testThePercentageIgnoresTheNumericLocale(): void
    {
        $previous = setlocale(\LC_NUMERIC, '0');

        if (false === setlocale(\LC_NUMERIC, 'fr_FR.UTF-8', 'fr_FR.utf8', 'de_DE.UTF-8', 'de_DE.utf8')) {
            self::markTestSkipped('No locale with a decimal comma is installed.');
        }

        try {
            $output = new BufferedOutput();
            $this->display()->table($this->io($output), [new LocaleCoverage('fr', 90, 10, 3)]);

            self::assertStringContainsString('90.0 %', $output->fetch());
        } finally {
            setlocale(\LC_NUMERIC, false !== $previous ? $previous : 'C');
        }
    }

    public function testTheMissingKeysAreGroupedByCatalogue(): void
    {
        $output = new BufferedOutput();

        $this->display()->missingKeys($this->io($output), [
            new LocaleCoverage('fr', 1, 2, 0, ['shop/Product/messages' => ['product.name', 'product.price']]),
        ], '');

        $rendered = $output->fetch();

        self::assertStringContainsString('Missing in fr (2)', $rendered);
        self::assertStringContainsString('shop/Product/messages', $rendered);
        self::assertStringContainsString('product.name', $rendered);
        self::assertStringContainsString('product.price', $rendered);
    }

    /**
     * A key with a suggestion already awaiting review is work in flight: flagging it is
     * what stops a translator handoff from duplicating a review in progress.
     */
    public function testAKeyWithAPendingSuggestionIsFlagged(): void
    {
        $pending = new TranslationSuggestion('product.name', 'shop/Product/messages', 'fr', 'Nom', 'Name', 'en', 'gpt', 0.9);

        $output = new BufferedOutput();

        $this->display($pending)->missingKeys($this->io($output), [
            new LocaleCoverage('fr', 1, 2, 1, ['shop/Product/messages' => ['product.name', 'product.price']]),
        ], '');

        $rendered = $output->fetch();

        self::assertStringContainsString('product.name (pending review)', $rendered);
        self::assertStringNotContainsString('product.price (pending review)', $rendered);
    }

    public function testALocaleWithoutMissingKeysPrintsNothing(): void
    {
        $output = new BufferedOutput();

        $this->display()->missingKeys($this->io($output), [new LocaleCoverage('fr', 100, 0, 0)], '');

        self::assertSame('', $output->fetch());
    }

    private function display(TranslationSuggestion ...$pending): CoverageDisplay
    {
        $repository = $this->createStub(TranslationSuggestionRepository::class);
        $repository->method('findPending')->willReturn(array_values($pending));

        return new CoverageDisplay($repository);
    }

    private function io(BufferedOutput $output): SymfonyStyle
    {
        return new SymfonyStyle(new ArrayInput([]), $output);
    }
}
