<?php

declare(strict_types=1);

namespace CylleneDigital\AiTranslationBundle\Tests\Unit\Override;

use CylleneDigital\AiTranslationBundle\Catalogue\CatalogueRegistry;
use CylleneDigital\AiTranslationBundle\Catalogue\LocaleProviderInterface;
use CylleneDigital\AiTranslationBundle\Catalogue\TranslationFileScanner;
use CylleneDigital\AiTranslationBundle\Override\TranslationValueValidator;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

final class TranslationValueValidatorTest extends TestCase
{
    private ?string $dir = null;

    protected function tearDown(): void
    {
        if (null !== $this->dir) {
            (new Filesystem())->remove($this->dir);
            $this->dir = null;
        }
    }

    public function testAValidIcuPatternOnAnIcuKeyPasses(): void
    {
        self::assertSame([], $this->validate('{count, plural, one {# pomme} other {# pommes}}', 'icu.apples'));
    }

    #[RequiresPhpExtension('intl')]
    public function testABrokenIcuPatternOnAnIcuKeyIsRefused(): void
    {
        self::assertSame([TranslationValueValidator::ISSUE_ICU_INVALID], $this->validate('{count, plural, one {# pomme}', 'icu.apples'));
    }

    public function testLegacyPlaceholdersOnAnIcuKeyAreRefused(): void
    {
        self::assertSame([TranslationValueValidator::ISSUE_LEGACY_IN_ICU], $this->validate('une pomme|%count% pommes', 'icu.apples'));
    }

    public function testAnIcuConstructOnALegacyKeyIsRefused(): void
    {
        self::assertSame([TranslationValueValidator::ISSUE_ICU_IN_LEGACY], $this->validate('{count, plural, other {# pommes}}', 'legacy.hello'));
    }

    public function testAPlainLegacyValueOnALegacyKeyPasses(): void
    {
        self::assertSame([], $this->validate('Bonjour %name%', 'legacy.hello'));
    }

    public function testABareBracePlaceholderOnALegacyKeyIsTolerated(): void
    {
        // "{name}" can be literal text in a legacy catalogue: only unambiguous ICU
        // constructs are refused.
        self::assertSame([], $this->validate('Utilisez {votre code}', 'legacy.hello'));
    }

    public function testAnUnknownCatalogueIsNotJudged(): void
    {
        self::assertSame([], $this->validate('{count, plural, one {# pomme}', 'icu.apples', catalogue: 'gone/messages'));
    }

    /** @return list<string> */
    private function validate(string $value, string $key, string $catalogue = 'messages'): array
    {
        $this->dir = sys_get_temp_dir().'/cyllene_value_validator_'.bin2hex(random_bytes(4));
        mkdir($this->dir, 0o777, true);
        file_put_contents($this->dir.'/messages.fr_FR.json', json_encode(['legacy.hello' => 'Bonjour %name%'], \JSON_THROW_ON_ERROR));
        file_put_contents($this->dir.'/messages+intl-icu.fr_FR.json', json_encode(['icu.apples' => '{count, plural, one {# pomme} other {# pommes}}'], \JSON_THROW_ON_ERROR));

        $localeProvider = $this->createStub(LocaleProviderInterface::class);
        $localeProvider->method('getAvailableLocales')->willReturn(['fr_FR']);

        $catalogues = new CatalogueRegistry(new TranslationFileScanner($this->dir), $localeProvider);

        return (new TranslationValueValidator($catalogues))->validate($value, $key, $catalogue, 'fr_FR');
    }
}
