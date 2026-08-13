<?php

use App\Mail\DeadlineReminderMail;
use App\Models\Conversation;
use App\Models\LegalCase;
use App\Models\Todo;
use App\Models\User;
use App\Notifications\DeadlineReminder;
use App\Services\Cases\DeadlineReminderService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    Notification::fake();

    config(['saligan.reminders.lead_days' => 3]);

    $this->user = User::factory()->create();
    $this->conversation = Conversation::factory()->for($this->user)->create();
});

it('reminds the owner of a case due inside the lead window', function () {
    $case = LegalCase::factory()->for($this->user)->create([
        'status' => 'open',
        'due_date' => now()->addDays(2),
    ]);

    expect(app(DeadlineReminderService::class)->sweep())->toBe(1);

    Notification::assertSentTo($this->user, DeadlineReminder::class, function (DeadlineReminder $n) use ($case): bool {
        return $n->subject->is($case) && $n->days === 2;
    });
});

it('reminds the owner of an overdue case', function () {
    $case = LegalCase::factory()->for($this->user)->create([
        'status' => 'open',
        'due_date' => now()->subDays(2),
    ]);

    expect(app(DeadlineReminderService::class)->sweep())->toBe(1);

    Notification::assertSentTo($this->user, DeadlineReminder::class, function (DeadlineReminder $n) use ($case): bool {
        return $n->subject->is($case) && $n->days === -2;
    });
});

it('does not remind a case outside the lead window', function () {
    LegalCase::factory()->for($this->user)->create([
        'status' => 'open',
        'due_date' => now()->addDays(4),
    ]);

    expect(app(DeadlineReminderService::class)->sweep())->toBe(0);

    Notification::assertNothingSent();
});

it('reminds a case due exactly at the lead window edge', function () {
    $this->travelTo(Carbon::parse('2026-08-01 12:00:00'));

    $edge = LegalCase::factory()->for($this->user)->create(['status' => 'open', 'due_date' => '2026-08-04']);
    $beyond = LegalCase::factory()->for($this->user)->create(['status' => 'open', 'due_date' => '2026-08-05']);

    expect(app(DeadlineReminderService::class)->sweep())->toBe(1);

    Notification::assertSentTo($this->user, DeadlineReminder::class, function (DeadlineReminder $n) use ($edge): bool {
        return $n->subject->is($edge);
    });
});

it('does not remind closed or archived cases', function () {
    $closed = LegalCase::factory()->for($this->user)->create([
        'status' => 'closed',
        'due_date' => now()->subDay(),
    ]);

    $archived = LegalCase::factory()->for($this->user)->archived()->create([
        'status' => 'open',
        'due_date' => now()->addDay(),
    ]);

    expect(app(DeadlineReminderService::class)->sweep())->toBe(0);

    Notification::assertNothingSent();
});

it('does not remind a case without a due date', function () {
    LegalCase::factory()->for($this->user)->create(['status' => 'open', 'due_date' => null]);

    expect(app(DeadlineReminderService::class)->sweep())->toBe(0);

    Notification::assertNothingSent();
});

it('reminds the owner of a task due inside the lead window', function () {
    $todo = Todo::factory()->for($this->conversation)->create([
        'status' => 'pending',
        'due_date' => now()->addDays(2),
    ]);

    expect(app(DeadlineReminderService::class)->sweep())->toBe(1);

    Notification::assertSentTo($this->user, DeadlineReminder::class, function (DeadlineReminder $n) use ($todo): bool {
        return $n->subject->is($todo) && $n->days === 2;
    });
});

it('does not remind completed tasks', function () {
    Todo::factory()->for($this->conversation)->completed()->create([
        'due_date' => now()->subDay(),
    ]);

    expect(app(DeadlineReminderService::class)->sweep())->toBe(0);

    Notification::assertNothingSent();
});

it('resolves the task owner through its conversation', function () {
    $case = LegalCase::factory()->for($this->user)->create(['status' => 'open', 'due_date' => null]);
    $conversation = Conversation::factory()->for($this->user)->create(['case_id' => $case->id]);
    $todo = Todo::factory()->for($conversation)->create([
        'status' => 'pending',
        'due_date' => now()->addDay(),
    ]);

    expect(app(DeadlineReminderService::class)->sweep())->toBe(1);

    Notification::assertSentTo($this->user, DeadlineReminder::class, function (DeadlineReminder $n) use ($todo): bool {
        return $n->subject->is($todo);
    });
});

it('emails each owner of their own deadline', function () {
    $colleague = User::factory()->create();
    $colleagueConversation = Conversation::factory()->for($colleague)->create();

    LegalCase::factory()->for($this->user)->create(['status' => 'open', 'due_date' => now()->addDay()]);
    Todo::factory()->for($colleagueConversation)->create(['status' => 'pending', 'due_date' => now()->addDay()]);

    expect(app(DeadlineReminderService::class)->sweep())->toBe(2);

    Notification::assertSentTo($this->user, DeadlineReminder::class);
    Notification::assertSentTo($colleague, DeadlineReminder::class);
});

it('reminds once per deadline and never again', function () {
    $case = LegalCase::factory()->for($this->user)->create([
        'status' => 'open',
        'due_date' => now()->addDays(2),
    ]);

    $service = app(DeadlineReminderService::class);

    expect($service->sweep())->toBe(1)
        ->and($service->sweep())->toBe(0)
        ->and($service->sweep())->toBe(0);

    Notification::assertSentToTimes($this->user, DeadlineReminder::class, 1);
});

it('reminds again when a deadline moves', function () {
    $case = LegalCase::factory()->for($this->user)->create([
        'status' => 'open',
        'due_date' => now()->addDays(2),
    ]);

    $service = app(DeadlineReminderService::class);

    expect($service->sweep())->toBe(1);

    $case->update(['due_date' => now()->addDays(3)]);

    expect($service->sweep())->toBe(1);

    Notification::assertSentToTimes($this->user, DeadlineReminder::class, 2);
});

it('stamps the deadline it reminded about', function () {
    $dueDate = now()->addDays(2);
    $case = LegalCase::factory()->for($this->user)->create([
        'status' => 'open',
        'due_date' => $dueDate,
    ]);

    app(DeadlineReminderService::class)->sweep();

    expect($case->fresh()->deadline_reminded_at)->not->toBeNull()
        ->and($case->fresh()->deadline_reminded_due_date->toDateString())->toBe($dueDate->toDateString());
});

it('reminds only deadlines at or before the due date when the lead window is zero', function () {
    config(['saligan.reminders.lead_days' => 0]);

    $dueToday = LegalCase::factory()->for($this->user)->create(['status' => 'open', 'due_date' => now()]);
    LegalCase::factory()->for($this->user)->create(['status' => 'open', 'due_date' => now()->addDay()]);

    expect(app(DeadlineReminderService::class)->sweep())->toBe(1);

    Notification::assertSentTo($this->user, DeadlineReminder::class, function (DeadlineReminder $n) use ($dueToday): bool {
        return $n->subject->is($dueToday);
    });
});

it('stops entirely when the lead window is negative', function () {
    config(['saligan.reminders.lead_days' => -1]);

    LegalCase::factory()->for($this->user)->create(['status' => 'open', 'due_date' => now()->addDay()]);

    expect(app(DeadlineReminderService::class)->sweep())->toBe(0);

    Notification::assertNothingSent();
});

it('reports the number of reminders sent from the command', function () {
    LegalCase::factory()->for($this->user)->create(['status' => 'open', 'due_date' => now()->addDay()]);

    test()->artisan('deadlines:remind')
        ->expectsOutputToContain('Deadline reminders sent: 1')
        ->assertExitCode(0);
});

it('renders the reminder email for both kinds', function () {
    $case = LegalCase::factory()->for($this->user)->create(['status' => 'open', 'due_date' => now()->addDays(2)]);
    $todo = Todo::factory()->for($this->conversation)->create(['status' => 'pending', 'due_date' => now()->subDays(1)]);

    foreach ([new DeadlineReminderMail($case, 2), new DeadlineReminderMail($todo, -1)] as $mail) {
        $rendered = $mail->render();

        expect($rendered)->toContain('Saligan')->toContain($mail->deadline->title);
    }
});

it('names the deadline and its state in the subject line', function () {
    $case = LegalCase::factory()->for($this->user)->create([
        'status' => 'open',
        'title' => 'Doe v. Santos',
        'due_date' => now()->addDays(3),
    ]);
    $todo = Todo::factory()->for($this->conversation)->create([
        'status' => 'pending',
        'title' => 'File the complaint',
        'due_date' => now()->subDays(2),
    ]);

    expect((new DeadlineReminderMail($case, 3))->envelope()->subject)->toBe('Case "Doe v. Santos" is due in 3 days')
        ->and((new DeadlineReminderMail($case, 0))->envelope()->subject)->toBe('Case "Doe v. Santos" is due today')
        ->and((new DeadlineReminderMail($todo, -2))->envelope()->subject)->toBe('Task "File the complaint" is 2 days overdue');
});
