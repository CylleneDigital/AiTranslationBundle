<?php

declare(strict_types=1);

namespace CylleneDigital\AiTranslationBundle\Bridge;

use CylleneDigital\AiTranslationBundle\Provider\ContextAwareProviderInterface;
use CylleneDigital\AiTranslationBundle\Provider\CostEstimatingProviderInterface;
use CylleneDigital\AiTranslationBundle\Provider\ProviderRunEstimate;
use CylleneDigital\AiTranslationBundle\Provider\TranslationProviderException;
use CylleneDigital\AiTranslationBundle\Provider\TranslationResult;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\HttpExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Bridge for the DeepL v2 API — not an LLM: no prompt, the texts are sent as-is and
 * come back in order. Free keys (suffix ":fx") automatically target the free endpoint;
 * `base_uri` overrides the detection if needed.
 *
 * Watch out with DeepL on UI catalogues: unlike the LLM bridges it has no instruction
 * channel, so placeholders like %name% may occasionally be altered — review before
 * approving.
 */
final class DeeplProvider implements ContextAwareProviderInterface, CostEstimatingProviderInterface
{
    private const float DEFAULT_CONFIDENCE = 0.9;

    private readonly string $baseUri;

    /**
     * Run-specific context, sent as DeepL's `context` parameter: text that steers the
     * translation without being translated. Set on a clone by withAdditionalContext().
     */
    private ?string $scopeContext = null;

    /** @var array{count: int, limit: int}|false|null null = not fetched yet, false = fetch failed */
    private array|false|null $usageCache = null;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly string $name,
        #[\SensitiveParameter]
        private readonly string $apiKey,
        ?string $baseUri = null,
        // Idle timeout of the translation calls in seconds (the quota probe keeps
        // its own short one).
        private readonly int $timeout = 60,
    ) {
        $this->baseUri = $baseUri ?? (str_ends_with($apiKey, ':fx') ? 'https://api-free.deepl.com' : 'https://api.deepl.com');
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function withAdditionalContext(?string $context): static
    {
        $context = null === $context ? null : trim($context);

        $clone = clone $this;
        $clone->scopeContext = '' === $context ? null : $context;

        return $clone;
    }

    public function translate(array $texts, string $sourceLocale, string $targetLocale, string $catalogue): array
    {
        if ([] === $texts) {
            return [];
        }

        $payload = [
            'text' => array_values($texts),
            'source_lang' => $this->toSourceLang($sourceLocale),
            'target_lang' => $this->toTargetLang($targetLocale),
            'preserve_formatting' => true,
        ];

        if (null !== $this->scopeContext) {
            $payload['context'] = $this->scopeContext;
        }

        try {
            $response = $this->httpClient->request('POST', rtrim($this->baseUri, '/').'/v2/translate', [
                // The API never redirects: one would only come from a misconfigured base_uri.
                'max_redirects' => 0,
                'headers' => [
                    'Authorization' => 'DeepL-Auth-Key '.$this->apiKey,
                    'Content-Type' => 'application/json',
                ],
                'json' => $payload,
                'timeout' => $this->timeout,
                // Idle timeout alone bounds nothing: a backend trickling bytes never trips it.
                // A whole call is capped at four idle windows, so generation_lock_ttl has a
                // ceiling to be calibrated against instead of an open-ended one.
                'max_duration' => $this->timeout * 4,
            ]);

            $data = $response->toArray();
        } catch (ExceptionInterface $e) {
            throw TranslationProviderException::requestFailed($this->name, $this->describeError($e), $e);
        }

        $translations = $data['translations'] ?? null;

        if (!\is_array($translations)) {
            throw TranslationProviderException::invalidResponse($this->name, 'missing "translations" array');
        }

        $keys = array_keys($texts);
        $results = [];

        foreach ($translations as $index => $translation) {
            $key = $keys[$index] ?? null;
            $text = \is_array($translation) ? ($translation['text'] ?? null) : null;

            if (null === $key || !\is_string($text) || '' === trim($text)) {
                continue;
            }

            $results[$key] = new TranslationResult($text, self::DEFAULT_CONFIDENCE, [
                'type' => 'deepl',
                'detected_source_language' => \is_array($translation) ? ($translation['detected_source_language'] ?? null) : null,
            ]);
        }

        return $results;
    }

    /**
     * DeepL bills source characters against a plan quota, no token nor public price
     * list involved: the forecast is the account's remaining quota. The characters the
     * run would consume are already carried by the volume side of the estimate.
     *
     * @param list<array<string, string>> $chunks
     */
    public function estimateRun(array $chunks, string $sourceLocale, string $targetLocale, string $catalogue): ProviderRunEstimate
    {
        $usage = $this->getUsage();

        if (null === $usage) {
            return new ProviderRunEstimate();
        }

        return new ProviderRunEstimate(
            quotaRemaining: max(0, $usage['limit'] - $usage['count']),
            quotaLimit: $usage['limit'],
        );
    }

    /**
     * The account's character consumption from the official /v2/usage endpoint — DeepL
     * has no machine-readable price list, but "how much of my quota would this burn?"
     * is the question that matters. Memoised for the lifetime of the service (a CLI
     * dry-run estimates one catalogue at a time); null when the endpoint is unreachable
     * or the plan reports no character quota.
     *
     * @return array{count: int, limit: int}|null
     */
    public function getUsage(): ?array
    {
        if (null !== $this->usageCache) {
            return false === $this->usageCache ? null : $this->usageCache;
        }

        try {
            $data = $this->httpClient->request('GET', rtrim($this->baseUri, '/').'/v2/usage', [
                'max_redirects' => 0,
                'headers' => ['Authorization' => 'DeepL-Auth-Key '.$this->apiKey],
                'timeout' => 10,
            ])->toArray();
        } catch (ExceptionInterface) {
            $this->usageCache = false;

            return null;
        }

        if (!is_numeric($data['character_count'] ?? null) || !is_numeric($data['character_limit'] ?? null)) {
            $this->usageCache = false;

            return null;
        }

        return $this->usageCache = [
            'count' => (int) $data['character_count'],
            'limit' => (int) $data['character_limit'],
        ];
    }

    /** DeepL wants a bare language code as source: "fr_FR" → "FR". */
    private function toSourceLang(string $locale): string
    {
        $parts = explode('_', str_replace('-', '_', $locale));

        return strtoupper($parts[0]);
    }

    /**
     * DeepL only accepts a regional variant as target for English and Portuguese, and
     * only two each (EN-US/EN-GB, PT-BR/PT-PT) — "es_ES" must collapse to "ES" (ES-ES is
     * rejected with a 400). Bare "en"/"pt" get the European variant.
     *
     * @see https://developers.deepl.com/docs/getting-started/supported-languages
     */
    private function toTargetLang(string $locale): string
    {
        $parts = explode('_', str_replace('-', '_', $locale));
        $lang = strtoupper($parts[0]);

        // DeepL's plain "ZH" is Simplified Chinese: Taiwan, Hong Kong (and Macau) and the
        // Hant script read Traditional.
        if ('ZH' === $lang) {
            $rest = array_map(strtolower(...), \array_slice($parts, 1));

            return [] !== array_intersect($rest, ['hant', 'tw', 'hk', 'mo']) ? 'ZH-HANT' : 'ZH-HANS';
        }

        // The only regional targets DeepL offers: any other region (en_CA, en_AU, pt_AO…)
        // would be a 400 for the whole run, so it maps to the variant it is written in.
        $region = strtoupper($parts[1] ?? '');

        if ('EN' === $lang) {
            return 'US' === $region ? 'EN-US' : 'EN-GB';
        }

        if ('PT' === $lang) {
            return 'BR' === $region ? 'PT-BR' : 'PT-PT';
        }

        return $lang;
    }

    /**
     * Surfaces the two DeepL-specific quota codes with an actionable message, and
     * appends the API's own explanation (the JSON "message" of the error body) to
     * every HTTP error — a bare "HTTP 400" hides the actual cause.
     */
    private function describeError(ExceptionInterface $e): string
    {
        $message = $e->getMessage();

        // Read from the response, never matched in the message text: "429" also appears
        // in a request id, a URL or a quota figure quoted by the API.
        $status = $e instanceof HttpExceptionInterface ? $e->getResponse()->getStatusCode() : null;

        if (429 === $status) {
            return 'rate limited by DeepL (HTTP 429) — retry later or reduce the batch frequency';
        }

        if (456 === $status) {
            return 'DeepL quota exhausted (HTTP 456) — the plan character limit has been reached';
        }

        $detail = $this->extractApiMessage($e);

        return null !== $detail ? \sprintf('%s — DeepL says: %s', $message, $detail) : $message;
    }

    /** The "message" field of DeepL's JSON error body, when there is one. */
    private function extractApiMessage(ExceptionInterface $e): ?string
    {
        if (!$e instanceof HttpExceptionInterface) {
            return null;
        }

        try {
            $body = $e->getResponse()->getContent(false);
            $decoded = json_decode($body, true);
        } catch (\Throwable) {
            return null;
        }

        $detail = \is_array($decoded) ? ($decoded['message'] ?? null) : null;

        return \is_string($detail) && '' !== $detail ? $detail : null;
    }
}
