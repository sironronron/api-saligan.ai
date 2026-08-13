<?php

namespace App\Console\Commands;

use App\Services\Cases\DeadlineReminderService;
use Illuminate\Console\Command;

class RemindDeadlines extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'deadlines:remind';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Email owners about cases and tasks with upcoming or overdue deadlines';

    /**
     * Execute the console command.
     */
    public function handle(DeadlineReminderService $service): int
    {
        $sent = $service->sweep();

        $this->info("Deadline reminders sent: {$sent}");

        return self::SUCCESS;
    }
}
