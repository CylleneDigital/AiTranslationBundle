<?php

declare(strict_types=1);

namespace CylleneDigital\AiTranslationBundle\Command\Journey;

use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * One guided task of the interactive console (`cyllene:ai-translation`): the hub shows
 * every journey's label as a menu entry and runs the chosen one. A journey asks its own
 * questions and owns its full flow — it never reads options or arguments.
 *
 * Implementations are tagged automatically; their menu position comes from the
 * #[AsTaggedItem(priority: …)] attribute (higher first).
 */
#[AutoconfigureTag(self::TAG)]
interface JourneyInterface
{
    public const string TAG = 'cyllene_digital_ai_translation.journey';

    /** The menu entry, imperative and self-explanatory (e.g. "Review the pending suggestions"). */
    public function getLabel(): string;

    /** Runs the full guided flow; returns a Command::* exit code. */
    public function run(SymfonyStyle $io): int;
}
