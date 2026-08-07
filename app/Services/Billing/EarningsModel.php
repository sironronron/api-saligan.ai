<?php

namespace App\Services\Billing;

use InvalidArgumentException;

/**
 * Cost model for per-seat monthly earnings based on current plan pricing
 * (PHP, centavos), per-plan message caps, and per-message token costs.
 *
 * All monetary outputs are in Philippine pesos. Token assumptions are derived
 * from the actual system prompts and retrieval/history sizes used by the chat
 * feature. Chat providers invoice in USD, so the configured exchange rate
 * converts those to pesos.
 */
final class EarningsModel
{
    public const INPUT_TOKENS_PER_MESSAGE = 11_000;

    public const OUTPUT_TOKENS_PER_MESSAGE = 1_000;

    public const SYSTEM_PROMPT_TOKENS = 8_000;

    public const EMBEDDING_TOKENS_PER_DOCUMENT = 25_000;

    public const PAYMONGO_PERCENT = 0.035;

    public const PAYMONGO_FIXED_PESOS = 15.0;

    public const WEB_SEARCH_USD_PER_QUERY = 0.014;

    /**
     * Assumed monthly document uploads for plans without a document cap.
     */
    public const ASSUMED_UNLIMITED_DOCUMENTS = 500;

    /**
     * Chat provider prices in USD per 1M tokens (uncached input / output).
     *
     * @var array<string, array{label: string, input: float, output: float, cached_input: float}>
     */
    private const PROVIDERS = [
        'gemini-flash' => ['label' => 'Gemini 3.6 Flash', 'input' => 1.50, 'output' => 7.50, 'cached_input' => 0.15],
        'gpt-4o-mini' => ['label' => 'gpt-4o-mini', 'input' => 0.15, 'output' => 0.60, 'cached_input' => 0.15],
        'gpt-4o' => ['label' => 'gpt-4o', 'input' => 2.50, 'output' => 10.00, 'cached_input' => 2.50],
    ];

    public const EMBEDDING_PROVIDER = 'gemini-embedding-2';

    private const EMBEDDING_USD_PER_1M_TOKENS = 0.10;

    /**
     * Default plan definitions (price in centavos, message cap, per-message
     * overage price in centavos) used when the database is empty. Mirrors
     * database/seeders/PlansSeeder.php.
     *
     * @return array<int, array{slug: string, name: string, price: int, messages: int, documents: ?int, overage: ?int}>
     */
    public static function defaultPlans(): array
    {
        return [
            ['slug' => 'starter', 'name' => 'Starter', 'price' => 150_000, 'messages' => 200, 'documents' => 10, 'overage' => null],
            ['slug' => 'pro', 'name' => 'Pro', 'price' => 200_000, 'messages' => 500, 'documents' => 100, 'overage' => 350],
            ['slug' => 'firm', 'name' => 'Firm', 'price' => 890_000, 'messages' => 3_000, 'documents' => null, 'overage' => 300],
        ];
    }

    /**
     * Per-message chat cost for a provider, in pesos.
     */
    public static function perMessageCostPesos(string $provider, float $exchangeRate, bool $cached = false): float
    {
        return self::perMessageCostUsd($provider, $cached) * $exchangeRate;
    }

    /**
     * Estimated embedding cost for a single uploaded document, in pesos.
     *
     * @param  float  $tokensPerDocument  Average tokens embedded per document.
     */
    public static function embeddingCostPerDocumentPesos(
        float $exchangeRate,
        float $tokensPerDocument = self::EMBEDDING_TOKENS_PER_DOCUMENT,
    ): float {
        return self::EMBEDDING_USD_PER_1M_TOKENS * $tokensPerDocument / 1_000_000 * $exchangeRate;
    }

    /**
     * Web search grounding cost per query, in pesos.
     */
    public static function webSearchCostPerQueryPesos(float $exchangeRate): float
    {
        return self::WEB_SEARCH_USD_PER_QUERY * $exchangeRate;
    }

    /**
     * PayMongo processing fee for a monthly charge, in pesos.
     */
    public static function paymongoFee(int $priceCents): float
    {
        $pesos = $priceCents / 100;

        return $pesos * self::PAYMONGO_PERCENT + self::PAYMONGO_FIXED_PESOS;
    }

    /**
     * Message count at which revenue covers AI, search, PayMongo, and
     * embedding costs.
     *
     * @param  array<string, float>  $providerMix
     * @param  int  $assumedDocuments  Monthly documents when the plan has no cap.
     */
    public static function breakEvenMessages(
        int $priceCents,
        ?int $documentCap,
        array $providerMix,
        float $exchangeRate,
        bool $cached = false,
        float $webSearchRate = 0.20,
        int $assumedDocuments = self::ASSUMED_UNLIMITED_DOCUMENTS,
    ): int {
        $revenuePesos = $priceCents / 100;
        $documentCount = $documentCap ?? $assumedDocuments;

        $fixedCosts = self::paymongoFee($priceCents)
            + self::embeddingCostPerDocumentPesos($exchangeRate) * $documentCount;

        $perMessage = self::blendedMessageCostPesos($providerMix, $exchangeRate, $cached)
            + $webSearchRate * self::webSearchCostPerQueryPesos($exchangeRate);

        $total = $revenuePesos - $fixedCosts;
        if ($perMessage <= 0 || $total <= 0) {
            return 0;
        }

        return (int) ceil($total / $perMessage);
    }

    /**
     * Full per-plan monthly earnings breakdown, in pesos.
     *
     * @param  array<string, float>  $providerMix  e.g. ['gemini-flash' => 0.7, 'gpt-4o-mini' => 0.3]
     * @param  int  $assumedDocuments  Monthly documents when the plan has no cap.
     * @return array{
     *     price_cents: int,
     *     price_pesos: float,
     *     messages: int,
     *     message_cost_pesos: float,
     *     ai_cogs_pesos: float,
     *     embeddings_pesos: float,
     *     web_search_pesos: float,
     *     paymongo_pesos: float,
     *     net_pesos: float,
     *     margin: float,
     * }
     */
    public static function earnings(
        int $priceCents,
        int $messageCap,
        ?int $documentCap,
        array $providerMix,
        float $exchangeRate,
        bool $cached = false,
        float $webSearchRate = 0.20,
        int $assumedDocuments = self::ASSUMED_UNLIMITED_DOCUMENTS,
    ): array {
        self::assertProviderMix($providerMix);

        $pricePesos = $priceCents / 100;
        $documentCount = $documentCap ?? $assumedDocuments;

        $messageCost = self::blendedMessageCostPesos($providerMix, $exchangeRate, $cached);

        $aiCogs = $messageCost * $messageCap;
        $embeddings = self::embeddingCostPerDocumentPesos($exchangeRate) * $documentCount;
        $webSearch = self::webSearchCostPerQueryPesos($exchangeRate) * ($webSearchRate * $messageCap);
        $paymongo = self::paymongoFee($priceCents);

        $net = $pricePesos - $aiCogs - $embeddings - $webSearch - $paymongo;

        return [
            'price_cents' => $priceCents,
            'price_pesos' => $pricePesos,
            'messages' => $messageCap,
            'message_cost_pesos' => round($messageCost, 2),
            'ai_cogs_pesos' => round($aiCogs, 2),
            'embeddings_pesos' => round($embeddings, 2),
            'web_search_pesos' => round($webSearch, 2),
            'paymongo_pesos' => round($paymongo, 2),
            'net_pesos' => round($net, 2),
            'margin' => round($pricePesos > 0 ? $net / $pricePesos : 0, 4),
        ];
    }

    /**
     * Provider metadata for display.
     *
     * @return array{label: string, input: float, output: float, cached_input: float}
     */
    public static function provider(string $provider): array
    {
        if (! isset(self::PROVIDERS[$provider])) {
            throw new InvalidArgumentException("Unknown provider [$provider].");
        }

        return self::PROVIDERS[$provider];
    }

    /**
     * Blended per-message chat cost across a provider mix, in pesos.
     *
     * @param  array<string, float>  $providerMix
     */
    private static function blendedMessageCostPesos(
        array $providerMix,
        float $exchangeRate,
        bool $cached,
    ): float {
        $cost = 0.0;

        foreach ($providerMix as $provider => $share) {
            $cost += $share * self::perMessageCostUsd($provider, $cached);
        }

        return $cost * $exchangeRate;
    }

    /**
     * Per-message chat cost for a provider, in USD.
     */
    private static function perMessageCostUsd(string $provider, bool $cached = false): float
    {
        $p = self::provider($provider);

        $inputPrice = $cached
            ? self::cachedInputCost($provider)
            : $p['input'] * self::INPUT_TOKENS_PER_MESSAGE / 1_000_000;

        $outputCost = $p['output'] * self::OUTPUT_TOKENS_PER_MESSAGE / 1_000_000;

        return $inputPrice + $outputCost;
    }

    /**
     * Input cost of the static system prompt per message when cached, in USD.
     */
    private static function cachedInputCost(string $provider): float
    {
        $p = self::provider($provider);

        return $p['cached_input'] * self::SYSTEM_PROMPT_TOKENS / 1_000_000
            + $p['input'] * (self::INPUT_TOKENS_PER_MESSAGE - self::SYSTEM_PROMPT_TOKENS) / 1_000_000;
    }

    /**
     * @param  array<string, float>  $providerMix
     */
    private static function assertProviderMix(array $providerMix): void
    {
        foreach ($providerMix as $provider => $share) {
            self::provider($provider);

            if ($share < 0) {
                throw new InvalidArgumentException("Provider [$provider] share cannot be negative.");
            }
        }

        if (abs(array_sum($providerMix) - 1.0) > 0.0001) {
            throw new InvalidArgumentException('Provider mix shares must sum to 1.');
        }
    }
}
