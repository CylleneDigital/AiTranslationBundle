<?php

declare(strict_types=1);

namespace CylleneDigital\AiTranslationBundle\Tests\Functional;

use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The wiring of {@see \CylleneDigital\AiTranslationBundle\Override\TranslatorOverrideCacheListener}:
 * an override written in the middle of a process must be served by the very next
 * trans(), without waiting for the next HTTP request.
 *
 * This is the console/worker case. In HTTP, Symfony's LocaleAwareListener calls
 * setLocale() on every request, which drops the translator's in-memory map on its own
 * and hides the problem; a console command or a Messenger worker has no kernel.request,
 * so the map would otherwise keep answering with the state it loaded at the first
 * translation of the process.
 */
final class TranslatorOverrideCacheTest extends DatabaseTestCase
{
    public function testAnOverrideWrittenMidProcessIsServedByTheNextTrans(): void
    {
        $translator = $this->translator();

        // The first translation loads (and memoises) the overrides of "fr" — exactly
        // what any rendered label does before the write happens.
        self::assertSame('Tableau de bord', $translator->trans('app.dashboard', [], 'messages', 'fr'));

        $this->writer()->save('app.dashboard', 'messages', 'fr', 'Mon tableau de bord');

        self::assertSame('Mon tableau de bord', $translator->trans('app.dashboard', [], 'messages', 'fr'));
    }

    public function testRemovingAnOverrideMidProcessFallsBackToTheFileValue(): void
    {
        $translator = $this->translator();

        $this->writer()->save('app.dashboard', 'messages', 'fr', 'Mon tableau de bord');
        self::assertSame('Mon tableau de bord', $translator->trans('app.dashboard', [], 'messages', 'fr'));

        $this->writer()->remove('app.dashboard', 'messages', 'fr');

        self::assertSame('Tableau de bord', $translator->trans('app.dashboard', [], 'messages', 'fr'));
    }

    private function translator(): TranslatorInterface
    {
        /** @var TranslatorInterface $translator */
        $translator = self::getContainer()->get('translator');

        return $translator;
    }
}
