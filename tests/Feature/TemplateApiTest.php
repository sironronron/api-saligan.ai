<?php

use App\Models\Template;
use App\Models\User;

beforeEach(function () {
    $this->user = User::factory()->create();
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

it('validates the custom template name and content', function () {
    $this->actingAs($this->user)
        ->postJson('/api/templates', ['name' => '', 'content' => ''])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['name', 'content']);
});
