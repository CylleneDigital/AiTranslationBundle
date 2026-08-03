<?php

declare(strict_types=1);

namespace CylleneDigital\AiTranslationBundle\Tests\Functional;

use Symfony\Component\Console\Command\Command;

/**
 * The guided export and import through the hub: the export filters and destination
 * asked instead of optioned, and the import previewing its counts before the write.
 */
final class TransferJourneysTest extends HubTestCase
{
    public function testAnEmptyStoreEndsTheExportPolitely(): void
    {
        $tester = $this->runHub(['Export the overrides to a file']);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('nothing to export', $tester->getDisplay());
    }

    public function testTheMenuRefusesAnOutputFileWhoseExtensionContradictsTheFormat(): void
    {
        $this->writer()->save('app.dashboard', 'messages', 'fr', 'Pilotage');
        $csvPath = $this->temporaryPath('menu_export.csv');
        $xlfPath = $this->temporaryPath('menu_export.xlf');

        $tester = $this->runHub([
            'Export the overrides to a file',
            'xlf',    // Format
            '',       // Locale: (all locales)
            '',       // Catalogue prefix: enter for all
            $csvPath, // Output file — refused, asked again
            $xlfPath,
        ]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('names the csv format', $tester->getDisplay());
        self::assertFileDoesNotExist($csvPath);
        self::assertStringContainsString('<xliff', (string) file_get_contents($xlfPath));
    }

    public function testExportThenImportRoundTripsThroughTheMenu(): void
    {
        $this->writer()->save('app.dashboard', 'messages', 'fr', 'Pilotage');
        $exportPath = $this->temporaryPath('hub_export.csv');

        $tester = $this->runHub([
            'Export the overrides to a file',
            'csv',       // Format
            '',          // Locale: (all locales)
            '',          // Catalogue prefix: enter for all
            $exportPath, // Output file
        ]);
        $display = $tester->getDisplay();

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        // The console wraps the long path: compared without the whitespace.
        self::assertStringContainsString('Overridesexportedto'.$exportPath, (string) preg_replace('/\s+/', '', $display));
        self::assertFileExists($exportPath);
        self::assertStringContainsString('app.dashboard', (string) file_get_contents($exportPath));

        // Wipe the store, then restore it through the guided import.
        $this->writer()->remove('app.dashboard', 'messages', 'fr');

        $tester = $this->runHub([
            'Import an overrides file',
            $exportPath,
            '', // Import these 1 override(s) now? yes
        ]);
        $display = $tester->getDisplay();

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('1 override(s) would be imported', $display);
        self::assertStringContainsString('1 override(s) imported — caches invalidated.', $display);
        self::assertSame('Pilotage', $this->overrides()->getOverrideValue('app.dashboard', 'messages', 'fr'));
    }

    public function testTheLocaleFilterNarrowsTheExport(): void
    {
        $this->writer()->save('app.dashboard', 'messages', 'fr', 'Pilotage');
        $this->writer()->save('app.dashboard', 'messages', 'en', 'Cockpit');
        $exportPath = $this->temporaryPath('hub_export.csv');

        $tester = $this->runHub([
            'Export the overrides to a file',
            'csv',
            'en', // Locale filter
            '',   // Catalogue prefix: all
            $exportPath,
        ]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        $content = (string) file_get_contents($exportPath);
        self::assertStringContainsString('Cockpit', $content);
        self::assertStringNotContainsString('Pilotage', $content);
    }

    public function testDecliningTheImportWritesNothing(): void
    {
        $this->writer()->save('app.dashboard', 'messages', 'fr', 'Pilotage');
        $exportPath = $this->temporaryPath('hub_export.csv');

        $this->runHub([
            'Export the overrides to a file',
            'csv',
            '',
            '',
            $exportPath,
        ]);

        $this->writer()->remove('app.dashboard', 'messages', 'fr');

        $tester = $this->runHub([
            'Import an overrides file',
            $exportPath,
            'no',
        ]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('Nothing imported.', $tester->getDisplay());
        self::assertNull($this->overrides()->getOverrideValue('app.dashboard', 'messages', 'fr'));
    }
}
