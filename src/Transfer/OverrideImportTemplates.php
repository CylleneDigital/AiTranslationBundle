<?php

declare(strict_types=1);

namespace CylleneDigital\AiTranslationBundle\Transfer;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

/**
 * Ready-to-fill sample files an integration can offer for download — the answer to
 * "what does the import expect?" on a fresh install, where exporting a real file as a
 * starting point is impossible (there is nothing to export yet). Each template MUST
 * import cleanly (nothing skipped): the round-trip is pinned by a unit test, so a
 * format change here or in the importer breaks the build, not the user.
 */
#[Exclude]
final class OverrideImportTemplates
{
    public static function get(string $format): ?string
    {
        return match ($format) {
            'xlf' => <<<'XLF'
                <?xml version="1.0" encoding="utf-8"?>
                <xliff xmlns="urn:oasis:names:tc:xliff:document:1.2" version="1.2">
                  <!-- One <file> per (locale, catalogue): the catalogue identifier travels in
                       the "original" attribute, the target locale in "target-language". -->
                  <file source-language="fr_FR" target-language="fr_FR" datatype="plaintext" original="shop/Product/messages">
                    <body>
                      <trans-unit id="1" resname="product.out_of_stock">
                        <source>product.out_of_stock</source>
                        <target>Rupture de stock</target>
                      </trans-unit>
                    </body>
                  </file>
                  <!-- A scoped override carries the scope code in the standard "category"
                       attribute; no attribute means a global override. -->
                  <file source-language="fr_FR" target-language="fr_FR" datatype="plaintext" original="shop/Product/messages" category="FASHION_WEB">
                    <body>
                      <trans-unit id="1" resname="product.out_of_stock">
                        <source>product.out_of_stock</source>
                        <target>Victime de son succès</target>
                      </trans-unit>
                    </body>
                  </file>
                </xliff>

                XLF,
            'csv' => <<<'CSV'
                locale,catalogue,translation_key,value,scope
                fr_FR,shop/Product/messages,product.out_of_stock,"Rupture de stock",
                en_US,messages,app.example,"An example value",
                fr_FR,shop/Product/messages,product.out_of_stock,"Victime de son succès",FASHION_WEB

                CSV,
            default => null,
        };
    }
}
