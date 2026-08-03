<?php

declare(strict_types=1);

namespace CylleneDigital\AiTranslationBundle\Tests\Unit\Suggestion;

use CylleneDigital\AiTranslationBundle\Suggestion\PlaceholderConsistencyChecker;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PlaceholderConsistencyCheckerTest extends TestCase
{
    private PlaceholderConsistencyChecker $checker;

    protected function setUp(): void
    {
        $this->checker = new PlaceholderConsistencyChecker();
    }

    /**
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function consistentPairs(): iterable
    {
        yield 'no placeholders' => ['Add to cart', 'Ajouter au panier'];
        yield 'symfony style' => ['Hello %name%!', 'Bonjour %name% !'];
        yield 'icu simple' => ['{count} items', '{count} articles'];
        yield 'icu plural' => ['{count, plural, one {# item} other {# items}}', '{count, plural, one {# article} other {# articles}}'];
        yield 'icu branches are text, not placeholders' => [
            '{products, plural, =1 {To add {nb} box.} other {To add {nb} boxes.}}',
            '{products, plural, =1 {Pour ajouter {nb} boîte.} other {Pour ajouter {nb} boîtes.}}',
        ];
        yield 'reordered' => ['%min%-%max% days', 'de %max% à %min% jours'];
        yield 'html tags' => ['<strong>Sale</strong> ends %date%', '<strong>Les soldes</strong> finissent le %date%'];
        // Languages with more plural forms repeat the variable in more branches.
        yield 'polish icu plural, four branches' => [
            '{count, plural, one {{count} item} other {{count} items}}',
            '{count, plural, one {{count} rzecz} few {{count} rzeczy} many {{count} rzeczy} other {{count} rzeczy}}',
        ];
        yield 'russian pipe plural, three forms' => ['one apple|%count% apples', '%count% яблоко|%count% яблока|%count% яблок'];
        yield 'pipe plural intervals are not placeholders' => ['{0} No apples|{1} One apple|]1,Inf[ %count% apples', '%count% яблоко|%count% яблока|%count% яблок'];
        // A one-word branch has the very shape of an argument ("{Madame}" / "{lastname}"):
        // only its position — right after the selector of a select or a plural — tells it
        // apart. Reading it as a placeholder refused every correct translation of it.
        yield 'one-word select branches are text' => [
            'Merci {gender, select, female {Madame} male {Monsieur} other {}} {lastname}, votre commande n°{number} a bien été enregistrée.',
            'Gracias {gender, select, female {Señora} male {Señor} other {}} {lastname}, tu pedido n.º {number} se ha registrado.',
        ];
        yield 'one-word plural branch' => [
            '{count, plural, =0 {Aucun} one {# article} other {# articles}}',
            '{count, plural, =0 {Nadie} one {# artículo} other {# artículos}}',
        ];
        yield 'one-word branch of a select nested in a plural branch' => [
            '{count, plural, =0 {Vide} other {{type, select, card {Carte} other {Autre}} ×{count}}}',
            '{count, plural, =0 {Vacío} other {{type, select, card {Tarjeta} other {Otro}} ×{count}}}',
        ];
        yield 'offset and a branch made of an argument only' => [
            '{guests, plural, offset:1 =0 {Personne} one {{host}} other {{host} et # autres}}',
            '{guests, plural, offset:1 =0 {Nadie} one {{host}} other {{host} y # más}}',
        ];
        yield 'icu argument spacing is not significant' => ['{ count } items', '{count} articles'];
        yield 'symfony validator placeholders' => ['At least {{ limit }} characters.', 'Au moins {{ limit }} caractères.'];
        yield 'symfony validator placeholders in a pipe plural' => [
            'At most {{ limit }} character.|At most {{ limit }} characters.',
            'Máximo {{ limit }} carácter.|Máximo {{ limit }} caracteres.',
        ];
    }

    #[DataProvider('consistentPairs')]
    public function testConsistentTranslations(string $source, string $translation): void
    {
        self::assertTrue($this->checker->isConsistent($source, $translation));
        self::assertSame([], $this->checker->diff($source, $translation));
    }

    public function testLostSymfonyPlaceholderIsReported(): void
    {
        $diff = $this->checker->diff('up to %max% days', 'jusqu’à max jours');

        self::assertSame(['%max%'], $diff);
        self::assertFalse($this->checker->isConsistent('up to %max% days', 'jusqu’à max jours'));
    }

    public function testTranslatedPlaceholderNameIsReported(): void
    {
        // The classic DeepL failure: the placeholder itself got translated.
        $diff = $this->checker->diff('Hello %name%!', 'Bonjour %nom% !');

        self::assertSame(['%name%', '%nom%'], $diff);
    }

    public function testAlteredIcuPlaceholderIsReported(): void
    {
        self::assertSame(['{compte', '{count'], $this->checker->diff('{count} items', '{compte} articles'));
    }

    public function testLostHtmlTagIsReported(): void
    {
        self::assertSame(['<strong'], $this->checker->diff('<strong>Sale</strong>', 'Soldes'));
    }

    /** Variables compare by presence, tags still by count: a lost closing tag breaks the markup. */
    public function testALostClosingTagIsStillReported(): void
    {
        self::assertSame(['<strong'], $this->checker->diff('<strong>Sale</strong>', '<strong>Soldes'));
    }

    public function testAVariableLostFromEveryBranchIsStillReported(): void
    {
        // Branch texts are skipped, the arguments inside them are not.
        $diff = $this->checker->diff(
            '{gender, select, female {Madame {lastname}} other {{lastname}}}',
            '{gender, select, female {Señora} other {Cliente}}',
        );

        self::assertSame(['{lastname'], $diff);
    }

    public function testAOneWordBranchWrittenAsAPlaceholderOutsideTheSelectIsReported(): void
    {
        // The same "{Madame}" at the message level is an argument: the translation added one.
        self::assertSame(['{Madame'], $this->checker->diff('Merci {lastname}', 'Merci {Madame} {lastname}'));
    }

    public function testAValidatorPlaceholderKeepsItsExactSpelling(): void
    {
        // The validator passes "{{ limit }}", spaces included, and the translator replaces
        // the parameters verbatim: "{{limit}}" would reach the customer as is.
        self::assertSame(['{{ limit }}', '{{limit}}'], $this->checker->diff('At most {{ limit }} characters.', 'Máximo {{limit}} caracteres.'));
    }

    public function testAValidatorPlaceholderIsNotAnIcuArgument(): void
    {
        self::assertSame(['{limit', '{{ limit }}'], $this->checker->diff('At most {{ limit }} characters.', 'Máximo {limit} caracteres.'));
    }

    /** A refusal has to say which way each marker differs: lost from the source, or added to it. */
    public function testTheMismatchIsSplitIntoMissingAndUnexpectedMarkers(): void
    {
        self::assertSame(
            ['missing' => ['%name%', '<strong>'], 'unexpected' => ['{foo}']],
            $this->checker->explain('<strong>Hello</strong> %name%', 'Bonjour {foo}'),
        );
        self::assertSame(['missing' => [], 'unexpected' => []], $this->checker->explain('{count} items', '{count} articles'));
    }

    public function testUnbalancedBracesAreNotAFatalError(): void
    {
        // A broken value is the syntax validator's concern: the checker still answers.
        self::assertSame([], $this->checker->diff('{count} items', '{count} articles}}'));
        self::assertSame([], $this->checker->diff('{count} items', '{count, plural, one {article'));
    }

    public function testARenamedPluralVariableIsReported(): void
    {
        // "products" is the runtime parameter name the host code passes to trans():
        // a translator renaming it breaks the lookup, whatever the branch texts say.
        // (Translated ICU *keywords* — "pluriel", "autre" — are a syntax concern,
        // caught by the TranslationValueValidator, not by this checker.)
        $diff = $this->checker->diff(
            '{products, plural, =1 {Add {nb} box.} other {Add {nb} boxes.}}',
            '{produits, plural, =1 {Ajouter {nb} boîte.} other {Ajouter {nb} boîtes.}}',
        );

        self::assertSame(['{products', '{produits'], $diff);
    }
}
