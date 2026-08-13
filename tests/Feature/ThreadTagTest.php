<?php

use App\Models\Conversation;
use App\Models\Label;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use Database\Seeders\LabelSeeder;

beforeEach(function () {
    (new LabelSeeder)->run();

    $this->user = User::factory()->create();
    Subscription::factory()->for($this->user)->create([
        'plan_id' => Plan::factory()->pro()->create()->id,
    ]);

    $this->tag = fn (string $slug) => Label::where('kind', 'thread_tag')->where('slug', $slug)->firstOrFail();
});

it('tags a thread as it is created', function () {
    $urgent = ($this->tag)('urgent');

    $response = $this->signInAs($this->user)
        ->postJson('/api/conversations', [
            'title' => 'Ejectment strategy',
            'label_ids' => [$urgent->id, ($this->tag)('case-strategy')->id],
        ])
        ->assertCreated();

    expect(collect($response->json('data.tags'))->pluck('slug')->sort()->values()->all())
        ->toBe(['case-strategy', 'urgent']);
});

it('replaces a thread tags on update', function () {
    $conversation = Conversation::factory()->for($this->user)->create();
    $conversation->syncLabels([($this->tag)('drafting')], $this->user);

    $response = $this->signInAs($this->user)
        ->patchJson("/api/conversations/{$conversation->id}", [
            'label_ids' => [($this->tag)('research')->id],
        ])
        ->assertOk();

    expect(collect($response->json('data.tags'))->pluck('slug')->all())->toBe(['research']);
});

it('leaves the tags alone when an update does not mention them', function () {
    $conversation = Conversation::factory()->for($this->user)->create();
    $conversation->syncLabels([($this->tag)('drafting')], $this->user);

    $this->signInAs($this->user)
        ->patchJson("/api/conversations/{$conversation->id}", ['title' => 'Renamed'])
        ->assertOk();

    expect($conversation->fresh()->labels->pluck('slug')->all())->toBe(['drafting'])
        ->and($conversation->fresh()->title)->toBe('Renamed');
});

it('refuses a document category applied as a thread tag', function () {
    $conversation = Conversation::factory()->for($this->user)->create();
    $category = Label::where('kind', 'document_category')->where('slug', 'pleading')->firstOrFail();

    $this->signInAs($this->user)
        ->patchJson("/api/conversations/{$conversation->id}", ['label_ids' => [$category->id]])
        ->assertStatus(422)
        ->assertJsonValidationErrors('label_ids');
});

it('filters threads by tag', function () {
    $urgent = ($this->tag)('urgent');

    $tagged = Conversation::factory()->for($this->user)->create();
    $tagged->syncLabels([$urgent], $this->user);
    Conversation::factory()->for($this->user)->create();

    $response = $this->signInAs($this->user)
        ->getJson("/api/conversations?tag_id[]={$urgent->id}")
        ->assertOk();

    expect(collect($response->json('data'))->pluck('id')->all())->toBe([$tagged->id]);
});

it('caps how many tags a single thread may carry', function () {
    $conversation = Conversation::factory()->for($this->user)->create();
    $ids = Label::where('kind', 'thread_tag')->limit(11)->pluck('id')->all();

    $this->signInAs($this->user)
        ->patchJson("/api/conversations/{$conversation->id}", ['label_ids' => $ids])
        ->assertStatus(422)
        ->assertJsonValidationErrors('label_ids');
});
