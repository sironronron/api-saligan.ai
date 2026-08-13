<?php

use App\Enums\CrawlStatus;
use App\Enums\LegalSourceCategory;
use App\Jobs\CrawlLegalSourcePage;
use App\Models\CrawledPage;
use App\Models\LegalSource;
use App\Models\SystemPrompt;
use App\Models\User;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->admin = User::factory()->admin()->create();
});

it('rejects non-admin users from legal source management', function () {
    $this->signInAs($this->user)
        ->getJson('/api/admin/legal-sources')
        ->assertForbidden();

    $this->signInAs($this->user)
        ->postJson('/api/admin/legal-sources', [
            'name' => 'Evil',
            'base_domain' => 'evil.example',
            'seed_urls' => ['https://evil.example'],
        ])->assertForbidden();
});

it('rejects non-admin users from system prompt management', function () {
    $this->signInAs($this->user)
        ->getJson('/api/admin/system-prompts')
        ->assertForbidden();
});

it('lists legal sources for admins with crawl stats', function () {
    $source = LegalSource::factory()->create();
    CrawledPage::factory()->for($source)->create(['crawl_status' => CrawlStatus::Ok]);

    $this->signInAs($this->admin)
        ->getJson('/api/admin/legal-sources')
        ->assertOk()
        ->assertJsonCount(1)
        ->assertJsonPath('0.id', $source->id)
        ->assertJsonPath('0.crawled_pages_count', 1);
});

it('creates a legal source for admins', function () {
    $response = $this->signInAs($this->admin)
        ->postJson('/api/admin/legal-sources', [
            'name' => 'LawPhil',
            'base_domain' => 'lawphil.net',
            'seed_urls' => ['https://lawphil.net/statutes/repacts/repacts.html'],
            'is_active' => true,
        ])->assertCreated();

    expect($response->json('seed_urls'))->toBeArray()
        ->and($response->json('is_active'))->toBeTrue()
        ->and($response->json('category'))->toBe(LegalSourceCategory::General->value);

    $this->assertDatabaseHas('legal_sources', ['base_domain' => 'lawphil.net']);
});

it('stores the chosen category when creating a legal source', function () {
    $response = $this->signInAs($this->admin)
        ->postJson('/api/admin/legal-sources', [
            'name' => 'SC E-Library',
            'base_domain' => 'elibrary.judiciary.gov.ph',
            'seed_urls' => ['https://elibrary.judiciary.gov.ph/thebookshelf'],
            'category' => LegalSourceCategory::Jurisprudence->value,
        ])->assertCreated();

    expect($response->json('category'))->toBe(LegalSourceCategory::Jurisprudence->value);

    $this->signInAs($this->admin)
        ->postJson('/api/admin/legal-sources', [
            'name' => 'Bad Category',
            'base_domain' => 'bad.example',
            'seed_urls' => ['https://bad.example'],
            'category' => 'not-a-category',
        ])->assertUnprocessable()
        ->assertJsonValidationErrors(['category']);
});

it('rejects duplicate base domains', function () {
    LegalSource::factory()->create(['base_domain' => 'lawphil.net']);

    $this->signInAs($this->admin)
        ->postJson('/api/admin/legal-sources', [
            'name' => 'LawPhil Again',
            'base_domain' => 'lawphil.net',
            'seed_urls' => ['https://lawphil.net'],
        ])->assertUnprocessable();
});

it('dispatches crawl jobs from crawl-now', function () {
    Queue::fake();

    $source = LegalSource::factory()->create([
        'seed_urls' => ['https://lawphil.net/a', 'https://lawphil.net/b'],
    ]);

    $this->signInAs($this->admin)
        ->postJson("/api/admin/legal-sources/{$source->id}/crawl-now")
        ->assertOk();

    Queue::assertPushed(CrawlLegalSourcePage::class, 2);
});

it('deletes a legal source and cascades its pages', function () {
    $source = LegalSource::factory()->create();
    CrawledPage::factory()->for($source)->create();

    $this->signInAs($this->admin)
        ->deleteJson("/api/admin/legal-sources/{$source->id}")
        ->assertNoContent();

    $this->assertDatabaseMissing('legal_sources', ['id' => $source->id]);
    $this->assertDatabaseMissing('crawled_pages', ['legal_source_id' => $source->id]);
});

it('lists crawled pages filtered by status', function () {
    $source = LegalSource::factory()->create();
    CrawledPage::factory()->for($source)->count(2)->create(['crawl_status' => CrawlStatus::Ok]);
    CrawledPage::factory()->for($source)->create(['crawl_status' => CrawlStatus::Failed]);

    $this->signInAs($this->admin)
        ->getJson('/api/admin/crawled-pages?status=failed')
        ->assertOk()
        ->assertJsonCount(1, 'data');
});

it('lists system prompts for admins', function () {
    SystemPrompt::factory()->create();

    $this->signInAs($this->admin)
        ->getJson('/api/admin/system-prompts')
        ->assertOk()
        ->assertJsonCount(1);
});

it('creates incrementing system prompt versions', function () {
    SystemPrompt::factory()->create(['name' => 'saligan', 'version' => 1]);

    $response = $this->signInAs($this->admin)
        ->postJson('/api/admin/system-prompts', [
            'name' => 'saligan',
            'content' => 'New persona',
        ])->assertCreated();

    expect($response->json('version'))->toBe(2)
        ->and($response->json('is_active'))->toBeFalse();
});

it('activates a system prompt and deactivates its peers', function () {
    $old = SystemPrompt::factory()->create(['name' => 'saligan', 'version' => 1, 'is_active' => true]);
    $new = SystemPrompt::factory()->create(['name' => 'saligan', 'version' => 2, 'is_active' => false]);

    $this->signInAs($this->admin)
        ->postJson("/api/admin/system-prompts/{$new->id}/activate")
        ->assertOk()
        ->assertJsonPath('is_active', true);

    expect($old->fresh()->is_active)->toBeFalse()
        ->and($new->fresh()->is_active)->toBeTrue();
});
