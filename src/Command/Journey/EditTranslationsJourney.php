<?php

declare(strict_types=1);

namespace CylleneDigital\AiTranslationBundle\Command\Journey;

use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

/**
 * Finding a translation and acting on it, through whichever door fits what the user
 * already knows: the key, or a set to narrow down. Both end in the same place —
 * {@see OverrideEditor} on one (key, catalogue, locale, scope) combination — so the
 * two doors are ways in, not two features to keep in sync.
 *
 * They used to be two menu entries, and the seam showed: reading the overrides table
 * left you with a key to retype in the other journey.
 */
#[AsTaggedItem(priority: 80)]
final class EditTranslationsJourney implements JourneyInterface
{
    private const string DOOR_KEY = 'I know the key';
    private const string DOOR_BROWSE = 'Let me browse and filter';

    public function __construct(
        private readonly KeyLandscape $keyLandscape,
        private readonly TranslationBrowser $browser,
    ) {
    }

    public function getLabel(): string
    {
        return 'Browse and edit translations';
    }

    public function run(SymfonyStyle $io): int
    {
        $door = $io->choice('How do you want to get there?', [self::DOOR_KEY, self::DOOR_BROWSE], self::DOOR_KEY);

        return self::DOOR_BROWSE === $door
            ? $this->browser->browse($io)
            : $this->keyLandscape->lookUp($io);
    }
}
