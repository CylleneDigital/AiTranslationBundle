<?php

declare(strict_types=1);

namespace CylleneDigital\AiTranslationBundle\Bridge;

use CylleneDigital\AiTranslationBundle\Estimation\ModelPriceProvider;
use CylleneDigital\AiTranslationBundle\Provider\TranslationProviderException;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * The default bridge: any API exposing the `/chat/completions` contract — Mistral,
 * Groq, Together, OpenRouter, a LiteLLM proxy, a local Ollama or vLLM, … That contract
 * is the one interoperability standard of the field, which is why it is the default
 * rather than a vendor-specific one.
 *
 * Nothing is assumed about the backend: `base_uri` and `model` are required (there is
 * no sensible default for "any API"), and `api_key` stays optional for keyless local
 * servers. {@see OpenAiProvider} is this bridge pinned to OpenAI itself.
 *
 * @internal only open for {@see OpenAiProvider}. A custom provider implements
 *           {@see \CylleneDigital\AiTranslationBundle\Provider\TranslationAiProviderInterface}.
 */
class DefaultProvider extends AbstractLlmProvider
{
    /**
     * Idle timeout of the API calls in seconds — a slow local server (Ollama on CPU…)
     * can stay silent far longer than a hosted API.
     */
    public const int DEFAULT_TIMEOUT = 120;

    /** Stored on every suggestion: the wire contract that produced it, not the vendor. */
    protected const string METADATA_TYPE = 'chat_completions';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        string $name,
        string $model,
        private readonly string $baseUri,
        #[\SensitiveParameter]
        private readonly ?string $apiKey = null,
        ?string $llmContext = null,
        ?ModelPriceProvider $priceProvider = null,
        private readonly int $timeout = self::DEFAULT_TIMEOUT,
    ) {
        parent::__construct($name, $model, $llmContext, $priceProvider);
    }

    public function translate(array $texts, string $sourceLocale, string $targetLocale, string $catalogue): array
    {
        if ([] === $texts) {
            return [];
        }

        $payload = [
            'model' => $this->model,
            'messages' => [
                ['role' => 'system', 'content' => $this->buildSystemPrompt($sourceLocale, $targetLocale)],
                ['role' => 'user', 'content' => $this->buildUserPrompt($texts, $catalogue)],
            ],
        ];

        // A bounded bill per call: a model that loops cannot write up to its own maximum.
        $payload[$this->maxTokensField()] = $this->maxOutputTokens();

        if (null !== ($temperature = $this->temperature())) {
            $payload['temperature'] = $temperature;
        }

        if (null !== ($responseFormat = $this->responseFormat($texts))) {
            $payload['response_format'] = $responseFormat;
        }

        try {
            $response = $this->httpClient->request('POST', rtrim($this->baseUri, '/').'/chat/completions', [
                // An API endpoint never redirects: one would only come from a misconfigured
                // base_uri (a proxy, a gateway), and would carry the credentials along.
                'max_redirects' => 0,
                'headers' => $this->requestHeaders(),
                'json' => $payload,
                'timeout' => $this->timeout,
                // Idle timeout alone bounds nothing: a backend trickling bytes never trips it.
                // A whole call is capped at four idle windows, so generation_lock_ttl has a
                // ceiling to be calibrated against instead of an open-ended one.
                'max_duration' => $this->timeout * 4,
            ]);

            $data = $response->toArray();
        } catch (ExceptionInterface $e) {
            throw TranslationProviderException::requestFailed($this->name, $this->describeError($e, 'the API'), $e);
        }

        $choice = \is_array($data['choices'] ?? null) ? ($data['choices'][0] ?? null) : null;
        $message = \is_array($choice) ? ($choice['message'] ?? null) : null;
        $content = \is_array($message) ? ($message['content'] ?? null) : null;

        // A truncated reply is not "invalid JSON": say what actually happened.
        if (\is_array($choice) && 'length' === ($choice['finish_reason'] ?? null)) {
            throw TranslationProviderException::invalidResponse($this->name, \sprintf('the reply hit the %d-token output cap and was truncated — the batch was too large for this model', $this->maxOutputTokens()));
        }

        if (!\is_string($content)) {
            throw TranslationProviderException::invalidResponse($this->name, 'missing choices[0].message.content');
        }

        return $this->parseTranslations($content, $texts, [
            'type' => static::METADATA_TYPE,
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

    /**
     * Headers of the API call: the Bearer scheme the contract agrees on, and nothing
     * else. A vendor bridge adds its own around `parent::requestHeaders()`.
     *
     * @return array<string, string>
     */
    protected function requestHeaders(): array
    {
        $headers = ['Content-Type' => 'application/json'];

        if (null !== $this->apiKey) {
            $headers['Authorization'] = 'Bearer '.$this->apiKey;
        }

        return $headers;
    }

    /**
     * Sampling temperature, or null to send none. Low but not zero: UI strings need
     * consistency, not determinism. A vendor bridge returns null for the models that
     * reject the parameter.
     */
    protected function temperature(): ?float
    {
        return 0.2;
    }

    /** The request field capping the reply — the historic name every compatible server knows. */
    protected function maxTokensField(): string
    {
        return 'max_tokens';
    }

    /** Far above what a batch needs (~2k tokens for 6000 source characters). */
    protected function maxOutputTokens(): int
    {
        return 8192;
    }

    /**
     * The `response_format` the API should enforce, or null to rely on the prompt and on
     * {@see AbstractLlmProvider::parseTranslations()} alone — the generic case, because
     * the servers speaking this contract disagree on what they support, and one that
     * chokes on the parameter fails the whole call.
     *
     * @param array<string, string> $texts the batch, for a schema built on its keys
     *
     * @return array<string, mixed>|null
     */
    protected function responseFormat(array $texts): ?array
    {
        return null;
    }

    /**
     * The `/chat/completions` servers only agree on the happy path — their error
     * bodies diverge: {"error": {"message": …}} for OpenAI/Groq/Together,
     * {"error": "…"} for Ollama, and a top-level "message" or FastAPI-style
     * "detail" (string, or list of {"msg": …}) for Mistral.
     */
    protected function extractErrorDetail(array $decoded): ?string
    {
        $error = $decoded['error'] ?? null;

        if (\is_array($error) && \is_string($error['message'] ?? null) && '' !== $error['message']) {
            return $error['message'];
        }

        if (\is_string($error) && '' !== $error) {
            return $error;
        }

        if (\is_string($decoded['message'] ?? null) && '' !== $decoded['message']) {
            return $decoded['message'];
        }

        $detail = $decoded['detail'] ?? null;

        if (\is_string($detail) && '' !== $detail) {
            return $detail;
        }

        $first = \is_array($detail) ? ($detail[0] ?? null) : null;

        if (\is_array($first) && \is_string($first['msg'] ?? null) && '' !== $first['msg']) {
            return $first['msg'];
        }

        return null;
    }
}
