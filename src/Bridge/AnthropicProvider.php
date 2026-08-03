<?php

declare(strict_types=1);

namespace CylleneDigital\AiTranslationBundle\Bridge;

use CylleneDigital\AiTranslationBundle\Estimation\ModelPriceProvider;
use CylleneDigital\AiTranslationBundle\Provider\TranslationProviderException;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Bridge for the Anthropic Messages API (Claude). Dedicated bridge because the wire
 * format differs from the OpenAI contract: `x-api-key` + `anthropic-version` headers,
 * a top-level `system` field, content blocks in the reply, and no sampling parameters
 * on current models (sending `temperature` would be rejected).
 */
final class AnthropicProvider extends AbstractLlmProvider
{
    public const string DEFAULT_MODEL = 'claude-sonnet-5';
    public const string DEFAULT_BASE_URI = 'https://api.anthropic.com';

    private const string API_VERSION = '2023-06-01';

    /** Output cap of one reply. Truncation is detected rather than left to surface as broken JSON. */
    private const int MAX_TOKENS = 8192;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        string $name,
        #[\SensitiveParameter]
        private readonly string $apiKey,
        string $model = self::DEFAULT_MODEL,
        private readonly string $baseUri = self::DEFAULT_BASE_URI,
        ?string $llmContext = null,
        ?ModelPriceProvider $priceProvider = null,
        // Identity-linked API keys must name the workspace the request acts in;
        // regular workspace-bound keys need no header.
        private readonly ?string $workspaceId = null,
        // Idle timeout of the API calls in seconds — the shared LLM default.
        private readonly int $timeout = DefaultProvider::DEFAULT_TIMEOUT,
    ) {
        parent::__construct($name, $model, $llmContext, $priceProvider);
    }

    public function translate(array $texts, string $sourceLocale, string $targetLocale, string $catalogue): array
    {
        if ([] === $texts) {
            return [];
        }

        try {
            $response = $this->httpClient->request('POST', rtrim($this->baseUri, '/').'/v1/messages', [
                // Symfony only strips Authorization and Cookie when a redirect changes host:
                // the x-api-key header would follow it. The API never redirects.
                'max_redirects' => 0,
                'headers' => array_filter([
                    'x-api-key' => $this->apiKey,
                    'anthropic-version' => self::API_VERSION,
                    'anthropic-workspace-id' => $this->workspaceId,
                    'Content-Type' => 'application/json',
                ], static fn (?string $value): bool => null !== $value),
                'json' => [
                    'model' => $this->model,
                    'max_tokens' => self::MAX_TOKENS,
                    'system' => $this->buildSystemPrompt($sourceLocale, $targetLocale),
                    'messages' => [
                        ['role' => 'user', 'content' => $this->buildUserPrompt($texts, $catalogue)],
                    ],
                ],
                'timeout' => $this->timeout,
                // Idle timeout alone bounds nothing: a backend trickling bytes never trips it.
                // A whole call is capped at four idle windows, so generation_lock_ttl has a
                // ceiling to be calibrated against instead of an open-ended one.
                'max_duration' => $this->timeout * 4,
            ]);

            $data = $response->toArray();
        } catch (ExceptionInterface $e) {
            throw TranslationProviderException::requestFailed($this->name, $this->describeError($e, 'Anthropic'), $e);
        }

        if ('refusal' === ($data['stop_reason'] ?? null)) {
            throw TranslationProviderException::invalidResponse($this->name, 'the request was refused by the model');
        }

        // A truncated reply is cut mid-JSON, which the parser would report as "not valid
        // JSON" — true, but useless: the fix is to send fewer keys per batch, and only
        // this stop_reason says so.
        if ('max_tokens' === ($data['stop_reason'] ?? null)) {
            throw TranslationProviderException::invalidResponse($this->name, \sprintf('the reply hit the %d-token output cap and was truncated — the batch was too large for this model', self::MAX_TOKENS));
        }

        $blocks = $data['content'] ?? [];

        $content = '';
        foreach (\is_array($blocks) ? $blocks : [] as $block) {
            if (\is_array($block) && 'text' === ($block['type'] ?? null) && \is_string($block['text'] ?? null)) {
                $content .= $block['text'];
            }
        }

        if ('' === $content) {
            throw TranslationProviderException::invalidResponse($this->name, 'no text block in the reply');
        }

        return $this->parseTranslations($content, $texts, [
            'type' => 'anthropic',
            'model' => $this->model,
            // The token usage is the whole call's, and every suggestion of the batch carries
            // this metadata: named as the batch's, with the number of keys it covers, so that
            // nobody sums it once per key.
            'batch_usage' => $data['usage'] ?? null,
            'batch_size' => \count($texts),
            // What tells two calls apart: count batch_usage once per distinct batch_id.
            'batch_id' => bin2hex(random_bytes(8)),
        ]);
    }

    /** Anthropic errors always carry {"type": "error", "error": {"type": …, "message": …}}. */
    protected function extractErrorDetail(array $decoded): ?string
    {
        $error = $decoded['error'] ?? null;
        $message = \is_array($error) ? ($error['message'] ?? null) : null;

        return \is_string($message) && '' !== $message ? $message : null;
    }
}
