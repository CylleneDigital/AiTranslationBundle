<?php

declare(strict_types=1);

namespace CylleneDigital\AiTranslationBundle\Catalogue;

use CylleneDigital\AiTranslationBundle\Locale\LocaleFallback;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Translation\Loader\JsonFileLoader;
use Symfony\Component\Translation\Loader\LoaderInterface;
use Symfony\Component\Translation\Loader\XliffFileLoader;
use Symfony\Component\Translation\Loader\YamlFileLoader;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Reads the host project's translations/ directory — the single source of the
 * catalogues this bundle exposes (the framework's and other bundles' catalogues
 * are deliberately out of scope). File names follow the Symfony convention parsed from the
 * right, so a type may contain dots: {type}(+intl-icu).{locale}.{ext} with ext one of
 * yaml/yml/xlf/xliff/json.
 *
 * Nothing is skipped silently: every file that cannot be interpreted (unknown
 * extension, unparseable name or content) is reported to the caller through
 * getIgnoredFiles().
 *
 * Everything is memoized for the lifetime of the service (one request / one CLI run):
 * browsing triggers several reads of the same catalogue in a row. Under that memo, each
 * parsed file is kept in a persistent pool (translation_files_cache), keyed by its path
 * and a hash of its content, so a new process reads and hashes the files instead of parsing
 * them again. The walk of the directories is never cached: a file added or removed is seen at
 * once. The memo is dropped between two Messenger messages (kernel.reset), so a worker
 * sees an edited file.
 */
final class TranslationFileScanner implements ResetInterface
{
    private const array SUPPORTED_EXTENSIONS = ['yaml', 'yml', 'xlf', 'xliff', 'json'];

    private const string INTL_ICU_SUFFIX = '+intl-icu';

    /** Part of every persistent cache key: bump it when what parseFile() returns changes. */
    private const int CACHE_FORMAT = 1;

    /** @var array<string, TranslationCatalogue>|null identifier => catalogue, sorted */
    private ?array $catalogues = null;

    /** @var array<string, list<array{path: string, locale: string, intlIcu: bool, extension: string}>> */
    private array $files = [];

    /** @var array<string, string> absolute path => human-readable reason */
    private array $ignoredFiles = [];

    /** @var array<string, array<string, string>> "identifier|locale" => key => value */
    private array $messagesCache = [];

    /**
     * @var array<string, array<string, string>> absolute path => key => value
     *
     * Per-FILE memo, on top of the per-(identifier, locale) one: usesIntlIcu() walks the
     * +intl-icu files key by key, and it is called once per entry by the value validator
     * — an import or a bulk approval used to re-parse the same files for every row
     */
    private array $fileCache = [];

    /** @var array<string, LoaderInterface> */
    private array $loaders = [];

    /**
     * @param array<string, string> $additionalPaths label => directory, browsed as "@label/…"
     * @param CacheInterface|null   $filesCache      null when translation_files_cache is off
     */
    public function __construct(
        #[Autowire(param: 'cyllene_digital_ai_translation.translations_path')]
        private readonly string $translationsPath,
        #[Autowire(param: 'cyllene_digital_ai_translation.additional_paths')]
        private readonly array $additionalPaths = [],
        private readonly ?CacheInterface $filesCache = null,
    ) {
    }

    public function reset(): void
    {
        $this->catalogues = null;
        $this->files = [];
        $this->ignoredFiles = [];
        $this->messagesCache = [];
        $this->fileCache = [];
    }

    /**
     * @return list<TranslationCatalogue>
     */
    public function getCatalogues(): array
    {
        $this->scan();

        return array_values($this->catalogues ?? []);
    }

    public function getCatalogue(string $identifier): ?TranslationCatalogue
    {
        $this->scan();

        return $this->catalogues[$identifier] ?? null;
    }

    /**
     * Every locale that appears in the scanned files — the default source of
     * {@see ScannedLocaleProvider} when the host has no locale registry of its own.
     *
     * @return list<string>
     */
    public function getLocales(): array
    {
        $this->scan();

        $locales = [];
        foreach ($this->files as $files) {
            foreach ($files as $file) {
                $locales[$file['locale']] = true;
            }
        }

        ksort($locales);

        return array_keys($locales);
    }

    /**
     * The configured additional roots whose directory does not exist. Scanning skips
     * them silently (a theme without translations is normal); the CLI surfaces them so
     * a mistyped additional_paths entry does not go unnoticed.
     *
     * @return array<string, string> label => configured path
     */
    public function getMissingAdditionalPaths(): array
    {
        $missing = [];

        foreach ($this->additionalPaths as $label => $path) {
            if (!is_dir(rtrim($path, '/'))) {
                $missing[$label] = $path;
            }
        }

        return $missing;
    }

    /**
     * Files found under translations/ that could not be interpreted — surfaced in the
     * caller instead of being silently ignored.
     *
     * @return array<string, string> absolute path => reason
     */
    public function getIgnoredFiles(): array
    {
        $this->scan();

        return $this->ignoredFiles;
    }

    /**
     * Messages of one catalogue seen from $locale, merging the files of the same
     * language chain: "fr" resources first, overridden by the more specific "fr_FR"
     * ones; within one locale the +intl-icu variant wins over the plain one (mirrors
     * the translator's own lookup order). No cross-language fallback: a key only
     * present in another language is genuinely missing here.
     *
     * @return array<string, string>
     */
    public function getMessages(string $identifier, string $locale): array
    {
        $cacheKey = $identifier.'|'.$locale;

        if (isset($this->messagesCache[$cacheKey])) {
            return $this->messagesCache[$cacheKey];
        }

        $this->scan();

        $candidates = [];

        foreach ($this->files[$identifier] ?? [] as $file) {
            if (LocaleFallback::covers($file['locale'], $locale)) {
                $candidates[] = $file;
            }
        }

        // Least specific first, plain before +intl-icu — the last merged wins.
        usort($candidates, static fn (array $a, array $b): int => [LocaleFallback::rank($a['locale']), $a['intlIcu']] <=> [LocaleFallback::rank($b['locale']), $b['intlIcu']]);

        $messages = [];
        foreach ($candidates as $file) {
            $messages = array_replace($messages, $this->loadFile($file));
        }

        return $this->messagesCache[$cacheKey] = $messages;
    }

    /**
     * Whether the translator formats this key with intl: mirrors Symfony's own lookup,
     * where a key present in an +intl-icu variant of the domain is formatted as an ICU
     * pattern, whatever file the plain value comes from. A key with no file in the
     * requested locale at all — a missing translation being written — follows the
     * format of the locale that declares it: living in an +intl-icu variant makes it
     * ICU, whatever locale that variant belongs to (the runtime serves that variant
     * through the fallback chain anyway).
     */
    public function usesIntlIcu(string $key, string $identifier, string $locale): bool
    {
        $this->scan();

        foreach ($this->files[$identifier] ?? [] as $file) {
            if (!$file['intlIcu']) {
                continue;
            }

            if (LocaleFallback::covers($file['locale'], $locale) && \array_key_exists($key, $this->loadFile($file))) {
                return true;
            }
        }

        if (\array_key_exists($key, $this->getMessages($identifier, $locale))) {
            return false;
        }

        foreach ($this->files[$identifier] ?? [] as $file) {
            if ($file['intlIcu'] && \array_key_exists($key, $this->loadFile($file))) {
                return true;
            }
        }

        return false;
    }

    private function scan(): void
    {
        if (null !== $this->catalogues) {
            return;
        }

        $this->catalogues = [];
        $this->scanRoot($this->translationsPath, '');

        // Additional roots (themes, shared packages…) are namespaced "@label/…" so
        // their identifiers can never collide with the main root's, and the label
        // surfaces as the top navigation level. A missing directory is skipped: a
        // theme without translations is a normal situation, not an error.
        foreach ($this->additionalPaths as $label => $path) {
            $this->scanRoot($path, '@'.$label);
        }

        $catalogues = $this->catalogues ?? [];
        ksort($catalogues);
        $this->catalogues = $catalogues;
    }

    private function scanRoot(string $rootPath, string $prefix): void
    {
        $root = rtrim($rootPath, '/');

        if (!is_dir($root)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
        );

        /** @var \SplFileInfo $fileInfo */
        foreach ($iterator as $fileInfo) {
            if (!$fileInfo->isFile() || str_starts_with($fileInfo->getFilename(), '.')) {
                continue;
            }

            $path = $fileInfo->getPathname();
            $parsed = $this->parseFilename($fileInfo->getFilename());

            if (\is_string($parsed)) {
                $this->ignoredFiles[$path] = $parsed;

                continue;
            }

            $relativeDir = trim(str_replace(\DIRECTORY_SEPARATOR, '/', substr(\dirname($path), \strlen($root))), '/');
            $relativeDir = trim($prefix.'/'.$relativeDir, '/');
            $catalogue = TranslationCatalogue::fromRelativeDir($relativeDir, $parsed['type']);

            $this->catalogues[$catalogue->identifier] ??= $catalogue;
            $this->files[$catalogue->identifier][] = [
                'path' => $path,
                'locale' => $parsed['locale'],
                'intlIcu' => $parsed['intlIcu'],
                'extension' => $parsed['extension'],
            ];
        }
    }

    /**
     * Parses {type}(+intl-icu).{locale}.{ext} from the right (the type itself may
     * contain dots). Returns the parsed parts, or the rejection reason as a string.
     *
     * @return array{type: string, locale: string, intlIcu: bool, extension: string}|string
     */
    private function parseFilename(string $filename): array|string
    {
        $parts = explode('.', $filename);

        if (\count($parts) < 3) {
            return 'The file name does not match the expected "{type}.{locale}.{extension}" convention.';
        }

        $extension = strtolower((string) array_pop($parts));

        if (!\in_array($extension, self::SUPPORTED_EXTENSIONS, true)) {
            return \sprintf('Unsupported extension "%s" (supported: %s).', $extension, implode(', ', self::SUPPORTED_EXTENSIONS));
        }

        $locale = (string) array_pop($parts);

        if (1 !== preg_match(LocaleFallback::PATTERN, $locale)) {
            return \sprintf('"%s" is not a valid locale code.', $locale);
        }

        $type = implode('.', $parts);
        $intlIcu = str_ends_with($type, self::INTL_ICU_SUFFIX);

        if ($intlIcu) {
            $type = substr($type, 0, -\strlen(self::INTL_ICU_SUFFIX));
        }

        if ('' === $type) {
            return 'The file name has an empty type part.';
        }

        return [
            'type' => $type,
            'locale' => str_replace('-', '_', $locale),
            'intlIcu' => $intlIcu,
            'extension' => $extension,
        ];
    }

    /**
     * @param array{path: string, locale: string, intlIcu: bool, extension: string} $file
     *
     * @return array<string, string>
     */
    private function loadFile(array $file): array
    {
        if (isset($this->fileCache[$file['path']])) {
            return $this->fileCache[$file['path']];
        }

        // Checked here, not left to the loaders: the JSON one reads an unreadable file as empty.
        // Never cached: a chmod or a chown changes nothing a cache key could see.
        if (!is_readable($file['path'])) {
            $this->ignoredFiles[$file['path']] = 'The file cannot be read: check its permissions.';

            return $this->fileCache[$file['path']] = [];
        }

        $parsed = null;
        if (null !== $this->filesCache && null !== $key = $this->persistentKey($file['path'])) {
            $parsed = $this->filesCache->get($key, fn (): array => $this->parseFile($file));
        }
        $parsed ??= $this->parseFile($file);

        // Recorded on every load, the cached ones included: an unparseable file stays
        // reported for as long as it is unparseable, not only by the process that parsed it.
        if (null !== $parsed['error']) {
            $this->ignoredFiles[$file['path']] = $parsed['error'];
        }

        return $this->fileCache[$file['path']] = $parsed['messages'];
    }

    /**
     * The content, not the date of modification: a build may freeze the dates, and a pool
     * shared between releases deployed under the same path would serve the previous content.
     * Null when the file cannot be read: it is then parsed as if there were no cache.
     */
    private function persistentKey(string $path): ?string
    {
        $content = @file_get_contents($path);

        if (false === $content) {
            return null;
        }

        return 'file_'.hash('xxh128', self::CACHE_FORMAT."\0".$path."\0".$content);
    }

    /**
     * @param array{path: string, locale: string, intlIcu: bool, extension: string} $file
     *
     * @return array{messages: array<string, string>, error: ?string}
     */
    private function parseFile(array $file): array
    {
        try {
            $catalogue = $this->getLoader($file['extension'])->load($file['path'], $file['locale'], 'messages');
        } catch (\Throwable $e) {
            // Kept like a parsed file: an unparseable file must not be retried on every lookup.
            return ['messages' => [], 'error' => \sprintf('The file could not be parsed: %s', $e->getMessage())];
        }

        $messages = [];
        foreach ($catalogue->all('messages') as $key => $value) {
            if (\is_scalar($value) || $value instanceof \Stringable) {
                $messages[(string) $key] = (string) $value;
            }
        }

        return ['messages' => $messages, 'error' => null];
    }

    private function getLoader(string $extension): LoaderInterface
    {
        return $this->loaders[$extension] ??= match ($extension) {
            'yaml', 'yml' => new YamlFileLoader(),
            'xlf', 'xliff' => new XliffFileLoader(),
            'json' => new JsonFileLoader(),
            default => throw new \LogicException(\sprintf('No loader for extension "%s".', $extension)),
        };
    }
}
