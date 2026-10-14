<?php

declare(strict_types=1);

namespace CylleneDigital\AiTranslationBundle\Tests\Functional;

use CylleneDigital\AiTranslationBundle\Entity\GenerationLog;
use CylleneDigital\AiTranslationBundle\Entity\TranslationSuggestion;
use CylleneDigital\AiTranslationBundle\Event\OverrideRemovedEvent;
use CylleneDigital\AiTranslationBundle\Scope\ScopeParametersManager;
use CylleneDigital\AiTranslationBundle\Scope\ScopePurger;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * A scope that no longer exists (a sales channel deleted): its overrides, suggestions and
 * parameters go, the journal of what was paid for stays, and every other scope is untouched.
 */
final class ScopePurgeTest extends DatabaseTestCase
{
    public function testItRemovesWhatTheScopeHoldsAndKeepsTheJournal(): void
    {
        $this->writer()->save('app.dashboard', 'messages', 'fr', 'Tableau (B2B)', scope: 'B2B');
        $this->writer()->save('product.add_to_cart', 'shop/Product/messages', 'en', 'Add (B2B)', scope: 'B2B');
        $this->writer()->save('app.dashboard', 'messages', 'fr', 'Tableau (global)');
        $this->writer()->save('app.dashboard', 'messages', 'fr', 'Tableau (web)', scope: 'WEB');
        $this->parameters()->setPromptContext('B2B', 'Professional buyers.');
        $this->parameters()->setPromptContext('WEB', 'Consumers.');

        $pending = new TranslationSuggestion('app.welcome', 'messages', 'fr', 'Bienvenue', 'Welcome', 'en', 'gpt', 0.9, 'B2B');
        $errored = TranslationSuggestion::failed('app.other', 'messages', 'fr', 'Other', 'en', 'gpt', 'boom', 'B2B');
        $kept = new TranslationSuggestion('app.welcome', 'messages', 'fr', 'Bienvenue', 'Welcome', 'en', 'gpt', 0.9, 'WEB');
        foreach ([$pending, $errored, $kept, GenerationLog::success('messages', 'fr', 'en', 'gpt', 2, 'B2B')] as $row) {
            $this->entityManager()->persist($row);
        }
        $this->entityManager()->flush();

        $removed = [];
        $dispatcher = self::getContainer()->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);
        $dispatcher->addListener(OverrideRemovedEvent::class, function (OverrideRemovedEvent $event) use (&$removed): void {
            // Effects follow the commit: the row is gone when the listener hears of it.
            self::assertSame(0, $this->fetchInt(\sprintf("SELECT COUNT(*) FROM cyllene_translation_override WHERE scope = 'B2B' AND translation_key = '%s'", $event->key)));
            $removed[] = $event->locale.'|'.$event->key.'|'.$event->scope;
        });

        $purged = $this->purger()->purge('B2B');

        self::assertSame(2, $purged->overrides);
        self::assertSame(2, $purged->suggestions);
        self::assertTrue($purged->parameters);
        self::assertEqualsCanonicalizing(['fr|app.dashboard|B2B', 'en|product.add_to_cart|B2B'], $removed);

        self::assertSame(0, $this->fetchInt("SELECT COUNT(*) FROM cyllene_translation_override WHERE scope = 'B2B'"));
        self::assertSame(0, $this->fetchInt("SELECT COUNT(*) FROM cyllene_translation_suggestion WHERE scope = 'B2B'"));
        self::assertNull($this->parameters()->getPromptContext('B2B'));
        self::assertSame(1, $this->fetchInt("SELECT COUNT(*) FROM cyllene_translation_generation_log WHERE scope = 'B2B'"), 'The journal of what was paid for stays.');

        self::assertSame(2, $this->fetchInt("SELECT COUNT(*) FROM cyllene_translation_override WHERE scope IN ('', 'WEB')"));
        self::assertSame(1, $this->fetchInt("SELECT COUNT(*) FROM cyllene_translation_suggestion WHERE scope = 'WEB'"));
        self::assertSame('Consumers.', $this->parameters()->getPromptContext('WEB'));
    }

    public function testTheGlobalScopeIsRefused(): void
    {
        $this->writer()->save('app.dashboard', 'messages', 'fr', 'Tableau (global)');

        try {
            $this->purger()->purge('');
            self::fail('Purging the global scope was expected to be refused.');
        } catch (\InvalidArgumentException) {
        }

        self::assertSame(1, $this->fetchInt('SELECT COUNT(*) FROM cyllene_translation_override'));
    }

    public function testAScopeWithNothingStoredPurgesNothing(): void
    {
        self::assertTrue($this->purger()->purge('GONE')->isEmpty());
    }

    public function testItListsTheScopesItStoresSomethingFor(): void
    {
        $this->writer()->save('app.dashboard', 'messages', 'fr', 'Tableau (global)');
        $this->writer()->save('app.dashboard', 'messages', 'fr', 'Tableau (B2B)', scope: 'B2B');
        $this->parameters()->setPromptContext('WEB', 'Consumers.');
        $this->entityManager()->persist(new TranslationSuggestion('app.welcome', 'messages', 'fr', 'Bienvenue', 'Welcome', 'en', 'gpt', 0.9, 'B2B'));
        $this->entityManager()->flush();

        $stored = $this->purger()->findStoredScopes();

        self::assertSame(['B2B', 'WEB'], array_keys($stored));
        self::assertSame([1, 1, false], [$stored['B2B']->overrides, $stored['B2B']->suggestions, $stored['B2B']->parameters]);
        self::assertSame([0, 0, true], [$stored['WEB']->overrides, $stored['WEB']->suggestions, $stored['WEB']->parameters]);
    }

    private function purger(): ScopePurger
    {
        $purger = self::getContainer()->get(ScopePurger::class);
        self::assertInstanceOf(ScopePurger::class, $purger);

        return $purger;
    }

    private function parameters(): ScopeParametersManager
    {
        $parameters = self::getContainer()->get(ScopeParametersManager::class);
        self::assertInstanceOf(ScopeParametersManager::class, $parameters);

        return $parameters;
    }
}
