<?php

declare(strict_types=1);

namespace CylleneDigital\AiTranslationBundle\Exception;

/**
 * Implemented by every exception a host is expected to handle — a refused approval, an
 * unusable import file, an unknown provider, a provider failure… — so that it can catch
 * them all in one place. Each one also extends the SPL exception that describes it
 * (\InvalidArgumentException, \RuntimeException…). Programming errors stay plain SPL
 * exceptions: a generation run inside a transaction, an import or export format outside
 * OverrideExporter::FORMATS, two providers with the same name.
 */
interface ExceptionInterface extends \Throwable
{
}
