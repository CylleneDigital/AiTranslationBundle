<?php

declare(strict_types=1);

namespace CylleneDigital\AiTranslationBundle\Tests\Unit\Bridge;

use CylleneDigital\AiTranslationBundle\Bridge\CappedRetryStrategy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpClient\RetryableHttpClient;

/**
 * RetryableHttpClient honours Retry-After before asking the strategy for a delay: the
 * ceiling has to be enforced by refusing the retry, or a provider could hold the worker
 * (and the generation lock) for as long as it likes.
 */
final class CappedRetryStrategyTest extends TestCase
{
    #[DataProvider('retryAfterAboveTheCeiling')]
    public function testARetryAfterAboveTheCeilingIsNotRetried(string $retryAfter): void
    {
        [$client, $requests] = $this->client([new MockResponse('', ['http_code' => 429, 'response_headers' => ['Retry-After' => $retryAfter]])]);

        self::assertSame(429, $client->request('POST', 'https://provider.test/v1')->getStatusCode());
        self::assertSame(1, $requests->count);
    }

    /** @return iterable<string, array{string}> */
    public static function retryAfterAboveTheCeiling(): iterable
    {
        yield 'seconds' => ['600'];
        yield 'HTTP date' => [gmdate('D, d M Y H:i:s', time() + 600).' GMT'];
    }

    public function testARetryAfterWithinTheCeilingIsRetried(): void
    {
        [$client, $requests] = $this->client([
            new MockResponse('', ['http_code' => 429, 'response_headers' => ['Retry-After' => '0']]),
            new MockResponse('{}', ['http_code' => 200]),
        ]);

        self::assertSame(200, $client->request('POST', 'https://provider.test/v1')->getStatusCode());
        self::assertSame(2, $requests->count);
    }

    public function testACodeOutsideTheRetryListIsNotRetried(): void
    {
        [$client, $requests] = $this->client([new MockResponse('', ['http_code' => 500])]);

        self::assertSame(500, $client->request('POST', 'https://provider.test/v1')->getStatusCode());
        self::assertSame(1, $requests->count);
    }

    /**
     * @param list<MockResponse> $responses
     *
     * @return array{RetryableHttpClient, object{count: int}}
     */
    private function client(array $responses): array
    {
        $requests = new class {
            public int $count = 0;
        };

        $mock = new MockHttpClient(static function () use (&$responses, $requests): MockResponse {
            ++$requests->count;

            return array_shift($responses) ?? throw new \LogicException('Unexpected extra request.');
        });

        return [new RetryableHttpClient($mock, new CappedRetryStrategy([429, 503, 529], 0, 2.0, 30000), 2), $requests];
    }
}
