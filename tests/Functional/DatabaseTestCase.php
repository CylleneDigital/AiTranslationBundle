<?php

declare(strict_types=1);

namespace CylleneDigital\AiTranslationBundle\Tests\Functional;

use CylleneDigital\AiTranslationBundle\Override\OverrideReader;
use CylleneDigital\AiTranslationBundle\Override\OverrideWriter;
use CylleneDigital\AiTranslationBundle\Tests\InspectsUntypedData;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\JsonMockResponse;

/**
 * Base of every test that touches the database: the bundle schema synced once per
 * class, then a booted kernel, empty bundle tables and an empty cache.app per test.
 */
abstract class DatabaseTestCase extends KernelTestCase
{
    use InspectsUntypedData;

    /** @var list<string> */
    private array $temporaryPaths = [];

    private const array TABLES = [
        'cyllene_translation_suggestion',
        'cyllene_translation_generation_log',
        'cyllene_translation_override',
        'cyllene_translation_scope_parameters',
    ];

    public static function setUpBeforeClass(): void
    {
        self::bootKernel();

        /** @var EntityManagerInterface $entityManager */
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);

        // Sync the bundle schema — idempotent between runs.
        $schemaTool = new SchemaTool($entityManager);
        $schemaTool->updateSchema($entityManager->getMetadataFactory()->getAllMetadata());

        self::ensureKernelShutdown();
    }

    protected function setUp(): void
    {
        self::bootKernel();

        $connection = $this->entityManager()->getConnection();

        foreach (self::TABLES as $table) {
            $connection->executeStatement('DELETE FROM '.$table);
        }

        // Truncating goes behind the cache manager's back, so the version tokens and the
        // override maps of the previous test survive in cache.app — which is on the
        // filesystem here, and therefore outlives the kernel reboot. A test that READS
        // before it writes would then be served the previous test's values.
        $cache = self::getContainer()->get('cache.app');

        if ($cache instanceof CacheItemPoolInterface) {
            $cache->clear();
        }
    }

    /** A COUNT(*) or an id: the DBAL hands it back as an int or a numeric string, by platform. */
    protected function fetchInt(string $sql): int
    {
        $value = $this->entityManager()->getConnection()->fetchOne($sql);
        self::assertIsNumeric($value);

        return (int) $value;
    }

    /**
     * Swaps the suite's HTTP client for an OpenAI-compatible server translating every
     * requested key as "FR <source>" — what a generation needs to go all the way.
     */
    protected function answerProviderCallsInFrench(): void
    {
        self::getContainer()->set('http_client', new MockHttpClient(static function (string $method, string $url, array $options): JsonMockResponse {
            $prompt = self::stringAt(self::jsonBody($options), 'messages', 1, 'content');
            preg_match('/\{.*\}/s', $prompt, $match);
            $texts = json_decode($match[0] ?? self::fail('No JSON object in the prompt.'), true, 512, \JSON_THROW_ON_ERROR);
            self::assertIsArray($texts);

            return new JsonMockResponse(['choices' => [['message' => ['content' => json_encode(
                array_map(static fn (mixed $text): array => ['t' => 'FR '.self::stringAt([$text], 0), 'c' => 0.9], $texts),
                \JSON_THROW_ON_ERROR,
            )]]]]);
        }));
    }

    /** One console command of the booted kernel, ready to execute. */
    protected function commandTester(string $name): CommandTester
    {
        $application = new Application(self::$kernel ?? throw new \LogicException('Kernel not booted.'));

        return new CommandTester($application->find($name));
    }

    /**
     * A file path of its own under var/tmp/, removed after the test whatever its outcome:
     * no collision between two runs, nothing left behind when an assertion fails.
     */
    protected function temporaryPath(string $name): string
    {
        $directory = \dirname(__DIR__, 2).'/var/tmp';
        (new Filesystem())->mkdir($directory);

        return $this->temporaryPaths[] = $directory.'/'.bin2hex(random_bytes(6)).'-'.$name;
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->temporaryPaths);
        $this->temporaryPaths = [];

        parent::tearDown();
    }

    /** Reading the stored overrides — what the assertions check. */
    protected function overrides(): OverrideReader
    {
        /** @var OverrideReader $reader */
        $reader = self::getContainer()->get(OverrideReader::class);

        return $reader;
    }

    /** Writing them — what a test arranges. */
    protected function writer(): OverrideWriter
    {
        /** @var OverrideWriter $writer */
        $writer = self::getContainer()->get(OverrideWriter::class);

        return $writer;
    }

    protected function entityManager(): EntityManagerInterface
    {
        /** @var EntityManagerInterface $entityManager */
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);

        return $entityManager;
    }
}
