<?php

declare(strict_types=1);

namespace CylleneDigital\AiTranslationBundle\Tests\Unit\Command;

use CylleneDigital\AiTranslationBundle\Command\Journey\ValueBlock;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Style\SymfonyStyle;

final class ValueBlockTest extends TestCase
{
    public function testAValueIsShownVerbatim(): void
    {
        self::assertStringContainsString('<b>Soldes</b>', $this->render('<b>Soldes</b>'));
    }

    public function testAnAbsentValueSaysSo(): void
    {
        self::assertStringContainsString('(missing — no value for this locale)', $this->render(null, '(missing — no value for this locale)'));
    }

    /** An empty value printed as is was a blank line, indistinguishable from a layout gap. */
    public function testAnEmptyValueSaysSo(): void
    {
        self::assertStringContainsString('(empty)', $this->render(''));
    }

    private function render(?string $value, string $missing = '(missing)'): string
    {
        $output = new BufferedOutput();
        (new ValueBlock())->render(new SymfonyStyle(new ArrayInput([]), $output), 'File value', $value, missing: $missing);

        return $output->fetch();
    }
}
