<?php

use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Template;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;

beforeEach(function () {
    $this->user = User::factory()->create();
    Subscription::factory()->for($this->user)->create([
        'plan_id' => Plan::factory()->pro()->create()->id,
    ]);
});

it('requires authentication', function () {
    $this->getJson('/api/templates')->assertStatus(401);
});

it('lists system templates and the users custom templates', function () {
    Template::factory()->system()->create(['name' => 'Demand Letter', 'category' => 'legal']);
    Template::factory()->system()->create(['name' => 'Formal Business Letter', 'category' => 'formal']);

    $own = Template::factory()->create(['name' => 'My Custom Template', 'category' => 'custom', 'user_id' => $this->user->id]);
    Template::factory()->create(['name' => 'Someone Else', 'category' => 'custom', 'user_id' => User::factory()->create()->id]);

    $this->actingAs($this->user)->getJson('/api/templates')
        ->assertOk()
        ->assertJsonCount(3, 'data')
        ->assertJsonFragment(['id' => $own->id])
        ->assertJsonFragment(['category' => 'legal', 'jurisdiction' => 'PH']);
});

it('exposes the legal subtype and structure', function () {
    Template::factory()->system()->legal()->create();

    $this->actingAs($this->user)->getJson('/api/templates')
        ->assertOk()
        ->assertJsonPath('data.0.legal_subtype', 'demand_letter')
        ->assertJsonPath('data.0.structure', ['Header', 'Date', 'Recipient', 'Subject', 'Body', 'Closing', 'Signature'])
        ->assertJsonPath('data.0.is_system', true);
});

it('saves a custom template derived from an edited letter', function () {
    $this->actingAs($this->user)->postJson('/api/templates', [
        'name' => 'My Demand Letter',
        'category' => 'custom',
        'content' => "My custom letter body with {{recipient_name}}.\n\nVery truly yours,\n{signatory}",
        'structure' => ['Date', 'Recipient', 'Body', 'Closing'],
        'placeholder_fields' => [['key' => 'recipient_name', 'label' => 'Recipient name', 'required' => true]],
    ])->assertCreated()
        ->assertJsonPath('data.name', 'My Demand Letter')
        ->assertJsonPath('data.category', 'custom')
        ->assertJsonPath('data.is_system', false);

    $this->assertDatabaseHas('templates', [
        'user_id' => $this->user->id,
        'name' => 'My Demand Letter',
        'jurisdiction' => 'PH',
    ]);
});

it('validates the custom template name', function () {
    $this->actingAs($this->user)
        ->postJson('/api/templates', ['name' => '', 'content' => ''])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['name']);
});

it('requires content or a template file', function () {
    $this->actingAs($this->user)
        ->postJson('/api/templates', ['name' => 'Empty Template'])
        ->assertUnprocessable()
        ->assertJson(['message' => 'Provide template content or upload a template file.']);
});

it('extracts content from an uploaded txt template', function () {
    Storage::fake('local');

    $this->actingAs($this->user)->postJson('/api/templates', [
        'name' => 'Txt Template',
        'template_file' => UploadedFile::fake()->createWithContent('letter.txt', "Dear {{client_name}},\n\nPlease settle your balance."),
    ])->assertCreated()
        ->assertJsonPath('data.name', 'Txt Template')
        ->assertJsonPath('data.content', fn ($content) => str_contains($content, 'Please settle your balance'));

    $this->assertDatabaseHas('templates', [
        'user_id' => $this->user->id,
        'name' => 'Txt Template',
        'content' => "Dear {{client_name}},\n\nPlease settle your balance.",
    ]);
});

it('extracts content from an uploaded docx template', function () {
    Storage::fake('local');

    $phpWord = new PhpWord;
    $section = $phpWord->addSection();
    $section->addText('My DOCX demand letter with {{recipient_name}}.');

    $path = tempnam(sys_get_temp_dir(), 'template').'.docx';
    IOFactory::createWriter($phpWord, 'Word2007')->save($path);

    $upload = new UploadedFile(
        $path,
        'demand-letter.docx',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        null,
        true,
    );

    $this->actingAs($this->user)->postJson('/api/templates', [
        'name' => 'Docx Template',
        'template_file' => $upload,
    ])->assertCreated()
        ->assertJsonPath('data.name', 'Docx Template')
        ->assertJsonPath('data.content', fn ($content) => str_contains($content, 'demand letter'));

    @unlink($path);
});

it('rejects unsupported template files', function () {
    Storage::fake('local');

    $this->actingAs($this->user)->postJson('/api/templates', [
        'name' => 'Image Template',
        'template_file' => UploadedFile::fake()->image('template.png'),
    ])->assertUnprocessable()
        ->assertJson(['message' => 'Supported template files: PDF, DOCX, TXT, MD.']);
});

it('deletes the users own custom template', function () {
    $template = Template::factory()->create(['name' => 'My Template', 'user_id' => $this->user->id]);

    $this->actingAs($this->user)
        ->deleteJson("/api/templates/{$template->id}")
        ->assertNoContent();

    $this->assertDatabaseMissing('templates', ['id' => $template->id]);
});

it('cannot delete a system template', function () {
    $template = Template::factory()->system()->create();

    $this->actingAs($this->user)
        ->deleteJson("/api/templates/{$template->id}")
        ->assertNotFound();

    $this->assertDatabaseHas('templates', ['id' => $template->id]);
});

it('cannot delete another users template', function () {
    $template = Template::factory()->create(['user_id' => User::factory()->create()->id]);

    $this->actingAs($this->user)
        ->deleteJson("/api/templates/{$template->id}")
        ->assertForbidden();

    $this->assertDatabaseHas('templates', ['id' => $template->id]);
});

it('includes content only for user-owned templates', function () {
    Template::factory()->system()->create(['name' => 'System Tpl']);
    Template::factory()->create(['name' => 'My Tpl', 'user_id' => $this->user->id, 'content' => 'body']);

    $data = $this->actingAs($this->user)->getJson('/api/templates')
        ->assertOk()
        ->json('data');

    $system = collect($data)->firstWhere('is_system', true);
    $own = collect($data)->firstWhere('is_system', false);

    $this->assertNull($system['content']);
    $this->assertSame('body', $own['content']);
});
