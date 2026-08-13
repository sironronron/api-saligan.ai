<?php

namespace App\Mail;

use App\Models\LegalCase;
use App\Models\Todo;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;

class DeadlineReminderMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly LegalCase|Todo $deadline,
        public readonly int $days,
    ) {
        //
    }

    /**
     * The subject says which deadline and how much time is left, so the inbox
     * list is scannable without opening the email.
     */
    public function envelope(): Envelope
    {
        $noun = $this->kind() === 'case' ? 'Case' : 'Task';
        $title = Str::limit($this->deadline->title, 60);
        $days = abs($this->days);

        $subject = match (true) {
            $this->days < 0 => "{$noun} \"{$title}\" is {$days} ".Str::plural('day', $days).' overdue',
            $this->days === 0 => "{$noun} \"{$title}\" is due today",
            default => "{$noun} \"{$title}\" is due in {$this->days} ".Str::plural('day', $this->days),
        };

        return new Envelope(subject: $subject);
    }

    public function content(): Content
    {
        $frontendUrl = rtrim((string) config('app.frontend_url'), '/');

        $isCase = $this->kind() === 'case';

        return new Content(
            view: 'emails.deadline-reminder',
            with: [
                'kind' => $this->kind(),
                'title' => $this->deadline->title,
                'dueDate' => $this->deadline->due_date,
                'days' => $this->days,
                'overdue' => $this->days < 0,
                'absDays' => abs($this->days),
                'detailUrl' => $isCase
                    ? "{$frontendUrl}/cases/{$this->deadline->id}"
                    : "{$frontendUrl}/todos",
                'detailLabel' => $isCase ? 'Open your case' : 'View your tasks',
            ],
        );
    }

    /**
     * The sweep sends one notification per deadline, so the mailable has to
     * know which kind of matter it is describing.
     */
    protected function kind(): string
    {
        return $this->deadline instanceof LegalCase ? 'case' : 'task';
    }
}
