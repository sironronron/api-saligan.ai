<?php

use App\Services\Billing\EarningsModel;

it('computes per-message cost in pesos for each provider', function (string $provider, float $expected) {
    expect(EarningsModel::perMessageCostPesos($provider, 57.0))->toBe($expected);
})->with([
    ['gemini-flash', (11_000 / 1_000_000 * 1.50 + 1_000 / 1_000_000 * 7.50) * 57.0],
    ['gpt-4o-mini', (11_000 / 1_000_000 * 0.15 + 1_000 / 1_000_000 * 0.60) * 57.0],
    ['gpt-4o', (11_000 / 1_000_000 * 2.50 + 1_000 / 1_000_000 * 10.00) * 57.0],
]);

it('cheapens the message with context caching', function () {
    $uncached = EarningsModel::perMessageCostPesos('gemini-flash', 57.0);
    $cached = EarningsModel::perMessageCostPesos('gemini-flash', 57.0, cached: true);

    expect($cached)->toBeLessThan($uncached);
});

it('throws for an unknown provider', function () {
    EarningsModel::perMessageCostPesos('nope', 57.0);
})->throws(InvalidArgumentException::class);

it('computes the PayMongo fee from centavos', function () {
    expect(EarningsModel::paymongoFee(440_000))->toBe(4400 * 0.035 + 15.0);
});

it('computes the embedding cost per document in pesos', function () {
    expect(EarningsModel::embeddingCostPerDocumentPesos(57.0))
        ->toBe(0.10 * 25_000 / 1_000_000 * 57.0);
});

it('computes a full plan earnings breakdown in pesos', function () {
    $e = EarningsModel::earnings(
        priceCents: 440_000,
        messageCap: 2_000,
        documentCap: 100,
        providerMix: ['gemini-flash' => 1.0],
        exchangeRate: 57.0,
    );

    expect($e['price_pesos'])->toBe(4400)
        ->and($e['messages'])->toBe(2_000)
        ->and($e['message_cost_pesos'])->toBe(round(EarningsModel::perMessageCostPesos('gemini-flash', 57.0), 2))
        ->and($e['embeddings_pesos'])->toBe(round(EarningsModel::embeddingCostPerDocumentPesos(57.0) * 100, 2))
        ->and($e['web_search_pesos'])->toBe(round(EarningsModel::webSearchCostPerQueryPesos(57.0) * 0.20 * 2_000, 2))
        ->and($e['ai_cogs_pesos'])->toBe(round(2_000 * EarningsModel::perMessageCostPesos('gemini-flash', 57.0), 2))
        ->and($e['paymongo_pesos'])->toBe(round(4400 * 0.035 + 15.0, 2));
});

it('counts assumed embeddings for uncapped plans', function () {
    $e = EarningsModel::earnings(
        priceCents: 890_000,
        messageCap: 10_000,
        documentCap: null,
        providerMix: ['gemini-flash' => 1.0],
        exchangeRate: 57.0,
        assumedDocuments: 500,
    );

    expect($e['embeddings_pesos'])
        ->toBe(round(EarningsModel::embeddingCostPerDocumentPesos(57.0) * 500, 2));
});

it('rejects a provider mix that does not sum to one', function () {
    EarningsModel::earnings(
        priceCents: 150_000,
        messageCap: 200,
        documentCap: 10,
        providerMix: ['gemini-flash' => 0.5, 'gpt-4o-mini' => 0.25],
        exchangeRate: 57.0,
    );
})->throws(InvalidArgumentException::class);

it('computes the firm break-even message count including embeddings', function () {
    $breakEven = EarningsModel::breakEvenMessages(
        priceCents: 890_000,
        documentCap: null,
        providerMix: ['gemini-flash' => 1.0],
        exchangeRate: 57.0,
        assumedDocuments: 500,
    );

    $fixedCosts = EarningsModel::paymongoFee(890_000)
        + EarningsModel::embeddingCostPerDocumentPesos(57.0) * 500;
    $perMessage = EarningsModel::perMessageCostPesos('gemini-flash', 57.0)
        + 0.20 * EarningsModel::webSearchCostPerQueryPesos(57.0);

    $expected = (int) ceil((890_000 / 100 - $fixedCosts) / $perMessage);

    expect($breakEven)->toBe($expected);
});

it('prints the earnings report in pesos', function () {
    $this->artisan('costing:earnings')
        ->expectsOutputToContain('Starter')
        ->expectsOutputToContain('Pro')
        ->expectsOutputToContain('Firm')
        ->expectsOutputToContain('₱/msg')
        ->assertExitCode(0);
});
