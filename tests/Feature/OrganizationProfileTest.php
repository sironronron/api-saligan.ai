<?php

use App\Models\Organization;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Organizations\OrganizationService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    $this->organization = Organization::factory()->create(['name' => 'Acme Law Office']);
    $this->owner = User::factory()->ownerOf($this->organization)->create();
    $this->member = User::factory()->memberOf($this->organization)->create();

    Subscription::factory()->for($this->organization)->for($this->owner)->create([
        'plan_id' => Plan::factory()->firm()->create()->id,
        'seats_purchased' => 3,
    ]);
});

it('lets an admin edit the name, description, and website', function () {
    $this->signInAs($this->owner)
        ->patchJson('/api/organizations', [
            'name' => 'Acme Legal',
            'description' => 'Public interest litigation in Metro Manila.',
            'website' => 'https://acme.test',
        ])
        ->assertOk()
        ->assertJsonPath('data.name', 'Acme Legal')
        ->assertJsonPath('data.description', 'Public interest litigation in Metro Manila.')
        ->assertJsonPath('data.website', 'https://acme.test');

    $this->assertDatabaseHas('organizations', [
        'id' => $this->organization->id,
        'name' => 'Acme Legal',
        'website' => 'https://acme.test',
    ]);
});

/**
 * The form saves one section at a time, so an absent field means "leave it
 * alone". Only an explicitly empty string clears a value.
 */
it('leaves out fields alone and clears only what is explicitly emptied', function () {
    $this->organization->forceFill([
        'description' => 'Original blurb.',
        'website' => 'https://acme.test',
    ])->save();

    $this->signInAs($this->owner)
        ->patchJson('/api/organizations', ['name' => 'Renamed'])
        ->assertOk()
        ->assertJsonPath('data.description', 'Original blurb.')
        ->assertJsonPath('data.website', 'https://acme.test');

    $this->signInAs($this->owner)
        ->patchJson('/api/organizations', ['description' => '', 'website' => ''])
        ->assertOk()
        ->assertJsonPath('data.description', null)
        ->assertJsonPath('data.website', null);
});

it('adds a scheme to a website typed as a bare host', function () {
    $this->signInAs($this->owner)
        ->patchJson('/api/organizations', ['website' => 'acme.test/team'])
        ->assertOk()
        ->assertJsonPath('data.website', 'https://acme.test/team');
});

it('refuses a website that is not an address at all', function () {
    $this->signInAs($this->owner)
        ->patchJson('/api/organizations', ['website' => 'not a website'])
        ->assertUnprocessable();
});

it('does not let a plain member edit the organization', function () {
    $this->signInAs($this->member)
        ->patchJson('/api/organizations', ['name' => 'Hijacked'])
        ->assertForbidden();

    expect($this->organization->fresh()->name)->toBe('Acme Law Office');
});

it('stores a logo and hands back a link an img tag can use', function () {
    Storage::fake(OrganizationService::LOGO_DISK);

    $url = $this->signInAs($this->owner)
        ->postJson('/api/organizations/logo', [
            'logo' => UploadedFile::fake()->image('firm.png', 240, 240),
        ])
        ->assertOk()
        ->json('data.logo_url');

    $path = $this->organization->fresh()->logo_path;

    expect($path)->not->toBeNull();
    Storage::disk(OrganizationService::LOGO_DISK)->assertExists($path);

    // Signed rather than bearer-authenticated: the whole point is that an
    // <img src> can fetch it without setting a header.
    expect($url)->toContain('signature=');

    $this->getJson($url)->assertOk();
});

it('refuses the logo route without a valid signature', function () {
    Storage::fake(OrganizationService::LOGO_DISK);

    $this->signInAs($this->owner)
        ->postJson('/api/organizations/logo', ['logo' => UploadedFile::fake()->image('firm.png')])
        ->assertOk();

    $this->getJson("/api/organizations/{$this->organization->id}/logo")
        ->assertForbidden();
});

it('replaces the old logo file rather than piling them up', function () {
    Storage::fake(OrganizationService::LOGO_DISK);

    $this->signInAs($this->owner)
        ->postJson('/api/organizations/logo', ['logo' => UploadedFile::fake()->image('first.png')])
        ->assertOk();

    $first = $this->organization->fresh()->logo_path;

    $this->signInAs($this->owner)
        ->postJson('/api/organizations/logo', ['logo' => UploadedFile::fake()->image('second.png')])
        ->assertOk();

    $second = $this->organization->fresh()->logo_path;

    expect($second)->not->toBe($first);
    Storage::disk(OrganizationService::LOGO_DISK)->assertMissing($first);
    Storage::disk(OrganizationService::LOGO_DISK)->assertExists($second);
});

it('removes the logo and reports no link', function () {
    Storage::fake(OrganizationService::LOGO_DISK);

    $this->signInAs($this->owner)
        ->postJson('/api/organizations/logo', ['logo' => UploadedFile::fake()->image('firm.png')])
        ->assertOk();

    $path = $this->organization->fresh()->logo_path;

    $this->signInAs($this->owner)
        ->deleteJson('/api/organizations/logo')
        ->assertOk()
        ->assertJsonPath('data.logo_url', null);

    Storage::disk(OrganizationService::LOGO_DISK)->assertMissing($path);
    expect($this->organization->fresh()->logo_path)->toBeNull();
});

it('does not let a plain member change the logo', function () {
    Storage::fake(OrganizationService::LOGO_DISK);

    $this->signInAs($this->member)
        ->postJson('/api/organizations/logo', ['logo' => UploadedFile::fake()->image('firm.png')])
        ->assertForbidden();

    $this->signInAs($this->member)
        ->deleteJson('/api/organizations/logo')
        ->assertForbidden();
});

it('refuses a file that is not an image', function () {
    Storage::fake(OrganizationService::LOGO_DISK);

    $this->signInAs($this->owner)
        ->postJson('/api/organizations/logo', [
            'logo' => UploadedFile::fake()->create('contract.pdf', 40, 'application/pdf'),
        ])
        ->assertUnprocessable();
});

it('reports the profile to every member, editable or not', function () {
    $this->organization->forceFill([
        'description' => 'Public interest litigation.',
        'website' => 'https://acme.test',
    ])->save();

    $this->signInAs($this->member)
        ->getJson('/api/organizations')
        ->assertOk()
        ->assertJsonPath('data.description', 'Public interest litigation.')
        ->assertJsonPath('data.website', 'https://acme.test')
        ->assertJsonPath('data.logo_url', null);
});
