<?php

declare(strict_types=1);

namespace CylleneDigital\AiTranslationBundle\Tests\Functional;

use CylleneDigital\AiTranslationBundle\Catalogue\CatalogueRegistry;
use CylleneDigital\AiTranslationBundle\Entity\TranslationSuggestion;
use CylleneDigital\AiTranslationBundle\Suggestion\SuggestionReviewer;
use CylleneDigital\AiTranslationBundle\Tests\App\DashedLocaleTestKernel;
use Symfony\Component\Console\Command\Command;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * "pt-BR", as the files, a URL or a translation tool write it, is the locale the bundle
 * stores as "pt_BR": the generation accepts it, the override lands under "pt_BR", and a
 * request for "pt-BR" is served it.
 */
final class DashedLocaleTest extends DatabaseTestCase
{
    protected static function getKernelClass(): string
    {
        return DashedLocaleTestKernel::class;
    }

    public function testTheScannedLocaleUsesTheUnderscore(): void
    {
        /** @var CatalogueRegistry $catalogues */
        $catalogues = self::getContainer()->get(CatalogueRegistry::class);

        self::assertSame(['en', 'pt_BR'], $catalogues->getAvailableLocales());
        self::assertSame('Painel', $catalogues->getOriginalValue('app.dashboard', 'messages', 'pt_BR'));
    }

    public function testADashedTargetIsGeneratedStoredUnderscoredAndServed(): void
    {
        $this->answerProviderCallsInFrench();

        $tester = $this->commandTester('cyllene:ai-translation:generate');
        $tester->execute(['--target' => ['pt-BR'], '--source' => 'en'], ['interactive' => false]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getDisplay());

        /** @var TranslationSuggestion $suggestion */
        $suggestion = $this->entityManager()->getRepository(TranslationSuggestion::class)->findOneBy(['key' => 'app.welcome']);
        self::assertSame('pt_BR', $suggestion->getLocale(), 'Only the missing key, under the stored spelling.');

        /** @var SuggestionReviewer $reviewer */
        $reviewer = self::getContainer()->get(SuggestionReviewer::class);
        $reviewer->approve($suggestion);

        self::assertSame('FR Welcome to the shop', $this->overrides()->getOverrideValue('app.welcome', 'messages', 'pt_BR'));

        /** @var TranslatorInterface $translator */
        $translator = self::getContainer()->get('translator');
        self::assertSame('FR Welcome to the shop', $translator->trans('app.welcome', [], 'messages', 'pt-BR'));
        self::assertSame('FR Welcome to the shop', $translator->trans('app.welcome', [], 'messages', 'pt_BR'));
    }

    /** A host whose own convention is lowercase keeps it: its overrides are stored and served as written. */
    public function testALowercaseRegionalLocaleIsStoredAndServedAsWritten(): void
    {
        $this->writer()->save('app.dashboard', 'messages', 'en_us', 'Dashboard (us)');

        /** @var TranslatorInterface $translator */
        $translator = self::getContainer()->get('translator');
        self::assertSame('Dashboard (us)', $translator->trans('app.dashboard', [], 'messages', 'en_us'));
    }
}
