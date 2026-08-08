<?php

use App\Models\Template;
use App\Models\User;
use App\Support\LegalTemplateLibrary;
use Database\Seeders\TemplateSeeder;

it('seeds a system template for every library document type', function () {
    (new TemplateSeeder)->run();

    $library = LegalTemplateLibrary::all();

    expect(Template::whereNull('user_id')->count())->toBe(count($library));

    foreach ($library as $template) {
        $documentType = $template['document_type'];
        $row = Template::whereNull('user_id')->where('legal_subtype', $documentType)->first();

        expect($row)->not->toBeNull()
            ->and($row->name)->toBe(LegalTemplateLibrary::title($template))
            ->and($row->category)->toBe('legal')
            ->and($row->jurisdiction)->toBe('PH')
            ->and($row->default_for_case_types)->toBe([])
            ->and($row->placeholder_fields)->toBeArray()
            ->and($row->content)->toBe(LegalTemplateLibrary::body($template));
    }
});

it('keeps the placeholder fields required flags from the library intake fields', function () {
    (new TemplateSeeder)->run();

    $row = Template::whereNull('user_id')->where('legal_subtype', 'demand_letter')->firstOrFail();

    $intake = LegalTemplateLibrary::intakeFields(
        LegalTemplateLibrary::forDocumentType('demand_letter'),
    );

    expect($row->placeholder_fields)->toHaveCount(count($intake));

    foreach ($row->placeholder_fields as $field) {
        expect($field)->toHaveKeys(['key', 'label', 'required']);
    }
});

it('is idempotent', function () {
    (new TemplateSeeder)->run();
    (new TemplateSeeder)->run();

    expect(Template::whereNull('user_id')->count())->toBe(count(LegalTemplateLibrary::all()));
});

it('removes stale system templates and keeps user templates', function () {
    Template::factory()->system()->create(['name' => 'Notice to Explain', 'legal_subtype' => 'notice_to_explain']);
    $user = User::factory()->create();
    $own = Template::factory()->create(['user_id' => $user->id, 'legal_subtype' => 'custom']);

    (new TemplateSeeder)->run();

    expect(Template::where('legal_subtype', 'notice_to_explain')->whereNull('user_id')->count())->toBe(0)
        ->and(Template::find($own->id))->not->toBeNull();
});
