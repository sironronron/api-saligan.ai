<?php

use App\Enums\MessageRole;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Export\DocumentExportService;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->pro = Plan::factory()->pro()->create();
    Subscription::factory()->for($this->user)->create(['plan_id' => $this->pro->id]);
});

it('exports a marked document as a valid PDF', function () {
    $conversation = Conversation::factory()->for($this->user)->create(['title' => 'Demand letter']);

    $message = Message::factory()->create([
        'conversation_id' => $conversation->id,
        'role' => MessageRole::Assistant,
        'content' => "Here is your draft.\n\n[[DOCUMENT_START]]\nREPUBLIC OF THE PHILIPPINES\nDEMAND LETTER\nVery truly yours,\n[[DOCUMENT_END]]\n\n[Download as Word](/api/messages/abc/export/word)\n[Download as PDF](/api/messages/abc/export/pdf)",
    ]);

    $response = $this->signInAs($this->user)
        ->post("/api/messages/{$message->id}/export/pdf")
        ->assertOk();

    expect($response->headers->get('Content-Type'))->toContain('application/pdf')
        ->and(substr($response->streamedContent(), 0, 5))->toBe('%PDF-');
});

it('exports an unmarked legacy message as a valid PDF', function () {
    $conversation = Conversation::factory()->for($this->user)->create(['title' => 'Legacy reply']);

    $message = Message::factory()->create([
        'conversation_id' => $conversation->id,
        'role' => MessageRole::Assistant,
        'content' => 'A plain reply without document markers.',
    ]);

    $response = $this->signInAs($this->user)
        ->post("/api/messages/{$message->id}/export/pdf")
        ->assertOk();

    expect($response->headers->get('Content-Type'))->toContain('application/pdf');
});

it('exports a marked document as a Word file', function () {
    $conversation = Conversation::factory()->for($this->user)->create(['title' => 'Affidavit']);

    $message = Message::factory()->create([
        'conversation_id' => $conversation->id,
        'role' => MessageRole::Assistant,
        'content' => "Preamble.\n\n[[DOCUMENT_START]]\nAFFIDAVIT OF LOSS\n[[DOCUMENT_END]]\n\nTrailing chat text.",
    ]);

    $response = $this->signInAs($this->user)
        ->post("/api/messages/{$message->id}/export/word")
        ->assertOk();

    expect($response->headers->get('Content-Type'))->toContain('openxmlformats');
});

it('forbids exporting another users message', function () {
    $conversation = Conversation::factory()->for($this->user)->create();

    $message = Message::factory()->create([
        'conversation_id' => $conversation->id,
        'role' => MessageRole::Assistant,
        'content' => 'REPUBLIC OF THE PHILIPPINES',
    ]);

    $other = User::factory()->create();
    Subscription::factory()->for($other)->create(['plan_id' => $this->pro->id]);

    $this->signInAs($other)
        ->post("/api/messages/{$message->id}/export/pdf")
        ->assertStatus(403);
});

it('derives the export filename from the document content, not the thread name', function () {
    $conversation = Conversation::factory()->for($this->user)->create(['title' => 'Demand letter to Juan']);

    $message = Message::factory()->create([
        'conversation_id' => $conversation->id,
        'role' => MessageRole::Assistant,
        'content' => "Here is your draft.\n\n[[DOCUMENT_START]]\nREPUBLIC OF THE PHILIPPINES\nDEMAND LETTER\nVery truly yours,\n[[DOCUMENT_END]]",
    ]);

    $response = $this->signInAs($this->user)
        ->post("/api/messages/{$message->id}/export/pdf")
        ->assertOk();

    $disposition = $response->headers->get('Content-Disposition');
    expect($disposition)
        ->toContain('REPUBLIC_OF_THE_PHILIPPINES')
        ->not->toContain('Demand_letter_to_Juan');
});

it('does not print the thread name inside the exported PDF', function () {
    $conversation = Conversation::factory()->for($this->user)->create(['title' => 'Demand letter to Juan']);

    $message = Message::factory()->create([
        'conversation_id' => $conversation->id,
        'role' => MessageRole::Assistant,
        'content' => "[[DOCUMENT_START]]\nREPUBLIC OF THE PHILIPPINES\nDEMAND LETTER\nVery truly yours,\n[[DOCUMENT_END]]",
    ]);

    $service = new DocumentExportService;
    $html = $service->toPdfHtml($message->content, 'REPUBLIC OF THE PHILIPPINES');

    expect($html)
        ->toContain('<title>REPUBLIC OF THE PHILIPPINES</title>')
        ->not->toContain('Demand letter to Juan');
});

it('renders peso amounts as PHP in exported documents', function () {
    $service = new DocumentExportService;

    $body = $service->extractDocument(
        "[[DOCUMENT_START]]\nDEMAND LETTER\n\n"
        .'We demand payment of Three Million Pesos (₱3,000,000.00) for the 1,200 sqm portion, '
        ."plus ₱ 25,000.00 in costs.\n\nVery truly yours,\n[[DOCUMENT_END]]"
    );

    // The PDF renderer's built-in font has no glyph for U+20B1, so any peso
    // sign that survives to the exported file prints as "?".
    expect($body)
        ->toContain('Three Million Pesos (PHP 3,000,000.00)')
        ->toContain('plus PHP 25,000.00 in costs')
        ->not->toContain('₱');
});
