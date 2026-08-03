<?php

declare(strict_types=1);

namespace CylleneDigital\AiTranslationBundle\Command\Journey;

use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Formatter\OutputFormatterStyle;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * The one way a translation value is displayed on the console: a styled label line
 * (metadata in the label, never after the value), the value alone on its own line(s),
 * a blank line to close the block. Values are escaped — a translation containing
 * markup-looking text (`<info>`, HTML…) must display verbatim, not style the output.
 */
final class ValueBlock
{
    private const string STYLE = 'value-label';

    public function render(SymfonyStyle $io, string $label, ?string $value, ?string $meta = null, string $missing = '(missing)'): void
    {
        if (!$io->getFormatter()->hasStyle(self::STYLE)) {
            $io->getFormatter()->setStyle(self::STYLE, new OutputFormatterStyle('cyan'));
        }

        $io->text(\sprintf(
            '<%s>%s</>%s:',
            self::STYLE,
            OutputFormatter::escape($label),
            null !== $meta ? \sprintf(' <comment>(%s)</comment>', OutputFormatter::escape($meta)) : '',
        ));
        // An empty value printed as is was a blank line, indistinguishable from the gap
        // that closes the block. "(empty)" and no more: a file value shadowed by an
        // override is shown here too, and only the effective value counts as missing.
        $io->text(match ($value) {
            null => \sprintf('<comment>%s</comment>', OutputFormatter::escape($missing)),
            '' => '<comment>(empty)</comment>',
            default => OutputFormatter::escape($value),
        });
        $io->newLine();
    }
}
