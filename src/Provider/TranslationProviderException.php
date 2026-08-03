<?php

declare(strict_types=1);

namespace CylleneDigital\AiTranslationBundle\Provider;

use CylleneDigital\AiTranslationBundle\Exception\ExceptionInterface;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

#[Exclude]
final class TranslationProviderException extends \RuntimeException implements ExceptionInterface
{
    private function __construct(
        string $message,
        private readonly bool $invalidResponse = false,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    /** The call itself failed (network, auth, quota): the next calls would fail too. */
    public static function requestFailed(string $provider, string $message, ?\Throwable $previous = null): self
    {
        return new self(\sprintf('[%s] Request failed: %s', $provider, $message), previous: $previous);
    }

    /** The call went through but its reply is unusable: only that batch is lost. */
    public static function invalidResponse(string $provider, string $message): self
    {
        return new self(\sprintf('[%s] Invalid response: %s', $provider, $message), invalidResponse: true);
    }

    public function isInvalidResponse(): bool
    {
        return $this->invalidResponse;
    }
}
