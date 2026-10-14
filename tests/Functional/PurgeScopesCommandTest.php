<?php

declare(strict_types=1);

namespace CylleneDigital\AiTranslationBundle\Tests\Functional;

use CylleneDigital\AiTranslationBundle\Tests\App\ScopedTestKernel;
use Symfony\Component\Console\Command\Command;

/**
 * The cleanup of the scopes the host no longer declares: read-only by default, a purge with
 * --force, the declared scopes and the global one never touched.
 */
final class PurgeScopesCommandTest extends DatabaseTestCase
{
    protected static function getKernelClass(): string
    {
        return ScopedTestKernel::class;
    }

    public function testItListsTheUndeclaredScopesWithoutTouchingThem(): void
    {
        $this->storeThreeScopes();

        $tester = $this->commandTester('cyllene:ai-translation:purge-scopes');
        $tester->execute([]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('GONE', $tester->getDisplay());
        self::assertStringNotContainsString('B2B', $tester->getDisplay());
        self::assertStringContainsString('--force', $tester->getDisplay());
        self::assertSame(3, $this->fetchInt('SELECT COUNT(*) FROM cyllene_translation_override'));
    }

    public function testForcePurgesOnlyTheUndeclaredScopes(): void
    {
        $this->storeThreeScopes();

        $tester = $this->commandTester('cyllene:ai-translation:purge-scopes');
        $tester->execute(['--force' => true]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('1 scope(s) purged', $tester->getDisplay());
        self::assertSame(0, $this->fetchInt("SELECT COUNT(*) FROM cyllene_translation_override WHERE scope = 'GONE'"));
        self::assertSame(2, $this->fetchInt("SELECT COUNT(*) FROM cyllene_translation_override WHERE scope IN ('', 'B2B')"));
    }

    public function testNothingIsListedWhenEveryStoredScopeIsDeclared(): void
    {
        $this->writer()->save('app.dashboard', 'messages', 'fr', 'Tableau (B2B)', scope: 'B2B');

        $tester = $this->commandTester('cyllene:ai-translation:purge-scopes');
        $tester->execute(['--force' => true]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('Nothing to purge', $tester->getDisplay());
        self::assertSame(1, $this->fetchInt('SELECT COUNT(*) FROM cyllene_translation_override'));
    }

    private function storeThreeScopes(): void
    {
        $this->writer()->save('app.dashboard', 'messages', 'fr', 'Tableau (global)');
        $this->writer()->save('app.dashboard', 'messages', 'fr', 'Tableau (B2B)', scope: 'B2B');
        $this->writer()->save('app.dashboard', 'messages', 'fr', 'Tableau (gone)', scope: 'GONE');
    }
}
