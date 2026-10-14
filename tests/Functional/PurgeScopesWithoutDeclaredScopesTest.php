<?php

declare(strict_types=1);

namespace CylleneDigital\AiTranslationBundle\Tests\Functional;

use Symfony\Component\Console\Command\Command;

/**
 * A host that declares no scopes accepts every code: the cleanup cannot tell a deleted scope
 * from a live one, and touches nothing, even with --force.
 */
final class PurgeScopesWithoutDeclaredScopesTest extends DatabaseTestCase
{
    public function testNothingIsPurgedWhenTheHostDeclaresNoScopes(): void
    {
        $this->writer()->save('app.dashboard', 'messages', 'fr', 'Tableau (b2b)', scope: 'b2b');

        $tester = $this->commandTester('cyllene:ai-translation:purge-scopes');
        $tester->execute(['--force' => true]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('declares no scopes', $tester->getDisplay());
        self::assertSame(1, $this->fetchInt('SELECT COUNT(*) FROM cyllene_translation_override'));
    }
}
