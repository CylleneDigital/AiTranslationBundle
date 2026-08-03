<?php

declare(strict_types=1);

namespace CylleneDigital\AiTranslationBundle\Tests\Unit\Scope;

use CylleneDigital\AiTranslationBundle\Entity\ScopeParameters;
use CylleneDigital\AiTranslationBundle\Repository\ScopeParametersRepository;
use CylleneDigital\AiTranslationBundle\Scope\ScopeParametersManager;
use PHPUnit\Framework\TestCase;

/**
 * The repository double is built per test: reading needs canned answers (a stub),
 * writing needs assertions on the calls (a mock).
 */
final class ScopeParametersManagerTest extends TestCase
{
    public function testAScopeWithoutARowHasNoContext(): void
    {
        $repository = $this->createStub(ScopeParametersRepository::class);
        $repository->method('find')->willReturn(null);

        self::assertNull((new ScopeParametersManager($repository))->getPromptContext('FASHION_WEB'));
    }

    public function testSettingAContextOnANewScopeSavesATrimmedRow(): void
    {
        $repository = $this->createMock(ScopeParametersRepository::class);
        $repository->method('find')->willReturn(null);

        $saved = null;
        // flush: false stages the write for the caller's own flush.
        $repository->expects(self::once())->method('saveDeferred')
            ->willReturnCallback(static function (ScopeParameters $parameters) use (&$saved): void {
                $saved = [$parameters->getScope(), $parameters->getPromptContext()];
            });
        $repository->expects(self::never())->method('save');
        $repository->expects(self::never())->method('remove');

        (new ScopeParametersManager($repository))->setPromptContext('FASHION_WEB', "  Luxury fashion, formal tone.  \n", flush: false);

        self::assertSame(['FASHION_WEB', 'Luxury fashion, formal tone.'], $saved);
    }

    public function testBlankingTheContextDeletesTheExistingRow(): void
    {
        $repository = $this->createMock(ScopeParametersRepository::class);
        $existing = (new ScopeParameters('FASHION_WEB'))->setPromptContext('Old context');
        $repository->method('find')->willReturn($existing);

        $repository->expects(self::once())->method('remove')->with($existing);
        $repository->expects(self::never())->method('save');

        (new ScopeParametersManager($repository))->setPromptContext('FASHION_WEB', '   ');
    }

    public function testBlankingAMissingScopeWritesNothing(): void
    {
        $repository = $this->createMock(ScopeParametersRepository::class);
        $repository->method('find')->willReturn(null);
        $repository->expects(self::never())->method('save');
        $repository->expects(self::never())->method('saveDeferred');
        $repository->expects(self::never())->method('remove');
        $repository->expects(self::never())->method('removeDeferred');

        (new ScopeParametersManager($repository))->setPromptContext('FASHION_WEB', null);
    }

    public function testRemovingAScopeDropsItsRowWhenItExists(): void
    {
        $repository = $this->createMock(ScopeParametersRepository::class);
        $existing = new ScopeParameters('FASHION_WEB');
        $repository->method('find')->willReturnCallback(static fn (string $scope): ?ScopeParameters => 'FASHION_WEB' === $scope ? $existing : null);
        $repository->expects(self::once())->method('remove')->with($existing);

        $manager = new ScopeParametersManager($repository);
        $manager->remove('FASHION_WEB');
        $manager->remove('UNKNOWN');
    }
}
