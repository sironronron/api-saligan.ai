<?php

use App\Enums\LabelKind;
use App\Models\Label;
use App\Models\Organization;
use Database\Seeders\LabelSeeder;

it('seeds the system vocabulary for both axes', function () {
    (new LabelSeeder)->run();

    $system = Label::whereNull('organization_id')->whereNull('user_id');

    expect((clone $system)->where('kind', LabelKind::DocumentCategory)->count())->toBe(14)
        ->and((clone $system)->where('kind', LabelKind::ThreadTag)->count())->toBe(12)
        ->and(Label::where('slug', 'evidence-testimonial')->first()->group)->toBe('Evidence');
});

it('can be re-run without duplicating or renumbering the vocabulary', function () {
    (new LabelSeeder)->run();
    $before = Label::orderBy('slug')->get(['slug', 'kind', 'position'])->toArray();

    (new LabelSeeder)->run();

    expect(Label::orderBy('slug')->get(['slug', 'kind', 'position'])->toArray())->toBe($before);
});

it('prunes a system term that has left the vocabulary but keeps custom ones', function () {
    (new LabelSeeder)->run();

    $retired = Label::create([
        'kind' => LabelKind::DocumentCategory,
        'slug' => 'retired-category',
        'name' => 'Retired Category',
    ]);

    $custom = Label::factory()->forOrganization(Organization::factory()->create())->create();

    (new LabelSeeder)->run();

    $this->assertDatabaseMissing('labels', ['id' => $retired->id]);
    $this->assertDatabaseHas('labels', ['id' => $custom->id]);
});
