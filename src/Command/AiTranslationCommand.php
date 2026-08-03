<?php

declare(strict_types=1);

namespace CylleneDigital\AiTranslationBundle\Command;

use CylleneDigital\AiTranslationBundle\Command\Journey\JourneyInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * The interactive console of the translation engine — one entry point, no option to
 * memorise: a menu lists every guided task (a {@see JourneyInterface} each), the
 * chosen one asks its own questions, and the menu comes back until "Quit".
 *
 * Automation does not belong here: CI and cron rely on the option-driven commands
 * (coverage, export-overrides, import-overrides, cleanup-suggestions).
 */
#[AsCommand(
    name: 'cyllene:ai-translation',
    description: 'Manage the translations interactively — generation, review, overrides, coverage…',
)]
final class AiTranslationCommand
{
    private const string QUIT = 'Quit';

    /**
     * @param iterable<JourneyInterface> $journeys
     */
    public function __construct(
        #[AutowireIterator(JourneyInterface::TAG)]
        private readonly iterable $journeys,
    ) {
    }

    public function __invoke(SymfonyStyle $io, InputInterface $input): int
    {
        if (!$input->isInteractive()) {
            $io->error('This console is interactive — run it from a terminal.');
            $io->text('For automation, use the option-driven commands:');
            $io->listing([
                'cyllene:ai-translation:coverage — CI gate (--min)',
                'cyllene:ai-translation:generate — scripted AI generation (--target, --dry-run, --max-cost)',
                'cyllene:ai-translation:export-overrides / :import-overrides — scripted backups and promotions',
                'cyllene:ai-translation:cleanup-suggestions — cron retention of the reviewed suggestions',
            ]);

            return Command::FAILURE;
        }

        $journeysByLabel = [];

        foreach ($this->journeys as $journey) {
            $journeysByLabel[$journey->getLabel()] = $journey;
        }

        $io->title('AI Translation');
        $exitCode = Command::SUCCESS;

        while (true) {
            $choice = $io->choice(
                'What do you want to do?',
                array_merge(array_keys($journeysByLabel), [self::QUIT]),
                self::QUIT,
            );

            if (!\is_string($choice) || self::QUIT === $choice) {
                return $exitCode;
            }

            $exitCode = max($exitCode, $journeysByLabel[$choice]->run($io));
            $io->newLine();
        }
    }
}
