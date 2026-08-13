<?php

use App\Models\Document;
use App\Models\Label;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use Database\Seeders\LabelSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    (new LabelSeeder)->run();

    $this->user = User::factory()->create();
    Subscription::factory()->for($this->user)->create([
        'plan_id' => Plan::factory()->pro()->create()->id,
    ]);

    $this->document = Document::factory()->for($this->user)->create();

    $this->category = fn (string $slug) => Label::where('kind', 'document_category')->where('slug', $slug)->firstOrFail();
});

it('files a document under several categories at once', function () {
    $evidence = ($this->category)('evidence-documentary');
    $financial = ($this->category)('financial-record');

    $response = $this->signInAs($this->user)
        ->patchJson("/api/documents/{$this->document->id}", [
            'label_ids' => [$evidence->id, $financial->id],
        ])
        ->assertOk();

    expect(collect($response->json('data.categories'))->pluck('slug')->sort()->values()->all())
        ->toBe(['evidence-documentary', 'financial-record']);

    $this->assertDatabaseHas('labelables', [
        'label_id' => $evidence->id,
        'labelable_id' => $this->document->id,
        'source' => 'user',
        'assigned_by' => $this->user->id,
    ]);
});

it('replaces the whole category set rather than merging into it', function () {
    $this->document->syncLabels([($this->category)('pleading'), ($this->category)('motion')], $this->user);

    $this->signInAs($this->user)
        ->patchJson("/api/documents/{$this->document->id}", [
            'label_ids' => [($this->category)('motion')->id],
        ])
        ->assertOk();

    expect($this->document->fresh()->labels->pluck('slug')->all())->toBe(['motion']);
});

it('clears every category when handed an empty set', function () {
    $this->document->syncLabels([($this->category)('pleading')], $this->user);

    $this->signInAs($this->user)
        ->patchJson("/api/documents/{$this->document->id}", ['label_ids' => []])
        ->assertOk();

    expect($this->document->fresh()->labels)->toHaveCount(0);
});

it('refuses a thread tag filed on a document', function () {
    $tag = Label::where('kind', 'thread_tag')->where('slug', 'urgent')->firstOrFail();

    $this->signInAs($this->user)
        ->patchJson("/api/documents/{$this->document->id}", ['label_ids' => [$tag->id]])
        ->assertStatus(422)
        ->assertJsonValidationErrors('label_ids');
});

it('refuses a label belonging to another organization', function () {
    $foreign = Label::factory()->forOrganization(Organization::factory()->create())->create();

    $this->signInAs($this->user)
        ->patchJson("/api/documents/{$this->document->id}", ['label_ids' => [$foreign->id]])
        ->assertStatus(422)
        ->assertJsonValidationErrors('label_ids');
});

it('caps how many categories a single document may carry', function () {
    $ids = Label::where('kind', 'document_category')->limit(6)->pluck('id')->all();

    $this->signInAs($this->user)
        ->patchJson("/api/documents/{$this->document->id}", ['label_ids' => $ids])
        ->assertStatus(422)
        ->assertJsonValidationErrors('label_ids');
});

it('refuses to categorize another user document', function () {
    $other = Document::factory()->for(User::factory())->create();

    $this->signInAs($this->user)
        ->patchJson("/api/documents/{$other->id}", ['label_ids' => [($this->category)('pleading')->id]])
        ->assertStatus(403);
});

it('categorizes a document as it is uploaded', function () {
    Queue::fake();
    Storage::fake('local');

    $pleading = ($this->category)('pleading');

    $response = $this->signInAs($this->user)
        ->postJson('/api/documents', [
            'file' => UploadedFile::fake()->createWithContent('complaint.txt', 'A complaint.'),
            'label_ids' => [$pleading->id],
        ])
        ->assertCreated();

    expect(collect($response->json('data.categories'))->pluck('slug')->all())->toBe(['pleading']);
});

it('filters documents by any of the given categories', function () {
    $pleading = ($this->category)('pleading');
    $financial = ($this->category)('financial-record');

    $this->document->syncLabels([$pleading], $this->user);
    $second = Document::factory()->for($this->user)->create();
    $second->syncLabels([$financial], $this->user);
    Document::factory()->for($this->user)->create();

    $response = $this->signInAs($this->user)
        ->getJson("/api/documents?category_id[]={$pleading->id}&category_id[]={$financial->id}")
        ->assertOk();

    expect(collect($response->json('data'))->pluck('id')->sort()->values()->all())
        ->toBe(collect([$this->document->id, $second->id])->sort()->values()->all());
});

it('narrows documents to those carrying every given category', function () {
    $evidence = ($this->category)('evidence-documentary');
    $financial = ($this->category)('financial-record');

    $this->document->syncLabels([$evidence, $financial], $this->user);

    $partial = Document::factory()->for($this->user)->create();
    $partial->syncLabels([$evidence], $this->user);

    $response = $this->signInAs($this->user)
        ->getJson("/api/documents?match=all&category_id[]={$evidence->id}&category_id[]={$financial->id}")
        ->assertOk();

    expect(collect($response->json('data'))->pluck('id')->all())->toBe([$this->document->id]);
});

it('tells the client which categories were suggested rather than chosen', function () {
    $this->document->syncSuggestedLabels([
        ['label' => ($this->category)('pleading'), 'confidence' => 0.82],
    ]);

    $response = $this->signInAs($this->user)
        ->getJson('/api/documents')
        ->assertOk();

    expect($response->json('data.0.categories.0.source'))->toBe('ai')
        ->and($response->json('data.0.categories.0.confidence'))->toBe(0.82);
});

it('marks a category the user chose as theirs', function () {
    $this->document->syncLabels([($this->category)('pleading')], $this->user);

    $response = $this->signInAs($this->user)
        ->getJson('/api/documents')
        ->assertOk();

    expect($response->json('data.0.categories.0.source'))->toBe('user')
        ->and($response->json('data.0.categories.0.confidence'))->toBeNull();
});

it('lists the documents still waiting to be filed', function () {
    $this->document->syncLabels([($this->category)('pleading')], $this->user);
    $unfiled = Document::factory()->for($this->user)->create();

    $response = $this->signInAs($this->user)
        ->getJson('/api/documents?uncategorized=1')
        ->assertOk();

    expect(collect($response->json('data'))->pluck('id')->all())->toBe([$unfiled->id]);
});
