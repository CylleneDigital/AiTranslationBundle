<?php

declare(strict_types=1);

namespace CylleneDigital\AiTranslationBundle\Tests\Unit\Catalogue;

use CylleneDigital\AiTranslationBundle\Catalogue\TranslationFileScanner;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Filesystem\Filesystem;

/**
 * The parsed files outlive the process (translation_files_cache): a new scanner on the same
 * pool reads a file again only when its content changed. Each "new process" below is a new
 * scanner instance on a shared pool.
 */
final class TranslationFileScannerCacheTest extends TestCase
{
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
        $this->scanner()->getMessages('messages', 'fr');

        // Only a read from the pool can return what the file does not say.
        $keys = array_keys($this->pool->getValues());
        self::assertCount(1, $keys);
        $item = $this->pool->getItem($keys[0]);
        $this->pool->save($item->set(['messages' => ['app.title' => 'from the pool'], 'error' => null]));

        self::assertSame(['app.title' => 'from the pool'], $this->scanner()->getMessages('messages', 'fr'));
    }

    /** A build that freezes the dates, a release deployed under the same path: same date, same size. */
    public function testAFileWhoseContentChangedIsParsedAgainWhateverItsDateAndSize(): void
    {
        $this->write('messages.fr.json', '{"app.title":"one"}');
        touch($this->dir.'/messages.fr.json', 1_760_000_000);
        $this->scanner()->getMessages('messages', 'fr');

        $this->write('messages.fr.json', '{"app.title":"two"}');
        touch($this->dir.'/messages.fr.json', 1_760_000_000);

        self::assertSame(['app.title' => 'two'], $this->scanner()->getMessages('messages', 'fr'));
    }

    /** A chmod or a chown changes neither the content nor the date: the error is not cached. */
    public function testAFileMadeReadableAgainIsReadByTheNextProcess(): void
    {
        $this->write('messages.fr.json', '{"app.title":"one"}');
        chmod($this->dir.'/messages.fr.json', 0o000);

        if (is_readable($this->dir.'/messages.fr.json')) {
            self::markTestSkipped('The file stays readable (running as root).');
        }

        $scanner = $this->scanner();
        self::assertSame([], $scanner->getMessages('messages', 'fr'));
        self::assertSame([$this->dir.'/messages.fr.json' => 'The file cannot be read: check its permissions.'], $scanner->getIgnoredFiles());

        chmod($this->dir.'/messages.fr.json', 0o644);
        $scanner = $this->scanner();

        self::assertSame(['app.title' => 'one'], $scanner->getMessages('messages', 'fr'));
        self::assertSame([], $scanner->getIgnoredFiles());
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

        $this->write('messages.fr.json', '{"app.title":"two"}');
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

    private function write(string $name, string $content): void
    {
        file_put_contents($this->dir.'/'.$name, $content);
    }
}
