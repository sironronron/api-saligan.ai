<?php

namespace App\Services\Cases;

use App\Models\LegalCase;
use App\Models\Todo;
use App\Notifications\DeadlineReminder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Sends the deadline reminder for every case or task whose due date has
 * arrived or is about to.
 *
 * Each deadline is reminded at most once, tracked by a stamp on the row (the
 * same stamp-before-send rule TrialWarner uses). The reminder is keyed to the
 * due date it was sent for: if the deadline later moves, the row becomes
 * eligible again and the owner is reminded for the new date.
 */
class DeadlineReminderService
{
    /**
     * Sweep every due case and task and email its owner.
     *
     * @return int the number of reminders sent
     */
    public function sweep(): int
    {
        $leadDays = (int) config('saligan.reminders.lead_days');

        if ($leadDays < 0) {
            return 0;
        }

        return $this->sweepCases($leadDays) + $this->sweepTodos($leadDays);
    }

    /**
     * @return int the number of case reminders sent
     */
    protected function sweepCases(int $leadDays): int
    {
        $sent = 0;
        $horizon = Carbon::today()->addDays($leadDays);

        LegalCase::query()
            ->whereNotNull('due_date')
            ->where('status', '!=', 'closed')
            ->whereNull('archived_at')
            ->where('due_date', '<=', $horizon)
            ->where(function ($query): void {
                $query->whereNull('deadline_reminded_at')
                    ->orWhereColumn('deadline_reminded_due_date', '!=', 'due_date');
            })
            ->with('user')
            ->chunkById(100, function ($cases) use (&$sent): void {
                foreach ($cases as $case) {
                    if ($this->remind($case)) {
                        $sent++;
                    }
                }
            });

        return $sent;
    }

    /**
     * @return int the number of task reminders sent
     */
    protected function sweepTodos(int $leadDays): int
    {
        $sent = 0;
        $horizon = Carbon::today()->addDays($leadDays);

        Todo::query()
            ->whereNotNull('due_date')
            ->where('status', '!=', 'completed')
            ->where('due_date', '<=', $horizon)
            ->where(function ($query): void {
                $query->whereNull('deadline_reminded_at')
                    ->orWhereColumn('deadline_reminded_due_date', '!=', 'due_date');
            })
            ->with(['conversation.user'])
            ->chunkById(100, function ($todos) use (&$sent): void {
                foreach ($todos as $todo) {
                    if ($this->remind($todo)) {
                        $sent++;
                    }
                }
            });

        return $sent;
    }

    /**
     * Remind the owner of a deadline, unless they have already been reminded
     * for this exact due date.
     */
    protected function remind(LegalCase|Todo $subject): bool
    {
        $user = $subject instanceof LegalCase
            ? $subject->user
            : $subject->conversation?->user;

        if ($user === null) {
            return false;
        }

        // Stamped before sending, not after: a mail failure must not leave the
        // row eligible for a retry on every subsequent sweep tick. Losing one
        // reminder is a far smaller problem than emailing in a loop.
        $subject->forceFill([
            'deadline_reminded_at' => now(),
            'deadline_reminded_due_date' => $subject->due_date,
        ])->save();

        $days = (int) Carbon::today()->diffInDays($subject->due_date->copy()->startOfDay(), false);

        try {
            $user->notify(new DeadlineReminder($subject, $days));
        } catch (\Throwable $e) {
            Log::warning('Deadline reminder email failed.', [
                'subject_type' => $subject::class,
                'subject_id' => $subject->id,
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);
        }

        return true;
    }
}
