<?php

declare(strict_types=1);

namespace CylleneDigital\AiTranslationBundle\Tests\Functional;

use CylleneDigital\AiTranslationBundle\Tests\App\FullConfigTestKernel;
use CylleneDigital\AiTranslationBundle\Tests\App\TestKernel;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * `cyllene_digital_ai_translation.default_provider` is public contract: it survives the
 * compilation of a host's container, for an integration to read.
 */
final class DefaultProviderParameterTest extends TestCase
{
    /**
     * @param class-string<TestKernel> $kernel
     */
    #[DataProvider('kernels')]
    public function testTheCompiledContainerKeepsTheParameter(string $kernel, string $expected): void
    {
        $host = new $kernel('test', false);
        $host->boot();

        try {
            self::assertSame($expected, $host->getContainer()->getParameter('cyllene_digital_ai_translation.default_provider'));
        } finally {
            $host->shutdown();
        }
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
