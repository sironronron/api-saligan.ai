<?php

use App\Models\Conversation;
use App\Models\Document;
use App\Models\Label;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Ai\EmbeddingService;
use App\Services\Ai\PythonAiClient;
use App\Services\Crawler\LegalDigestService;
use App\Services\Documents\DocumentClassifier;
use App\Services\Documents\ImageOcrExtractor;
use App\Services\LetterDrafts\LetterDraftService;
use App\Services\TextRewrite\TextRewriteService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

beforeEach(function () {
    config([
        'saligan.ai_provider.url' => 'http://ai-provider.test',
        'saligan.ai_provider.internal_secret' => 'shared-secret',
        'saligan.chat.engine' => 'python',
    ]);

    Http::preventStrayRequests();
});

it('streams bytes from the Python service with internal authentication', function () {
    $sse = "event: delta\ndata: {\"delta\":\"Hello\"}\n\nevent: done\ndata: {\"ok\":true,\"web_citations\":0}\n\n";
    Http::fake(['ai-provider.test/*' => Http::response($sse, 200)]);

    $conversationId = (string) Str::uuid();
    $client = app(PythonAiClient::class);
    $response = $client->streamChat($conversationId, 'Hello', [], false, false);
    $body = implode('', iterator_to_array($client->body($response)));

    expect($body)->toBe($sse);
    Http::assertSent(fn (Request $request): bool => $request->url() === "http://ai-provider.test/chat/{$conversationId}/stream"
        && $request->hasHeader('Authorization', 'Bearer shared-secret')
        && $request['message'] === 'Hello');
});

it('uses the Python stream behind the chat feature flag without changing SSE bytes', function () {
    $sse = "event: status\ndata: {\"status\":\"composing\",\"label\":\"Writing your answer\"}\n\nevent: delta\ndata: {\"delta\":\"Proxied.\"}\n\nevent: done\ndata: {\"ok\":true,\"web_citations\":0}\n\n";
    Http::fake(['ai-provider.test/*' => Http::response($sse, 200)]);

    $user = User::factory()->create();
    Subscription::factory()->for($user)->create([
        'plan_id' => Plan::factory()->pro()->create()->id,
    ]);
    $conversation = Conversation::factory()->for($user)->create();

    $body = $this->signInAs($user)
        ->post("/api/conversations/{$conversation->id}/messages", ['message' => 'Answer this.'])
        ->assertOk()
        ->streamedContent();

    expect($body)->toBe($sse);
});

it('routes embeddings, OCR, digests, rewrites, and letters through Python', function () {
    config(['saligan.ai_provider.batch_engine' => 'python']);

    Http::fake(function (Request $request) {
        return match ($request->url()) {
            'http://ai-provider.test/embeddings' => Http::response([
                'embeddings' => [[0.1, 0.2]],
                'dimensions' => 2,
            ]),
            'http://ai-provider.test/documents/ocr' => Http::response(['text' => 'Read text']),
            'http://ai-provider.test/documents/classify' => Http::response([
                'categories' => [['slug' => 'pleading', 'confidence' => 0.92]],
            ]),
            'http://ai-provider.test/crawler/digest' => Http::response(['digest' => 'Nature: A test']),
            'http://ai-provider.test/agents/rewrite' => Http::response(['text' => 'Rewritten text']),
            'http://ai-provider.test/agents/letter' => Http::response([
                'title' => 'Demand Letter',
                'content' => [
                    'type' => 'doc',
                    'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Dear Sir']]]],
                ],
            ]),
            default => Http::response(['message' => 'Unexpected request'], 500),
        };
    });

    $path = tempnam(sys_get_temp_dir(), 'ocr-test-');
    file_put_contents($path, 'image bytes');

    try {
        expect(app(EmbeddingService::class)->embedMany(['one']))->toBe([[0.1, 0.2]])
            ->and(app(ImageOcrExtractor::class)->extract($path, 'image/png'))->toBe('Read text')
            ->and(app(LegalDigestService::class)->generate('Authority text'))->toBe('Nature: A test')
            ->and(app(TextRewriteService::class)->rewrite('Old text', 'Clarify'))->toBe('Rewritten text');

        $letter = app(LetterDraftService::class)->generate('Write a demand letter', null);
        expect($letter['title'])->toBe('Demand Letter')
            ->and($letter['content']['type'])->toBe('doc');

        $label = Label::factory()->create(['slug' => 'pleading']);
        $document = Document::factory()->for(User::factory())->create();
        $suggestions = app(DocumentClassifier::class)->suggest(
            $document,
            'A complaint pleading.',
            collect([$label]),
        );
        expect($suggestions[0]['label']->id)->toBe($label->id)
            ->and($suggestions[0]['confidence'])->toBe(0.92);
    } finally {
        @unlink($path);
    }

    Http::assertSentCount(6);
});
