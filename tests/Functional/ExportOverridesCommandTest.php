<?php

declare(strict_types=1);

namespace CylleneDigital\AiTranslationBundle\Tests\Functional;

use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;

/**
 * The export-overrides wizard: on a TTY, format and destination are asked with the
 * defaults pre-filled — a plain enter behaves exactly like the bare invocation.
 */
final class ExportOverridesCommandTest extends DatabaseTestCase
{
    private string $customPath;

    private string $defaultPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->writer()->save('app.dashboard', 'messages', 'fr', 'Pilotage');

        $this->customPath = $this->temporaryPath('export.csv');

        /** @var string $projectDir */
        $projectDir = self::getContainer()->getParameter('kernel.project_dir');
        $this->defaultPath = $projectDir.'/var/export/translation_overrides.xlf';
    }

    protected function tearDown(): void
    {
        // Filesystem::remove(), not @unlink(): the error handler logs a silenced
        // warning anyway, which turns "the file was already gone" into test noise.
        (new Filesystem())->remove($this->defaultPath);

        parent::tearDown();
    }

    public function testAPlainEnterOnEachPromptKeepsTheDefaults(): void
    {
        $tester = $this->executeCommand([], ['', '']);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('translation_overrides.xlf', $tester->getDisplay());
        self::assertFileExists($this->defaultPath);
    }

    public function testTheWizardTakesAFormatAndACustomPath(): void
    {
        $tester = $this->executeCommand([], ['csv', $this->customPath]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertFileExists($this->customPath);
        // Behind the UTF-8 BOM that tells Excel the encoding.
        self::assertStringStartsWith("\xEF\xBB\xBFlocale,catalogue,translation_key", (string) file_get_contents($this->customPath));
    }

    /** "export-overrides file.csv" used to write XLIFF into the .csv: the extension names the format. */
    public function testTheFormatFollowsTheExtensionOfTheOutputFile(): void
    {
        $tester = $this->executeCommand(['output' => $this->customPath]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringStartsWith("\xEF\xBB\xBFlocale,catalogue,translation_key", (string) file_get_contents($this->customPath));
    }

    public function testAPathWithAKnownExtensionSkipsTheFormatQuestion(): void
    {
        $tester = $this->executeCommand(['output' => $this->customPath], ['unused']);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringNotContainsString('Format', $tester->getDisplay());
        self::assertStringStartsWith("\xEF\xBB\xBFlocale,catalogue,translation_key", (string) file_get_contents($this->customPath));
    }

    public function testAFormatContradictingTheExtensionIsRefused(): void
    {
        $tester = $this->executeCommand(['output' => $this->customPath, '--format' => 'xlf']);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('names the csv format', $tester->getDisplay());
        self::assertFileDoesNotExist($this->customPath);
    }

    public function testWithoutTtyNothingIsAskedAndTheDefaultsApply(): void
    {
        $tester = $this->executeCommand([]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringNotContainsString('Format', $tester->getDisplay());
        self::assertFileExists($this->defaultPath);
    }

    /**
     * The full backup is the restore artifact: exported with no filter, wiped, imported
     * back, it must give the very same rows — the scoped ones included, which the export
     * reads through a query of their own.
     */
    #[DataProvider('formats')]
    public function testAFullBackupRestoresTheGlobalAndTheScopedOverrides(string $format): void
    {
        $this->writer()->save('app.dashboard', 'messages', 'fr', 'Pilotage B2B', scope: 'b2b');
        $file = $this->temporaryPath('backup.'.$format);

        self::assertSame(Command::SUCCESS, $this->executeCommand(['output' => $file, '--format' => $format])->getStatusCode());

        $this->entityManager()->getConnection()->executeStatement('DELETE FROM cyllene_translation_override');

        $import = $this->commandTester('cyllene:ai-translation:import-overrides');
        self::assertSame(Command::SUCCESS, $import->execute(['file' => $file], ['interactive' => false]), $import->getDisplay());

        self::assertSame('Pilotage', $this->overrides()->getOverrideValue('app.dashboard', 'messages', 'fr'));
        self::assertSame('Pilotage B2B', $this->overrides()->getOverrideValue('app.dashboard', 'messages', 'fr', 'b2b'));
    }

    /** @return iterable<string, array{string}> */
    public static function formats(): iterable
    {
        yield 'xlf' => ['xlf'];
        yield 'csv' => ['csv'];
    }

    /**
     * @param array<string, string|bool> $input
     * @param list<string>               $inputs answers fed to the interactive prompts — their presence turns the run interactive
     */
    private function executeCommand(array $input, array $inputs = []): CommandTester
    {
        $tester = $this->commandTester('cyllene:ai-translation:export-overrides');

        if ([] !== $inputs) {
            $tester->setInputs($inputs);
        }

        $tester->execute($input, ['interactive' => [] !== $inputs]);

        return $tester;
    }
}
