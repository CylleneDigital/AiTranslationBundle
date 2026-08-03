<?php

declare(strict_types=1);

namespace CylleneDigital\AiTranslationBundle\Tests\Unit\Docs;

use CylleneDigital\AiTranslationBundle\Bridge\AnthropicProvider;
use CylleneDigital\AiTranslationBundle\Bridge\OpenAiProvider;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The Markdown docs cannot interpolate the DEFAULT_MODEL constants, so they quote the
 * model ids as plain text. This guard keeps those mirrors honest: change a default in
 * a bridge and this test goes red until every listed page is updated.
 */
final class DefaultModelDocsTest extends TestCase
{
    /** @return iterable<string, array{string, string}> */
    public static function documentedDefaults(): iterable
    {
        yield 'configuration reference / anthropic' => ['docs/configuration-reference.md', AnthropicProvider::DEFAULT_MODEL];
        yield 'configuration reference / openai' => ['docs/configuration-reference.md', OpenAiProvider::DEFAULT_MODEL];
        yield 'anthropic bridge page' => ['docs/provider_bridge/anthropic.md', AnthropicProvider::DEFAULT_MODEL];
        yield 'openai bridge page' => ['docs/provider_bridge/openai.md', OpenAiProvider::DEFAULT_MODEL];
    }

    #[DataProvider('documentedDefaults')]
    public function testTheDocsQuoteTheCurrentDefaultModel(string $file, string $model): void
    {
        $path = \dirname(__DIR__, 3).'/'.$file;
        $content = file_get_contents($path);

        self::assertIsString($content, $path.' is unreadable.');
        self::assertStringContainsString(
            $model,
            $content,
            \sprintf('%s no longer mentions the real default model "%s" — update the doc.', $file, $model),
        );
    }
}
