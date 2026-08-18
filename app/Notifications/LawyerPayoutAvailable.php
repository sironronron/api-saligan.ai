<?php

namespace App\Notifications;

use App\Models\LawyerPayout;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class LawyerPayoutAvailable extends Notification
{
    use Queueable;

    public function __construct(public readonly LawyerPayout $payout)
    {
        //
    }

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        $pesos = number_format($this->payout->lawyer_share / 100, 2);

        return [
            'kind' => 'payout',
            'title' => 'New payout available: ₱'.$pesos,
            'body' => 'Your payout for '.$this->payout->notarization_count.' notarization(s) is ready. It will be disbursed on your next payout run.',
            'url' => '/lawyer',
        ];
    }
}
