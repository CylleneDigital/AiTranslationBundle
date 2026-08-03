<?php

declare(strict_types=1);

namespace CylleneDigital\AiTranslationBundle\Tests;

use PHPUnit\Framework\Assert;

/**
 * Reading untyped data in a test — what a bridge sent through a MockHttpClient (options
 * and decoded JSON bodies are mixed all the way down), a processed configuration tree.
 * Each step asserts what it reads, so a wrong shape fails on the field that is off
 * rather than on a type error.
 */
trait InspectsUntypedData
{
    /**
     * @param array<mixed> $options the options the MockHttpClient callback receives
     *
     * @return array<mixed>
     */
    private static function jsonBody(array $options): array
    {
        Assert::assertIsString($options['body'] ?? null);
        $body = json_decode($options['body'], true, 512, \JSON_THROW_ON_ERROR);
        Assert::assertIsArray($body);

        return $body;
    }

    /**
     * @param array<mixed> $options
     */
    private static function header(array $options, string $name): ?string
    {
        $headers = $options['normalized_headers'] ?? [];
        Assert::assertIsArray($headers);
        $values = $headers[strtolower($name)] ?? null;

        if (null === $values) {
            return null;
        }

        Assert::assertIsArray($values);
        Assert::assertIsString($values[0] ?? null);

        return $values[0];
    }

    /**
     * Walks $data along $path, asserting every level is an array holding the next key.
     */
    private static function at(mixed $data, int|string ...$path): mixed
    {
        foreach ($path as $key) {
            Assert::assertIsArray($data);
            Assert::assertArrayHasKey($key, $data);
            $data = $data[$key];
        }

        return $data;
    }

    /** {@see at()}, for a string — a prompt, a header value. */
    private static function stringAt(mixed $data, int|string ...$path): string
    {
        $value = self::at($data, ...$path);
        Assert::assertIsString($value);

        return $value;
    }
}
