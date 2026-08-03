<?php

declare(strict_types=1);

namespace CylleneDigital\AiTranslationBundle\Schema;

use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Doctrine\DBAL\Platforms\MySQL80Platform;
use Doctrine\DBAL\Schema\ColumnEditor;
use Doctrine\DBAL\Schema\TableEditor;
use Doctrine\ORM\Tools\Event\GenerateSchemaEventArgs;
use Doctrine\ORM\Tools\ToolEvents;

/**
 * Translation keys are compared byte for byte by Symfony, SQLite and PostgreSQL, but
 * the usual MySQL/MariaDB collations (utf8mb4_unicode_ci, utf8mb4_0900_ai_ci…) ignore
 * case, accents and trailing spaces: "Save" and "save" would share one override row,
 * and saving the second would overwrite the first. Even utf8mb4_bin is not enough: it
 * is a PAD SPACE collation, for which "Save" and "Save " are equal — hence the NO PAD
 * binary collation of each server. Catalogue identifiers are file paths,
 * case-sensitive too: "Shop/messages" is not "shop/messages" — and the orphan lookup,
 * which compares them in SQL, must agree with the file scan.
 * Scopes are opaque codes the host defines: "FR" and "fr" are two scopes, each with
 * its own overrides and prompt context — the default collation would merge them.
 *
 * The collation cannot go on the mapping — every platform would render it, and
 * "utf8mb4_bin" means nothing to PostgreSQL or SQLite — so it is set on the generated
 * schema, for MySQL/MariaDB only. schema:update and migrations:diff pick it up like
 * any other column change.
 */
#[AsDoctrineListener(event: ToolEvents::postGenerateSchema)]
final class BinaryKeyCollationListener
{
    /** table => the columns compared byte for byte */
    private const array KEY_COLUMNS = [
        'cyllene_translation_override' => ['translation_key', 'catalogue', 'locale', 'scope'],
        'cyllene_translation_suggestion' => ['translation_key', 'catalogue', 'locale', 'scope'],
        'cyllene_translation_scope_parameters' => ['scope'],
    ];

    public function postGenerateSchema(GenerateSchemaEventArgs $args): void
    {
        $platform = $args->getEntityManager()->getConnection()->getDatabasePlatform();

        if (!$platform instanceof AbstractMySQLPlatform) {
            return;
        }

        $collation = match (true) {
            $platform instanceof MariaDBPlatform => 'utf8mb4_nopad_bin',
            $platform instanceof MySQL80Platform => 'utf8mb4_0900_bin',
            // MySQL 5.7 has no NO PAD binary collation: the closest one.
            default => 'utf8mb4_bin',
        };

        $schema = $args->getSchema();

        // DBAL 4.5 deprecates mutating a column in place for its immutable editors, and
        // ORM 3.7 takes the edited schema back through setSchema(). Older DBALs (3.9 to
        // 4.4, which composer.json allows) have only the in-place setters.
        // @phpstan-ignore function.alreadyNarrowedType, function.alreadyNarrowedType (false on the DBAL and ORM floors of composer.json)
        if (method_exists($schema, 'edit') && method_exists($args, 'setSchema')) {
            $editor = $schema->edit();

            foreach (self::KEY_COLUMNS as $table => $columns) {
                if (!$schema->hasTable($table)) {
                    continue;
                }

                $editor->modifyTableByUnquotedName($table, static function (TableEditor $table) use ($columns, $collation): void {
                    foreach ($columns as $column) {
                        $table->modifyColumnByUnquotedName($column, static function (ColumnEditor $column) use ($collation): void {
                            $column->setCharset('utf8mb4')->setCollation($collation);
                        });
                    }
                });
            }

            $args->setSchema($editor->create());

            return;
        }

        foreach (self::KEY_COLUMNS as $table => $columns) {
            if (!$schema->hasTable($table)) {
                continue;
            }

            foreach ($columns as $column) {
                $schema->getTable($table)->getColumn($column)
                    ->setPlatformOption('charset', 'utf8mb4')
                    ->setPlatformOption('collation', $collation);
            }
        }
    }
}
