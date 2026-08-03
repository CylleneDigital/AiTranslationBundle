<?php

declare(strict_types=1);

namespace CylleneDigital\AiTranslationBundle\Bridge;

use Symfony\Component\HttpClient\Response\AsyncContext;
use Symfony\Component\HttpClient\Retry\GenericRetryStrategy;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;

/**
 * GenericRetryStrategy whose ceiling also binds the provider's Retry-After header.
 *
 * RetryableHttpClient waits for Retry-After before asking the strategy for a delay, so
 * maxDelayMs alone caps nothing when a provider sends one: a "Retry-After: 600" on a 429
 * would hold the worker, and the generation lock, for ten minutes per attempt — long
 * enough for an expiring lock to let a second run bill the same keys. A wait above the
 * ceiling is not retried at all: the request was not processed (429/503/529), so failing
 * it costs nothing, and the run stores the keys as errored suggestions.
 *
 * @internal
 */
final class CappedRetryStrategy extends GenericRetryStrategy
{
    /**
     * @param array<int, int|string[]> $statusCodes
     */
    public function __construct(array $statusCodes, int $delayMs, float $multiplier, private readonly int $maxDelayMs)
    {
        parent::__construct($statusCodes, $delayMs, $multiplier, $maxDelayMs);
    }

    public function shouldRetry(AsyncContext $context, ?string $responseContent, ?TransportExceptionInterface $exception): ?bool
    {
        $headers = $context->getHeaders()['retry-after'] ?? [];
        $retryAfter = \is_array($headers) ? ($headers[0] ?? null) : null;

        if (\is_string($retryAfter) && $this->delayMs($retryAfter) > $this->maxDelayMs) {
            return false;
        }

        return parent::shouldRetry($context, $responseContent, $exception);
    }

    /** Same reading of the header as RetryableHttpClient: seconds, or an HTTP date. */
    private function delayMs(string $retryAfter): int
    {
        if (is_numeric($retryAfter)) {
            return (int) ((float) $retryAfter * 1000);
        }

        $time = strtotime($retryAfter);

        return false === $time ? 0 : max(0, $time - time()) * 1000;
    }
}
