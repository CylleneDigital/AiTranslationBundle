<?php

declare(strict_types=1);

namespace CylleneDigital\AiTranslationBundle\Tests\Unit\Suggestion;

use CylleneDigital\AiTranslationBundle\Catalogue\CatalogueRegistry;
use CylleneDigital\AiTranslationBundle\Catalogue\LocaleProviderInterface;
use CylleneDigital\AiTranslationBundle\Catalogue\TranslationFileScanner;
use CylleneDigital\AiTranslationBundle\Entity\TranslationSuggestion;
use CylleneDigital\AiTranslationBundle\Override\TranslationValueValidator;
use CylleneDigital\AiTranslationBundle\Suggestion\AutocorrectResult;
use CylleneDigital\AiTranslationBundle\Suggestion\PlaceholderConsistencyChecker;
use CylleneDigital\AiTranslationBundle\Suggestion\SuggestionAutocorrector;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

/**
 * The deterministic repair of a flawed suggestion, against real fixture catalogues
 * (one legacy, one ICU): renamed placeholders swapped back, translated ICU skeletons
 * realigned on the source — and nothing proposed when a full clean fix is impossible.
 */
final class SuggestionAutocorrectorTest extends TestCase
{
    private ?string $dir = null;

    protected function tearDown(): void
    {
        if (null !== $this->dir) {
            (new Filesystem())->remove($this->dir);
            $this->dir = null;
        }
    }

    public function testARenamedPercentPlaceholderIsSwappedBack(): void
    {
        $result = $this->correct('Bonjour %nom% !', 'Hello %name%!', 'legacy.hello');

        self::assertNotNull($result);
        self::assertSame('Bonjour %name% !', $result->value);
        self::assertSame(['%nom% → %name%'], $result->applied);
    }

    public function testATranslatedIcuSkeletonIsRealignedOnTheSource(): void
    {
        $result = $this->correct(
            '{compte, pluriel, one {# pomme} autre {# pommes}}',
            '{count, plural, one {# apple} other {# apples}}',
            'icu.apples',
        );

        self::assertNotNull($result);
        self::assertSame('{count, plural, one {# pomme} other {# pommes}}', $result->value);
        self::assertSame(['{compte} → {count}', 'pluriel → plural', 'autre → other'], $result->applied);
    }

    public function testARenamedPlaceholderInsideAnIcuBranchIsSwappedToo(): void
    {
        $result = $this->correct(
            '{count, plural, one {{nb} pomme} autre {{nb} pommes}}',
            '{count, plural, one {{total} apple} other {{total} apples}}',
            'icu.apples',
        );

        self::assertNotNull($result);
        self::assertSame('{count, plural, one {{total} pomme} other {{total} pommes}}', $result->value);
        self::assertSame(['autre → other', '{nb} → {total}'], $result->applied);
    }

    public function testTheSuggestionsOwnLayoutIsPreservedByTheRealignment(): void
    {
        // The tokens are spliced in place: a multi-line suggestion keeps its
        // newlines and indentation, only the flawed tokens change.
        $suggested = "{produits, plural,\n    =1    {Pour A {nb_produits} boîte.}\n    other {Pour B {nb_produits} boîtes.}\n}";
        $source = '{products, plural, =1 {To A {nb_products} box.} other {To B {nb_products} boxes.}}';

        $result = $this->correct($suggested, $source, 'icu.apples');

        self::assertNotNull($result);
        self::assertSame(
            "{products, plural,\n    =1    {Pour A {nb_products} boîte.}\n    other {Pour B {nb_products} boîtes.}\n}",
            $result->value,
        );
        self::assertSame(['{produits} → {products}', '{nb_produits} → {nb_products}'], $result->applied);
    }

    public function testTheSpacingOfAValidatorPlaceholderIsRestored(): void
    {
        $result = $this->correct('Máximo {{limit}} caracteres.', 'At most {{ limit }} characters.', 'legacy.hello');

        self::assertNotNull($result);
        self::assertSame('Máximo {{ limit }} caracteres.', $result->value);
        self::assertSame(['{{limit}} → {{ limit }}'], $result->applied);
    }

    public function testARenamedArgumentIsSwappedBackWithoutTouchingABranchText(): void
    {
        // "usuario" is both the translated argument name and a branch text: only the
        // argument goes back to "{user}", the branch stays translated text.
        $result = $this->correct(
            '{role, select, admin {usuario} other {{usuario}}}',
            '{role, select, admin {user} other {{user}}}',
            'icu.apples',
        );

        self::assertNotNull($result);
        self::assertSame('{role, select, admin {usuario} other {{user}}}', $result->value);
        self::assertSame(['{usuario} → {user}'], $result->applied);
    }

    public function testACorrectTranslationOfOneWordBranchesYieldsNothing(): void
    {
        self::assertNull($this->correct(
            '{gender, select, female {Señora} male {Señor} other {}} {lastname}',
            '{gender, select, female {Madame} male {Monsieur} other {}} {lastname}',
            'icu.apples',
        ));
    }

    public function testALostPlaceholderWithoutCounterpartIsNotCorrectable(): void
    {
        // "%name%" simply disappeared — nobody can know where it belongs.
        self::assertNull($this->correct('Bonjour !', 'Hello %name%!', 'legacy.hello'));
    }

    public function testTwoRenamedPlaceholdersAreAmbiguousAndLeftAlone(): void
    {
        self::assertNull($this->correct('De %un% à %deux%', 'From %a% to %b%', 'legacy.hello'));
    }

    public function testACleanSuggestionYieldsNothing(): void
    {
        self::assertNull($this->correct('Bonjour %name% !', 'Hello %name%!', 'legacy.hello'));
    }

    public function testAnErroredSuggestionYieldsNothing(): void
    {
        $suggestion = new TranslationSuggestion('legacy.hello', 'messages', 'fr_FR', null, 'Hello %name%!', 'en_US', 'stub', 0.9);

        self::assertNull($this->createAutocorrector()->correct($suggestion));
    }

    private function correct(string $suggestedValue, string $sourceValue, string $key): ?AutocorrectResult
    {
        $suggestion = new TranslationSuggestion($key, 'messages', 'fr_FR', $suggestedValue, $sourceValue, 'en_US', 'stub', 0.9);

        return $this->createAutocorrector()->correct($suggestion);
    }

    private function createAutocorrector(): SuggestionAutocorrector
    {
        $this->dir = sys_get_temp_dir().'/cyllene_autocorrector_'.bin2hex(random_bytes(4));
        mkdir($this->dir, 0o777, true);
        file_put_contents($this->dir.'/messages.fr_FR.json', json_encode(['legacy.hello' => 'Bonjour %name%'], \JSON_THROW_ON_ERROR));
        file_put_contents($this->dir.'/messages+intl-icu.fr_FR.json', json_encode(['icu.apples' => '{count, plural, one {# pomme} other {# pommes}}'], \JSON_THROW_ON_ERROR));

        $localeProvider = $this->createStub(LocaleProviderInterface::class);
        $localeProvider->method('getAvailableLocales')->willReturn(['fr_FR']);

        $catalogues = new CatalogueRegistry(new TranslationFileScanner($this->dir), $localeProvider);

        return new SuggestionAutocorrector(new PlaceholderConsistencyChecker(), new TranslationValueValidator($catalogues));
    }
}
