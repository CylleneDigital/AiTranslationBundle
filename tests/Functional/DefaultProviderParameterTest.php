<?php

declare(strict_types=1);

namespace CylleneDigital\AiTranslationBundle\Tests\Functional;

use CylleneDigital\AiTranslationBundle\Tests\App\FullConfigTestKernel;
use CylleneDigital\AiTranslationBundle\Tests\App\TestKernel;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * `cyllene_digital_ai_translation.default_provider` is public contract: it survives the
 * compilation of a host's container, for an integration to read.
 */
final class DefaultProviderParameterTest extends KernelTestCase
{
    /**
     * @param class-string<TestKernel> $kernel
     */
    #[DataProvider('kernels')]
    public function testTheCompiledContainerKeepsTheParameter(string $kernel, string $expected): void
    {
        // Read by createKernel(), reset by the parent's tearDown(): which also restores the
        // error and exception handlers the kernel installs.
        static::$class = $kernel;
        self::bootKernel();

        self::assertSame($expected, self::getContainer()->getParameter('cyllene_digital_ai_translation.default_provider'));
    }

    /**
     * @return iterable<string, array{class-string<TestKernel>, string}>
     */
    public static function kernels(): iterable
    {
        yield 'the default_provider key' => [FullConfigTestKernel::class, 'gpt'];
        yield 'the first provider declared' => [TestKernel::class, 'gpt'];
    }
}
