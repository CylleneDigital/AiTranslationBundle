<?php

declare(strict_types=1);

namespace CylleneDigital\AiTranslationBundle\Tests\Functional;

use CylleneDigital\AiTranslationBundle\Entity\TranslationOverride;
use CylleneDigital\AiTranslationBundle\Override\OverrideWriter;
use CylleneDigital\AiTranslationBundle\Repository\TranslationOverrideRepository;
use CylleneDigital\AiTranslationBundle\Scope\ScopeParametersManager;
use Doctrine\ORM\EntityManagerInterface;

/**
 * How overrides are stored, on the engine the suite runs against. Symfony compares
 * translation keys byte for byte: two keys differing only by case, accents or a trailing
 * space are two entries, and must stay two rows — the default MySQL/MariaDB collations
 * would fold them into one. On SQLite these assertions hold trivially; the CI jobs on
 * MariaDB, MySQL and PostgreSQL are where they prove something. Also: every locale code
 * the bundle accepts fits its column, the runtime lookup leaves nothing in the host's unit
 * of work, and a batch updates rows in place.
 */
final class OverrideStorageTest extends DatabaseTestCase
{
    public function testKeysDifferingOnlyByCaseAccentOrTrailingSpaceAreDistinctOverrides(): void
    {
        /** @var OverrideWriter $writer */
        $writer = self::getContainer()->get(OverrideWriter::class);
        /** @var TranslationOverrideRepository $repository */
        $repository = self::getContainer()->get(TranslationOverrideRepository::class);

        $writer->save('Save', 'messages', 'fr', 'Enregistrer');
        $writer->save('save', 'messages', 'fr', 'sauvegarder');
        $writer->save('sàve', 'messages', 'fr', 'accentué');
        $writer->save('Save ', 'messages', 'fr', 'espace final');

        self::assertSame('Enregistrer', $repository->findOneByKey('Save', 'messages', 'fr')?->getValue());
        self::assertSame('sauvegarder', $repository->findOneByKey('save', 'messages', 'fr')?->getValue());
        self::assertSame('accentué', $repository->findOneByKey('sàve', 'messages', 'fr')?->getValue());
        // A PAD SPACE collation (utf8mb4_bin) would fold the trailing space away.
        self::assertSame('espace final', $repository->findOneByKey('Save ', 'messages', 'fr')?->getValue());
    }

    /** Scope codes are opaque: "FR" and "fr" are two scopes, not one. */
    public function testScopesDifferingOnlyByCaseAreDistinct(): void
    {
        /** @var OverrideWriter $writer */
        $writer = self::getContainer()->get(OverrideWriter::class);
        /** @var TranslationOverrideRepository $repository */
        $repository = self::getContainer()->get(TranslationOverrideRepository::class);
        /** @var ScopeParametersManager $parameters */
        $parameters = self::getContainer()->get(ScopeParametersManager::class);

        $writer->save('app.hello', 'messages', 'fr', 'Bonjour FR', scope: 'FR');
        $writer->save('app.hello', 'messages', 'fr', 'Bonjour fr', scope: 'fr');
        $parameters->setPromptContext('FR', 'Contexte FR');

        self::assertSame('Bonjour FR', $repository->findOneByKey('app.hello', 'messages', 'fr', 'FR')?->getValue());
        self::assertSame('Bonjour fr', $repository->findOneByKey('app.hello', 'messages', 'fr', 'fr')?->getValue());
        self::assertSame('Contexte FR', $parameters->getPromptContext('FR'));
        self::assertNull($parameters->getPromptContext('fr'));
    }

    /** The locale pattern accepts long variant codes: the columns must store them. */
    public function testALongLocaleCodeFitsTheColumn(): void
    {
        /** @var OverrideWriter $writer */
        $writer = self::getContainer()->get(OverrideWriter::class);
        /** @var TranslationOverrideRepository $repository */
        $repository = self::getContainer()->get(TranslationOverrideRepository::class);

        $writer->save('app.hello', 'messages', 'ca_ES_VALENCIA', 'Hola');

        self::assertSame('Hola', $repository->findOneByKey('app.hello', 'messages', 'ca_ES_VALENCIA')?->getValue());
    }

    /**
     * The translator loads the overrides inside the host's requests: they must not stay
     * managed by the host's entity manager, whose next flush() would scan them all.
     */
    public function testTheRuntimeLookupLeavesNothingInTheUnitOfWork(): void
    {
        /** @var OverrideWriter $writer */
        $writer = self::getContainer()->get(OverrideWriter::class);
        /** @var TranslationOverrideRepository $repository */
        $repository = self::getContainer()->get(TranslationOverrideRepository::class);
        /** @var EntityManagerInterface $entityManager */
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);

        $writer->save('app.hello', 'messages', 'fr', 'Salut');
        $entityManager->clear();

        $rows = $repository->findForRuntime('fr_FR', '');

        self::assertCount(1, $rows);
        self::assertSame('Salut', $rows[0]->getValue());
        self::assertSame(0, $entityManager->getUnitOfWork()->size());
    }

    /** The batch reads the stored rows once per (locale, scope), then updates them in place. */
    public function testABatchUpdatesTheStoredRowsInsteadOfDuplicatingThem(): void
    {
        $this->writer()->save('app.hello', 'messages', 'fr', 'Bonjour');
        $this->writer()->save('app.hello', 'messages', 'fr', 'Bonjour B2B', scope: 'b2b');

        self::assertSame(2, $this->writer()->saveMany([
            ['key' => 'app.hello', 'catalogue' => 'messages', 'locale' => 'fr', 'value' => 'Salut'],
            ['key' => 'app.hello', 'catalogue' => 'messages', 'locale' => 'fr', 'value' => 'Salut B2B', 'scope' => 'b2b'],
        ]));

        self::assertSame(2, $this->fetchInt('SELECT COUNT(*) FROM cyllene_translation_override'));
        self::assertSame('Salut', $this->overrides()->getOverrideValue('app.hello', 'messages', 'fr'));
        self::assertSame('Salut B2B', $this->overrides()->getOverrideValue('app.hello', 'messages', 'fr', 'b2b'));
    }

    /** A batch records the original like save() does: once, when the override is created. */
    public function testABatchRecordsTheOriginalOfANewOverrideOnly(): void
    {
        $this->writer()->saveMany([['key' => 'app.dashboard', 'catalogue' => 'messages', 'locale' => 'fr', 'value' => 'Pilotage', 'original' => 'Tableau de bord']]);
        $this->writer()->saveMany([['key' => 'app.dashboard', 'catalogue' => 'messages', 'locale' => 'fr', 'value' => 'Cockpit', 'original' => 'Autre']]);

        /** @var TranslationOverrideRepository $repository */
        $repository = self::getContainer()->get(TranslationOverrideRepository::class);
        self::assertSame('Tableau de bord', $repository->findOneByKey('app.dashboard', 'messages', 'fr')?->getOriginalValue());
    }

    /** A batch of one key, in a host request: the other overrides of the locale stay out of its unit of work. */
    public function testABatchLoadsOnlyTheOverridesOfItsKeys(): void
    {
        foreach (['app.one', 'app.two', 'app.three'] as $key) {
            $this->writer()->save($key, 'messages', 'fr', 'Valeur');
        }

        $entityManager = $this->entityManager();
        $entityManager->clear();

        $this->writer()->saveMany([['key' => 'app.two', 'catalogue' => 'messages', 'locale' => 'fr', 'value' => 'Changée']]);

        $managed = $entityManager->getUnitOfWork()->getIdentityMap()[TranslationOverride::class] ?? [];
        self::assertCount(1, $managed);
        self::assertSame('Changée', $this->overrides()->getOverrideValue('app.two', 'messages', 'fr'));
    }

    public function testABatchWithCaseVariantsDoesNotViolateTheUniqueIndex(): void
    {
        /** @var OverrideWriter $writer */
        $writer = self::getContainer()->get(OverrideWriter::class);

        self::assertSame(2, $writer->saveMany([
            ['key' => 'Save', 'catalogue' => 'messages', 'locale' => 'fr', 'value' => 'Enregistrer'],
            ['key' => 'save', 'catalogue' => 'messages', 'locale' => 'fr', 'value' => 'sauvegarder'],
        ]));

        self::assertSame('Enregistrer', $this->overrides()->getOverrideValue('Save', 'messages', 'fr'));
        self::assertSame('sauvegarder', $this->overrides()->getOverrideValue('save', 'messages', 'fr'));
    }
}
