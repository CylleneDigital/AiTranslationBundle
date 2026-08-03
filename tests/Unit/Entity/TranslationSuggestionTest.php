<?php

declare(strict_types=1);

namespace CylleneDigital\AiTranslationBundle\Tests\Unit\Entity;

use CylleneDigital\AiTranslationBundle\Entity\TranslationSuggestion;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The pending-uniqueness guard: at most one PENDING row per (locale, catalogue, key,
 * scope), while approved rows accumulate as the audit trail of their approvals.
 */
final class TranslationSuggestionTest extends TestCase
{
    public function testTwoPendingSuggestionsOfTheSameKeyShareTheGuardValue(): void
    {
        // Two concurrent runs producing the same key: the database refuses the second
        // insert instead of letting a duplicate reach the review screen.
        self::assertSame(
            $this->pendingKeyOf($this->suggestion()),
            $this->pendingKeyOf($this->suggestion()),
        );
    }

    /**
     * @return iterable<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    public static function distinctCoordinates(): iterable
    {
        yield 'another locale' => ['de_DE', 'messages', 'app.greeting', ''];
        yield 'another catalogue' => ['fr_FR', 'shop/messages', 'app.greeting', ''];
        yield 'another key' => ['fr_FR', 'messages', 'app.farewell', ''];
        yield 'another scope' => ['fr_FR', 'messages', 'app.greeting', 'b2b'];
    }

    #[DataProvider('distinctCoordinates')]
    public function testEachCoordinateIsPartOfTheGuard(string $locale, string $catalogue, string $key, string $scope): void
    {
        self::assertNotSame(
            $this->pendingKeyOf($this->suggestion()),
            $this->pendingKeyOf($this->suggestion($locale, $catalogue, $key, $scope)),
        );
    }

    public function testApprovingReleasesTheKeyForALaterRun(): void
    {
        // An approved row is KEPT (audit trail), so a "re-translate every key" run must
        // still be able to create a new pending suggestion for the same key — which a
        // plain unique constraint on the four columns would have refused.
        $approved = $this->suggestion();
        $approved->approve('reviewer');

        self::assertNull($this->pendingKeyOf($approved));
        self::assertNotNull($this->pendingKeyOf($this->suggestion()));
    }

    public function testAFailedGenerationStillHoldsTheKey(): void
    {
        // An errored row is pending too: a later run refills it rather than duplicating it.
        $failed = TranslationSuggestion::failed('app.greeting', 'messages', 'fr_FR', 'Hello', 'en', 'gpt', 'quota exhausted');

        self::assertSame($this->pendingKeyOf($this->suggestion()), $this->pendingKeyOf($failed));
    }

    public function testRefillingAnErroredRowKeepsItPending(): void
    {
        $failed = TranslationSuggestion::failed('app.greeting', 'messages', 'fr_FR', 'Hello', 'en', 'gpt', 'quota exhausted');
        $failed->fill('Bonjour', 0.9, 'gpt', 'Hello');

        self::assertSame($this->pendingKeyOf($this->suggestion()), $this->pendingKeyOf($failed));
    }

    private function suggestion(string $locale = 'fr_FR', string $catalogue = 'messages', string $key = 'app.greeting', string $scope = ''): TranslationSuggestion
    {
        return new TranslationSuggestion($key, $catalogue, $locale, 'Bonjour', 'Hello', 'en', 'gpt', 0.9, $scope);
    }

    private function pendingKeyOf(TranslationSuggestion $suggestion): ?string
    {
        /** @var string|null $value */
        $value = (new \ReflectionProperty(TranslationSuggestion::class, 'pendingKey'))->getValue($suggestion);

        return $value;
    }
}
