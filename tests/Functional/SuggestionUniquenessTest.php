<?php

declare(strict_types=1);

namespace CylleneDigital\AiTranslationBundle\Tests\Functional;

use CylleneDigital\AiTranslationBundle\Entity\TranslationSuggestion;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;

/**
 * The database side of the pending-uniqueness guard: the application filters
 * ({@see \CylleneDigital\AiTranslationBundle\Suggestion\SuggestionGenerator::collectTexts()}
 * skipping the pending keys, and the generation lock) are read-then-write checks that two
 * concurrent runs can both pass. The unique index is the only arbiter that cannot be raced.
 */
final class SuggestionUniquenessTest extends DatabaseTestCase
{
    public function testASecondPendingSuggestionOfTheSameKeyIsRefusedByTheDatabase(): void
    {
        $entityManager = $this->entityManager();
        $entityManager->persist($this->suggestion());
        $entityManager->flush();

        // What a second concurrent run would insert for the same key.
        $entityManager->persist($this->suggestion());

        $this->expectException(UniqueConstraintViolationException::class);

        $entityManager->flush();
    }

    public function testAnApprovedSuggestionDoesNotBlockALaterRunOnTheSameKey(): void
    {
        $entityManager = $this->entityManager();

        // The "re-translate every key" mode legitimately proposes a key that already has
        // an approved suggestion — the approved row is kept as its audit trail.
        $approved = $this->suggestion();
        $approved->approve('reviewer');
        $entityManager->persist($approved);
        $entityManager->flush();

        $entityManager->persist($this->suggestion());
        $entityManager->flush();

        self::assertSame(2, $this->countSuggestions());
    }

    public function testSeveralApprovedSuggestionsOfTheSameKeyCoexist(): void
    {
        $entityManager = $this->entityManager();

        // Successive approvals of the same key over time: NULL guards never collide.
        foreach (range(1, 3) as $ignored) {
            $suggestion = $this->suggestion();
            $suggestion->approve('reviewer');
            $entityManager->persist($suggestion);
        }

        $entityManager->flush();

        self::assertSame(3, $this->countSuggestions());
    }

    private function suggestion(): TranslationSuggestion
    {
        return new TranslationSuggestion('app.dashboard', 'messages', 'fr', 'Mon tableau de bord', 'Dashboard', 'en', 'gpt', 0.9);
    }

    private function countSuggestions(): int
    {
        return $this->fetchInt('SELECT COUNT(*) FROM cyllene_translation_suggestion');
    }
}
