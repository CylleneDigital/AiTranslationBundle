<?php

declare(strict_types=1);

namespace CylleneDigital\AiTranslationBundle\Command\Journey;

use Symfony\Component\Console\Question\Question;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * The one way a translation value is typed on the console — by the override editor and
 * by the review's "edit" decision alike, which used to keep two copies that drifted (the
 * review never learned the multi-line entry).
 *
 * No Question default: a long value between brackets would flood the prompt, and it is on
 * screen already — a plain enter keeps the current value. A value of several lines (legal
 * texts, e-mail bodies) cannot be typed at a one-line prompt, where the first Enter would
 * save its first line alone: it is typed line by line instead, ended by a line holding a
 * single "." — the convention of the command-line mail clients. Plain one-line questions
 * on purpose: Symfony's multiline mode re-opens the input to read up to Ctrl+D, which finds
 * nothing on a pipe (a script feeding the console) and aborted the command.
 */
final class ValuePrompt
{
    /**
     * @param string      $label       "New value", "Final value"…
     * @param string|null $current     what a plain enter keeps; null when a value is required
     * @param string      $currentName how the prompt names it: "the current one", "the suggestion"…
     */
    public function ask(SymfonyStyle $io, string $label, ?string $current, string $currentName = 'the current one'): string
    {
        return null !== $current && str_contains($current, "\n")
            ? $this->askLines($io, $label, $current, $currentName)
            : $this->askLine($io, $label, $current, $currentName);
    }

    private function askLine(SymfonyStyle $io, string $label, ?string $current, string $currentName): string
    {
        $question = new Question(null !== $current ? \sprintf('%s (enter to keep %s)', $label, $currentName) : $label);
        // Symfony trims an answer before the validator sees it: a label concatenated in a
        // template would lose the space it needs.
        $question->setTrimmable(false);
        $question->setValidator(static function (mixed $answer) use ($current): string {
            // Not trimmed means the line break comes with it.
            $answer = \is_string($answer) ? rtrim($answer, "\r\n") : '';

            // Trimmed only to tell a blank answer from a real one: a translation may need
            // its leading or trailing space.
            if ('' === trim($answer)) {
                return $current ?? throw new \InvalidArgumentException('A value is required.');
            }

            return $answer;
        });

        $value = $io->askQuestion($question);

        return \is_string($value) ? $value : '';
    }

    /** Several lines: a blank one is kept (paragraphs need it), enter on the first one keeps $current. */
    private function askLines(SymfonyStyle $io, string $label, string $current, string $currentName): string
    {
        $io->text(\sprintf('Several lines: type them one by one, then a line with a single "." to finish (enter on the first line keeps %s).', $currentName));
        $lines = [];

        while (true) {
            $question = new Question([] === $lines ? $label.', line 1' : \sprintf('Line %d', \count($lines) + 1));
            // Kept as typed: indentation and trailing spaces belong to the value.
            $question->setTrimmable(false);
            $line = $io->askQuestion($question);
            // Not trimmed means the line break comes with it.
            $line = \is_string($line) ? rtrim($line, "\r\n") : '';

            if ([] === $lines && '' === $line) {
                return $current;
            }

            if ('.' === $line) {
                return [] === $lines ? $current : implode("\n", $lines);
            }

            $lines[] = $line;
        }
    }
}
