<?php

declare(strict_types=1);

namespace CylleneDigital\AiTranslationBundle\Tests\Functional;

use CylleneDigital\AiTranslationBundle\Event\OverrideSavedEvent;
use CylleneDigital\AiTranslationBundle\Override\OverrideReader;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * The import-overrides command's interactive entrance: on a TTY the missing file
 * argument becomes a validated prompt, without one it becomes an actionable error
 * instead of the bare console exception.
 */
final class ImportOverridesCommandTest extends DatabaseTestCase
{
    private string $csvFile;

    protected function setUp(): void
    {
        parent::setUp();

        $this->csvFile = $this->temporaryPath('import.csv');
        file_put_contents($this->csvFile, "locale,catalogue,translation_key,value\nfr,messages,app.dashboard,Pilotage\n");
    }

    public function testTheWizardAsksForTheFile(): void
    {
        $tester = $this->executeCommand([], [$this->csvFile]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('1 override(s) imported', $tester->getDisplay());

        /** @var OverrideReader $overrides */
        $overrides = self::getContainer()->get(OverrideReader::class);
        self::assertSame('Pilotage', $overrides->getOverrideValue('app.dashboard', 'messages', 'fr'));
    }

    /** As one saved in the editor or approved: the override keeps the file value it was created over. */
    public function testAnImportedOverrideRecordsTheFileValueAsItsOriginal(): void
    {
        $this->executeCommand(['file' => $this->csvFile]);

        self::assertSame('Tableau de bord', $this->entityManager()->getConnection()->fetchOne("SELECT original_value FROM cyllene_translation_override WHERE translation_key = 'app.dashboard'"));
    }

    public function testWithoutFileAndWithoutTtyTheErrorIsActionable(): void
    {
        $tester = $this->executeCommand([]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('A file to import is required.', $tester->getDisplay());
        self::assertStringContainsString('Usage: bin/console cyllene:ai-translation:import-overrides', $tester->getDisplay());
    }

    /**
     * Re-importing an untouched export changes nothing: no row is rewritten, so no
     * updated_at moves and no OverrideSavedEvent reaches the host's purges and webhooks.
     */
    public function testReimportingAnUntouchedExportWritesNothing(): void
    {
        $this->executeCommand(['file' => $this->csvFile]);
        $connection = $this->entityManager()->getConnection();
        $connection->executeStatement("UPDATE cyllene_translation_override SET updated_at = '2020-01-01 00:00:00'");

        $saved = 0;
        /** @var EventDispatcherInterface $dispatcher */
        $dispatcher = self::getContainer()->get('event_dispatcher');
        $dispatcher->addListener(OverrideSavedEvent::class, static function () use (&$saved): void {
            ++$saved;
        });

        $tester = $this->executeCommand(['file' => $this->csvFile]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getDisplay());
        // The console wraps long lines: compared with the whitespace collapsed.
        self::assertStringContainsString('Nothing to import — every entry matches what is already in place (1 unchanged).', (string) preg_replace('/\s+/', ' ', $tester->getDisplay()));
        self::assertStringNotContainsString('caches invalidated', $tester->getDisplay());
        self::assertSame(0, $saved);
        self::assertSame('2020-01-01 00:00:00', $connection->fetchOne('SELECT updated_at FROM cyllene_translation_override'));
    }

    /** The valid rows are applied, but a skipped one still fails a CI step. */
    public function testSkippedEntriesExitWithAnError(): void
    {
        file_put_contents($this->csvFile, "locale,catalogue,translation_key,value\nfr,messages,app.dashboard,Pilotage\nfr,no/such/catalogue,app.x,Oups\n");

        $tester = $this->executeCommand(['file' => $this->csvFile]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('1 override(s) imported', $tester->getDisplay());
        self::assertStringContainsString('1 invalid entry(ies) skipped', $tester->getDisplay());
    }

    /** An XLIFF 2.0 file parses fine but holds nothing this importer reads. */
    /** A skipped row is named — where it is in the file, its key, why — instead of a bare count. */
    public function testEachSkippedEntryIsNamedWithItsReason(): void
    {
        file_put_contents($this->csvFile, implode("\n", [
            'locale,catalogue,translation_key,value',
            'fr,messages,app.dashboard,Pilotage',
            'fr,no/such/catalogue,app.x,Oups',
            'fr,messages,,Sans clé',
            'xx,messages,app.dashboard,Locale inconnue',
            'fr,messages,app.welcome,"{count, plural, other {#"',
        ])."\n");

        $tester = $this->executeCommand(['file' => $this->csvFile]);
        $display = (string) preg_replace('/\s+/', ' ', $tester->getDisplay());

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('4 invalid entry(ies) skipped', $display);
        self::assertStringContainsString('row 3 — app.x: unknown catalogue "no/such/catalogue"', $display);
        self::assertStringContainsString('row 4: a locale, a catalogue and a key are required', $display);
        self::assertStringContainsString('row 5 — app.dashboard: unknown locale "xx"', $display);
        self::assertStringContainsString('row 6 — app.welcome: the value does not match the catalogue syntax', $display);
    }

    public function testAFileWithNoRecognisedEntryExitsWithAnError(): void
    {
        $xliff = $this->temporaryPath('import.xlf');
        file_put_contents($xliff, '<?xml version="1.0"?><xliff xmlns="urn:oasis:names:tc:xliff:document:2.0" version="2.0" srcLang="en" trgLang="fr"><file id="f1"><unit id="u1"><segment><source>a</source><target>b</target></segment></unit></file></xliff>');

        $tester = $this->executeCommand(['file' => $xliff]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('No entry recognised', $tester->getDisplay());
    }

    /**
     * @param array<string, string|bool> $input
     * @param list<string>               $inputs answers fed to the interactive prompts — their presence turns the run interactive
     */
    private function executeCommand(array $input, array $inputs = []): CommandTester
    {
        $tester = $this->commandTester('cyllene:ai-translation:import-overrides');

        if ([] !== $inputs) {
            $tester->setInputs($inputs);
        }

        $tester->execute($input, ['interactive' => [] !== $inputs]);

        return $tester;
    }
}
