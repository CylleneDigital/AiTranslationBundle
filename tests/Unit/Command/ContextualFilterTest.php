<?php

declare(strict_types=1);

namespace CylleneDigital\AiTranslationBundle\Tests\Unit\Command;

use CylleneDigital\AiTranslationBundle\Command\Journey\ContextualFilter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Style\SymfonyStyle;

final class ContextualFilterTest extends TestCase
{
    /**
     * The point of the class: a listing that spans a single value has nothing to ask, and
     * an unnecessary prompt is what makes a guided flow tedious.
     */
    public function testNoQuestionIsAskedWhenEveryItemSharesTheSameValue(): void
    {
        $output = new BufferedOutput();
        $items = [['locale' => 'fr'], ['locale' => 'fr']];

        $result = (new ContextualFilter())->apply(
            $this->io($output),
            $items,
            'Locale',
            static fn (array $item): string => $item['locale'],
        );

        self::assertSame($items, $result);
        self::assertSame('', $output->fetch());
    }

    public function testAnEmptySetAsksNothingEither(): void
    {
        $output = new BufferedOutput();

        $result = (new ContextualFilter())->apply($this->io($output), [], 'Locale', static fn (): string => self::fail('An empty set has nothing to label.'));

        self::assertSame([], $result);
        self::assertSame('', $output->fetch());
    }

    public function testChoosingAValueNarrowsTheSetToIt(): void
    {
        $items = [['locale' => 'fr'], ['locale' => 'en'], ['locale' => 'fr']];

        $result = (new ContextualFilter())->apply(
            $this->io(new BufferedOutput(), ['en']),
            $items,
            'Locale',
            static fn (array $item): string => $item['locale'],
        );

        self::assertSame([['locale' => 'en']], $result);
    }

    /** A plain enter keeps everything — "(all)" is the default answer. */
    public function testAPlainEnterKeepsTheWholeSet(): void
    {
        $items = [['locale' => 'fr'], ['locale' => 'en']];

        $result = (new ContextualFilter())->apply(
            $this->io(new BufferedOutput(), ['']),
            $items,
            'Locale',
            static fn (array $item): string => $item['locale'],
        );

        self::assertSame($items, $result);
    }

    /**
     * The scope dimension displays '' as "(global)": filtering has to match on what was
     * SHOWN, not on the raw value, or picking "(global)" would narrow to nothing.
     */
    public function testTheDisplayCallbackIsWhatTheAnswerIsMatchedAgainst(): void
    {
        $items = [['scope' => ''], ['scope' => 'b2b']];

        $result = (new ContextualFilter())->apply(
            $this->io(new BufferedOutput(), ['(global)']),
            $items,
            'Scope',
            static fn (array $item): string => $item['scope'],
            static fn (string $scope): string => '' === $scope ? '(global)' : $scope,
        );

        self::assertSame([['scope' => '']], $result);
    }

    /**
     * @param list<string> $inputs
     */
    private function io(BufferedOutput $output, array $inputs = []): SymfonyStyle
    {
        $input = new ArrayInput([]);
        $input->setInteractive(true);
        $input->setStream($this->stream($inputs));

        return new SymfonyStyle($input, $output);
    }

    /**
     * @param list<string> $inputs
     *
     * @return resource
     */
    private function stream(array $inputs)
    {
        $stream = fopen('php://memory', 'r+');
        \assert(false !== $stream);
        fwrite($stream, implode(\PHP_EOL, $inputs).\PHP_EOL);
        rewind($stream);

        return $stream;
    }
}
