<?php

declare(strict_types=1);

namespace CylleneDigital\AiTranslationBundle\Bridge;

use CylleneDigital\AiTranslationBundle\Estimation\ModelPriceProvider;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * OpenAI itself — {@see DefaultProvider} plus what only OpenAI offers, and what the
 * generic bridge therefore cannot send: a **strict JSON schema** built on the batch's
 * own keys (the reply shape is enforced by the API instead of being asked for in the
 * prompt), the account-routing headers, and the omission of `temperature` on the
 * reasoning models that reject it.
 *
 * The endpoint and a model are prefilled, and the API key is required: unlike the
 * generic bridge, there is no keyless OpenAI.
 */
final class OpenAiProvider extends DefaultProvider
{
    public const string DEFAULT_MODEL = 'gpt-4o-mini';
    public const string DEFAULT_BASE_URI = 'https://api.openai.com/v1';

    protected const string METADATA_TYPE = 'openai';

    /**
     * Strict schemas are capped by the API (properties, nesting, size). The generator
     * chunks far below it, but a host calling the bridge directly may not: past the
     * ceiling the batch falls back to plain JSON mode rather than failing.
     */
    private const int SCHEMA_MAX_PROPERTIES = 100;

    /**
     * The reasoning families (o-series, GPT-5) refuse `temperature` with a 400 instead
     * of ignoring it — the parameter is simply not part of their contract.
     */
    private const string REASONING_MODELS = '/^(o\d|gpt-5)/';

    public function __construct(
        HttpClientInterface $httpClient,
        string $name,
        #[\SensitiveParameter]
        string $apiKey,
        string $model = self::DEFAULT_MODEL,
        string $baseUri = self::DEFAULT_BASE_URI,
        ?string $llmContext = null,
        ?ModelPriceProvider $priceProvider = null,
        int $timeout = self::DEFAULT_TIMEOUT,
        // Account routing, the counterpart of Anthropic's workspace_id: which
        // organisation and which project the call is attributed to and billed on.
        private readonly ?string $organization = null,
        private readonly ?string $project = null,
        // Off switch for the strict schema — an OpenAI-compatible gateway reached
        // through base_uri may accept the endpoint but not the parameter.
        private readonly bool $structuredOutput = true,
    ) {
        parent::__construct($httpClient, $name, $model, $baseUri, $apiKey, $llmContext, $priceProvider, $timeout);
    }

    protected function requestHeaders(): array
    {
        $headers = parent::requestHeaders();

        if (null !== $this->organization) {
            $headers['OpenAI-Organization'] = $this->organization;
        }

        if (null !== $this->project) {
            $headers['OpenAI-Project'] = $this->project;
        }

        return $headers;
    }

    protected function temperature(): ?float
    {
        return $this->isReasoningModel() ? null : parent::temperature();
    }

    protected function isReasoningModel(): bool
    {
        return 1 === preg_match(self::REASONING_MODELS, $this->model);
    }

    /** OpenAI's current name: the reasoning models refuse "max_tokens". */
    protected function maxTokensField(): string
    {
        return 'max_completion_tokens';
    }

    /** A reasoning model spends part of the cap thinking, before writing a single key. */
    protected function maxOutputTokens(): int
    {
        return $this->isReasoningModel() ? 32768 : parent::maxOutputTokens();
    }

    /**
     * The batch's keys ARE the schema: one required property per key, each an object
     * {"t": …, "c": …}, nothing else allowed. The model can then neither invent a key,
     * nor drop one, nor wrap the object in prose — the three failure modes the prompt
     * can only ask it to avoid.
     */
    protected function responseFormat(array $texts): ?array
    {
        if (!$this->structuredOutput) {
            return null;
        }

        if ([] === $texts || \count($texts) > self::SCHEMA_MAX_PROPERTIES) {
            // JSON mode still guarantees a parseable object; the shape stays on the prompt.
            return ['type' => 'json_object'];
        }

        // Numeric keys come back from array_keys() as ints: the schema wants names.
        $keys = array_map(strval(...), array_keys($texts));
        $properties = [];

        foreach ($keys as $key) {
            $properties[$key] = ['$ref' => '#/$defs/translation'];
        }

        return [
            'type' => 'json_schema',
            'json_schema' => [
                'name' => 'translations',
                'strict' => true,
                'schema' => [
                    'type' => 'object',
                    // Cast so numeric keys ("0", "1") still encode as an object, not a list.
                    'properties' => (object) $properties,
                    'required' => $keys,
                    'additionalProperties' => false,
                    '$defs' => [
                        'translation' => [
                            'type' => 'object',
                            'properties' => [
                                't' => ['type' => 'string', 'description' => 'The translated string.'],
                                'c' => ['type' => 'number', 'description' => 'Confidence between 0 and 1.'],
                            ],
                            'required' => ['t', 'c'],
                            'additionalProperties' => false,
                        ],
                    ],
                ],
            ],
        ];
    }
}
