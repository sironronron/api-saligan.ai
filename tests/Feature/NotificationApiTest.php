<?php

use App\Models\Conversation;
use App\Models\LegalCase;
use App\Models\Todo;
use App\Models\User;
use App\Notifications\DeadlineReminder;
use App\Services\Cases\DeadlineReminderService;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Str;

function deadlineNotification(User $user, array $data = [], bool $read = false): DatabaseNotification
{
    return $user->notifications()->create([
        'id' => (string) Str::uuid(),
        'type' => DeadlineReminder::class,
        'data' => $data ?: [
            'kind' => 'case',
            'title' => 'Doe v. Santos',
            'due_date' => now()->addDays(2)->toDateString(),
            'days' => 2,
            'overdue' => false,
            'url' => '/cases/example-case',
        ],
        'read_at' => $read ? now() : null,
    ]);
}

beforeEach(function () {
    $this->user = User::factory()->create();
});

it('requires authentication', function () {
    $this->getJson('/api/notifications')->assertStatus(401);
    $this->getJson('/api/notifications/unread-count')->assertStatus(401);
    $this->postJson('/api/notifications/read-all')->assertStatus(401);

    $notification = deadlineNotification($this->user);

    $this->patchJson("/api/notifications/{$notification->id}", ['read' => true])->assertStatus(401);
    $this->deleteJson("/api/notifications/{$notification->id}")->assertStatus(401);
});

it('lists the authenticated user notifications newest first with an unread count', function () {
    $older = deadlineNotification($this->user, [], read: true);
    $newer = deadlineNotification($this->user);

    $older->forceFill(['created_at' => now()->subHours(2)])->save();
    $newer->forceFill(['created_at' => now()->subHour()])->save();

    $response = $this->signInAs($this->user)
        ->getJson('/api/notifications')
        ->assertOk();

    expect($response->json('data'))->toHaveCount(2)
        ->and($response->json('data.0.id'))->toBe($newer->id)
        ->and($response->json('data.1.id'))->toBe($older->id)
        ->and($response->json('data.0.read'))->toBeFalse()
        ->and($response->json('data.1.read'))->toBeTrue()
        ->and($response->json('data.0.title'))->toBe('Doe v. Santos')
        ->and($response->json('unread_count'))->toBe(1);
});

it('does not list another users notifications', function () {
    $other = User::factory()->create();
    $otherNotification = deadlineNotification($other);

    $this->signInAs($this->user)
        ->getJson('/api/notifications')
        ->assertOk()
        ->assertJsonCount(0, 'data');

    expect($otherNotification->fresh())->not->toBeNull();
});

it('filters to unread notifications', function () {
    deadlineNotification($this->user, [], read: true);
    deadlineNotification($this->user);

    $this->signInAs($this->user)
        ->getJson('/api/notifications?unread=true')
        ->assertOk()
        ->assertJsonCount(1, 'data');
});

it('reports the unread count for the navbar badge', function () {
    deadlineNotification($this->user);
    deadlineNotification($this->user, [], read: true);

    $this->signInAs($this->user)
        ->getJson('/api/notifications/unread-count')
        ->assertOk()
        ->assertJsonPath('unread_count', 1);
});

it('marks a notification as read', function () {
    $notification = deadlineNotification($this->user);

    $response = $this->signInAs($this->user)
        ->patchJson("/api/notifications/{$notification->id}", ['read' => true])
        ->assertOk();

    $response->assertJsonPath('data.read', true)
        ->assertJsonPath('data.read_at', $notification->fresh()->read_at->toISOString());

    expect($notification->fresh()->read_at)->not->toBeNull();
});

it('marks a read notification as unread again', function () {
    $notification = deadlineNotification($this->user, [], read: true);

    $this->signInAs($this->user)
        ->patchJson("/api/notifications/{$notification->id}", ['read' => false])
        ->assertOk()
        ->assertJsonPath('data.read', false);

    expect($notification->fresh()->read_at)->toBeNull();
});

it('marks all notifications as read', function () {
    deadlineNotification($this->user);
    deadlineNotification($this->user);
    deadlineNotification($this->user, [], read: true);

    $this->signInAs($this->user)
        ->postJson('/api/notifications/read-all')
        ->assertOk()
        ->assertJsonPath('read_count', 2);

    expect($this->user->notifications()->whereNull('read_at')->count())->toBe(0);
});

it('deletes a notification', function () {
    $notification = deadlineNotification($this->user);

    $this->signInAs($this->user)
        ->deleteJson("/api/notifications/{$notification->id}")
        ->assertOk()
        ->assertJsonPath('message', 'Notification deleted');

    $this->assertDatabaseMissing('notifications', ['id' => $notification->id]);
});

it('does not allow marking another users notification as read', function () {
    $other = User::factory()->create();
    $notification = deadlineNotification($other);

    $this->signInAs($this->user)
        ->patchJson("/api/notifications/{$notification->id}", ['read' => true])
        ->assertForbidden();

    expect($notification->fresh()->read_at)->toBeNull();
});

it('persists a database notification when the deadline sweep reminds', function () {
    $case = LegalCase::factory()->for($this->user)->create([
        'status' => 'open',
        'due_date' => now()->addDays(2),
    ]);

    expect(app(DeadlineReminderService::class)->sweep())->toBe(1);

    $this->assertDatabaseHas('notifications', [
        'notifiable_id' => $this->user->id,
        'type' => DeadlineReminder::class,
    ]);

    $notification = $this->user->notifications()->first();

    expect($notification->data['kind'])->toBe('case')
        ->and($notification->data['title'])->toBe($case->title)
        ->and($notification->data['url'])->toBe("/cases/{$case->id}")
        ->and($notification->read_at)->toBeNull();
});

it('persists a database notification for task deadlines too', function () {
    $conversation = Conversation::factory()->for($this->user)->create();
    $todo = Todo::factory()->for($conversation)->create([
        'status' => 'pending',
        'due_date' => now()->addDay(),
    ]);

    app(DeadlineReminderService::class)->sweep();

    $notification = $this->user->notifications()->first();

    expect($notification->data['kind'])->toBe('task')
        ->and($notification->data['url'])->toBe('/todos');
});
