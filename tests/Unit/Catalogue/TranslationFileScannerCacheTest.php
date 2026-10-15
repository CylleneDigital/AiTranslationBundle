<?php

declare(strict_types=1);

namespace CylleneDigital\AiTranslationBundle\Tests\Unit\Catalogue;

use CylleneDigital\AiTranslationBundle\Catalogue\TranslationFileScanner;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Filesystem\Filesystem;

/**
 * The parsed files outlive the process (translation_files_cache): a new scanner on the same
 * pool reads a file again only when its date of modification or its size changed. Each
 * "new process" below is a new scanner instance on a shared pool.
 */
final class TranslationFileScannerCacheTest extends TestCase
{
    private const int MTIME = 1_760_000_000;

    private string $dir;

    private ArrayAdapter $pool;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/cyllene_scanner_cache_'.bin2hex(random_bytes(4));
        mkdir($this->dir, 0o777, true);
        $this->pool = new ArrayAdapter();
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->dir);
    }

    public function testANewProcessIsServedTheFileWithoutParsingItAgain(): void
    {
        $this->write('messages.fr.json', '{"app.title":"one"}');
        self::assertSame(['app.title' => 'one'], $this->scanner()->getMessages('messages', 'fr'));

        // Same date, same size, other content: only a cached read still says "one".
        $this->write('messages.fr.json', '{"app.title":"two"}');

        self::assertSame(['app.title' => 'one'], $this->scanner()->getMessages('messages', 'fr'));
        self::assertSame(['app.title' => 'two'], (new TranslationFileScanner($this->dir))->getMessages('messages', 'fr'));
    }

    public function testAFileWhoseDateChangedIsParsedAgain(): void
    {
        $this->write('messages.fr.json', '{"app.title":"one"}');
        $this->scanner()->getMessages('messages', 'fr');

        $this->write('messages.fr.json', '{"app.title":"two"}', self::MTIME + 1);

        self::assertSame(['app.title' => 'two'], $this->scanner()->getMessages('messages', 'fr'));
    }

    /** Two writes within the same second: the size tells them apart. */
    public function testAFileWhoseSizeChangedIsParsedAgain(): void
    {
        $this->write('messages.fr.json', '{"app.title":"one"}');
        $this->scanner()->getMessages('messages', 'fr');

        $this->write('messages.fr.json', '{"app.title":"three"}');

        self::assertSame(['app.title' => 'three'], $this->scanner()->getMessages('messages', 'fr'));
    }

    public function testAnUnparseableFileIsStillReportedWhenServedFromTheCache(): void
    {
        $this->write('messages.fr.json', '{"app.title":');
        $this->scanner()->getMessages('messages', 'fr');

        $scanner = $this->scanner();

        self::assertSame([], $scanner->getMessages('messages', 'fr'));
        self::assertStringStartsWith('The file could not be parsed', $scanner->getIgnoredFiles()[$this->dir.'/messages.fr.json'] ?? '');
    }

    /** What a Messenger worker gets between two messages (kernel.reset). */
    public function testAResetScannerSeesAnEditedFileAndANewOne(): void
    {
        $this->write('messages.fr.json', '{"app.title":"one"}');
        $scanner = $this->scanner();
        $scanner->getMessages('messages', 'fr');

        $this->write('messages.fr.json', '{"app.title":"two"}', self::MTIME + 1);
        $this->write('messages.de.json', '{"app.title":"eins"}');
        self::assertSame(['app.title' => 'one'], $scanner->getMessages('messages', 'fr'), 'Memoized until the reset.');

        $scanner->reset();

        self::assertSame(['app.title' => 'two'], $scanner->getMessages('messages', 'fr'));
        self::assertSame(['de', 'fr'], $scanner->getLocales());
    }

    private function scanner(): TranslationFileScanner
    {
        return new TranslationFileScanner($this->dir, filesCache: $this->pool);
    }

    private function write(string $name, string $content, int $mtime = self::MTIME): void
    {
        file_put_contents($this->dir.'/'.$name, $content);
        touch($this->dir.'/'.$name, $mtime);
    }
}
