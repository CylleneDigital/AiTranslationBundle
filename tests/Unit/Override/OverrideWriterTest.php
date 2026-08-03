<?php

declare(strict_types=1);

namespace CylleneDigital\AiTranslationBundle\Tests\Unit\Override;

use CylleneDigital\AiTranslationBundle\Catalogue\LocaleProviderInterface;
use CylleneDigital\AiTranslationBundle\Event\OverrideSavedEvent;
use CylleneDigital\AiTranslationBundle\Override\InvalidOverrideException;
use CylleneDigital\AiTranslationBundle\Override\NullAuthorProvider;
use CylleneDigital\AiTranslationBundle\Override\OverrideWriter;
use CylleneDigital\AiTranslationBundle\Override\TranslationCacheManager;
use CylleneDigital\AiTranslationBundle\Repository\TranslationOverrideRepository;
use CylleneDigital\AiTranslationBundle\Repository\TranslationSuggestionRepository;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\EventDispatcher\EventDispatcher;

/**
 * Writes made inside a transaction must not announce themselves before it commits: the
 * host purges, notifies or audits on OverrideSavedEvent.
 */
final class OverrideWriterTest extends TestCase
{
    /** @var list<string> */
    private array $dispatched = [];

    public function testEffectsWaitForTheOperationToReturn(): void
    {
        $writer = $this->createWriter();

        $writer->withEffectsAfter(function () use ($writer): void {
            $writer->save('app.hello', 'messages', 'fr', 'Salut');
            self::assertSame([], $this->dispatched, 'Nothing is announced before the operation returns.');
        });

        self::assertSame(['app.hello'], $this->dispatched);
    }

    public function testEffectsAreDroppedWhenTheOperationThrows(): void
    {
        $writer = $this->createWriter();

        $thrown = null;

        try {
            $writer->withEffectsAfter(static function () use ($writer): void {
                $writer->save('app.hello', 'messages', 'fr', 'Salut');

                throw new \RuntimeException('rolled back');
            });
        } catch (\RuntimeException $e) {
            $thrown = $e->getMessage();
        }

        self::assertSame('rolled back', $thrown, 'The exception reaches the caller.');

        self::assertSame([], $this->dispatched);

        // And the writer is back to applying effects right away.
        $writer->save('app.bye', 'messages', 'fr', 'Salut');
        self::assertSame(['app.bye'], $this->dispatched);
    }

    private function createWriter(): OverrideWriter
    {
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(OverrideSavedEvent::class, function (OverrideSavedEvent $event): void {
            $this->dispatched[] = $event->override->getKey();
        });

        // A host whose files are "fr_FR" and, by its own convention, "en_us".
        $locales = $this->createStub(LocaleProviderInterface::class);
        $locales->method('getAvailableLocales')->willReturn(['en_us', 'fr', 'fr_FR']);

        return new OverrideWriter(
            $this->createStub(TranslationOverrideRepository::class),
            new TranslationCacheManager(new ArrayAdapter()),
            new NullAuthorProvider(),
            $dispatcher,
            $this->suggestionRepository(),
            $locales,
        );
    }

    /** @return iterable<string, array{string, string, string, string}> */
    public static function unstorableCoordinates(): iterable
    {
        yield 'malformed locale' => ['app.hello', 'messages', 'fr FR', ''];
        yield 'locale too long' => ['app.hello', 'messages', 'fr_'.str_repeat('X', 40), ''];
        yield 'empty catalogue' => ['app.hello', '', 'fr', ''];
        yield 'catalogue too long' => ['app.hello', str_repeat('c', 256), 'fr', ''];
        yield 'scope too long' => ['app.hello', 'messages', 'fr', str_repeat('s', 65)];
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function unstorableTargets(): iterable
    {
        yield 'malformed locale' => ['messages', 'fr.UTF8', ''];
        yield 'locale too long' => ['messages', 'fr_'.str_repeat('X', 40), ''];
        // Well formed, but never what the runtime looks up ("fr_FR", byte for byte):
        // stored, paid for, and never served.
        yield 'dash form' => ['messages', 'fr-FR', ''];
        // Variants of the available "fr_FR" and "en_us".
        yield 'reversed casing' => ['messages', 'FR_fr', ''];
        yield 'region lowercased' => ['messages', 'fr_fr', ''];
        yield 'region uppercased against the host convention' => ['messages', 'en_US', ''];
        yield 'empty catalogue' => ['', 'fr', ''];
        yield 'catalogue too long' => [str_repeat('c', 256), 'fr', ''];
        yield 'scope too long' => ['messages', 'fr', str_repeat('s', 65)];
    }

    /** The same rule as a write, for a caller that has no key yet: the generation, before it pays. */
    #[DataProvider('unstorableTargets')]
    public function testAnUnstorableTargetIsRefused(string $catalogue, string $locale, string $scope): void
    {
        $this->expectException(InvalidOverrideException::class);

        $this->createWriter()->assertStorable($catalogue, $locale, $scope);
    }

    public function testADashedLocaleIsRefusedWithTheFormToWrite(): void
    {
        $this->expectException(InvalidOverrideException::class);
        $this->expectExceptionMessage('write "pt_BR"');

        $this->createWriter()->assertStorable('messages', 'pt-BR');
    }

    public function testACaseVariantOfAnAvailableLocaleIsRefusedWithItsSpelling(): void
    {
        $this->expectException(InvalidOverrideException::class);
        $this->expectExceptionMessage('"FR_fr" differs from the available locale "fr_FR" only by its case: write "fr_FR".');

        $this->createWriter()->assertStorable('messages', 'FR_fr');
    }

    public function testAStorableTargetPasses(): void
    {
        $this->createWriter()->assertStorable('shop/Product/messages', 'zh_Hant_TW', 'b2b');
        $this->createWriter()->assertStorable('messages', 'es_419');
        $this->createWriter()->assertStorable('messages', 'fr');
        // The host's own casing convention, kept as written.
        $this->createWriter()->assertStorable('messages', 'en_us');
        // A new language, no file yet: nothing to differ from.
        $this->createWriter()->assertStorable('messages', 'DE_de');
        $this->addToAssertionCount(1);
    }

    /** Refused before the flush: a driver error there would close the host's entity manager. */
    #[DataProvider('unstorableCoordinates')]
    public function testAnOverrideTheTablesCannotHoldIsRefusedBeforeAnyWrite(string $key, string $catalogue, string $locale, string $scope): void
    {
        $repository = $this->createMock(TranslationOverrideRepository::class);
        $repository->expects(self::never())->method('save');
        $repository->expects(self::never())->method('flush');

        $writer = new OverrideWriter($repository, new TranslationCacheManager(new ArrayAdapter()), new NullAuthorProvider(), new EventDispatcher(), $this->suggestionRepository(), $this->createStub(LocaleProviderInterface::class));

        foreach ([
            static fn () => $writer->save($key, $catalogue, $locale, 'Salut', scope: $scope),
            static fn () => $writer->saveMany([['key' => $key, 'catalogue' => $catalogue, 'locale' => $locale, 'value' => 'Salut', 'scope' => $scope]]),
        ] as $write) {
            try {
                $write();
                self::fail('The override was expected to be refused.');
            } catch (InvalidOverrideException) {
            }
        }
    }

    /** Transactions run their operation: the writer wraps every write in one. */
    private function suggestionRepository(): TranslationSuggestionRepository
    {
        $repository = $this->createStub(TranslationSuggestionRepository::class);
        $repository->method('transactional')->willReturnCallback(static fn (callable $operation): mixed => $operation());

        return $repository;
    }
}
