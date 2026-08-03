<?php

declare(strict_types=1);

namespace CylleneDigital\AiTranslationBundle\Transfer;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

/**
 * Restricts an export to the overrides matching a caller's current view. Every
 * criterion is optional; null means "do not restrict on this axis". Note the scope
 * asymmetry: null exports every scope, '' exports the GLOBAL overrides only — the
 * distinction mirrors catalogue browsing, where '' is the (default) global view.
 *
 * A plain carrier: the narrowing itself happens in SQL
 * ({@see \CylleneDigital\AiTranslationBundle\Repository\TranslationOverrideRepository::findFilteredRowsForExport()}),
 * so an export never hydrates rows it is about to discard.
 */
#[Exclude]
final class OverrideExportFilter
{
    public function __construct(
        public readonly ?string $locale = null,
        public readonly ?string $scope = null,
        /** A full catalogue identifier ("shop/Product/messages") or any prefix of one ("shop", "shop/Product"). */
        public readonly ?string $catalogue = null,
        /** Case-insensitive substring, matched against the key and the override value. */
        public readonly ?string $search = null,
    ) {
    }
}
